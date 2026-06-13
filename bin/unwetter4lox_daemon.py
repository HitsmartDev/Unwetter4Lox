"""Unwetter4Lox Daemon v0.4.32 – GeoSphere (ZAMG) + INCA + TAWES 360° -> MQTT
   Changelog:
   v0.4.32: Historischer Daten-Abruf (letzte 60min) beim Start implementiert, um den
            TAWES-Puffer sofort zu füllen (verhindert leere Stationslisten nach Update).
            Status-Meldung "Initialisierung..." beim Start für besseres UI-Feedback.
            Verbesserte Fehlerbehandlung beim State-Laden.
   v0.4.31: MQTT LWT (Last Will and Testament) für Offline-Status implementiert.
            Heartbeat-Topic status/last_seen (Epoch) zur Überwachung in UI/Loxone.
            Status-Badge zeigt jetzt auch TAWES-Fehler an.
            Verstärktes Logging im Haupt-Loop und Fehlerbehandlung.
"""
import os, sys, json, time, logging, configparser, urllib.request, signal, subprocess, glob, threading, math, re
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
    """LoxBerry-Loglevel (0-7, syslog-basiert) in Python-logging-Level umrechnen."""
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
    # MQTT Status auf Offline setzen
    publish('status', L['status_err'].format(v='Gestoppt'), retain=True)
    if os.path.exists(PID_FILE):
        try: os.remove(PID_FILE)
        except: pass
    if LB_SDK: log.stop()
    else:
        try:
            with open(LOGFILE, 'a', encoding='utf-8') as _f:
                _f.write(f'{datetime.now().astimezone().strftime("%Y-%m-%d %H:%M:%S")} <LOGEND>\n')
        except: pass
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
    if LB_SDK: log.stop()
    sys.exit(1)

LAT          = float(_lat_raw)
LON          = float(_lon_raw)
INTERVAL     = int(get_cfg('SCHEDULE',       'INTERVAL',          '300'))
BOEN_ALARM   = float(get_cfg('THRESHOLDS',   'BOEN_ALARM',        '60'))
REGEN_ALARM  = float(get_cfg('THRESHOLDS',   'REGEN_ALARM',       '10.0'))
ZAMG_ENABLED = get_cfg('ZAMG',              'ENABLED',             '1') == '1'
INCA_ENABLED = get_cfg('INCA',              'ENABLED',             '1') == '1'
INCA_HORIZON = int(get_cfg('INCA',          'HORIZON_MINUTES',     '60'))
MIN_STUFE    = int(get_cfg('NOTIFICATIONS', 'MIN_STUFE',           '1'))
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
    _u = cfg.get('MQTT', 'USER', fallback='')
    if _u: MQTT_USER = _u; MQTT_PASS = cfg.get('MQTT', 'PASS', fallback='')
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
mqtt_client, _mqtt_connected = None, threading.Event()
def _on_connect(c, u, f, rc):
    if rc == 0:
        _mqtt_connected.set()
        log.info(f'MQTT: Verbunden mit {MQTT_BROKER}:{MQTT_PORT}')
    else:
        log.error(f'MQTT: Verbindung fehlgeschlagen (RC={rc})')

def _on_disconnect(c, u, rc):
    _mqtt_connected.clear()
    if rc != 0: log.warning('MQTT: Verbindung zum Broker verloren')

def mqtt_connect():
    global mqtt_client
    if not MQTT_OK: return False
    
    if mqtt_client is not None:
        try:
            mqtt_client.loop_stop()
            mqtt_client.disconnect()
        except: pass

    _mqtt_connected.clear()
    try:
        try: mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id='unwetter4lox', clean_session=True)
        except: mqtt_client = mqtt.Client(client_id='unwetter4lox', clean_session=True)
        mqtt_client.on_connect, mqtt_client.on_disconnect = _on_connect, _on_disconnect
        
        # Last Will and Testament (LWT)
        lwt_topic = f'{TOPIC_PREFIX}/status'
        lwt_msg = L['status_err'].format(v='Offline (LWT)')
        mqtt_client.will_set(lwt_topic, lwt_msg, qos=0, retain=True)
        
        if MQTT_USER: mqtt_client.username_pw_set(MQTT_USER, MQTT_PASS)
        mqtt_client.connect(MQTT_BROKER, MQTT_PORT, 60); mqtt_client.loop_start()
        return _mqtt_connected.wait(timeout=8)
    except Exception as e:
        log.error(f'MQTT: Fehler beim Verbindungsaufbau: {e}')
        return False

def publish(topic, value, retain=True):
    if mqtt_client and _mqtt_connected.is_set():
        try:
            full_topic = f'{TOPIC_PREFIX}/{topic}'
            val_str = str(value) if value is not None else ''
            mqtt_client.publish(full_topic, val_str, qos=0, retain=retain)
        except Exception as e:
            log.debug(f'MQTT: Publish fehlgeschlagen ({topic}): {e}')

def fetch_json(url, provider):
    url_short = url[:120]
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Unwetter4Lox/0.4.0'})
        with urllib.request.urlopen(req, timeout=15) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        log.error(f'HTTP {provider}: Status {e.code} {e.reason} | {url_short}')
    except urllib.error.URLError as e:
        log.error(f'HTTP {provider}: Netzwerk-Fehler – {str(e.reason)[:80]} | {url_short}')
    except json.JSONDecodeError as e:
        log.error(f'HTTP {provider}: Ungültiges JSON (pos {e.pos}) | {url_short}')
    except Exception as e:
        log.error(f'HTTP {provider}: {type(e).__name__} – {e} | {url_short}')
    return None

# ---------------------------------------------------------------------------
# GeoSphere ZAMG – Wetterwarnungen
# ---------------------------------------------------------------------------
def fetch_zamg():
    if not ZAMG_ENABLED: return {}, 0, []
    data = fetch_json(f'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lon={LON}&lat={LAT}&lang={LBLANG}', 'GeoSphere')
    if data is None: return None, 0, []
    warnings, now, result, akut, ids = data.get('properties', {}).get('warnings', []), datetime.now(tz=timezone.utc), {}, 0, []
    for t in list(WARN_TYPES.values()) + ['hagel']: result[t] = dict(stufe=0, aktiv=0, bald=0, start_epoch=0, end_epoch=0, start_text='', end_text='', notification='', text='')
    for w in warnings:
        p = w.get('properties', {}); typ, stufe, wid = p.get('warntypid'), p.get('warnstufeid', 1), str(p.get('warnid', ''))
        raw = p.get('rawinfo', {}); s_s, e_s = int(raw.get('start', 0)), int(raw.get('end', 0))
        if wid.startswith('gwa'): akut = 1
        if wid: ids.append(wid)
        s_dt, e_dt = datetime.fromtimestamp(s_s, tz=timezone.utc), datetime.fromtimestamp(e_s, tz=timezone.utc)
        if s_dt > now + timedelta(hours=24) or e_dt < now: continue
        key = WARN_TYPES.get(typ)
        if not key or stufe <= result[key]['stufe']: continue
        aktiv, bald, tl = int(now >= s_dt and now <= e_dt), int(now + timedelta(minutes=30) >= s_dt and now <= e_dt), p.get('text', '').lower()
        if 'sturm' in tl or 'wind' in tl: desc = L['sturmböen']
        elif 'gewitter' in tl or 'thunder' in tl: desc = L['gewitter_desc']
        elif 'hagel' in tl or 'hail' in tl: desc = L['hagel_desc']
        elif 'regen' in tl or 'rain' in tl: desc = L['regen_desc']
        elif 'schnee' in tl or 'snow' in tl: desc = L['schnee_desc']
        elif 'glatteis' in tl or 'ice' in tl: desc = L['glatteis_desc']
        else: desc = p.get('text', '')[:50]
        st, et = fmt_dt(s_s), fmt_dt(e_s)
        result[key] = dict(stufe=stufe, aktiv=aktiv, bald=bald, start_epoch=s_s, end_epoch=e_s, start_text=st, end_text=et,
                           notification=f'{STUFE_NAME.get(stufe, "GELB")} – {L.get(key, key.capitalize())} | {st} – {et} | {desc}')
    return result, akut, ids

