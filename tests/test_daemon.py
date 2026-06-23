"""
Unit-Tests für Unwetter4Lox Daemon.
Ausführen: python -m pytest tests/test_daemon.py -v
"""
import sys
import os
import math
import time
import json
import tempfile
import shutil
import unittest
from unittest.mock import MagicMock, patch
from collections import deque

# ---------------------------------------------------------------------------
# Vor dem Import: Fake-Umgebung aufbauen
# ---------------------------------------------------------------------------
TMPDIR = tempfile.mkdtemp(prefix='u4l_test_')

FAKE_CFG = """[LOCATION]
LAT=48.306900
LON=14.285800
NAME=Testort

[MQTT]
USE_LOXBERRY_MQTT=0
BROKER=127.0.0.1
PORT=1883
USER=
PASS=
TOPIC_PREFIX=unwetter

[ZAMG]
ENABLED=1

[INCA]
ENABLED=1
HORIZON_MINUTES=60

[SCHEDULE]
INTERVAL=300
ZAMG_INTERVAL=300
INCA_INTERVAL=300
TAWES_INTERVAL=480

[THRESHOLDS]
BOEN_ALARM=60
REGEN_ALARM=10.0

[NOTIFICATIONS]
MIN_STUFE=1

[TAWES]
ENABLED=1
MAX_DISTANCE_KM=120
MAX_STATIONS=25
MIN_ALARM_PROZENT=30
MAX_UPSTREAM_HOEHE_M=1200
REGEN_LOKAL_KM=25
UPSTREAM_WINKEL_GRAD=45
"""

def _setup_env():
    """Temp-Verzeichnisse und Fake-Config anlegen."""
    cfg_dir  = os.path.join(TMPDIR, 'config', 'plugins', 'unwetter4lox')
    data_dir = os.path.join(TMPDIR, 'data',   'plugins', 'unwetter4lox')
    log_dir  = os.path.join(TMPDIR, 'log',    'plugins', 'unwetter4lox')
    for d in (cfg_dir, data_dir, log_dir):
        os.makedirs(d, exist_ok=True)
    with open(os.path.join(cfg_dir, 'unwetter4lox.cfg'), 'w') as f:
        f.write(FAKE_CFG)
    os.environ['LBHOMEDIR']    = TMPDIR
    os.environ['LBPPLUGINDIR'] = 'unwetter4lox'

def _mock_external_modules():
    """LoxBerry SDK und paho-mqtt durch Mocks ersetzen."""
    mock_lb = MagicMock()
    mock_log_inst = MagicMock()
    mock_log_inst.filename = os.path.join(TMPDIR, 'log', 'plugins', 'unwetter4lox', 'daemon.log')
    mock_log_inst.start.return_value = None
    mock_lb.log.Logger.return_value = mock_log_inst

    for name in ('loxberry', 'loxberry.log', 'loxberry.mqtt', 'loxberry.system'):
        sys.modules[name] = mock_lb

    mock_paho = MagicMock()
    mock_mqtt_client = MagicMock()
    mock_mqtt_client.MQTT_ERR_SUCCESS = 0
    mock_paho.client = mock_mqtt_client
    sys.modules['paho']              = MagicMock()
    sys.modules['paho.mqtt']         = MagicMock()
    sys.modules['paho.mqtt.client']  = mock_mqtt_client

_setup_env()
_mock_external_modules()

# Jetzt erst den Daemon importieren
_BIN_DIR = os.path.join(os.path.dirname(__file__), '..', 'bin')
sys.path.insert(0, os.path.abspath(_BIN_DIR))
import unwetter4lox_daemon as D


def teardown_module(module):
    shutil.rmtree(TMPDIR, ignore_errors=True)


# ===========================================================================
# TEST: _parse_iso
# ===========================================================================
class TestParseIso(unittest.TestCase):

    def test_leerer_string_gibt_null(self):
        """Leerer String muss 0 zurückgeben, NICHT time.time()!
        Wichtig: der Caller berechnet e_ep = s_ep + 86400 wenn e_ep == 0."""
        self.assertEqual(D._parse_iso(''), 0)

    def test_none_gibt_null(self):
        self.assertEqual(D._parse_iso(None), 0)

    def test_iso_mit_z(self):
        from datetime import datetime, timezone as tz
        expected = int(datetime(2026, 6, 23, 10, 0, 0, tzinfo=tz.utc).timestamp())
        result = D._parse_iso('2026-06-23T10:00:00Z')
        self.assertAlmostEqual(result, expected, delta=5)

    def test_iso_ohne_sekunden(self):
        from datetime import datetime, timezone as tz
        expected = int(datetime(2026, 6, 23, 10, 0, 0, tzinfo=tz.utc).timestamp())
        result = D._parse_iso('2026-06-23T10:00Z')
        self.assertAlmostEqual(result, expected, delta=5)

    def test_iso_nur_datum(self):
        result = D._parse_iso('2026-06-23T00:00:00Z')
        self.assertGreater(result, 0)

    def test_ungueltig_gibt_null(self):
        self.assertEqual(D._parse_iso('kein-datum'), 0)
        self.assertEqual(D._parse_iso('abc'), 0)

    def test_gibt_int_zurueck(self):
        result = D._parse_iso('2026-06-23T10:00:00Z')
        self.assertIsInstance(result, int)

    def test_leeres_ergebnis_nie_groesser_1970(self):
        """Fehlende Zeitangaben dürfen kein positives Epoch liefern."""
        self.assertEqual(D._parse_iso(''), 0)
        self.assertEqual(D._parse_iso(None), 0)


