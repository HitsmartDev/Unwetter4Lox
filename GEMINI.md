# Gemini Handoff – Unwetter4Lox v0.4.32

Letzter Stand: 2026-06-13 (Gemini)

---

## Aktueller Projektstatus

Plugin in aktiver Entwicklung. Version 0.4.32. Fokus auf sofortige Datenverfügbarkeit nach dem Start/Update (Cold-Start Problem behoben).

---

## Was zuletzt geändert wurde (diese Session)

### v0.4.32 (2026-06-13)
**Initialisierungs-Logik & TAWES-Historie:**
- **Historischer Daten-Abruf:** Beim ersten Loop-Durchlauf nach dem Start ruft der Daemon nun via `fetch_tawes_data(duration_min=60)` die historischen Daten der letzten Stunde ab. Dies füllt den `TAWES_BUFFER` (In-Memory) sofort, sodass Trends, Kaskaden und Display-Werte ohne Verzögerung zur Verfügung stehen.
- **Status-Meldung:** Während dieses initialen Abrufs steht der Status in der UI auf `Initialisierung...`.
- **API-Endpoint Wechsel:** Nutzt für den Initial-Load den `/timeseries/historical` Endpoint der GeoSphere API.

### v0.4.31 (2026-06-13)
...

- `regen_upstream` Buffer-Fenster auf 30min begrenzt (`REGEN_PUFFER_FENSTER = 3` Einträge). Verhindert "Regen bei Vöcklabruck" Notification Stunden nach dem letzten Regen.
- `wind_kaskade` Buffer-Fenster auf 60min begrenzt (`WIND_KASKADE_FENSTER = 6` Einträge). Verhindert dass alter Böen-Eintrag + aktuelle Einzelstation als Kaskade erkannt wird.
- Neues `alarm/wind_quelle` MQTT Topic: `ZAMG`, `INCA (52.3km/h)`, `TAWES_STURM (65km/h)`, `TAWES_KASKADE`, `–`.

### v0.4.26 (2026-06-11)
**Konsens-Gate + API-Robustheit:**
- Kritischer Fix: `build_alarm()` gated TAWES-Wind-Alarm auf `sturm_upstream == 1`
- Konsens-Minimum auf 2 Stationen erhöht (`max(2, round(N×30%))`)
- Wind-Kaskaden-Erkennung mit ETA-Berechnung
- Robuste API-Fehlerbehandlung, `tawes/api_ok` MQTT Topic
- `status/zamg_ok`, `status/inca_ok`, `status/tawes_ok` Topics
- Buffer-Cleanup nach Windrichtungswechsel

### v0.4.25 (2026-06-11)
**TAWES Elevation-Support + Lokal-Regen-Erkennung:**
- `load_tawes_stations()`: `alt` (Seehöhe m) aus API speichern
- `MAX_UPSTREAM_HOEHE_M` (Standard 1200m): Alpine Stationen aus Wind-Konsens ausschließen
- `tawes/alpine_upstream` Topic: Anzahl ausgeschlossener Upstream-Stationen
- `regen_lokal` / `regen_lokal_mm`: Stationen innerhalb 40km (damals fix) auf RR > 0.1mm/10min prüfen

### v0.4.24 (2026-06-10)
**TAWES Konsens-Schwelle:**
- `MAX_STATIONS` (Standard 25) und `MIN_ALARM_PROZENT` (Standard 30) in Config [TAWES]
- Wind-Konsens: `alarm_count >= max(1, round(len(ffx_vals) * PCT/100))`
- Settings-UI Schieberegler

### v0.4.23 (2026-06-10)
**Bugfix retained Messages:** Bei alarm==0 leerer String → löscht retained Message im Broker

### v0.4.22 (2026-06-10)
**Notification-Gate:** `notification/inca`, `notification/tawes`, `notification/alle` nur bei `alarm/gesamt ≥ 1`. `alarm/entwarnung` (0/1) einmalig bei Alarm-Ende.

### v0.4.21 (2026-06-09)
`_canon_sid` Bugfix: längste Zifferngruppe statt letzte. Float-ID-Fix.

### v0.4.20 (2026-06-09)
TAWES Cache bei Daemon-Start immer löschen (kein Flag mehr).

### v0.4.19 (2026-06-09)
Alarm-Schema 1×/2×/3×-Schwellwert. INCA/TAWES können Level 3 erreichen. UI AJAX-Restart.

---

## Wichtige Architektur-Details

### Alarm-Level Schema (gilt für ALLE alarm/ Topics)
```
0 = Ruhig
1 = Vorsicht  → ZAMG Gelb   / INCA/TAWES ≥ 1× Schwellwert
2 = Warnung   → ZAMG Orange / INCA/TAWES ≥ 2× Schwellwert
3 = Extrem    → ZAMG Rot/Lila / INCA/TAWES ≥ 3× Schwellwert
```
`aktiv`-Flag ändert Level NICHT. Wind: INCA nutzt `fx_max_60min` direkt (kein Konsens). TAWES braucht Konsens (`sturm_upstream=1`) ODER Wind-Kaskade (`wind_kaskade=1`).

### TAWES RR Einheit – ACHTUNG
TAWES API gibt `RR` in **mm/10min**. Umrechnung zu mm/h: ×6. `regen_upstream_mm` und `regen_lokal_mm` sind bereits in mm/h (nach ×6). REGEN_ALARM ist in mm/h.

