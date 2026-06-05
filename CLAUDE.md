# CLAUDE.md – Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria API + INCA Nowcast) → MQTT → Loxone Miniserver.

---

## Projektstatus (Stand: 2026-06-05)

**Phase:** Aktiv in Betrieb – erste Installation läuft beim Entwickler Stefan  
**Version:** 0.1.0 (getestet, Daemon startet, Log erscheint im LoxBerry Log-Viewer)  
**Repo:** https://github.com/HitsmartDev/Unwetter4Lox  
**Nächster Schritt:** Weitere Tests, dann v0.2.0 Release via `git tag v0.2.0 && git push origin v0.2.0`

---

## Was bereits umgesetzt ist

### Funktionalität
- GeoSphere Austria Warn-API: Wind, Regen, Schnee, Glatteis, Gewitter, Hagel, Hitze, Kälte
- INCA Nowcast: Böen, Wind, Niederschlagstyp, Hagel/Graupel-Alarm (<30/60 min)
- MQTT-Veröffentlichung über LoxBerry MQTT Gateway (auto) oder manuellen Broker
- ~40 MQTT Topics mit Retain; Notification-Texte für Loxone Push
- Web-Frontend: Status, Einstellungen, Log-Viewer (mit Session-Auswahl)

### LoxBerry-Konformität (alles implementiert)
- `REPLACELBHOMEDIR` / `REPLACELBPPLUGINDIR` in allen Scripts – keine hardcodierten Pfade
- LoxBerry::Log Perl-Bridge (`bin/loglevel.pl`) für Sessions wie in anderen Plugins
- Pro Daemon-Start eine neue Log-Session-Datei (erscheint im LoxBerry System-Log-Manager)
- `daemon.log.current` Pointer-Datei (wie aloxberry-Plugin)
- SIGTERM/SIGINT Handler schreibt `<LOGEND>` vor Exit
- Log-Format: `YYYY-MM-DD HH:MM:SS <OK|WARNING|ERR|CRIT|DEBUG> Nachricht`
- Sudoers via `postroot.sh` unter `/etc/sudoers.d/unwetter4lox`
- `preinstall.sh` prüft Python 3.8+
- `uninstall/uninstall` stoppt Daemon, entfernt Sudoers
- GitHub Actions: automatische ZIP bei `git tag v*`

### Behobene Bugs (v0.1.0)
| Bug | Ursache | Fix |
|---|---|---|
| Daemon startete nicht | `sudo` strippt Env-Vars → `LBHOMEDIR` leer | `REPLACELBHOMEDIR` wird zur Installationszeit eingesetzt |
| `LBPPLUGINDIR` leer in postinstall | LoxBerry setzt diese Var nicht in postinstall | `REPLACELBPPLUGINDIR` Placeholder |
| Log-Datei nicht vorhanden | Pfade alle relativ/falsch | Daemon erstellt Log-Dir, LoxBerry::Log Session |
| Doppeltes Logging | FileHandler + stdout-Redirect | Nur FileHandler, kein stdout-Redirect |
| paho-mqtt >= 2.0 API-Break | `CallbackAPIVersion` Pflicht | `try/except AttributeError` für beide Versionen |
| Hardcoded-Path-Warning | `/opt/loxberry` literal im Code | `REPLACELBHOMEDIR` Placeholder |

---

## Technische Architektur

```
GeoSphere Austria Warn-API  ──┐
(warnungen.zamg.at)           ├──► Python Daemon ──► paho-mqtt ──► MQTT Broker
GeoSphere INCA Nowcast API  ──┘   (5-min Loop)          │          (LoxBerry Gateway
(dataset.api.hub.geosphere.at)         │                  └──► Loxone Miniserver
                                       └──► state.json (Zustandsspeicher für Web-UI)
```

