# CLAUDE.md – Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria API + INCA Nowcast) → MQTT → Loxone Miniserver.

---

## Sync-Instruktionen

1. **Beim Start:** `aimemory.md` lesen – dort steht der aktuelle Projektzustand, offene Probleme und nächste Schritte.
2. **Beim Abschluss / Wechsel zu Gemini:** `aimemory.md` aktualisieren – Version, was geändert wurde, was als nächstes ansteht, offene Probleme.

---

## LoxBerry Plugin Framework – Pflicht-Regeln

### Pfade (NIEMALS hardcoden)
- Immer `REPLACELBHOMEDIR` und `REPLACELBPPLUGINDIR` verwenden – der Installer ersetzt diese Strings in allen Textdateien
- `LBPPLUGINDIR` niemals als Env-Var in `postinstall.sh` – LoxBerry setzt sie dort nicht

### Verzeichnis-Mapping (ZIP → Installation)
```
bin/                  → $LBHOMEDIR/bin/plugins/$LBPPLUGINDIR/
config/               → $LBHOMEDIR/config/plugins/$LBPPLUGINDIR/
daemon/daemon         → $LBHOMEDIR/system/daemons/plugins/$LBPPLUGINDIR  (Datei, nicht Ordner!)
uninstall/uninstall   → $LBHOMEDIR/data/system/uninstall/$LBPPLUGINDIR  (Datei!)
webfrontend/htmlauth/ → $LBHOMEDIR/webfrontend/htmlauth/plugins/$LBPPLUGINDIR/
apt/apt.txt           → APT-Pakete werden automatisch installiert
```

### REPLACE-Variablen
```
REPLACELBHOMEDIR      → /opt/loxberry
REPLACELBPPLUGINDIR   → unwetter4lox
REPLACELBPBINDIR      → /opt/loxberry/bin/plugins/unwetter4lox
REPLACELBPCONFIGDIR   → /opt/loxberry/config/plugins/unwetter4lox
REPLACELBPDATADIR     → /opt/loxberry/data/plugins/unwetter4lox
REPLACELBPLOGDIR      → /opt/loxberry/log/plugins/unwetter4lox
```

### Log-Format
```
YYYY-MM-DD HH:MM:SS <OK>       normaler Erfolg
YYYY-MM-DD HH:MM:SS <WARNING>  Warnung
YYYY-MM-DD HH:MM:SS <ERR>      Fehler
YYYY-MM-DD HH:MM:SS <CRIT>     kritisch
YYYY-MM-DD HH:MM:SS <DEBUG>    debug
YYYY-MM-DD HH:MM:SS <LOGSTART> Session-Name   ← erste Zeile
YYYY-MM-DD HH:MM:SS <LOGEND>                  ← letzte Zeile bei sauberem Exit
```

### PHP Web-Frontend
```php
// Pflicht-Includes (Reihenfolge!)
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";

// Globals: $lbhomedir, $lbpplugindir, $lbpconfigdir, $lbpdatadir, $lbplogdir
LBWeb::lbheader("Titel", "helplink", "");
LBWeb::lbfooter();
```

### Sudoers
Nur in `postroot.sh` schreiben, unter `/etc/sudoers.d/unwetter4lox`

### plugin.cfg
`INTERFACE=2.0` für LoxBerry 2.x+

### MQTT Gateway Config (kritische Eigenheit)
`/opt/loxberry/config/system/mqttgateway.json` hat verschachtelte Struktur:
```json
{"Main": {"Brokerhost": "...", "Brokerport": 1883, "Brokeruser": "...", "Brokerpass": "..."}, "subscriptions": [...]}
```
Credentials sind unter `Main`, Keys großgeschrieben. Der Daemon durchsucht alle Sub-Dicts case-insensitiv.
