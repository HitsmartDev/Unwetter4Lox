# GEMINI.md – Unwetter4Lox

> Diese Datei enthält den vollständigen Projektkontext für die Arbeit mit Gemini.
> Bitte diese Datei zu Beginn jeder Session lesen.

---

## Was ist Unwetter4Lox?

LoxBerry-Plugin das österreichische Unwetterwarnungen von **GeoSphere Austria** (offizielle Wetterbehörde AT) und **INCA Nowcast**-Daten per **MQTT** an den **Loxone Miniserver** überträgt.

- **LoxBerry**: Raspberry Pi / x86 basiertes Heimautomations-Gateway (Perl/PHP/Python, Debian-basiert)
- **Loxone**: Österreichischer Hersteller von Heimautomations-Systemen
- **MQTT**: Messaging-Protokoll für IoT/Heimautomation
- **GeoSphere Austria**: Früher ZAMG – österreichische Wetterbehörde, kostenlose API

**GitHub-Repo:** https://github.com/HitsmartDev/Unwetter4Lox  
**Aktuell Version:** 0.1.0 (in Betrieb)

---

## Technische Architektur

```
GeoSphere Austria Warn-API      ──┐
(warnungen.zamg.at)               │
                                  ├──► Python 3 Daemon ──► paho-mqtt ──► MQTT Broker
GeoSphere INCA Nowcast API      ──┘   (Polling-Loop,              │
(dataset.api.hub.geosphere.at)         alle 300s)                  └──► Loxone Miniserver
                                              │
                                              └──► state.json (für Web-UI)
```

### Sprachen und Technologien
- **Python 3.8+**: Daemon-Logik (`bin/unwetter4lox_daemon.py`)
- **Perl**: LoxBerry-Bridge für Log-Sessions (`bin/loglevel.pl`)
- **Bash**: Daemon-Control, Install-Scripts
- **PHP**: Web-Frontend (LoxBerry-Standard: jQuery Mobile UI)
- **paho-mqtt**: MQTT Python-Bibliothek (kompatibel mit v1.x und v2.x)
- **loxberry**: Offizielles LoxBerry Python SDK (für Logging & MQTT Gateway Integration)

---

## Projektstruktur

```
Unwetter4Lox/
├── plugin.cfg                          # LoxBerry Plugin-Metadaten (Name, Version, Interface)
├── preinstall.sh                       # Läuft VOR Installation: Python-Version prüfen
├── postinstall.sh                      # Läuft NACH Installation (als loxberry User): paho-mqtt, Config
├── postroot.sh                         # Läuft NACH Installation (als root): Sudoers, chmod
├── create-plugin-zip.sh                # Build-Script für LoxBerry-ZIP (Linux/WSL)
├── CHANGELOG.md                        # Versionshistorie
├── README.md                           # Öffentliche Dokumentation
│
├── bin/
│   ├── unwetter4lox_daemon.py          # HAUPT-Daemon (Python)
│   └── loglevel.pl                     # Perl-Bridge: erstellt LoxBerry::Log Sessions
│
├── config/
│   └── unwetter4lox.cfg.default        # Standard-Konfiguration (wird bei Installation kopiert)
│
├── daemon/
│   └── daemon                          # Daemon-Control-Script (start|stop|restart|status)
│
├── uninstall/
│   └── uninstall                       # Deinstallations-Script
│
├── webfrontend/htmlauth/
│   ├── index.php                       # Status-Seite + Daemon-Steuerung
│   ├── settings.php                    # Einstellungen (Standort, MQTT, Intervall)
│   ├── log.php                         # Log-Viewer mit Session-Auswahl
│   └── ajax.php                        # Daemon start/stop/restart via sudo
│
├── templates/lang/
│   ├── language_de.ini                 # Deutsche Sprachdatei
│   └── language_en.ini                 # Englische Sprachdatei
│
├── icons/                              # Plugin-Icons (64/128/256/512px)
├── apt/apt.txt                         # APT-Pakete (python3-pip)
└── .github/workflows/release.yml      # GitHub Actions: ZIP bei git tag v*
```

---

## LoxBerry Plugin Framework – kritische Regeln

### 1. Keine hardcodierten Pfade – REPLACELBHOMEDIR verwenden

LoxBerry ersetzt vor der Installation folgende Strings in ALLEN Textdateien:

| Placeholder | Wird ersetzt durch |
|---|---|
| `REPLACELBHOMEDIR` | `/opt/loxberry` (oder anderer Installationspfad) |
| `REPLACELBPPLUGINDIR` | `unwetter4lox` (Plugin-Ordnername) |
| `REPLACELBPBINDIR` | `/opt/loxberry/bin/plugins/unwetter4lox` |
| `REPLACELBPCONFIGDIR` | `/opt/loxberry/config/plugins/unwetter4lox` |
| `REPLACELBPDATADIR` | `/opt/loxberry/data/plugins/unwetter4lox` |
| `REPLACELBPLOGDIR` | `/opt/loxberry/log/plugins/unwetter4lox` |

