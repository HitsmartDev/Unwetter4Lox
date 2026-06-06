## 📌 Projekt-Status
- **Version:** 0.2.2 (Bugfix-Release)
- **Letzter Stand (Claude, 2026-06-06):**
  1. **load_state/save_state** implementiert – fehlten komplett, Hauptloop crashte alle 5 min mit `name 'load_state' is not defined`
  2. **Log-Level-Fix** – LoxBerry syslog-Level (0-7) korrekt auf Python logging gemappt: Level 3=ERROR, 4=WARNING, 6=INFO, 7=DEBUG. Vorher: alles auf INFO gesetzt.
  3. **daemon.log.current Pointer** – wird jetzt auch im Fallback-Modus (LB_SDK=False) geschrieben, damit PHP-Frontend das aktuelle Log findet.
  4. **Log-Viewer Fix** – `logfile_button_html` in `index.php` + `log.php` übergibt jetzt `LOGFILE` direkt (aus daemon.log.current). Ohne das blieb `logfile=` leer weil Log nicht in LoxBerry SDK DB registriert (LB_SDK=False).
  5. **Log-Tab Sortierung** – nach `filemtime` statt Dateiname → neueste Session immer zuerst.
  6. **Terminal-Font** – hardcoded `'Courier New', Courier, monospace` in CSS; `TERMINAL_FONT` aus Language-Files entfernt (Language-Key wurde HTML-encoded im CSS → kaputte Schrift).
- **Aktiv bearbeitet von:** Claude

## 🛠️ Aktueller Fokus (Next Steps)
1. **Testen:** Plugin auf LoxBerry neu installieren (ZIP: `unwetter4lox.zip` lokal vorhanden).
2. **Validieren:** Daemon starten, Log-Viewer prüfen, Log-Level-Filter testen.
3. **Release:** Bei Stabilität GitHub Tag `v0.2.2` erstellen.

## ⚠️ Offene Probleme & Erkenntnisse
- **LB_SDK = False:** Auf dem Testsystem ist die LoxBerry Python SDK nicht verfügbar (Import schlägt fehl). Deshalb nutzt der Daemon den Fallback-Logger. Alle Fixes wurden darauf ausgerichtet, dass der Fallback vollständig LoxBerry-konform funktioniert.
- **LoxBerry Python SDK Pfad:** Eventuell ist das `loxberry` Python-Paket nicht installiert oder nicht im Python-Pfad. Falls LB_SDK aktiviert werden soll: `pip3 install loxberry` auf dem LoxBerry prüfen.
- **logfile_button_html LOGFILE-Parameter:** Ist laut LoxBerry Perl-Source Pflicht (oder zumindest der bevorzugte Weg). Ohne diesen Parameter wird die Log-DB abgefragt – bei LB_SDK=False immer leer.
- **MQTT Topics:** Bestehende Loxone-Konfigurationen müssen von `warnung/` auf `zamg/` angepasst werden (seit v0.2.1).
