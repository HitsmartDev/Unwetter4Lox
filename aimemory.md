## 📌 Projekt-Status
- **Version:** 0.4.33 (ZIP: `unwetter4Lox-V.0.4.33.zip`)
- **Letzter Stand (Gemini, 2026-06-13):** v0.4.33 implementiert. **Safe-Boot Fix:** Inkompatibilität mit Python < 3.7 (`fromisoformat`) behoben. Detailliertes Exception-Logging via Traceback und `crash.log` hinzugefügt.

---

## 🏗️ Architektur-Übersicht

### Datenquellen
- **ZAMG/GeoSphere:** Offizielle Warnungen, Stufe 0–4 → `zamg/` Topics
- **INCA Nowcast:** 15-min Vorhersage, 1km, 60min Horizont → `inca/` Topics
- **TAWES 360°:** ~10min Live-Stationsdaten, Upstream-Erkennung, Regen-ETA → `tawes/` Topics
- **alarm/:** Aggregierter Gesamtstatus (alle 3 Quellen kombiniert)

### Alarm-Level Schema (v0.4.19+, WICHTIG)
- **0** = Ruhig
- **1** = Vorsicht (ZAMG Gelb / 1×BOEN_ALARM / 1×REGEN_ALARM)
- **2** = Warnung (ZAMG Orange / 2×BOEN_ALARM / 2×REGEN_ALARM)
- **3** = Extrem (ZAMG Rot/Lila / 3×BOEN_ALARM / 3×REGEN_ALARM)
- INCA und TAWES können **alle drei Level** erreichen (kein Cap bei 2 mehr!). `aktiv`-Flag ändert Level NICHT.
- Wind: INCA nutzt `fx_max_60min` direkt (nicht mehr `bald_sturm_30/60` für Level-Berechnung)

### Schwellwerte (konfigurierbar)
- `BOEN_ALARM` (Standard 60 km/h): INCA + TAWES Wind-Alarm; Level 1/2/3 bei 1×/2×/3×
- `REGEN_ALARM` (Standard 10 mm/h): INCA rr_jetzt + TAWES upstream_mm; Level 1/2/3 bei 1×/2×/3×

### TAWES RR Einheit: mm/10min (×6 = mm/h)
Die TAWES-API gibt RR in mm/10min zurück! `regen_upstream_mm` wird intern auf mm/h umgerechnet (×6) und mit REGEN_ALARM verglichen. Regen-Upstream-Detection-Threshold: 0.1 mm/10min ≈ 0.6 mm/h.

### TAWES Lokal-Regen Radius (v0.4.28+)
- Anzeigeradius: 40km (Log, UI). Alarmradius: 25km (regen_lokal_mm → alarm/regen).
- Stationen 25–40km erscheinen in Info-Log aber lösen keinen alarm/regen aus.
- `tawes/regen_lokal_station`: Name + km + mm/h der Alarm-Station (≤25km).
- `alarm/regen_quelle`: ZAMG / INCA / TAWES_UPSTREAM / TAWES_LOKAL / –.
- notification/alle: INCA-Fallback "kein Alarm" erscheint nicht wenn nur TAWES/ZAMG Alarm aktiv.

### TAWES Buffer-Fenster (v0.4.27+)
- `regen_upstream` scannt nur letzte `REGEN_PUFFER_FENSTER = 3` Buffer-Einträge (30min). Verhindert dass Regen von vor 2h noch `regen_upstream=1` zeigt.
- `wind_kaskade` scannt nur letzte `WIND_KASKADE_FENSTER = 6` Buffer-Einträge (60min). Eine Kaskade muss innerhalb 60min stattfinden → verhindert False-Alarm durch alten Böen-Eintrag + aktuelle Einzelstation.
- `alarm/wind_quelle` Topic: zeigt Alarm-Quelle (`ZAMG`, `INCA (Xkm/h)`, `TAWES_STURM (Xkm/h)`, `TAWES_KASKADE`, `–`).

### TAWES Konsens-Schwelle (v0.4.24+)
Zwei neue Config-Parameter in `[TAWES]`:
- `MAX_STATIONS` (Standard 25): Anzahl Stationen die pro Zyklus vom API abgefragt werden (in `fetch_tawes_data` via `[:TAWES_MAX_STATIONS]`). Auch in Diagnose-Log verwendet.
- `MIN_ALARM_PROZENT` (Standard 30): % der Upstream-Stationen MIT Daten die Schwellwert überschreiten müssen. Wind: `alarm_count >= max(1, round(len(ffx_vals) * PCT/100))`. Regen: gleiche Logik auf Stationen mit Buffer-Einträgen. Verhindert Single-Station-False-Positives.
- Beide in Settings-UI als Schieberegler; Daemon-Neustart nach Änderung nötig.

### TAWES Elevation & Lokal-Regen (v0.4.25+)
- **Stationshöhe**: `load_tawes_stations()` speichert jetzt `alt` (Seehöhe in Meter) aus API-Feldern `alt/altitude/elevation/hoehe`. Cache-Datei enthält `alt`; Fallback `0` für alte Caches.
- **`MAX_UPSTREAM_HOEHE_M`** (Standard 1200m): Upstream-Stationen über dieser Höhe werden aus `ffx_vals` für Wind-Alarm-Konsens ausgeschlossen. Variable `ffx_vals_alpin` enthält die ausgeschlossenen Werte; werden geloggt aber nicht für Alarm genutzt. Topic: `tawes/alpine_upstream`.
- **Lokal-Regen** (`regen_lokal`, `regen_lokal_mm`): In `correlate_tawes()` nach regen_upstream-Block: alle `stations_mit_daten` innerhalb 40km auf `RR > 0.1mm/10min` prüfen. Unabhängig von Windrichtung. `regen_lokal_mm = max(RR) × 6` (mm/h). Fließt in `build_alarm()` `alarm/regen` ein (gleiche 1×/2×/3×-REGEN_ALARM-Logik wie upstream_mm).
- Upstream-Log zeigt jetzt Seehöhe: `Feuerkogel (70km, 1618m, FFX=85km/h)`.