### Dateipfade nach LoxBerry-Installation
```
/opt/loxberry/bin/plugins/unwetter4lox/
    unwetter4lox_daemon.py      # Python-Daemon (Hauptlogik)
    loglevel.pl                 # Perl-Bridge für LoxBerry::Log Sessions

/opt/loxberry/system/daemons/plugins/
    unwetter4lox                # Daemon-Control-Script (aus daemon/daemon)

/opt/loxberry/config/plugins/unwetter4lox/
    unwetter4lox.cfg            # Aktive Konfiguration
    unwetter4lox.cfg.default    # Template (aus config/)

/opt/loxberry/log/plugins/unwetter4lox/
    daemon.log.current          # Pointer auf aktive Log-Session
    daemon.pid                  # PID des laufenden Daemons
    *.log                       # Log-Sessions (eine pro Start, via LoxBerry::Log)

/opt/loxberry/data/plugins/unwetter4lox/
    state.json                  # Letzter Zustand (Warnungen, INCA, Timestamps)

/opt/loxberry/webfrontend/htmlauth/plugins/unwetter4lox/
    index.php / settings.php / log.php / ajax.php
```

### Logging-Flow (LoxBerry-Standard)
```
Daemon startet
    → loglevel.pl aufrufen (Perl-Bridge)
        → LoxBerry::Log->new() erstellt /opt/loxberry/log/plugins/unwetter4lox/TIMESTAMP.log
        → gibt Pfad + Log-Level zurück
    → Python schreibt in diese Datei via FileHandler
    → daemon.log.current zeigt auf aktive Session
    → LoxBerry System-Log-Manager erkennt alle *.log Dateien automatisch
Daemon stoppt (SIGTERM)
    → <LOGEND> schreiben → sauberer Exit
```

---

## LoxBerry Plugin Framework – Regeln und Konventionen

### Pflicht-Regeln (NIEMALS brechen)
1. **Keine hardcodierten Pfade** – immer `REPLACELBHOMEDIR` und `REPLACELBPPLUGINDIR` verwenden, der Installer ersetzt diese Strings in allen Textdateien vor Ausführung
2. **LBPPLUGINDIR niemals als Env-Var in postinstall** – LoxBerry setzt sie nicht; nur `REPLACELBPPLUGINDIR` Placeholder verwenden
3. **Daemon-Script** → kommt aus `daemon/` Verzeichnis, LoxBerry installiert es nach `system/daemons/plugins/PLUGINNAME` (automatisch `chmod 755`)
4. **Uninstall-Script** → kommt aus `uninstall/` Verzeichnis, LoxBerry installiert es nach `data/system/uninstall/PLUGINNAME`
5. **Log-Format**: `YYYY-MM-DD HH:MM:SS <LEVEL> Nachricht` mit LoxBerry-Tags
6. **plugin.cfg INTERFACE=2.0** für LoxBerry 2.x+
7. **Sudoers**: Nur in postroot.sh schreiben, unter `/etc/sudoers.d/PLUGINNAME`

### Verzeichnis-Mapping (Plugin-ZIP → Installation)
```
bin/        → $LBHOMEDIR/bin/plugins/$LBPPLUGINDIR/
config/     → $LBHOMEDIR/config/plugins/$LBPPLUGINDIR/
daemon/     → $LBHOMEDIR/system/daemons/plugins/$LBPPLUGINDIR  (als Datei, nicht Ordner!)
templates/  → $LBHOMEDIR/templates/plugins/$LBPPLUGINDIR/
webfrontend/htmlauth/ → $LBHOMEDIR/webfrontend/htmlauth/plugins/$LBPPLUGINDIR/
icons/      → $LBHOMEDIR/webfrontend/html/system/images/icons/$LBPPLUGINDIR/
uninstall/  → $LBHOMEDIR/data/system/uninstall/$LBPPLUGINDIR  (als Datei!)
apt/apt.txt → APT-Pakete werden automatisch installiert
```

### Environment-Variable Replacements (Installer bakt diese ein)
```
REPLACELBHOMEDIR      → /opt/loxberry
REPLACELBPPLUGINDIR   → unwetter4lox
REPLACELBPBINDIR      → /opt/loxberry/bin/plugins/unwetter4lox
REPLACELBPCONFIGDIR   → /opt/loxberry/config/plugins/unwetter4lox
REPLACELBPDATADIR     → /opt/loxberry/data/plugins/unwetter4lox
REPLACELBPLOGDIR      → /opt/loxberry/log/plugins/unwetter4lox
REPLACELBPTEMPLDIR    → /opt/loxberry/templates/plugins/unwetter4lox
REPLACELBPWEBFRONTEND → /opt/loxberry/webfrontend/htmlauth/plugins/unwetter4lox
```