# ---------------------------------------------------------------------------
# INCA Nowcast
# ---------------------------------------------------------------------------
def fetch_inca():
    if not INCA_ENABLED: return {}
    now, res = datetime.now(tz=timezone.utc), dict(ff_jetzt=0.0, fx_jetzt=0.0, fx_max_30min=0.0, fx_max_60min=0.0, rr_jetzt=0.0, pt_jetzt=255, pt_name=L['pt_none'], pt_bald=255, pt_bald_name='', bald_regen=0, bald_hagel=0, bald_graupel=0, bald_sturm_30=0, bald_sturm_60=0, minuten_bis_regen=-1)
    _fehler = []
    for param in ['ff', 'fx', 'rr', 'pt']:
        url = f'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km?lat_lon={LAT}%2C{LON}&parameters={param}&output_format=geojson'
        data = fetch_json(url, f'INCA {param}')
        if not data:
            _fehler.append(param)
            continue  # Einzelnen Parameter überspringen statt alles aufgeben
        ts_list, features = data.get('timestamps', []), data.get('features', [])
        if not features: continue
        values = features[0].get('properties', {}).get('parameters', {}).get(param, {}).get('data', [])
        if param == 'ff': res['ff_jetzt'] = round(float(values[0]) * 3.6, 1) if values else 0.0
        elif param == 'fx':
            serie = [round(float(v) * 3.6, 1) for v in values]; res['fx_jetzt'] = serie[0] if serie else 0.0
            m30 = m60 = 0.0
            for i, ts in enumerate(ts_list):
                if i >= len(serie): break
                v, t = serie[i], datetime.fromisoformat(ts).timestamp()
                if t <= now.timestamp()+1800 and v > m30: m30 = v
                if t <= now.timestamp()+3600 and v > m60: m60 = v
            res['fx_max_30min'], res['fx_max_60min'], res['bald_sturm_30'], res['bald_sturm_60'] = round(m30, 1), round(m60, 1), int(m30 >= BOEN_ALARM), int(m60 >= BOEN_ALARM)
        elif param == 'rr':
            serie = [round(float(v), 2) if v else 0.0 for v in values]; res['rr_jetzt'] = serie[0] if serie else 0.0
            res['regen_alarm'] = int(res['rr_jetzt'] >= REGEN_ALARM)
            for i, ts in enumerate(ts_list):
                if i < len(serie) and serie[i] > 0.0:
                    t = datetime.fromisoformat(ts).timestamp()
                    res['minuten_bis_regen'], res['bald_regen'] = max(0, int((t - now.timestamp()) / 60)), int(t <= now.timestamp()+1800)
                    res['_regen_idx'] = i  # Index für pt_bald-Lookup merken
                    break
        elif param == 'pt':
            serie = [int(v) if v else 255 for v in values]; res['pt_jetzt'] = serie[0] if serie else 255
            res['pt_name'] = PT_NAME.get(res['pt_jetzt'], 'unbekannt')
            # Niederschlagstyp des nächsten Regeneintreffens (pt_bald)
            ri = res.get('_regen_idx', -1)
            if ri >= 0 and ri < len(serie) and serie[ri] not in (255, None):
                res['pt_bald'] = serie[ri]
                res['pt_bald_name'] = PT_NAME.get(serie[ri], 'Regen')
            for i, ts in enumerate(ts_list):
                if i < len(serie) and datetime.fromisoformat(ts).timestamp() <= now.timestamp()+3600:
                    if serie[i] == 5: res['bald_hagel'] = 1
                    if serie[i] == 4: res['bald_graupel'] = 1
    if len(_fehler) == 4:
        return None  # Alle 4 Parameter fehlgeschlagen → kein nutzbares Ergebnis
    if _fehler:
        log.warning(f'INCA: {len(_fehler)}/4 Parameter fehlgeschlagen ({", ".join(_fehler)}) – Teildaten verwendet')
    return res

# ---------------------------------------------------------------------------
# TAWES 360° Stationsnetz & Korrelation
# ---------------------------------------------------------------------------
_tawes_all_stations = []       # In-Memory Cache der Stationsliste
TAWES_BUFFER = {}              # station_id → deque(maxlen=12) [12×10min = 2h]
_tawes_last_fetch = 0          # Timestamp letzter Messdaten-Abruf
_tawes_fehler_count = 0        # Konsekutive TAWES-Fehler (für Log-Kontext)

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
    """Lineare Regression ohne numpy. Filtert None-Werte, min. 4 Punkte nötig."""
    y = [v for v in series if v is not None]
    n = len(y)
    if n < 4: return 0.0
    xm = (n - 1) / 2.0; ym = sum(y) / n
    denom = sum((i - xm)**2 for i in range(n))
    if denom == 0: return 0.0
    return sum((i - xm) * (v - ym) for i, v in enumerate(y)) / denom

def _canon_sid(x):
    """Kanonische Station-ID: numerische Strings ohne führende Nullen (bidirektionales Matching).
    Behandelt: '11035' → '11035', '011035' → '11035', '11035.0' → '11035',
               'tawes.11035' → '11035', 'ST.11035-01' → '11035' (längste Zifferngruppe)."""
    s = str(x).strip()
    # Direkt ganzzahlig (häufigster Fall: int oder numerischer String)
    try: return str(int(s))
    except (ValueError, TypeError): pass
    # Float-String wie '11035.0' → '11035'
    try: return str(int(float(s)))
    except (ValueError, TypeError): pass
    # Längste Zifferngruppe extrahieren: 'tawes.11035' → '11035', 'ST.11035-01' → '11035'
    # Längste statt letzte: vermeidet Fehlmatch bei Compound-IDs mit kurzer Nummer am Ende
    nums = re.findall(r'\d+', s)
    if nums: return str(int(max(nums, key=len)))
    return s

