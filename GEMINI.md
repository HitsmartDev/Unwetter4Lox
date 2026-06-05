# GEMINI.md – Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria API + INCA Nowcast) → MQTT → Loxone Miniserver.

---

## Sync-Instruktionen

1. **Beim Start:** `aimemory.md` lesen – dort steht der aktuelle Projektzustand, offene Probleme und nächste Schritte.
2. **Beim Abschluss / Wechsel zu Claude:** `aimemory.md` aktualisieren – Version, was geändert wurde, was als nächstes ansteht, offene Probleme.
3. **Architektur-Entscheidungen** nicht ändern, ohne es in `aimemory.md` zu dokumentieren.

---

## Projektstruktur

```
Unwetter4Lox/
├── plugin.cfg                          # LoxBerry Plugin-Metadaten (Name, Version, Interface)
├── preinstall.sh                       # Läuft VOR Installation: Python-Version prüfen
├── postinstall.sh                      # Läuft NACH Installation (als loxberry User): paho-mqtt, Config
├── postroot.sh                         # Läuft NACH Installation (als root): Sudoers, chmod
├── CHANGELOG.md
├── aimemory.md                         # ← Shared AI State – immer lesen/schreiben
├── bin/
│   ├── unwetter4lox_daemon.py          # HAUPT-Daemon (Python 3.8+)
│   └── loglevel.pl                     # Perl-Bridge: erstellt LoxBerry::Log Sessions
├── config/
│   └── unwetter4lox.cfg.default        # Standard-Konfiguration
├── daemon/daemon                       # Daemon-Control-Script (start|stop|restart|status)
├── uninstall/uninstall                 # Deinstallations-Script
├── webfrontend/htmlauth/
│   ├── index.php                       # Status-Seite + Daemon-Steuerung
│   ├── settings.php                    # Einstellungen (Standort, MQTT, Intervall)
│   ├── log.php                         # Log-Viewer mit Session-Auswahl
│   └── ajax.php                        # Daemon start/stop/restart via sudo
├── templates/lang/
│   ├── language_de.ini
│   └── language_en.ini
├── icons/
├── apt/apt.txt
└── .github/workflows/release.yml      # ZIP bei git tag v*
```

---

## Tech-Stack

- **Python 3.8+** – Daemon-Logik
- **paho-mqtt** – MQTT Client (kompatibel v1.x + v2.x, `try/except AttributeError` für `CallbackAPIVersion`)
- **Perl** – LoxBerry Log-Bridge
- **PHP** – Web-Frontend (jQuery Mobile UI, LoxBerry-Standard)
- **Bash** – Daemon-Control, Install-Scripts

---

## Konfigurationsdatei (`unwetter4lox.cfg`)

```ini
[LOCATION]
LAT=47.952835
LON=13.791286
NAME=Mein Zuhause

[MQTT]
USE_LOXBERRY_MQTT=1   # 1=Auto aus LoxBerry Gateway, 0=manuell
BROKER=127.0.0.1
PORT=1883
USER=                 # leer = auto; bei rc=5 hier manuell eintragen
PASS=
TOPIC_PREFIX=unwetter

[SCHEDULE]
INTERVAL=300

[THRESHOLDS]
BOEN_ALARM=60         # km/h Böen-Alarmschwelle für INCA bald_sturm_*

[INCA]
ENABLED=1
HORIZON_MINUTES=60

[NOTIFICATIONS]
MIN_STUFE=1           # 1=Gelb, 2=Orange, 3=Rot
```

---

## Offene Ideen

- [ ] Mehrsprachigkeit Web-Frontend ausbauen (language_en.ini aktuell minimal)
- [ ] Automatischer Update-Check via `RELEASECFG` in plugin.cfg aktivieren
- [ ] Testen mit echten Wetterwarnungen