### TAWES Cache beim Daemon-Start löschen (v0.4.20+)
`run()` löscht `tawes_stations.json` bei JEDEM Daemon-Start. Kein Startup-Flag (`_tawes_startup_fresh_done`) mehr. Stationen werden bei jedem Start frisch von der API geladen → keine Race-Conditions.

### TAWES `_canon_sid` – längste Zifferngruppe (v0.4.21 Fix)
Bug: Compound-IDs wie `"ST.11035-01"` gaben `"1"` (letzte Gruppe) statt `"11035"` (längste). Auch Float-Strings wie `"11035.0"` → jetzt korrekt via `int(float(s))`. ID-Format-Log jetzt INFO-Level (sichtbar ohne Debug-Modus).

---

## ⚠️ Kritische Erkenntnisse (immer beachten)

### Config-Backup-Mechanismus
`preupgrade.sh` sichert Config nach `/tmp/unwetter4lox_cfg_upgrade.bak`.  
`postroot.sh` prüft `/tmp/` zuerst. LoxBerry löscht config/-Ordner beim Update!

### TAWES Startup-Fresh-Load (v0.4.20+)
`run()` löscht `tawes_stations.json` bei jedem Daemon-Start. Das alte `_tawes_startup_fresh_done` Flag wurde entfernt (war unzuverlässig). Stationen werden immer frisch geladen.

### ZIP-Erstellung (Windows)
Immer `build_zip.ps1 -Version X.X.X` verwenden. Nutzt .NET ZipFile API mit `.Replace([char]92, [char]47)` – Forward-Slashes für LoxBerry-kompatibles ZIP.  
**Namenskonvention: `unwetter4Lox-V.X.X.X.zip`** (z.B. `unwetter4Lox-V.0.4.26.zip`)  
**Ausschließen:** `.git/`, `__pycache__/`, `aimemory.md`, `*.zip`, `*.pyc`, `build_zip.ps1`

### LB_SDK Status
LoxBerry Python SDK (`loxberry`) nicht installiert auf dem Testsystem → Fallback-Logging aktiv. Kein Bug.

### TAWES-Netzrealität
In der Region des Users haben nur wenige Stationen aktive Böen-Sensoren. Viele TAWES-Stationen sind Klimastationen ohne Anemometer – FFX=None ist normal, kein Bug.

---

## 🛠️ Aktueller Fokus (Next Steps)
1. **Installieren:** v0.4.28 ZIP mit `build_zip.ps1 -Version 0.4.28` erstellen und auf LoxBerry installieren
2. **TAWES_REGEN_LOKAL_KM:** In Einstellungen prüfen ob Slider sichtbar und korrekt speichert. Standard 25km.
3. **Validieren:** `alarm/regen_quelle` zeigt bei lokalem Regen `TAWES_LOKAL (Station km mm/h)` – GALLSPACH bei 28km NW darf jetzt keinen alarm/regen mehr auslösen (liegt außerhalb 25km-Radius).
4. **Validieren:** `notification/alle` zeigt keinen "kein Alarm"-Text wenn Alarm nur von TAWES kommt (INCA hat keinen eigenen Alarm).
5. **ZIP-Befehl:** `.\build_zip.ps1 -Version "0.4.28"` → erzeugt `unwetter4Lox-V.0.4.28.zip`

---

## 📋 Letzte Versionen
- **v0.4.28:** regen_lokal Alarmradius 40→25km; alarm/regen_quelle + tawes/regen_lokal_station; notification/alle Fix
- **v0.4.27:** Buffer-Fenster-Fix: regen_upstream nur letzte 30min, wind_kaskade nur letzte 60min; alarm/wind_quelle Topic
- **v0.4.26:** Konsens-Gate in build_alarm, API-Robustheit, sturm_upstream Pflicht für Wind-Alarm, Wind-Kaskade-Erkennung
- **v0.4.25:** TAWES Elevation-Support (alt in Metadaten), Alpine-Ausschluss (MAX_UPSTREAM_HOEHE_M=1200m), Lokal-Regen-Erkennung (tawes/regen_lokal)
- **v0.4.24:** TAWES Konsens-Schwelle (MIN_ALARM_PROZENT=30%, MAX_STATIONS=25) – Alarm nur wenn ≥N% Upstream-Stationen bestätigen
- **v0.4.23:** Bugfix retained Messages: bei alarm==0 leerer String gesendet → Broker löscht retained Eintrag
- **v0.4.22:** Notification-Gate (INCA/TAWES/Alle nur bei alarm>=1), neues `alarm/entwarnung` Topic (0/1)
- **v0.4.21:** `_canon_sid` Bugfix (längste Zifferngruppe), Float-ID-Fix, ID-Format als INFO geloggt
- **v0.4.20:** Cache-Lösung: `run()` löscht TAWES-Cache bei jedem Daemon-Start (kein Flag mehr)
- **v0.4.19:** Alarm-Schema 1×/2×/3×-Schwellwert; UI Restart/Reload AJAX mit Auto-Refresh
- **v0.4.18:** Konservativer Fallback upstream_mm==0 entfernt
- **v0.4.17:** TAWES Startup-Fresh-Load, regen_upstream_mm (mm/h), Unit-Fix
- **v0.4.16:** Notification-Dedup, REGEN_ALARM-Gate für bald_regen
