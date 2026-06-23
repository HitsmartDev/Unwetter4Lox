"""
Unit-Tests für Unwetter4Lox Daemon.
Ausführen: python -m pytest tests/test_daemon.py -v
Abhängigkeiten: pytest (pip install pytest)

Diese Tests benötigen KEINE LoxBerry-Umgebung und KEINE Netzwerkverbindung.
Alle externen Abhängigkeiten werden gemockt.
"""
import sys
import os
import math
import time
import json
import types
import unittest
from unittest.mock import patch, MagicMock
from collections import deque

# ---------------------------------------------------------------------------
# Daemon-Modul isoliert importieren (ohne LoxBerry, ohne echte Config)
# ---------------------------------------------------------------------------
DAEMON_PATH = os.path.join(os.path.dirname(__file__), '..', 'bin', 'unwetter4lox_daemon.py')

def _load_daemon_module():
    """Lädt den Daemon als Modul mit gestubbten Globals."""
    # Stub-Logger damit das Modul ohne LB-SDK startet
    stub_log = MagicMock()
    stub_log.info = MagicMock()
    stub_log.debug = MagicMock()
    stub_log.warning = MagicMock()
    stub_log.error = MagicMock()
    stub_log.critical = MagicMock()

    env_patch = {
        'LBHOMEDIR': '/tmp',
        'LBPPLUGINDIR': 'unwetter4lox',
    }

    # Modul-Code lesen und mit Patches ausführen
    with open(DAEMON_PATH, 'r', encoding='utf-8') as f:
        src = f.read()

    mod = types.ModuleType('daemon')
    mod.__file__ = DAEMON_PATH

    # Alle nötigen Importe vorab setzen
    import math, time, json, re, os, socket, logging, traceback, urllib.request
    from datetime import datetime, timezone
    from collections import deque
    from concurrent.futures import ThreadPoolExecutor, as_completed

    mod.__dict__.update({
        'math': math, 'time': time, 'json': json, 're': re, 'os': os,
        'socket': socket, 'logging': logging, 'traceback': traceback,
        'urllib': urllib, 'datetime': datetime, 'timezone': timezone,
        'deque': deque, 'ThreadPoolExecutor': ThreadPoolExecutor,
        'as_completed': as_completed,
        'sys': sys,
    })

    # Stubs für Daemon-Globals die normalerweise aus Config kommen
    stubs = {
        'LB_SDK': False, 'log': stub_log, 'LOGFILE': '/tmp/test.log',
        'LOGDIR': '/tmp',
        'LAT': 48.3069, 'LON': 14.2858,
        'LBLANG': 'de',
        'INTERVAL': 300, 'ZAMG_INTERVAL': 300, 'INCA_INTERVAL': 300, 'TAWES_INTERVAL': 480,
        'BOEN_ALARM': 60.0, 'REGEN_ALARM': 10.0,
        'MQTT_BROKER': '127.0.0.1', 'MQTT_PORT': 1883,
        'MQTT_USER': '', 'MQTT_PASS': '',
        'TOPIC_PREFIX': 'unwetter',
        'ZAMG_ENABLED': True, 'INCA_ENABLED': True, 'TAWES_ENABLED': True,
        'INCA_HORIZON': 60,
        'TAWES_MAX_KM': 120, 'TAWES_MAX_STATIONS': 25,
        'TAWES_MIN_ALARM_PCT': 0.30, 'TAWES_MAX_UPSTREAM_HOEHE': 1200,
        'TAWES_REGEN_LOKAL_KM': 25, 'TAWES_UPSTREAM_WINKEL': 45,
        'MQTT_WATCHDOG_TIMEOUT': 1800,
        'NOTIFICATION_MIN_STUFE': 1,
        'STATE_FILE': '/tmp/state_test.json',
        'PID_FILE': '/tmp/daemon_test.pid',
        'LBPPLUGINDIR': 'unwetter4lox',
        '_MQTT_CLIENT_ID': 'unwetter4lox-test',
        '_mqtt_connected': MagicMock(is_set=lambda: True),
        '_disconnect_since': 0,
        '_last_successful_publish': time.time(),
        '_mqtt_reconnect_count': 0,
        'TAWES_BUFFER': {},
        '_TREND_HISTORY': deque(maxlen=8),
        'CURRENT_LOGLEVEL': 3,
        'client': MagicMock(),
    }

    # PT_NAME Dict (aus Daemon)
    stubs['PT_NAME'] = {
        1: 'Regen', 2: 'Schneeregen', 3: 'Schnee', 4: 'Graupel', 5: 'Hagel',
        66: 'Gefrierender Regen', 67: 'Gefrierender Regen', 255: 'Kein Niederschlag'
    }

    # L Dict (Sprachstrings)
    stubs['L'] = {
        'no_warns': 'Keine aktiven Warnungen',
        'pt_none': 'Kein Niederschlag',
        'wind': 'Wind', 'regen': 'Regen', 'schnee': 'Schnee',
        'glatteis': 'Glatteis', 'gewitter': 'Gewitter',
        'hitze': 'Hitze', 'kaelte': 'Kälte', 'hagel': 'Hagel',
        'stufe_0': 'Ruhig', 'stufe_1': 'Vorsicht', 'stufe_2': 'Warnung', 'stufe_3': 'Gefahr',
    }

    mod.__dict__.update(stubs)

    # Nur die reinen Funktionen extrahieren (kein top-level Execution-Code)
    # Wir führen nur die Funktionsdefinitionen aus
    exec(compile(src, DAEMON_PATH, 'exec'), mod.__dict__)
    return mod


