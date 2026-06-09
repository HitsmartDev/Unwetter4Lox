## 📌 Projekt-Status
- **Version:** 0.4.17
- **Letzter Stand (Claude, 2026-06-09):** Committed. Zwei kritische Bugs gefixt.

### Abgeschlossen v0.4.4–v0.4.8:

**v0.4.4 – Miniserver-Koordinaten**
- Geocoding: Von `/jdev/cfg/api` (liefert keine Location) auf `/data/LoxApp3.json` gewechselt
- Range-Request (erste 16KB) – `msInfo.location` ist immer am Dateianfang
- Direkte GPS-Koordinaten aus `msInfo.latitude/longitude` wenn vorhanden
- strlen-Bug für kurze Ortsnamen (z.B. "Wien") behoben

**v0.4.5 – Logging, Daemon-Status, Autostart**
- Separate Last-Update-Timestamps per Quelle (ZAMG/INCA/TAWES) in state.json + UI
- INFO-Logging pro Datenquelle (Stationsanzahl, Erreichbarkeit)
- PID-Check via `/proc/{$pid}/cmdline` (verhindert False-Positive nach Reboot)
- `postinstall.sh`: Daemon-Autostart nach Update wenn LAT/LON konfiguriert
- `postinstall.sh`: `@reboot`-Cronjob (90s Delay, idempotent via Marker-Kommentar)

**v0.4.6 – Auto-Refresh**
- `ajax.php`: `check_update`-Action gibt `letzter_abruf_epoch` zurück
- `index.php`: JavaScript pollt alle 30s, reload nur wenn Epoch sich geändert hat

**v0.4.7 – Log-Kosmetik**
- `FFX=Nonekm/h` → `FFX=–km/h` fix: `or` statt default-Wert für None-Prüfung
- Sensor-Count-Log: Info wenn 0 Böen-Sensoren oder weniger als 1/3 Stationen

**v0.4.8 – TAWES ID-Mismatch Fix**
- Root Cause: `str(props.get('station', ...))` wenn `props['station']=None` → `str(None)` = `"None"` (literal)
- `load_tawes_stations()`: `str(p.get('id') or p.get('station_id') or s.get('id') or '')` + `if not sid or sid == 'None': continue`
- `fetch_tawes_data()`: `requested_norm`-Mapping int↔string, `api_sid = str(props.get('station') or props.get('id') or feature.get('id') or '')`
- `fetch_tawes_data()`: Warnung wenn ungematchte API-IDs nach Datenabruf
- `correlate_tawes()`: ID-Match-Diagnose (Warnung wenn <50% der Stationen gefunden)

## ⚠️ Config-Backup Mechanismus (KRITISCH)
`preupgrade.sh` sichert die Config nach `/tmp/unwetter4lox_cfg_upgrade.bak` – NICHT in den config/-Ordner selbst!
Grund: LoxBerry löscht beim Plugin-Update den gesamten config/-Ordner und legt ihn neu an.
`postroot.sh` prüft `/tmp/` als erstes, dann config/-Ordner (legacy), dann erst Default.

## 🗜️ ZIP-Erstellung (WICHTIG – Windows-Kompatibilität)
Das ZIP muss mit .NET ZipFile API erstellt werden, NICHT mit PowerShell Compress-Archive (Windows-Backslashes!).
Pflicht-Methode in PowerShell:
```powershell
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::Open($zipOut, [System.IO.Compression.ZipArchiveMode]::Create)
# Für jeden Dateipfad:
$rel = $file.Substring($root.Length + 1).Replace([char]92, [char]47)   # Backslash → Forward-Slash!
$entry = $archive.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal)
```
**Ausschließen:** `.git/`, `__pycache__/`, `aimemory.md`, `*.zip`, `*.pyc`
**Grund:** LoxBerry `unzip` auf Linux erwartet Forward-Slashes.

## 🛠️ Aktueller Fokus (Next Steps)
1. **Testen:** Plugin auf LoxBerry installieren (v0.4.17 ZIP)
2. **Validieren:** TAWES Stationen nach Installation ohne manuelles Cache-Löschen
3. **Validieren:** alarm_regen bei leichtem Regen bleibt 0 wenn REGEN_ALARM=30mm/h

## ⚠️ Offene Probleme & Erkenntnisse
- **TAWES Stations-Cache (wiederkehrend, v0.4.17 gefixt):** Race-Condition – Daemon lädt alten Cache bevor postinstall.sh ihn löschen kann. Fix: `_tawes_startup_fresh_done` Flag → erster Daemon-Start immer frischer API-Abruf.
- **alarm_regen trotz hoher Schwelle (v0.4.17 gefixt):** TAWES regen_upstream prüfte nicht die Intensität – jede Upstream-Station mit RR>0.1mm löste Level 1 aus. Fix: `regen_upstream_mm` (max. Upstream-Intensität) messen und in build_alarm gegen `REGEN_ALARM/3` prüfen.
- **Alarm-Level Schema (v0.4.13):** ZAMG Gelb→1, Orange→2, Rot/Lila→3. INCA/TAWES max. Level 2. Konsistent über alle 5 Kategorien.
- **Notification Dedup (v0.4.16):** ETA/Wind auf 5er-Schritte gerundet, REGEN_ALARM gate für bald_regen.
- **TAWES-Netzrealität:** In der Region des Users haben nur wenige Stationen aktive Wind/Regen-Sensoren (Klimastationen ohne Anemometer) – kein Bug.
- **LB_SDK = False:** LoxBerry Python SDK nicht installiert → Fallback-Modus aktiv.
- **ZIP auf Windows:** Immer `.Replace([char]92, [char]47)` auf relativen Pfad – sonst Installation fehl.
