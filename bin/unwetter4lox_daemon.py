#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Unwetter4Lox Daemon – GeoSphere + INCA -> MQTT"""
import os, sys, json, time, logging, configparser, urllib.request
from datetime import datetime, timezone, timedelta

LBHOMEDIR    = os.environ.get('LBHOMEDIR', '')
LBPPLUGINDIR = os.environ.get('LBPPLUGINDIR', 'unwetter4lox')
CONFIGDIR    = os.path.join(LBHOMEDIR, 'config', 'plugins', LBPPLUGINDIR)
DATADIR      = os.path.join(LBHOMEDIR, 'data',   'plugins', LBPPLUGINDIR)
LOGDIR       = os.path.join(LBHOMEDIR, 'log',    'plugins', LBPPLUGINDIR)

os.makedirs(DATADIR, exist_ok=True)
os.makedirs(LOGDIR,  exist_ok=True)

# LoxBerry-kompatibles Logging
# Format: Zeitstempel <TAG> Nachricht (wie LoxBerry Logviewer erwartet)
LOGFILE = os.path.join(LOGDIR, 'unwetter4lox.log')

class LoxBerryFormatter(logging.Formatter):
    TAG_MAP = {
        'DEBUG':    '<DEBUG>',
        'INFO':     '<OK>',
        'WARNING':  '<WARNING>',
        'ERROR':    '<ERR>',
        'CRITICAL': '<CRIT>',
    }
    def format(self, record):
        tag = self.TAG_MAP.get(record.levelname, '<INFO>')
        ts  = self.formatTime(record, '%Y-%m-%d %H:%M:%S')
        return f'{ts} {tag} {record.getMessage()}'

_fh  = logging.FileHandler(LOGFILE, encoding='utf-8')
_fmt = LoxBerryFormatter()
_fh.setFormatter(_fmt)
# Kein StreamHandler – Shell-Script redirectet stdout nicht mehr zur Log-Datei,
# Python schreibt ausschließlich über FileHandler direkt in die Datei
logging.basicConfig(level=logging.INFO, handlers=[_fh])
log = logging.getLogger('unwetter4lox')

# Konfiguration laden
cfg = configparser.ConfigParser()
cfg.read(os.path.join(CONFIGDIR, 'unwetter4lox.cfg'))

LAT          = float(cfg.get('LOCATION',      'LAT',              fallback='47.952835'))
LON          = float(cfg.get('LOCATION',      'LON',              fallback='13.791286'))
INTERVAL     = int(cfg.get('SCHEDULE',        'INTERVAL',         fallback='300'))
BOEN_ALARM   = float(cfg.get('THRESHOLDS',    'BOEN_ALARM',       fallback='60'))
INCA_ENABLED = cfg.get('INCA',               'ENABLED',           fallback='1') == '1'
INCA_HORIZON = int(cfg.get('INCA',           'HORIZON_MINUTES',   fallback='60'))
MIN_STUFE    = int(cfg.get('NOTIFICATIONS',  'MIN_STUFE',         fallback='1'))
TOPIC_PREFIX = cfg.get('MQTT',              'TOPIC_PREFIX',        fallback='haus/wetter')

# MQTT: LoxBerry auto oder manuell
MQTT_BROKER = '127.0.0.1'; MQTT_PORT = 1883; MQTT_USER = ''; MQTT_PASS = ''
USE_LB_MQTT = cfg.get('MQTT', 'USE_LOXBERRY_MQTT', fallback='1') == '1'

