"""Unwetter4Lox Daemon v0.9.0 – GeoSphere (ZAMG) + INCA + TAWES 360° -> MQTT"""
import os, sys, json, time, logging, configparser, urllib.request, signal, subprocess, glob, threading, math, re, traceback
from datetime import datetime, timezone, timedelta
from collections import deque

# LoxBerry SDK imports
try:
    import loxberry.log
    import loxberry.mqtt
    import loxberry.system
    LB_SDK = True
except ImportError:
    LB_SDK = False

LBHOMEDIR    = os.environ.get('LBHOMEDIR', '')
LBPPLUGINDIR = os.environ.get('LBPPLUGINDIR', 'unwetter4lox')
CONFIGDIR    = os.path.join(LBHOMEDIR, 'config', 'plugins', LBPPLUGINDIR)
DATADIR      = os.path.join(LBHOMEDIR, 'data',   'plugins', LBPPLUGINDIR)
LOGDIR       = os.path.join(LBHOMEDIR, 'log',    'plugins', LBPPLUGINDIR)

os.makedirs(DATADIR, exist_ok=True)
os.makedirs(LOGDIR,  exist_ok=True)
STATE_FILE         = os.path.join(DATADIR, 'state.json')
TAWES_CACHE_FILE   = os.path.join(DATADIR, 'tawes_stations.json')
PID_FILE           = os.path.join(LOGDIR,  'daemon.pid')

# ---------------------------------------------------------------------------
# LoxBerry Logging Initialisierung
# ---------------------------------------------------------------------------
log = None
LOGFILE = None

def _lb_level_to_python(lb_level):
    if lb_level <= 0: return 60
    if lb_level <= 2: return logging.CRITICAL
    if lb_level <= 3: return logging.ERROR
    if lb_level <= 4: return logging.WARNING
    if lb_level < 7:  return logging.INFO
    return logging.DEBUG

def get_loxberry_loglevel():
    try:
        bridge = os.path.join(LBHOMEDIR, 'bin', 'plugins', LBPPLUGINDIR, 'loglevel.pl')
        if not os.path.exists(bridge): bridge = os.path.join(os.path.dirname(__file__), 'loglevel.pl')
        res = subprocess.check_output(['perl', bridge], encoding='utf-8').strip()
        return int(res) if res.isdigit() else 6
    except: return 6

CURRENT_LOGLEVEL = get_loxberry_loglevel()

if LB_SDK:
    log = loxberry.log.Logger(name='Daemon', package=LBPPLUGINDIR, logdir=LOGDIR, max_log_files=20)
    log.start()
    LOGFILE = log.filename
    try:
        with open(os.path.join(LOGDIR, 'daemon.log.current'), 'w') as _f: _f.write(LOGFILE)
    except: pass
else:
    class LoxBerryFormatter(logging.Formatter):
        TAG_MAP = {'DEBUG': '<DEBUG>', 'INFO': '<OK>', 'WARNING': '<WARNING>', 'ERROR': '<ERR>', 'CRITICAL': '<CRIT>'}
        def format(self, record):
            tag = self.TAG_MAP.get(record.levelname, '<OK>')
            ts  = datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S')
            return f'{ts} {tag} {record.getMessage()}'
    _ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    LOGFILE = os.path.join(LOGDIR, f'daemon_{_ts}.log')
    with open(LOGFILE, 'w', encoding='utf-8') as _f:
        _f.write(f'{datetime.now().astimezone().strftime("%Y-%m-%d %H:%M:%S")} <LOGSTART> Unwetter4Lox Daemon\n')
    try:
        with open(os.path.join(LOGDIR, 'daemon.log.current'), 'w') as _f: _f.write(LOGFILE)
    except: pass
    _fh = logging.FileHandler(LOGFILE, mode='a', encoding='utf-8')
    _fh.setFormatter(LoxBerryFormatter())
    logging.basicConfig(level=_lb_level_to_python(CURRENT_LOGLEVEL), handlers=[_fh])
    log = logging.getLogger('unwetter4lox')

log.info(f'Logging initialisiert (Level {CURRENT_LOGLEVEL})')

def _on_signal(signum, frame):
    log.info(f'Daemon gestoppt (Signal {signum})')
    publish('status', L['status_err'].format(v='Gestoppt'), retain=True)
    if os.path.exists(PID_FILE):
        try: os.remove(PID_FILE)
        except: pass
    if LB_SDK: log.stop()
    sys.exit(0)