# ===========================================================================
# TEST: _haversine
# ===========================================================================
class TestHaversine(unittest.TestCase):

    def test_gleicher_punkt_null(self):
        self.assertAlmostEqual(D._haversine(48.3069, 14.2858, 48.3069, 14.2858), 0.0, places=3)

    def test_linz_wels(self):
        """Linz → Wels ≈ 27 km"""
        km = D._haversine(48.3069, 14.2858, 48.1667, 14.0244)
        self.assertAlmostEqual(km, 27, delta=5)

    def test_linz_salzburg(self):
        """Linz → Salzburg ≈ 116 km"""
        km = D._haversine(48.3069, 14.2858, 47.8095, 13.0550)
        self.assertAlmostEqual(km, 116, delta=10)

    def test_symmetrisch(self):
        a = D._haversine(48.0, 14.0, 47.0, 13.0)
        b = D._haversine(47.0, 13.0, 48.0, 14.0)
        self.assertAlmostEqual(a, b, places=5)

    def test_immer_positiv(self):
        self.assertGreater(D._haversine(47.0, 13.0, 48.0, 14.0), 0)


# ===========================================================================
# TEST: _bearing
# ===========================================================================
class TestBearing(unittest.TestCase):

    def test_nord(self):
        b = D._bearing(48.0, 14.0, 49.0, 14.0)
        self.assertAlmostEqual(b % 360, 0.0, delta=2)

    def test_sued(self):
        b = D._bearing(48.0, 14.0, 47.0, 14.0)
        self.assertAlmostEqual(b, 180.0, delta=2)

    def test_ost(self):
        b = D._bearing(48.0, 14.0, 48.0, 15.0)
        self.assertAlmostEqual(b, 90.0, delta=5)

    def test_west(self):
        b = D._bearing(48.0, 14.0, 48.0, 13.0)
        self.assertAlmostEqual(b, 270.0, delta=5)

    def test_bereich_0_360(self):
        for lat2, lon2 in [(49.0, 14.0), (47.0, 14.0), (48.0, 15.0), (48.0, 13.0)]:
            b = D._bearing(48.0, 14.0, lat2, lon2)
            self.assertGreaterEqual(b, 0.0)
            self.assertLess(b, 360.0)


# ===========================================================================
# TEST: _mm_al / _fx_al (Alarm-Level)
# ===========================================================================
class TestAlarmLevel(unittest.TestCase):

    def setUp(self):
        D.REGEN_ALARM = 10.0
        D.BOEN_ALARM  = 60.0

    def tearDown(self):
        D.REGEN_ALARM = 10.0
        D.BOEN_ALARM  = 60.0

    # --- mm ---
    def test_mm_unter_schwelle_0(self):
        self.assertEqual(D._mm_al(0.0),  0)
        self.assertEqual(D._mm_al(9.99), 0)

    def test_mm_genau_schwelle_1(self):
        self.assertEqual(D._mm_al(10.0), 1)

    def test_mm_stufe_1(self):
        self.assertEqual(D._mm_al(15.0), 1)
        self.assertEqual(D._mm_al(19.99), 1)

    def test_mm_stufe_2(self):
        self.assertEqual(D._mm_al(20.0), 2)
        self.assertEqual(D._mm_al(25.0), 2)
        self.assertEqual(D._mm_al(29.99), 2)

    def test_mm_stufe_3(self):
        self.assertEqual(D._mm_al(30.0), 3)
        self.assertEqual(D._mm_al(100.0), 3)

    def test_mm_custom_schwelle(self):
        D.REGEN_ALARM = 5.0
        self.assertEqual(D._mm_al(4.99), 0)
        self.assertEqual(D._mm_al(5.0),  1)
        self.assertEqual(D._mm_al(10.0), 2)
        self.assertEqual(D._mm_al(15.0), 3)

    # --- fx ---
    def test_fx_unter_schwelle_0(self):
        self.assertEqual(D._fx_al(0.0),  0)
        self.assertEqual(D._fx_al(59.9), 0)

    def test_fx_stufe_1(self):
        self.assertEqual(D._fx_al(60.0), 1)
        self.assertEqual(D._fx_al(90.0), 1)

    def test_fx_stufe_2(self):
        self.assertEqual(D._fx_al(120.0), 2)
        self.assertEqual(D._fx_al(150.0), 2)

    def test_fx_stufe_3(self):
        self.assertEqual(D._fx_al(180.0), 3)
        self.assertEqual(D._fx_al(200.0), 3)


# ===========================================================================
# TEST: _linreg_slope
# ===========================================================================
class TestLinregSlope(unittest.TestCase):

    def test_zu_kurz_null(self):
        self.assertEqual(D._linreg_slope([]),              0.0)
        self.assertEqual(D._linreg_slope([1.0]),           0.0)
        self.assertEqual(D._linreg_slope([1.0, 2.0]),      0.0)
        self.assertEqual(D._linreg_slope([1.0, 2.0, 3.0]), 0.0)

    def test_konstante_serie_null(self):
        self.assertAlmostEqual(D._linreg_slope([5.0]*6), 0.0, places=5)

    def test_steigend_positiv(self):
        slope = D._linreg_slope([1.0, 2.0, 3.0, 4.0, 5.0])
        self.assertAlmostEqual(slope, 1.0, places=5)

    def test_fallend_negativ(self):
        slope = D._linreg_slope([5.0, 4.0, 3.0, 2.0, 1.0])
        self.assertAlmostEqual(slope, -1.0, places=5)

    def test_vier_werte_reichen(self):
        slope = D._linreg_slope([1.0, 2.0, 3.0, 4.0])
        self.assertAlmostEqual(slope, 1.0, places=3)

    def test_kein_divisionbyzerro(self):
        # Alle x-Werte identisch → denom=0 → slope=0
        slope = D._linreg_slope([3.0, 3.0, 3.0, 3.0])
        self.assertEqual(slope, 0.0)


