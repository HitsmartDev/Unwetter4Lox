# Changelog – Unwetter4Lox

Alle relevanten Änderungen werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

---

## [0.4.17] – 2026-06-09

### Behoben
- TAWES Stationen nach Installation fehlend: Daemon lädt beim ersten Start nach Plugin-Update die Stationsliste jetzt immer frisch von der API (Cache-File wird beim Daemon-Start einmalig ignoriert). Verhindert Race-Condition zwischen postinstall.sh und Daemon-Start.
- `alarm/regen` Level 1 feuerte bei Nieselregen obwohl REGEN_ALARM=30 mm/h gesetzt: TAWES `regen_upstream` löst jetzt nur einen Alarm aus wenn die Upstream-Intensität (`tawes/regen_upstream_mm`) ≥ REGEN_ALARM/3 ist.
- Unit-Bug: TAWES RR war in mm/10min, `regen_upstream_mm` wurde ohne ×6-Faktor mit REGEN_ALARM (mm/h) verglichen (bei hohen Schwellen durch Zufall korrekt, bei 2 mm/h falsch).

### Hinzugefügt
- Neues MQTT-Topic `tawes/regen_upstream_mm` (mm/h): Max. Regenintensität an Upstream-Stationen – für Diagnose und als Alarm-Gate
- UI zeigt Upstream-Regenintensität in der Regenfront-Zeile an (z.B. "Ankunft unbekannt (3.6 mm/h)")

### Dokumentation
- README + help.php: Alarm/regen-Tabelle mit TAWES Intensitäts-Gate (REGEN_ALARM/3) ergänzt
- README + help.php: `tawes/regen_upstream_mm` als neues Topic dokumentiert
- Regen-Alarmschwelle Beschreibung erklärt TAWES-Threshold-Beziehung
- help.php FAQ: Startup-Fresh-Load Verhalten bei TAWES-Stationen erklärt
- Versionsstrings im Daemon korrigiert (waren noch v0.4.5)

---

## [0.4.16] – 2026-06-09

### Behoben
- Notification-Spam: ETA-Werte und Windstärken werden auf 5er-Schritte gerundet bevor Dedup-Vergleich (verhindert Push alle 5 min durch reine Countdown-Änderung)
- `bald_regen`-Notification nur noch wenn REGEN_ALARM ≤ 2.0 mm/h oder `regen_alarm=1` aktiv – verhindert Spam bei hoher Alarmschwelle
- TAWES Wind-Notification zeigt jetzt Stationsname und Distanz: "💨 Sturmböen Rax (78km): 90 km/h" statt nur "90 km/h"

---

## [0.4.15] – 2026-06-08

### Behoben
- `notification/tawes` wurde doppelt publiziert (in `publish_tawes()` ohne Dedup + in `publish_all()` mit Dedup) – Duplikat entfernt
- `notification/tawes` zeigt jetzt "keine aktiven Warnungen" wenn leer (wie geosphere und inca)

### Dokumentation
- README + help.php: alle MQTT Topics vollständig dokumentiert mit Wertebereichen

---

## [0.4.14] – 2026-06-08

### Hinzugefügt
- `inca/pt_bald` und `inca/pt_bald_name`: Niederschlagstyp des nächsten erwarteten Regens (z.B. "Regen" wenn aktuell trocken aber in 8 min Regen kommt)
- UI zeigt "kein Niederschlag → Regen in ~8 min" wenn pt_jetzt=255 aber Regen vorhergesagt

### Behoben
- INCA-UI zeigte "kein Niederschlag" und "Regen in ~8 min" gleichzeitig – war kein Bug, aber verwirrend. Neue UI-Logik erklärt den Zusammenhang.

---

## [0.4.13] – 2026-06-07

### Geändert (Breaking Change – intentional)
- Alarm-Level-Schema komplett vereinheitlicht: **ZAMG Gelb→1, Orange→2, Rot/Lila→3** für alle 5 Kategorien (Gewitter, Wind, Regen, Hagel, Schnee)
- `aktiv`-Flag ändert den Level nicht mehr (war früher teilweise +1)
- INCA und TAWES können auf max. Level 2 anheben (niemals 3)
- Schnee/Glatteis-Berechnung hatte alten Bug der ZAMG-Stufen (0-4) als Alarm-Level interpretierte

### Dokumentation
- help.php + README: vollständige Alarm-Berechnungstabellen je Kategorie mit Level-Prinzip-Erklärung

---

## [0.4.12] – 2026-06-07

### Dokumentation
- help.php: Alarm-Logik, Wind/Regen-Schwellwerte, Quellenübersicht vollständig dokumentiert
- README: Alle Abschnitte auf aktuellen Stand gebracht

---

## [0.4.11] – 2026-06-07

### Behoben
- Miniserver-Koordinaten-Abruf über HTTPS (Loxone 14+ erzwingt HTTPS)
- Alarm-Schwellen: Wind-Level war bei hohem BOEN_ALARM nicht konsistent

---

## [0.1.5] – 2026-06-05