**Niemals** `/opt/loxberry` direkt in Quellcode schreiben – immer `REPLACELBHOMEDIR`.

### 2. LBPPLUGINDIR in postinstall.sh

LoxBerry setzt die Umgebungsvariable `LBPPLUGINDIR` in `postinstall.sh` **nicht**!  
→ Immer `REPLACELBPPLUGINDIR` als Literal verwenden (wird eingesetzt vor Ausführung).

### 3. Verzeichnis-Mapping (ZIP-Struktur → Installation)

```
daemon/daemon   → installiert als DATEI: system/daemons/plugins/unwetter4lox
uninstall/uninstall → installiert als DATEI: data/system/uninstall/unwetter4lox
bin/            → bin/plugins/unwetter4lox/
config/         → config/plugins/unwetter4lox/
webfrontend/htmlauth/ → webfrontend/htmlauth/plugins/unwetter4lox/
```

### 4. LoxBerry Log-Format

```
YYYY-MM-DD HH:MM:SS <OK>       Normaler Erfolg
YYYY-MM-DD HH:MM:SS <INFO>     Information  
YYYY-MM-DD HH:MM:SS <WARNING>  Warnung
YYYY-MM-DD HH:MM:SS <ERR>      Fehler
YYYY-MM-DD HH:MM:SS <CRIT>     Kritischer Fehler
YYYY-MM-DD HH:MM:SS <DEBUG>    Debug
YYYY-MM-DD HH:MM:SS <LOGSTART> Session-Name   ← Erste Zeile jeder Log-Session
YYYY-MM-DD HH:MM:SS <LOGEND>                  ← Letzte Zeile bei sauberem Exit
```

Jeder Daemon-Start erstellt eine neue Log-Session-Datei via `loglevel.pl` → `LoxBerry::Log`.

### 5. PHP-Frontend Konventionen

```php
// Pflicht-Includes (Reihenfolge beachten)
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";

// Verfügbare PHP-Globals ($lbhomedir, $lbpplugindir, $lbpconfigdir, $lbpdatadir, $lbplogdir)
// UI: jQuery Mobile (data-role="listview", data-role="collapsible", etc.)
// Header/Footer immer: LBWeb::lbheader() / LBWeb::lbfooter()
```

---

## Konfigurationsdatei (`unwetter4lox.cfg`)

```ini
[LOCATION]
LAT=47.952835       # Breitengrad (GPS)
LON=13.791286       # Längengrad (GPS)
NAME=Mein Zuhause

[MQTT]
USE_LOXBERRY_MQTT=1 # 1=Auto aus LoxBerry Gateway, 0=manuell
BROKER=127.0.0.1
PORT=1883
USER=
PASS=
TOPIC_PREFIX=unwetter   # Standard MQTT-Präfix

[SCHEDULE]
INTERVAL=300        # Abfrageintervall in Sekunden

[THRESHOLDS]
BOEN_ALARM=60       # Böen-Alarmschwelle km/h für INCA bald_sturm_*

[INCA]
ENABLED=1
HORIZON_MINUTES=60  # Vorausschau-Fenster für Max-Böen

[NOTIFICATIONS]
MIN_STUFE=1         # Mindeststufe für Notification-Text (1=Gelb, 2=Orange, 3=Rot)
```

---

## MQTT Topics (Präfix: `unwetter`)

### GeoSphere Warnungen
```
unwetter/warnung/wind/stufe             0-4 (0=keine, 1=Gelb, 2=Orange, 3=Rot, 4=Lila)
unwetter/warnung/wind/aktiv             0/1
unwetter/warnung/wind/bald              0/1 (Warnung beginnt in <30 min)
unwetter/warnung/wind/start_text        z.B. "heute 14:00"
unwetter/warnung/wind/end_text          z.B. "morgen 08:00"
unwetter/warnung/wind/notification      Fertigtext für Loxone Push
```
Gleiches Schema für: `regen`, `schnee`, `glatteis`, `gewitter`, `hagel`, `hitze`, `kaelte`

```
unwetter/warnung/akutwarnung            0/1 (GWA Stationswarnung)
unwetter/warnung/max_stufe              0-4
unwetter/warnung/irgendwas_aktiv        0/1
```

### INCA Nowcast
```
unwetter/inca/boen_jetzt_kmh
unwetter/inca/wind_jetzt_kmh
unwetter/inca/boen_max_30min
unwetter/inca/boen_max_60min
unwetter/inca/niederschlag_jetzt        mm/h
unwetter/inca/niederschlag_typ          255=kein, 1=Regen, 2=Schnee, 3=Schneeregen, 4=Graupel, 5=Hagel
unwetter/inca/niederschlag_typ_name
unwetter/inca/bald_regen                0/1 (<30 min)
unwetter/inca/bald_hagel                0/1 (<60 min)
unwetter/inca/bald_graupel              0/1 (<60 min)
unwetter/inca/bald_sturm_30min          0/1
unwetter/inca/bald_sturm_60min          0/1
unwetter/inca/minuten_bis_regen         Zahl, -1 wenn kein Regen geplant
```

