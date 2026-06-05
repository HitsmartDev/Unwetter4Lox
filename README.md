# Unwetter4Lox

LoxBerry-Plugin: Österreichische Unwetterwarnungen (GeoSphere Austria) und INCA Nowcast per MQTT an den Loxone Miniserver.

---

## Was macht das Plugin?

- Ruft alle N Sekunden (Standard: 300) die **GeoSphere Austria Warn-API** ab
- Ruft den **INCA Nowcast** (Böen, Niederschlag, Hagel/Graupel) ab
- Veröffentlicht alle Werte per **MQTT** über das LoxBerry MQTT Gateway (oder manuell konfigurierten Broker)
- Erstellt fertige **Notification-Texte** für Loxone Push-Nachrichten

---

## Voraussetzungen

| Anforderung | Mindestversion |
|---|---|
| LoxBerry | 2.0 |
| Python | 3.8 |
| LoxBerry MQTT Gateway | empfohlen (optional) |

---

## Installation

1. [`create-plugin-zip.sh`](create-plugin-zip.sh) ausführen (auf Linux/macOS):
   ```bash
   chmod +x create-plugin-zip.sh
   ./create-plugin-zip.sh
   ```
   Oder: ZIP-Datei direkt aus dem GitHub-Release herunterladen.

2. In LoxBerry: **Plugin Manager** → **ZIP-Datei installieren** → die erzeugte `.zip` hochladen

3. Nach der Installation: **Einstellungen** im Plugin-Tab öffnen und Standort (Lat/Lon) eintragen

4. Daemon über den **Status-Tab** starten

---

## Konfiguration

| Parameter | Bedeutung | Standard |
|---|---|---|
| LAT / LON | GPS-Koordinaten des Standorts | 47.952835 / 13.791286 |
| NAME | Bezeichnung des Standorts | Mein Zuhause |
| USE_LOXBERRY_MQTT | LoxBerry MQTT Gateway automatisch verwenden | 1 (ein) |
| TOPIC_PREFIX | MQTT Topic-Präfix | `haus/wetter` |
| INTERVAL | Abfrageintervall in Sekunden | 300 |
| BOEN_ALARM | Böen-Alarmschwelle km/h | 60 |
| INCA / ENABLED | INCA Nowcast aktivieren | 1 |
| INCA / HORIZON_MINUTES | Zeithorizont für Max-Böen | 60 |
| MIN_STUFE | Mindeststufe für Notification-Text | 1 |

---

## MQTT Topics

Alle Topics beginnen mit `TOPIC_PREFIX` (Standard: `haus/wetter`).

### GeoSphere Warnungen

```
haus/wetter/warnung/wind/stufe          0–4 (0=keine, 1=Gelb, 2=Orange, 3=Rot, 4=Lila)
haus/wetter/warnung/wind/aktiv          0 oder 1
haus/wetter/warnung/wind/bald           0 oder 1 (Warnung beginnt in <30 min)
haus/wetter/warnung/wind/start_text     z.B. "heute 14:00"
haus/wetter/warnung/wind/end_text       z.B. "morgen 08:00"
haus/wetter/warnung/wind/notification   Fertigtext für Loxone Push
```

Gleiche Struktur für: `regen`, `schnee`, `glatteis`, `gewitter`, `hagel`, `hitze`, `kaelte`

```
haus/wetter/warnung/akutwarnung         0 oder 1 (GeoSphere GWA-Stationswarnung)
haus/wetter/warnung/max_stufe           0–4
haus/wetter/warnung/irgendwas_aktiv    0 oder 1
```

### INCA Nowcast

```
haus/wetter/inca/boen_jetzt_kmh         aktuelle Böenstärke in km/h
haus/wetter/inca/wind_jetzt_kmh         aktueller Windmittelwert km/h
haus/wetter/inca/boen_max_30min         max. Böen nächste 30 min km/h
haus/wetter/inca/boen_max_60min         max. Böen nächste 60 min km/h
haus/wetter/inca/niederschlag_jetzt     Niederschlagsrate mm/h
haus/wetter/inca/niederschlag_typ       255=kein, 1=Regen, 2=Schnee, 3=Schneeregen, 4=Graupel, 5=Hagel
haus/wetter/inca/niederschlag_typ_name  Klartext
haus/wetter/inca/bald_regen             0/1 – Regen in <30 min
haus/wetter/inca/bald_hagel             0/1 – Hagel in <60 min
haus/wetter/inca/bald_graupel           0/1 – Graupel in <60 min
haus/wetter/inca/bald_sturm_30min       0/1 – Böen >= Schwellwert in <30 min
haus/wetter/inca/bald_sturm_60min       0/1 – Böen >= Schwellwert in <60 min
haus/wetter/inca/minuten_bis_regen      Minuten bis nächster Regen, -1 = kein Regen geplant
```

### Notifications (Loxone Push)

```
haus/wetter/notification/geosphere      Alle GeoSphere-Warnungen als Text
haus/wetter/notification/inca           INCA Nowcast-Zusammenfassung
haus/wetter/notification/alle           Kombination aus beidem
haus/wetter/notification/neu_geosphere  1 wenn neue Warnungen (retain=false, Puls)
haus/wetter/notification/entwarnung     Text wenn alle Warnungen aufgehoben (retain=false)
haus/wetter/letztes_update              Zeitstempel letztes Update
```

---

## Loxone Miniserver Einrichtung

1. Im **Loxone Config**: Virtuellen HTTP-Eingang oder MQTT-Verbindung zum LoxBerry MQTT Gateway einrichten
2. Virtuelle Eingänge für gewünschte Topics anlegen
3. Für Push-Nachrichten: Topic `notification/alle` abonnieren und in Notification-Block einspeisen

---

## Fehlerdiagnose

### Daemon startet nicht

```bash
# Per SSH auf LoxBerry
sudo /opt/loxberry/system/daemons/plugins/unwetter4lox start

# Status prüfen
sudo /opt/loxberry/system/daemons/plugins/unwetter4lox status

# Log prüfen
cat /opt/loxberry/log/plugins/unwetter4lox/unwetter4lox.log
```

### paho-mqtt fehlt

```bash
pip3 install paho-mqtt
# oder:
sudo apt-get install python3-paho-mqtt
```

### MQTT-Verbindung schlägt fehl

- LoxBerry MQTT Gateway Plugin installiert und aktiv?
- Bei manueller Konfiguration: Broker-IP und Port in den Plugin-Einstellungen prüfen

---

## Entwicklung / Build

```bash
# ZIP für LoxBerry Plugin-Installer erstellen
./create-plugin-zip.sh
```

---

## Datenquellen

- **GeoSphere Austria Warn-API**: `https://warnungen.zamg.at/wsapp/api/`  
  Kostenlos, keine API-Key erforderlich, österreichweite Wetterwarnungen

- **GeoSphere Dataset API (INCA)**: `https://dataset.api.hub.geosphere.at/`  
  Kostenlos, keine API-Key erforderlich, Nowcast-Daten für Österreich

---

## Lizenz

MIT License – © 2026 Stefan Hörmandinger / HitSmart