# ===========================================================================
# TEST: _canon_sid
# ===========================================================================
class TestCanonSid(unittest.TestCase):

    def test_reiner_int_string(self):
        self.assertEqual(D._canon_sid('11035'), '11035')

    def test_int(self):
        self.assertEqual(D._canon_sid(11035), '11035')

    def test_float_string(self):
        self.assertEqual(D._canon_sid('11035.0'), '11035')

    def test_mit_prefix_punkt(self):
        """'ST.11035-01' → längste Zifferngruppe = '11035'"""
        self.assertEqual(D._canon_sid('ST.11035-01'), '11035')

    def test_mit_prefix_strich(self):
        self.assertEqual(D._canon_sid('ST-11060'), '11060')

    def test_kurze_nummer(self):
        self.assertEqual(D._canon_sid('123'), '123')


# ===========================================================================
# TEST: ZAMG-Parser – fetch_zamg() mit gemockten API-Antworten
# ===========================================================================
class TestFetchZamg(unittest.TestCase):

    NOW = int(time.time())

    def _warn(self, warntypid, warnstufeid, start_offset=-600, end_offset=3600, akut=0):
        """Erzeugt eine ZAMG-Warnung im echten API-Format."""
        s = self.NOW + start_offset
        e = self.NOW + end_offset
        return {
            "type": "Warning",
            "properties": {
                "warnid": 1,
                "warntypid": warntypid,
                "warnstufeid": warnstufeid,
                "begin": "23.06.2026 10:00",
                "end": "23.06.2026 22:00",
                "text": f"Testwarnung typ={warntypid}",
                "auswirkungen": "",
                "empfehlungen": "",
                "rawinfo": {
                    "wtype": warntypid,
                    "wlevel": warnstufeid,
                    "start": str(s),
                    "end": str(e),
                    "gwa": akut,
                }
            }
        }

    def _response(self, warnings):
        """Erzeugt eine ZAMG Feature-Antwort."""
        return {
            "type": "Feature",
            "geometry": {"type": "MultiPolygon", "coordinates": []},
            "properties": {
                "location": {"type": "Municipal", "properties": {}},
                "warnings": warnings
            }
        }

    def test_keine_warnungen_alles_null(self):
        with patch.object(D, 'fetch_json', return_value=self._response([])):
            r = D.fetch_zamg()
        self.assertIsNotNone(r)
        self.assertTrue(r['_api_ok'])
        self.assertEqual(r['max_stufe'], 0)
        self.assertEqual(r['irgendwas_aktiv'], 0)

    def test_api_none_gibt_none(self):
        with patch.object(D, 'fetch_json', return_value=None):
            self.assertIsNone(D.fetch_zamg())

    def test_gewitter_stufe_1_aktiv(self):
        with patch.object(D, 'fetch_json', return_value=self._response([
            self._warn(warntypid=5, warnstufeid=1, start_offset=-600, end_offset=3600)
        ])):
            r = D.fetch_zamg()
        self.assertEqual(r['gewitter']['stufe'], 1)
        self.assertEqual(r['gewitter']['aktiv'], 1)
        self.assertEqual(r['gewitter']['bald'],  0)
        self.assertEqual(r['max_stufe'], 1)
        self.assertEqual(r['irgendwas_aktiv'], 1)

    def test_hitze_stufe_2_aktiv(self):
        # Hitze (Typ 6) ist standardmäßig deaktiviert – für diesen Test aktivieren
        with patch.object(D, 'ZAMG_AKTIVE_TYPEN', {1,2,3,4,5,6,7,8}):
            with patch.object(D, 'fetch_json', return_value=self._response([
                self._warn(warntypid=6, warnstufeid=2, start_offset=-3600, end_offset=7200)
            ])):
                r = D.fetch_zamg()
        self.assertEqual(r['hitze']['stufe'], 2)
        self.assertEqual(r['hitze']['aktiv'], 1)
        self.assertEqual(r['max_stufe'], 2)

    def test_wind_stufe_3(self):
        with patch.object(D, 'fetch_json', return_value=self._response([
            self._warn(warntypid=1, warnstufeid=3, start_offset=-100, end_offset=3600)
        ])):
            r = D.fetch_zamg()
        self.assertEqual(r['wind']['stufe'], 3)
        self.assertEqual(r['max_stufe'], 3)

    def test_alle_8_warntypen_korrekt_gemappt(self):
        typen = {1:'wind', 2:'regen', 3:'schnee', 4:'glatteis',
                 5:'gewitter', 6:'hitze', 7:'kaelte', 8:'hagel'}
        # Alle 8 Typen für diesen Test aktivieren (inkl. Hitze/Kälte die standardmäßig deaktiviert sind)
        with patch.object(D, 'ZAMG_AKTIVE_TYPEN', {1,2,3,4,5,6,7,8}):
            for wtype, key in typen.items():
                with patch.object(D, 'fetch_json', return_value=self._response([
                    self._warn(warntypid=wtype, warnstufeid=1, start_offset=-60, end_offset=3600)
                ])):
                    r = D.fetch_zamg()
                self.assertIsNotNone(r, f"wtype={wtype} lieferte None")
                self.assertEqual(r[key]['stufe'], 1, f"wtype={wtype} ({key}): stufe erwartet 1")
                self.assertEqual(r[key]['aktiv'], 1, f"wtype={wtype} ({key}): aktiv erwartet 1")

    def test_abgelaufene_warnung_ignoriert(self):
        with patch.object(D, 'fetch_json', return_value=self._response([
            self._warn(warntypid=5, warnstufeid=2, start_offset=-7200, end_offset=-3600)
        ])):
            r = D.fetch_zamg()
        self.assertEqual(r['gewitter']['aktiv'], 0)
        self.assertEqual(r['max_stufe'], 0)

    def test_warnung_bald_in_20min(self):
        with patch.object(D, 'fetch_json', return_value=self._response([
            self._warn(warntypid=5, warnstufeid=1, start_offset=1200, end_offset=7200)
        ])):
            r = D.fetch_zamg()
        self.assertEqual(r['gewitter']['bald'],  1)
        self.assertEqual(r['gewitter']['aktiv'], 0)

    def test_warnung_in_6h_tageswarnung(self):
        with patch.object(D, 'fetch_json', return_value=self._response([
            self._warn(warntypid=5, warnstufeid=1, start_offset=3*3600, end_offset=6*3600)
        ])):
            r = D.fetch_zamg()
        self.assertEqual(r['gewitter']['tageswarnung'], 1)
        self.assertEqual(r['gewitter']['aktiv'], 0)
        self.assertEqual(r['gewitter']['bald'],  0)

    def test_mehrere_warnungen_max_stufe(self):
        # Hitze (Typ 6) für diesen Test aktivieren
        with patch.object(D, 'ZAMG_AKTIVE_TYPEN', {1,2,3,4,5,6,7,8}):
            with patch.object(D, 'fetch_json', return_value=self._response([
                self._warn(warntypid=5, warnstufeid=1, start_offset=-100, end_offset=3600),
                self._warn(warntypid=6, warnstufeid=2, start_offset=-3600, end_offset=7200),
            ])):
                r = D.fetch_zamg()
        self.assertEqual(r['max_stufe'], 2)
        self.assertEqual(r['gewitter']['stufe'], 1)
        self.assertEqual(r['hitze']['stufe'], 2)

    def test_akutwarnung_flag(self):
        with patch.object(D, 'fetch_json', return_value=self._response([
            self._warn(warntypid=5, warnstufeid=3, start_offset=-100, end_offset=3600, akut=1)
        ])):
            r = D.fetch_zamg()
        self.assertEqual(r['akutwarnung'], 1)

    def test_realistische_antwort_heute(self):
        """Reproduziert die echte API-Antwort von heute (6 Warnungen). Hitze aktiviert für Test."""
        now = int(time.time())
        mock = {
            "type": "Feature", "geometry": {},
            "properties": {"location": {}, "warnings": [
                # Hitze heute aktiv
                {"type":"Warning","properties":{"warntypid":6,"warnstufeid":2,"text":"Hitze",
                 "rawinfo":{"wtype":6,"wlevel":2,"start":str(now-3600),"end":str(now+7200)}}},
                # Gewitter heute aktiv
                {"type":"Warning","properties":{"warntypid":5,"warnstufeid":1,"text":"Gewitter",
                 "rawinfo":{"wtype":5,"wlevel":1,"start":str(now-1800),"end":str(now+5400)}}},
                # Hitze morgen (außerhalb 8h Horizont → ignoriert)
                {"type":"Warning","properties":{"warntypid":6,"warnstufeid":2,"text":"Hitze",
                 "rawinfo":{"wtype":6,"wlevel":2,"start":str(now+86400),"end":str(now+172800)}}},
            ]}
        }
        # Hitze für diesen Test aktivieren (standardmäßig deaktiviert)
        with patch.object(D, 'ZAMG_AKTIVE_TYPEN', {1,2,3,4,5,6,7,8}):
            with patch.object(D, 'fetch_json', return_value=mock):
                r = D.fetch_zamg()
        self.assertIsNotNone(r)
        self.assertEqual(r['hitze']['stufe'],    2)
        self.assertEqual(r['hitze']['aktiv'],    1)
        self.assertEqual(r['gewitter']['stufe'], 1)
        self.assertEqual(r['gewitter']['aktiv'], 1)
        self.assertEqual(r['max_stufe'],         2)
        self.assertEqual(r['irgendwas_aktiv'],   1)

    def test_hitze_standard_ignoriert(self):
        """Hitze und Kälte sind standardmäßig deaktiviert (AKTIVE_TYPEN=1,2,3,4,5,8)."""
        now = int(time.time())
        # ZAMG_AKTIVE_TYPEN standardmäßig ohne Hitze (6) und Kälte (7)
        with patch.object(D, 'ZAMG_AKTIVE_TYPEN', {1, 2, 3, 4, 5, 8}):
            with patch.object(D, 'fetch_json', return_value=self._response([
                self._warn(warntypid=6, warnstufeid=2, start_offset=-3600, end_offset=7200),
                self._warn(warntypid=7, warnstufeid=1, start_offset=-3600, end_offset=7200),
            ])):
                r = D.fetch_zamg()
        self.assertEqual(r['hitze']['stufe'],  0, 'Hitze muss ignoriert werden')
        self.assertEqual(r['kaelte']['stufe'], 0, 'Kälte muss ignoriert werden')
        self.assertEqual(r['max_stufe'],       0)
        self.assertEqual(r['irgendwas_aktiv'], 0)


