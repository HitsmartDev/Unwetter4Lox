#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Unwetter4Lox Daemon v0.2.2 – GeoSphere (ZAMG) + INCA -> MQTT"""
import os, sys, json, time, logging, configparser, urllib.request, signal, subprocess, glob, threading
from datetime import datetime, timezone, timedelta

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
STATE_FILE   = os.path.join(DATADIR, 'state.json')

# ---------------------------------------------------------------------------
# LoxBerry Logging Initialisierung (Systemzeit beachten)
# ---------------------------------------------------------------------------
log = None
LOGFILE = None

def _lb_level_to_python(lb_level):
    """LoxBerry-Loglevel (0-7, syslog-basiert) in Python-logging-Level umrechnen.
    LoxBerry: 0=aus, 3=ERROR, 4=WARNING, 6=INFO, 7=DEBUG (höher = mehr Logging)
    """
    if lb_level <= 0: return 60               # Logging deaktiviert
    if lb_level <= 2: return logging.CRITICAL  # Nur kritische Fehler
    if lb_level <= 3: return logging.ERROR     # Nur Fehler (LoxBerry Standard "Errors only")
    if lb_level <= 4: return logging.WARNING   # Warnungen und schlimmer
    if lb_level < 7:  return logging.INFO      # Info und schlimmer (Standard)
    return logging.DEBUG                        # Alles inkl. Debug

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
    CURRENT_LOG_PTR = os.path.join(LOGDIR, 'daemon.log.current')
    try:
        with open(CURRENT_LOG_PTR, 'w') as _f: _f.write(LOGFILE)
    except: pass
else:
    class LoxBerryFormatter(logging.Formatter):
        TAG_MAP = {'DEBUG': '<DEBUG>', 'INFO': '<OK>', 'WARNING': '<WARNING>', 'ERROR': '<ERR>', 'CRITICAL': '<CRIT>'}
        def format(self, record):
            tag = self.TAG_MAP.get(record.levelname, '<OK>')
            ts  = datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S')
            return f'{ts} {tag} {record.getMessage()}'
    ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    LOGFILE = os.path.join(LOGDIR, f'daemon_{ts}.log')
    with open(LOGFILE, 'w', encoding='utf-8') as f:
        f.write(f'{datetime.now().astimezone().strftime("%Y-%m-%d %H:%M:%S")} <LOGSTART> Unwetter4Lox Daemon\n')
    # Pointer-Datei für PHP-Frontend schreiben (auch im Fallback-Modus)
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
    cfg_path = os.path.join(CONFIGDIR, 'unwetter4lox.cfg')
    cfg.read(cfg_path)
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
INTERVAL     = int(get_cfg('SCHEDULE',        'INTERVAL',         '300'))
BOEN_ALARM   = float(get_cfg('THRESHOLDS',    'BOEN_ALARM',       '60'))
ZAMG_ENABLED = get_cfg('ZAMG',               'ENABLED',           '1') == '1'
INCA_ENABLED = get_cfg('INCA',               'ENABLED',           '1') == '1'
INCA_HORIZON = int(get_cfg('INCA',           'HORIZON_MINUTES',   '60'))
MIN_STUFE    = int(get_cfg('NOTIFICATIONS',  'MIN_STUFE',         '1'))
TOPIC_PREFIX = get_cfg('MQTT',              'TOPIC_PREFIX',        'unwetter')

MQTT_BROKER = '127.0.0.1'; MQTT_PORT = 1883; MQTT_USER = ''; MQTT_PASS = ''
USE_LB_MQTT = get_cfg('MQTT', 'USE_LOXBERRY_MQTT', '1') == '1'

