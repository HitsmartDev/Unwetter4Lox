# CLAUDE.md – Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria API + INCA Nowcast + TAWES 360°) → MQTT → Loxone Miniserver.

**Aktuelle Version: 0.4.17**

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

### Alarm-Level Schema
- **0** Ruhig / **1** Vorsicht (ZAMG Gelb) / **2** Warnung (ZAMG Orange) / **3** Extrem (ZAMG Rot/Lila)
- INCA/TAWES können NUR bis Level 2 anheben – niemals 3
- `aktiv`-Flag beeinflusst Level NICHT

### TAWES RR Einheit
TAWES API liefert `RR` in **mm/10min** (nicht mm/h!). `regen_upstream_mm` wird mit ×6 in mm/h umgerechnet. Wichtig bei Schwellwert-Vergleichen.

### TAWES Startup-Fresh-Load
`_tawes_startup_fresh_done` Flag erzwingt frischen API-Abruf beim ersten Daemon-Start nach Neustart/Update. Verhindert veraltete Station-IDs nach Plugin-Updates.

### ZIP-Erstellung (Windows)
Immer `build_zip.ps1 -Version X.X.X` verwenden. NICHT `Compress-Archive` (erzeugt Backslashes → LoxBerry bricht ab).