# ===========================================================================
# TEST: build_alarm() – Alarm-Korrelationslogik
# ===========================================================================
class TestBuildAlarm(unittest.TestCase):

    def setUp(self):
        D.REGEN_ALARM = 10.0
        D.BOEN_ALARM  = 60.0

    def tearDown(self):
        D.REGEN_ALARM = 10.0
        D.BOEN_ALARM  = 60.0

    def _zamg(self, **kwargs):
        base = {wt: {'stufe':0,'aktiv':0,'bald':0,'tageswarnung':0,
                     'start_epoch':0,'end_epoch':0,'notification':''}
                for wt in ['wind','regen','schnee','glatteis','gewitter','hitze','kaelte','hagel']}
        for key, val in kwargs.items():
            typ, field = key.split('_', 1)
            base[typ][field] = val
        # max_stufe wie echtes fetch_zamg() setzen
        base['max_stufe'] = max(v['stufe'] for v in base.values() if isinstance(v, dict))
        base['irgendwas_aktiv'] = int(any(v.get('aktiv', 0) for v in base.values() if isinstance(v, dict)))
        return base

    def _inca(self, rr=0.0, fx60=0.0, rr30=0.0, eta=-1, regen_alarm=None):
        ra = regen_alarm if regen_alarm is not None else int(rr >= D.REGEN_ALARM)
        return {'ff_jetzt':0.0,'fx_jetzt':fx60,'fx_max_30min':fx60,'fx_max_60min':fx60,
                'rr_jetzt':rr,'rr_max_30min':rr30,'rr_max_60min':rr30,
                'pt_jetzt':255,'minuten_bis_regen':eta,
                'bald_regen':0,'bald_hagel':0,'regen_alarm':ra,'_api_ok':True}

    def _tawes(self, regen_up=0, rr_up=0.0, sturm_up=0, fx_up=0.0, eta_physik=-1):
        return {'sturm_upstream':sturm_up,'regen_upstream':regen_up,
                'regen_upstream_mm':rr_up,'regen_lokal':0,'regen_lokal_mm':0.0,
                'wind_upstream_kmh':fx_up,'eta_physik_min':eta_physik,
                'upstream_aktiv':1 if (regen_up or sturm_up) else 0,'_api_ok':True}

    def test_alles_leer_gesamt_0(self):
        r = D.build_alarm(self._zamg(), self._inca(), self._tawes(), {})
        self.assertEqual(r['gesamt'], 0)
        self.assertEqual(r['wind'],   0)
        self.assertEqual(r['regen'],  0)

    def test_zamg_wind_stufe_3_direkt(self):
        """ZAMG-Warnung → volle Stufe ohne Korroboration."""
        r = D.build_alarm(self._zamg(wind_stufe=3, wind_aktiv=1),
                          self._inca(), self._tawes(), {})
        self.assertEqual(r['wind'],   3)
        self.assertGreaterEqual(r['gesamt'], 1)

    def test_zamg_gewitter_stufe_1(self):
        r = D.build_alarm(self._zamg(gewitter_stufe=1, gewitter_aktiv=1),
                          self._inca(), self._tawes(), {})
        self.assertEqual(r['gewitter'], 1)
        self.assertGreaterEqual(r['gesamt'], 1)

    def test_inca_allein_max_stufe_1(self):
        """INCA allein: egal wie hoch rr/fx → nie über Stufe 1."""
        r = D.build_alarm(self._zamg(),
                          self._inca(rr=50.0, fx60=200.0, rr30=50.0, regen_alarm=1),
                          self._tawes(), {})
        self.assertLessEqual(r['regen'], 1, "INCA allein: max Stufe 1!")
        self.assertLessEqual(r['wind'],  1, "INCA allein: max Stufe 1!")

    def test_inca_plus_tawes_stufe_2(self):
        """INCA (2.5× Schwelle) + TAWES bestätigt → Stufe ≥ 2."""
        r = D.build_alarm(self._zamg(),
                          self._inca(rr=25.0, rr30=25.0, regen_alarm=1),
                          self._tawes(regen_up=1, rr_up=25.0), {})
        self.assertGreaterEqual(r['regen'], 2)

    def test_inca_plus_tawes_stufe_3(self):
        """INCA (3.5× Schwelle) + TAWES bestätigt → Stufe 3."""
        r = D.build_alarm(self._zamg(),
                          self._inca(rr=35.0, rr30=35.0, regen_alarm=1),
                          self._tawes(regen_up=1, rr_up=35.0), {})
        self.assertEqual(r['regen'], 3)

    def test_tawes_allein_max_stufe_1(self):
        """TAWES allein (ohne INCA-Signal) → max Stufe 1."""
        r = D.build_alarm(self._zamg(),
                          self._inca(rr=0.0, regen_alarm=0),
                          self._tawes(regen_up=1, rr_up=50.0), {})
        self.assertLessEqual(r['regen'], 1)

    def test_entwarnung_flag_bei_rueckgang(self):
        """Alarm fällt von ≥1 auf 0 → entwarnung=1."""
        prev = {'gesamt': 2}
        r = D.build_alarm(self._zamg(), self._inca(), self._tawes(), prev)
        self.assertEqual(r['gesamt'],     0)
        self.assertEqual(r['entwarnung'], 1)

    def test_kein_entwarnung_wenn_alarm_aktiv(self):
        prev = {'gesamt': 1}
        r = D.build_alarm(self._zamg(wind_stufe=1, wind_aktiv=1),
                          self._inca(), self._tawes(), prev)
        self.assertEqual(r['entwarnung'], 0)

    def test_kein_entwarnung_wenn_vorher_null(self):
        prev = {'gesamt': 0}
        r = D.build_alarm(self._zamg(), self._inca(), self._tawes(), prev)
        self.assertEqual(r['entwarnung'], 0)

    def test_konfidenz_zamg_mindestens_40(self):
        """ZAMG Wind/Regen aktiv → konfidenz >= 40 (Hitze zählt nicht zu konfidenz!)"""
        r = D.build_alarm(self._zamg(wind_stufe=1, wind_aktiv=1),
                          self._inca(), self._tawes(), {})
        self.assertGreaterEqual(r['konfidenz'], 40)

    def test_konfidenz_inca_plus_tawes_hoeher(self):
        r_inca_only = D.build_alarm(self._zamg(),
                                    self._inca(rr=12.0, regen_alarm=1),
                                    self._tawes(), {})
        r_both = D.build_alarm(self._zamg(),
                               self._inca(rr=12.0, regen_alarm=1),
                               self._tawes(regen_up=1, rr_up=12.0), {})
        self.assertGreater(r_both['konfidenz'], r_inca_only['konfidenz'])

    def test_stufe_gleich_max_zamg_stufe(self):
        """build_alarm['stufe'] spiegelt zamg['max_stufe'] (inkl. Hitze)."""
        r = D.build_alarm(self._zamg(hitze_stufe=3, hitze_aktiv=1),
                          self._inca(), self._tawes(), {})
        self.assertEqual(r['stufe'], 3)  # stufe = z.get('max_stufe', 0) = 3

    def test_keine_none_werte_in_result(self):
        r = D.build_alarm(self._zamg(), self._inca(), self._tawes(), {})
        for key, val in r.items():
            self.assertIsNotNone(val, f"build_alarm Result enthält None für Key '{key}'")

    def test_empty_dicts_kein_crash(self):
        """Alle None/leere Dicts dürfen keinen Exception verursachen."""
        try:
            r = D.build_alarm(None, None, None, {})
            self.assertIsNotNone(r)
        except Exception as e:
            self.fail(f"build_alarm(None, None, None, {{}}) crashte: {e}")


