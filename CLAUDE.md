# CLAUDE.md – Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria API + INCA Nowcast) → MQTT → Loxone Miniserver.

---

## Sync-Instruktionen

1. **Beim Start:** `aimemory.md` lesen – dort steht der aktuelle Projektzustand (aktuell v0.2.2).
2. **Beim Abschluss / Wechsel zu Gemini:** `aimemory.md` aktualisieren.

---

## Architektur & Standards (v0.2.2+)

### Mehrsprachigkeit (i18n)
- **PHP:** Nutzt `LBSystem::readlanguage("language.ini")`. Sprachdateien in `templates/lang/`.
- **Python:** Nutzt `loxberry.system.lblanguage()`. Alle MQTT-Klartexte und Notifications müssen über das `T`-Dictionary in `unwetter4lox_daemon.py` übersetzt werden.

### MQTT Topic Struktur
- Präfix: `unwetter/` (konfigurierbar)
- **System:** `status` (OK/Error), `letzter_abruf_datum`, `letzter_abruf_epoch`
- **ZAMG:** `zamg/{typ}/{subtopic}` (ehemals `warnung/`)
- **INCA:** `inca/{parameter}`

### APIs
- **GeoSphere Austria (ZAMG):** Wetterwarnungen via `warnungen.zamg.at`.
- **INCA Nowcast:** Hochauflösende 15min-Vorhersage.
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
