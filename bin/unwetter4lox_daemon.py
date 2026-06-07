#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Unwetter4Lox Daemon v0.4.3 – GeoSphere (ZAMG) + INCA + TAWES 360° -> MQTT"""
import os, sys, json, time, logging, configparser, urllib.request, signal, subprocess, glob, threading, math
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
TAWES_ENABLED   = get_cfg('TAWES',         'ENABLED',              '1') == '1'
TAWES_MAX_KM    = float(get_cfg('TAWES',   'MAX_DISTANCE_KM',     '120'))

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
        'pt_none': 'kein Niederschlag', 'status_ok': 'OK', 'status_err': 'Fehler - {v}'
    },
    'en': {
        'wind': 'Wind', 'regen': 'Rain', 'schnee': 'Snow', 'glatteis': 'Ice', 'gewitter': 'Thunderstorm', 'hitze': 'Heat', 'kaelte': 'Cold', 'hagel': 'Hail',
        'sturmböen': 'Storm gusts expected', 'gewitter_desc': 'Thunderstorms possible', 'hagel_desc': 'Hail possible', 'regen_desc': 'Heavy rain expected',
        'schnee_desc': 'Snowfall expected', 'glatteis_desc': 'Ice hazard', 'heute': 'today', 'morgen': 'tomorrow',
        'akut_active': '🚨 Automatic acute warning active', 'hagel_60': '🔴 Hail expected in <60 min', 'graupel_60': '⚠️ Graupel possible in <60 min',
        'sturm_30': '🟠 Storm gusts <30 min: max {v} km/h', 'sturm_60': '⚠️ Storm gusts <60 min: max {v} km/h',
        'regen_in': '🌧️ Rain in ~{v} min', 'regen_jetzt': '🌧️ Rain: {v} mm/h', 'kein_alarm': '✅ no alarm | Gusts: {v} km/h',
        'no_warns': 'no active warnings', 'entwarnung': '✅ All-clear – all weather warnings lifted.',
        'pt_none': 'no precipitation', 'status_ok': 'OK', 'status_err': 'Error - {v}'
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
    if rc == 0: _mqtt_connected.set()
def _on_disconnect(c, u, rc): _mqtt_connected.clear()
def mqtt_connect():
    global mqtt_client
    if not MQTT_OK: return False
    _mqtt_connected.clear()
    try:
        try: mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id='unwetter4lox', clean_session=True)
        except: mqtt_client = mqtt.Client(client_id='unwetter4lox', clean_session=True)
        mqtt_client.on_connect, mqtt_client.on_disconnect = _on_connect, _on_disconnect
        if MQTT_USER: mqtt_client.username_pw_set(MQTT_USER, MQTT_PASS)
        mqtt_client.connect(MQTT_BROKER, MQTT_PORT, 60); mqtt_client.loop_start()
        return _mqtt_connected.wait(timeout=8)
    except: return False

def publish(topic, value, retain=True):
    if mqtt_client and _mqtt_connected.is_set():
        try: mqtt_client.publish(f'{TOPIC_PREFIX}/{topic}', str(value) if value is not None else '', qos=0, retain=retain)
        except: pass

def fetch_json(url, provider):
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Unwetter4Lox/0.4.0'})
        with urllib.request.urlopen(req, timeout=15) as r: return json.loads(r.read().decode())
    except Exception as e: log.error(f'HTTP {provider}: {e}'); return None

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
    now, res = datetime.now(tz=timezone.utc), dict(ff_jetzt=0.0, fx_jetzt=0.0, fx_max_30min=0.0, fx_max_60min=0.0, rr_jetzt=0.0, pt_jetzt=255, pt_name=L['pt_none'], bald_regen=0, bald_hagel=0, bald_graupel=0, bald_sturm_30=0, bald_sturm_60=0, minuten_bis_regen=-1)
    for param in ['ff', 'fx', 'rr', 'pt']:
        url = f'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km?lat_lon={LAT}%2C{LON}&parameters={param}&output_format=geojson'
        data = fetch_json(url, f'INCA {param}')
        if not data: return None
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
                    res['minuten_bis_regen'], res['bald_regen'] = max(0, int((t - now.timestamp()) / 60)), int(t <= now.timestamp()+1800); break
        elif param == 'pt':
            serie = [int(v) if v else 255 for v in values]; res['pt_jetzt'] = serie[0] if serie else 255
            res['pt_name'] = PT_NAME.get(res['pt_jetzt'], 'unbekannt')
            for i, ts in enumerate(ts_list):
                if i < len(serie) and datetime.fromisoformat(ts).timestamp() <= now.timestamp()+3600:
                    if serie[i] == 5: res['bald_hagel'] = 1
                    if serie[i] == 4: res['bald_graupel'] = 1
    return res