### Notifications & Status
```
unwetter/notification/geosphere         Alle GeoSphere-Warnungen als Text
unwetter/notification/inca              INCA-Zusammenfassung
unwetter/notification/alle              Kombination
unwetter/notification/neu_geosphere     1 wenn neue Warnungen (retain=false, Puls)
unwetter/notification/entwarnung        Text bei Entwarnung (retain=false)
unwetter/letztes_update                 Zeitstempel
```

---

## Wichtige externe APIs

### GeoSphere Austria Warn-API
```
https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lon={LON}&lat={LAT}&lang=de
```
- Kostenlos, kein API-Key
- Gibt aktive Warnungen für GPS-Koordinaten zurück
- Warntypen: 1=Wind, 2=Regen, 3=Schnee, 4=Glatteis, 5=Gewitter, 6=Hitze, 7=Kälte
- Warnstufen: 1=Gelb, 2=Orange, 3=Rot, 4=Lila
- `warnid` mit Präfix `gwa` = Akutwarnung/Stationswarnung

### GeoSphere INCA Nowcast
```
https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km
    ?lat_lon={LAT}%2C{LON}&parameters={param}&output_format=geojson
```
- Kostenlos, kein API-Key
- Parameter: `ff` (Windmittel), `fx` (Böen), `rr` (Niederschlag), `pt` (Niederschlagstyp)
- 15-Minuten-Auflösung, ca. 60 Minuten Vorausschau

---

## Release-Prozess

```bash
# 1. plugin.cfg: VERSION= erhöhen
# 2. CHANGELOG.md aktualisieren
# 3. Commit + Push
git add -A && git commit -m "release: vX.Y.Z – Beschreibung"
git push
# 4. Tag → GitHub Actions baut ZIP + erstellt GitHub Release automatisch
git tag vX.Y.Z
git push origin vX.Y.Z
```

Lokal ZIP bauen (Windows PowerShell, da .zip ignoriert wird im Repo):
- Über das PowerShell-Skript im Gespräch mit AI erzeugen (via .NET ZipFile)
- Oder auf Linux/WSL: `./create-plugin-zip.sh`

---

## LoxBerry Developer Dokumentation

> Wiki ist hinter LoxBerry-Login. Stefan hat Zugang.

| Thema | URL |
|---|---|
| Grundlagen Plugin-Entwicklung | https://wiki.loxberry.de/entwickler/grundlagen_zur_erstellung_eines_plugins |
| Advanced Developers | https://wiki.loxberry.de/entwickler/advanced_developers/start |
| Entwickler-Tipps & Tricks | https://wiki.loxberry.de/entwickler/entwicker_tipps_und_tricks/start |
| Bash Supporting Scripts | https://wiki.loxberry.de/entwickler/bash_supporting_scripts_for_your_plugin_development/start |
| Web UI Development | https://wiki.loxberry.de/entwickler/web_ui_development_in_loxberry/start |
| Entwickler Übersicht | https://wiki.loxberry.de/entwickler/start |
| Python Plugin Entwicklung | https://wiki.loxberry.de/entwickler/python_develop_plugins_with_python |
| Plugin entwickeln ab v1.x | https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/start |
| PHP Plugin Entwicklung | https://wiki.loxberry.de/entwickler/php_develop_plugins_with_php/start |
| Perl Plugin Entwicklung | https://wiki.loxberry.de/entwickler/perl_develop_plugins_with_perl/start |

**Referenz-Plugin (Aloxberry – sehr ähnliche Struktur):**  
https://github.com/Grestorn/loxberry-plugin-aloxberry

---

## Debugging

```bash
# Daemon manuell starten (Fehler direkt sichtbar)
sudo /opt/loxberry/system/daemons/plugins/unwetter4lox start

# Aktives Log live verfolgen
tail -f $(cat /opt/loxberry/log/plugins/unwetter4lox/daemon.log.current)

# Python-Daemon direkt testen
LBHOMEDIR=/opt/loxberry LBPPLUGINDIR=unwetter4lox \
    python3 /opt/loxberry/bin/plugins/unwetter4lox/unwetter4lox_daemon.py

# paho-mqtt Version prüfen
python3 -c "import paho.mqtt; print(paho.mqtt.__version__)"

# Alle Log-Sessions anzeigen
ls -lth /opt/loxberry/log/plugins/unwetter4lox/
```

---

## Offene Punkte / Ideen

- [ ] Mehrsprachigkeit im Web-Frontend ausbauen (language_en.ini aktuell minimal)
- [ ] Automatischer Update-Check via `RELEASECFG` in plugin.cfg aktivieren
- [ ] MQTT Reconnect-Logik verbessern (aktuell: einmaliger Connect beim Start)
- [ ] Testen mit echten Wetterwarnungen (aktuell: Betrieb läuft, aber noch keine reale Warnung aufgetreten)
