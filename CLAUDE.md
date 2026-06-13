# CLAUDE.md – Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria API + INCA Nowcast + TAWES 360°) → MQTT → Loxone Miniserver.

**Aktuelle Version: 0.4.30**

---

## Sync-Instruktionen

1. **Beim Start:** `aimemory.md` lesen – dort steht der aktuelle Projektzustand.
2. **Beim Abschluss / Wechsel zu Gemini:** `aimemory.md` UND `gemini.md` aktualisieren.

---

## Architektur & Standards (v0.4.x)

### Mehrsprachigkeit (i18n)
- **PHP:** Nutzt `LBSystem::readlanguage("language.ini")`. Sprachdateien in `templates/lang/`.
- **Python:** Nutzt `loxberry.system.lblanguage()`. Alle MQTT-Klartexte und Notifications müssen über das `T`-Dictionary in `unwetter4lox_daemon.py` übersetzt werden.

### MQTT Topic Struktur
- Präfix: `unwetter/` (konfigurierbar)
- **System:** `status` (OK/Error), `letzter_abruf_datum`, `letzter_abruf_epoch`
- **ZAMG:** `zamg/{typ}/{subtopic}`
- **INCA:** `inca/{parameter}`
- **TAWES:** `tawes/{parameter}`
- **Alarm:** `alarm/{kategorie}` (0-3, aggregiert aus allen Quellen)

### APIs
- **GeoSphere Austria (ZAMG):** Wetterwarnungen via `warnungen.zamg.at`.
- **INCA Nowcast:** Hochauflösende 15min-Vorhersage.
- **TAWES:** Wetterstations-Messdaten via `dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min`. Stationsmetadaten unter `/metadata`. Station IDs als wiederholte `station_ids=` Parameter.
- **Geocoding:** Nominatim (OpenStreetMap) via `ajax.php`.

### LoxBerry Logging
- **Daemon:** Muss `loxberry.log.Logger(name='Daemon', ...)` nutzen für DB-Registrierung.
- **Rotation:** `max_log_files=20` einhalten.
- **Viewer:** In PHP `LBWeb::logfile_button_html(["PACKAGE" => $lbpplugindir, "NAME" => "Daemon", ...])` verwenden.

---

## LoxBerry Plugin Framework – Pflicht-Regeln

### Pfade (NIEMALS hardcoden)
- Immer `REPLACELBHOMEDIR` und `REPLACELBPPLUGINDIR` verwenden.

### Log-Format
Standard LoxBerry Tags (`<OK>`, `<ERR>`, `<LOGSTART>`, etc.) sind zwingend für den Viewer.

### Persistence
`postinstall.sh` darf die `unwetter4lox.cfg` niemals überschreiben, wenn sie bereits existiert.

---

## Kritische Implementierungs-Details (v0.4.x)

### Alarm-Level Schema (v0.4.19+)
- **0** Ruhig / **1** Vorsicht / **2** Warnung / **3** Extrem
- ZAMG: Gelb→1, Orange→2, Rot/Lila→3
- INCA/TAWES: **1×Schwellwert→1, 2×→2, 3×→3** (ALLE Level erreichbar!)
- Wind: INCA verwendet `fx_max_60min` direkt (nicht `bald_sturm_30/60` für Level-Logik)
- Regen: INCA `rr_jetzt` und TAWES `regen_upstream_mm` gegen 1×/2×/3× REGEN_ALARM
- `aktiv`-Flag beeinflusst Level NICHT

### TAWES RR Einheit
TAWES API liefert `RR` in **mm/10min** (nicht mm/h!). `regen_upstream_mm` wird mit ×6 in mm/h umgerechnet. Wichtig bei Schwellwert-Vergleichen.

### TAWES Cache – immer beim Start löschen
`run()` löscht `tawes_stations.json` beim Daemon-Start automatisch. Kein Startup-Flag mehr. Stationen werden bei jedem Daemon-Start frisch von der API geladen.

### TAWES ID-Matching (`_canon_sid`)
Normiert beliebige ID-Formate auf numerischen Kern. Nutzt **längste** Zifferngruppe (nicht letzte!) um Compound-IDs wie `"ST.11035-01"` korrekt auf `"11035"` zu mappen.