# ---------------------------------------------------------------------------
# TAWES 360° Stationsnetz & Korrelation
# ---------------------------------------------------------------------------
_tawes_all_stations = []  # In-Memory Cache der Stationsliste
TAWES_BUFFER = {}          # station_id → deque(maxlen=12) [12×10min = 2h]
_tawes_last_fetch = 0      # Timestamp letzter Messdaten-Abruf

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

def load_tawes_stations():
    """Stationsliste laden. Täglich von API, sonst aus TAWES_CACHE_FILE."""
    global _tawes_all_stations
    # Aus In-Memory Cache wenn frisch genug
    cache_age = time.time() - os.path.getmtime(TAWES_CACHE_FILE) if os.path.exists(TAWES_CACHE_FILE) else 999999
    if _tawes_all_stations and cache_age < 86400:
        return _tawes_all_stations
    # Aus Datei-Cache laden wenn < 24h
    if os.path.exists(TAWES_CACHE_FILE) and cache_age < 86400:
        try:
            with open(TAWES_CACHE_FILE, 'r', encoding='utf-8') as f:
                _tawes_all_stations = json.load(f)
            return _tawes_all_stations
        except: pass
    # Von API laden
    data = fetch_json('https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min/metadata', 'TAWES-Metadata')
    if not data:
        return _tawes_all_stations  # Fallback auf alten Cache
    stations = []
    for s in data.get('stations', data.get('features', [])):
        p = s.get('properties', s)
        sid = str(p.get('id', p.get('station_id', '')))
        name = p.get('name', p.get('station_name', sid))
        lat = float(p.get('lat', p.get('latitude', 0) or 0))
        lon = float(p.get('lon', p.get('longitude', 0) or 0))
        active = p.get('is_active', p.get('active', True))
        if sid and lat and lon and active:
            stations.append({'id': sid, 'name': name, 'lat': lat, 'lon': lon})
    if stations:
        _tawes_all_stations = stations
        try:
            with open(TAWES_CACHE_FILE, 'w', encoding='utf-8') as f:
                json.dump(stations, f, ensure_ascii=False)
        except: pass
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
                           'dist_km': round(dist, 1), 'bearing': round(bear, 1), 'bearing_name': _bearing_to_name(bear)})
    return sorted(result, key=lambda x: x['dist_km'])

def fetch_tawes_data(station_ids):
    """Messdaten für bis zu 25 Stationen in einem API-Call."""
    if not station_ids: return {}
    ids = station_ids[:25]
    sid_params = '&'.join(f'station_ids={sid}' for sid in ids)
    url = f'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min?parameters=RR,FF,FFX,DD,P,RF&{sid_params}&output_format=geojson'
    data = fetch_json(url, 'TAWES-Data')
    if not data: return {}
    ts_list = data.get('timestamps', [])
    ts = int(time.time())
    if ts_list:
        try: ts = int(datetime.fromisoformat(ts_list[0].replace('Z', '+00:00')).timestamp())
        except: pass
    result = {}
    for feature in data.get('features', []):
        props = feature.get('properties', {})
        sid = str(props.get('station', feature.get('id', '')))
        if not sid: continue
        params = props.get('parameters', {})
        def _v(key):
            raw = params.get(key, {}).get('data', [None])
            v = raw[0] if raw else None
            return float(v) if v is not None else None
        result[sid] = {'ts': ts, 'RR': _v('RR'), 'FF': _v('FF'), 'FFX': _v('FFX'), 'DD': _v('DD'), 'P': _v('P'), 'RF': _v('RF')}
    return result