signal.signal(signal.SIGTERM, _on_signal)
signal.signal(signal.SIGINT,  _on_signal)

# ---------------------------------------------------------------------------
# Konfiguration laden
# ---------------------------------------------------------------------------
cfg = configparser.ConfigParser()
try:
    cfg.read(os.path.join(CONFIGDIR, 'unwetter4lox.cfg'))
except Exception as e: log.critical(f'Fehler beim Laden der Konfiguration: {e}')

def get_cfg(section, key, default=''):
    try: return cfg.get(section, key, fallback=default)
    except: return default

_lat_raw = get_cfg('LOCATION', 'LAT', '')
_lon_raw = get_cfg('LOCATION', 'LON', '')
if not _lat_raw or not _lon_raw:
    log.critical('STANDORT NICHT KONFIGURIERT!')
    sys.exit(1)

LAT          = float(_lat_raw)
LON          = float(_lon_raw)
INTERVAL     = int(get_cfg('SCHEDULE',       'INTERVAL',          '300'))
BOEN_ALARM   = float(get_cfg('THRESHOLDS',   'BOEN_ALARM',        '60'))
REGEN_ALARM  = float(get_cfg('THRESHOLDS',   'REGEN_ALARM',       '10.0'))
ZAMG_ENABLED = get_cfg('ZAMG',              'ENABLED',             '1') == '1'
INCA_ENABLED = get_cfg('INCA',              'ENABLED',             '1') == '1'
TOPIC_PREFIX = get_cfg('MQTT',             'TOPIC_PREFIX',         'unwetter')
TAWES_ENABLED        = get_cfg('TAWES', 'ENABLED',           '1') == '1'
TAWES_MAX_KM         = float(get_cfg('TAWES', 'MAX_DISTANCE_KM',  '120'))
TAWES_MAX_STATIONS   = max(5, int(get_cfg('TAWES', 'MAX_STATIONS',   '25')))
TAWES_MIN_ALARM_PCT  = max(1, min(100, int(get_cfg('TAWES', 'MIN_ALARM_PROZENT', '30'))))
TAWES_MAX_UPSTREAM_HOEHE = float(get_cfg('TAWES', 'MAX_UPSTREAM_HOEHE_M', '1200'))
TAWES_REGEN_LOKAL_KM     = max(5.0, min(100.0, float(get_cfg('TAWES', 'REGEN_LOKAL_KM', '25'))))

MQTT_BROKER = '127.0.0.1'; MQTT_PORT = 1883; MQTT_USER = ''; MQTT_PASS = ''
USE_LB_MQTT = get_cfg('MQTT', 'USE_LOXBERRY_MQTT', '1') == '1'

def _read_mqtt_creds_from_files():
    global MQTT_BROKER, MQTT_PORT, MQTT_USER, MQTT_PASS
    for p in [os.path.join(LBHOMEDIR, 'config', 'system', 'general.json'),
              os.path.join(LBHOMEDIR, 'config', 'system', 'mqttgateway.json')]:
        if not os.path.exists(p): continue
        try:
            with open(p, 'r') as f: data = json.load(f)
            search = [data]
            for k in ['Mqtt', 'Main']:
                if k in data and isinstance(data[k], dict): search.append(data[k])
            for sd in search:
                for bk in ['Brokerhost', 'brokerhost', 'brokeraddress']:
                    if bk in sd: MQTT_BROKER = sd[bk]; break
                for pk in ['Brokerport', 'brokerport']:
                    if pk in sd: MQTT_PORT = int(sd[pk]); break
            for sd in search:
                for uk in ['Brokeruser', 'brokeruser']:
                    if uk in sd:
                        MQTT_USER = sd[uk]
                        for pk in ['Brokerpass', 'brokerpass']:
                            if pk in sd: MQTT_PASS = sd[pk]; break
                        return True
        except: pass
    return False

if USE_LB_MQTT:
    if LB_SDK:
        try:
            m = loxberry.mqtt.mqtt_connectiondetails()
            MQTT_BROKER = m.get('brokeraddress', m.get('brokerhost', '127.0.0.1'))
            MQTT_PORT   = int(m.get('brokerport', 1883))
            MQTT_USER   = m.get('brokeruser', '')
            MQTT_PASS   = m.get('brokerpass', '')
        except: _read_mqtt_creds_from_files()
    else: _read_mqtt_creds_from_files()
