# Changelog – Unwetter4Lox

Alle relevanten Änderungen werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

---

## [0.4.32] – 2026-06-13

### Hinzugefügt
- **Historische Daten-Initialisierung**: Beim Start des Daemons werden nun automatisch die TAWES-Daten der letzten 60 Minuten abgerufen. Dies stellt sicher, dass der Puffer sofort gefüllt ist und die Stationsliste in der UI nicht mehr leer ("-") erscheint.
- **Start-Feedback**: Der Status wird beim Start explizit auf "Initialisierung..." gesetzt, um sofortiges Feedback in der UI zu geben, während die Daten geladen werden.

### Behoben
- **Leere Stationslisten nach Update**: Das Problem, dass nach einem Neustart/Update erst nach 10-20 Minuten Daten angezeigt wurden, wurde durch den Initialisierungs-Abruf behoben.

---

## [0.4.31] – 2026-06-13

### Hinzugefügt
- **Heartbeat-Monitoring**: Neues MQTT-Topic `status/last_seen` (Epoch) zur Überwachung der Loop-Aktivität. Erlaubt Loxone oder der UI zu erkennen, ob der Datenabruf hinkt.
- **MQTT LWT (Last Will)**: Der MQTT-Status wird nun automatisch auf „Offline (LWT)“ gesetzt, falls der Daemon abstürzt oder die Verbindung verliert.

### Behoben
- **Erkennung von hängendem Daemon**: Die UI zeigt nun eine auffällige Warnung (Rot), wenn die Daten älter als zwei Abrufintervalle sind – selbst wenn der Daemon-Prozess noch läuft.
- **Robustes TAWES-Parsing**: Fehlerbehandlung bei der Verarbeitung von Stationsdaten verbessert (Schutz gegen `null`, `NaN` oder ungültige Datentypen von der API).
- **Speichereffizienz**: Verhindert das Starten multipler MQTT-Hintergrundthreads bei Verbindungsneuaufbau.
- **Daemon-Status Fix**: Das Status-Badge in der UI bleibt nun zuverlässig rot, wenn der Prozess nicht läuft, auch wenn der letzte gespeicherte Status „OK“ war.

---

## [0.4.30] – 2026-06-11

### Behoben
- **TAWES Stations-Tabelle zeigt oft `–` für Wind/Böen**: Das `tawes-v1-10min` API liefert viele Stationen als `null` wenn sie im aktuellen 10min-Fenster noch keine Messung eingespielt haben. Fix: Buffer-Fallback in `_disp_val()` — wenn aktuelle API-Daten `null`, wird der letzte gültige Wert aus den letzten 3 Buffer-Einträgen (≤30min) verwendet. Alarm-Logik bleibt auf aktuellen Rohwerten (kein Buffer-Fallback dort, kein False-Alarm durch veralteten Wind).
- **Atomarer `save_state()`**: State wurde mit `open('w')` direkt in `state.json` geschrieben → wenn PHP die Datei genau in diesem Moment las, las es eine leere/halbfertige Datei. Fix: Write in `state.json.tmp`, dann `os.replace()` (atomar auf POSIX).
- **NaN-Guard in `_v()`**: GeoSphere API könnte `"NaN"` als Sentinel-Wert liefern. `float("NaN")` = `nan` → `json.dump()` mit `allow_nan=False` würde fehlschlagen → state.json nicht gespeichert. Fix: NaN/Inf wird in `_v()` abgefangen und als `None` behandelt.
- **Wind-Kaskade ignorierte alpine Ausschluss-Logik**: FEUERKOGEL (1618m) und andere alpine Stationen (> 800m) wurden zwar aus dem Alarm-Konsens ausgeschlossen, aber weiterhin in der Wind-Kaskade verwendet. Dadurch erschienen alpine Stationsnamen in der Kaskaden-Notification und beeinflussten die ETA-Berechnung. Fix: `upstream_tal_kaskade` filtert alpine Stationen aus der Kaskade – konsistent mit der Alarm-Konsens-Logik.
- **Daemon startet nach Plugin-Installation nicht automatisch**: `postinstall.sh` (loxberry user) wartete nur 1s auf die sudoers-Datei (zu kurz). Fix: `postroot.sh` (root) startet den Daemon jetzt direkt via `runuser`/`su` ohne sudo; `postinstall.sh` dient als Fallback mit `sleep 3`.
- **UI aktualisiert sich nach Daemon-Start zu langsam**: Erste Polling-Prüfung nach 10s zu spät für schnellen Daemon-Start. Fix: erste Prüfung jetzt nach 5s.