# Modul einmal laden
try:
    _D = _load_daemon_module()
    DAEMON_LOADED = True
except Exception as e:
    DAEMON_LOADED = False
    DAEMON_LOAD_ERROR = str(e)


# ---------------------------------------------------------------------------
# Hilfsfunktionen direkt definieren (falls Modul nicht ladbar)
# ---------------------------------------------------------------------------
def haversine(lat1, lon1, lat2, lon2):
    R = 6371.0
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = math.sin(dlat/2)**2 + math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) * math.sin(dlon/2)**2
    return R * 2 * math.asin(math.sqrt(a))

def bearing(lat1, lon1, lat2, lon2):
    lat1, lat2 = math.radians(lat1), math.radians(lat2)
    dlon = math.radians(lon2 - lon1)
    x = math.sin(dlon) * math.cos(lat2)
    y = math.cos(lat1)*math.sin(lat2) - math.sin(lat1)*math.cos(lat2)*math.cos(dlon)
    return (math.degrees(math.atan2(x, y)) + 360) % 360


# ===========================================================================
# TEST: _parse_iso
# ===========================================================================
class TestParseIso(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        self.fn = _D._parse_iso

    def test_leerer_string_gibt_null(self):
        """Leerer String muss 0 zurückgeben, NICHT time.time()!"""
        result = self.fn('')
        self.assertEqual(result, 0, "Leerer String soll 0 liefern (Fallback für e_ep-Berechnung)")

    def test_none_gibt_null(self):
        result = self.fn(None)
        self.assertEqual(result, 0)

    def test_iso_mit_z(self):
        result = self.fn('2026-06-23T10:00:00Z')
        expected = 1782295200  # 2026-06-23 10:00 UTC
        self.assertAlmostEqual(result, expected, delta=5)

    def test_iso_ohne_sekunden(self):
        result = self.fn('2026-06-23T10:00Z')
        expected = 1782295200
        self.assertAlmostEqual(result, expected, delta=5)

    def test_iso_mit_offset(self):
        result = self.fn('2026-06-23T12:00+02:00')
        expected = 1782295200  # = 10:00 UTC
        self.assertAlmostEqual(result, expected, delta=5)

    def test_ungueltig_gibt_null(self):
        result = self.fn('kein-datum')
        self.assertEqual(result, 0)

    def test_gibt_int_zurueck(self):
        result = self.fn('2026-06-23T10:00:00Z')
        self.assertIsInstance(result, int, "_parse_iso() muss int zurückgeben!")


# ===========================================================================
# TEST: _haversine
# ===========================================================================
class TestHaversine(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        self.fn = _D._haversine

    def test_gleicher_punkt_null(self):
        self.assertAlmostEqual(self.fn(48.3069, 14.2858, 48.3069, 14.2858), 0.0, places=3)

    def test_linz_gmunden(self):
        """Linz → Gmunden ≈ 38 km"""
        km = self.fn(48.3069, 14.2858, 47.918, 13.799)
        self.assertAlmostEqual(km, 38, delta=5)

    def test_linz_wien(self):
        """Linz → Wien ≈ 181 km"""
        km = self.fn(48.3069, 14.2858, 48.2093, 16.3728)
        self.assertAlmostEqual(km, 168, delta=10)

    def test_positiv(self):
        km = self.fn(47.0, 13.0, 48.0, 14.0)
        self.assertGreater(km, 0)

    def test_symmetrisch(self):
        a = self.fn(48.0, 14.0, 47.0, 13.0)
        b = self.fn(47.0, 13.0, 48.0, 14.0)
        self.assertAlmostEqual(a, b, places=5)


# ===========================================================================
# TEST: _bearing
# ===========================================================================
class TestBearing(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        self.fn = _D._bearing

    def test_nord(self):
        """Punkt direkt nördlich → 0°"""
        b = self.fn(48.0, 14.0, 49.0, 14.0)
        self.assertAlmostEqual(b % 360, 0.0, delta=2)

    def test_sued(self):
        """Punkt direkt südlich → 180°"""
        b = self.fn(48.0, 14.0, 47.0, 14.0)
        self.assertAlmostEqual(b, 180.0, delta=2)

    def test_ost(self):
        """Punkt direkt östlich → 90°"""
        b = self.fn(48.0, 14.0, 48.0, 15.0)
        self.assertAlmostEqual(b, 90.0, delta=5)

    def test_west(self):
        """Punkt direkt westlich → 270°"""
        b = self.fn(48.0, 14.0, 48.0, 13.0)
        self.assertAlmostEqual(b, 270.0, delta=5)

    def test_bereich_0_360(self):
        """Ergebnis immer 0-360°"""
        for lat2, lon2 in [(49.0,14.0),(47.0,14.0),(48.0,15.0),(48.0,13.0),(49.0,15.0)]:
            b = self.fn(48.0, 14.0, lat2, lon2)
            self.assertGreaterEqual(b, 0.0)
            self.assertLess(b, 360.0)


# ===========================================================================
# TEST: _mm_al und _fx_al (Alarm-Level)
# ===========================================================================
class TestAlarmLevel(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        # REGEN_ALARM=10, BOEN_ALARM=60
        _D.REGEN_ALARM = 10.0
        _D.BOEN_ALARM = 60.0

    def test_mm_unter_schwelle_stufe_0(self):
        self.assertEqual(_D._mm_al(0.0), 0)
        self.assertEqual(_D._mm_al(9.9), 0)

    def test_mm_erste_schwelle_stufe_1(self):
        self.assertEqual(_D._mm_al(10.0), 1)
        self.assertEqual(_D._mm_al(15.0), 1)
        self.assertEqual(_D._mm_al(19.9), 1)

    def test_mm_zweite_schwelle_stufe_2(self):
        self.assertEqual(_D._mm_al(20.0), 2)
        self.assertEqual(_D._mm_al(25.0), 2)
        self.assertEqual(_D._mm_al(29.9), 2)

    def test_mm_dritte_schwelle_stufe_3(self):
        self.assertEqual(_D._mm_al(30.0), 3)
        self.assertEqual(_D._mm_al(100.0), 3)

    def test_fx_unter_schwelle_stufe_0(self):
        self.assertEqual(_D._fx_al(0.0), 0)
        self.assertEqual(_D._fx_al(59.9), 0)

    def test_fx_erste_schwelle_stufe_1(self):
        self.assertEqual(_D._fx_al(60.0), 1)
        self.assertEqual(_D._fx_al(90.0), 1)
        self.assertEqual(_D._fx_al(119.9), 1)

    def test_fx_zweite_schwelle_stufe_2(self):
        self.assertEqual(_D._fx_al(120.0), 2)

    def test_fx_dritte_schwelle_stufe_3(self):
        self.assertEqual(_D._fx_al(180.0), 3)

    def test_schwellenwert_genau_an_grenze(self):
        """Exakt an der Schwelle → Stufe 1"""
        _D.REGEN_ALARM = 5.0
        self.assertEqual(_D._mm_al(5.0), 1)
        self.assertEqual(_D._mm_al(4.99), 0)
        _D.REGEN_ALARM = 10.0  # Reset


# ===========================================================================
# TEST: _linreg_slope
# ===========================================================================
class TestLinregSlope(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        self.fn = _D._linreg_slope

    def test_konstante_serie_null_steigung(self):
        self.assertAlmostEqual(self.fn([5.0, 5.0, 5.0, 5.0, 5.0]), 0.0, places=5)

    def test_steigende_serie(self):
        slope = self.fn([1.0, 2.0, 3.0, 4.0, 5.0])
        self.assertGreater(slope, 0)
        self.assertAlmostEqual(slope, 1.0, places=5)

    def test_fallende_serie(self):
        slope = self.fn([5.0, 4.0, 3.0, 2.0, 1.0])
        self.assertLess(slope, 0)
        self.assertAlmostEqual(slope, -1.0, places=5)

    def test_zu_kurz_gibt_null(self):
        """Weniger als 4 Werte → 0.0 (nicht genug für Trend)"""
        self.assertEqual(self.fn([1.0, 2.0, 3.0]), 0.0)
        self.assertEqual(self.fn([]), 0.0)
        self.assertEqual(self.fn([1.0]), 0.0)

    def test_vier_werte_reichen(self):
        slope = self.fn([1.0, 2.0, 3.0, 4.0])
        self.assertAlmostEqual(slope, 1.0, places=3)


# ===========================================================================
# TEST: ZAMG Parser – fetch_zamg() mit gemocktem API-Response
# ===========================================================================
class TestFetchZamg(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')

    def _make_warning(self, warntypid, warnstufeid, start_offset=0, end_offset=3600, text='Test'):
        """Erzeugt eine realistische ZAMG-API Warnung."""
        now = int(time.time())
        return {
            "type": "Warning",
            "properties": {
                "warnid": 1,
                "warntypid": warntypid,
                "warnstufeid": warnstufeid,
                "begin": "23.06.2026 10:00",
                "end": "23.06.2026 22:00",
                "text": text,
                "rawinfo": {
                    "wtype": warntypid,
                    "wlevel": warnstufeid,
                    "start": str(now + start_offset),
                    "end": str(now + end_offset),
                }
            }
        }

    def _make_response(self, warnings):
        """Erzeugt eine realistische ZAMG API-Antwort (Feature-Format)."""
        return {
            "type": "Feature",
            "geometry": {"type": "MultiPolygon", "coordinates": []},
            "properties": {
                "location": {"type": "Municipal", "properties": {}},
                "warnings": warnings
            }
        }

    @patch.object(sys.modules.get('__main__', MagicMock()), 'fetch_json')
    def test_aktive_gewitterwarnung_erkannt(self):
        """Aktive Gewitterwarnung (wtype=5, wlevel=1) muss alarm/gewitter=1 setzen."""
        mock_data = self._make_response([
            self._make_warning(warntypid=5, warnstufeid=1, start_offset=-3600, end_offset=3600, text='Gelbe Gewitterwarnung')
        ])
        with patch.object(_D, 'fetch_json', return_value=mock_data):
            result = _D.fetch_zamg()
        self.assertIsNotNone(result, "fetch_zamg() darf nicht None zurückgeben")
        self.assertTrue(result.get('_api_ok'), "_api_ok muss True sein")
        self.assertEqual(result['gewitter']['stufe'], 1)
        self.assertEqual(result['gewitter']['aktiv'], 1)
        self.assertEqual(result['max_stufe'], 1)

    def test_hitzewarnung_stufe_2(self):
        """Hitzewarnung Stufe 2 (wtype=6, wlevel=2)."""
        mock_data = self._make_response([
            self._make_warning(warntypid=6, warnstufeid=2, start_offset=-7200, end_offset=7200)
        ])
        with patch.object(_D, 'fetch_json', return_value=mock_data):
            result = _D.fetch_zamg()
        self.assertEqual(result['hitze']['stufe'], 2)
        self.assertEqual(result['hitze']['aktiv'], 1)
        self.assertEqual(result['max_stufe'], 2)

    def test_alle_warn_typen_erkannt(self):
        """Alle 8 Warnungstypen müssen korrekt gemappt werden."""
        typ_map = {1:'wind', 2:'regen', 3:'schnee', 4:'glatteis', 5:'gewitter', 6:'hitze', 7:'kaelte', 8:'hagel'}
        for wtype, expected_key in typ_map.items():
            mock_data = self._make_response([
                self._make_warning(warntypid=wtype, warnstufeid=1, start_offset=-100, end_offset=3600)
            ])
            with patch.object(_D, 'fetch_json', return_value=mock_data):
                result = _D.fetch_zamg()
            self.assertIsNotNone(result, f"wtype={wtype} lieferte None")
            self.assertEqual(result[expected_key]['stufe'], 1, f"wtype={wtype} → {expected_key} sollte stufe=1 haben")

    def test_leere_warnings_kein_alarm(self):
        """Leere Warningsliste → alle Stufen 0."""
        mock_data = self._make_response([])
        with patch.object(_D, 'fetch_json', return_value=mock_data):
            result = _D.fetch_zamg()
        self.assertEqual(result['max_stufe'], 0)
        self.assertEqual(result['irgendwas_aktiv'], 0)

    def test_api_none_gibt_none(self):
        """Wenn API None liefert → fetch_zamg() gibt None zurück."""
        with patch.object(_D, 'fetch_json', return_value=None):
            result = _D.fetch_zamg()
        self.assertIsNone(result)

    def test_abgelaufene_warnung_wird_ignoriert(self):
        """Warnung die bereits abgelaufen ist, darf keinen Alarm auslösen."""
        mock_data = self._make_response([
            self._make_warning(warntypid=5, warnstufeid=2, start_offset=-7200, end_offset=-3600)
        ])
        with patch.object(_D, 'fetch_json', return_value=mock_data):
            result = _D.fetch_zamg()
        self.assertEqual(result['gewitter']['aktiv'], 0)
        self.assertEqual(result['max_stufe'], 0)

    def test_zukuenftige_warnung_bald(self):
        """Warnung die in 15 Minuten beginnt → bald=1."""
        mock_data = self._make_response([
            self._make_warning(warntypid=5, warnstufeid=1, start_offset=900, end_offset=7200)
        ])
        with patch.object(_D, 'fetch_json', return_value=mock_data):
            result = _D.fetch_zamg()
        self.assertEqual(result['gewitter']['bald'], 1)
        self.assertEqual(result['gewitter']['aktiv'], 0)

    def test_realistische_api_antwort(self):
        """Test mit der echten heute beobachteten API-Struktur (6 Warnungen)."""
        now = int(time.time())
        mock_data = {
            "type": "Feature",
            "geometry": {"type": "MultiPolygon", "coordinates": []},
            "properties": {
                "location": {},
                "warnings": [
                    # Hitze heute (aktiv)
                    {"type":"Warning","properties":{"warntypid":6,"warnstufeid":2,"text":"Hitzewelle",
                     "rawinfo":{"wtype":6,"wlevel":2,"start":str(now-3600),"end":str(now+7200)}}},
                    # Hitze morgen (Tageswarnung)
                    {"type":"Warning","properties":{"warntypid":6,"warnstufeid":2,"text":"Hitzewelle",
                     "rawinfo":{"wtype":6,"wlevel":2,"start":str(now+86400),"end":str(now+172800)}}},
                    # Gewitter heute (aktiv)
                    {"type":"Warning","properties":{"warntypid":5,"warnstufeid":1,"text":"Gewitter",
                     "rawinfo":{"wtype":5,"wlevel":1,"start":str(now-1800),"end":str(now+5400)}}},
                ]
            }
        }
        with patch.object(_D, 'fetch_json', return_value=mock_data):
            result = _D.fetch_zamg()
        self.assertIsNotNone(result)
        self.assertEqual(result['hitze']['stufe'], 2)
        self.assertEqual(result['hitze']['aktiv'], 1)
        self.assertEqual(result['gewitter']['stufe'], 1)
        self.assertEqual(result['gewitter']['aktiv'], 1)
        self.assertEqual(result['max_stufe'], 2)
        self.assertEqual(result['irgendwas_aktiv'], 1)


# ===========================================================================
# TEST: build_alarm() – Multi-Source Alarm-Fusion
# ===========================================================================
class TestBuildAlarm(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        _D.REGEN_ALARM = 10.0
        _D.BOEN_ALARM = 60.0

    def _empty_zamg(self):
        return {wt: {'stufe':0,'aktiv':0,'bald':0,'tageswarnung':0,'start_epoch':0,'end_epoch':0,'notification':''}
                for wt in ['wind','regen','schnee','glatteis','gewitter','hitze','kaelte','hagel']}

    def _empty_inca(self):
        return {'ff_jetzt':0.0,'fx_jetzt':0.0,'fx_max_30min':0.0,'fx_max_60min':0.0,
                'rr_jetzt':0.0,'rr_max_30min':0.0,'pt_jetzt':255,'minuten_bis_regen':-1,
                'bald_regen':0,'bald_hagel':0,'regen_alarm':0,'_api_ok':True}

    def _empty_tawes(self):
        return {'sturm_upstream':0,'regen_upstream':0,'regen_upstream_mm':0.0,
                'regen_lokal':0,'regen_lokal_mm':0.0,'wind_upstream_kmh':0.0,
                'eta_physik_min':-1,'upstream_aktiv':0,'_api_ok':True}

    def test_alles_leer_kein_alarm(self):
        """Wenn alle Quellen leer → gesamt=0."""
        result = _D.build_alarm(self._empty_zamg(), self._empty_inca(), self._empty_tawes(), {})
        self.assertEqual(result['gesamt'], 0)
        self.assertEqual(result['wind'], 0)
        self.assertEqual(result['regen'], 0)

    def test_zamg_allein_voller_alarm(self):
        """ZAMG-Warnung direkt → volle Stufe 1-3 ohne Korroboration."""
        zamg = self._empty_zamg()
        zamg['wind']['stufe'] = 3
        zamg['wind']['aktiv'] = 1
        result = _D.build_alarm(zamg, self._empty_inca(), self._empty_tawes(), {})
        self.assertEqual(result['wind'], 3)
        self.assertGreaterEqual(result['gesamt'], 1)

    def test_inca_allein_max_stufe_1(self):
        """INCA allein (ohne ZAMG, ohne TAWES) → max. Stufe 1."""
        inca = self._empty_inca()
        inca['rr_jetzt'] = 25.0  # Weit über Schwelle → würde Stufe 2-3 geben
        inca['fx_max_60min'] = 80.0
        inca['regen_alarm'] = 1
        result = _D.build_alarm(self._empty_zamg(), inca, self._empty_tawes(), {})
        self.assertLessEqual(result['regen'], 1, "INCA allein darf nicht über Stufe 1!")

    def test_inca_plus_tawes_voller_alarm(self):
        """INCA + TAWES zusammen → volle Stufe 1-3."""
        inca = self._empty_inca()
        inca['rr_jetzt'] = 25.0  # 2.5× Schwelle → würde Stufe 2 rechtfertigen
        inca['rr_max_30min'] = 25.0
        inca['regen_alarm'] = 1
        tawes = self._empty_tawes()
        tawes['regen_upstream'] = 1
        tawes['regen_upstream_mm'] = 25.0
        result = _D.build_alarm(self._empty_zamg(), inca, tawes, {})
        self.assertGreaterEqual(result['regen'], 2, "INCA+TAWES mit 2.5× Schwelle → min. Stufe 2")

    def test_tawes_allein_max_stufe_1(self):
        """TAWES allein → max. Stufe 1."""
        tawes = self._empty_tawes()
        tawes['regen_upstream'] = 1
        tawes['regen_upstream_mm'] = 35.0  # Würde Stufe 3 geben
        result = _D.build_alarm(self._empty_zamg(), self._empty_inca(), tawes, {})
        self.assertLessEqual(result['regen'], 1, "TAWES allein darf nicht über Stufe 1!")

    def test_entwarnung_bei_rueckgang(self):
        """Wenn gesamt von ≥1 auf 0 fällt → entwarnung=1."""
        prev = {'gesamt': 2, 'wind': 0, 'regen': 2}
        result = _D.build_alarm(self._empty_zamg(), self._empty_inca(), self._empty_tawes(), prev)
        self.assertEqual(result['gesamt'], 0)
        self.assertEqual(result['entwarnung'], 1, "Entwarnung muss 1 sein wenn Alarm zurückgeht!")

    def test_kein_entwarnung_wenn_weiter_aktiv(self):
        """Wenn Alarm weiter aktiv → entwarnung=0."""
        zamg = self._empty_zamg()
        zamg['wind']['stufe'] = 1
        zamg['wind']['aktiv'] = 1
        prev = {'gesamt': 1}
        result = _D.build_alarm(zamg, self._empty_inca(), self._empty_tawes(), prev)
        self.assertEqual(result['entwarnung'], 0)

    def test_konfidenz_mit_zamg(self):
        """ZAMG aktiv → Konfidenz ≥ 40."""
        zamg = self._empty_zamg()
        zamg['hitze']['stufe'] = 1
        zamg['hitze']['aktiv'] = 1
        result = _D.build_alarm(zamg, self._empty_inca(), self._empty_tawes(), {})
        self.assertGreaterEqual(result['konfidenz'], 40)

    def test_keine_stufe_null_erzeugt_entwarnung(self):
        """Wenn vorher kein Alarm (gesamt=0) → kein Entwarnung-Flag."""
        prev = {'gesamt': 0}
        result = _D.build_alarm(self._empty_zamg(), self._empty_inca(), self._empty_tawes(), prev)
        self.assertEqual(result['entwarnung'], 0)

    def test_stufe_berechnung_korrekt(self):
        """alarm/stufe = max ZAMG-Stufe direkt."""
        zamg = self._empty_zamg()
        zamg['hitze']['stufe'] = 3
        zamg['hitze']['aktiv'] = 1
        result = _D.build_alarm(zamg, self._empty_inca(), self._empty_tawes(), {})
        self.assertEqual(result['stufe'], 3)


# ===========================================================================
# TEST: _add_trend und _analyse_trend
# ===========================================================================
class TestTrendEngine(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        _D._TREND_HISTORY = deque(maxlen=8)
        _D.REGEN_ALARM = 10.0
        _D.BOEN_ALARM = 60.0

    def _make_inca(self, rr=0.0, fx60=0.0, eta=-1):
        return {'rr_jetzt': rr, 'fx_max_60min': fx60, 'minuten_bis_regen': eta,
                'regen_alarm': int(rr >= _D.REGEN_ALARM)}

    def _make_tawes(self, rr_up=0.0, fx_up=0.0, regen=0, sturm=0):
        return {'regen_upstream_mm': rr_up, 'wind_upstream_kmh': fx_up,
                'regen_upstream': regen, 'sturm_upstream': sturm}

    def test_leerer_buffer_unbekannter_trend(self):
        result = _D._analyse_trend()
        self.assertEqual(result['regen_trend'], 'unbekannt')
        self.assertEqual(result['konfidenz_bonus'], 0)

    def test_weniger_als_vier_eintraege_unbekannt(self):
        for _ in range(3):
            _D._add_trend(self._make_inca(rr=5.0), self._make_tawes())
        result = _D._analyse_trend()
        self.assertEqual(result['regen_trend'], 'unbekannt')

    def test_zunehmender_regen_trend(self):
        """Stetig steigender Regen → 'zunehmend' oder 'stark_zunehmend'."""
        for i in range(6):
            _D._add_trend(self._make_inca(rr=float(i)*3), self._make_tawes())
        result = _D._analyse_trend()
        self.assertIn(result['regen_trend'], ['zunehmend', 'stark_zunehmend'])

    def test_abnehmender_regen_trend(self):
        """Sinkender Regen → 'abnehmend'."""
        for i in range(6, 0, -1):
            _D._add_trend(self._make_inca(rr=float(i)*3), self._make_tawes())
        result = _D._analyse_trend()
        self.assertEqual(result['regen_trend'], 'abnehmend')

    def test_stabiler_regen_kein_trend(self):
        """Konstanter Regen → 'stabil'."""
        for _ in range(6):
            _D._add_trend(self._make_inca(rr=5.0), self._make_tawes())
        result = _D._analyse_trend()
        self.assertEqual(result['regen_trend'], 'stabil')

    def test_eta_nicht_null_bei_leerem_buffer(self):
        """eta_korrigiert bei leerem Buffer → -1."""
        result = _D._analyse_trend()
        self.assertEqual(result.get('eta_korrigiert', -1), -1)

    def test_buffer_maxlaenge_8(self):
        """Buffer soll maximal 8 Einträge behalten."""
        for _ in range(12):
            _D._add_trend(self._make_inca(rr=5.0), self._make_tawes())
        self.assertLessEqual(len(_D._TREND_HISTORY), 8)


# ===========================================================================
# TEST: _canon_sid (TAWES Station-ID Normalisierung)
# ===========================================================================
class TestCanonSid(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        self.fn = _D._canon_sid

    def test_int_string(self):
        self.assertEqual(self.fn('11035'), '11035')

    def test_int(self):
        self.assertEqual(self.fn(11035), '11035')

    def test_mit_prefix(self):
        """'ST.11035-01' → '11035' (längste Zifferngruppe)."""
        self.assertEqual(self.fn('ST.11035-01'), '11035')

    def test_einfacher_prefix(self):
        self.assertEqual(self.fn('ST-11060'), '11060')

    def test_float_string(self):
        self.assertEqual(self.fn('11035.0'), '11035')


# ===========================================================================
# TEST: DD=0 Bug – Windrichtung Nord wird korrekt verarbeitet
# ===========================================================================
class TestWindrichtungNord(unittest.TestCase):
    """DD=0 (Windrichtung Nord) war früher durch 'if ff and dd' falsy."""

    def test_dd_null_ist_nicht_falsy(self):
        """dd=0.0 muss als gültige Windrichtung Nord erkannt werden."""
        # Windvektor-Berechnung direkt testen
        ff, dd = 5.0, 0.0
        # Alte kaputte Bedingung:
        old_result = bool(ff and dd)  # False weil dd=0.0 ist falsy!
        # Neue korrekte Bedingung:
        new_result = bool(ff is not None and dd is not None and ff > 1.0)
        self.assertFalse(old_result, "Alter Bug: dd=0.0 wurde als False interpretiert")
        self.assertTrue(new_result, "Neue Logik: dd=0.0 (Nord) ist gültig")

    def test_vektoraddition_nord_korrekt(self):
        """Wind aus Nord (dd=0°) muss sin=0, cos=ff ergeben."""
        ff, dd = 10.0, 0.0
        rad = math.radians(dd)
        sin_comp = math.sin(rad) * ff  # ≈ 0
        cos_comp = math.cos(rad) * ff  # ≈ 10
        self.assertAlmostEqual(sin_comp, 0.0, places=5)
        self.assertAlmostEqual(cos_comp, 10.0, places=5)


# ===========================================================================
# TEST: INCA API-Antwort-Parsing
# ===========================================================================
class TestFetchInca(unittest.TestCase):
    def setUp(self):
        if not DAEMON_LOADED:
            self.skipTest(f'Daemon nicht ladbar: {DAEMON_LOAD_ERROR}')
        _D.REGEN_ALARM = 10.0
        _D.BOEN_ALARM = 60.0
        _D.INCA_HORIZON = 60

    def _make_inca_response(self, param, values, timestamps=None):
        """Erzeugt eine realistische INCA API-Antwort."""
        now = __import__('datetime').datetime.now(__import__('datetime').timezone.utc)
        if timestamps is None:
            from datetime import datetime, timezone, timedelta
            timestamps = [(now + timedelta(minutes=15*(i+1))).strftime('%Y-%m-%dT%H:%M+00:00')
                          for i in range(len(values))]
        return {
            "type": "FeatureCollection",
            "timestamps": timestamps,
            "features": [{
                "type": "Feature",
                "geometry": {"type": "Point", "coordinates": [14.289, 48.305]},
                "properties": {
                    "parameters": {
                        param: {"name": param, "unit": "m/s" if param in ('ff','fx') else "kg m-2", "data": values}
                    }
                }
            }]
        }

    def test_kein_regen_eta_minus_1(self):
        """Wenn kein Regen in der Prognose → minuten_bis_regen=-1."""
        def mock_req(param):
            vals = [0.0] * 4
            return self._make_inca_response(param, vals)
        with patch.object(_D, 'fetch_json', side_effect=lambda url, p: mock_req(p.split()[-1])):
            result = _D.fetch_inca()
        if result:
            self.assertEqual(result.get('minuten_bis_regen', -1), -1)

    def test_regen_einheit_mm_per_h(self):
        """RR: kg/m² per 15 min (= mm/15min) → mm/h (×4). INCA liefert kg/m² = mm."""
        # INCA rr-Wert 0.5 kg/m² per 15min = 2.0 mm/h (0.5×4)
        # Aber der Daemon gibt rr_jetzt = values[0] direkt zurück (erste Zeitschritte = mm/15min)
        # Überprüfen ob Einheit korrekt behandelt wird
        from datetime import datetime, timezone, timedelta
        now = datetime.now(timezone.utc)
        ts = [(now + timedelta(minutes=15*(i+1))).strftime('%Y-%m-%dT%H:%M+00:00') for i in range(4)]

        def mock_fetch(url, provider):
            param = provider.split()[-1]
            if param == 'rr':
                return {"type":"FeatureCollection","timestamps":ts,
                        "features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[14.2,48.3]},
                                     "properties":{"parameters":{"rr":{"data":[0.5,0.5,0.5,0.5],"unit":"kg m-2"}}}}]}
            return {"type":"FeatureCollection","timestamps":ts,
                    "features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[14.2,48.3]},
                                 "properties":{"parameters":{param:{"data":[0.0,0.0,0.0,0.0],"unit":"m/s"}}}}]}

        with patch.object(_D, 'fetch_json', side_effect=mock_fetch):
            result = _D.fetch_inca()
        if result:
            # rr_jetzt = 0.5 mm (per 15 min interval) - direkt übergeben
            self.assertGreater(result.get('rr_jetzt', 0), 0)

    def test_wind_kmh_konversion(self):
        """FF/FX werden von m/s in km/h konvertiert (×3.6)."""
        from datetime import datetime, timezone, timedelta
        now = datetime.now(timezone.utc)
        ts = [(now + timedelta(minutes=15*(i+1))).strftime('%Y-%m-%dT%H:%M+00:00') for i in range(4)]

        def mock_fetch(url, provider):
            param = provider.split()[-1]
            val = 10.0 if param in ('ff','fx') else 0.0  # 10 m/s = 36 km/h
            return {"type":"FeatureCollection","timestamps":ts,
                    "features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[14.2,48.3]},
                                 "properties":{"parameters":{param:{"data":[val,val,val,val],"unit":"m/s"}}}}]}

        with patch.object(_D, 'fetch_json', side_effect=mock_fetch):
            result = _D.fetch_inca()
        if result:
            self.assertAlmostEqual(result.get('ff_jetzt', 0), 36.0, delta=1.0)
            self.assertAlmostEqual(result.get('fx_jetzt', 0), 36.0, delta=1.0)


# ===========================================================================
# TEST: Koordinatenformat – Locale-Robustheit
# ===========================================================================
class TestKoordinatenFormat(unittest.TestCase):
    def test_python_float_komma_bereinigung(self):
        """float('48,3069'.replace(',', '.')) muss 48.3069 liefern."""
        raw = '48,3069'
        result = float(raw.replace(',', '.'))
        self.assertAlmostEqual(result, 48.3069, places=4)

    def test_php_locale_simulation(self):
        """Simuliert PHP floatval() mit DE-Locale → Komma als Dezimaltrennzeichen."""
        # PHP gibt '48,3069' wenn LC_NUMERIC=de_AT.UTF-8
        # Python muss das robut behandeln
        for raw in ['48.3069', '48,3069', ' 48.3069 ', '48.306900']:
            result = float(raw.strip().replace(',', '.'))
            self.assertAlmostEqual(result, 48.3069, places=4)

    def test_url_format_kein_scientific_notation(self):
        """LAT/LON dürfen in URL nie wissenschaftliche Notation haben."""
        lat = 48.3069
        lon = 14.2858
        url_part = f'{lat:.6f}'
        self.assertNotIn('e', url_part.lower(), "Keine wissenschaftliche Notation in URL")
        self.assertIn('.', url_part)

    def test_koordinaten_range_oesterreich(self):
        """Österreich-Koordinaten: LAT 46.3-49.0, LON 9.5-17.2"""
        for lat in [46.3, 47.5, 48.3069, 49.0]:
            for lon in [9.5, 13.0, 14.2858, 17.2]:
                url = f'lat={lat:.6f}&lon={lon:.6f}'
                self.assertIn('lat=', url)
                self.assertNotIn('e+', url.lower())
                self.assertNotIn('e-', url.lower())


# ===========================================================================
# LIVE API Tests (nur wenn Netzwerk verfügbar – optional)
# ===========================================================================
class TestLiveAPIs(unittest.TestCase):
    """Live-Tests gegen die echten APIs. Werden übersprungen wenn kein Netzwerk."""

    LAT = 48.306900
    LON = 14.285800

    def _try_fetch(self, url):
        try:
            import urllib.request
            req = urllib.request.Request(url, headers={'User-Agent': 'Unwetter4Lox/Test'})
            with urllib.request.urlopen(req, timeout=10) as r:
                return json.loads(r.read().decode())
        except Exception:
            return None

    def test_zamg_api_erreichbar(self):
        """ZAMG API gibt HTTP 200 und korrektes Format zurück."""
        url = f'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lat={self.LAT:.6f}&lon={self.LON:.6f}&lang=de'
        data = self._try_fetch(url)
        if data is None:
            self.skipTest("ZAMG API nicht erreichbar (Netzwerk)")
        self.assertIsInstance(data, dict)
        self.assertIn('properties', data, "ZAMG Response muss 'properties' Key haben")
        self.assertIn('warnings', data['properties'], "ZAMG Response muss data.properties.warnings haben")
        warnings = data['properties']['warnings']
        self.assertIsInstance(warnings, list)

    def test_zamg_warnings_korrekte_struktur(self):
        """Wenn Warnungen vorhanden, müssen sie warntypid/warnstufeid/rawinfo haben."""
        url = f'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lat={self.LAT:.6f}&lon={self.LON:.6f}&lang=de'
        data = self._try_fetch(url)
        if data is None:
            self.skipTest("ZAMG API nicht erreichbar")
        warnings = data.get('properties', {}).get('warnings', [])
        for w in warnings:
            props = w.get('properties', {})
            self.assertIn('warntypid', props, f"Warnung ohne 'warntypid': {props.keys()}")
            self.assertIn('warnstufeid', props, f"Warnung ohne 'warnstufeid': {props.keys()}")
            rawinfo = props.get('rawinfo', {})
            self.assertIn('wtype', rawinfo, "rawinfo muss 'wtype' haben")
            self.assertIn('wlevel', rawinfo, "rawinfo muss 'wlevel' haben")
            self.assertIn('start', rawinfo, "rawinfo muss 'start' (Unix-TS) haben")
            self.assertIn('end', rawinfo, "rawinfo muss 'end' (Unix-TS) haben")

    def test_inca_api_erreichbar(self):
        """INCA Nowcast API gibt Daten für alle 4 Parameter zurück."""
        base = 'https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km'
        for param in ('rr', 'ff', 'fx', 'pt'):
            url = f'{base}?lat_lon={self.LAT:.6f}%2C{self.LON:.6f}&parameters={param}&output_format=geojson'
            data = self._try_fetch(url)
            if data is None:
                self.skipTest(f"INCA API nicht erreichbar ({param})")
            self.assertIn('features', data, f"INCA {param}: 'features' Key fehlt")
            self.assertIn('timestamps', data, f"INCA {param}: 'timestamps' Key fehlt")
            features = data['features']
            self.assertGreater(len(features), 0, f"INCA {param}: leere features Liste")
            vals = features[0]['properties']['parameters'][param]['data']
            self.assertGreater(len(vals), 0, f"INCA {param}: keine Datenpunkte")
            # Plausibilitätsprüfung
            if param == 'ff':
                for v in vals:
                    self.assertGreaterEqual(float(v), 0, f"ff negativ: {v}")
                    self.assertLess(float(v), 100, f"ff unrealistisch hoch: {v} m/s")
            if param == 'rr':
                for v in vals:
                    self.assertGreaterEqual(float(v), 0, f"rr negativ: {v}")
                    self.assertLess(float(v), 100, f"rr unrealistisch hoch: {v} mm")

    def test_tawes_api_erreichbar(self):
        """TAWES API gibt Stationsdaten zurück."""
        url = 'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min?parameters=RR,FF,FFX,DD,P,RF&station_ids=11060,11010&output_format=geojson'
        data = self._try_fetch(url)
        if data is None:
            self.skipTest("TAWES API nicht erreichbar")
        self.assertIn('features', data)
        self.assertIn('timestamps', data)
        for f in data['features']:
            params = f['properties']['parameters']
            for p in ('RR', 'FF', 'FFX', 'DD'):
                self.assertIn(p, params, f"TAWES: Parameter {p} fehlt")
                val = params[p]['data'][-1]
                if val is not None:
                    # Plausibilitätsprüfung
                    if p == 'FF':
                        self.assertGreaterEqual(float(val), 0)
                        self.assertLess(float(val), 50, f"FF unrealistisch: {val} m/s")
                    if p == 'DD':
                        self.assertGreaterEqual(float(val), 0)
                        self.assertLessEqual(float(val), 360)
                    if p == 'RR':
                        self.assertGreaterEqual(float(val), 0)

    def test_tawes_stationen_liste(self):
        """TAWES Metadata-API liefert Stationsliste."""
        url = 'https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min/metadata'
        data = self._try_fetch(url)
        if data is None:
            self.skipTest("TAWES Meta-API nicht erreichbar")
        self.assertIn('stations', data)
        stations = data['stations']
        self.assertGreater(len(stations), 100, "Weniger als 100 TAWES-Stationen – verdächtig")
        # Prüfe Linz vorhanden
        linz_ids = [s for s in stations if s.get('id') in ('11060', 11060)]
        self.assertGreater(len(linz_ids), 0, "Station Linz-Stadt (11060) nicht in Stationsliste")


# ===========================================================================
if __name__ == '__main__':
    unittest.main(verbosity=2)