else:
    MQTT_BROKER, MQTT_PORT = get_cfg('MQTT','BROKER','127.0.0.1'), int(get_cfg('MQTT','PORT','1883'))
    MQTT_USER, MQTT_PASS   = get_cfg('MQTT','USER',''), get_cfg('MQTT','PASS','')

# ---------------------------------------------------------------------------
# Mehrsprachigkeit (i18n)
# ---------------------------------------------------------------------------
LBLANG = 'de'
if LB_SDK:
    try: LBLANG = loxberry.system.lblanguage()
    except: pass

T = {
    'de': {
        'wind': 'Wind', 'regen': 'Regen', 'schnee': 'Schnee', 'glatteis': 'Glatteis', 'gewitter': 'Gewitter', 'hitze': 'Hitze', 'kaelte': 'Kälte', 'hagel': 'Hagel',
        'sturmböen': 'Sturmböen erwartet', 'gewitter_desc': 'Gewitter möglich', 'hagel_desc': 'Hagelschlag möglich', 'regen_desc': 'Starkregen erwartet',
        'schnee_desc': 'Schneefall erwartet', 'glatteis_desc': 'Glatteis-Gefahr', 'heute': 'heute', 'morgen': 'morgen',
        'akut_active': '🚨 Automatische Akutwarnung aktiv', 'hagel_60': '🔴 Hagel in <60 min erwartet', 'graupel_60': '⚠️ Graupel in <60 min möglich',
        'sturm_30': '🟠 Sturmböen <30 min: max {v} km/h', 'sturm_60': '⚠️ Sturmböen <60 min: max {v} km/h',
        'regen_in': '🌧️ Regen in ~{v} min', 'regen_jetzt': '🌧️ Regen: {v} mm/h', 'kein_alarm': '✅ kein Alarm | Böen: {v} km/h',
        'no_warns': 'keine aktiven Warnungen', 'entwarnung': '✅ Entwarnung – alle Wetterwarnungen aufgehoben.',
        'pt_none': 'kein Niederschlag', 'status_ok': 'OK', 'status_err': 'Fehler - {v}', 'status_init': 'Initialisierung...'
    },
    'en': {
        'wind': 'Wind', 'regen': 'Rain', 'schnee': 'Snow', 'glatteis': 'Ice', 'gewitter': 'Thunderstorm', 'hitze': 'Heat', 'kaelte': 'Cold', 'hagel': 'Hail',
        'sturmböen': 'Storm gusts expected', 'gewitter_desc': 'Thunderstorms possible', 'hagel_desc': 'Hail possible', 'regen_desc': 'Heavy rain expected',
        'schnee_desc': 'Snowfall expected', 'glatteis_desc': 'Ice hazard', 'heute': 'today', 'morgen': 'tomorrow',
        'akut_active': '🚨 Automatic acute warning active', 'hagel_60': '🔴 Hail expected in <60 min', 'graupel_60': '⚠️ Graupel possible in <60 min',
        'sturm_30': '🟠 Storm gusts <30 min: max {v} km/h', 'sturm_60': '⚠️ Storm gusts <60 min: max {v} km/h',
        'regen_in': '🌧️ Rain in ~{v} min', 'regen_jetzt': '🌧️ Rain: {v} mm/h', 'kein_alarm': '✅ no alarm | Gusts: {v} km/h',
        'no_warns': 'no active warnings', 'entwarnung': '✅ All-clear – all weather warnings lifted.',
        'pt_none': 'no precipitation', 'status_ok': 'OK', 'status_err': 'Error - {v}', 'status_init': 'Initializing...'
    }
}
L = T.get(LBLANG, T['en'])
WARN_TYPES = {1: 'wind', 2: 'regen', 3: 'schnee', 4: 'glatteis', 5: 'gewitter', 6: 'hitze', 7: 'kaelte'}
STUFE_NAME = {0: 'KEINE', 1: 'GELB', 2: 'ORANGE', 3: 'ROT', 4: 'LILA'} if LBLANG == 'de' else {0: 'NONE', 1: 'YELLOW', 2: 'ORANGE', 3: 'RED', 4: 'EXTREME'}
PT_NAME    = {255: L['pt_none'], 1: 'Regen' if LBLANG=='de' else 'Rain', 2: 'Schnee' if LBLANG=='de' else 'Snow', 3: 'Schneeregen' if LBLANG=='de' else 'Sleet', 4: 'Graupel', 5: 'Hagel' if LBLANG=='de' else 'Hail'}

