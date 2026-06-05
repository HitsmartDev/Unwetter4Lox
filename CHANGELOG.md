# Changelog – Unwetter4Lox

Alle relevanten Änderungen werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

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