# ===========================================================================
# TEST: Trend-Engine
# ===========================================================================
class TestTrendEngine(unittest.TestCase):

    def setUp(self):
        D._TREND_HISTORY = deque(maxlen=8)
        D.REGEN_ALARM = 10.0
        D.BOEN_ALARM  = 60.0

    def _inca(self, rr=0.0, fx=0.0, eta=-1):
        return {'rr_jetzt': rr, 'fx_max_60min': fx, 'minuten_bis_regen': eta}

    def _tawes(self, rr_up=0.0, fx_up=0.0, regen=0, sturm=0):
        return {'regen_upstream_mm': rr_up, 'wind_upstream_kmh': fx_up,
                'regen_upstream': regen, 'sturm_upstream': sturm}

    def test_leerer_buffer_unbekannt(self):
        r = D._analyse_trend()
        self.assertEqual(r['regen_trend'], 'unbekannt')
        self.assertEqual(r['konfidenz_bonus'], 0)

    def test_weniger_2_eintraege_unbekannt(self):
        """< 2 Einträge → 'unbekannt'. Schwelle ist 2, nicht 4."""
        D._add_trend(self._inca(rr=5.0), self._tawes())
        r = D._analyse_trend()
        self.assertEqual(r['regen_trend'], 'unbekannt')

    def test_zunehmend(self):
        for i in range(6):
            D._add_trend(self._inca(rr=float(i) * 3.0), self._tawes())
        r = D._analyse_trend()
        self.assertIn(r['regen_trend'], ['zunehmend', 'stark_zunehmend'])

    def test_abnehmend(self):
        for i in range(6, 0, -1):
            D._add_trend(self._inca(rr=float(i) * 3.0), self._tawes())
        r = D._analyse_trend()
        self.assertEqual(r['regen_trend'], 'abnehmend')

    def test_stabil(self):
        for _ in range(6):
            D._add_trend(self._inca(rr=5.0), self._tawes())
        r = D._analyse_trend()
        self.assertEqual(r['regen_trend'], 'stabil')

    def test_buffer_max_8_eintraege(self):
        for _ in range(12):
            D._add_trend(self._inca(rr=5.0), self._tawes())
        self.assertLessEqual(len(D._TREND_HISTORY), 8)

    def test_eta_leer_minus_1(self):
        r = D._analyse_trend()
        self.assertEqual(r.get('eta_korrigiert', -1), -1)

    def test_konfidenz_bonus_positiv_bei_konsistentem_trend(self):
        for i in range(6):
            D._add_trend(self._inca(rr=float(i)*3, eta=30-i*2), self._tawes())
        r = D._analyse_trend()
        if r['regen_trend'] != 'unbekannt':
            self.assertGreaterEqual(r['konfidenz_bonus'], 0)


