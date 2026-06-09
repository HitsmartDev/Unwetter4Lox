## 📌 Projekt-Status
- **Version:** 0.4.17 (ZIP: `unwetter4lox_v0.4.17.zip`)
- **Letzter Stand (Claude, 2026-06-09):** Vollständiger Konsistenz-Audit abgeschlossen. ZIP erstellt. Alle Dokus aktuell.

---

## 🏗️ Architektur-Übersicht

### Datenquellen
- **ZAMG/GeoSphere:** Offizielle Warnungen, Stufe 0–4 → `zamg/` Topics
- **INCA Nowcast:** 15-min Vorhersage, 1km, 60min Horizont → `inca/` Topics
- **TAWES 360°:** ~10min Live-Stationsdaten, Upstream-Erkennung, Regen-ETA → `tawes/` Topics
- **alarm/:** Aggregierter Gesamtstatus (alle 3 Quellen kombiniert)

### Alarm-Level Schema (v0.4.13+, WICHTIG)
- **0** = Ruhig
- **1** = Vorsicht (ZAMG Gelb / INCA ≥ BOEN_ALARM in 60min / TAWES ≥ BOEN_ALARM upstream)
- **2** = Warnung (ZAMG Orange / INCA in 30min / TAWES 2×BOEN oder Regen ≥ REGEN_ALARM)
- **3** = Extrem (nur ZAMG Rot/Lila)
- INCA/TAWES können auf **max. Level 2** anheben. `aktiv`-Flag ändert Level NICHT.

### Schwellwerte (konfigurierbar)
- `BOEN_ALARM` (Standard 60 km/h): INCA + TAWES Wind-Alarm
- `REGEN_ALARM` (Standard 10 mm/h): INCA regen_alarm; TAWES-Alarm erst ab REGEN_ALARM/3

### TAWES RR Einheit: mm/10min (×6 = mm/h)
Die TAWES-API gibt RR in mm/10min zurück! `regen_upstream_mm` wird intern auf mm/h umgerechnet (×6) und mit REGEN_ALARM verglichen. Regen-Upstream-Detection-Threshold: 0.1 mm/10min ≈ 0.6 mm/h.

---

## ⚠️ Kritische Erkenntnisse (immer beachten)

### Config-Backup-Mechanismus
`preupgrade.sh` sichert Config nach `/tmp/unwetter4lox_cfg_upgrade.bak`.  
`postroot.sh` prüft `/tmp/` zuerst. LoxBerry löscht config/-Ordner beim Update!

### TAWES Startup-Fresh-Load
`_tawes_startup_fresh_done` Flag: Beim ersten Daemon-Start nach Neustart/Update wird der Station-Cache ignoriert und frisch von der API geladen. Verhindert veraltete IDs nach Plugin-Updates.

### ZIP-Erstellung (Windows)
Immer `build_zip.ps1 -Version X.X.X` verwenden. Nutzt .NET ZipFile API mit `.Replace([char]92, [char]47)` – Forward-Slashes für LoxBerry-kompatibles ZIP.  
**Ausschließen:** `.git/`, `__pycache__/`, `aimemory.md`, `*.zip`, `*.pyc`, `build_zip.ps1`

### LB_SDK Status
LoxBerry Python SDK (`loxberry`) nicht installiert auf dem Testsystem → Fallback-Logging aktiv. Kein Bug.

### TAWES-Netzrealität
In der Region des Users haben nur wenige Stationen aktive Böen-Sensoren. Viele TAWES-Stationen sind Klimastationen ohne Anemometer – FFX=None ist normal, kein Bug.

---

## 🛠️ Aktueller Fokus (Next Steps)
1. **Testen:** v0.4.17 ZIP auf LoxBerry installieren
2. **Validieren:** TAWES Stationen nach frischer Installation ohne manuelles Cache-Löschen vorhanden
3. **Validieren:** alarm_regen bleibt 0 bei Nieselregen wenn REGEN_ALARM=30mm/h

---

## 📋 Letzte Versionen
- **v0.4.17:** TAWES Startup-Fresh-Load, regen_upstream_mm (mm/h), Unit-Fix, Konsistenz-Audit
- **v0.4.16:** Notification-Dedup (Runden), REGEN_ALARM-Gate für bald_regen, Stations-Kontext in Wind-Notification
- **v0.4.15:** notification/tawes immer befüllt, Doppel-Publish-Bug behoben
- **v0.4.14:** pt_bald/pt_bald_name Topics, UI zeigt kommenden Niederschlagstyp
- **v0.4.13:** Alarm-Level-Schema vereinheitlicht (Gelb=1, Orange=2, Rot/Lila=3)
- **v0.4.12:** Alarm-Logik vollständig dokumentiert