def fmt_dt(epoch_s):
    d, now = datetime.fromtimestamp(epoch_s, tz=timezone.utc).astimezone(), datetime.now().astimezone()
    t, days = d.strftime('%H:%M'), (['So','Mo','Di','Mi','Do','Fr','Sa'] if LBLANG == 'de' else ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'])
    if d.date() == now.date(): return f'{L["heute"]} {t}'
    if d.date() == (now + timedelta(days=1)).date(): return f'{L["morgen"]} {t}'
    return f'{days[d.weekday()]} {d.strftime("%d.%m.")} {t}'

# ---------------------------------------------------------------------------
# MQTT Client
# ---------------------------------------------------------------------------
try:
    import paho.mqtt.client as mqtt
    MQTT_OK = True
except: MQTT_OK = False

mqtt_client     = None
_mqtt_connected = threading.Event()

# Watchdog: wann wurde zuletzt erfolgreich verbunden (für Watchdog-Prüfung)
_mqtt_connect_time    = 0.0
_mqtt_reconnect_count = 0

# RC-Code Bedeutungen (MQTT 3.1.1)
_MQTT_RC = {
    0: 'Verbindung akzeptiert',
    1: 'Protokoll-Version nicht unterstützt',
    2: 'Client-ID abgelehnt',
    3: 'Broker nicht verfügbar',
    4: 'Ungültige Zugangsdaten',
    5: 'Nicht autorisiert',
}

def _on_connect(c, u, f, rc):
    global _mqtt_connect_time, _mqtt_reconnect_count
    if rc == 0:
        _mqtt_connected.set()
        _mqtt_connect_time = time.time()
        if _mqtt_reconnect_count > 0:
            log.info(f'MQTT: Verbunden mit {MQTT_BROKER}:{MQTT_PORT} (Reconnect #{_mqtt_reconnect_count})')
        else:
            log.info(f'MQTT: Verbunden mit {MQTT_BROKER}:{MQTT_PORT}')
    else:
        reason = _MQTT_RC.get(rc, f'unbekannt RC={rc}')
        log.error(f'MQTT: Verbindung fehlgeschlagen – {reason} (RC={rc}) | Broker={MQTT_BROKER}:{MQTT_PORT}')

def _on_disconnect(c, u, rc):
    global _mqtt_reconnect_count
    _mqtt_connected.clear()
    _mqtt_reconnect_count += 1
    if rc == 0:
        log.info(f'MQTT: Verbindung sauber getrennt (Reconnect #{_mqtt_reconnect_count})')
    else:
        reason = _MQTT_RC.get(rc, f'unbekannt RC={rc}')
        log.warning(f'MQTT: Verbindung unerwartet getrennt – {reason} (RC={rc}) | Reconnect #{_mqtt_reconnect_count} folgt automatisch')

def mqtt_connect():
    global mqtt_client, _mqtt_reconnect_count
    if not MQTT_OK: return False
    # Alten Client sauber beenden bevor neuer erstellt wird
    if mqtt_client is not None:
        try:
            mqtt_client.loop_stop()
            mqtt_client.disconnect()
        except: pass
        mqtt_client = None
        time.sleep(0.5)  # OS Zeit geben um Socket freizugeben
    _mqtt_connected.clear()
    try:
        try:
            mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id='unwetter4lox', clean_session=True)
        except:
            mqtt_client = mqtt.Client(client_id='unwetter4lox', clean_session=True)
        mqtt_client.on_connect    = _on_connect
        mqtt_client.on_disconnect = _on_disconnect
        # Kürzeres Keepalive: tote Verbindungen werden schneller erkannt (45s statt 90s)
        mqtt_client.reconnect_delay_set(min_delay=5, max_delay=60)
        lwt_topic = f'{TOPIC_PREFIX}/status'
        lwt_msg   = L['status_err'].format(v='Offline (LWT)')
        mqtt_client.will_set(lwt_topic, lwt_msg, qos=1, retain=True)
        if MQTT_USER: mqtt_client.username_pw_set(MQTT_USER, MQTT_PASS)
        mqtt_client.connect(MQTT_BROKER, MQTT_PORT, keepalive=30)
        mqtt_client.loop_start()
        connected = _mqtt_connected.wait(timeout=10)
        if connected:
            # LWT sofort überschreiben – verhindert "Offline"-Status nach Reconnect
            try:
                mqtt_client.publish(f'{TOPIC_PREFIX}/status', L['status_ok'], qos=1, retain=True)
            except: pass
        else:
            log.warning(f'MQTT: Verbindungsaufbau Timeout (10s) | Broker={MQTT_BROKER}:{MQTT_PORT}')
        return connected
    except Exception as e:
        log.error(f'MQTT: Verbindungsfehler: {e} | Broker={MQTT_BROKER}:{MQTT_PORT}')
        return False