def correlate_tawes():
    """360° Korrelation: Upstream-Stationen → Regen-ETA, Windtrend, Gewitter-Signal."""
    global TAWES_BUFFER
    nearby = find_nearby_stations()
    if not nearby:
        return {}

    # Messdaten abrufen und Ring-Buffer füllen
    raw_data = fetch_tawes_data([st['id'] for st in nearby])
    for sid, vals in raw_data.items():
        if sid not in TAWES_BUFFER:
            TAWES_BUFFER[sid] = deque(maxlen=12)
        TAWES_BUFFER[sid].append(vals)

    # Schritt 1: Dominante Windrichtung – vektorbasiert, gewichtet nach FF
    # (Median schlägt bei Nordwind fehl: 350°+10° → Median 180° statt 0°)
    sin_sum = cos_sum = 0.0; wr_count = 0
    for sid in raw_data:
        ff = raw_data[sid].get('FF'); dd = raw_data[sid].get('DD')
        if ff is not None and dd is not None and ff > 1.0:
            rad = math.radians(dd)
            sin_sum += math.sin(rad) * ff
            cos_sum += math.cos(rad) * ff
            wr_count += 1
    dd_vals = [wr_count]  # Nur noch als bool-Check ob Stationen mit Wind vorhanden
    if wr_count > 0:
        dominante_wr = (math.degrees(math.atan2(sin_sum, cos_sum)) + 360) % 360
        dominante_wr_name = _bearing_to_name(dominante_wr)
    else:
        dominante_wr = 0.0; dominante_wr_name = '–'

    # Schritt 2: Upstream-Stationen dynamisch bestimmen (±70° Toleranz)
    upstream_list = []
    stations_mit_daten = []
    for st in nearby:
        sid = st['id']
        if sid not in raw_data: continue
        vals = raw_data[sid]
        ist_upstream = False
        if wr_count > 0:
            bearing_home = (st['bearing'] + 180) % 360
            wind_to = (dominante_wr + 180) % 360
            diff = abs(wind_to - bearing_home)
            diff = min(diff, 360 - diff)
            ist_upstream = diff < 70
        ff_kmh  = round(vals['FF'] * 3.6, 1)  if vals.get('FF')  is not None else None
        ffx_kmh = round(vals['FFX'] * 3.6, 1) if vals.get('FFX') is not None else None
        entry = dict(st, ist_upstream=ist_upstream, RR=vals.get('RR'), FF_kmh=ff_kmh,
                     FFX_kmh=ffx_kmh, DD=vals.get('DD'), P=vals.get('P'), RF=vals.get('RF'))
        stations_mit_daten.append(entry)
        if ist_upstream:
            upstream_list.append(entry)

    # Upstream Wind & Sturm
    wind_upstream_kmh = 0.0; sturm_upstream = 0
    if upstream_list:
        ffx_vals = [s['FFX_kmh'] for s in upstream_list if s.get('FFX_kmh') is not None]
        if ffx_vals:
            wind_upstream_kmh = round(max(ffx_vals), 1)
            sturm_upstream = int(wind_upstream_kmh >= BOEN_ALARM)

    # Schritt 3–4: Regen-Welle aus Buffer → ETA
    regen_upstream = 0; regen_eta_min = -1; front_speed_kmh = 0.0
    regen_start = {}  # sid → wie viele 10min-Steps zurück (0 = aktuell)
    for st in upstream_list:
        sid = st['id']
        buf = list(TAWES_BUFFER.get(sid, []))
        for i in range(len(buf) - 1, -1, -1):
            rr = buf[i].get('RR')
            if rr is not None and rr > 0.1:
                regen_start[sid] = -(len(buf) - 1 - i)
                regen_upstream = 1
                break

    upstream_mit_regen = sorted([st for st in upstream_list if st['id'] in regen_start],
                                 key=lambda x: x['dist_km'], reverse=True)
    if len(upstream_mit_regen) >= 2:
        speeds = []
        for i in range(len(upstream_mit_regen) - 1):
            far, near = upstream_mit_regen[i], upstream_mit_regen[i+1]
            dist_diff = far['dist_km'] - near['dist_km']
            time_diff = (regen_start[near['id']] - regen_start[far['id']]) * 10
            if time_diff > 0 and dist_diff > 0:
                speed = dist_diff / time_diff * 60
                if 10 < speed < 180: speeds.append(speed)
        if speeds: front_speed_kmh = round(sum(speeds) / len(speeds), 1)

    if regen_upstream and front_speed_kmh > 0:
        ns_regen = sorted(upstream_mit_regen, key=lambda x: x['dist_km'])
        if ns_regen:
            ns = ns_regen[0]
            elapsed_min = abs(regen_start[ns['id']]) * 10
            remaining_km = ns['dist_km'] - (elapsed_min / 60 * front_speed_kmh)
            regen_eta_min = int(remaining_km / front_speed_kmh * 60) if remaining_km > 0 else 0

    # Schritt 5: Wind-Trend (nächste Upstream-Station, letzten 6 Buffer-Einträge)
    wind_trend = 0
    naechste_upstream = upstream_list[0] if upstream_list else (nearby[0] if nearby else None)
    if naechste_upstream:
        buf = list(TAWES_BUFFER.get(naechste_upstream['id'], []))
        ffx_raw = [b.get('FFX') for b in buf[-6:]]
        slope = _linreg_slope([(v * 3.6 if v is not None else None) for v in ffx_raw])
        wind_trend = 1 if slope > 1.0 else (-1 if slope < -1.0 else 0)

    # Schritt 6: Konfidenz
    konfidenz = 0
    if len(upstream_mit_regen) >= 2: konfidenz += 40
    if 10 <= front_speed_kmh <= 150: konfidenz += 30
    if naechste_upstream and naechste_upstream.get('DD') is not None and wr_count > 0:
        diff = abs(naechste_upstream['DD'] - dominante_wr)
        if min(diff, 360 - diff) < 45: konfidenz += 20
    if wind_trend == 1: konfidenz += 10

    # Schritt 7: Gewitter-Signal (Druckabfall + Feuchte + FFX-Anstieg)
    # Level 1 = Gewittergefahr, Level 2 = akute Gefahr (zusätzlich Böen-Anstieg)
    gewitter_signal = 0; druck_trend = 0.0
    if naechste_upstream:
        buf = list(TAWES_BUFFER.get(naechste_upstream['id'], []))
        p_raw     = [b.get('P')   for b in buf[-6:]]
        ffx_raw_g = [b.get('FFX') for b in buf[-6:]]
        druck_trend = round(_linreg_slope(p_raw), 2)
        rf = naechste_upstream.get('RF')
        if druck_trend < -0.5 and rf is not None and rf > 85:
            gewitter_signal = 1
            # Level 2: zusätzlich starker FFX-Anstieg upstream
            ffx_slope = _linreg_slope([(v * 3.6 if v is not None else None) for v in ffx_raw_g])
            if ffx_slope > 3.0:
                gewitter_signal = 2

    # Nächste Station Metadaten
    ns_name = naechste_upstream['name'] if naechste_upstream else '–'
    ns_km   = naechste_upstream['dist_km'] if naechste_upstream else 0
    ns_richt = naechste_upstream['bearing_name'] if naechste_upstream else '–'

    # Notification
    notif = ''
    if gewitter_signal:
        rf_val = (naechste_upstream or {}).get('RF', 0) or 0
        prefix = '🔴 AKUTE GEWITTERGEFAHR' if gewitter_signal == 2 else '⚡ Gewittergefahr'
        notif = f'{prefix} | Druck {abs(druck_trend):.1f} hPa/10min + {rf_val:.0f}% Feuchte'
    elif sturm_upstream:
        trend_str = f' | Trend +{wind_trend} km/h/10min' if wind_trend > 0 else ''
        notif = f'💨 Sturmböen upstream {wind_upstream_kmh} km/h{trend_str}'
    elif regen_upstream and regen_eta_min >= 0:
        notif = f'🌧️ Regenfront ~{regen_eta_min}min | {front_speed_kmh}km/h aus {dominante_wr_name} | {konfidenz}% Konfidenz'
    elif regen_upstream:
        notif = f'🌧️ Regen bei {ns_name} ({ns_km}km) | Wind {dominante_wr_name} | Ankunft unbekannt'

    return {
        'letztes_update':              datetime.now().astimezone().strftime('%d.%m.%Y %H:%M:%S'),
        'dominante_windrichtung':      round(dominante_wr, 1),
        'dominante_windrichtung_name': dominante_wr_name,
        'wind_upstream_kmh':           wind_upstream_kmh,
        'wind_trend':                  wind_trend,
        'sturm_upstream':              sturm_upstream,
        'regen_upstream':              regen_upstream,
        'regen_eta_min':               regen_eta_min,
        'regen_konfidenz':             konfidenz,
        'front_speed_kmh':             front_speed_kmh,
        'druck_trend':                 druck_trend,
        'gewitter_signal':             gewitter_signal,
        'upstream_aktiv':              len(upstream_list),
        'naechste_station_name':       ns_name,
        'naechste_station_km':         ns_km,
        'naechste_station_richtung':   ns_richt,
        'notification':                notif,
        'alle_stationen':              stations_mit_daten,
    }