---

## [0.4.29] – 2026-06-11

### Behoben
- **Notifications wurden nach Daemon-Restart nicht gelöscht**: Im Clearing-Pfad (kein Alarm) verhinderte die Dedup-Logik (`'' != '' → False`) das Publizieren von leeren Strings nach einem Neustart. Alte retained Messages im MQTT-Broker blieben sichtbar. Fix: Beim Clearing (`alarm/gesamt == 0`) werden alle drei Notification-Topics immer publiziert, ohne Dedup.
- **`regen_lokal`-Alarm ohne TAWES Notification-Text**: Wenn `alarm/regen` nur durch lokalen Regen (`regen_lokal_mm ≥ REGEN_ALARM`) ausgelöst wurde, hatte `notification/tawes` keinen Text → `notification/alle` fiel auf INCA „✅ kein Alarm"-Fallback zurück, obwohl ein Alarm aktiv war. Fix: `regen_lokal` generiert jetzt einen eigenen Notification-Text (z.B. `🌧️ Lokal-Regen: VÖCKLABRUCK (12km) 8.4mm/h`).
- **`regen_upstream` ohne ETA/Konfidenz ohne Notification-Text**: Bei Konfidenz < 50% gab es keine TAWES-Notification. Fix: auch bei niedriger Konfidenz wird `🌧️ Regen bei Station (km) aus Richtung | Ankunft unbekannt` publiziert.

### Geändert
- **TAWES Stationsanzeige: Regen in mm/h**: Stationswerte wurden bisher in mm/10min (API-Rohwert) angezeigt. Jetzt ×6 auf mm/h umgerechnet – einheitlich mit allen anderen Regen-Werten im Plugin. Farbgebung: blau = Regen vorhanden, rot = ≥ konfigurierter REGEN_ALARM Schwelle.

---

## [0.4.28] – 2026-06-11

### Behoben
- **`regen_lokal` Alarm-Radius reduziert (40 → 25 km)**: Getrennte Radien eingeführt: 40 km für Anzeige/Info-Log, 25 km für `regen_lokal_mm` das in `alarm/regen` einfließt. Stationen 25–40 km entfernt (z.B. GALLSPACH 28 km nordwärts bei lokaler Gewitterzelle) lösen keinen `alarm/regen` mehr aus, sind aber weiterhin in der Stations-Anzeige sichtbar. Neues Log: `TAWES Lokal-Regen: Regen nur außerhalb 25km (kein alarm/regen-Beitrag)`.
- **`notification/alle` zeigte `✅ kein Alarm`** obwohl `alarm/gesamt ≥ 1`: Wenn INCA keinen eigenen Alarm hatte (nur TAWES/ZAMG-Alarm aktiv), stand der INCA-Fallback-Text in der kombinierten Notification. Fix: `n_alle` enthält INCA-Text nur wenn INCA echte Alarm-Inhalte hat (nicht nur den Status-Fallback).

### Hinzugefügt
- **`alarm/regen_quelle` MQTT Topic**: Analog zu `alarm/wind_quelle` zeigt es die Alarm-Quelle: `ZAMG`, `INCA (0.5mm/h)`, `TAWES_UPSTREAM (12mm/h)`, `TAWES_LOKAL (GALLSPACH 25km 31.8mm/h)` oder `–`. Macht sofort klar warum `alarm/regen > 0` auslöst.
- **`tawes/regen_lokal_station`**: Name, Distanz und mm/h der Alarm-auslösenden lokalen Station (≤ 25km). Leer wenn keine Station im Alarmradius Regen meldet.

---

## [0.4.27] – 2026-06-11

