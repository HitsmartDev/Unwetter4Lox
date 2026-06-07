# GEMINI.md – Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria API + INCA Nowcast + TAWES 360°) → MQTT → Loxone Miniserver.

---

## Sync-Instruktionen

1. **Beim Start:** `aimemory.md` lesen (Aktueller Stand: v0.4.0).
2. **Beim Abschluss / Wechsel zu Claude:** `aimemory.md` aktualisieren.

---

## Architektur-Leitplanken (v0.4.0+)

### MQTT & Topics
- **Hierarchie:** `{prefix}/zamg/`, `{prefix}/inca/`, `{prefix}/tawes/`, `{prefix}/notification/`, `{prefix}/alarm/`
- **alarm/-Topics (neu in v0.4.0):** `alarm/gewitter`, `alarm/wind`, `alarm/regen`, `alarm/hagel`, `alarm/schnee`, `alarm/stufe`, `alarm/zusammenfassung` – kombinieren alle 3 Quellen
- **Multilang:** Texte in MQTT-Payloads müssen lokalisiert sein (T-Dictionary im Daemon).

### TAWES 360° Korrelation (v0.4.0 Bugfixes)
- **Windrichtung:** Vektorgewichteter Durchschnitt via sin/cos (korrekte Kreisstatistik – kein Median)
- **Linreg:** None-Werte intern gefiltert, min. 4 Datenpunkte erforderlich
- **Gewitter Level 2:** zusätzlich wenn FFX-Slope > 3.0 km/h/10min (akutes Gewitter)
- **Alle 10min** (480s Threshold im run()-Loop) wird `correlate_tawes()` aufgerufen.
- **Stationen-Cache:** `tawes_stations.json` im DATADIR, täglich von API erneuert.
- **Ring-Buffer:** `collections.deque(maxlen=12)` pro Station = 2h Messdaten.
- **Upstream:** Bearing-Differenz < 70° zur dominanten Windrichtung.
- **Konfidenz:** 0–100%, zusammengesetzt aus Stationsanzahl, Frontgeschwindigkeit, Windkonsistenz, Trend.

### Aggregierter Gesamtstatus (build_alarm)
- `build_alarm(zamg, inca, tawes, akut)` in `daemon.py` kombiniert alle 3 Quellen
- Alarm-Level: `0`=Keine, `1`=Möglich, `2`=Aktiv, `3`=AKUT
- Wird in `state.json` unter Key `alarm` gespeichert
- `index.php` liest `state['alarm']` und zeigt Gesamtstatus-Block ganz oben

### Internationalisierung (i18n)
- **Standard:** Immer DE und EN unterstützen.
- **Sprachdateien:** `templates/lang/language_de.ini` + `language_en.ini`.
- **Python:** T-Dictionary mit allen MQTT-Texten (inkl. TAWES-Notifications).

### Geocoding & Standort
- Adresseingabe über `ajax.php` (Nominatim API).
- Daemon prüft LAT/LON vor Start.

### Logging-Integrität
- **DB-Registrierung:** `name='Daemon'` für LoxBerry Log-Viewer.
- **daemon.log.current:** Pointer-Datei im LOGDIR – PHP liest daraus den aktuellen Log-Pfad.
- **Format:** `<OK>`, `<ERR>`, `<WARNING>`, `<DEBUG>`, `<LOGSTART>`, `<LOGEND>` Tags.

---

## Kritische LoxBerry Regeln

- **REPLACELBHOMEDIR:** Einzige erlaubte Methode für Pfad-Referenzen.
- **Sudoers:** Daemon-Start-Einträge in `postroot.sh`.
- **Config-Schutz:** `preupgrade.sh` sichert `.cfg` vor Update → `postroot.sh` stellt wieder her.
- **TAWES-Migration:** `postroot.sh` fügt `[TAWES]` Sektion automatisch hinzu wenn fehlend.
- **logfile_button_html:** Immer `LOGFILE` Parameter übergeben (LB_SDK=False → DB leer).