def publish_tawes(tawes):
    """TAWES MQTT Topics publizieren."""
    if not tawes: return
    for k in ['dominante_windrichtung','dominante_windrichtung_name','wind_upstream_kmh',
              'wind_trend','sturm_upstream','regen_upstream','regen_eta_min','regen_konfidenz',
              'front_speed_kmh','druck_trend','gewitter_signal','upstream_aktiv']:
        publish(f'tawes/{k}', tawes.get(k, 0))
    publish('tawes/stationen_anzahl', len(tawes.get('alle_stationen', [])))
    publish('tawes/letztes_update', tawes.get('letztes_update', ''))
    ns = f'{tawes.get("naechste_station_name","–")} ({tawes.get("naechste_station_km",0)}km, {tawes.get("naechste_station_richtung","–")})'
    publish('tawes/naechste_station', ns)
    publish('notification/tawes', tawes.get('notification', ''))

# ---------------------------------------------------------------------------
# Aggregierter Alarm-Status (alle Quellen kombiniert → alarm/ Topics)
# ---------------------------------------------------------------------------
def build_alarm(zamg, inca, tawes, akut):
    """Kombiniert ZAMG + INCA + TAWES zu einem einheitlichen Alarm-Dict.
    Level: 0=keine, 1=möglich/Vorsicht, 2=aktiv/Warnung, 3=akut/extrem"""

    # Gewitter
    gewitter = 0
    if zamg.get('gewitter', {}).get('stufe', 0) >= 1: gewitter = 1
    if zamg.get('gewitter', {}).get('aktiv', 0):      gewitter = 2
    tawes_g = tawes.get('gewitter_signal', 0)
    gewitter = max(gewitter, tawes_g)
    if akut: gewitter = max(gewitter, 2)

    # Wind – kombiniert ZAMG, INCA, TAWES Upstream-Böen (vs. BOEN_ALARM-Schwelle)
    wind = zamg.get('wind', {}).get('stufe', 0)
    if wind > 0 and zamg.get('wind', {}).get('aktiv', 0): wind = min(3, wind + 1)
    if inca.get('bald_sturm_30'): wind = max(wind, 2)
    elif inca.get('bald_sturm_60'): wind = max(wind, 1)
    tawes_wind = float(tawes.get('wind_upstream_kmh', 0) or 0)
    if tawes_wind >= BOEN_ALARM * 2.0: wind = max(wind, 2)   # doppelte Schwelle = akuter Sturm
    elif tawes_wind >= BOEN_ALARM:     wind = max(wind, 1)   # Schwelle = Warnstufe

    # Regen – kombiniert ZAMG, INCA Nowcast (vs. REGEN_ALARM-Schwelle), TAWES Regenfront
    regen = 0
    if zamg.get('regen', {}).get('stufe', 0) >= 1: regen = 1
    if zamg.get('regen', {}).get('aktiv', 0):      regen = 2
    if inca.get('regen_alarm'):                     regen = max(regen, 2)   # >= REGEN_ALARM mm/h
    elif inca.get('bald_regen') or inca.get('rr_jetzt', 0) > 0.1: regen = max(regen, 1)
    if tawes.get('regen_upstream'):
        eta = tawes.get('regen_eta_min', -1)
        regen = max(regen, 2 if 0 <= eta <= 30 else 1)

    # Hagel
    hagel = 0
    if zamg.get('hagel', {}).get('stufe', 0) >= 1: hagel = 1
    if zamg.get('hagel', {}).get('aktiv', 0):      hagel = 2
    if inca.get('bald_hagel'):   hagel = max(hagel, 1)
    if inca.get('bald_graupel'): hagel = max(hagel, 1)

    # Schnee/Eis
    schnee = max(zamg.get('schnee', {}).get('stufe', 0), zamg.get('glatteis', {}).get('stufe', 0))
    if schnee > 0 and (zamg.get('schnee', {}).get('aktiv') or zamg.get('glatteis', {}).get('aktiv')): schnee = min(3, schnee + 1)
    if inca.get('pt_jetzt') in [2, 3]: schnee = max(schnee, 1)

    # Gesamtstatus: höchster Wert aller Kategorien
    gesamt = max(gewitter, wind, regen, hagel, schnee)

    # Gesamtstufe (max aus ZAMG für Referenz)
    all_types = list(WARN_TYPES.values()) + ['hagel']
    max_stufe = max((zamg.get(t, {}).get('stufe', 0) for t in all_types), default=0)

    # Zusammenfassung
    parts = []
    if gewitter >= 2: parts.append('⚡ Gewitter AKUT')
    elif gewitter:    parts.append('⚡ Gewitter möglich')
    if wind >= 3:     parts.append('💨 Extremsturm')
    elif wind == 2:   parts.append('💨 Sturm aktiv')
    elif wind:        parts.append('💨 Erhöhte Windgefahr')
    if hagel >= 2:    parts.append('🌨 Hagel AKTIV')
    elif hagel:       parts.append('🌨 Hagelgefahr')
    if regen >= 2:    parts.append('🌧 Starkregen')
    elif regen:       parts.append('🌧 Regen erwartet')
    if schnee >= 2:   parts.append('❄️ Schnee/Eis AKTIV')
    elif schnee:      parts.append('❄️ Schnee/Eis möglich')
    zusammenfassung = ' | '.join(parts) if parts else '✅ Keine Warnungen'

    return {
        'gewitter': gewitter, 'wind': wind, 'regen': regen,
        'hagel': hagel, 'schnee': schnee, 'gesamt': gesamt,
        'stufe': int(max_stufe), 'zusammenfassung': zusammenfassung
    }

