# Changelog – Unwetter4Lox

Alle relevanten Änderungen werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

---

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
