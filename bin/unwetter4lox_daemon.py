"""Unwetter4Lox Daemon v0.9.29 – GeoSphere (ZAMG) + INCA + TAWES 360° -> MQTT"""
import os, sys, json, time, logging, configparser, urllib.request, signal, subprocess, glob, threading, math, re, traceback, socket
from datetime import datetime, timezone, timedelta
from collections import deque
from concurrent.futures import ThreadPoolExecutor, as_completed

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
# Session-Log-Dateien in DATADIR speichern – LoxBerry's log_maint.pl (Stage 4) löscht
# rekursiv alle *.log Dateien unter /log/plugins/ bei Disk < 5%.
# DATADIR (/data/plugins/) ist NICHT im Scan-Pfad von log_maint.pl → sicher!
SESSIONDIR         = os.path.join(DATADIR, 'logs')
os.makedirs(SESSIONDIR, exist_ok=True)
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

def _init_file_logger():
    """File-Logger mit LoxBerry-Tags. Session-Dateien in SESSIONDIR (Unterverzeichnis),
    damit LoxBerry-Log-Manager sie nicht löscht."""
    class LoxBerryFormatter(logging.Formatter):
        TAG_MAP = {'DEBUG': '<DEBUG>', 'INFO': '<OK>', 'WARNING': '<WARNING>', 'ERROR': '<ERR>', 'CRITICAL': '<CRIT>'}
        def format(self, record):
            tag = self.TAG_MAP.get(record.levelname, '<OK>')
            ts  = datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S')
            return f'{ts} {tag} {record.getMessage()}'
    _ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    # Session-Datei im Unterverzeichnis – nicht im direkten Log-Dir (LoxBerry würde sie löschen)
    logfile = os.path.join(SESSIONDIR, f'Unwetter4Lox_Daemon_{_ts}.log')
    with open(logfile, 'w', encoding='utf-8') as _f:
        _f.write(f'{datetime.now().astimezone().strftime("%Y-%m-%d %H:%M:%S")} <LOGSTART> Unwetter4Lox Daemon\n')
    try:
        with open(os.path.join(LOGDIR, 'daemon.log.current'), 'w') as _f: _f.write(logfile)
    except: pass
    _make_stable_log_link(logfile)
    root = logging.getLogger()
    for h in root.handlers[:]: root.removeHandler(h)
    _fh = logging.FileHandler(logfile, mode='a', encoding='utf-8')
    _fh.setFormatter(LoxBerryFormatter())
    logging.basicConfig(level=_lb_level_to_python(CURRENT_LOGLEVEL), handlers=[_fh])
    return logging.getLogger('unwetter4lox'), logfile

def _make_stable_log_link(target_path):
    """Erstellt daemon.log als Symlink auf die aktuelle Session-Log-Datei.
    Ermöglicht LoxBerry logmanager.cgi stabile Pfad-Registrierung."""
    try:
        stable = os.path.join(LOGDIR, 'daemon.log')
        if os.path.exists(stable) or os.path.islink(stable):
            os.unlink(stable)
        os.symlink(target_path, stable)
    except: pass

def _cleanup_old_sessions(max_sessions=7):
    """Hält max. N Session-Log-Dateien in SESSIONDIR, löscht ältere."""
    pattern = os.path.join(SESSIONDIR, 'Unwetter4Lox_Daemon_????????_??????.log')
    existing = sorted(glob.glob(pattern), reverse=True)
    for old in existing[max_sessions - 1:]:
        try: os.remove(old)
        except OSError: pass

# Eigener File-Logger mit kontrollierter Rotation.
# LB SDK Logger wird NICHT verwendet (startet Background-Thread der Logs löscht).
_cleanup_old_sessions()
log, LOGFILE = _init_file_logger()

log.info(f'Logging initialisiert (Level {CURRENT_LOGLEVEL})')

def _on_signal(signum, frame):
    log.info(f'Daemon gestoppt (Signal {signum})')
    try:
        publish('status', L['status_err'].format(v='Gestoppt'), retain=True)
    except: pass
    # Paho-Background-Thread explizit stoppen BEVOR der Prozess endet.
    # Ohne loop_stop() läuft der Thread nach sys.exit() noch kurz weiter,
    # reconnectet zum Broker und kickt die neue Instanz (→ RC=7-Loop).
    global mqtt_client
    if mqtt_client:
        try: mqtt_client.loop_stop()
        except: pass
        try: mqtt_client.disconnect()
        except: pass
    if os.path.exists(PID_FILE):
        try: os.remove(PID_FILE)
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
    sys.exit(1)

LAT          = float(_lat_raw.replace(',', '.'))
LON          = float(_lon_raw.replace(',', '.'))
INTERVAL       = int(get_cfg('SCHEDULE', 'INTERVAL',       '300'))
# Per-API-Intervalle: Fallback auf INTERVAL wenn nicht konfiguriert
ZAMG_INTERVAL  = max(60,  min(3600, int(get_cfg('SCHEDULE', 'ZAMG_INTERVAL',  str(INTERVAL)))))
INCA_INTERVAL  = max(60,  min(3600, int(get_cfg('SCHEDULE', 'INCA_INTERVAL',  str(INTERVAL)))))
TAWES_INTERVAL = max(120, min(3600, int(get_cfg('SCHEDULE', 'TAWES_INTERVAL', '480'))))
BOEN_ALARM   = float(get_cfg('THRESHOLDS',   'BOEN_ALARM',        '60'))
REGEN_ALARM  = float(get_cfg('THRESHOLDS',   'REGEN_ALARM',       '10.0'))
ZAMG_ENABLED   = get_cfg('ZAMG', 'ENABLED',           '1') == '1'
INCA_ENABLED   = get_cfg('INCA', 'ENABLED',           '1') == '1'
INCA_HORIZON   = max(15, min(60, int(get_cfg('INCA', 'HORIZON_MINUTES', '60'))))
MIN_STUFE_PUSH = max(1, int(get_cfg('NOTIFICATIONS', 'MIN_STUFE', '1')))
TOPIC_PREFIX = get_cfg('MQTT',             'TOPIC_PREFIX',         'unwetter')
TAWES_ENABLED        = get_cfg('TAWES', 'ENABLED',           '1') == '1'
TAWES_MAX_KM         = float(get_cfg('TAWES', 'MAX_DISTANCE_KM',  '120'))
TAWES_MAX_STATIONS   = max(5, int(get_cfg('TAWES', 'MAX_STATIONS',   '25')))
TAWES_MIN_ALARM_PCT  = max(1, min(100, int(get_cfg('TAWES', 'MIN_ALARM_PROZENT', '30'))))
TAWES_MAX_UPSTREAM_HOEHE = float(get_cfg('TAWES', 'MAX_UPSTREAM_HOEHE_M', '1200'))
TAWES_REGEN_LOKAL_KM     = max(5.0, min(100.0, float(get_cfg('TAWES', 'REGEN_LOKAL_KM', '25'))))
# Upstream-Kegel: Halbwinkel in Grad (45° = 90° Gesamtkegel; war 70° = zu breit)
TAWES_UPSTREAM_WINKEL = max(20, min(90, int(get_cfg('TAWES', 'UPSTREAM_WINKEL_GRAD', '45'))))
# Welche ZAMG-Warntypen sollen berücksichtigt werden (kommagetrennte Typ-IDs oder Namen)
# Standard: alle außer Hitze (6) und Kälte (7)
_ZAMG_TYPEN_RAW = get_cfg('ZAMG', 'AKTIVE_TYPEN', '1,2,3,4,5,8')
_ZAMG_TYPE_MAP  = {'wind':1,'regen':2,'schnee':3,'glatteis':4,'gewitter':5,'hitze':6,'kaelte':7,'hagel':8}
ZAMG_AKTIVE_TYPEN: set[int] = set()
for _t in _ZAMG_TYPEN_RAW.split(','):
    _t = _t.strip()
    if _t.isdigit():
        ZAMG_AKTIVE_TYPEN.add(int(_t))
    elif _t.lower() in _ZAMG_TYPE_MAP:
        ZAMG_AKTIVE_TYPEN.add(_ZAMG_TYPE_MAP[_t.lower()])
if not ZAMG_AKTIVE_TYPEN:
    ZAMG_AKTIVE_TYPEN = {1, 2, 3, 4, 5, 8}

# ---------------------------------------------------------------------------
# Trend-Engine – Zeitreihe der letzten Zyklen (Multi-Source Fusion)
# ---------------------------------------------------------------------------
# Puffert Schlüsselwerte aus INCA + TAWES für Trend-Analyse.
# max 8 Einträge × ~5min Zykluszeit = ~40 Minuten Verlaufsfenster.
# Daraus werden ETA-Korrekturen, Konfidenz-Bonus und Trendaussagen berechnet.
_TREND_HISTORY = deque(maxlen=8)

def _add_trend(inca, tawes):
    """Fügt aktuellen Messzyklus zur Trend-History hinzu."""
    _TREND_HISTORY.append({
        'ts':           int(time.time()),
        'inca_rr':      (inca  or {}).get('rr_jetzt',        0) or 0,
        'inca_fx60':    (inca  or {}).get('fx_max_60min',     0) or 0,
        'inca_eta':     (inca  or {}).get('minuten_bis_regen',-1),
        'tawes_rr_up':  (tawes or {}).get('regen_upstream_mm',0) or 0,
        'tawes_fx_up':  (tawes or {}).get('wind_upstream_kmh', 0) or 0,
        'tawes_regen':  int(bool((tawes or {}).get('regen_upstream',0) or (tawes or {}).get('regen_lokal',0))),
        'tawes_sturm':  int(bool((tawes or {}).get('sturm_upstream',0) or (tawes or {}).get('wind_kaskade',0))),
    })