### Behoben
- MQTT rc=5: mqttgateway.json hat verschachtelte Struktur – Credentials sind unter `Main`-Key, nicht auf oberster Ebene
- Credential-Suche durchsucht jetzt alle Sub-Dicts der Config (Main, Gateway, etc.) nicht nur Top-Level
- Debug-Log zeigt jetzt auch Keys aller Sub-Dicts für vollständige Diagnose

## [0.1.4] – 2026-06-05

### Behoben
- MQTT rc=5: Wurzelursache gefunden – LoxBerry speichert JSON-Keys großgeschrieben (`Brokeruser`, `Brokerpass`, `Brokerhost`, `Brokerport`), Code suchte nur lowercase → Credentials wurden nie gelesen
- MQTT Credential-Lookup jetzt case-insensitiv (funktioniert mit Groß- und Kleinschreibung)
- Suchreihenfolge optimiert: Credentials zuerst direkt in der Haupt-Config suchen (LoxBerry Standard), dann in separaten Dateien
- Debug-Log zeigt jetzt die tatsächlich vorhandenen JSON-Keys der Config-Datei für einfachere Fehlerdiagnose

## [0.1.3] – 2026-06-05

### Behoben
- MQTT rc=5: Erweiterte Suche nach LoxBerry MQTT Credentials in allen bekannten Pfaden und Key-Varianten (cred.json, mqttgatewaycred.json, credentials.json; brokeruser/user/username/mqttuser etc.)
- MQTT: Manueller User/Pass-Override in `unwetter4lox.cfg` wirkt jetzt auch bei `USE_LOXBERRY_MQTT=1` – Workaround wenn Auto-Erkennung fehlschlägt
- MQTT: Detailliertes Logging welche Config-Datei gefunden/nicht gefunden wurde + Hinweis wenn keine Credentials gefunden
- Config-Default: Dokumentation für manuellen Credential-Override direkt in der cfg-Datei

## [0.1.2] – 2026-06-05

### Behoben
- MQTT: Race Condition behoben – Daemon wartete nicht auf erfolgreichen TCP-Verbindungsaufbau bevor Topics publiziert wurden (QoS 0 hat Messages verworfen)
- MQTT: `on_connect` Callback mit klarer Fehlerdiagnose (rc-Code + Klartext) hinzugefügt
- MQTT: `on_disconnect` Callback für automatischen Reconnect im Hauptloop
- MQTT: `publish()` prüft nun ob Verbindung aktiv ist (`_mqtt_connected` Event) statt nur ob Client-Objekt existiert
- MQTT: Verbindungs-Timeout nach 8s mit Log-Meldung statt stillem Fehlschlag

## [0.1.1] – 2026-06-05

### Behoben
- MQTT-Verbindung: Umstellung auf das offizielle LoxBerry Python SDK (`loxberry.mqtt`). Dies behebt Probleme mit dem LoxBerry MQTT Gateway unter LoxBerry 3.0 (korrekte Port- und Credential-Erkennung).
- Logging: Umstellung auf `loxberry.log`. Die Log-Sessions werden nun korrekt in der LoxBerry-Datenbank registriert und im Standard-LogViewer farbig angezeigt.
- Bereinigung der Perl-Log-Bridge, um doppelte `<LOGEND>` Tags zu vermeiden.

## [0.1.0] – 2026-06-05

### Neu
- GeoSphere Austria Warn-API Integration (Windwarnungen, Gewitter, Regen, Schnee, Glatteis, Hitze, Kälte, Hagel)
- INCA Nowcast via GeoSphere Dataset API (Böen, Windgeschwindigkeit, Niederschlagstyp, Hagel/Graupel-Alarm)
- MQTT-Veröffentlichung aller Warnwerte über LoxBerry MQTT Gateway oder manuellem Broker
- Automatische Erkennung der LoxBerry MQTT Gateway Konfiguration
- Web-Frontend: Status-Seite mit Daemon-Steuerung, GeoSphere-Warnlage und INCA-Werten
- Web-Frontend: Einstellungsseite (Standort, MQTT, Intervall, Böen-Schwellwert, Notifications)
- Web-Frontend: Log-Viewer mit LoxBerry-kompatiblem Log-Format
- Notification-Texte für Loxone Push (`notification/alle`, `/geosphere`, `/inca`)
- Entwarnung-Topic wenn alle Warnungen aufgehoben werden

### Behoben
- Daemon-Start-Fehler: `LBHOMEDIR` wird nun robust aus `/etc/environment` gelesen (sudo strippt Env-Vars)
- Log-Verzeichnis wird vor Python-Start sichergestellt
- Kein doppeltes Logging mehr (FileHandler statt StreamHandler+Redirect)
- paho-mqtt >= 2.0 Kompatibilität (CallbackAPIVersion)

### Hinzugefügt
- `preinstall.sh` – Python3-Versionscheck vor Installation
- `uninstall/uninstall` – saubere Deinstallation (Daemon stoppen, Sudoers entfernen)
- `create-plugin-zip.sh` – Build-Script für LoxBerry-installierbare ZIP-Datei