if USE_LB_MQTT:
    for p in [
        os.path.join(LBHOMEDIR, 'config', 'plugins', 'mqttgateway', 'mqtt.json'),
        os.path.join(LBHOMEDIR, 'config', 'plugins', 'mqttgateway', 'mqtt.cfg'),
    ]:
        if os.path.exists(p):
            try:
                if p.endswith('.json'):
                    d = json.load(open(p))
                    MQTT_BROKER = d.get('brokeraddress', d.get('brokerhost', '127.0.0.1'))
                    MQTT_PORT   = int(d.get('brokerport', 1883))
                    MQTT_USER   = d.get('brokeruser', '')
                    MQTT_PASS   = d.get('brokerpass', '')
                else:
                    mc = configparser.ConfigParser(); mc.read(p)
                    s  = mc.sections()[0] if mc.sections() else 'MQTT'
                    MQTT_BROKER = mc.get(s, 'brokeraddress', fallback='127.0.0.1')
                    MQTT_PORT   = int(mc.get(s, 'brokerport', fallback='1883'))
                    MQTT_USER   = mc.get(s, 'brokeruser', fallback='')
                    MQTT_PASS   = mc.get(s, 'brokerpass', fallback='')
                log.info(f'LoxBerry MQTT Gateway erkannt: {MQTT_BROKER}:{MQTT_PORT}')
                break
            except Exception as e:
                log.warning(f'MQTT Config Fehler: {e}')
else:
    MQTT_BROKER = cfg.get('MQTT', 'BROKER', fallback='127.0.0.1')
    MQTT_PORT   = int(cfg.get('MQTT', 'PORT', fallback='1883'))
    MQTT_USER   = cfg.get('MQTT', 'USER', fallback='')
    MQTT_PASS   = cfg.get('MQTT', 'PASS', fallback='')

WARN_TYPES  = {1:'wind',2:'regen',3:'schnee',4:'glatteis',5:'gewitter',6:'hitze',7:'kaelte'}
STUFE_EMOJI = {1:'⚠️', 2:'🟠', 3:'🔴', 4:'🟣'}
STUFE_NAME  = {0:'KEINE', 1:'GELB', 2:'ORANGE', 3:'ROT', 4:'LILA'}
PT_NAME     = {255:'kein Niederschlag', 1:'Regen', 2:'Schnee', 3:'Schneeregen', 4:'Graupel', 5:'Hagel'}

try:
    import paho.mqtt.client as mqtt
    MQTT_OK = True
except ImportError:
    log.warning('paho-mqtt nicht installiert')
    MQTT_OK = False

mqtt_client = None

def mqtt_connect():
    global mqtt_client
    if not MQTT_OK: return False
    try:
        # paho-mqtt >= 2.0 erfordert CallbackAPIVersion, ältere Versionen kennen das nicht
        try:
            mqtt_client = mqtt.Client(
                mqtt.CallbackAPIVersion.VERSION1,
                client_id='unwetter4lox',
                clean_session=True
            )
        except AttributeError:
            mqtt_client = mqtt.Client(client_id='unwetter4lox', clean_session=True)
        if MQTT_USER: mqtt_client.username_pw_set(MQTT_USER, MQTT_PASS)
        mqtt_client.connect(MQTT_BROKER, MQTT_PORT, 60)
        mqtt_client.loop_start()
        log.info(f'MQTT verbunden: {MQTT_BROKER}:{MQTT_PORT}')
        return True
    except Exception as e:
        log.error(f'MQTT Fehler: {e}'); return False

def publish(topic, value, retain=True):
    full = f'{TOPIC_PREFIX}/{topic}'
    payload = str(value) if value is not None else ''
    if mqtt_client:
        try: mqtt_client.publish(full, payload, qos=0, retain=retain)
        except Exception as e: log.error(f'publish {full}: {e}')

def fetch_json(url):
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Unwetter4Lox/0.1'})
        with urllib.request.urlopen(req, timeout=15) as r:
            return json.loads(r.read().decode())
    except Exception as e:
        log.error(f'HTTP {url}: {e}'); return None

def fmt_dt(epoch_s):
    d   = datetime.fromtimestamp(epoch_s, tz=timezone.utc).astimezone()
    now = datetime.now().astimezone()
    t   = d.strftime('%H:%M')
    days = ['So','Mo','Di','Mi','Do','Fr','Sa']
    if d.date() == now.date():                         return f'heute {t}'
    if d.date() == (now + timedelta(days=1)).date():   return f'morgen {t}'
    return f'{days[d.weekday()]} {d.strftime("%d.%m.")} {t}'

def save_state(state):
    with open(os.path.join(DATADIR, 'state.json'), 'w') as f:
        json.dump(state, f, ensure_ascii=False, indent=2)

def load_state():
    p = os.path.join(DATADIR, 'state.json')
    return json.load(open(p)) if os.path.exists(p) else {}