def load_tawes_stations():
    """Stationsliste laden. Cache-File wird beim Daemon-Start gelöscht (run()),
    daher ist beim ersten Aufruf immer ein frischer API-Abruf nötig.
    Danach: In-Memory bevorzugt, sonst Datei-Cache (< 24h), sonst API."""
    global _tawes_all_stations
    cache_age = time.time() - os.path.getmtime(TAWES_CACHE_FILE) if os.path.exists(TAWES_CACHE_FILE) else 999999

    # In-Memory Cache nutzen wenn vorhanden and Datei-Cache noch frisch
    if _tawes_all_stations and cache_age < 86400:
        return _tawes_all_stations

    # Datei-Cache nutzen wenn aktuell (< 24h)
    if os.path.exists(TAWES_CACHE_FILE) and cache_age < 86400:
        try:
            with open(TAWES_CACHE_FILE, 'r', encoding='utf-8') as f:
                loaded = json.load(f)
            cleaned = []
            for s in loaded:
                sid = _canon_sid(s.get('id', ''))
                if sid and sid != 'None':
                    cleaned.append({**s, 'id': sid})
            if cleaned:
                _tawes_all_stations = cleaned
                return _tawes_all_stations
        except Exception as e:
            log.warning(f'TAWES: Fehler beim Laden des Cache-Files: {e}')
    # Von API laden – IDs sofort kanonisieren beim Speichern
    data = fetch_json('https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min/metadata', 'TAWES-Metadata')
    if not data:
        return _tawes_all_stations  # Fallback auf alten Cache
    stations = []
    for s in data.get('stations', data.get('features', [])):
        try:
            p = s.get('properties', s)
            raw_id = p.get('id') or p.get('station_id') or s.get('id') or ''
            sid = _canon_sid(raw_id)
            if not sid or sid == 'None': continue
            name = p.get('name', p.get('station_name', sid))
            
            def _float(v):
                if v is None: return 0.0
                try: return float(v)
                except: return 0.0

            lat = _float(p.get('lat', p.get('latitude', 0)))
            lon = _float(p.get('lon', p.get('longitude', 0)))
            active = p.get('is_active', p.get('active', True))
            alt = _float(p.get('alt', p.get('altitude', p.get('elevation', p.get('hoehe', 0)))))
            
            if sid and lat and lon and active:
                stations.append({'id': sid, 'name': name, 'lat': lat, 'lon': lon, 'alt': alt})
        except Exception as e:
            log.debug(f'TAWES: Station-Parsing fehlgeschlagen: {e}')
            continue
    if stations:
        _tawes_all_stations = stations
        try:
            with open(TAWES_CACHE_FILE, 'w', encoding='utf-8') as f:
                json.dump(stations, f, ensure_ascii=False)
        except Exception as e:
            log.warning(f'TAWES: Fehler beim Speichern des Cache-Files: {e}')
        log.info(f'TAWES: {len(stations)} Stationen geladen und gecacht')
    return _tawes_all_stations

def find_nearby_stations():
    """Alle aktiven Stationen im Umkreis TAWES_MAX_KM, sortiert nach Distanz."""
    result = []
    for st in load_tawes_stations():
        dist = _haversine(LAT, LON, st['lat'], st['lon'])
        if 2 < dist <= TAWES_MAX_KM:
            bear = _bearing(LAT, LON, st['lat'], st['lon'])
            result.append({'id': st['id'], 'name': st['name'], 'lat': st['lat'], 'lon': st['lon'],
                           'alt': st.get('alt', 0), 'dist_km': round(dist, 1), 'bearing': round(bear, 1), 'bearing_name': _bearing_to_name(bear)})
    return sorted(result, key=lambda x: x['dist_km'])

def fetch_tawes_data(station_ids, duration_min=0):
    """Messdaten für Stationen abrufen. 
    Wenn duration_min > 0, wird der Timeseries-Endpoint für historische Daten genutzt."""
    if not station_ids: return {}
    ids = station_ids[:TAWES_MAX_STATIONS]
    sid_params = '&'.join(f'station_ids={sid}' for sid in ids)
    
    if duration_min > 0:
        # Historische Daten (Timeseries) um den Puffer zu füllen
        start_ts = (datetime.now(timezone.utc) - timedelta(minutes=duration_min)).strftime('%Y-%m-%dT%H:%M')
        url = f'https://dataset.api.hub.geosphere.at/v1/timeseries/historical/tawes-v1-10min?parameters=RR,FF,FFX,DD,P,RF&{sid_params}&start={start_ts}&output_format=geojson'
    else:
        # Aktuelle Momentaufnahme
        url = f'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min?parameters=RR,FF,FFX,DD,P,RF&{sid_params}&output_format=geojson'
    
    data = fetch_json(url, 'TAWES-Data')
    if not data: return {}
    
    ts_list = data.get('timestamps', [])
    features = data.get('features', [])
    canon_to_orig = {_canon_sid(str(i)): str(i) for i in ids}
    result_map = {} # sid -> list of data points

    for feature in features:
        props = feature.get('properties', {})
        raw_sid = props.get('station') or props.get('id') or feature.get('id') or ''
        api_canon = _canon_sid(str(raw_sid))
        matched_id = canon_to_orig.get(api_canon, str(raw_sid))
        params = props.get('parameters', {})
        
        station_points = []
        for i, timestamp in enumerate(ts_list):
            try:
                ts_epoch = int(datetime.fromisoformat(timestamp.replace('Z', '+00:00')).timestamp())
            except: ts_epoch = int(time.time())
            
            def _v(key, idx):
                raw = params.get(key, {}).get('data', [])
                if idx >= len(raw): return None
                v = raw[idx]
                if v is None: return None
                try:
                    fv = float(v)
                    return None if (math.isnan(fv) or math.isinf(fv)) else fv
                except: return None
            
            point = {'ts': ts_epoch, 'RR': _v('RR', i), 'FF': _v('FF', i), 'FFX': _v('FFX', i), 'DD': _v('DD', i), 'P': _v('P', i), 'RF': _v('RF', i)}
            station_points.append(point)
        
        if station_points:
            result_map[matched_id] = station_points

    # Für Abwärtskompatibilität mit dem restlichen Code: 
    # Wenn duration_min=0, geben wir sid -> {last_point} zurück.
    if duration_min == 0:
        return {sid: points[-1] for sid, points in result_map.items() if points}
    return result_map