### Behoben
- **`regen_upstream` Stale-Alarm** (Buffer zu lang): `regen_upstream=1` blieb bis zu 2 Stunden aktiv, da der gesamte 12-Einträge-Buffer (2h) auf Regen geprüft wurde. Fix: nur noch letzte 3 Einträge (30min) → `regen_upstream` zeigt nur aktiven oder sehr frischen Regen upstream.
- **`wind_kaskade` Stale-Alarm** (Buffer zu lang): Eine Böe von vor 90min + aktuelle Einzelstation konnten fälschlich als "Kaskade" erkannt werden (weit entfernte Station vor Stunden + nahe Station jetzt → `kaskade_ok=True`). Fix: Wind-Kaskade prüft nur letzte 6 Einträge (60min) → eine echte Sturm-Kaskade muss innerhalb 60min stattfinden.

### Hinzugefügt
- **`alarm/wind_quelle` MQTT Topic**: Zeigt exakt welche Quelle den Wind-Alarm ausgelöst hat: `ZAMG`, `INCA (52.3km/h)`, `TAWES_STURM (65km/h)`, `TAWES_KASKADE` oder `–`. Damit sofort sichtbar warum `alarm/wind > 0` ohne Log lesen zu müssen.

---

## [0.4.26] – 2026-06-11

### Behoben (kritisch)
- **`build_alarm()` ignorierte `sturm_upstream`-Konsens**: `wind_upstream_kmh` (Max-FFX einer einzigen Upstream-Station) konnte direkt `alarm/wind` auslösen, ohne dass der Konsens-Check griff. Fix: `alarm/wind` via TAWES nur wenn `sturm_upstream == 1`. Dies war der Grund warum einzelne Stationen mit hohen Böen sofort Alarm auslösten.
- **Konsens-Minimum 1 → 2 Stationen**: `max(1, round(N×30%))` ergab für N≤4 immer 1 — Konsens war effektiv deaktiviert. Neu: `max(2, round(N×30%))` + explizite Prüfung `len(ffx_vals) >= 2`. Eine einzige Upstream-Station kann nie mehr Wind-Alarm auslösen.

### Hinzugefügt
- **Robuste API-Fehlerklassifikation** in `fetch_json()`: unterscheidet HTTP-Statusfehler, Netzwerk-/Timeout-Fehler und JSON-Parse-Fehler. Loggt URL-Snippet für schnelle Diagnose.
- **INCA Partial-Fehler**: Einzelne Parameter (ff/fx/rr/pt) werden übersprungen statt das ganze Ergebnis zu verwerfen. Nur wenn alle 4 fehlschlagen → `None`. Warnung bei Teilausfall.
- **TAWES `_api_ok` Flag**: `correlate_tawes()` erkennt leere Antwort (API-Fehler) und gibt `{'_api_ok': False}` zurück. `run()` behält dann den letzten gültigen Datensatz und setzt `_tawes_last_fetch` zurück sodass Retry in 90s statt 480s erfolgt.
- **TAWES Buffer-Cleanup**: Nach erfolgreichem Abruf werden Stationen die nicht mehr im Umkreis liegen aus `TAWES_BUFFER` entfernt (Windrichtung/Radius geändert → kein Stale-State).
- **Datenquellen-Status Topics**: `status/zamg_ok`, `status/inca_ok`, `status/tawes_ok` (0/1) für Monitoring in Loxone/UI.
- **TAWES `tawes/api_ok`** Topic (0/1) — zeigt ob letzter TAWES-Abruf Daten lieferte.
- **Wind-Kaskaden-Erkennung** (`tawes/wind_kaskade`, `tawes/wind_kaskade_eta_min`, `tawes/wind_kaskade_speed_kmh`): Erkennt wenn Upstream-Stationen in zeitlicher Abfolge (weiter entfernt → näher) Böen ≥ BOEN_ALARM melden — Indikator dass ein Sturm gerade in Windrichtung auf den User zuzieht. Berechnet ETA analog zur Regen-Front. Gibt Vorwarnung (`alarm/wind = 1`) auch wenn Konsens noch nicht erreicht, liefert Notification `💨 Sturmfront naht aus NW | ETA ~10min`.
- Fehler-Zähler `_tawes_fehler_count` für konsekutive TAWES-Fehler im Log.

---

## [0.4.25] – 2026-06-11