def fetch_zamg():
    log.info('GeoSphere Warn-API...')
    data = fetch_json(f'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lon={LON}&lat={LAT}&lang=de')
    if not data: return None, 0, []
    warnings = data.get('properties', {}).get('warnings', [])
    now = datetime.now(tz=timezone.utc)
    in30 = now + timedelta(minutes=30)
    in24h = now + timedelta(hours=24)
    result = {}
    for t in list(WARN_TYPES.values()) + ['hagel']:
        result[t] = dict(stufe=0, aktiv=0, bald=0, start_epoch=0, end_epoch=0,
                         start_text='', end_text='', notification='', text='')
    akut = 0; ids = []
    for w in warnings:
        p = w.get('properties', {})
        typ   = p.get('warntypid')
        stufe = p.get('warnstufeid', 1)
        raw   = p.get('rawinfo', {})
        s_s   = int(raw.get('start', 0))
        e_s   = int(raw.get('end', 0))
        wid   = str(p.get('warnid', ''))
        text  = p.get('text', '')
        if wid.startswith('gwa'): akut = 1
        if wid: ids.append(wid)
        s_dt = datetime.fromtimestamp(s_s, tz=timezone.utc)
        e_dt = datetime.fromtimestamp(e_s, tz=timezone.utc)
        if s_dt > in24h or e_dt < now: continue
        key = WARN_TYPES.get(typ)
        if not key or stufe <= result[key]['stufe']: continue
        aktiv = int(now >= s_dt and now <= e_dt)
        bald  = int(in30 >= s_dt and now <= e_dt)
        tl = text.lower()
        if   'sturm' in tl or 'windwarnung' in tl: desc = 'Sturmböen erwartet'
        elif 'gewitter' in tl:                      desc = 'Gewitter möglich'
        elif 'hagel'    in tl:                      desc = 'Hagelschlag möglich'
        elif 'regen'    in tl:                      desc = 'Starkregen erwartet'
        elif 'schnee'   in tl:                      desc = 'Schneefall erwartet'
        elif 'glatteis' in tl:                      desc = 'Glatteis-Gefahr'
        else:                                       desc = text[:50]
        em = STUFE_EMOJI.get(stufe, '⚠️')
        sl = STUFE_NAME.get(stufe, 'GELB')
        st = fmt_dt(s_s); et = fmt_dt(e_s)
        notif = f'{em} {sl} – {key.capitalize()} | {st} – {et} | {desc}'
        result[key] = dict(stufe=stufe, aktiv=aktiv, bald=bald,
                           start_epoch=s_s, end_epoch=e_s,
                           start_text=st, end_text=et, notification=notif, text=text)
        if 'hagel' in tl and stufe > result['hagel']['stufe']:
            result['hagel'] = dict(stufe=stufe, aktiv=aktiv, bald=bald,
                                   start_epoch=s_s, end_epoch=e_s, start_text=st, end_text=et,
                                   notification=f'{em} {sl} – Hagel | {st} – {et} | Hagelschlag möglich',
                                   text=text)
    log.info(f'GeoSphere: {len(warnings)} Warnungen, akut={akut}')
    return result, akut, ids