### TAWES Lokal-Regen Radius (v0.4.28+)
- Anzeigeradius fix: 40km (Log, Info)
- Alarmradius konfigurierbar: `TAWES_REGEN_LOKAL_KM` (Standard 25km, Config `[TAWES]`, Settings-UI)
- Stationen 25–40km: im Log sichtbar, kein Alarm-Beitrag

### TAWES Buffer-Fenster (v0.4.27+)
- `REGEN_PUFFER_FENSTER = 3` (30min): verhindert stale `regen_upstream=1` nach Regen-Ende
- `WIND_KASKADE_FENSTER = 6` (60min): verhindert False-Alarm durch alten Böen-Eintrag

---

## Dateistruktur (relevante Dateien)

```
bin/unwetter4lox_daemon.py    ← Haupt-Daemon, alle Logik
webfrontend/htmlauth/
  index.php                   ← Status-UI
  settings.php                ← Konfiguration (inkl. Lokal-Regen Slider)
  help.php                    ← MQTT-Referenz, komplett aktualisiert v0.4.28
  ajax.php                    ← Daemon-Steuerung, Geocoding
postinstall.sh                ← Cache löschen + Daemon restart nach Install
README.md                     ← Vollständige Doku, aktualisiert auf v0.4.28
CHANGELOG.md                  ← Versionshistorie
aimemory.md                   ← KI-Kontext (IMMER zuerst lesen, IMMER aktualisieren!)
GEMINI.md                     ← Dieses Handoff-Dokument
build_zip.ps1                 ← ZIP-Build (Windows, Forward-Slash-Fix)
```

---

## MQTT Topics Übersicht (vollständig)

**Präfix:** `unwetter/` (konfigurierbar in [MQTT] TOPIC_PREFIX)

| Gruppe | Topics |
|:-------|:-------|
| `alarm/` | `gesamt`, `gewitter`, `wind`, `regen`, `hagel`, `schnee`, `stufe`, `zusammenfassung`, `entwarnung`, `wind_quelle`, `regen_quelle` |
| `status/` | `status`, `zamg_ok`, `inca_ok`, `tawes_ok`, `letzter_abruf_datum`, `letzter_abruf_epoch` |
| `notification/` | `geosphere` (immer), `inca`, `tawes`, `alle` (nur bei alarm/gesamt≥1) |
| `zamg/` | `{typ}/stufe`, `{typ}/aktiv`, `{typ}/bald`, `{typ}/start_epoch`, `{typ}/end_epoch`, `{typ}/notification`, `max_stufe`, `irgendwas_aktiv`, `akutwarnung`, `letzter_abruf` |
| `inca/` | `fx`, `ff`, `fx_max_30min`, `fx_max_60min`, `rr`, `regen_alarm`, `bald_regen`, `bald_hagel`, `bald_graupel`, `bald_sturm_30`, `bald_sturm_60`, `pt`, `pt_name`, `pt_bald`, `pt_bald_name`, `minuten_bis_regen`, `letzter_abruf` |
| `tawes/` | `dominante_windrichtung`, `dominante_windrichtung_name`, `upstream_aktiv`, `wind_upstream_kmh`, `wind_trend`, `sturm_upstream`, `wind_kaskade`, `wind_kaskade_eta_min`, `wind_kaskade_speed_kmh`, `alpine_upstream`, `regen_upstream`, `regen_upstream_mm`, `regen_eta_min`, `front_speed_kmh`, `regen_konfidenz`, `regen_lokal`, `regen_lokal_mm`, `regen_lokal_station`, `druck_trend`, `gewitter_signal`, `stationen_anzahl`, `naechste_station`, `api_ok`, `letztes_update` |

---

## Offene Tasks / Bekannte Punkte

- v0.4.28 ZIP erstellen und auf LoxBerry installieren: `.\build_zip.ps1 -Version "0.4.28"`
- Nach Installation validieren: GALLSPACH (28km NW) darf keinen alarm/regen mehr auslösen
- `notification/alle` zeigt keinen "kein Alarm"-Text wenn nur TAWES aktiven Alarm hat
- LB_SDK: Python SDK (`loxberry`) nicht installiert → Fallback-Logging aktiv (kein Bug)
- TAWES-Netzrealität: Viele Klimastationen ohne Anemometer → `–` in Wind/Böen ist normal

---

## Wichtige Workflows

- **ZIP-Erstellung:** Nach jeder funktionalen Änderung oder Versionierung MUSS automatisch ein neues Plugin-ZIP erstellt werden (`.\build_zip.ps1 -Version "X.X.X"`).

```powershell
.\build_zip.ps1 -Version "0.4.28"
```

**WICHTIG:** Immer `build_zip.ps1` verwenden, **nie** `Compress-Archive`! Das .NET ZipFile API erzeugt Forward-Slashes in Pfaden. `Compress-Archive` → Windows-Backslashes → LoxBerry-Installation schlägt fehl.

**Namenskonvention:** `unwetter4Lox-V.0.4.28.zip`

---

## Sync-Pflicht (für beide AIs)

Beim Start: `aimemory.md` lesen.  
Beim Abschluss / Wechsel: `aimemory.md` UND `GEMINI.md` aktualisieren.