### Hinzugefügt
- **TAWES Stations-Seehöhe**: Metadaten-Abruf speichert jetzt die Höhe (`alt`) jeder Station. Upstream-Log zeigt Höhe: `Feuerkogel (70km, 1618m, FFX=85km/h)`.
- **Alpine Upstream-Ausschluss** (`MAX_UPSTREAM_HOEHE_M`, Standard: 1200m): Upstream-Stationen über dieser Seehöhe werden aus dem Wind-Alarm-Konsens ausgeschlossen. Verhindert False-Alarms durch natürlich höhere Bergwind-Werte (z.B. Feuerkogel 1618m, Schafberg 1783m). Konfigurierbar per Schieberegler in den Einstellungen. Neues MQTT Topic: `tawes/alpine_upstream` (Anzahl ausgeschlossener alpiner Stationen).
- **Lokal-Regen-Erkennung** (`tawes/regen_lokal`, `tawes/regen_lokal_mm`): Prüft alle Stationen innerhalb 40km auf aktuellen Regen – unabhängig von der Windrichtung. Erkennt "es regnet JETZT hier" auch wenn die regnenden Stationen nicht upstream liegen. `regen_lokal_mm` (mm/h) fließt in den `alarm/regen`-Level ein.
- Log-Meldung für Lokal-Regen zeigt welche Stationen Regen melden und mit welcher Intensität.

### Geändert
- `TAWES Wind: X/Y Stationen`-Log zeigt jetzt "Tal-Stationen" vs. "alpine Stationen" klar getrennt.

---

## [0.4.24] – 2026-06-11

### Hinzugefügt
- **TAWES Konsens-Schwelle** (`MIN_ALARM_PROZENT`, Standard: 30%): TAWES Wind- und Regen-Alarm wird nur ausgelöst wenn mindestens N% der Upstream-Stationen MIT Daten den Schwellwert überschreiten. Verhindert False-Positives durch einzelne Ausreißer-Stationen (z.B. eine Station mit hohen Böen während alle anderen ruhig sind).
- **TAWES Max-Stationen** (`MAX_STATIONS`, Standard: 25): konfigurierbare Obergrenze für API-Abfragen (war bisher hardcoded 25). Mehr Stationen = genauerer Konsens, mehr Netzlast.
- Beide Parameter in Einstellungen (Settings-UI) konfigurierbar mit Schieberegler.
- Log-Meldung wenn Konsens-Schwelle nicht erreicht: `TAWES Wind: X/Y Stationen >= Z km/h (Minimum: N = 30%) → kein Alarm`

---

## [0.4.23] – 2026-06-10

### Behoben
- `notification/inca`, `notification/tawes`, `notification/alle` wurden trotz inaktivem Alarm im MQTT-Broker retained gehalten. Ursache: `publish()` nutzt `retain=True`, d.h. der Broker liefert den letzten Wert auch ohne erneute Publikation aus. Fix: bei `alarm/gesamt == 0` wird aktiv ein leerer String publiziert (löscht die retained Message). Gilt auch beim ersten Zyklus nach Daemon-Neustart wenn noch alte retained Werte im Broker liegen.

---

## [0.4.22] – 2026-06-10

### Geändert
- **Notification-Gate:** `notification/inca`, `notification/tawes` und `notification/alle` werden nur noch publiziert wenn `alarm/gesamt >= 1`. Verhindert Push-Nachrichten bei informativem Dauerregen ohne Alarm-Level.
- `notification/geosphere` bleibt unverändert – offizielle ZAMG-Warnungen werden immer publiziert.

### Hinzugefügt
- Neues MQTT-Topic `alarm/entwarnung` (0/1): Wird einmalig auf `1` gesetzt wenn `alarm/gesamt` von ≥1 auf 0 fällt (Entwarnung), danach wieder `0`. Erlaubt Loxone auf den Entwarnung-Event direkt zu reagieren.
- Bei Entwarnung: `notification/alle` sendet einmalig "✅ Entwarnung – alle Wetterwarnungen aufgehoben."

---

## [0.4.21] – 2026-06-09

### Behoben
- `_canon_sid()`: Compound-IDs mit kurzem Suffix nach Bindestrich (z.B. `"ST.11035-01"`) gaben fälschlicherweise `"1"` statt `"11035"` zurück (letzter statt längster Ziffernblock). Jetzt wird die längste Zifferngruppe verwendet.
- `_canon_sid()`: Float-Strings wie `"11035.0"` wurden nicht korrekt behandelt (fehlende `int(float(s))` Konvertierung).
- TAWES ID-Format-Log von DEBUG auf INFO hochgestuft: zeigt jetzt im normalen Log welche raw IDs die API zurückgibt und wie sie kanonisiert werden – erleichtert Diagnose von ID-Mismatches erheblich.
- TAWES Match-Warnung zeigt jetzt konkret welche Station-IDs kein Match haben.