def publish(topic, value, retain=True):
    if not (mqtt_client and _mqtt_connected.is_set()):
        return False
    try:
        res = mqtt_client.publish(
            f'{TOPIC_PREFIX}/{topic}',
            str(value) if value is not None else '',
            qos=0, retain=retain
        )
        if res.rc != 0:
            log.warning(f'MQTT: Publish fehlgeschlagen ({topic}), RC={res.rc} – erzwinge Reconnect')
            _mqtt_connected.clear()
            return False
        return True
    except Exception as e:
        log.warning(f'MQTT: Publish Exception ({topic}): {e} – erzwinge Reconnect')
        _mqtt_connected.clear()
        return False

def fetch_json(url, provider):
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Unwetter4Lox/0.9.0'})
        with urllib.request.urlopen(req, timeout=15) as r: return json.loads(r.read().decode())
    except Exception as e: log.error(f'HTTP {provider}: {e} | {url[:100]}'); return None

# ---------------------------------------------------------------------------
# TAWES 360° Stationsnetz
# ---------------------------------------------------------------------------
_tawes_all_stations = []
TAWES_BUFFER = {}

def _haversine(lat1, lon1, lat2, lon2):
    R = 6371; dlat = math.radians(lat2 - lat1); dlon = math.radians(lon2 - lon1)
    a = math.sin(dlat/2)**2 + math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) * math.sin(dlon/2)**2
    return 2 * R * math.asin(math.sqrt(max(0, min(1, a))))

def _bearing(lat1, lon1, lat2, lon2):
    dlon = math.radians(lon2 - lon1)
    y = math.sin(dlon) * math.cos(math.radians(lat2))
    x = math.cos(math.radians(lat1)) * math.sin(math.radians(lat2)) - math.sin(math.radians(lat1)) * math.cos(math.radians(lat2)) * math.cos(dlon)
    return (math.degrees(math.atan2(y, x)) + 360) % 360

def _bearing_to_name(b):
    dirs = ['N','NNO','NO','ONO','O','OSO','SO','SSO','S','SSW','SW','WSW','W','WNW','NW','NNW']
    return dirs[round(b / 22.5) % 16]

def _linreg_slope(series):
    y = [v for v in series if v is not None]; n = len(y)
    if n < 4: return 0.0
    xm = (n - 1) / 2.0; ym = sum(y) / n; denom = sum((i - xm)**2 for i in range(n))
    return sum((i - xm) * (v - ym) for i, v in enumerate(y)) / denom if denom != 0 else 0.0

def _canon_sid(x):
    s = str(x).strip()
    try: return str(int(s))
    except: pass
    try: return str(int(float(s)))
    except: pass
    nums = re.findall(r'\d+', s)
    return str(int(max(nums, key=len))) if nums else s

def _parse_iso(ts):
    """Sicherer ISO-Parser ohne fromisoformat."""
    if not ts: return int(time.time())
    try:
        s = ts.replace('Z', '').split('+')[0].replace('T', ' ')
        for f in ['%Y-%m-%d %H:%M:%S', '%Y-%m-%d %H:%M']:
            try: return int(datetime.strptime(s, f).replace(tzinfo=timezone.utc).timestamp())
            except: continue
    except: pass
    return int(time.time())