def correlate_tawes(initial_history=False):
    """360° Korrelation: Upstream-Stationen → Regen-ETA, Windtrend, Gewitter-Signal."""
    global TAWES_BUFFER
    nearby = find_nearby_stations()
    if not nearby:
        log.info('TAWES: Keine Stationen im konfigurierten Umkreis gefunden')
        return {}

    # Wenn initial_history=True, füllen wir den Puffer mit den letzten 60 Minuten
    if initial_history:
        log.info('TAWES: Initialisiere Puffer mit historischen Daten (60min)...')
        hist_data = fetch_tawes_data([st['id'] for st in nearby], duration_min=60)
        for sid, points in hist_data.items():
            if sid not in TAWES_BUFFER: TAWES_BUFFER[sid] = deque(maxlen=12)
            for p in points: TAWES_BUFFER[sid].append(p)
        log.info(f'TAWES: Puffer für {len(hist_data)} Stationen gefüllt')

    # Aktuelle Messdaten abrufen
    raw_data = fetch_tawes_data([st['id'] for st in nearby])

    # API-Status: kein Ergebnis obwohl Stationen vorhanden → echter API-Fehler
    requested_count = min(len(nearby), TAWES_MAX_STATIONS)
    api_ok = len(raw_data) > 0 or len(nearby) == 0

    if not api_ok:
        log.error(f'TAWES API: Keine Daten zurück ({requested_count} Stationen angefragt)')
        return {'_api_ok': False}
    
    mit_ff  = sum(1 for v in raw_data.values() if v.get('FF')  is not None)
    mit_ffx = sum(1 for v in raw_data.values() if v.get('FFX') is not None)
    mit_rr  = sum(1 for v in raw_data.values() if v.get('RR')  is not None)
    log.info(f'TAWES API: {len(raw_data)}/{len(nearby)} Stationen erreicht | Sensors: FF:{mit_ff} FFX:{mit_ffx} RR:{mit_rr}')

    for sid, vals in raw_data.items():
        if sid not in TAWES_BUFFER:
            TAWES_BUFFER[sid] = deque(maxlen=12)
        TAWES_BUFFER[sid].append(vals)

    def _disp_val(sid, key, mult=1.0):
        cur = raw_data.get(sid, {}).get(key)
        if cur is not None:
            return round(cur * mult, 1)
        # Wenn aktuell null, in Puffer suchen (bis zu 30min zurück)
        for entry in reversed(list(TAWES_BUFFER.get(sid, []))[-3:]):
            v = entry.get(key)
            if v is not None:
                return round(v * mult, 1)
        return None

    # Schritt 1: Dominante Windrichtung
    sin_sum = cos_sum = 0.0; wr_count = 0
    for sid in raw_data:
        ff = raw_data[sid].get('FF'); dd = raw_data[sid].get('DD')
        if ff is not None and dd is not None and ff > 1.0:
            rad = math.radians(dd)
            sin_sum += math.sin(rad) * ff
            cos_sum += math.cos(rad) * ff
            wr_count += 1
    if wr_count > 0:
        dominante_wr = (math.degrees(math.atan2(sin_sum, cos_sum)) + 360) % 360
        dominante_wr_name = _bearing_to_name(dominante_wr)
    else:
        dominante_wr = 0.0; dominante_wr_name = '–'

    # Schritt 2: Upstream-Stationen bestimmen
    upstream_list = []
    stations_mit_daten = []
    for st in nearby:
        sid = st['id']
        if sid not in TAWES_BUFFER: continue
        vals = list(TAWES_BUFFER[sid])[-1] # Letzter bekannter Punkt
        ist_upstream = False
        if wr_count > 0:
            bearing_home = (st['bearing'] + 180) % 360
            wind_to = (dominante_wr + 180) % 360
            diff = abs(wind_to - bearing_home)
            diff = min(diff, 360 - diff)
            ist_upstream = diff < 70
        
        # Display-Werte mit Puffer-Fallback
        ff_kmh  = _disp_val(sid, 'FF',  mult=3.6)
        ffx_kmh = _disp_val(sid, 'FFX', mult=3.6)
        rr_raw  = _disp_val(sid, 'RR')
        
        entry = dict(st, ist_upstream=ist_upstream, RR=rr_raw, FF_kmh=ff_kmh,
                     FFX_kmh=ffx_kmh, DD=vals.get('DD'), P=vals.get('P'), RF=vals.get('RF'))
        if ist_upstream:
            upstream_list.append(entry)
        stations_mit_daten.append(entry)

    # Upstream Wind & Sturm
    wind_upstream_kmh = 0.0; sturm_upstream = 0; alpine_upstream_count = 0
    if upstream_list:
        ffx_vals = []; ffx_vals_alpin = []
        for _s in upstream_list:
            if _s.get('FFX_kmh') is None: continue
            if TAWES_MAX_UPSTREAM_HOEHE > 0 and _s.get('alt', 0) > TAWES_MAX_UPSTREAM_HOEHE:
                ffx_vals_alpin.append(_s['FFX_kmh'])
            else:
                ffx_vals.append(_s['FFX_kmh'])
        alpine_upstream_count = len(ffx_vals_alpin)
        if ffx_vals:
            wind_upstream_kmh = round(max(ffx_vals), 1)
            alarm_count = sum(1 for v in ffx_vals if v >= BOEN_ALARM)
            min_bestaetigt = max(2, round(len(ffx_vals) * TAWES_MIN_ALARM_PCT / 100))
            sturm_upstream = int(len(ffx_vals) >= 2 and alarm_count >= min_bestaetigt)

    # Regen-ETA
    regen_upstream = 0; regen_eta_min = -1; front_speed_kmh = 0.0; regen_upstream_mm = 0.0
    regen_start = {}
    for st in upstream_list:
        sid = st['id']
        buf = list(TAWES_BUFFER.get(sid, []))
        for i in range(len(buf)-1, max(-1, len(buf)-4), -1):
            if (buf[i].get('RR') or 0) > 0.1:
                regen_start[sid] = -(len(buf)-1-i); regen_upstream = 1; break
    
    if regen_upstream:
        upstream_mit_daten = len(upstream_list)
        min_regen_bestaetigt = max(1, round(upstream_mit_daten * TAWES_MIN_ALARM_PCT / 100))
        if len(regen_start) >= min_regen_bestaetigt:
            for sid in regen_start:
                max_rr = max((b.get('RR') or 0 for b in list(TAWES_BUFFER[sid])[-3:]), default=0)
                if max_rr > regen_upstream_mm: regen_upstream_mm = max_rr
            regen_upstream_mm = round(regen_upstream_mm * 6, 1)

    # Lokal-Regen
    regen_lokal = 0; regen_lokal_mm = 0.0; regen_lokal_station = ''
    REGEN_LOKAL_KM = 40.0; REGEN_LOKAL_ALARM_KM = TAWES_REGEN_LOKAL_KM
    lokal_mit_regen = [st for st in stations_mit_daten if st['dist_km'] <= REGEN_LOKAL_KM and (st.get('RR') or 0) > 0.1]
    if lokal_mit_regen:
        regen_lokal = 1
        lokal_alarm = [st for st in lokal_mit_regen if st['dist_km'] <= REGEN_LOKAL_ALARM_KM]
        if lokal_alarm:
            best = max(lokal_alarm, key=lambda s: s.get('RR') or 0)
            regen_lokal_mm = round((best.get('RR') or 0) * 6, 1)
            regen_lokal_station = f'{best["name"]} ({best["dist_km"]:.0f}km) {regen_lokal_mm}mm/h'

    # Front-Geschwindigkeit & ETA
    upstream_mit_regen = sorted([st for st in upstream_list if st['id'] in regen_start], key=lambda x: x['dist_km'], reverse=True)
    if len(upstream_mit_regen) >= 2:
        speeds = []
        for i in range(len(upstream_mit_regen)-1):
            f, n = upstream_mit_regen[i], upstream_mit_regen[i+1]
            dt = (regen_start[n['id']] - regen_start[f['id']]) * 10
            if dt > 0:
                s = (f['dist_km'] - n['dist_km']) / dt * 60
                if 10 < s < 180: speeds.append(s)
        if speeds: front_speed_kmh = round(sum(speeds)/len(speeds), 1)
    
    if regen_upstream and front_speed_kmh > 0:
        ns = sorted(upstream_mit_regen, key=lambda x: x['dist_km'])[0]
        rem_km = ns['dist_km'] - (abs(regen_start[ns['id']])*10/60 * front_speed_kmh)
        regen_eta_min = max(0, int(rem_km / front_speed_kmh * 60))

    # Wind-Kaskade
    wind_kaskade = 0; wind_kaskade_eta_min = -1; wind_kaskade_speed_kmh = 0.0; wind_kaskade_stationen = []
    wind_start = {}
    upstream_tal = [s for s in upstream_list if not (TAWES_MAX_UPSTREAM_HOEHE > 0 and s.get('alt', 0) > TAWES_MAX_UPSTREAM_HOEHE)]
    for st in upstream_tal:
        buf = list(TAWES_BUFFER[st['id']])
        for i in range(len(buf)-1, max(-1, len(buf)-7), -1):
            if (buf[i].get('FFX') or 0) * 3.6 >= BOEN_ALARM:
                wind_start[st['id']] = -(len(buf)-1-i); break
    
    if len(wind_start) >= 2:
        ws_sorted = sorted([st for st in upstream_tal if st['id'] in wind_start], key=lambda x: x['dist_km'], reverse=True)
        if all(wind_start[ws_sorted[i]['id']] <= wind_start[ws_sorted[i+1]['id']] for i in range(len(ws_sorted)-1)):
            wind_kaskade = 1; wind_kaskade_stationen = ws_sorted
            f, n = ws_sorted[0], ws_sorted[-1]
            dt = (wind_start[n['id']] - wind_start[f['id']]) * 10
            if dt > 0:
                s = (f['dist_km'] - n['dist_km']) / dt * 60
                if 10 < s < 200:
                    wind_kaskade_speed_kmh = round(s, 1)
                    wind_kaskade_eta_min = max(0, int(n['dist_km']/s*60 - abs(wind_start[n['id']])*10))

    # Trends & Signale
    naechste_upstream = upstream_list[0] if upstream_list else (nearby[0] if nearby else None)
    wind_trend = 0; gewitter_signal = 0; druck_trend = 0.0; konfidenz = 0
    if naechste_upstream:
        buf = list(TAWES_BUFFER.get(naechste_upstream['id'], []))
        if len(buf) >= 4:
            ffx_r = [(b.get('FFX')*3.6 if b.get('FFX') is not None else None) for b in buf[-6:]]
            wind_trend = 1 if _linreg_slope(ffx_r) > 1.0 else (-1 if _linreg_slope(ffx_r) < -1.0 else 0)
            druck_trend = round(_linreg_slope([b.get('P') for b in buf[-6:]]), 2)
            if druck_trend < -0.5 and (naechste_upstream.get('RF') or 0) > 85:
                gewitter_signal = 2 if _linreg_slope(ffx_r) > 3.0 else 1
    
    if len(upstream_mit_regen) >= 2: konfidenz += 40
    if 10 <= front_speed_kmh <= 150: konfidenz += 30
    if wind_trend == 1: konfidenz += 10

    notif = ''
    if gewitter_signal: notif = f'{"🔴 AKUTE GEWITTERGEFAHR" if gewitter_signal==2 else "⚡ Gewittergefahr"} | Druck {abs(druck_trend):.1f} hPa/10min'
    elif sturm_upstream: notif = f'💨 Sturmböen {naechste_upstream["name"]} ({naechste_upstream["dist_km"]}km): {round(wind_upstream_kmh/5)*5} km/h'
    elif wind_kaskade: notif = f'💨 Sturmfront aus {dominante_wr_name} | ETA ~{round(wind_kaskade_eta_min/5)*5}min'
    elif regen_upstream:
        if konfidenz >= 50: notif = f'🌧️ Regenfront ~{round(regen_eta_min/5)*5}min | {konfidenz}%'
        else: notif = f'🌧️ Regen bei {naechste_upstream["name"]} ({naechste_upstream["dist_km"]}km)'
    elif regen_lokal: notif = f'🌧️ Lokal-Regen: {regen_lokal_station or f"{regen_lokal_mm} mm/h"}'

    return {
        'letztes_update': datetime.now().astimezone().strftime('%d.%m.%Y %H:%M:%S'),
        'dominante_windrichtung': round(dominante_wr, 1), 'dominante_windrichtung_name': dominante_wr_name,
        'wind_upstream_kmh': wind_upstream_kmh, 'wind_trend': wind_trend, 'sturm_upstream': sturm_upstream,
        'regen_upstream': regen_upstream, 'regen_upstream_mm': regen_upstream_mm, 'regen_eta_min': regen_eta_min,
        'regen_konfidenz': konfidenz, 'front_speed_kmh': front_speed_kmh, 'druck_trend': druck_trend,
        'gewitter_signal': gewitter_signal, 'upstream_aktiv': len(upstream_list), 'wind_kaskade': wind_kaskade,
        'wind_kaskade_eta_min':        wind_kaskade_eta_min,
        'wind_kaskade_speed_kmh':      wind_kaskade_speed_kmh,
        'regen_lokal':                 regen_lokal,
        'regen_lokal_mm':              regen_lokal_mm,
        'regen_lokal_station':         regen_lokal_station,
        'alpine_upstream':             alpine_upstream_count,
        'naechste_station_name':       naechste_upstream['name'] if naechste_upstream else '–',
        'naechste_station_km':         naechste_upstream['dist_km'] if naechste_upstream else 0,
        'naechste_station_richtung':   naechste_upstream['bearing_name'] if naechste_upstream else '–',
        'notification':                notif,
        'alle_stationen':              stations_mit_daten,
        '_api_ok':                     True,
    }