def _analyse_trend():
    """Multi-Source Trendanalyse über den Verlauf der letzten Zyklen.

    Berechnet:
    - regen_trend / wind_trend: 'zunehmend' | 'abnehmend' | 'stabil'
    - konfidenz_bonus (0-25): Aufschlag wenn mehrere Zyklen in gleiche Richtung zeigen
    - eta_korrigiert: extrapolierter ETA (Minuten) aus INCA-Zeitreihe
    - n_quellen_aktiv: Anzahl Quellen mit Signal in letztem Zyklus (0-3)
    - rr_slope / fx_slope: Steigung der kombinierten Intensitätsserie (mm/h pro Zyklus)
    - inca_trend_zyklen: wie viele aufeinander folgende Zyklen zeigt INCA Regen-Signal

    Gibt leeres Dict zurück wenn < 3 Einträge vorhanden.
    """
    h = list(_TREND_HISTORY)
    if len(h) < 2:
        return {'regen_trend': 'unbekannt', 'wind_trend': 'unbekannt',
                'konfidenz_bonus': 0, 'eta_korrigiert': -1, 'n_quellen_aktiv': 0,
                'rr_slope': 0.0, 'fx_slope': 0.0, 'inca_trend_zyklen': 0}

    # Trend-Serien: INCA und TAWES GETRENNT – sie messen verschiedene Orte!
    # rr_inca: Regen am Standort (INCA-Modell) – für Slope-Berechnung maßgeblich
    # rr_tawes: Regen upstream (echte Messung) – als Bestätigung, nicht addiert
    rr_inca  = [e['inca_rr']      for e in h]
    rr_tawes = [e['tawes_rr_up']  for e in h]
    fx_serie = [max(e['inca_fx60'], e['tawes_fx_up']) for e in h]

    rr_slope = _linreg_slope(rr_inca)   # Slope nur aus INCA (sauber, ein Ort)
    fx_slope = _linreg_slope(fx_serie)

    # Beschleunigung: aktueller Slope (letzte 3 Zyklen) vs. Gesamtslope
    # Wenn aktuell > 2× Gesamt → Storm intensiviert sich gerade
    beschleunigt = False
    if len(h) >= 4:
        rr_slope_aktuell = _linreg_slope(rr_inca[-3:])
        if rr_slope_aktuell > 0 and rr_slope > 0 and rr_slope_aktuell > rr_slope * 2.0:
            beschleunigt = True
        fx_slope_aktuell = _linreg_slope(fx_serie[-3:])
        if fx_slope_aktuell > 0 and fx_slope > 0 and fx_slope_aktuell > fx_slope * 2.0:
            beschleunigt = True

    regen_trend = ('stark_zunehmend' if beschleunigt and rr_slope > 0.1
                   else ('zunehmend' if rr_slope > 0.3
                   else ('abnehmend' if rr_slope < -0.3 else 'stabil')))
    wind_trend  = ('stark_zunehmend' if beschleunigt and fx_slope > 0.5
                   else ('zunehmend' if fx_slope > 1.0
                   else ('abnehmend' if fx_slope < -1.0 else 'stabil')))

    # Konsistenz: wie viele Schritte zeigen gleiche Richtung?
    n_cons_rr = sum(1 for i in range(1, len(h)) if (rr_inca[i]  - rr_inca[i-1])  * rr_slope > 0)
    n_cons_fx = sum(1 for i in range(1, len(h)) if (fx_serie[i] - fx_serie[i-1]) * fx_slope > 0)
    # Basis-Bonus aus Konsistenz + Extra-Bonus bei Beschleunigung
    konfidenz_bonus = min(25, max(n_cons_rr, n_cons_fx) * 5 + (10 if beschleunigt else 0))

    # Zyklen-Zähler: eta > 0 (eta == 0 heißt Regen ist da → das ist rr_jetzt-Territorium)
    inca_trend_zyklen = 0
    for e in reversed(h):
        if e['inca_rr'] >= REGEN_ALARM or e['inca_eta'] > 0: inca_trend_zyklen += 1
        else: break

    # ETA-Korrektur: lineare Extrapolation der INCA minuten_bis_regen-Serie
    eta_serie = [e['inca_eta'] for e in h if e['inca_eta'] >= 0]
    eta_korrigiert = -1
    if len(eta_serie) >= 3:
        eta_sl = _linreg_slope(eta_serie)
        if eta_sl < -1.5:  # ETA nimmt ab → Regen nähert sich sicher
            eta_korrigiert = max(0, int(eta_serie[-1] + eta_sl))

    # Aktive Quellen im letzten Zyklus
    last = h[-1]
    n_quellen = int(last['inca_rr'] >= REGEN_ALARM or last['inca_eta'] >= 0) + \
                int(last['tawes_regen']) + \
                int(last['inca_fx60'] >= BOEN_ALARM or last['tawes_sturm'])
    # Normiert auf max 3 (Regen-Quellen + Wind-Quellen ≠ 3, aber als Näherungswert OK)
    n_quellen_aktiv = min(3, n_quellen)

    return {
        'regen_trend': regen_trend, 'wind_trend': wind_trend,
        'konfidenz_bonus': konfidenz_bonus, 'eta_korrigiert': eta_korrigiert,
        'n_quellen_aktiv': n_quellen_aktiv, 'rr_slope': round(rr_slope, 2),
        'fx_slope': round(fx_slope, 2), 'inca_trend_zyklen': inca_trend_zyklen,
    }

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

# Client-ID: Hostname-Suffix verhindert Konflikte mit anderen Instanzen/Geräten
_MQTT_CLIENT_ID = f"unwetter4lox-{socket.gethostname().split('.')[0][:12].replace(' ', '-')}"

mqtt_client     = None
_mqtt_connected = threading.Event()

_mqtt_connect_time        = 0.0
_mqtt_reconnect_count     = 0
_last_successful_publish  = 0.0   # Watchdog: wann hat zuletzt ein Publish geklappt
_disconnect_since         = 0.0   # Watchdog: seit wann sind wir getrennt
_first_disco_time         = 0.0   # Zeit des ERSTEN Disconnects – nur bei rc=0 (Connect) zurückgesetzt

# RC-Code Bedeutungen (MQTT 3.1.1 CONNACK + paho-interne Disconnect-Codes)
_MQTT_RC = {
    0: 'Verbindung akzeptiert',
    1: 'Protokoll-Version nicht unterstützt',
    2: 'Client-ID abgelehnt',
    3: 'Broker nicht verfügbar',
    4: 'Ungültige Zugangsdaten',
    5: 'Nicht autorisiert',
    7: 'Verbindung unterbrochen – andere Instanz mit gleicher Client-ID?',
}

def _on_connect(c, u, f, rc):
    global _mqtt_connect_time, _mqtt_reconnect_count, _disconnect_since
    if rc == 0:
        _mqtt_connected.set()
        _mqtt_connect_time = time.time()
        _disconnect_since  = 0.0
        # _first_disco_time wird NICHT hier zurückgesetzt!
        # Beim RC=7-Loop reconnectet paho alle ~5s mit RC=0 und würde sonst den
        # 10-Min-Timer jedes Mal nullen. Stattdessen: Reset erst nach 120s Stabilität im Main-Loop.
        if _mqtt_reconnect_count > 0:
            log.info(f'MQTT: Verbunden mit {MQTT_BROKER}:{MQTT_PORT} (Reconnect #{_mqtt_reconnect_count})')
        else:
            log.info(f'MQTT: Verbunden mit {MQTT_BROKER}:{MQTT_PORT}')
    else:
        reason = _MQTT_RC.get(rc, f'unbekannt RC={rc}')
        log.error(f'MQTT: Verbindung fehlgeschlagen – {reason} (RC={rc}) | Broker={MQTT_BROKER}:{MQTT_PORT}')

def _on_disconnect(c, u, rc):
    global _mqtt_reconnect_count, _disconnect_since, _first_disco_time
    _mqtt_connected.clear()
    _mqtt_reconnect_count += 1
    if _disconnect_since == 0.0:
        _disconnect_since = time.time()
    if _first_disco_time == 0.0:
        _first_disco_time = time.time()   # Startzeitpunkt der aktuellen Disconnect-Serie
    if rc == 0:
        log.info(f'MQTT: Verbindung sauber getrennt (Reconnect #{_mqtt_reconnect_count})')
    else:
        reason = _MQTT_RC.get(rc, f'unbekannt RC={rc}')
        log.warning(f'MQTT: Getrennt RC={rc} ({reason}) | Reconnect #{_mqtt_reconnect_count}')

def mqtt_connect():
    """Erstellt neuen MQTT-Client und verbindet. Paho's loop_start() übernimmt
    danach alle Auto-Reconnects – diese Funktion wird im Normalbetrieb nur EINMAL
    beim Start aufgerufen, und nur als Hard-Reset nach sehr langer Unterbrechung."""
    global mqtt_client, _mqtt_reconnect_count
    if not MQTT_OK: return False
    # Alten Client vollständig beenden bevor neuer erstellt wird
    if mqtt_client is not None:
        try:
            mqtt_client.loop_stop()   # Blockiert bis Background-Thread beendet
        except: pass
        try:
            mqtt_client.disconnect()
        except: pass
        mqtt_client = None
        time.sleep(1.5)  # Längere Pause: OS-Socket-Release + Broker-Cleanup
    _mqtt_connected.clear()
    try:
        try:
            c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id=_MQTT_CLIENT_ID, clean_session=True)
        except:
            c = mqtt.Client(client_id=_MQTT_CLIENT_ID, clean_session=True)
        c.on_connect    = _on_connect
        c.on_disconnect = _on_disconnect
        # Paho Auto-Reconnect: nach Disconnect wartet paho 5–60s und reconnectet selbst.
        # Kein manueller Reconnect im Haupt-Loop nötig – das verhindert Client-ID-Konflikte.
        c.reconnect_delay_set(min_delay=5, max_delay=60)
        lwt_topic = f'{TOPIC_PREFIX}/status'
        c.will_set(lwt_topic, L['status_err'].format(v='Offline (LWT)'), qos=1, retain=True)
        if MQTT_USER: c.username_pw_set(MQTT_USER, MQTT_PASS)
        c.connect(MQTT_BROKER, MQTT_PORT, keepalive=60)
        mqtt_client = c
        c.loop_start()
        connected = _mqtt_connected.wait(timeout=15)
        if connected:
            try: c.publish(f'{TOPIC_PREFIX}/status', L['status_ok'], qos=1, retain=True)
            except: pass
        else:
            log.warning(f'MQTT: Verbindungsaufbau Timeout (15s) | Broker={MQTT_BROKER}:{MQTT_PORT}')
        return connected
    except Exception as e:
        log.error(f'MQTT: Verbindungsfehler: {e} | Broker={MQTT_BROKER}:{MQTT_PORT}')
        return False