def publish_alarm(alarm):
    for k in ['gewitter', 'wind', 'regen', 'hagel', 'schnee', 'gesamt', 'stufe']:
        publish(f'alarm/{k}', alarm.get(k, 0))
    publish('alarm/zusammenfassung', alarm.get('zusammenfassung', ''))

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
    """Aktuellen Zustand in JSON-Datei speichern."""
    try:
        with open(STATE_FILE, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
    except Exception as e:
        log.warning(f'State speichern fehlgeschlagen: {e}')

# ---------------------------------------------------------------------------
# MQTT publish + State save
# ---------------------------------------------------------------------------
def publish_all(zamg, akut, inca, prev_ids, new_ids, status_msg, tawes=None, prev=None):
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
        for k in ['fx_jetzt','ff_jetzt','fx_max_30min','fx_max_60min','rr_jetzt','pt_jetzt','pt_name','bald_regen','bald_hagel','bald_graupel','bald_sturm_30','bald_sturm_60','minuten_bis_regen']:
            publish(f'inca/{k.replace("_jetzt","") if "jetzt" in k else k}', inca.get(k, 0))
        publish('inca/regen_alarm', inca.get('regen_alarm', 0))
    z_lines = [zamg[t]['notification'] for t in all_types if zamg.get(t,{}).get('stufe',0) >= MIN_STUFE and zamg[t]['notification']]
    if akut: z_lines.insert(0, L['akut_active'])
    i_lines = []
    if inca:
        if inca.get('bald_hagel'): i_lines.append(L['hagel_60'])
        elif inca.get('bald_graupel'): i_lines.append(L['graupel_60'])
        if inca.get('bald_sturm_30'): i_lines.append(L['sturm_30'].format(v=inca["fx_max_30min"]))
        elif inca.get('bald_sturm_60'): i_lines.append(L['sturm_60'].format(v=inca["fx_max_60min"]))
        m = inca.get('minuten_bis_regen', -1)
        if inca.get('bald_regen'): i_lines.append(L['regen_in'].format(v=m))
        elif inca.get('rr_jetzt', 0) > 0: i_lines.append(L['regen_jetzt'].format(v=inca["rr_jetzt"]))
        if not i_lines: i_lines.append(L['kein_alarm'].format(v=inca.get("fx_jetzt", 0)))
    n_geo   = '\n'.join(z_lines) if z_lines else L['no_warns']
    n_inca  = '\n'.join(i_lines) if i_lines else L['no_warns']
    n_tawes = (tawes or {}).get('notification', '')

    # Entwarnung: wenn letzte Runde Warnungen aktiv waren und jetzt keine mehr
    hatte_warn = prev.get('hatte_aktiv', False)
    if hatte_warn and not irgendwas and not z_lines:
        n_geo = L['entwarnung']
        log.info('ZAMG: Entwarnung – alle Wetterwarnungen aufgehoben')

    n_alle = '\n──\n'.join(filter(None, [n_geo if (z_lines or n_geo == L['entwarnung']) else '', n_inca if i_lines else '', n_tawes])) or n_inca

    # Notifications nur publizieren wenn sich Inhalt geändert hat (Deduplizierung)
    if n_geo   != prev.get('_n_geo',   ''):  publish('notification/geosphere', n_geo)
    if n_inca  != prev.get('_n_inca',  ''):  publish('notification/inca',      n_inca)
    if n_tawes != prev.get('_n_tawes', ''):  publish('notification/tawes',     n_tawes)
    if n_alle  != prev.get('_n_alle',  ''):  publish('notification/alle',      n_alle)

    now = datetime.now().astimezone(); ts_iso = now.strftime('%d.%m.%Y %H:%M:%S'); ts_epoch = int(now.timestamp())
    publish('letzter_abruf_datum', ts_iso); publish('letzter_abruf_epoch', ts_epoch)
    publish('status', L['status_ok'] if status_msg == 'OK' else L['status_err'].format(v=status_msg))
    alarm = build_alarm(zamg, inca, tawes or {}, akut)
    publish_alarm(alarm)
    save_state({
        'last_warn_ids': new_ids, 'hatte_aktiv': bool(irgendwas), 'letztes_update': ts_iso,
        'letzter_abruf_epoch': ts_epoch, 'status': status_msg, 'zamg': zamg, 'inca': inca,
        'akutwarnung': akut, 'max_stufe': int(max_stufe), 'irgendwas_aktiv': irgendwas,
        'notification_alle': n_alle, 'alarm': alarm, 'tawes': tawes if tawes else {},
        '_n_geo': n_geo, '_n_inca': n_inca, '_n_tawes': n_tawes, '_n_alle': n_alle,
    })

# ---------------------------------------------------------------------------
# Hauptloop
# ---------------------------------------------------------------------------
def run():
    global _tawes_last_fetch
    log.info(f'Unwetter4Lox v0.4.3 gestartet | {LAT},{LON} | Lang={LBLANG}')
    _tawes_last_fetch = 0
    while True:
        try:
            if not _mqtt_connected.is_set(): mqtt_connect()
            prev = load_state(); prev_ids = prev.get('last_warn_ids', [])
            status, zamg, akut, new_ids, inca = 'OK', {}, 0, [], {}
            if ZAMG_ENABLED:
                zamg, akut, new_ids = fetch_zamg()
                if zamg is None:
                    status, zamg = 'GeoSphere API Error', {}
                else:
                    aktive = sum(1 for t in zamg.values() if t.get('stufe', 0) > 0)
                    max_st = max((t.get('stufe', 0) for t in zamg.values()), default=0)
                    log.info(f'ZAMG: {aktive} Warntypen aktiv | max_stufe={max_st} | akut={akut}')
            if INCA_ENABLED:
                inca = fetch_inca()
                if inca is None:
                    status, inca = ('INCA Error' if status == 'OK' else status + ' & INCA Error'), {}
                else:
                    log.info(f'INCA: Boen {inca.get("fx_max_60min",0)} km/h | Regen {inca.get("rr_jetzt",0)} mm/h | ETA {inca.get("minuten_bis_regen",-1)} min | Alarm={inca.get("regen_alarm",0)}')
            # TAWES – nur alle 10min (480s Schwelle)
            tawes = prev.get('tawes', {})
            if TAWES_ENABLED and time.time() - _tawes_last_fetch > 480:
                try:
                    tawes = correlate_tawes()
                    publish_tawes(tawes)
                    _tawes_last_fetch = time.time()
                    log.info(f'TAWES: {len(tawes.get("alle_stationen",[]))} Stationen | upstream={tawes.get("upstream_aktiv",0)} | regen={tawes.get("regen_upstream",0)}')
                except Exception as te:
                    log.error(f'TAWES Fehler: {te}')
            publish_all(zamg, akut, inca, prev_ids, new_ids, status, tawes, prev=prev)
        except Exception as e: log.error(f'Hauptloop Fehler: {e}')
        time.sleep(INTERVAL)

if __name__ == '__main__': run()