def load_tawes_stations():
    global _tawes_all_stations
    cache_age = time.time() - os.path.getmtime(TAWES_CACHE_FILE) if os.path.exists(TAWES_CACHE_FILE) else 999999
    if _tawes_all_stations and cache_age < 86400: return _tawes_all_stations
    if os.path.exists(TAWES_CACHE_FILE) and cache_age < 86400:
        try:
            with open(TAWES_CACHE_FILE, 'r', encoding='utf-8') as f:
                loaded = json.load(f)
                cleaned = []
                for s in loaded:
                    sid = _canon_sid(s.get('id', ''))
                    if sid and sid != 'None': cleaned.append({**s, 'id': sid})
                if cleaned: _tawes_all_stations = cleaned; return cleaned
        except: pass
    data = fetch_json('https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min/metadata', 'TAWES-Metadata')
    if not data: return _tawes_all_stations
    stations = []
    for s in data.get('stations', data.get('features', [])):
        try:
            p = s.get('properties', s); raw_id = p.get('id') or p.get('station_id') or s.get('id') or ''
            sid = _canon_sid(raw_id)
            if not sid or sid == 'None': continue
            def _f(v, d=0.0):
                try: return float(v) if v is not None else d
                except: return d
            lat, lon, alt = _f(p.get('lat', p.get('latitude'))), _f(p.get('lon', p.get('longitude'))), _f(p.get('alt', p.get('elevation')))
            if sid and lat and lon: stations.append({'id': sid, 'name': p.get('name', sid), 'lat': lat, 'lon': lon, 'alt': alt})
        except: continue
    if stations:
        _tawes_all_stations = stations
        try:
            with open(TAWES_CACHE_FILE, 'w', encoding='utf-8') as f: json.dump(stations, f, ensure_ascii=False)
        except: pass
    return stations

def fetch_tawes_data(station_ids, duration_min=0):
    if not station_ids: return {}
    ids = station_ids[:TAWES_MAX_STATIONS]; sid_params = '&'.join(f'station_ids={sid}' for sid in ids)
    if duration_min > 0:
        start_ts = (datetime.now(timezone.utc) - timedelta(minutes=duration_min)).strftime('%Y-%m-%dT%H:%M')
        url = f'https://dataset.api.hub.geosphere.at/v1/timeseries/historical/tawes-v1-10min?parameters=RR,FF,FFX,DD,P,RF&{sid_params}&start={start_ts}&output_format=geojson'
    else:
        url = f'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min?parameters=RR,FF,FFX,DD,P,RF&{sid_params}&output_format=geojson'
    data = fetch_json(url, 'TAWES-Data')
    if not data: return {}
    ts_list, features, canon_to_orig, res = data.get('timestamps', []), data.get('features', []), {_canon_sid(str(i)): str(i) for i in ids}, {}
    for f in features:
        p = f.get('properties', {}); raw_sid = p.get('station') or p.get('id') or f.get('id') or ''
        sid = canon_to_orig.get(_canon_sid(str(raw_sid)), str(raw_sid)); params = p.get('parameters', {}); points = []
        for i, ts in enumerate(ts_list):
            def _v(k):
                d = params.get(k, {}).get('data', [])
                if i >= len(d) or d[i] is None: return None
                try:
                    fv = float(d[i])
                    return None if (math.isnan(fv) or math.isinf(fv)) else fv
                except: return None
            points.append({'ts': _parse_iso(ts), 'RR': _v('RR'), 'FF': _v('FF'), 'FFX': _v('FFX'), 'DD': _v('DD'), 'P': _v('P'), 'RF': _v('RF')})
        if points: res[sid] = points
    return {s: p[-1] for s, p in res.items()} if duration_min == 0 else res