def publish(topic, value, retain=True):
    global _last_successful_publish
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
        _last_successful_publish = time.time()
        return True
    except Exception as e:
        log.warning(f'MQTT: Publish Exception ({topic}): {e} – erzwinge Reconnect')
        _mqtt_connected.clear()
        return False

def fetch_json(url, provider):
    log.debug(f'[{provider}] → GET {url}')
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Unwetter4Lox/0.9.4'})
        with urllib.request.urlopen(req, timeout=15) as r:
            raw = r.read().decode()
            data = json.loads(raw)
            # Kompakte Debug-Zusammenfassung der Antwort (kein Dump des vollen JSON)
            try:
                if isinstance(data, dict):
                    keys = list(data.keys())[:8]
                    summary = f'dict keys={keys}'
                    if 'features' in data:
                        summary += f' features={len(data["features"])}'
                    if 'timestamps' in data:
                        summary += f' timestamps={len(data["timestamps"])}'
                    if 'properties' in data and isinstance(data['properties'], dict):
                        if 'warnings' in data['properties']:
                            summary += f' warnings={len(data["properties"]["warnings"])}'
                elif isinstance(data, list):
                    summary = f'list len={len(data)}'
                else:
                    summary = str(type(data))
            except Exception:
                summary = '(Zusammenfassung nicht möglich)'
            log.debug(f'[{provider}] ← HTTP 200 | {summary} | {len(raw)} Bytes')
            return data
    except urllib.error.HTTPError as e:
        body = ''
        try: body = e.read().decode('utf-8', errors='replace')[:300]
        except: pass
        log.error(f'HTTP {provider}: {e} | {url[:120]} | API-Antwort: {body}')
        return None
    except Exception as e:
        log.error(f'HTTP {provider}: {e} | {url[:120]}')
        return None

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
    if not ts: return 0  # 0 → caller setzt Fallback (e_ep = s_ep + 86400)
    try:
        s = ts.replace('Z', '').split('+')[0].replace('T', ' ')
        for f in ['%Y-%m-%d %H:%M:%S', '%Y-%m-%d %H:%M']:
            try: return int(datetime.strptime(s, f).replace(tzinfo=timezone.utc).timestamp())
            except: continue
    except: pass
    return 0  # ungültig → 0, nicht time.time() (würde Zeitfenster-Checks verfälschen)

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
        # Historischer Endpoint braucht start UND end (beide mit Z-Suffix für UTC)
        start_ts = (datetime.now(timezone.utc) - timedelta(minutes=duration_min)).strftime('%Y-%m-%dT%H:%MZ')
        end_ts   = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%MZ')
        url = f'https://dataset.api.hub.geosphere.at/v1/station/historical/tawes-v1-10min?parameters=RR,FF,FFX,DD,P,RF&{sid_params}&start={start_ts}&end={end_ts}&output_format=geojson'
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
    label = 'hist' if duration_min > 0 else 'aktuell'
    log.debug(f'TAWES-API ({label}): {len(res)}/{len(ids)} Stationen mit Messwerten')
    return {s: p[-1] for s, p in res.items()} if duration_min == 0 else res

def correlate_tawes(initial_history=False):
    global TAWES_BUFFER
    nearby = sorted([dict(st, dist_km=round(_haversine(LAT, LON, st['lat'], st['lon']), 1),
                          bearing=round(_bearing(LAT, LON, st['lat'], st['lon']), 1))
                     for st in load_tawes_stations()], key=lambda x: x['dist_km'])
    nearby = [st for st in nearby if 2 < st['dist_km'] <= TAWES_MAX_KM][:TAWES_MAX_STATIONS]
    if not nearby: return {}
    if initial_history:
        # 120 Minuten entspricht maxlen=12 × 10min – Buffer vollständig befüllen
        hist = fetch_tawes_data([s['id'] for s in nearby], duration_min=120)
        for sid, points in hist.items():
            if sid not in TAWES_BUFFER: TAWES_BUFFER[sid] = deque(maxlen=12)
            for p in points: TAWES_BUFFER[sid].append(p)
    raw = fetch_tawes_data([s['id'] for s in nearby])
    if not raw and not initial_history: return {'_api_ok': False}
    for sid, vals in raw.items():
        # Nur Einträge mit mindestens einem Messwert speichern – verhindert dass
        # Stationen die vorübergehend keine Daten liefern den Buffer mit None-Werten
        # überschreiben und nach 12 Zyklen (~96min) verschwinden.
        if any(vals.get(k) is not None for k in ('RR', 'FF', 'FFX')):
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
        if ff is not None and dd is not None and ff > 1.0: rad = math.radians(dd); sin_sum += math.sin(rad)*ff; cos_sum += math.cos(rad)*ff; wr_count += 1
    dom_wr = (math.degrees(math.atan2(sin_sum, cos_sum)) + 360) % 360 if wr_count > 0 else 0.0
    dom_wr_name = _bearing_to_name(dom_wr) if wr_count > 0 else '–'
    upstream, all_st = [], []
    for st in nearby:
        sid = st['id']; vals = list(TAWES_BUFFER.get(sid, [{}]))[-1]; up = False
        if wr_count > 0:
            diff = abs(dom_wr - st['bearing']); up = min(diff, 360 - diff) < TAWES_UPSTREAM_WINKEL
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

    # Physik-ETA: nächste Upstream-Station mit Regen + Windgeschwindigkeit → Reisezeit
    # Regenfront bewegt sich in Windrichtung mit ~Windgeschwindigkeit.
    # Formel: eta_min = dist_km / (wind_kmh / 60) → Minuten bis Ankunft
    eta_physik = -1
    eta_physik_station = ''
    if w_up_kmh >= 5 and reg_up:
        up_mit_regen = sorted(
            [s for s in upstream if (s.get('RR') or 0) > 0.1],
            key=lambda s: s['dist_km']
        )
        if up_mit_regen:
            naechste = up_mit_regen[0]
            eta_physik = max(0, round(naechste['dist_km'] / (w_up_kmh / 60)))
            eta_physik_station = f'{naechste["name"]} ({naechste["dist_km"]:.0f}km)'

    ns = upstream[0] if upstream else (nearby[0] if nearby else None)
    n_mit_daten = sum(1 for s in all_st if s.get('FF_kmh') is not None or s.get('FFX_kmh') is not None or s.get('RR') is not None)
    eta_log = f' | eta_physik={eta_physik}min ({eta_physik_station})' if eta_physik >= 0 else ''
    log.info(f'TAWES OK | {n_mit_daten}/{len(nearby)} Stationen aktiv | upstream={len(upstream)} (±{TAWES_UPSTREAM_WINKEL}° von {dom_wr_name}) | wind_up={w_up_kmh}km/h | regen_up={reg_up}{eta_log}')
    # Debug: Upstream-Stationen mit Messwerten
    for _s in upstream[:8]:
        log.debug(f'[TAWES upstream] {_s["name"]} ({_s.get("dist_km",0):.1f}km, {_s.get("bearing_name","?")}): FF={_s.get("FF_kmh")} FFX={_s.get("FFX_kmh")} RR={_s.get("RR")} Höhe={_s.get("alt",0)}m')
    if n_mit_daten < len(nearby) // 2:
        offline = [s['name'] for s in all_st if s.get('FF_kmh') is None and s.get('FFX_kmh') is None and s.get('RR') is None]
        log.debug(f'TAWES: {len(offline)} Stationen ohne aktuelle Messwerte: {", ".join(offline[:10])}{"..." if len(offline)>10 else ""}')
    return {
        'letztes_update': datetime.now().astimezone().strftime('%d.%m.%Y %H:%M:%S'),
        'dominante_windrichtung': round(dom_wr, 1), 'dominante_windrichtung_name': dom_wr_name,
        'wind_upstream_kmh': w_up_kmh, 'sturm_upstream': sturm_up, 'regen_upstream': reg_up, 'regen_upstream_mm': r_up_mm,
        'upstream_aktiv': len(upstream), 'regen_lokal': r_lok, 'regen_lokal_mm': r_lok_mm, 'regen_lokal_station': r_lok_st,
        'alpine_upstream': alp_cnt, 'naechste_station_name': ns['name'] if ns else '–',
        'naechste_station_km': ns['dist_km'] if ns else 0, 'naechste_station_richtung': ns.get('bearing_name', '–') if ns else '–',
        'eta_physik_min': eta_physik, 'eta_physik_station': eta_physik_station,
        'alle_stationen': all_st, '_api_ok': True,
    }

# ---------------------------------------------------------------------------
# ZAMG – GeoSphere Austria Offizielle Warnungen
# ---------------------------------------------------------------------------
WARN_TYPES_FULL = {1:'wind', 2:'regen', 3:'schnee', 4:'glatteis', 5:'gewitter', 6:'hitze', 7:'kaelte', 8:'hagel'}
_STUFE_FARBE    = {1:'⚠️ GELB', 2:'🟠 ORANGE', 3:'🔴 ROT', 4:'🟣 LILA'}