def _cleanup_tawes_buffer(nearby_ids_set):
    """Buffer-Einträge für nicht mehr relevante Stationen entfernen (Windrichtung, Radius geändert)."""
    stale = [sid for sid in list(TAWES_BUFFER.keys()) if sid not in nearby_ids_set]
    for sid in stale:
        del TAWES_BUFFER[sid]
    if stale:
        log.debug(f'TAWES Buffer: {len(stale)} nicht mehr relevante Station(en) bereinigt')

def publish_tawes(tawes):
    """TAWES MQTT Topics publizieren."""
    if not tawes: return
    for k in ['dominante_windrichtung','dominante_windrichtung_name','wind_upstream_kmh',
              'wind_trend','sturm_upstream','wind_kaskade','wind_kaskade_eta_min','wind_kaskade_speed_kmh',
              'regen_upstream','regen_upstream_mm','regen_eta_min',
              'regen_konfidenz','front_speed_kmh','druck_trend','gewitter_signal','upstream_aktiv',
              'regen_lokal','regen_lokal_mm','alpine_upstream',
              'regen_lokal_station']:
        publish(f'tawes/{k}', tawes.get(k, 0))
    publish('tawes/stationen_anzahl', len(tawes.get('alle_stationen', [])))
    publish('tawes/letztes_update', tawes.get('letztes_update', ''))
    publish('tawes/api_ok', int(tawes.get('_api_ok', True)))
    ns = f'{tawes.get("naechste_station_name","–")} ({tawes.get("naechste_station_km",0)}km, {tawes.get("naechste_station_richtung","–")})'
    publish('tawes/naechste_station', ns)
    # notification/tawes wird dedupliziert in publish_all() publiziert

