# GEMINI.md – Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria API + INCA Nowcast) → MQTT → Loxone Miniserver.

---

## Sync-Instruktionen

1. **Beim Start:** `aimemory.md` lesen (Aktueller Stand: v0.2.2).
2. **Beim Abschluss / Wechsel zu Claude:** `aimemory.md` aktualisieren.

---

## Architektur-Leitplanken (v0.2.1+)

### MQTT & Topics
- **Hierarchie:** System-Status auf Top-Level, API-spezifische Daten in Sub-Topics (`zamg/`, `inca/`).
- **Namensgebung:** Topics müssen logisch der Datenquelle entsprechen.
- **Multilang:** Texte in MQTT-Payloads (z.B. `niederschlag_typ_name`) müssen lokalisiert sein.

### Internationalisierung (i18n)
- **Standard:** Immer DE und EN unterstützen.
- **Python:** SDK Fallback implementieren, falls LoxBerry SDK nicht geladen werden kann (z.B. bei lokalen Tests).

### Geocoding & Standort
- Adresseingabe erfolgt über `ajax.php` (Nominatim API).
- **Validierung:** Daemon darf ohne gültige LAT/LON Koordinaten nicht starten (Prüfung im `daemon/daemon` Shell-Script).

### Logging-Integrität
- **DB-Registrierung:** `name='Daemon'` ist Pflicht für die Sichtbarkeit im LoxBerry-System.
- **Format:** Striktes Einhalten der `<TAG>` Syntax.
- **Zeit:** Immer LoxBerry Systemzeit (`astimezone()`) verwenden.

---

## Kritische LoxBerry Regeln

- **REPLACELBHOMEDIR:** Einzige erlaubte Methode für Pfad-Referenzen in Skripten.
- **Sudoers:** Einträge für den Daemon-Start müssen in `postroot.sh` generiert werden.
- **Auto-Updates:** In `plugin.cfg` via `RELEASECFG` (GitHub Raw URL) steuern.
- **Konfiguration:** Bestehende `.cfg` Dateien bei Updates schützen (`postinstall.sh`).