def fetch_zamg():
    url  = f'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lat={LAT:.6f}&lon={LON:.6f}&lang={LBLANG}'
    data = fetch_json(url, 'ZAMG')
    if data is None: return None
    now_ts = int(time.time())

    # API-Antwort: {"type":"Feature","properties":{"location":{...},"warnings":[...]}}
    # Warnungen sind in data['properties']['warnings'] – NICHT in data['warnings']!
    raw = []
    if isinstance(data, dict):
        raw = data.get('properties', {}).get('warnings', [])
        if not raw:  # Fallback für alte/alternative Formate
            raw = data.get('warnings', data.get('features', []))
            if not raw:
                for k in ('items', 'data', 'Warnings'):
                    if k in data: raw = data[k]; break
    if not raw:
        log.debug(f'ZAMG: Keine Warnungen in Antwort – Top-Keys: {list(data.keys())[:10]}')

    result = {wt: {'stufe':0,'aktiv':0,'bald':0,'tageswarnung':0,'start_epoch':0,'end_epoch':0,'notification':''} for wt in WARN_TYPES_FULL.values()}
    max_stufe = 0; akut = 0; irgendwas = 0; warn_texts = []; tages_texts = []
    # Tageswarnung-Horizont: bis zu 8 Stunden voraus (für Morgeninfo im notification/tageswarnung)
    TAGES_HORIZONT = 8 * 3600
    for w in raw:
        p = w.get('properties', w)
        # rawinfo enthält wtype/wlevel + Unix-Timestamps (bevorzugt!)
        rawinfo = p.get('rawinfo', {})
        try:
            wtype = int(rawinfo.get('wtype') or p.get('warntypid') or p.get('wtype') or
                        p.get('type') or p.get('warningtype') or 0)
        except: continue
        try:
            wlevel = int(rawinfo.get('wlevel') or p.get('warnstufeid') or p.get('wlevel') or
                         p.get('level') or p.get('warninglevel') or p.get('severity') or 0)
        except: continue
        typ = WARN_TYPES_FULL.get(wtype)
        if not typ or wlevel == 0: continue
        if wtype not in ZAMG_AKTIVE_TYPEN:
            log.debug(f'[ZAMG] Warntyp {wtype} ({typ}) ignoriert (nicht in AKTIVE_TYPEN)')
            continue
        # rawinfo.start/end sind Unix-Timestamps als Strings → direkt int()
        try: s_ep = int(rawinfo['start']) if rawinfo.get('start') else 0
        except: s_ep = 0
        try: e_ep = int(rawinfo['end'])   if rawinfo.get('end')   else 0
        except: e_ep = 0
        # Fallback auf ISO-Felder
        if not s_ep: s_ep = _parse_iso(p.get('startzeit', p.get('validFrom', p.get('start', ''))))
        if not e_ep: e_ep = _parse_iso(p.get('endzeit',   p.get('validTo',   p.get('end',   ''))))
        if e_ep == 0: e_ep = max(s_ep, now_ts) + 86400
        is_act   = s_ep <= now_ts <= e_ep
        is_soon  = not is_act and 0 < (s_ep - now_ts) <= 1800
        is_today = not is_act and not is_soon and 0 < (s_ep - now_ts) <= TAGES_HORIZONT
        if not (is_act or is_soon or is_today): continue
        if p.get('akutwarnung', rawinfo.get('gwa', p.get('gwa', p.get('isGWA', 0)))): akut = 1
        if wlevel > result[typ]['stufe']:
            result[typ]['stufe'] = wlevel; result[typ]['start_epoch'] = s_ep; result[typ]['end_epoch'] = e_ep
        result[typ]['aktiv']       = max(result[typ]['aktiv'],       int(is_act))
        result[typ]['bald']        = max(result[typ]['bald'],        int(is_soon))
        result[typ]['tageswarnung']= max(result[typ]['tageswarnung'], int(is_today))
        sf = _STUFE_FARBE.get(wlevel, f'Stufe {wlevel}')
        tn = L.get(typ, typ.upper())
        nt = f'{sf} – {tn} | {fmt_dt(s_ep)} – {fmt_dt(e_ep)}'
        result[typ]['notification'] = nt
        if is_act or is_soon:
            max_stufe = max(max_stufe, wlevel); irgendwas = 1
            if nt not in warn_texts: warn_texts.append(nt)
        elif is_today:
            tt = f'📅 {fmt_dt(s_ep)}: {sf} {tn}'
            if tt not in tages_texts: tages_texts.append(tt)
    n_active = len([t for t, v in result.items() if v['aktiv']])
    n_today  = len([t for t, v in result.items() if v['tageswarnung']])
    log.info(f'GeoSphere ZAMG: OK | {n_active} aktiv | {n_today} Tageswarnungen | max_stufe={max_stufe} | akut={akut}')
    if n_active or n_today:
        for typ, v in result.items():
            if v['stufe']:
                log.debug(f'[ZAMG] {typ}: stufe={v["stufe"]} aktiv={v["aktiv"]} bald={v["bald"]} tages={v["tageswarnung"]} | {v["notification"]}')
    notif_tages = ' | '.join(tages_texts) if tages_texts else ''
    return {**result, 'max_stufe':max_stufe, 'akutwarnung':akut, 'irgendwas_aktiv':irgendwas,
            'letzter_abruf':datetime.now().astimezone().strftime('%d.%m.%Y %H:%M:%S'),
            'notification_geosphere':(' | '.join(warn_texts) if warn_texts else L['no_warns']),
            'notification_tageswarnung': notif_tages, '_api_ok':True}

