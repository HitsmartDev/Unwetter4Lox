# Gemini Handoff – Unwetter4Lox v0.4.17

Letzter Stand: 2026-06-09 (Claude)

---

## Aktueller Projektstatus

Plugin läuft stabil auf dem LoxBerry. Version 0.4.17 ist committed und ZIP liegt bereit (`unwetter4lox_v0.4.17.zip`). Alle drei Datenquellen (ZAMG, INCA, TAWES) funktionieren. Zwei kritische Bugs wurden in dieser Session behoben.

---

## Was zuletzt geändert wurde

### v0.4.17 (2026-06-09)

**Bug 1 – TAWES Stationen nach Installation fehlend:**
- Root Cause: Race-Condition zwischen `postinstall.sh` (löscht Cache) und Daemon-Start. Daemon lud alten Cache bevor der Delete lief.
- Fix: Neues Flag `_tawes_startup_fresh_done` in `bin/unwetter4lox_daemon.py`. Beim ersten Aufruf von `load_tawes_stations()` nach Daemon-Start wird der Cache-File immer ignoriert und frisch von der API geladen.

**Bug 2 – alarm_regen=1 trotz REGEN_ALARM=30mm/h (Nieselregen):**
- Root Cause: TAWES `regen_upstream=1` wurde bei jeder Upstream-Station mit RR>0.1mm/10min gesetzt, ohne Intensitäts-Check in `build_alarm()`.
- Fix: Neues Feld `regen_upstream_mm` (mm/h, nach ×6-Umrechnung aus mm/10min TAWES-Messwert) in `correlate_tawes()`. In `build_alarm()` wird TAWES Regen-Alarm nur ausgelöst wenn `upstream_mm >= REGEN_ALARM / 3.0` oder `upstream_mm == 0` (unbekannt → konservativ alarmieren).

**Konsistenz-Audit:**
- Unit-Bug behoben: TAWES RR = mm/10min, ×6 ergibt mm/h (in regen_upstream_mm)
- Versionsstrings im Daemon (Docstring + run-Log) von v0.4.5 auf v0.4.17 korrigiert
- README Version von v0.4.8 auf v0.4.17 aktualisiert
- `tawes/regen_upstream_mm` in README + help.php dokumentiert
- alarm/regen Tabellen in README + help.php: TAWES Intensitäts-Gate erklärt
- UI (index.php) zeigt jetzt Upstream-Regenintensität in der Regenfront-Zeile
- CHANGELOG.md: v0.4.11 bis v0.4.17 nachgetragen

---

## Wichtige Architektur-Details

### Alarm-Level Schema (gilt für ALLE alarm/ Topics)
```
0 = Ruhig
1 = Vorsicht  → ZAMG Gelb / INCA ≥ BOEN_ALARM (60min) / TAWES ≥ BOEN_ALARM
2 = Warnung   → ZAMG Orange / INCA ≥ BOEN_ALARM (30min) oder ≥ REGEN_ALARM / TAWES 2×BOEN
3 = Extrem    → NUR ZAMG Rot/Lila
```
**WICHTIG:** INCA/TAWES können NUR bis Level 2 anheben. `aktiv`-Flag ändert Level NICHT.

### Schwellwerte (in Config einstellbar, Abschnitt [THRESHOLDS])
- `BOEN_ALARM` (Standard: 60 km/h) – Wind-Alarm INCA + TAWES
- `REGEN_ALARM` (Standard: 10 mm/h) – Regen-Alarm für INCA (direkt) und TAWES (Gate: REGEN_ALARM/3)

### TAWES RR Einheit – ACHTUNG
TAWES API gibt `RR` in **mm/10min**. Umrechnung zu mm/h: ×6.
- `regen_upstream` Detektionsschwelle: RR > 0.1 mm/10min ≈ 0.6 mm/h (sehr empfindlich, für Info)
- `regen_upstream_mm` im Return-Dict und MQTT-Topic: bereits in mm/h (nach ×6 Umrechnung)
- Alarm-Schwelle in `build_alarm()`: `upstream_mm >= REGEN_ALARM / 3.0` (beide in mm/h → korrekt)