# ---------------------------------------------------------------------------
# Aggregierter Alarm-Status (alle Quellen kombiniert → alarm/ Topics)
# ---------------------------------------------------------------------------
def build_alarm(zamg, inca, tawes, akut):
    """Kombiniert ZAMG + INCA + TAWES zu einem einheitlichen Alarm-Dict.
    Level: 0=ruhig, 1=Vorsicht (Gelb/1×Schwelle), 2=Warnung (Orange/2×Schwelle), 3=Extrem (Rot/Lila/3×Schwelle)
    ZAMG-Stufe mappt direkt: Gelb→1, Orange→2, Rot/Lila→3.
    INCA/TAWES: 1×Schwelle→1, 2×→2, 3×→3."""

    # Gewitter: ZAMG Gelb→1, Orange→2, Rot/Lila→3; TAWES Signal 0/1/2 direkt verwendbar
    g_stufe = zamg.get('gewitter', {}).get('stufe', 0)
    if g_stufe >= 3:   gewitter = 3
    elif g_stufe == 2: gewitter = 2
    elif g_stufe == 1: gewitter = 1
    else:              gewitter = 0
    gewitter = max(gewitter, tawes.get('gewitter_signal', 0))  # TAWES: 0=kein, 1=möglich, 2=akut
    if akut: gewitter = max(gewitter, 2)

    # Wind: ZAMG Gelb→1, Orange→2, Rot/Lila→3
    # INCA fx_max_60min / TAWES upstream_kmh: 1×BOEN_ALARM→1, 2×→2, 3×→3
    w_stufe = zamg.get('wind', {}).get('stufe', 0)
    if w_stufe >= 3:   wind = 3
    elif w_stufe == 2: wind = 2
    elif w_stufe == 1: wind = 1
    else:              wind = 0
    wind_quelle = 'ZAMG' if wind > 0 else '–'
    fx60 = float(inca.get('fx_max_60min', 0) or 0)
    if fx60 >= BOEN_ALARM * 3:
        if max(wind, 3) > wind: wind_quelle = f'INCA ({fx60}km/h)'
        wind = max(wind, 3)
    elif fx60 >= BOEN_ALARM * 2:
        if max(wind, 2) > wind: wind_quelle = f'INCA ({fx60}km/h)'
        wind = max(wind, 2)
    elif fx60 >= BOEN_ALARM:
        if max(wind, 1) > wind: wind_quelle = f'INCA ({fx60}km/h)'
        wind = max(wind, 1)
    # TAWES Wind: Konsens (sturm_upstream=1) MUSS bestätigt sein.
    if tawes.get('sturm_upstream', 0):
        tawes_wind = float(tawes.get('wind_upstream_kmh', 0) or 0)
        if tawes_wind >= BOEN_ALARM * 3:
            if max(wind, 3) > wind: wind_quelle = f'TAWES_STURM ({tawes_wind}km/h)'
            wind = max(wind, 3)
        elif tawes_wind >= BOEN_ALARM * 2:
            if max(wind, 2) > wind: wind_quelle = f'TAWES_STURM ({tawes_wind}km/h)'
            wind = max(wind, 2)
        elif tawes_wind >= BOEN_ALARM:
            if max(wind, 1) > wind: wind_quelle = f'TAWES_STURM ({tawes_wind}km/h)'
            wind = max(wind, 1)
    # Wind-Kaskade ohne Konsens → Level 1 (Vorsicht / Vorwarnung)
    elif tawes.get('wind_kaskade', 0):
        if max(wind, 1) > wind: wind_quelle = 'TAWES_KASKADE'
        wind = max(wind, 1)

    # Regen: ZAMG Gelb→1, Orange→2, Rot/Lila→3
    # INCA rr_jetzt (mm/h) / TAWES upstream_mm (mm/h): 1×REGEN_ALARM→1, 2×→2, 3×→3
    r_stufe = zamg.get('regen', {}).get('stufe', 0)
    if r_stufe >= 3:   regen = 3
    elif r_stufe == 2: regen = 2
    elif r_stufe == 1: regen = 1
    else:              regen = 0
    regen_quelle = 'ZAMG' if regen > 0 else '–'
    rr = float(inca.get('rr_jetzt', 0) or 0)
    if rr >= REGEN_ALARM * 3:
        if max(regen, 3) > regen: regen_quelle = f'INCA ({rr}mm/h)'
        regen = max(regen, 3)
    elif rr >= REGEN_ALARM * 2:
        if max(regen, 2) > regen: regen_quelle = f'INCA ({rr}mm/h)'
        regen = max(regen, 2)
    elif rr >= REGEN_ALARM:
        if max(regen, 1) > regen: regen_quelle = f'INCA ({rr}mm/h)'
        regen = max(regen, 1)
    upstream_mm = float(tawes.get('regen_upstream_mm', 0) or 0)
    if upstream_mm >= REGEN_ALARM * 3:
        if max(regen, 3) > regen: regen_quelle = f'TAWES_UPSTREAM ({upstream_mm}mm/h)'
        regen = max(regen, 3)
    elif upstream_mm >= REGEN_ALARM * 2:
        if max(regen, 2) > regen: regen_quelle = f'TAWES_UPSTREAM ({upstream_mm}mm/h)'
        regen = max(regen, 2)
    elif upstream_mm >= REGEN_ALARM:
        if max(regen, 1) > regen: regen_quelle = f'TAWES_UPSTREAM ({upstream_mm}mm/h)'
        regen = max(regen, 1)
    lokal_mm = float(tawes.get('regen_lokal_mm', 0) or 0)
    lokal_station = tawes.get('regen_lokal_station', '')
    if lokal_mm >= REGEN_ALARM * 3:
        if max(regen, 3) > regen: regen_quelle = f'TAWES_LOKAL ({lokal_station or lokal_mm}mm/h)'
        regen = max(regen, 3)
    elif lokal_mm >= REGEN_ALARM * 2:
        if max(regen, 2) > regen: regen_quelle = f'TAWES_LOKAL ({lokal_station or lokal_mm}mm/h)'
        regen = max(regen, 2)
    elif lokal_mm >= REGEN_ALARM:
        if max(regen, 1) > regen: regen_quelle = f'TAWES_LOKAL ({lokal_station or lokal_mm}mm/h)'
        regen = max(regen, 1)

    # Hagel: ZAMG Gelb→1, Orange/höher→2; INCA bald_hagel/graupel→1
    h_stufe = zamg.get('hagel', {}).get('stufe', 0)
    if h_stufe >= 2:   hagel = 2
    elif h_stufe == 1: hagel = 1
    else:              hagel = 0
    if inca.get('bald_hagel'):   hagel = max(hagel, 1)
    if inca.get('bald_graupel'): hagel = max(hagel, 1)

    # Schnee/Glatteis: max(ZAMG schnee, glatteis) Gelb→1, Orange/höher→2; INCA Niederschlagstyp
    s_stufe = max(zamg.get('schnee', {}).get('stufe', 0), zamg.get('glatteis', {}).get('stufe', 0))
    if s_stufe >= 2:   schnee = 2
    elif s_stufe == 1: schnee = 1
    else:              schnee = 0
    if inca.get('pt_jetzt') in (2, 3): schnee = max(schnee, 1)  # 2=Schnee, 3=Schneeregen

    # Gesamtstatus: höchster Wert aller Kategorien
    gesamt = max(gewitter, wind, regen, hagel, schnee)

    # Gesamtstufe (max aus ZAMG für Referenz, unverändert 0-4)
    all_types = list(WARN_TYPES.values()) + ['hagel']
    max_stufe = max((zamg.get(t, {}).get('stufe', 0) for t in all_types), default=0)

    parts = []
    if gewitter >= 3:   parts.append('⚡ Gewitter EXTREM')
    elif gewitter == 2: parts.append('⚡ Gewitter Warnung')
    elif gewitter:      parts.append('⚡ Gewitter möglich')
    if wind >= 3:       parts.append('💨 Extremsturm')
    elif wind == 2:     parts.append('💨 Sturm Warnung')
    elif wind:          parts.append('💨 Wind Vorsicht')
    if hagel >= 2:      parts.append('🌨 Hagel Warnung')
    elif hagel:         parts.append('🌨 Hagelgefahr')
    if regen >= 3:      parts.append('🌧 Extremregen')
    elif regen == 2:    parts.append('🌧 Starkregen')
    elif regen:         parts.append('🌧 Regen erwartet')
    if schnee >= 2:     parts.append('❄️ Schnee/Eis Warnung')
    elif schnee:        parts.append('❄️ Schnee/Eis möglich')
    zusammenfassung = ' | '.join(parts) if parts else '✅ Keine Warnungen'

    return {
        'gewitter': gewitter, 'wind': wind, 'regen': regen,
        'hagel': hagel, 'schnee': schnee, 'gesamt': gesamt,
        'stufe': int(max_stufe), 'zusammenfassung': zusammenfassung,
        'wind_quelle': wind_quelle, 'regen_quelle': regen_quelle,
    }