### TAWES Konsens-Schwelle (v0.4.24+, Fix v0.4.26)
- `MAX_STATIONS` (Standard 25, Config `[TAWES]`): Anzahl Stationen pro API-Abruf.
- `MIN_ALARM_PROZENT` (Standard 30, Config `[TAWES]`): % der Upstream-Tal-Stationen die Schwellwert überschreiten müssen.
- **Kritisch (v0.4.26):** Wind-Konsens braucht mind. 2 Stationen (`max(2, round(N×PCT%))`). 1 Station kann nie Alarm auslösen.
- **Kritisch (v0.4.26):** `build_alarm()` verwendet `wind_upstream_kmh` NUR wenn `sturm_upstream == 1`. Vorher war `wind_upstream_kmh` direkt verwendet (Konsens-Gate ignoriert).
- Wind-Kaskade (`wind_kaskade`): Erkennt zeitliche Abfolge von Böen in Windrichtung → ETA-Berechnung. Gibt `alarm/wind=1` als Vorwarnung auch ohne Konsens.
- **Kritisch (v0.4.30):** Wind-Kaskade filtert alpine Stationen (>800m) aus — gleiche Logik wie Alarm-Konsens (`upstream_tal_kaskade`). Alpine Stationen dürfen weder Kaskaden-ETA noch Notification-Text beeinflussen.

### TAWES Lokal-Regen Radius (v0.4.28+)
- **Anzeigeradius** `REGEN_LOKAL_KM = 40.0` (fix): Alle Stationen bis 40km in Log + UI sichtbar.
- **Alarmradius** `REGEN_LOKAL_ALARM_KM = 25.0` (fix): Nur Stationen ≤ 25km tragen zu `regen_lokal_mm` → `alarm/regen` bei. Stationen 25–40km auslösen keinen Alarm, erscheinen aber in Info-Log.
- `tawes/regen_lokal_station`: Name + Distanz + mm/h der Alarm-auslösenden Station (leer wenn keine ≤ 25km).
- `alarm/regen_quelle`: `ZAMG`, `INCA (Xmm/h)`, `TAWES_UPSTREAM (Xmm/h)`, `TAWES_LOKAL (Name Xkm Ymm/h)`, `–`.
- **`notification/alle` Fix**: INCA-Fallback-Text `✅ kein Alarm` erscheint nicht mehr in `notification/alle` wenn nur TAWES/ZAMG einen Alarm auslöst.

### TAWES Buffer-Fenster (v0.4.27+)
- `REGEN_PUFFER_FENSTER = 3` (30min): `regen_upstream` nur auf Regen in letzten 30min prüfen. Ohne Limit blieb `regen_upstream=1` bis zu 2h nach Regen-Ende.
- `WIND_KASKADE_FENSTER = 6` (60min): Wind-Kaskade nur aus Böen der letzten 60min berechnen. Verhindert False-Alarm durch alten Böen-Eintrag + aktuelle Einzelstation.
- `alarm/wind_quelle` (neu): MQTT Topic zeigt Alarm-Quelle: `ZAMG`, `INCA (Xkm/h)`, `TAWES_STURM (Xkm/h)`, `TAWES_KASKADE`, `–`.

### TAWES API-Robustheit (v0.4.26+)
- `correlate_tawes()` gibt `{'_api_ok': False}` zurück wenn `fetch_tawes_data()` leere Antwort liefert.
- `run()`: Bei `_api_ok=False` → alter Datensatz bleibt, Retry in 90s (statt 480s). Bei Exception → kein Update von `_tawes_last_fetch`.
- `_cleanup_tawes_buffer()`: Entfernt Buffer-Einträge für Stationen die nicht mehr im Umkreis liegen (nach Windrichtungswechsel).
- `status/zamg_ok`, `status/inca_ok`, `status/tawes_ok`: MQTT Topics für Monitoring (0/1).
- `fetch_json()`: Unterscheidet HTTP-Fehler, Netzwerkfehler, JSON-Fehler – loggt URL-Snippet.

### Notification-Gate (v0.4.22+)
- `notification/inca`, `notification/tawes`, `notification/alle`: nur bei `alarm/gesamt >= 1` publiziert.
- Bei `alarm == 0`: leerer String publiziert → löscht retained Message im Broker.
- `alarm/entwarnung` (0/1): einmalig `1` bei Übergang alarm ≥1 → 0.
- `notification/geosphere`: immer aktiv (ZAMG-Offizialwarnungen).

### ZIP-Erstellung (Windows)
Immer `build_zip.ps1 -Version X.X.X` verwenden. NICHT `Compress-Archive` (erzeugt Backslashes → LoxBerry bricht ab).
**Namenskonvention: `unwetter4Lox-V.X.X.X.zip`** (z.B. `unwetter4Lox-V.0.4.26.zip`)