## [0.4.20] – 2026-06-09

### Behoben
- TAWES Stations-Cache wird jetzt beim Daemon-Start automatisch gelöscht (`run()` löscht `tawes_stations.json` direkt beim Start). Verhindert zuverlässig veraltete oder fehlende Stationsdaten nach jedem Plugin-Update, Neuinstallation oder manuellem Neustart – ohne Race-Condition und ohne Abhängigkeit von `postinstall.sh`-Timing.
- `_tawes_startup_fresh_done` Flag entfernt (war unzuverlässig, da Race-Conditions möglich blieben).

---

## [0.4.19] – 2026-06-09

### Geändert
- **Alarm-Level nach Schwellwert-Vielfachem** (Breaking Change – konsistentere Logik):
  - `alarm/regen`: INCA `rr_jetzt` und TAWES `upstream_mm` lösen jetzt Level 1/2/3 aus bei 1×/2×/3× `REGEN_ALARM` (statt bisher: max Level 2 bei Schwelle, mit ETA-Abhängigkeit)
  - `alarm/wind`: INCA `fx_max_60min` und TAWES `upstream_kmh` lösen Level 1/2/3 aus bei 1×/2×/3× `BOEN_ALARM` (statt bisher: bald_sturm_30→2, bald_sturm_60→1)
  - ZAMG bleibt unverändert: Gelb→1, Orange→2, Rot/Lila→3
  - INCA und TAWES können jetzt Level 3 erreichen (bisher war Max = 2)
- `alarm/zusammenfassung`: neuer Text "🌧 Extremregen" für `alarm/regen=3`

### Verbesserungen
- **Restart/Start-Button** (index.php): Buttons senden jetzt AJAX-Request statt Seiten-Redirect. Zeigt Status "Neustart läuft…" und wartet bis Daemon läuft, dann automatische Seitenaktualisierung (alle 3s polling, max 60s).
- **Stationen neu laden** (settings.php): Löscht TAWES-Cache UND startet Daemon neu – Stationen werden sofort frisch geladen. UI zeigt Wartestatus und lädt Seite nach erfolgreichem Daemon-Start automatisch neu.

---

## [0.4.18] – 2026-06-09

### Behoben
- `alarm/regen` Level 1 feuerte noch immer wenn TAWES `regen_upstream=1` aber `regen_upstream_mm=0`: Der konservative Fallback (`upstream_mm==0 → alarmieren`) war falsch – `upstream_mm=0` bedeutet Regen nur im 2h-Buffer gefunden, letzte 30 min aber trocken. Jetzt: Alarm nur wenn `upstream_mm >= REGEN_ALARM / 3.0` (strikte Bedingung ohne Fallback).

---

## [0.4.17] – 2026-06-09

### Behoben
- TAWES Stationen nach Installation fehlend: Daemon lädt beim ersten Start nach Plugin-Update die Stationsliste jetzt immer frisch von der API (Cache-File wird beim Daemon-Start einmalig ignoriert). Verhindert Race-Condition zwischen postinstall.sh und Daemon-Start.
- `alarm/regen` Level 1 feuerte bei Nieselregen obwohl REGEN_ALARM=30 mm/h gesetzt: TAWES `regen_upstream` löst jetzt nur einen Alarm aus wenn die Upstream-Intensität (`tawes/regen_upstream_mm`) ≥ REGEN_ALARM/3 ist.
- Unit-Bug: TAWES RR war in mm/10min, `regen_upstream_mm` wurde ohne ×6-Faktor mit REGEN_ALARM (mm/h) verglichen (bei hohen Schwellen durch Zufall korrekt, bei 2 mm/h falsch).

### Hinzugefügt
- Neues MQTT-Topic `tawes/regen_upstream_mm` (mm/h): Max. Regenintensität an Upstream-Stationen – für Diagnose und als Alarm-Gate
- UI zeigt Upstream-Regenintensität in der Regenfront-Zeile an (z.B. "Ankunft unbekannt (3.6 mm/h)")