def publish_alarm(alarm):
    for k in ['gewitter', 'wind', 'regen', 'hagel', 'schnee', 'gesamt', 'stufe']:
        publish(f'alarm/{k}', alarm.get(k, 0))
    publish('alarm/zusammenfassung', alarm.get('zusammenfassung', ''))
    publish('alarm/entwarnung', alarm.get('entwarnung', 0))
    publish('alarm/wind_quelle',  alarm.get('wind_quelle',  '–'))
    publish('alarm/regen_quelle', alarm.get('regen_quelle', '–'))

# ---------------------------------------------------------------------------
# State
# ---------------------------------------------------------------------------
def load_state():
    """Letzten bekannten Zustand aus JSON-Datei laden."""
    try:
        if os.path.exists(STATE_FILE):
            with open(STATE_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
    except Exception as e:
        log.warning(f'State laden fehlgeschlagen: {e}')
    return {}

def save_state(data):
    """Aktuellen Zustand in JSON-Datei speichern – atomarer Write via .tmp Datei."""
    try:
        tmp = STATE_FILE + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2, allow_nan=False)
        os.replace(tmp, STATE_FILE)
    except Exception as e:
        log.warning(f'State speichern fehlgeschlagen: {e}')
        try:
            if os.path.exists(STATE_FILE + '.tmp'): os.remove(STATE_FILE + '.tmp')
        except: pass