# ---------------------------------------------------------------------------
# INCA Nowcast
# ---------------------------------------------------------------------------
def fetch_inca():
    """INCA Nowcast – 4 Parameter PARALLEL abrufen (max ~15s statt 60s bei API-Problemen).
    URL-Format: lat_lon=LAT%2CLON&parameters={param}&output_format=geojson"""
    if not INCA_ENABLED: return {}
    now = datetime.now(tz=timezone.utc)
    n_steps = max(1, INCA_HORIZON // 15)
    res = dict(
        ff_jetzt=0.0, fx_jetzt=0.0, fx_max_30min=0.0, fx_max_60min=0.0,
        rr_jetzt=0.0, rr_max_30min=0.0, rr_max_60min=0.0,
        pt_jetzt=255, pt_name=L['pt_none'],
        pt_bald=255, pt_bald_name='',
        bald_regen=0, bald_hagel=0, bald_graupel=0,
        bald_sturm_30=0, bald_sturm_60=0, minuten_bis_regen=-1, regen_alarm=0,
    )
    _fehler = []
    _base = 'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km'

    def _req(param):
        url = f'{_base}?lat_lon={LAT:.6f}%2C{LON:.6f}&parameters={param}&output_format=geojson'
        return fetch_json(url, f'INCA {param}')

    # Alle 4 API-Calls gleichzeitig starten
    _raw = {}
    with ThreadPoolExecutor(max_workers=4) as _ex:
        _futs = {_ex.submit(_req, p): p for p in ('ff', 'fx', 'rr', 'pt')}
        try:
            for _fut in as_completed(_futs, timeout=20):
                _raw[_futs[_fut]] = _fut.result()
        except Exception:
            pass
    for p in ('ff', 'fx', 'rr', 'pt'):
        if p not in _raw:
            _raw[p] = None

    for param in ('ff', 'fx', 'rr', 'pt'):
        data = _raw[param]
        if not data:
            _fehler.append(param)
            continue
        ts_list = data.get('timestamps', [])
        features = data.get('features', [])
        if not features:
            _fehler.append(param)
            continue
        values = features[0].get('properties', {}).get('parameters', {}).get(param, {}).get('data', [])
        if param == 'ff':
            res['ff_jetzt'] = round(float(values[0]) * 3.6, 1) if values else 0.0
        elif param == 'fx':
            serie = [round(float(v) * 3.6, 1) for v in values[:n_steps] if v is not None]
            res['fx_jetzt'] = serie[0] if serie else 0.0
            m30 = m60 = 0.0
            for i, ts in enumerate(ts_list[:n_steps]):
                if i >= len(serie): break
                v = serie[i]; t = _parse_iso(ts)  # t ist Unix-Timestamp als int
                if t <= now.timestamp() + 1800 and v > m30: m30 = v
                if t <= now.timestamp() + 3600 and v > m60: m60 = v
            res['fx_max_30min'] = round(m30, 1); res['fx_max_60min'] = round(m60, 1)
            res['bald_sturm_30'] = int(m30 >= BOEN_ALARM); res['bald_sturm_60'] = int(m60 >= BOEN_ALARM)
        elif param == 'rr':
            serie = [round(float(v), 2) if v is not None else 0.0 for v in values[:n_steps]]
            res['rr_jetzt'] = serie[0] if serie else 0.0
            res['regen_alarm'] = int(res['rr_jetzt'] >= REGEN_ALARM)
            # Spitzenwerte der nächsten 30/60 min – gleiche Logik wie fx_max_30/60min
            m30r = m60r = 0.0
            for j, ts in enumerate(ts_list[:n_steps]):
                if j >= len(serie): break
                v = serie[j]; t_ts = _parse_iso(ts)
                if t_ts <= now.timestamp() + 1800 and v > m30r: m30r = v
                if t_ts <= now.timestamp() + 3600 and v > m60r: m60r = v
            res['rr_max_30min'] = round(m30r, 2)
            res['rr_max_60min'] = round(m60r, 2)
            # Erster Zeitschritt mit signifikantem Regen → ETA und bald_regen
            # Schwelle: 25% von REGEN_ALARM – filtert Modellrauschen (<0.01mm/h Artefakte)
            _eta_threshold = REGEN_ALARM * 0.25
            for i, ts in enumerate(ts_list[:n_steps]):
                if i < len(serie) and serie[i] >= _eta_threshold:
                    t_ts = _parse_iso(ts)
                    dt = max(0, int((t_ts - now.timestamp()) / 60))
                    res['minuten_bis_regen'] = dt; res['bald_regen'] = int(dt <= 30)
                    res['_regen_idx'] = i
                    break
        elif param == 'pt':
            serie = [int(v) if v is not None else 255 for v in values[:n_steps]]
            res['pt_jetzt'] = serie[0] if serie else 255
            res['pt_name'] = PT_NAME.get(res['pt_jetzt'], 'unbekannt')
            ri = res.get('_regen_idx', -1)
            if 0 <= ri < len(serie) and serie[ri] not in (255,):
                res['pt_bald'] = serie[ri]; res['pt_bald_name'] = PT_NAME.get(serie[ri], 'Regen')
            for i, ts in enumerate(ts_list[:n_steps]):
                if i < len(serie):
                    t = _parse_iso(ts)  # int Unix-Timestamp
                    if t <= now.timestamp() + 3600:
                        if serie[i] == 5: res['bald_hagel'] = 1
                        if serie[i] == 4: res['bald_graupel'] = 1
    if len(_fehler) == 4:
        log.error('INCA: Alle 4 Parameter fehlgeschlagen')
        return None
    if _fehler:
        log.warning(f'INCA: {len(_fehler)}/4 Parameter fehlgeschlagen ({", ".join(_fehler)}) – Teildaten verwendet')
    fx60 = res['fx_max_60min']
    rr_peak_log = res['rr_max_30min']
    peak_hint = f' (max30: {rr_peak_log})' if rr_peak_log > res['rr_jetzt'] else ''
    log.info(f'INCA OK | Böen {fx60} km/h | Regen {res["rr_jetzt"]}{peak_hint} mm/h | ETA {res["minuten_bis_regen"]}min | PT={res["pt_name"]}')
    # Debug: erste 4 Zeitschritte jedes Parameters als Überblick
    for _dbg_param, _dbg_key, _dbg_unit in (('ff','ff_jetzt','km/h'),('fx','fx_max_60min','km/h'),('rr','rr_jetzt','mm/h'),('pt','pt_name','')):
        if _dbg_param not in _fehler:
            try:
                _vals = _raw[_dbg_param]['features'][0]['properties']['parameters'][_dbg_param]['data'][:4]
                log.debug(f'[INCA {_dbg_param}] Zeitschritte[0..3]: {_vals} | Resultat: {res[_dbg_key]}{_dbg_unit}')
            except Exception:
                pass
    res.pop('_regen_idx', None)
    res['letzter_abruf'] = datetime.now().astimezone().strftime('%d.%m.%Y %H:%M:%S')
    res['_api_ok'] = True
    return res

# ---------------------------------------------------------------------------
# Alarm-Aggregation (ZAMG + INCA + TAWES → alarm/ Topics)
# ---------------------------------------------------------------------------
def _stufe_al(s): return 0 if s<=0 else (1 if s==1 else (2 if s==2 else 3))
def _mm_al(mm):   return 0 if mm < REGEN_ALARM else (3 if mm >= 3*REGEN_ALARM else (2 if mm >= 2*REGEN_ALARM else 1))
def _fx_al(fx):   return 0 if fx < BOEN_ALARM  else (3 if fx >= 3*BOEN_ALARM  else (2 if fx >= 2*BOEN_ALARM  else 1))

def build_alarm(zamg, inca, tawes, prev_alarm, trend=None):
    """Multi-Source Alarm-Fusion mit Konfidenz-Scoring und Trend-Eskalation.

    Datenschichten:
      ZAMG  – amtl. GeoSphere Warnung → direktes Vertrauen, Level 1-3 ohne Korroboration
      INCA  – 15-min Nowcast (Modell) → allein max Level 1; mit Trend-Bestätigung max Level 2
      TAWES – Realmessungen der Umgebungsstationen → allein max Level 1;
              kombiniert mit INCA → voller Level 1-3

    Konfidenz-Score 0-100:
      Basis: ZAMG aktiv = 40 | INCA Signal = 30 | TAWES Signal = 20
      Bonus: Trend konsistent über ≥3 Zyklen = +10…+25 (aus trend['konfidenz_bonus'])
    """
    z = zamg or {}; i = inca or {}; t = tawes or {}
    tr = trend or {}

    trend_bonus   = tr.get('konfidenz_bonus', 0)
    inca_zyklen   = tr.get('inca_trend_zyklen', 0)
    regen_trend   = tr.get('regen_trend', 'unbekannt')
    eta_korr      = tr.get('eta_korrigiert', -1)

    # Physik-ETA aus TAWES: Entfernung der nächsten Upstream-Station mit Regen ÷ Windgeschwindigkeit
    eta_physik    = t.get('eta_physik_min', -1)

    # === REGEN ===
    a_r = 0; rq = '–'; conf_r = 0
    # ZAMG nur wenn aktiv ODER bald (< 30 Min) – fernere Warnungen nur in notification/tageswarnung
    _zr_d = z.get('regen', {})
    zr = _stufe_al(_zr_d.get('stufe', 0)) if (_zr_d.get('aktiv') or _zr_d.get('bald')) else 0
    if zr: a_r = max(a_r, zr); rq = 'ZAMG'; conf_r = max(conf_r, 40)

    rr       = i.get('rr_jetzt', 0) or 0
    rr_max30 = i.get('rr_max_30min', 0) or 0
    eta      = i.get('minuten_bis_regen', -1)
    ir_raw   = _mm_al(rr) or (1 if eta > 0 else 0)
    tawes_regen = bool(t.get('regen_upstream', 0) or t.get('regen_lokal', 0))
    up_mm    = t.get('regen_upstream_mm', 0) or 0
    lok_mm   = t.get('regen_lokal_mm', 0) or 0

    # Physik-ETA Konfidenz: INCA-Modell und TAWES-Reisezeit stimmen überein?
    # Übereinstimmung innerhalb 10 Min = starke gegenseitige Bestätigung → +15 Konfidenzpunkte
    eta_physik_bonus = 0
    if eta > 0 and eta_physik >= 0 and abs(eta - eta_physik) <= 10:
        eta_physik_bonus = 15

    if ir_raw:
        conf_r += 30
        rr_peak = max(rr, rr_max30)
        if tawes_regen or zr:
            ir  = max(_mm_al(rr_peak), 1)
            rq  = f'INCA+TAWES ({rr}→{rr_peak}mm/h)' if tawes_regen else f'INCA+ZAMG ({rr}→{rr_peak}mm/h)'
        elif inca_zyklen >= 4:
            ir  = min(2, max(_mm_al(rr_peak), 1))
            rq  = f'INCA ({rr}→{rr_peak}mm/h, {inca_zyklen} Zyklen)'
        else:
            ir  = min(1, _mm_al(rr)) if rr >= REGEN_ALARM else (1 if eta > 0 else 0)
            rq  = f'INCA ({rr}mm/h)' if rr > 0 else f'INCA (Regen ~{eta}min)'
        a_r = max(a_r, ir)

    if tawes_regen:
        conf_r += 20
    # TAWES upstream: Stufe aus gemessenen mm/h → immer durch _mm_al (Schwelle gilt)
    # Ohne INCA-Signal: max Stufe 1 (single-source Messung)
    if up_mm >= REGEN_ALARM:
        tr_lvl = _mm_al(up_mm) if ir_raw else min(1, _mm_al(up_mm))
        a_r = max(a_r, tr_lvl)
        if rq == '–': rq = f'TAWES_UP ({up_mm}mm/h)'
    # TAWES lokal: immer max Stufe 1 (nur Lokal-Station, kein Upstream-Konsens)
    if lok_mm >= REGEN_ALARM:
        a_r = max(a_r, 1)
        if rq == '–': rq = f'TAWES_LOK ({t.get("regen_lokal_station","")}) {lok_mm}mm/h'

    # Trend-Bonus auf Konfidenz, limitiert auf max Level
    conf_r = min(100, conf_r + trend_bonus + eta_physik_bonus)

    # === WIND ===
    a_w = 0; wq = '–'; conf_w = 0
    _zw_d = z.get('wind', {})
    zw = _stufe_al(_zw_d.get('stufe', 0)) if (_zw_d.get('aktiv') or _zw_d.get('bald')) else 0
    if zw: a_w = max(a_w, zw); wq = 'ZAMG'; conf_w = max(conf_w, 40)

    fx60     = i.get('fx_max_60min', 0) or 0
    iw_raw   = _fx_al(fx60)
    tawes_wind = bool(t.get('sturm_upstream', 0) or t.get('wind_kaskade', 0))
    w_up     = t.get('wind_upstream_kmh', 0) or 0

    if iw_raw:
        conf_w += 30
        if tawes_wind or zw:
            iw = iw_raw; wq = f'INCA+TAWES ({fx60}km/h)' if tawes_wind else f'INCA+ZAMG ({fx60}km/h)'
        else:
            iw = min(1, iw_raw); wq = f'INCA ({fx60}km/h)'
        a_w = max(a_w, iw)
    if tawes_wind: conf_w += 20
    if t.get('sturm_upstream', 0):
        tw = _fx_al(w_up) if iw_raw else min(1, _fx_al(w_up))
        if tw: a_w = max(a_w, tw); wq = wq if wq != '–' else f'TAWES_STURM ({w_up}km/h)'
    if t.get('wind_kaskade', 0) and a_w == 0: a_w = 1; wq = 'TAWES_KASKADE'
    conf_w = min(100, conf_w + trend_bonus)

    # === GEWITTER / HAGEL / SCHNEE ===
    # ZAMG-Stufen nur wenn aktiv ODER bald (< 30 Min) – fernere Warnungen nur in notification/tageswarnung
    _zg_d = z.get('gewitter', {})
    a_g = _stufe_al(_zg_d.get('stufe', 0)) if (_zg_d.get('aktiv') or _zg_d.get('bald')) else 0
    gs  = int(t.get('gewitter_signal', 0) or 0)
    if gs >= 1: a_g = max(a_g, 1)
    if gs >= 2: a_g = max(a_g, 2)
    if z.get('akutwarnung', 0): a_g = max(a_g, 2)

    _zh_d = z.get('hagel', {})
    a_h = min(2, _stufe_al(_zh_d.get('stufe', 0)) if (_zh_d.get('aktiv') or _zh_d.get('bald')) else 0)
    if i.get('bald_hagel',0) or i.get('bald_graupel',0): a_h = max(a_h, 1)

    _zs_d = z.get('schnee', {}); _ze_d = z.get('glatteis', {})
    a_s = min(2, max(
        _stufe_al(_zs_d.get('stufe', 0)) if (_zs_d.get('aktiv') or _zs_d.get('bald')) else 0,
        _stufe_al(_ze_d.get('stufe', 0)) if (_ze_d.get('aktiv') or _ze_d.get('bald')) else 0
    ))
    if i.get('pt_jetzt',255) in (2,3): a_s = max(a_s, 1)

    a_ges = max(a_w, a_r, a_g, a_h, a_s)
    konfidenz = max(conf_r, conf_w) if a_ges > 0 else 0
    prev_g = int((prev_alarm or {}).get('gesamt', 0))
    entw = int(prev_g >= 1 and a_ges == 0)

    # ETA-Auswahl: Reihenfolge der Verlässlichkeit
    # 1. Trendkorrigierter INCA-ETA (Extrapolation der Zeitreihe)
    # 2. Physik-ETA aus TAWES Reisezeit (wenn INCA und Physik sich einig sind → nimm den früheren)
    # 3. INCA-Rohwert
    if eta_korr >= 0 and eta_physik >= 0 and eta_physik_bonus > 0:
        # Beide Quellen aktiv und sie stimmen überein → früherer Wert = konservativer
        eta_best = min(eta_korr, eta_physik)
    elif eta_korr >= 0:
        eta_best = eta_korr
    elif eta_physik >= 0 and tawes_regen:
        # Kein INCA-Trend, aber Physik-ETA aus bestätigtem TAWES-Regen
        eta_best = eta_physik
    else:
        eta_best = eta if eta >= 0 else -1

    # Zusammenfassung in Alltagssprache: Was wird gewarnt, Warum (Quelle), Wie sicher, Was bedeutet das.
    # Kein Fachjargon – verständlich auch ohne Meteorologie-Kenntnisse.
    def _w_quelle(zd, wq_str):
        """Lesbare Quellenangabe für Wind-Warnung."""
        sf = _STUFE_FARBE.get(zd.get('stufe') or 0, '')
        if zd.get('stufe'):    return f'Amtliche Warnung {sf} (GeoSphere Austria)'
        if 'TAWES' in wq_str:  return 'Wetterstationen in der Umgebung bestätigt'
        if 'INCA'  in wq_str:  return 'Wetterradar-Prognose'
        return 'Wetterradar-Prognose'

    def _r_quelle(zd, rq_str):
        """Lesbare Quellenangabe für Regen-Warnung."""
        sf = _STUFE_FARBE.get(zd.get('stufe') or 0, '')
        if zd.get('stufe'):              return f'Amtliche Warnung {sf} (GeoSphere Austria)'
        if 'TAWES' in rq_str and 'INCA' in rq_str: return 'Radar + Wetterstationen bestätigt'
        if 'TAWES' in rq_str:           return 'Wetterstationen in der Umgebung'
        if 'INCA'  in rq_str:           return 'Wetterradar-Prognose'
        return 'Wetterradar-Prognose'

    kfz_txt = ('sehr zuverlässig' if konfidenz >= 80 else
               ('zuverlässig'     if konfidenz >= 60 else
               ('wahrscheinlich'  if konfidenz >= 40 else 'unsicher')))
    parts = []

    # --- WIND ---
    if a_w > 0:
        zw_d    = z.get('wind', {})
        peak    = max(fx60, i.get('fx_jetzt', 0) or 0, w_up)
        wr_name = t.get('dominante_windrichtung_name', '') if tawes_wind else ''
        W_LBL   = ['', 'Böen erwartet', 'Sturm erwartet', 'Extremsturm']
        w_part  = f'💨 {W_LBL[min(a_w,3)]} (Warnstufe {a_w}/3)'
        if peak > 0:
            w_part += f' – Böen bis {round(peak)} km/h'
            if wr_name: w_part += f' aus {wr_name}'
        w_part += f' | {_w_quelle(zw_d, wq)}'
        if zw_d.get('aktiv') and zw_d.get('end_epoch', 0) > 0:
            w_part += f' | Warnung gültig bis {fmt_dt(zw_d["end_epoch"])}'
        elif zw_d.get('bald') and zw_d.get('start_epoch', 0) > 0:
            w_part += f' | Warnung gültig ab {fmt_dt(zw_d["start_epoch"])}'
        parts.append(w_part)

    # --- REGEN ---
    if a_r > 0:
        zr_d = z.get('regen', {})
        R_LBL = ['', 'Regen möglich', 'Regen im Anmarsch', 'Starkregen']
        rp    = f'🌧️ {R_LBL[min(a_r,3)]} (Warnstufe {a_r}/3)'
        if rr > 0.1:
            rp += f' – {rr:.1f} mm/h bereits vor Ort'
        elif up_mm > 0:
            rp += f' – {up_mm:.1f} mm/h in der näheren Umgebung gemessen'
        if eta_best > 0:    rp += f' | Ankunft in ca. {eta_best} Minuten'
        elif eta_best == 0: rp += ' | Regen ist jetzt vor Ort'
        if zr_d.get('aktiv') and zr_d.get('end_epoch', 0) > 0:
            rp += f' | Warnung gültig bis {fmt_dt(zr_d["end_epoch"])}'
        elif zr_d.get('bald') and zr_d.get('start_epoch', 0) > 0:
            rp += f' | Warnung gültig ab {fmt_dt(zr_d["start_epoch"])}'
        if regen_trend == 'stark_zunehmend': rp += ' | Intensität nimmt rasch zu'
        elif regen_trend == 'zunehmend':     rp += ' | Intensität nimmt zu'
        elif regen_trend == 'abnehmend':     rp += ' | Lässt nach'
        rp += f' | {_r_quelle(zr_d, rq)}'
        parts.append(rp)

    # --- GEWITTER ---
    if a_g > 0:
        zg_d   = z.get('gewitter', {})
        gs_sig = int(t.get('gewitter_signal', 0) or 0)
        G_LBL  = ['', 'Gewitter möglich', 'Gewitter wahrscheinlich', 'Heftiges Gewitter']
        sf_g   = _STUFE_FARBE.get(zg_d.get('stufe') or 0, '')
        if zg_d.get('stufe'):
            g_src = f'Amtliche Warnung {sf_g} (GeoSphere Austria)'
        elif gs_sig:
            g_src = 'Blitze in der Umgebung bereits messbar'
        else:
            g_src = 'Wetterradar-Prognose'
        g_part = f'⚡ {G_LBL[min(a_g,3)]} (Warnstufe {a_g}/3) | Blitz und Donner erwartet | {g_src}'
        if zg_d.get('aktiv') and zg_d.get('end_epoch', 0) > 0:
            g_part += f' | Warnung gültig bis {fmt_dt(zg_d["end_epoch"])}'
        elif zg_d.get('bald') and zg_d.get('start_epoch', 0) > 0:
            g_part += f' | Warnung gültig ab {fmt_dt(zg_d["start_epoch"])}'
        parts.append(g_part)

    # --- HAGEL ---
    if a_h > 0:
        zh_d = z.get('hagel', {})
        sf_h = _STUFE_FARBE.get(zh_d.get('stufe') or 0, '')
        if zh_d.get('stufe'):
            h_src = f'Amtliche Warnung {sf_h} (GeoSphere Austria)'
        elif i.get('bald_hagel'):
            h_src = 'Hagelkörner laut Wetterradar möglich'
        else:
            h_src = 'Graupel laut Wetterradar möglich'
        h_part = f'🌨 Hagelgefahr (Warnstufe {a_h}/3) | Schäden an Fahrzeugen und Pflanzen möglich | {h_src}'
        if zh_d.get('aktiv') and zh_d.get('end_epoch', 0) > 0:
            h_part += f' | Warnung gültig bis {fmt_dt(zh_d["end_epoch"])}'
        parts.append(h_part)

    # --- SCHNEE / EIS ---
    if a_s > 0:
        zs_d  = z.get('schnee', {})
        ze_d  = z.get('glatteis', {})
        zse_d = zs_d if (zs_d.get('stufe') or 0) >= (ze_d.get('stufe') or 0) else ze_d
        sf_s  = _STUFE_FARBE.get(zse_d.get('stufe') or 0, '')
        s_src = f'Amtliche Warnung {sf_s} (GeoSphere Austria)' if zse_d.get('stufe') else 'Wetterradar-Prognose'
        s_part = f'❄️ Schnee- und Glatteisgefahr (Warnstufe {a_s}/3) | Rutschgefahr auf Straßen möglich | {s_src}'
        if zse_d.get('aktiv') and zse_d.get('end_epoch', 0) > 0:
            s_part += f' | Warnung gültig bis {fmt_dt(zse_d["end_epoch"])}'
        parts.append(s_part)

    if parts and konfidenz >= 40:
        parts.append(f'Prognose-Zuverlässigkeit: {kfz_txt}')
    zusf = ' | '.join(parts) if parts else ('Entwarnung – kein Unwetter mehr aktiv' if entw else '')

    return {'gesamt':a_ges,'wind':a_w,'regen':a_r,'gewitter':a_g,'hagel':a_h,'schnee':a_s,
            'stufe':z.get('max_stufe',0),'wind_quelle':wq,'regen_quelle':rq,
            'konfidenz':konfidenz,'eta_min':eta_best,'regen_trend':regen_trend,
            'entwarnung':entw,'zusammenfassung':zusf}

def _notification_inca(i, alarm=None):
    """Nowcast-Nachricht in Klartext – 60-Minuten-Vorschau, für Loxone gut lesbar."""
    if not i: return ''
    fx60 = i.get('fx_max_60min', 0) or 0
    rr   = i.get('rr_jetzt', 0) or 0
    eta  = i.get('minuten_bis_regen', -1)
    al   = alarm or {}
    rq   = al.get('regen_quelle', '')
    kfz  = al.get('konfidenz', 0)
    rtr  = al.get('regen_trend', '')
    eta_b= al.get('eta_min', eta)
    kfz_txt = ('sehr zuverlässig' if kfz >= 80 else ('zuverlässig' if kfz >= 60 else ('wahrscheinlich' if kfz >= 40 else 'unsicher')))
    parts = []
    # Wind/Sturm
    if i.get('bald_sturm_30'):
        parts.append(f'💨 Starke Sturmböen bis {round(fx60)} km/h in weniger als 30 Minuten erwartet')
    elif i.get('bald_sturm_60'):
        parts.append(f'💨 Sturmböen bis {round(fx60)} km/h in den nächsten 60 Minuten möglich')
    # Hagel / Graupel
    if i.get('bald_hagel'):     parts.append('🌨 Hagelgefahr in den nächsten 60 Minuten – Schäden an Fahrzeugen möglich')
    elif i.get('bald_graupel'): parts.append('Graupel in den nächsten 60 Minuten möglich')
    # Regen
    if rr >= REGEN_ALARM:
        best  = ' – von Wetterstationen in der Umgebung bestätigt' if 'TAWES' in rq else (' – laut amtlicher GeoSphere-Warnung' if 'ZAMG' in rq else '')
        trend = (', intensiviert sich rasch' if rtr == 'stark_zunehmend'
                 else (', Intensität nimmt zu' if rtr == 'zunehmend'
                 else (', lässt nach' if rtr == 'abnehmend' else '')))
        parts.append(f'🌧️ Regen ist vor Ort: {rr} mm/h{best}{trend}')
    elif eta_b > 0:
        best = ' – von Wetterstationen in der Umgebung bestätigt' if 'TAWES' in rq else ''
        sicherheit = f' (Zuverlässigkeit: {kfz_txt})' if kfz >= 40 else ''
        parts.append(f'🌧️ Regen erwartet in ca. {eta_b} Minuten{best}{sicherheit}')
    # Fallback
    if not parts:
        if fx60 > 20:
            parts.append(f'Böen bis {round(fx60)} km/h möglich – kein Regenalarm')
        else:
            pt = i.get('pt_name', '')
            parts.append(f'Kein Alarm – {pt if pt else "kein Niederschlag erwartet"}')
    return ' | '.join(parts)

def _notification_tawes(t, alarm=None):
    """Messnetz-Nachricht in Klartext – zeigt was Umgebungsstationen gerade messen."""
    if not t: return ''
    al   = alarm or {}
    eta_b= al.get('eta_min', -1)
    rtr  = al.get('regen_trend', '')
    wr   = t.get('dominante_windrichtung_name', '–')
    n_up = t.get('upstream_aktiv', 0) or 0
    n_ges= len(t.get('alle_stationen', []))
    parts = []
    # Wind
    w_up = t.get('wind_upstream_kmh', 0) or 0
    if t.get('sturm_upstream'):
        parts.append(f'💨 Sturmböen aus {wr} in der Umgebung – {w_up} km/h ({n_up} Stationen)')
    elif t.get('wind_kaskade'):
        keta = t.get('wind_kaskade_eta_min', -1)
        s = f'💨 Sturmfront aus {wr} nähert sich'
        if keta > 0: s += f' – Ankunft in ~{keta} Minuten'
        parts.append(s)
    elif w_up > 20 and n_up > 0:
        parts.append(f'Wind aus {wr}: {w_up} km/h ({n_up} Stationen)')
    # Regen
    up_mm = t.get('regen_upstream_mm', 0) or 0
    if up_mm > 0:
        s = f'🌧️ Regen aus {wr} nähert sich – {up_mm} mm/h gemessen'
        if eta_b > 0: s += f', Ankunft in ~{eta_b} Minuten'
        if rtr == 'stark_zunehmend': s += ', intensiviert sich rasch'
        elif rtr == 'zunehmend': s += ', Intensität nimmt zu'
        parts.append(s)
    elif t.get('regen_lokal'):
        lok    = t.get('regen_lokal_station', '') or ''
        lok_mm = t.get('regen_lokal_mm', 0) or 0
        s = f'🌧️ Regen in der Nähe: {lok_mm} mm/h'
        if lok: s += f' (bei {lok})'
        parts.append(s)
    # Fallback
    if not parts:
        if n_ges > 0:
            parts.append(f'{n_ges} Wetterstationen in der Umgebung – aktuell kein Unwetter-Signal')
    return ' | '.join(parts)

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

def publish_all(status_msg, tawes=None, zamg=None, inca=None, alarm=None, prev=None):
    now = datetime.now().astimezone(); ts_iso = now.strftime('%d.%m.%Y %H:%M:%S'); ts_epoch = int(now.timestamp())
    publish('letzter_abruf_datum',  ts_iso); publish('letzter_abruf_epoch', ts_epoch); publish('status/last_seen', ts_epoch)
    publish('status', L['status_ok'] if status_msg == 'OK' else L['status_err'].format(v=status_msg), retain=True)
    zamg_ok  = 1 if (zamg  and zamg.get('_api_ok'))        else 0
    inca_ok  = 1 if (inca  and inca.get('_api_ok'))        else 0
    tawes_ok = 1 if (tawes and tawes.get('_api_ok', True)) else 0
    publish('status/zamg_ok',         zamg_ok)
    publish('status/inca_ok',         inca_ok)
    publish('status/tawes_ok',        tawes_ok)
    publish('status/mqtt_reconnects', _mqtt_reconnect_count)
    # Fehlertext für Loxone Push-Notifications (leer = alles OK)
    fehler_teile = []
    if not zamg_ok:  fehler_teile.append('ZAMG: API-Fehler')
    if not inca_ok:  fehler_teile.append('INCA: API-Fehler')
    if not tawes_ok: fehler_teile.append('TAWES: API-Fehler')
    publish('status/api_fehler', ' | '.join(fehler_teile), retain=True)
    publish('status/api_ok', 1 if not fehler_teile else 0)

    # ZAMG
    if zamg:
        publish('zamg/max_stufe',       zamg.get('max_stufe', 0))
        publish('zamg/irgendwas_aktiv', zamg.get('irgendwas_aktiv', 0))
        publish('zamg/akutwarnung',     zamg.get('akutwarnung', 0))
        publish('zamg/letzter_abruf',   zamg.get('letzter_abruf', ''))
        for typ in WARN_TYPES_FULL.values():
            d = zamg.get(typ, {})
            publish(f'zamg/{typ}/stufe',       d.get('stufe', 0))
            publish(f'zamg/{typ}/aktiv',       d.get('aktiv', 0))
            publish(f'zamg/{typ}/bald',        d.get('bald', 0))
            publish(f'zamg/{typ}/start_epoch', d.get('start_epoch', 0))
            publish(f'zamg/{typ}/end_epoch',   d.get('end_epoch', 0))
            publish(f'zamg/{typ}/notification',d.get('notification', ''))
        publish('notification/geosphere', zamg.get('notification_geosphere', L['no_warns']))

    # INCA – MQTT-Topics behalten alte Namen (inca/fx, inca/ff, inca/rr, inca/pt)
    # Interne Feldnamen sind jetzt fx_jetzt, ff_jetzt, rr_jetzt, pt_jetzt
    if inca:
        publish('inca/fx',       inca.get('fx_jetzt', 0))
        publish('inca/ff',       inca.get('ff_jetzt', 0))
        publish('inca/rr',       inca.get('rr_jetzt', 0))
        publish('inca/pt',       inca.get('pt_jetzt', 255))
        for k in ('regen_alarm','pt_name','pt_bald','pt_bald_name',
                  'fx_max_30min','fx_max_60min','bald_regen','bald_hagel','bald_graupel',
                  'bald_sturm_30','bald_sturm_60','minuten_bis_regen','letzter_abruf'):
            publish(f'inca/{k}', inca.get(k, '' if k in ('pt_name','pt_bald_name','letzter_abruf') else 0))

    # TAWES
    if tawes and tawes.get('_api_ok', True):
        for k in ('dominante_windrichtung','dominante_windrichtung_name','upstream_aktiv',
                  'wind_upstream_kmh','sturm_upstream','regen_upstream','regen_upstream_mm',
                  'regen_lokal','regen_lokal_mm','regen_lokal_station','alpine_upstream','letztes_update',
                  'wind_kaskade','wind_kaskade_eta_min','wind_kaskade_speed_kmh',
                  'regen_eta_min','regen_konfidenz','front_speed_kmh','druck_trend','gewitter_signal'):
            publish(f'tawes/{k}', tawes.get(k, 0))
        ns = f'{tawes.get("naechste_station_name","–")} ({tawes.get("naechste_station_km",0)}km) {tawes.get("naechste_station_richtung","–")}'
        publish('tawes/naechste_station', ns)
        publish('tawes/stationen_anzahl',  len(tawes.get('alle_stationen', [])))
        publish('tawes/api_ok', 1)

    # Alarm
    a_ges = 0; entw = 0
    if alarm:
        a_ges = alarm.get('gesamt', 0); entw = alarm.get('entwarnung', 0)
        for k in ('gesamt','wind','regen','gewitter','hagel','schnee','stufe','entwarnung'):
            publish(f'alarm/{k}', alarm.get(k, 0))
        # konfidenz: 0-100 Score aus Multi-Source-Fusion (ersetzt binäres regen_konfidenz)
        publish('alarm/konfidenz',     alarm.get('konfidenz', 0))
        publish('alarm/eta_min',       alarm.get('eta_min', -1))
        publish('alarm/regen_trend',   alarm.get('regen_trend', 'unbekannt'))
        for k in ('wind_quelle','regen_quelle','zusammenfassung'):
            publish(f'alarm/{k}', alarm.get(k, '–'))

    # Notifications – inca und tawes IMMER senden (Loxone kann unabhängig anzeigen)
    notif_alle = ''
    nt_inca  = _notification_inca(inca, alarm)    if inca  else ''
    nt_tawes = _notification_tawes(tawes, alarm)  if tawes else ''
    publish('notification/inca',  nt_inca  or '')
    publish('notification/tawes', nt_tawes or '')

    # Tageswarnung: ZAMG-Warnungen 0-8h in der Zukunft → Frühinfo für Morgenroutine
    notif_tages = (zamg or {}).get('notification_tageswarnung', '')
    publish('notification/tageswarnung', notif_tages)

    if a_ges >= 1:
        # notification/alle: zusammenfassung enthält jetzt bereits Quelle + Zeit + Messwerte.
        # ZAMG-Offizialtext nur noch anhängen wenn GeoSphere-Info noch nicht drin ist
        # (Fallback für den seltenen Fall dass zamg aktiv aber nicht in build_alarm erfasst).
        notif_alle = (alarm or {}).get('zusammenfassung', '')
        if zamg and zamg.get('irgendwas_aktiv') and '[GeoSphere' not in notif_alle:
            zamg_txt = zamg.get('notification_geosphere', '')
            if zamg_txt and zamg_txt not in notif_alle:
                notif_alle = (notif_alle + ' | ' + zamg_txt) if notif_alle else zamg_txt
        publish('notification/alle', notif_alle)
    elif entw:
        notif_alle = L['entwarnung']
        publish('notification/alle', notif_alle)
    else:
        # Kein Alarm: Tageswarnung weiterleiten wenn vorhanden, sonst leer
        publish('notification/alle', notif_tages if notif_tages else '')

    # INCA: Felder heißen direkt fx_jetzt/ff_jetzt/rr_jetzt/pt_jetzt – keine Aliases nötig
    inca_st = {k: v for k, v in (inca or {}).items() if k != '_api_ok'}

    # TAWES: alle_stationen auf UI-relevante Felder reduzieren (Stationsliste in index.php)
    tawes_st = {k: v for k, v in (tawes or {}).items() if k != '_api_ok'}
    tawes_st['alle_stationen'] = [
        {'name': s.get('name',''), 'dist_km': s.get('dist_km', 0),
         'bearing_name': s.get('bearing_name', '–'), 'ist_upstream': s.get('ist_upstream', False),
         'FF_kmh': s.get('FF_kmh'), 'FFX_kmh': s.get('FFX_kmh'), 'RR': s.get('RR')}
        for s in (tawes or {}).get('alle_stationen', [])
    ]

    # State speichern
    save_state({
        'letztes_update': ts_iso, 'letzter_abruf_epoch': ts_epoch, 'status': status_msg,
        # Top-Level: index.php liest diese direkt (nicht verschachtelt)
        'akutwarnung':          (zamg or {}).get('akutwarnung', 0),
        'irgendwas_aktiv':      (zamg or {}).get('irgendwas_aktiv', 0),
        'max_stufe':            (zamg or {}).get('max_stufe', 0),
        'zamg_letztes_update':  (zamg or {}).get('letzter_abruf', ''),
        'inca_letztes_update':  (inca or {}).get('letzter_abruf', ''),
        'tawes_letztes_update': (tawes or {}).get('letztes_update', ''),
        'notification_alle':    notif_alle,
        'notification_tageswarnung': notif_tages,
        # Trend-Snapshot für UI-Anzeige
        'trend': {
            'regen':     (alarm or {}).get('regen_trend', ''),
            'eta_min':   (alarm or {}).get('eta_min', -1),
            'konfidenz': (alarm or {}).get('konfidenz', 0),
            'n_zyklen':  len(_TREND_HISTORY),
        },
        # Verschachtelt
        'zamg':  {k: v for k, v in (zamg or {}).items() if k != '_api_ok'},
        'inca':  inca_st,
        'alarm': alarm or {},
        'tawes': tawes_st,
    })

# Watchdog: Hard Reset wenn seit 30min kein erfolgreiches Publish (Zombie-TCP-Erkennung)
MQTT_WATCHDOG_TIMEOUT = 1800

def run():
    # Vor dem Start: sicherstellen dass keine ältere Python-Instanz noch läuft.
    # Wichtig: SIGTERM allein reicht nicht – paho's Background-Thread kann kurz nach
    # sys.exit() noch einen Reconnect versuchen und die neue Instanz kicken (→ RC=7-Loop).
    # Daher: SIGTERM senden, 2s warten, bei Bedarf SIGKILL nachlegen, nochmal 1s warten.
    my_pid = os.getpid()
    try:
        result = subprocess.run(
            ['pgrep', '-f', 'unwetter4lox_daemon.py'],
            capture_output=True, text=True
        )
        killed = []
        for pid_str in result.stdout.strip().split():
            other = int(pid_str)
            if other != my_pid:
                log.warning(f'Alte Instanz PID {other} gefunden – SIGTERM')
                try: os.kill(other, signal.SIGTERM); killed.append(other)
                except: pass
        if killed:
            time.sleep(4)   # Länger warten: alte Instanz braucht Zeit für loop_stop()+disconnect()
            # Prüfen ob noch lebt → SIGKILL
            for other in killed:
                try:
                    os.kill(other, 0)  # Existenz-Check
                    log.warning(f'Alte Instanz PID {other} noch aktiv – SIGKILL')
                    os.kill(other, signal.SIGKILL)
                except OSError: pass  # Bereits beendet
            time.sleep(2)   # Nach SIGKILL: OS braucht Zeit Socket-Cleanup + Broker-Session-Release
    except Exception: pass

    log.info(f'Unwetter4Lox v0.9.37 gestartet | ZAMG={ZAMG_INTERVAL}s INCA={INCA_INTERVAL}s TAWES={TAWES_INTERVAL}s Loop={INTERVAL}s | Broker={MQTT_BROKER}:{MQTT_PORT} | MQTT-ID={_MQTT_CLIENT_ID} | Upstream=±{TAWES_UPSTREAM_WINKEL}°')
    log.info(f'Standort: LAT={LAT:.6f} LON={LON:.6f}')
    _typ_namen = {1:'wind',2:'regen',3:'schnee',4:'glatteis',5:'gewitter',6:'hitze',7:'kaelte',8:'hagel'}
    _aktiv_str = ', '.join(_typ_namen[t] for t in sorted(ZAMG_AKTIVE_TYPEN) if t in _typ_namen)
    _inaktiv   = [_typ_namen[t] for t in sorted(_typ_namen) if t not in ZAMG_AKTIVE_TYPEN]
    log.info(f'ZAMG Warntypen aktiv: {_aktiv_str}' + (f' | ignoriert: {", ".join(_inaktiv)}' if _inaktiv else ''))
    try:
        with open(PID_FILE, 'w') as f: f.write(str(my_pid))
    except: pass

    # TAWES-Cache beim Start löschen – frische Stationsdaten erzwingen
    if os.path.exists(TAWES_CACHE_FILE):
        try: os.remove(TAWES_CACHE_FILE)
        except: pass

    st = load_state(); st['status'] = 'Initialisierung...'; save_state(st)

    # Initiale MQTT-Verbindung
    if not mqtt_connect():
        log.warning('MQTT: Startverbindung fehlgeschlagen – Daemon läuft weiter, Reconnect folgt im nächsten Zyklus')

    global _first_disco_time
    first = True; last_zamg = 0; last_inca = 0; last_tawes = 0
    zamg = {}; inca = {}; tawes = st.get('tawes', {}); prev_alarm = st.get('alarm', {})

    while True:
        try:
            now = time.time()

            # --- MQTT-Status-Check ---
            # RC=7-Loop-Erkennung: _first_disco_time wird beim ERSTEN Disconnect gesetzt
            # und erst nach 120s stabiler Verbindung zurückgesetzt (NICHT bei jedem RC=0!).
            # Dieser Check läuft bei JEDER Loop-Iteration – auch wenn paho gerade verbunden ist,
            # weil im RC=7-Loop jeder Reconnect nach ~5s wieder gekickt wird.
            total_disco = now - _first_disco_time if _first_disco_time > 0 else 0
            if total_disco > 600:
                log.error(
                    f'MQTT: RC=7-Loop seit {total_disco/60:.1f}min '
                    f'({_mqtt_reconnect_count} Reconnects) – '
                    f'Daemon beendet sich, Watchdog startet sauber neu'
                )
                try: os.remove(PID_FILE)
                except: pass
                sys.exit(2)

            if not _mqtt_connected.is_set():
                elapsed_disco = now - _disconnect_since if _disconnect_since > 0 else 0
                log.info(f'MQTT: Warte auf Verbindung (seit {elapsed_disco:.0f}s getrennt, '
                         f'RC=7-Serie seit {total_disco:.0f}s, Reconnects: {_mqtt_reconnect_count})')
                if elapsed_disco > 300:
                    log.warning(f'MQTT: {elapsed_disco/60:.1f}min getrennt – Hard Reset')
                    mqtt_connect()
            else:
                # Verbunden: nach 120s Stabilität Disco-Serie als beendet markieren
                stable_for = now - _mqtt_connect_time if _mqtt_connect_time > 0 else 0
                if _first_disco_time > 0 and stable_for > 120:
                    log.info(
                        f'MQTT: {stable_for:.0f}s stabil – RC=7-Serie beendet '
                        f'(Dauer {total_disco:.0f}s, {_mqtt_reconnect_count} Reconnects)'
                    )
                    _first_disco_time = 0.0
                elif (_last_successful_publish > 0 and
                      (now - _last_successful_publish) > MQTT_WATCHDOG_TIMEOUT):
                    log.warning(
                        f'MQTT-Watchdog: Kein Publish seit {(now-_last_successful_publish)/60:.0f}min '
                        f'– Hard Reset (Reconnects gesamt: {_mqtt_reconnect_count})'
                    )
                    mqtt_connect()

            status = 'OK'

            # ZAMG – konfiguriertes Intervall (Standard: INTERVAL, min 60s)
            if ZAMG_ENABLED and (first or now - last_zamg >= ZAMG_INTERVAL):
                try:
                    new_z = fetch_zamg()
                    if new_z and new_z.get('_api_ok'): zamg = new_z; last_zamg = now
                    elif not new_z: status = 'ZAMG API Fehler'
                except Exception: log.error(f'ZAMG Fehler: {traceback.format_exc()}')

            # INCA – konfiguriertes Intervall (Standard: INTERVAL, min 60s)
            if INCA_ENABLED and (first or now - last_inca >= INCA_INTERVAL):
                try:
                    new_i = fetch_inca()
                    if new_i and new_i.get('_api_ok'): inca = new_i; last_inca = now
                    elif not new_i and status == 'OK': status = 'INCA API Fehler'
                except Exception: log.error(f'INCA Fehler: {traceback.format_exc()}')

            # TAWES – konfiguriertes Intervall (Standard: 480s, min 120s)
            if TAWES_ENABLED and (first or now - last_tawes >= TAWES_INTERVAL):
                try:
                    new_t = correlate_tawes(initial_history=first)
                    if new_t.get('_api_ok', True): tawes = new_t; last_tawes = now
                    elif status == 'OK': status = 'TAWES API Fehler'
                except Exception: log.error(f'TAWES Fehler: {traceback.format_exc()}')

            # Trend-Engine: aktuellen Zyklus einbuchen + Analyse
            _add_trend(inca or None, tawes or None)
            trend = _analyse_trend()
            if len(_TREND_HISTORY) >= 3 and (trend.get('regen_trend') != 'unbekannt' or trend.get('wind_trend') != 'unbekannt'):
                log.debug(f'Trend | Regen: {trend["regen_trend"]} (slope={trend["rr_slope"]}) '
                          f'| Wind: {trend["wind_trend"]} (slope={trend["fx_slope"]}) '
                          f'| ETA korr.: {trend["eta_korrigiert"]}min | Bonus: +{trend["konfidenz_bonus"]}')

            # Alarm aggregieren und publizieren
            alarm = build_alarm(zamg or None, inca or None, tawes or None, prev_alarm, trend=trend)
            publish_all(status, tawes or None, zamg or None, inca or None, alarm, prev_alarm)
            prev_alarm = alarm
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