### Notification-Dedup
Notifications werden nur publiziert wenn sich der Text ändert (via `prev.get('_n_geo', '')` etc. in `state.json`). Volatile Werte (ETA, Windstärke) werden auf 5er-Schritte gerundet bevor Textvergleich.

### TAWES Startup-Fresh-Load
```python
_tawes_startup_fresh_done = False  # Modul-Variable, Zeile ~328

# In load_tawes_stations():
if not _tawes_startup_fresh_done:
    _tawes_startup_fresh_done = True
    log.info('TAWES: Daemon-Start – Station-Cache ignoriert, frischer API-Abruf...')
    # → springt direkt zur API-Fetch-Logik, überspringt Cache-File
```

---

## Dateistruktur (relevante Dateien)

```
bin/unwetter4lox_daemon.py    ← Haupt-Daemon, alle Logik
webfrontend/htmlauth/
  index.php                   ← Status-UI
  settings.php                ← Konfiguration
  help.php                    ← MQTT-Referenz, Alarm-Logik-Doku
  ajax.php                    ← Daemon-Steuerung, Geocoding
postinstall.sh                ← Cache löschen + Daemon restart nach Install
README.md                     ← Vollständige Doku (aktuell halten!)
CHANGELOG.md                  ← Versionshistorie (aktuell halten!)
aimemory.md                   ← KI-Kontext (IMMER zuerst lesen, IMMER aktualisieren!)
gemini.md                     ← Dieses Handoff-Dokument
build_zip.ps1                 ← ZIP-Build (Windows, Forward-Slash-Fix)
```

---

## MQTT Topics Übersicht

**Präfix:** `unwetter/` (konfigurierbar in [MQTT] TOPIC_PREFIX)

| Gruppe | Wichtigste Topics |
|:-------|:-----------------|
| `alarm/` | `gesamt` (0-3), `gewitter`, `wind`, `regen`, `hagel`, `schnee`, `zusammenfassung` |
| `notification/` | `geosphere`, `inca`, `tawes`, `alle` – immer befüllt, nur bei Änderung publiziert |
| `zamg/` | `{typ}/stufe`, `{typ}/aktiv`, `{typ}/bald`, `max_stufe`, `irgendwas_aktiv`, `akutwarnung` |
| `inca/` | `fx`, `ff`, `fx_max_30min`, `fx_max_60min`, `rr`, `regen_alarm`, `bald_regen`, `bald_sturm_30`, `bald_sturm_60`, `pt`, `pt_name`, `pt_bald`, `pt_bald_name`, `minuten_bis_regen` |
| `tawes/` | `regen_upstream`, `regen_upstream_mm`, `regen_eta_min`, `regen_konfidenz`, `wind_upstream_kmh`, `sturm_upstream`, `wind_trend`, `gewitter_signal`, `druck_trend`, `dominante_windrichtung`, `upstream_aktiv` |
| System | `status`, `letzter_abruf_datum`, `letzter_abruf_epoch` |

---

## Offene Tasks / Bekannte Punkte

- **Testen:** v0.4.17 auf LoxBerry installieren und validieren:
  1. TAWES Stationen nach frischer Installation vorhanden (ohne manuelles Cache-Löschen)
  2. `alarm/regen` bleibt 0 bei Nieselregen wenn REGEN_ALARM=30mm/h
- **LB_SDK:** Python SDK (`loxberry`) nicht installiert → Fallback-Logging aktiv (kein Bug)
- **TAWES-Netzrealität:** Wenige Stationen mit Böen-Sensoren in der Region – FFX=None ist normal

---

## ZIP erstellen (Windows)

```powershell
cd "...\Unwetter4Lox"
.\build_zip.ps1 -Version "0.4.17"
```

**WICHTIG:** Immer `build_zip.ps1` verwenden, **nie** `Compress-Archive`! Das .NET ZipFile API erzeugt Forward-Slashes in Pfaden. `Compress-Archive` erzeugt Windows-Backslashes → LoxBerry-Installation schlägt fehl.

---

## Sync-Pflicht (für beide AIs)

Beim Start: `aimemory.md` lesen.  
Beim Abschluss / Wechsel: `aimemory.md` UND `gemini.md` aktualisieren.
