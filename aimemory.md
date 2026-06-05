# aimemory.md – Unwetter4Lox (Shared AI Context)

> Diese Datei ist die einzige Wahrheitsquelle für den gemeinsamen Projektzustand.
> Claude und Gemini lesen und schreiben hier. Beim Wechsel immer aktualisieren.

---

## 📌 Projekt-Status

- **Aktuelle Version:** 0.1.5
- **Aktiv bearbeitet von:** Claude Code
- **Letzter Stand:** MQTT rc=5 Fehler behoben – `mqttgateway.json` hat verschachtelte Struktur `{"Main": {"Brokeruser":..., "Brokerpass":...}}`, Credential-Suche durchsucht jetzt alle Sub-Dicts. Außerdem: Race Condition beim MQTT-Connect behoben via `threading.Event`.
- **Repo:** https://github.com/HitsmartDev/Unwetter4Lox

---

## 🛠️ Aktueller Fokus (Next Steps)

1. v0.1.5 auf LoxBerry installieren und MQTT-Verbindung verifizieren (Topics in MQTT-Broker prüfen)
2. Wenn MQTT läuft: v0.2.0 planen (Stabilität, ggf. neue Features)
3. Mehrsprachigkeit Web-Frontend ausbauen (language_en.ini aktuell minimal)

---

## ⚠️ Offene Probleme & Erkenntnisse

- **LoxBerry Python SDK (`loxberry` Package) ist nicht installiert** → `LB_SDK = False`, Daemon nutzt manuellen JSON-Datei-Fallback. Das ist OK, aber suboptimal.
- **mqttgateway.json Struktur:** `{"Main": {"Brokerhost":..., "Brokeruser":..., "Brokerpass":...}, "subscriptions": [...]}` – Keys unter `Main` Sub-Dict, nicht Top-Level, nicht vorhersehbar aus Doku.
- **MQTT Reconnect-Logik:** Im Loop implementiert, aber noch nicht unter realen Trennbedingungen getestet.
- **Keine echten Wetterwarnungen** aufgetreten seit Installation – Logik nur mit Leerantworten (0 Warnungen) getestet.

---

## 📋 Versions-History (wichtige Fixes)

| Version | Fix |
|---|---|
| 0.1.0 | Initial Release – Daemon startet, Log erscheint im LoxBerry Log-Viewer |
| 0.1.1 | MQTT & Logging via LoxBerry Python SDK |
| 0.1.2 | MQTT Race Condition – `threading.Event` wartet auf echten `on_connect(rc=0)` |
| 0.1.3 | Erweiterte Credential-Suche, manueller USER/PASS Override in cfg |
| 0.1.4 | LoxBerry JSON-Keys großgeschrieben (`Brokeruser`) – case-insensitiver Lookup |
| 0.1.5 | `mqttgateway.json` verschachtelt unter `Main`-Key – alle Sub-Dicts werden durchsucht |

---

## 🏗️ Technische Architektur

```
GeoSphere Austria Warn-API  ──┐
(warnungen.zamg.at)           ├──► Python Daemon ──► paho-mqtt ──► MQTT Broker (LoxBerry Gateway)
GeoSphere INCA Nowcast API  ──┘   (alle 300s)              └──► Loxone Miniserver
(dataset.api.hub.geosphere.at)         │
                                       └──► state.json (für Web-UI)
```

**Dateien:**
- `bin/unwetter4lox_daemon.py` – Haupt-Daemon (Python 3.8+)
- `bin/loglevel.pl` – Perl-Bridge für LoxBerry::Log Sessions
- `daemon/daemon` – Bash Control-Script (start|stop|restart|status)
- `webfrontend/htmlauth/` – PHP Web-Frontend (index/settings/log/ajax)
- `config/unwetter4lox.cfg.default` – Konfigurationsvorlage

**Pfade auf LoxBerry nach Installation:**
```
/opt/loxberry/bin/plugins/unwetter4lox/unwetter4lox_daemon.py
/opt/loxberry/system/daemons/plugins/unwetter4lox          ← Control-Script
/opt/loxberry/config/plugins/unwetter4lox/unwetter4lox.cfg
/opt/loxberry/log/plugins/unwetter4lox/daemon.log.current  ← Pointer auf aktive Session
/opt/loxberry/data/plugins/unwetter4lox/state.json
```

---

## 📡 MQTT Topics (Präfix: `unwetter`, konfigurierbar)

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

## 🌐 Externe APIs

| API | URL | Auth |
|---|---|---|
| GeoSphere Warnungen | `https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lon={LON}&lat={LAT}&lang=de` | keiner |
| INCA Nowcast | `https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km?lat_lon={LAT}%2C{LON}&parameters={param}&output_format=geojson` | keiner |

GeoSphere Warntypen: 1=Wind, 2=Regen, 3=Schnee, 4=Glatteis, 5=Gewitter, 6=Hitze, 7=Kälte  
Warnstufen: 1=Gelb, 2=Orange, 3=Rot, 4=Lila

---

## 🚀 Release-Prozess

```bash
# 1. VERSION in plugin.cfg erhöhen
# 2. CHANGELOG.md aktualisieren
# 3. Commit + Push
git add -A && git commit -m "fix/feat: ... (vX.Y.Z)"
git push
# 4. Tag → GitHub Actions baut ZIP + GitHub Release automatisch
git tag vX.Y.Z && git push origin vX.Y.Z
# 5. Lokal ZIP (Windows PowerShell via .NET ZipFile – kein WSL nötig)
#    → Claude/Gemini bauen das via PowerShell Compress-Archive
```

---

## 🐛 Debugging (SSH auf LoxBerry)

```bash
sudo /opt/loxberry/system/daemons/plugins/unwetter4lox start
sudo /opt/loxberry/system/daemons/plugins/unwetter4lox status
tail -f $(cat /opt/loxberry/log/plugins/unwetter4lox/daemon.log.current)
ls -lth /opt/loxberry/log/plugins/unwetter4lox/
# Direkt testen:
LBHOMEDIR=/opt/loxberry LBPPLUGINDIR=unwetter4lox python3 \
    /opt/loxberry/bin/plugins/unwetter4lox/unwetter4lox_daemon.py
```

---

## 📚 LoxBerry Developer Links

> Wiki hinter Login – Stefans Zugang

- Grundlagen: https://wiki.loxberry.de/entwickler/grundlagen_zur_erstellung_eines_plugins
- Python: https://wiki.loxberry.de/entwickler/python_develop_plugins_with_python
- PHP: https://wiki.loxberry.de/entwickler/php_develop_plugins_with_php/start
- Referenz-Plugin Aloxberry: https://github.com/Grestorn/loxberry-plugin-aloxberry
