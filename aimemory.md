## 📌 Projekt-Status
- **Version:** 0.4.2 (Feature Release)
- **Letzter Stand (Claude, 2026-06-07):**

### Abgeschlossen in v0.4.0:
1. **TAWES 360° Bugfixes (Gemini-Review):**
   - Windrichtung: Vektorgewichteter Durchschnitt (sin/cos, korrekte Kreisstatistik) statt Median
   - Linreg: None-Werte werden intern gefiltert, min. 4 Datenpunkte erforderlich
   - Gewitter Level 2 (Akut): zusätzlich wenn FFX-Slope > 3.0 km/h/10min
2. **Aggregierter Gesamtstatus (`build_alarm()` + `publish_alarm()`):**
   - Kombiniert ZAMG + INCA + TAWES zu einheitlichen Alarm-Leveln (0-3)
   - Topics: `alarm/gewitter`, `alarm/wind`, `alarm/regen`, `alarm/hagel`, `alarm/schnee`, `alarm/stufe`, `alarm/zusammenfassung`
   - In `state.json` unter Key `alarm` gespeichert
3. **MQTT Topics bereinigt:** Doppeltes `letztes_update` entfernt, doppelte Notifications behoben
4. **UI Gesamtstatus-Block** (index.php): 5-Kategorien-Grid mit Farbkodierung (grün/gelb/orange/rot)
5. **"Vom Miniserver" Button** (settings.php + ajax.php): LoxBerry general.json → Loxone `/jdev/cfg/api` → Nominatim
6. **Default-Werte** in Settings beschriftet: `(Standard: 300s)`, `(Standard: 60 min)`, `(Standard: 60 km/h)`
7. **UI-Überarbeitung** (index.php): intuitive Spaltenheader, Block-Beschriftungen, Legende, TAWES-Tooltips
8. **help.php** komplett neu: alle 3 APIs dokumentiert, vollständige MQTT-Tabellen mit Wertebereichen
9. **README.md** auf v0.4.0 aktualisiert: Plugin-Beschreibung, alle APIs, vollständige MQTT-Referenz
10. **ZIP-Fix:** Forward-Slashes in ZIP-Einträgen (Windows→Linux-Kompatibilität)
11. **Sprachdateien** (DE+EN): TAWES_*, ALARM_*, MINISERVER_*, LABEL_DEFAULT Keys

- **Aktiv bearbeitet von:** Claude
- **Status:** Alle Dateien geschrieben, noch kein Commit/ZIP

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
**Einzuschließende Ordner:** `bin/`, `config/`, `daemon/`, `icons/`, `templates/`, `webfrontend/`
**Einzuschließende Dateien:** `plugin.cfg`, `postroot.sh`, `preupgrade.sh`, `CHANGELOG.md`
**Ausschließen:** `*.txt`, `*.bak`, `*.pyc`, `__pycache__`
**Grund:** LoxBerry `unzip` auf Linux erwartet Forward-Slashes. Windows-ZIP-Tools schreiben Backslashes → "appears to use backslashes as path separators" → CRITICAL install failure.

## 🛠️ Aktueller Fokus (Next Steps)
1. **Testen:** Plugin auf LoxBerry installieren, Daemon starten, MQTT prüfen
2. **Validieren:** Gesamtstatus-Block, REGEN_ALARM-Schwelle, Miniserver-Button testen

## ⚠️ Offene Probleme & Erkenntnisse
- **LB_SDK = False:** LoxBerry Python SDK nicht installiert → Fallback-Modus aktiv. Alle Features laufen ohne SDK.
- **TAWES API:** GeoSphere Austria `dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min` – Station IDs als wiederholte `station_ids=` Parameter (NICHT Komma-getrennt).
- **Regen-ETA Genauigkeit:** Erfordert ≥2 Upstream-Stationen mit Regen im Buffer für Frontgeschwindigkeit. Sonst ETA=-1.
- **ZIP auf Windows:** Immer `.Replace('\', '/')` auf relativen Pfad anwenden vor `$archive.CreateEntry()` – sonst schlägt LoxBerry-Installation fehl.
- **logfile_button_html LOGFILE-Parameter:** Pflicht wenn LB_SDK=False (Log nicht in DB registriert).
- **MQTT Topics Änderungen seit v0.2.1:** `warnung/` → `zamg/`, neue Topics `tawes/`, `alarm/`, `notification/tawes`.