### PHP Web-Frontend Globals (von LoxBerry gesetzt)
```php
$lbhomedir        // /opt/loxberry
$lbpplugindir     // unwetter4lox
$lbpconfigdir     // /opt/loxberry/config/plugins/unwetter4lox
$lbpdatadir       // /opt/loxberry/data/plugins/unwetter4lox
$lbplogdir        // /opt/loxberry/log/plugins/unwetter4lox
$lbptempldir      // /opt/loxberry/templates/plugins/unwetter4lox
```

### Pflicht-Includes in PHP
```php
require_once "loxberry_system.php";   // Immer als erstes
require_once "loxberry_web.php";      // Für LBWeb::lbheader/lbfooter
require_once "loxberry_log.php";      // Für LBLog::newLog
require_once "loxberry_io.php";       // Für MQTT, IO-Funktionen
```

### Wichtige PHP-Funktionen
```php
LBWeb::lbheader("Titel", "helplink", "");   // Seitenheader mit Navbar
LBWeb::lbfooter();                           // Seitenfooter
LBLog::newLog(["name"=>"X","filename"=>$f,"append"=>1]);
LBWeb::logfile_button_html(["NAME"=>"X","LABEL"=>"📋 ..."]);
mqtt_connectiondetails();                    // LoxBerry MQTT Gateway Creds
LBSystem::readlanguage("language.ini");      // Sprachdatei laden
```

---

## LoxBerry Developer Dokumentation (Links)

> Wiki ist hinter Login – diese Links als Referenz für Stefans eigenen Zugang:

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

**Referenz-Plugin (Aloxberry):** https://github.com/Grestorn/loxberry-plugin-aloxberry  
→ Node.js-basiertes Plugin, sehr ähnliche Struktur; Logging-Pattern, control.sh und log-session-create.pl als Vorlage verwendet.

---

## Release-Prozess

```bash
# 1. Version in plugin.cfg erhöhen
# 2. CHANGELOG.md aktualisieren
# 3. Lokal ZIP erstellen (für sofortigen Test)
#    Windows PowerShell: via .NET ZipFile (create-plugin-zip.sh für Linux/WSL)
# 4. Committen und pushen
git add -A && git commit -m "release: vX.Y.Z - ..." && git push
# 5. Tag setzen → GitHub Actions baut ZIP automatisch und erstellt GitHub Release
git tag vX.Y.Z && git push origin vX.Y.Z
```

---

## MQTT Topics Übersicht

Präfix: `unwetter` (konfigurierbar)

```
unwetter/warnung/{typ}/stufe|aktiv|bald|start_text|end_text|notification
    Typen: wind, regen, schnee, glatteis, gewitter, hagel, hitze, kaelte
unwetter/warnung/max_stufe|irgendwas_aktiv|akutwarnung
unwetter/inca/boen_jetzt_kmh|wind_jetzt_kmh|boen_max_30min|boen_max_60min
unwetter/inca/niederschlag_jetzt|niederschlag_typ|niederschlag_typ_name
unwetter/inca/bald_regen|bald_hagel|bald_graupel|bald_sturm_30min|bald_sturm_60min
unwetter/inca/minuten_bis_regen
unwetter/notification/geosphere|inca|alle|neu_geosphere|entwarnung
unwetter/letztes_update
```

---

## Debugging (SSH auf LoxBerry)

```bash
# Daemon manuell starten (zeigt Fehler direkt)
sudo /opt/loxberry/system/daemons/plugins/unwetter4lox start

# Status
sudo /opt/loxberry/system/daemons/plugins/unwetter4lox status

# Aktives Log live verfolgen
tail -f $(cat /opt/loxberry/log/plugins/unwetter4lox/daemon.log.current)

# Alle Log-Sessions
ls -lth /opt/loxberry/log/plugins/unwetter4lox/

# Python-Daemon direkt testen
cd /opt/loxberry/bin/plugins/unwetter4lox
LBHOMEDIR=/opt/loxberry LBPPLUGINDIR=unwetter4lox python3 unwetter4lox_daemon.py

# paho-mqtt prüfen
python3 -c "import paho.mqtt; print(paho.mqtt.__version__)"
```