# ===========================================================================
# TEST: DD=0 Bug (Windrichtung Nord)
# ===========================================================================
class TestWindrichtungNord(unittest.TestCase):

    def test_alter_bug_reproduzieren(self):
        """Zeigt dass der alte Code 'if ff and dd' für Nord (DD=0) falsch war."""
        ff, dd = 5.0, 0.0
        old_check = bool(ff and dd)
        self.assertFalse(old_check, "Alter Bug: dd=0.0 → False (Nord-Wind wird ignoriert)")

    def test_neue_logik_korrekt(self):
        ff, dd = 5.0, 0.0
        new_check = ff is not None and dd is not None and ff > 1.0
        self.assertTrue(new_check, "Neue Logik: dd=0.0 ist gültig")

    def test_nord_vektoraddition(self):
        """Wind aus Nord (0°): sin=0, cos=ff → dominante Richtung bleibt 0°."""
        ff, dd = 10.0, 0.0
        rad = math.radians(dd)
        self.assertAlmostEqual(math.sin(rad) * ff, 0.0, places=5)
        self.assertAlmostEqual(math.cos(rad) * ff, 10.0, places=5)

    def test_kein_wind_wird_ignoriert(self):
        """ff=0 (Windstille) soll ignoriert werden."""
        ff, dd = 0.0, 90.0
        new_check = ff is not None and dd is not None and ff > 1.0
        self.assertFalse(new_check)