def correlate_tawes(initial_history=False):
    global TAWES_BUFFER
    nearby = sorted([dict(st, dist_km=round(_haversine(LAT, LON, st['lat'], st['lon']), 1),
                          bearing=round(_bearing(LAT, LON, st['lat'], st['lon']), 1))
                     for st in load_tawes_stations()], key=lambda x: x['dist_km'])
    nearby = [st for st in nearby if 2 < st['dist_km'] <= TAWES_MAX_KM][:TAWES_MAX_STATIONS]
    if not nearby: return {}
    if initial_history:
        hist = fetch_tawes_data([s['id'] for s in nearby], duration_min=60)
        for sid, points in hist.items():
            if sid not in TAWES_BUFFER: TAWES_BUFFER[sid] = deque(maxlen=12)
            for p in points: TAWES_BUFFER[sid].append(p)
    raw = fetch_tawes_data([s['id'] for s in nearby])
    if not raw and not initial_history: return {'_api_ok': False}
    for sid, vals in raw.items():
        if sid not in TAWES_BUFFER: TAWES_BUFFER[sid] = deque(maxlen=12)
        TAWES_BUFFER[sid].append(vals)
    def _dv(sid, k, m=1.0):
        v = raw.get(sid, {}).get(k)
        if v is not None: return round(v * m, 1)
        for e in reversed(list(TAWES_BUFFER.get(sid, []))[-3:]):
            if e.get(k) is not None: return round(e[k] * m, 1)
        return None
    sin_sum = cos_sum = 0.0; wr_count = 0
    for sid, v in raw.items():
        ff, dd = v.get('FF'), v.get('DD')
        if ff and dd and ff > 1.0: rad = math.radians(dd); sin_sum += math.sin(rad)*ff; cos_sum += math.cos(rad)*ff; wr_count += 1
    dom_wr = (math.degrees(math.atan2(sin_sum, cos_sum)) + 360) % 360 if wr_count > 0 else 0.0
    dom_wr_name = _bearing_to_name(dom_wr) if wr_count > 0 else '–'
    upstream, all_st = [], []
    for st in nearby:
        sid = st['id']; vals = list(TAWES_BUFFER.get(sid, [{}]))[-1]; up = False
        if wr_count > 0:
            diff = abs(((dom_wr+180)%360) - ((st['bearing']+180)%360)); up = min(diff, 360-diff) < 70
        entry = dict(st, ist_upstream=up, RR=_dv(sid,'RR'), FF_kmh=_dv(sid,'FF',3.6), FFX_kmh=_dv(sid,'FFX',3.6),
                     DD=vals.get('DD'), P=vals.get('P'), RF=vals.get('RF'), bearing_name=_bearing_to_name(st['bearing']))
        if up: upstream.append(entry)
        all_st.append(entry)
    # Wind/Sturm
    w_up_kmh = 0.0; sturm_up = 0; alp_cnt = 0
    ffx_v = [s['FFX_kmh'] for s in upstream if s.get('FFX_kmh') is not None and not (TAWES_MAX_UPSTREAM_HOEHE > 0 and s.get('alt', 0) > TAWES_MAX_UPSTREAM_HOEHE)]
    alp_cnt = sum(1 for s in upstream if TAWES_MAX_UPSTREAM_HOEHE > 0 and s.get('alt', 0) > TAWES_MAX_UPSTREAM_HOEHE)
    if ffx_v:
        w_up_kmh = round(max(ffx_v), 1); min_c = max(2, round(len(ffx_v) * TAWES_MIN_ALARM_PCT / 100))
        sturm_up = int(sum(1 for v in ffx_v if v >= BOEN_ALARM) >= min_c)
    # Regen
    reg_up = 0; r_up_mm = 0.0; r_start = {}
    for st in upstream:
        buf = list(TAWES_BUFFER.get(st['id'], []))
        for i in range(len(buf)-1, max(-1, len(buf)-4), -1):
            if (buf[i].get('RR') or 0) > 0.1: r_start[st['id']] = -(len(buf)-1-i); reg_up = 1; break
    if reg_up and len(r_start) >= max(1, round(len(upstream) * TAWES_MIN_ALARM_PCT / 100)):
        for sid in r_start:
            m = max((b.get('RR') or 0 for b in list(TAWES_BUFFER[sid])[-3:]), default=0)
            if m*6 > r_up_mm: r_up_mm = round(m*6, 1)
    # Lokal-Regen
    r_lok = 0; r_lok_mm = 0.0; r_lok_st = ''
    lok_r = [s for s in all_st if s['dist_km'] <= 40 and (s.get('RR') or 0) > 0.1]
    if lok_r:
        r_lok = 1; lok_a = [s for s in lok_r if s['dist_km'] <= TAWES_REGEN_LOKAL_KM]
        if lok_a:
            best = max(lok_a, key=lambda s: s.get('RR') or 0); r_lok_mm = round((best.get('RR') or 0)*6, 1)
            r_lok_st = f'{best["name"]} ({best["dist_km"]:.0f}km) {r_lok_mm}mm/h'

    ns = upstream[0] if upstream else (nearby[0] if nearby else None)
    return {
        'letztes_update': datetime.now().astimezone().strftime('%d.%m.%Y %H:%M:%S'),
        'dominante_windrichtung': round(dom_wr, 1), 'dominante_windrichtung_name': dom_wr_name,
        'wind_upstream_kmh': w_up_kmh, 'sturm_upstream': sturm_up, 'regen_upstream': reg_up, 'regen_upstream_mm': r_up_mm,
        'upstream_aktiv': len(upstream), 'regen_lokal': r_lok, 'regen_lokal_mm': r_lok_mm, 'regen_lokal_station': r_lok_st,
        'alpine_upstream': alp_cnt, 'naechste_station_name': ns['name'] if ns else '–',
        'naechste_station_km': ns['dist_km'] if ns else 0, 'naechste_station_richtung': ns.get('bearing_name', '–') if ns else '–',
        'alle_stationen': all_st, '_api_ok': True,
    }

