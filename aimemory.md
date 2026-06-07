## 📌 Projekt-Status
- **Version:** 0.3.1 (Bugfix-Release)
- **Letzter Stand (Claude, 2026-06-07):**
  1. **TAWES 360° Korrelation** komplett implementiert in `daemon.py`:
     - `find_nearby_stations()`, `fetch_tawes_data()`, `correlate_tawes()`, `publish_tawes()`
     - Haversine-Distanz + Bearing, dominante Windrichtung (Median über alle Stationen FF>1m/s)
     - Ring-Buffer 2h (`deque(maxlen=12)`) pro Station
     - Regenfront-ETA: Welle aus Buffer + lineare Frontgeschwindigkeit zwischen Upstream-Stationen
     - Wind-Trend: lineare Regression (6 letzte Messungen, kein numpy)
     - Gewitter-Signal: Druckabfall <-0.5 hPa/10min + Feuchte >85%
     - Konfidenz 0–100%, TAWES alle 10min (480s-Threshold)
     - Stationen-Cache täglich in `tawes_stations.json`
  2. **TAWES UI** in `index.php`: Summary-Listview + aufklappbare Stationstabelle mit Farbkodierung
  3. **TAWES Settings** in `settings.php`: Umkreis-Slider (20-150km), Stations-Cache-Reset via ajax
  4. **ajax.php**: `reload_stations` Action löscht `tawes_stations.json`
  5. **Config**: `cfg.default` + `postroot.sh` Upgrade-Migration mit `[TAWES]` Sektion
  6. **preupgrade.sh** sichert Config vor Plugin-Update
  7. Alle Bugfixes aus v0.2.2 sind enthalten (load_state, Log-Level, Pointer, logfile=)
- **Aktiv bearbeitet von:** Claude
- **ZIP:** `unwetter4lox.zip` lokal vorhanden (29 Dateien, 48.5 KB)

## 🛠️ Aktueller Fokus (Next Steps)
1. **Testen:** Plugin auf LoxBerry installieren (ZIP: `unwetter4lox.zip`).
2. **Validieren:** Daemon starten → TAWES-Stationen laden → 10min warten → Status-Tab prüfen.
3. **MQTT Topics testen:** `unwetter/tawes/*` in MQTT-Explorer prüfen.
4. **Release:** Bei Stabilität GitHub Tag `v0.3.0` erstellen.

## ⚠️ Offene Probleme & Erkenntnisse
- **LB_SDK = False:** LoxBerry Python SDK nicht installiert → Fallback-Modus aktiv. Alle Features laufen ohne SDK.
- **TAWES API:** GeoSphere Austria `dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min` – Stationsmetadaten unter `/metadata` Endpunkt. Station IDs als wiederholte `station_ids=` Parameter (nicht Komma-getrennt).
- **Regen-ETA Genauigkeit:** Erfordert ≥2 Upstream-Stationen mit Regen im Buffer für Frontgeschwindigkeit. Sonst ETA=-1.
- **MQTT Topics:** Loxone-Konfigurationen müssen von `warnung/` auf `zamg/` angepasst sein (seit v0.2.1). Neue TAWES-Topics unter `unwetter/tawes/`.
- **logfile_button_html LOGFILE-Parameter:** Pflicht wenn LB_SDK=False (Log nicht in DB registriert).