# ===========================================================================
# TEST: Koordinatenformat / Locale
# ===========================================================================
class TestKoordinaten(unittest.TestCase):

    def test_komma_zu_punkt(self):
        for raw in ['48,3069', '48.3069', ' 48.306900 ']:
            v = float(raw.strip().replace(',', '.'))
            self.assertAlmostEqual(v, 48.3069, delta=0.001)

    def test_url_kein_scientific_notation(self):
        for lat in [48.3069, 47.0, 46.300001, 49.0]:
            url_part = f'{lat:.6f}'
            self.assertNotIn('e', url_part.lower())
            self.assertIn('.', url_part)

    def test_lat_auf_daemon_gesetzt(self):
        """LAT/LON müssen aus der Config korrekt geladen sein."""
        self.assertAlmostEqual(D.LAT, 48.306900, places=4)
        self.assertAlmostEqual(D.LON, 14.285800, places=4)

    def test_oesterreich_koordinaten_realistisch(self):
        """Österreich: LAT 46-49, LON 9-18"""
        self.assertGreater(D.LAT, 46.0)
        self.assertLess(D.LAT, 49.5)
        self.assertGreater(D.LON, 9.0)
        self.assertLess(D.LON, 18.0)


# ===========================================================================
# TEST: Live-API Tests (echte GeoSphere-Endpunkte)
# ===========================================================================
class TestLiveAPIs(unittest.TestCase):
    """Ruft die echten APIs auf und prüft Struktur + Plausibilität."""

    LAT = 48.306900
    LON = 14.285800

    def _get(self, url):
        import urllib.request
        try:
            req = urllib.request.Request(url, headers={'User-Agent': 'Unwetter4Lox/Test'})
            with urllib.request.urlopen(req, timeout=15) as r:
                return json.loads(r.read().decode())
        except Exception:
            return None

    # -- ZAMG --
    def test_zamg_erreichbar_und_korrekte_struktur(self):
        url = f'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lat={self.LAT:.6f}&lon={self.LON:.6f}&lang=de'
        data = self._get(url)
        if data is None:
            self.skipTest("ZAMG API nicht erreichbar")
        self.assertIn('properties', data)
        self.assertIn('warnings', data['properties'],
                      "ZAMG: data.properties.warnings erwartet – API-Struktur geändert?")
        self.assertIsInstance(data['properties']['warnings'], list)

    def test_zamg_warnungsstruktur(self):
        url = f'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lat={self.LAT:.6f}&lon={self.LON:.6f}&lang=de'
        data = self._get(url)
        if data is None:
            self.skipTest("ZAMG API nicht erreichbar")
        for w in data['properties']['warnings']:
            p = w.get('properties', {})
            self.assertIn('warntypid',   p, "Warnung: 'warntypid' fehlt")
            self.assertIn('warnstufeid', p, "Warnung: 'warnstufeid' fehlt")
            ri = p.get('rawinfo', {})
            self.assertIn('wtype', ri, "rawinfo: 'wtype' fehlt")
            self.assertIn('wlevel', ri, "rawinfo: 'wlevel' fehlt")
            self.assertIn('start',  ri, "rawinfo: 'start' (Unix-TS) fehlt")
            self.assertIn('end',    ri, "rawinfo: 'end' (Unix-TS) fehlt")
            # Unix-Timestamps müssen konvertierbar sein
            self.assertGreater(int(ri['start']), 1000000000)
            self.assertGreater(int(ri['end']),   1000000000)

    def test_zamg_warntypen_im_gueltigen_bereich(self):
        url = f'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lat={self.LAT:.6f}&lon={self.LON:.6f}&lang=de'
        data = self._get(url)
        if data is None:
            self.skipTest("ZAMG API nicht erreichbar")
        for w in data['properties']['warnings']:
            ri = w['properties']['rawinfo']
            self.assertIn(int(ri['wtype']),  range(1, 9), f"wtype={ri['wtype']} ungültig")
            self.assertIn(int(ri['wlevel']), range(1, 5), f"wlevel={ri['wlevel']} ungültig")

    # -- INCA --
    def test_inca_alle_parameter_erreichbar(self):
        base = 'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km'
        for param in ('rr', 'ff', 'fx', 'pt'):
            url = f'{base}?lat_lon={self.LAT:.6f}%2C{self.LON:.6f}&parameters={param}&output_format=geojson'
            data = self._get(url)
            if data is None:
                self.skipTest(f"INCA API nicht erreichbar (param={param})")
            self.assertIn('features',   data, f"INCA {param}: 'features' fehlt")
            self.assertIn('timestamps', data, f"INCA {param}: 'timestamps' fehlt")
            self.assertGreater(len(data['features']), 0, f"INCA {param}: leere features")
            vals = data['features'][0]['properties']['parameters'][param]['data']
            self.assertGreater(len(vals), 0)

    def test_inca_rr_plausibel(self):
        url = f'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km?lat_lon={self.LAT:.6f}%2C{self.LON:.6f}&parameters=rr&output_format=geojson'
        data = self._get(url)
        if data is None:
            self.skipTest("INCA RR nicht erreichbar")
        for v in data['features'][0]['properties']['parameters']['rr']['data']:
            self.assertGreaterEqual(float(v), 0.0,   f"RR negativ: {v}")
            self.assertLess(float(v), 200.0,          f"RR unrealistisch: {v} mm")

    def test_inca_ff_plausibel(self):
        url = f'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km?lat_lon={self.LAT:.6f}%2C{self.LON:.6f}&parameters=ff&output_format=geojson'
        data = self._get(url)
        if data is None:
            self.skipTest("INCA FF nicht erreichbar")
        for v in data['features'][0]['properties']['parameters']['ff']['data']:
            self.assertGreaterEqual(float(v), 0.0,  f"FF negativ: {v}")
            self.assertLess(float(v), 100.0,         f"FF > 100 m/s unrealistisch: {v}")

    def test_inca_pt_gueltige_codes(self):
        url = f'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km?lat_lon={self.LAT:.6f}%2C{self.LON:.6f}&parameters=pt&output_format=geojson'
        data = self._get(url)
        if data is None:
            self.skipTest("INCA PT nicht erreichbar")
        gueltig = {1, 2, 3, 4, 5, 66, 67, 255}
        for v in data['features'][0]['properties']['parameters']['pt']['data']:
            self.assertIn(int(v), gueltig, f"PT-Code {v} unbekannt")

    def test_inca_12_zeitschritte(self):
        """INCA liefert für Horizont 60min genau 4 Schritte × 15min = mindestens 4."""
        url = f'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km?lat_lon={self.LAT:.6f}%2C{self.LON:.6f}&parameters=rr&output_format=geojson'
        data = self._get(url)
        if data is None:
            self.skipTest("INCA nicht erreichbar")
        self.assertGreaterEqual(len(data['timestamps']), 4)

    # -- TAWES --
    def test_tawes_stationen_api(self):
        url = 'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min/metadata'
        data = self._get(url)
        if data is None:
            self.skipTest("TAWES Meta-API nicht erreichbar")
        self.assertIn('stations', data)
        self.assertGreater(len(data['stations']), 100)
        ids = {str(s['id']) for s in data['stations']}
        self.assertIn('11060', ids, "Linz-Stadt (11060) nicht in TAWES-Stationsliste")

    def test_tawes_live_daten(self):
        url = 'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min?parameters=RR,FF,FFX,DD,P,RF&station_ids=11060,11010&output_format=geojson'
        data = self._get(url)
        if data is None:
            self.skipTest("TAWES Live-API nicht erreichbar")
        self.assertIn('features', data)
        self.assertGreater(len(data['features']), 0)
        for f in data['features']:
            params = f['properties']['parameters']
            for p in ('RR', 'FF', 'FFX', 'DD'):
                self.assertIn(p, params, f"TAWES: Parameter {p} fehlt")
            # Plausibilitätsprüfung
            ff = params['FF']['data'][-1]
            dd = params['DD']['data'][-1]
            if ff is not None:
                self.assertGreaterEqual(float(ff), 0.0)
                self.assertLess(float(ff), 50.0, f"FF > 50 m/s unrealistisch: {ff}")
            if dd is not None:
                self.assertGreaterEqual(float(dd), 0.0)
                self.assertLessEqual(float(dd), 360.0)

    def test_tawes_dd_null_nord_gueltig(self):
        """DD=0.0 (Windrichtung Nord) muss als gültiger Wert akzeptiert werden."""
        url = 'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min?parameters=DD,FF&station_ids=11060,11010,11012&output_format=geojson'
        data = self._get(url)
        if data is None:
            self.skipTest("TAWES nicht erreichbar")
        for f in data['features']:
            dd = f['properties']['parameters']['DD']['data'][-1]
            ff = f['properties']['parameters']['FF']['data'][-1]
            if dd is not None and ff is not None:
                # Korrekte Bedingung (neue Logik)
                should_use = ff is not None and dd is not None and float(ff) > 1.0
                # Alte kaputte Bedingung
                old_check = bool(float(ff)) and bool(float(dd))
                if float(dd) == 0.0 and float(ff) > 1.0:
                    self.assertTrue(should_use, "Nord-Wind muss verarbeitet werden")
                    self.assertFalse(old_check, "Alter Bug demonstriert: dd=0 war False")


# ===========================================================================
if __name__ == '__main__':
    unittest.main(verbosity=2)