# ---------------------------------------------------------------------------
# State & Hauptloop
# ---------------------------------------------------------------------------
def load_state():
    try:
        if os.path.exists(STATE_FILE):
            with open(STATE_FILE, 'r', encoding='utf-8') as f: return json.load(f)
    except: pass
    return {}

def save_state(data):
    try:
        tmp = STATE_FILE + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f: json.dump(data, f, ensure_ascii=False, indent=2, allow_nan=False)
        os.replace(tmp, STATE_FILE)
    except: pass

def publish_all(status_msg, tawes=None, prev=None):
    now = datetime.now().astimezone(); ts_iso = now.strftime('%d.%m.%Y %H:%M:%S'); ts_epoch = int(now.timestamp())
    publish('letzter_abruf_datum', ts_iso); publish('letzter_abruf_epoch', ts_epoch); publish('status/last_seen', ts_epoch)
    publish('status', L['status_ok'] if status_msg == 'OK' else L['status_err'].format(v=status_msg), retain=True)
    publish('status/mqtt_reconnects', _mqtt_reconnect_count)
    if tawes:
        for k in ['wind_upstream_kmh','sturm_upstream','regen_upstream','regen_upstream_mm','regen_lokal','regen_lokal_mm']:
            publish(f'tawes/{k}', tawes.get(k, 0))
    save_state({**prev, 'letztes_update': ts_iso, 'letzter_abruf_epoch': ts_epoch, 'status': status_msg, 'tawes': tawes or {}})

# Watchdog: wenn seit mehr als 10 Minuten keine erfolgreiche Verbindung bestand → Reconnect
MQTT_WATCHDOG_TIMEOUT = 600

def run():
    log.info(f'Unwetter4Lox v0.9.0 gestartet | Interval={INTERVAL}s | Broker={MQTT_BROKER}:{MQTT_PORT}')
    try:
        with open(PID_FILE, 'w') as f: f.write(str(os.getpid()))
    except: pass

    # TAWES-Cache beim Start löschen – frische Stationsdaten erzwingen
    if os.path.exists(TAWES_CACHE_FILE):
        try: os.remove(TAWES_CACHE_FILE)
        except: pass

    st = load_state(); st['status'] = 'Initialisierung...'; save_state(st)

    # Initiale MQTT-Verbindung
    if not mqtt_connect():
        log.warning('MQTT: Startverbindung fehlgeschlagen – Daemon läuft weiter, Reconnect folgt im nächsten Zyklus')

    first = True; last_tawes = 0; _consecutive_publish_fails = 0

    while True:
        try:
            # --- MQTT-Watchdog ---
            if not _mqtt_connected.is_set():
                # Bereits getrennt → Reconnect
                elapsed = time.time() - _mqtt_connect_time if _mqtt_connect_time > 0 else 0
                log.info(f'MQTT: Nicht verbunden (seit {elapsed:.0f}s) – Reconnect...')
                mqtt_connect()
            elif _mqtt_connect_time > 0 and (time.time() - _mqtt_connect_time) > MQTT_WATCHDOG_TIMEOUT:
                # Verbunden laut Flag, aber zu lange ohne neuen Connect-Event → Watchdog greift
                log.warning(
                    f'MQTT-Watchdog: Verbindung seit {(time.time()-_mqtt_connect_time)/60:.0f}min nicht erneuert '
                    f'(Reconnects gesamt: {_mqtt_reconnect_count}) – erzwinge Reconnect'
                )
                _mqtt_connected.clear()
                mqtt_connect()

            cur_st = load_state(); status = 'OK'; tawes = cur_st.get('tawes', {})
            if TAWES_ENABLED and (first or time.time() - last_tawes > 480):
                try:
                    new_t = correlate_tawes(initial_history=first)
                    if new_t.get('_api_ok', True): tawes = new_t; last_tawes = time.time()
                except: log.error(f'TAWES Fehler: {traceback.format_exc()}')

            # publish_all gibt True zurück wenn mindestens der Status-Publish geklappt hat
            publish_all(status, tawes, cur_st)
            first = False

        except Exception:
            log.error(f'Loop Fehler: {traceback.format_exc()}')
        time.sleep(INTERVAL)

if __name__ == '__main__':
    try: run()
    except Exception:
        with open(os.path.join(LOGDIR, 'crash.log'), 'a') as f:
            f.write(f'{datetime.now()} CRASH: {traceback.format_exc()}\n')
        sys.exit(1)