def _read_mqtt_creds_from_files():
    global MQTT_BROKER, MQTT_PORT, MQTT_USER, MQTT_PASS
    candidates = [os.path.join(LBHOMEDIR, 'config', 'system', 'general.json'),
                  os.path.join(LBHOMEDIR, 'config', 'system', 'mqttgateway.json')]
    for p in candidates:
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
    MQTT_USER, MQTT_PASS = get_cfg('MQTT','USER',''), get_cfg('MQTT','PASS','')

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
WARN_TYPES  = {1: 'wind', 2: 'regen', 3: 'schnee', 4: 'glatteis', 5: 'gewitter', 6: 'hitze', 7: 'kaelte'}
STUFE_NAME  = {0: 'KEINE', 1: 'GELB', 2: 'ORANGE', 3: 'ROT', 4: 'LILA'} if LBLANG == 'de' else {0: 'NONE', 1: 'YELLOW', 2: 'ORANGE', 3: 'RED', 4: 'EXTREME'}
PT_NAME     = {255: L['pt_none'], 1: 'Regen' if LBLANG=='de' else 'Rain', 2: 'Schnee' if LBLANG=='de' else 'Snow', 3: 'Schneeregen' if LBLANG=='de' else 'Sleet', 4: 'Graupel', 5: 'Hagel' if LBLANG=='de' else 'Hail'}

def fmt_dt(epoch_s):
    d, now = datetime.fromtimestamp(epoch_s, tz=timezone.utc).astimezone(), datetime.now().astimezone()
    t, days = d.strftime('%H:%M'), (['So','Mo','Di','Mi','Do','Fr','Sa'] if LBLANG == 'de' else ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'])
    if d.date() == now.date(): return f'{L["heute"]} {t}'
    if d.date() == (now + timedelta(days=1)).date(): return f'{L["morgen"]} {t}'
    return f'{days[d.weekday()]} {d.strftime("%d.%m.")} {t}'

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
        req = urllib.request.Request(url, headers={'User-Agent': 'Unwetter4Lox/0.2.2'})
        with urllib.request.urlopen(req, timeout=15) as r: return json.loads(r.read().decode())
    except Exception as e: log.error(f'HTTP {provider}: {e}'); return None

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

def publish_all(zamg, akut, inca, prev_ids, new_ids, status_msg):
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
    n_geo, n_inca = '\n'.join(z_lines) if z_lines else L['no_warns'], '\n'.join(i_lines) if i_lines else L['no_warns']
    publish('notification/geosphere', n_geo); publish('notification/inca', n_inca); publish('notification/alle', n_geo + '\n──\n' + n_inca if z_lines else n_inca)
    now = datetime.now().astimezone(); ts_iso, ts_epoch = now.strftime('%d.%m.%Y %H:%M:%S'), int(now.timestamp())
    publish('letztes_update', ts_iso); publish('letzter_abruf_datum', ts_iso); publish('letzter_abruf_epoch', ts_epoch)
    publish('status', L['status_ok'] if status_msg == 'OK' else L['status_err'].format(v=status_msg))
    save_state({'last_warn_ids': new_ids, 'hatte_aktiv': bool(irgendwas), 'letztes_update': ts_iso, 'letzter_abruf_epoch': ts_epoch, 'status': status_msg, 'zamg': zamg, 'inca': inca, 'akutwarnung': akut, 'max_stufe': int(max_stufe), 'irgendwas_aktiv': irgendwas, 'notification_alle': n_geo + '\n──\n' + n_inca if z_lines else n_inca})

def run():
    log.info(f'Unwetter4Lox gestartet | {LAT},{LON} | Lang={LBLANG}')
    while True:
        try:
            if not _mqtt_connected.is_set(): mqtt_connect()
            prev = load_state(); prev_ids = prev.get('last_warn_ids', [])
            status, zamg, akut, new_ids, inca = 'OK', {}, 0, [], {}
            if ZAMG_ENABLED:
                zamg, akut, new_ids = fetch_zamg()
                if zamg is None: status, zamg = 'GeoSphere API Error', {}
            if INCA_ENABLED:
                inca = fetch_inca()
                if inca is None: status, inca = ('INCA Error' if status == 'OK' else status + ' & INCA Error'), {}
            publish_all(zamg, akut, inca, prev_ids, new_ids, status)
        except Exception as e: log.error(f'Hauptloop Fehler: {e}')
        time.sleep(INTERVAL)
if __name__ == '__main__': run()