def fetch_inca():
    if not INCA_ENABLED: return {}
    log.info('INCA Nowcast...')
    now      = datetime.now(tz=timezone.utc)
    in30_ts  = (now + timedelta(minutes=30)).timestamp()
    in60_ts  = (now + timedelta(minutes=INCA_HORIZON)).timestamp()
    res = dict(ff_jetzt=0.0, fx_jetzt=0.0, fx_max_30min=0.0, fx_max_60min=0.0,
               rr_jetzt=0.0, pt_jetzt=255, pt_name='kein Niederschlag',
               bald_regen=0, bald_hagel=0, bald_graupel=0,
               bald_sturm_30=0, bald_sturm_60=0, minuten_bis_regen=-1)
    def ts2e(ts):
        try: return datetime.fromisoformat(ts).timestamp()
        except: return 0
    for param in ['ff', 'fx', 'rr', 'pt']:
        base = 'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km'
        url  = f'{base}?lat_lon={LAT}%2C{LON}&parameters={param}&output_format=geojson'
        data = fetch_json(url)
        if not data: continue
        ts_list  = data.get('timestamps', [])
        features = data.get('features', [])
        if not features: continue
        values = features[0].get('properties', {}).get('parameters', {}).get(param, {}).get('data', [])
        if param == 'ff':
            res['ff_jetzt'] = round(float(values[0]) * 3.6, 1) if values else 0.0
        elif param == 'fx':
            serie = [round(float(v) * 3.6, 1) for v in values]
            res['fx_jetzt'] = serie[0] if serie else 0.0
            m30 = m60 = 0.0
            for i, ts in enumerate(ts_list):
                if i >= len(serie): break
                v = serie[i]; t = ts2e(ts)
                if t <= in30_ts and v > m30: m30 = v
                if t <= in60_ts and v > m60: m60 = v
            res['fx_max_30min'] = round(m30, 1); res['fx_max_60min'] = round(m60, 1)
            res['bald_sturm_30'] = int(m30 >= BOEN_ALARM)
            res['bald_sturm_60'] = int(m60 >= BOEN_ALARM)
        elif param == 'rr':
            serie = [round(float(v), 2) if v else 0.0 for v in values]
            res['rr_jetzt'] = serie[0] if serie else 0.0
            for i, ts in enumerate(ts_list):
                if i >= len(serie): break
                if serie[i] > 0.0:
                    t = ts2e(ts)
                    res['minuten_bis_regen'] = max(0, int((t - now.timestamp()) / 60))
                    res['bald_regen'] = int(t <= in30_ts)
                    break
        elif param == 'pt':
            serie = [int(v) if v else 255 for v in values]
            res['pt_jetzt'] = serie[0] if serie else 255
            res['pt_name']  = PT_NAME.get(res['pt_jetzt'], 'unbekannt')
            for i, ts in enumerate(ts_list):
                if i >= len(serie): break
                if ts2e(ts) > in60_ts: break
                pt = serie[i]
                if pt == 5: res['bald_hagel']  = 1
                if pt == 4: res['bald_graupel'] = 1
    log.info(f'INCA: fx={res["fx_jetzt"]}km/h max30={res["fx_max_30min"]} pt={res["pt_name"]}')
    return res