### Dokumentation
- README + help.php: Alarm/regen-Tabelle mit TAWES Intensitäts-Gate (REGEN_ALARM/3) ergänzt
- README + help.php: `tawes/regen_upstream_mm` als neues Topic dokumentiert
- Regen-Alarmschwelle Beschreibung erklärt TAWES-Threshold-Beziehung
- help.php FAQ: Startup-Fresh-Load Verhalten bei TAWES-Stationen erklärt
- Versionsstrings im Daemon korrigiert (waren noch v0.4.5)

---

## [0.4.16] – 2026-06-09

### Behoben
- Notification-Spam: ETA-Werte und Windstärken werden auf 5er-Schritte gerundet bevor Dedup-Vergleich (verhindert Push alle 5 min durch reine Countdown-Änderung)
- `bald_regen`-Notification nur noch wenn REGEN_ALARM ≤ 2.0 mm/h oder `regen_alarm=1` aktiv – verhindert Spam bei hoher Alarmschwelle
- TAWES Wind-Notification zeigt jetzt Stationsname und Distanz: "💨 Sturmböen Rax (78km): 90 km/h" statt nur "90 km/h"

---

## [0.4.15] – 2026-06-08

### Behoben
- `notification/tawes` wurde doppelt publiziert (in `publish_tawes()` ohne Dedup + in `publish_all()` mit Dedup) – Duplikat entfernt
- `notification/tawes` zeigt jetzt "keine aktiven Warnungen" wenn leer (wie geosphere und inca)

### Dokumentation
- README + help.php: alle MQTT Topics vollständig dokumentiert mit Wertebereichen

---

## [0.4.14] – 2026-06-08

### Hinzugefügt
- `inca/pt_bald` und `inca/pt_bald_name`: Niederschlagstyp des nächsten erwarteten Regens (z.B. "Regen" wenn aktuell trocken aber in 8 min Regen kommt)
- UI zeigt "kein Niederschlag → Regen in ~8 min" wenn pt_jetzt=255 aber Regen vorhergesagt

### Behoben
- INCA-UI zeigte "kein Niederschlag" und "Regen in ~8 min" gleichzeitig – war kein Bug, aber verwirrend. Neue UI-Logik erklärt den Zusammenhang.

---

## [0.4.13] – 2026-06-07

### Geändert (Breaking Change – intentional)
- Alarm-Level-Schema komplett vereinheitlicht: **ZAMG Gelb→1, Orange→2, Rot/Lila→3** für alle 5 Kategorien (Gewitter, Wind, Regen, Hagel, Schnee)
- `aktiv`-Flag ändert den Level nicht mehr (war früher teilweise +1)
- INCA und TAWES können auf max. Level 2 anheben (niemals 3)
- Schnee/Glatteis-Berechnung hatte alten Bug der ZAMG-Stufen (0-4) als Alarm-Level interpretierte

### Dokumentation
- help.php + README: vollständige Alarm-Berechnungstabellen je Kategorie mit Level-Prinzip-Erklärung

---

## [0.4.12] – 2026-06-07

### Dokumentation
- help.php: Alarm-Logik, Wind/Regen-Schwellwerte, Quellenübersicht vollständig dokumentiert
- README: Alle Abschnitte auf aktuellen Stand gebracht

---

## [0.4.11] – 2026-06-07

### Behoben
- Miniserver-Koordinaten-Abruf über HTTPS (Loxone 14+ erzwingt HTTPS)
- Alarm-Schwellen: Wind-Level war bei hohem BOEN_ALARM nicht konsistent

---

## [0.1.5] – 2026-06-05

### Behoben
- MQTT rc=5: mqttgateway.json hat verschachtelte Struktur – Credentials sind unter `Main`-Key, nicht auf oberster Ebene
- Credential-Suche durchsucht jetzt alle Sub-Dicts der Config (Main, Gateway, etc.) nicht nur Top-Level
- Debug-Log zeigt jetzt auch Keys aller Sub-Dicts für vollständige Diagnose

## [0.1.4] – 2026-06-05

### Behoben
- MQTT rc=5: Wurzelursache gefunden – LoxBerry speichert JSON-Keys großgeschrieben (`Brokeruser`, `Brokerpass`, `Brokerhost`, `Brokerport`), Code suchte nur lowercase → Credentials wurden nie gelesen
- MQTT Credential-Lookup jetzt case-insensitiv (funktioniert mit Groß- und Kleinschreibung)
- Suchreihenfolge optimiert: Credentials zuerst direkt in der Haupt-Config suchen (LoxBerry Standard), dann in separaten Dateien
- Debug-Log zeigt jetzt die tatsächlich vorhandenen JSON-Keys der Config-Datei für einfachere Fehlerdiagnose