# ---------------------------------------------------------------------------
# MQTT publish + State save
# ---------------------------------------------------------------------------
def publish_all(zamg, akut, inca, prev_ids, new_ids, status_msg, tawes=None, prev=None, zamg_ok=True, inca_ok=True):
    prev = prev or {}
    all_types = list(WARN_TYPES.values()) + ['hagel']
    for t in all_types:
        w = zamg.get(t, {})
        for k in ['stufe','aktiv','bald','start_epoch','end_epoch','start_text','end_text','notification']:
            publish(f'zamg/{t}/{k}', w.get(k, 0 if k != 'notification' and not k.endswith('text') else ''))
    publish('zamg/akutwarnung', akut)
    max_stufe = max((zamg.get(t, {}).get('stufe', 0) for t in all_types), default=0)
    irgendwas = int(any(zamg.get(t, {}).get('aktiv', 0) or zamg.get(t, {}).get('bald', 0) for t in all_types) or akut)
    publish('zamg/max_stufe', max_stufe); publish('zamg/irgendwas_aktiv', irgendwas)
    if inca:
        for k in ['fx_jetzt','ff_jetzt','fx_max_30min','fx_max_60min','rr_jetzt','pt_jetzt','pt_name','pt_bald','pt_bald_name','bald_regen','bald_hagel','bald_graupel','bald_sturm_30','bald_sturm_60','minuten_bis_regen']:
            publish(f'inca/{k.replace("_jetzt","") if "jetzt" in k else k}', inca.get(k, 0))
        publish('inca/regen_alarm', inca.get('regen_alarm', 0))
    z_lines = [zamg[t]['notification'] for t in all_types if zamg.get(t,{}).get('stufe',0) >= MIN_STUFE and zamg[t]['notification']]
    if akut: z_lines.insert(0, L['akut_active'])
    def _rnd_n(v, n): return round(float(v or 0) / n) * n

    i_lines = []
    if inca:
        if inca.get('bald_hagel'): i_lines.append(L['hagel_60'])
        elif inca.get('bald_graupel'): i_lines.append(L['graupel_60'])
        if inca.get('bald_sturm_30'):
            i_lines.append(L['sturm_30'].format(v=_rnd_n(inca["fx_max_30min"], 5)))
        elif inca.get('bald_sturm_60'):
            i_lines.append(L['sturm_60'].format(v=_rnd_n(inca["fx_max_60min"], 5)))
        m = inca.get('minuten_bis_regen', -1)
        m_r = _rnd_n(m, 5) if m >= 0 else -1
        if inca.get('bald_regen') and (inca.get('regen_alarm') or REGEN_ALARM <= 2.0):
            i_lines.append(L['regen_in'].format(v=m_r))
        elif inca.get('rr_jetzt', 0) > 0:
            i_lines.append(L['regen_jetzt'].format(v=inca["rr_jetzt"]))
        i_lines_hat_inhalt = bool(i_lines)
        if not i_lines: i_lines.append(L['kein_alarm'].format(v=_rnd_n(inca.get("fx_jetzt", 0), 1)))
    else:
        i_lines_hat_inhalt = False
    n_geo       = '\n'.join(z_lines) if z_lines else L['no_warns']
    n_inca      = '\n'.join(i_lines) if i_lines else L['no_warns']
    n_tawes_raw = (tawes or {}).get('notification', '')
    n_tawes     = n_tawes_raw or L['no_warns']

    hatte_warn = prev.get('hatte_aktiv', False)
    if hatte_warn and not irgendwas and not z_lines:
        n_geo = L['entwarnung']
        log.info('ZAMG: Entwarnung – alle Wetterwarnungen aufgehoben')

    n_alle = '\n──\n'.join(filter(None, [
        n_geo if (z_lines or n_geo == L['entwarnung']) else '',
        n_inca if i_lines_hat_inhalt else '',
        n_tawes_raw,
    ])) or n_inca

    alarm = build_alarm(zamg, inca, tawes or {}, akut)
    alarm_gesamt      = alarm.get('gesamt', 0)
    prev_alarm_gesamt = prev.get('alarm', {}).get('gesamt', 0)
    entwarnung_jetzt  = (prev_alarm_gesamt >= 1 and alarm_gesamt == 0)
    alarm['entwarnung'] = 1 if entwarnung_jetzt else 0
    if entwarnung_jetzt:
        log.info(f'Alarm: Entwarnung – alarm/gesamt von {prev_alarm_gesamt} auf 0 gefallen')

    if n_geo != prev.get('_n_geo', ''): publish('notification/geosphere', n_geo)
    if alarm_gesamt >= 1:
        if n_inca  != prev.get('_n_inca',  ''): publish('notification/inca',  n_inca)
        if n_tawes != prev.get('_n_tawes', ''): publish('notification/tawes', n_tawes)
        if n_alle  != prev.get('_n_alle',  ''): publish('notification/alle',  n_alle)
    else:
        n_inca  = ''
        n_tawes = ''
        n_alle  = L['entwarnung'] if entwarnung_jetzt else ''
        publish('notification/inca',  n_inca)
        publish('notification/tawes', n_tawes)
        publish('notification/alle',  n_alle)

    now = datetime.now().astimezone(); ts_iso = now.strftime('%d.%m.%Y %H:%M:%S'); ts_epoch = int(now.timestamp())
    zamg_ts  = ts_iso  if zamg_ok  else (prev or {}).get('zamg_letztes_update',  '–')
    inca_ts  = ts_iso  if inca_ok  else (prev or {}).get('inca_letztes_update',  '–')
    tawes_ts = (tawes or {}).get('letztes_update', (prev or {}).get('tawes_letztes_update', '–'))
    
    publish('letzter_abruf_datum', ts_iso); publish('letzter_abruf_epoch', ts_epoch)
    publish('status/last_seen',    ts_epoch)  # Heartbeat-Topic
    publish('zamg/letzter_abruf',  zamg_ts)
    publish('inca/letzter_abruf',  inca_ts)
    
    # Status-Badge Text
    display_status = L['status_ok'] if status_msg == 'OK' else L['status_err'].format(v=status_msg)
    publish('status', display_status, retain=True)
    
    publish('status/zamg_ok',  int(zamg_ok))
    publish('status/inca_ok',  int(inca_ok))
    tawes_data_ok = bool(tawes and tawes.get('_api_ok', bool(tawes.get('alle_stationen'))))
    publish('status/tawes_ok', int(tawes_data_ok))
    
    publish_alarm(alarm)
    save_state({
        'last_warn_ids': new_ids, 'hatte_aktiv': bool(irgendwas), 'letztes_update': ts_iso,
        'letzter_abruf_epoch': ts_epoch, 'status': status_msg, 'zamg': zamg, 'inca': inca,
        'akutwarnung': akut, 'max_stufe': int(max_stufe), 'irgendwas_aktiv': irgendwas,
        'notification_alle': n_alle, 'alarm': alarm, 'tawes': tawes if tawes else {},
        '_n_geo': n_geo, '_n_inca': n_inca, '_n_tawes': n_tawes, '_n_alle': n_alle,
        'zamg_letztes_update': zamg_ts, 'inca_letztes_update': inca_ts, 'tawes_letztes_update': tawes_ts,
    })

# ---------------------------------------------------------------------------
# Hauptloop
# ---------------------------------------------------------------------------
def run():
    global _tawes_last_fetch, _tawes_fehler_count
    _tawes_fehler_count = 0
    log.info(f'Unwetter4Lox v0.4.31 gestartet | {LAT},{LON} | Interval={INTERVAL}s | ZAMG={ZAMG_ENABLED} INCA={INCA_ENABLED} TAWES={TAWES_ENABLED}')
    
    # PID File schreiben
    try:
        with open(PID_FILE, 'w') as f: f.write(str(os.getpid()))
    except Exception as e: log.warning(f'PID-File konnte nicht geschrieben werden: {e}')
    
    # TAWES Station-Cache beim Start löschen
    try:
        if os.path.exists(TAWES_CACHE_FILE):
            os.remove(TAWES_CACHE_FILE)
            log.info('TAWES: Station-Cache gelöscht – frischer API-Abruf beim ersten Lauf')
    except Exception as e: log.warning(f'TAWES: Cache löschen fehlgeschlagen: {e}')
    
    _tawes_last_fetch = 0
    while True:
        try:
            loop_start = time.time()
            if not _mqtt_connected.is_set():
                log.info('MQTT: Verbindungsaufbau...')
                mqtt_connect()
            
            prev = load_state(); prev_ids = prev.get('last_warn_ids', [])
            status, zamg, akut, new_ids, inca = 'OK', {}, 0, [], {}
            zamg_ok = False; inca_ok = False
            
            if ZAMG_ENABLED:
                zamg, akut, new_ids = fetch_zamg()
                if zamg is None:
                    status, zamg = 'GeoSphere API Error', {}
                else: zamg_ok = True
            
            if INCA_ENABLED:
                inca = fetch_inca()
                if inca is None:
                    status = 'INCA Error' if status == 'OK' else status + ' & INCA Error'
                    inca = {}
                else: inca_ok = True
            
            tawes = prev.get('tawes', {})
            if TAWES_ENABLED and time.time() - _tawes_last_fetch > 480:
                try:
                    new_tawes = correlate_tawes()
                    if new_tawes.get('_api_ok', True) and new_tawes.get('alle_stationen') is not None:
                        nearby_ids = {st['id'] for st in new_tawes.get('alle_stationen', [])}
                        _cleanup_tawes_buffer(nearby_ids)
                        tawes = new_tawes
                        publish_tawes(tawes)
                        _tawes_last_fetch = time.time()
                        _tawes_fehler_count = 0
                    else:
                        _tawes_fehler_count += 1
                        if _tawes_fehler_count >= 3:
                            status = 'TAWES API Error' if status == 'OK' else status + ' & TAWES Error'
                        _tawes_last_fetch = time.time() - 390
                        publish('tawes/api_ok', 0)
                except Exception as te:
                    _tawes_fehler_count += 1
                    log.error(f'TAWES Ausnahme: {te}')
            
            publish_all(zamg, akut, inca, prev_ids, new_ids, status, tawes, prev=prev, zamg_ok=zamg_ok, inca_ok=inca_ok)
            
            duration = time.time() - loop_start
            log.debug(f'Loop abgeschlossen in {duration:.1f}s. Schlafen {INTERVAL}s...')
            
        except Exception as e:
            log.error(f'Hauptloop Fehler: {e}')
            try: publish('status', L['status_err'].format(v=str(e)[:40]), retain=True)
            except: pass
            
        time.sleep(INTERVAL)