def publish_all(zamg, akut, inca, prev_ids, new_ids):
    all_types = list(WARN_TYPES.values()) + ['hagel']
    for t in all_types:
        w = zamg.get(t, {})
        publish(f'warnung/{t}/stufe',        w.get('stufe', 0))
        publish(f'warnung/{t}/aktiv',        w.get('aktiv', 0))
        publish(f'warnung/{t}/bald',         w.get('bald', 0))
        publish(f'warnung/{t}/start_epoch',  w.get('start_epoch', 0))
        publish(f'warnung/{t}/end_epoch',    w.get('end_epoch', 0))
        publish(f'warnung/{t}/start_text',   w.get('start_text', ''))
        publish(f'warnung/{t}/end_text',     w.get('end_text', ''))
        publish(f'warnung/{t}/notification', w.get('notification', ''))
    publish('warnung/akutwarnung', akut)
    max_stufe = max((zamg.get(t, {}).get('stufe', 0) for t in all_types), default=0)
    irgendwas = int(any(zamg.get(t, {}).get('aktiv', 0) or zamg.get(t, {}).get('bald', 0)
                        for t in all_types) or akut)
    publish('warnung/max_stufe',       max_stufe)
    publish('warnung/irgendwas_aktiv', irgendwas)
    publish('inca/boen_jetzt_kmh',        inca.get('fx_jetzt', 0))
    publish('inca/wind_jetzt_kmh',        inca.get('ff_jetzt', 0))
    publish('inca/boen_max_30min',        inca.get('fx_max_30min', 0))
    publish('inca/boen_max_60min',        inca.get('fx_max_60min', 0))
    publish('inca/niederschlag_jetzt',    inca.get('rr_jetzt', 0))
    publish('inca/niederschlag_typ',      inca.get('pt_jetzt', 255))
    publish('inca/niederschlag_typ_name', inca.get('pt_name', 'kein Niederschlag'))
    publish('inca/bald_regen',            inca.get('bald_regen', 0))
    publish('inca/bald_hagel',            inca.get('bald_hagel', 0))
    publish('inca/bald_graupel',          inca.get('bald_graupel', 0))
    publish('inca/bald_sturm_30min',      inca.get('bald_sturm_30', 0))
    publish('inca/bald_sturm_60min',      inca.get('bald_sturm_60', 0))
    publish('inca/minuten_bis_regen',     inca.get('minuten_bis_regen', -1))
    # Notifications
    zamg_lines = []
    for t in all_types:
        w = zamg.get(t, {})
        if w.get('stufe', 0) >= MIN_STUFE and w.get('notification'):
            if w.get('aktiv') or w.get('bald'): zamg_lines.insert(0, w['notification'])
            else: zamg_lines.append(w['notification'])
    if akut: zamg_lines.insert(0, '🚨 Automatische Akutwarnung aktiv')
    inca_lines = []
    if inca.get('bald_hagel'):      inca_lines.append('🔴 Hagel in <60 min erwartet')
    elif inca.get('bald_graupel'):  inca_lines.append('⚠️ Graupel in <60 min möglich')
    if inca.get('bald_sturm_30'):   inca_lines.append(f'🟠 Sturmböen <30 min: max {inca["fx_max_30min"]} km/h')
    elif inca.get('bald_sturm_60'): inca_lines.append(f'⚠️ Sturmböen <60 min: max {inca["fx_max_60min"]} km/h')
    m = inca.get('minuten_bis_regen', -1)
    if inca.get('bald_regen'):      inca_lines.append(f'🌧️ Regen in ~{m} min')
    elif inca.get('rr_jetzt', 0) > 0: inca_lines.append(f'🌧️ Regen: {inca["rr_jetzt"]} mm/h')
    if not inca_lines:              inca_lines.append(f'✅ kein Alarm | Böen: {inca.get("fx_jetzt", 0)} km/h')
    notif_geo  = '\n'.join(zamg_lines) if zamg_lines else 'keine aktiven Warnungen'
    notif_inca = '\n'.join(inca_lines)
    notif_alle = '\n'.join(zamg_lines + ['──'] + inca_lines) if zamg_lines else notif_inca
    publish('notification/geosphere',     notif_geo)
    publish('notification/inca',          notif_inca)
    publish('notification/alle',          notif_alle)
    publish('notification/neu_geosphere', int(sorted(new_ids) != sorted(prev_ids)), retain=False)
    prev = load_state()
    hatte = prev.get('hatte_aktiv', False)
    hat   = bool(irgendwas)
    if hatte and not hat:
        publish('notification/entwarnung', '✅ Entwarnung – alle Wetterwarnungen aufgehoben.', retain=False)
    ts = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    publish('letztes_update', ts)
    save_state({
        'last_warn_ids': new_ids, 'hatte_aktiv': hat, 'letztes_update': ts,
        'zamg': zamg, 'inca': inca, 'akutwarnung': akut,
        'max_stufe': int(max_stufe), 'irgendwas_aktiv': irgendwas,
        'notification_geosphere': notif_geo,
        'notification_inca': notif_inca,
        'notification_alle': notif_alle,
    })

def run():
    # LoxBerry LOGSTART
    with open(LOGFILE, 'a', encoding='utf-8') as f:
        import datetime as _dt
        f.write('=' * 80 + '\n')
        f.write(f'{_dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")} <LOGSTART> Unwetter4Lox Daemon gestartet\n')
    log.info(f'Unwetter4Lox gestartet | {LAT},{LON} | {MQTT_BROKER}:{MQTT_PORT} | {INTERVAL}s')
    mqtt_connect()
    while True:
        try:
            prev     = load_state()
            prev_ids = prev.get('last_warn_ids', [])
            zamg, akut, new_ids = fetch_zamg()
            inca = fetch_inca() if INCA_ENABLED else {}
            if zamg is not None:
                publish_all(zamg, akut, inca, prev_ids, new_ids)
            else:
                log.warning('Keine Daten von GeoSphere')
        except Exception as e:
            log.error(f'Hauptloop Fehler: {e}', exc_info=True)
        time.sleep(INTERVAL)

if __name__ == '__main__':
    run()
