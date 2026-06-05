# CLAUDE.md – Unwetter4Lox

LoxBerry-Plugin für österreichische Unwetterwarnungen via GeoSphere Austria API → MQTT → Loxone Miniserver.

---

## Projektstatus (Stand: 2026-06-05)

**Phase:** Dev – erste Installation beim Entwickler Stefan in Betrieb nehmen  
**Version:** 0.1.0  
**Nächster Schritt:** Plugin per ZIP auf LoxBerry installieren und Daemon-Start verifizieren

---

## Architektur

```
GeoSphere Austria API  ──┐
                         ├──► Python Daemon ──► MQTT ──► Loxone Miniserver
GeoSphere INCA API    ──┘         │
                                  └──► state.json (Zustandsspeicher)
```

**Python Daemon** (`bin/unwetter4lox_daemon.py`):
- Polling-Loop mit konfiguriertem Intervall (Standard: 300s)
- Holt GeoSphere-Warnungen und INCA-Nowcast
- Veröffentlicht ~40 MQTT Topics
- Schreibt `data/plugins/unwetter4lox/state.json` für das Web-Frontend

**Daemon Control Script** (`daemon/daemon`):
- LoxBerry installiert diese Datei nach `system/daemons/plugins/unwetter4lox`
- Wird per `sudo` aus dem Web-Frontend aufgerufen (ajax.php)
- Liest `LBHOMEDIR` aus `/etc/environment` (sudo strippt Umgebungsvariablen!)

**Web-Frontend** (`webfrontend/htmlauth/`):
- `index.php` – Status + Daemon-Steuerung + Live-Warnlage
- `settings.php` – Konfiguration (Standort, MQTT, Intervall)
- `log.php` – Log-Viewer
- `ajax.php` – Daemon start/stop/restart via sudo

---

## Bekannte Bugs & Fixes (0.1.0)

| Bug | Ursache | Fix |
|---|---|---|
| Daemon startet nicht | `LBHOMEDIR` leer wegen sudo Env-Strip | `daemon/daemon`: Fallback aus `/etc/environment` |
| Log-Datei leer / nicht vorhanden | Log-Verzeichnis nicht erstellt wenn Env fehlt | `mkdir -p` im daemon-Script vor Python-Start |
| Doppeltes Logging | FileHandler + stdout-Redirect in selbe Datei | StreamHandler entfernt; Shell redirectet nicht mehr |
| paho-mqtt >= 2.0 Crash | `CallbackAPIVersion` Pflicht in neuer API | `try/except AttributeError` für beide API-Versionen |

---

## LoxBerry Plugin-Konventionen

- `plugin.cfg` – Metadaten (INTERFACE=2.0)
- `daemon/daemon` → wird installiert als `$LBHOMEDIR/system/daemons/plugins/unwetter4lox`
- `bin/` → `$LBHOMEDIR/bin/plugins/unwetter4lox/`
- `config/` → `$LBHOMEDIR/config/plugins/unwetter4lox/`
- Log-Format: `YYYY-MM-DD HH:MM:SS <OK|WARNING|ERR|CRIT|DEBUG> Nachricht`
- Log-Datei: `$LBHOMEDIR/log/plugins/unwetter4lox/unwetter4lox.log`
- Sudoers: `/etc/sudoers.d/unwetter4lox` (erstellt von `postroot.sh`)

---

## Wichtige Dateipfade

```
bin/unwetter4lox_daemon.py       Python-Daemon (Hauptlogik)
daemon/daemon                    Daemon-Control-Script (→ system/daemons/plugins/)
config/unwetter4lox.cfg.default  Standard-Konfiguration
webfrontend/htmlauth/            PHP Web-Frontend
postinstall.sh                   Post-Install (paho-mqtt, Config-Copy)
postroot.sh                      Post-Install als Root (Sudoers, chmod)
preinstall.sh                    Pre-Install (Python3 Check)
uninstall/uninstall              Deinstallation (Daemon stoppen, Sudoers entfernen)
create-plugin-zip.sh             Build-Script für LoxBerry-ZIP
```

---

## MQTT Topics Übersicht

Präfix: `haus/wetter` (konfigurierbar)

- `warnung/{typ}/stufe|aktiv|bald|start_text|end_text|notification` (Typen: wind, regen, schnee, glatteis, gewitter, hagel, hitze, kaelte)
- `warnung/max_stufe|irgendwas_aktiv|akutwarnung`
- `inca/boen_jetzt_kmh|wind_jetzt_kmh|boen_max_30min|boen_max_60min|...`
- `notification/geosphere|inca|alle|neu_geosphere|entwarnung`
- `letztes_update`

---

## Deployment

```bash
# ZIP erstellen
./create-plugin-zip.sh

# Auf LoxBerry installieren
# Plugin Manager → ZIP-Datei hochladen

# Nach Installation – Daemon-Start testen per SSH
sudo /opt/loxberry/system/daemons/plugins/unwetter4lox start
cat /opt/loxberry/log/plugins/unwetter4lox/unwetter4lox.log
```