## [0.1.3] – 2026-06-05

### Behoben
- MQTT rc=5: Erweiterte Suche nach LoxBerry MQTT Credentials in allen bekannten Pfaden und Key-Varianten (cred.json, mqttgatewaycred.json, credentials.json; brokeruser/user/username/mqttuser etc.)
- MQTT: Manueller User/Pass-Override in `unwetter4lox.cfg` wirkt jetzt auch bei `USE_LOXBERRY_MQTT=1` – Workaround wenn Auto-Erkennung fehlschlägt
- MQTT: Detailliertes Logging welche Config-Datei gefunden/nicht gefunden wurde + Hinweis wenn keine Credentials gefunden
- Config-Default: Dokumentation für manuellen Credential-Override direkt in der cfg-Datei

## [0.1.2] – 2026-06-05

### Behoben
- MQTT: Race Condition behoben – Daemon wartete nicht auf erfolgreichen TCP-Verbindungsaufbau bevor Topics publiziert wurden (QoS 0 hat Messages verworfen)
- MQTT: `on_connect` Callback mit klarer Fehlerdiagnose (rc-Code + Klartext) hinzugefügt
- MQTT: `on_disconnect` Callback für automatischen Reconnect im Hauptloop
- MQTT: `publish()` prüft nun ob Verbindung aktiv ist (`_mqtt_connected` Event) statt nur ob Client-Objekt existiert
- MQTT: Verbindungs-Timeout nach 8s mit Log-Meldung statt stillem Fehlschlag

## [0.1.1] – 2026-06-05

### Behoben
- MQTT-Verbindung: Umstellung auf das offizielle LoxBerry Python SDK (`loxberry.mqtt`). Dies behebt Probleme mit dem LoxBerry MQTT Gateway unter LoxBerry 3.0 (korrekte Port- und Credential-Erkennung).
- Logging: Umstellung auf `loxberry.log`. Die Log-Sessions werden nun korrekt in der LoxBerry-Datenbank registriert und im Standard-LogViewer farbig angezeigt.
- Bereinigung der Perl-Log-Bridge, um doppelte `<LOGEND>` Tags zu vermeiden.

## [0.1.0] – 2026-06-05

### Neu
- GeoSphere Austria Warn-API Integration (Windwarnungen, Gewitter, Regen, Schnee, Glatteis, Hitze, Kälte, Hagel)
- INCA Nowcast via GeoSphere Dataset API (Böen, Windgeschwindigkeit, Niederschlagstyp, Hagel/Graupel-Alarm)
- MQTT-Veröffentlichung aller Warnwerte über LoxBerry MQTT Gateway oder manuellem Broker
- Automatische Erkennung der LoxBerry MQTT Gateway Konfiguration
- Web-Frontend: Status-Seite mit Daemon-Steuerung, GeoSphere-Warnlage und INCA-Werten
- Web-Frontend: Einstellungsseite (Standort, MQTT, Intervall, Böen-Schwellwert, Notifications)
- Web-Frontend: Log-Viewer mit LoxBerry-kompatiblem Log-Format
- Notification-Texte für Loxone Push (`notification/alle`, `/geosphere`, `/inca`)
- Entwarnung-Topic wenn alle Warnungen aufgehoben werden

### Behoben
- Daemon-Start-Fehler: `LBHOMEDIR` wird nun robust aus `/etc/environment` gelesen (sudo strippt Env-Vars)
- Log-Verzeichnis wird vor Python-Start sichergestellt
- Kein doppeltes Logging mehr (FileHandler statt StreamHandler+Redirect)
- paho-mqtt >= 2.0 Kompatibilität (CallbackAPIVersion)

### Hinzugefügt
- `preinstall.sh` – Python3-Versionscheck vor Installation
- `uninstall/uninstall` – saubere Deinstallation (Daemon stoppen, Sudoers entfernen)
- `create-plugin-zip.sh` – Build-Script für LoxBerry-installierbare ZIP-Datei
