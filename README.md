# Unwetter4Lox v0.4.0

LoxBerry-Plugin für präzise österreichische Unwetterwarnungen, Kurzzeit-Vorhersagen und Echtzeit-Wetterdaten von naheliegenden Messstationen. Es kombiniert **drei offizielle Datenquellen** der GeoSphere Austria und liefert alle Informationen via **MQTT** an den **Loxone Miniserver**.

---

## Was macht dieses Plugin?

Unwetter4Lox überwacht kontinuierlich die offizielle Warn-Infrastruktur von GeoSphere Austria (früher ZAMG) für deinen genauen Standort und sendet alle relevanten Wetterdaten an deinen Loxone Miniserver. Das ermöglicht vollautomatische Reaktionen: Beschattung einfahren, Bewässerung stoppen, Alarmierung bei Extremwetter.

Das Plugin kombiniert drei unabhängige Datenquellen zu einem **aggregierten Gesamtstatus** (Alarm-Blöcke), der sofort anzeigt: Gibt es gerade Gewitter-, Wind-, Regen-, Hagel- oder Schneegefahr?

---

## Datenquellen

### 1. GeoSphere Austria – Offizielle Wetterwarnungen (ZAMG)

Die offiziellen Warnungen der österreichischen Wetterbehörde für 8 Wettertypen:
- **Wind/Sturm** – ab 60 km/h Böen
- **Regen/Überflutung** – Starkregen, Hochwassergefahr
- **Schnee/Eis** – Schneefall, Glatteis
- **Gewitter** – mit und ohne Hagel
- **Hagel** – separater Hagelwarner
- **Hitze** – Hitzewellen
- **Kälte** – Frostwarnungen

Warnstufen: 1 (Gelb) bis 4 (Lila/Extrem). Das Plugin prüft alle aktiven Warnungen und Vorwarnungen für deinen Standort.

**API:** `https://warnungen.zamg.at/wsapp/api/getWarnings`

### 2. INCA Nowcast – 15-Minuten-Kurzvorhersage

Hochauflösende Vorhersage für die nächsten 60 Minuten (Schritte à 15 Minuten) direkt für deine GPS-Koordinaten:
- Aktueller Niederschlag (mm/h) und Niederschlagstyp
- Böenstärke (km/h) – maximale Böen in den nächsten 60 Minuten
- Hagelgefahr (Ja/Nein)
- Minuten bis zum nächsten Regen (oder -1 wenn aktuell trocken bleibt)
- Temperatur, Luftdruck, Luftfeuchtigkeit (aktuelle Werte)

Die INCA-Daten eignen sich ideal für reaktive Automatisierungen: Beschattung bei nahenden Böen einfahren, Bewässerung 15 Minuten vor Regenfront stoppen.

**API:** `https://dataset.api.hub.geosphere.at/v1/timeseries/historical/inca-v1-1h-1km`

### 3. TAWES 360° – Echtzeit-Wetterstationsnetz

Das Plugin sucht automatisch alle TAWES-Wetterstationen der GeoSphere Austria im einstellbaren Umkreis (Standard: 120 km) und wertet deren Echtzeitmessungen aus. Daraus werden berechnet:

- **Dominante Windrichtung** – vektorgewichtet aus allen Stationen (sin/cos-Methode, korrekte Kreisstatistik)
- **Upstream-Stationen** – welche Stationen liegen in der Anströmrichtung? Diese sind besonders relevant, weil das Wetter von dort kommt.
- **Maximale Böen upstream** – schlimmste Böen die aus der Windrichtung kommen
- **Wind-Trend** – Steigt oder fällt die Windgeschwindigkeit? (Lineare Regression über 2 Stunden)
- **Regenfront** – Bewegt sich Regen auf deinen Standort zu? Wenn ja: Frontgeschwindigkeit und ETA in Minuten
- **Gewittersignal** – Kombination aus Luftdruckabfall + Luftfeuchtigkeitsanstieg + rasant steigenden Böen
- **Luftdrucktrend** – Steigt oder fällt der Luftdruck? (Regression über 2 Stunden)

Die TAWES-Daten werden im 2-Stunden-Ringpuffer (12 × 10-Minuten-Intervalle) gespeichert für Trendberechnungen.

**API:** `https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min`

---

## Aggregierter Gesamtstatus (Alarm)

Alle drei Quellen werden automatisch zu einem einheitlichen Gesamtstatus zusammengeführt:

| Kategorie | Quellen |
|:---|:---|
| Gewitter | ZAMG Gewitterwarnung + TAWES Gewittersignal |
| Wind/Sturm | ZAMG Windwarnung + INCA Böen + TAWES Upstream-Böen |
| Regen | ZAMG Regenwarnung + INCA Niederschlag + TAWES Regenfront |
| Hagel | ZAMG Hagelwarnung + INCA Hagelgefahr |
| Schnee/Eis | ZAMG Schnee/Glatteis-Warnung |

Alarmstufen: `0` = Keine, `1` = Möglich, `2` = Aktiv, `3` = AKUT

---

## MQTT Schnittstelle (vollständige Referenz)

Standard-Präfix: `unwetter/` (konfigurierbar in den Einstellungen)

Alle Topics werden mit `retain=true` publiziert, damit Loxone den letzten Wert sofort nach Verbindungsaufbau erhält.

### System-Topics

| Topic | Beschreibung | Werte |
|:---|:---|:---|
| `status` | Systemzustand des Daemons | `OK` / `Error - [Quelle]` |
| `letzter_abruf_datum` | Zeitstempel des letzten Abrufs | `07.06.2026 14:30:00` |
| `letzter_abruf_epoch` | Unix-Timestamp des letzten Abrufs | `1749303000` |

### Gesamtstatus-Topics (alarm/)

Diese Topics kombinieren alle drei Quellen zu einheitlichen Alarm-Leveln. Ideal für Loxone-Automatisierungen.

| Topic | Beschreibung | Werte |
|:---|:---|:---|
| `alarm/gewitter` | Gewittergefahr kombiniert | `0`=Keine, `1`=Möglich, `2`=Aktiv, `3`=AKUT |
| `alarm/wind` | Windgefahr kombiniert | `0`=Keine, `1`=Möglich, `2`=Aktiv, `3`=AKUT |
| `alarm/regen` | Regenrisiko kombiniert | `0`=Keine, `1`=Möglich, `2`=Aktiv |
| `alarm/hagel` | Hagelgefahr kombiniert | `0`=Keine, `1`=Möglich, `2`=Aktiv |
| `alarm/schnee` | Schnee/Eisrisiko kombiniert | `0`=Keine, `1`=Möglich, `2`=Aktiv |
| `alarm/stufe` | Höchste ZAMG-Warnstufe | `0`–`4` |
| `alarm/zusammenfassung` | Kurzzusammenfassung | `Gewitter AKUT, Wind aktiv` / `Keine Warnungen` |

### GeoSphere Austria Warnungen (zamg/)

| Topic | Beschreibung | Werte |
|:---|:---|:---|
| `zamg/max_stufe` | Höchste aktive Warnstufe | `0` (Keine) bis `4` (Lila/Extrem) |
| `zamg/irgendwas_aktiv` | Mindestens eine aktive/baldige Warnung | `0` / `1` |
| `zamg/{typ}/stufe` | Warnstufe je Typ | `0`–`4` |
| `zamg/{typ}/aktiv` | Warnung gerade aktiv | `0` / `1` |
| `zamg/{typ}/bald` | Warnung beginnt in < 6h | `0` / `1` |
| `zamg/{typ}/notification` | Klartext für Push | `⚠️ ORANGE – Wind | heute 14:00 bis 20:00` |

**Typen (`{typ}`):** `wind`, `regen`, `schnee`, `glatteis`, `gewitter`, `hagel`, `hitze`, `kaelte`

**Warnstufen:** `1`=Gelb, `2`=Orange, `3`=Rot, `4`=Lila

### INCA Nowcast (inca/)

| Topic | Beschreibung | Einheit |
|:---|:---|:---|
| `inca/boen_max_60min` | Maximale Böen nächste 60 Min | km/h |
| `inca/niederschlag` | Aktueller Niederschlag | mm/h |
| `inca/minuten_bis_regen` | Zeit bis Regenstart | Minuten (`-1` = bleibt trocken) |
| `inca/bald_hagel` | Hagelgefahr in < 60 Min | `0` / `1` |
| `inca/niederschlag_typ` | Art des Niederschlags | `Regen`, `Schnee`, `Hagel`, `Trocken` |
| `inca/temperatur` | Aktuelle Temperatur | °C |
| `inca/luftfeuchtigkeit` | Aktuelle Luftfeuchtigkeit | % |
| `inca/luftdruck` | Aktueller Luftdruck | hPa |
| `inca/windgeschwindigkeit` | Aktuelle Windgeschwindigkeit | km/h |
| `inca/windrichtung` | Aktuelle Windrichtung | Grad (0–360) |
| `inca/globalstrahlung` | Solarstrahlung | W/m² |
| `inca/akut_warnung` | Akute Warnung aktiv | `0` / `1` |
| `inca/notification` | Klartext für Push | `💨 Böen 72 km/h in 15 min` |

### TAWES 360° Stationsdaten (tawes/)

| Topic | Beschreibung | Einheit/Werte |
|:---|:---|:---|
| `tawes/windrichtung` | Dominante Windrichtung (vektorgewichtet) | Grad (0–360) |
| `tawes/windrichtung_name` | Windrichtung als Text | `N`, `NE`, `E`, `SE`, `S`, `SW`, `W`, `NW` |
| `tawes/upstream_count` | Anzahl aktiver Upstream-Stationen | Ganzzahl |
| `tawes/wind_upstream_max` | Max. Böen aus Anströmrichtung | km/h |
| `tawes/wind_trend` | Wind-Trendstärke (Regression 2h) | km/h pro 10 Min, positiv=steigend |
| `tawes/regen_upstream` | Regen aus Anströmrichtung | `0` (Nein) / `1` (Ja) |
| `tawes/regen_eta_min` | Minuten bis Regenfront ankommt | Minuten (`-1` = ETA unbekannt) |
| `tawes/front_speed_kmh` | Geschwindigkeit der Regenfront | km/h |
| `tawes/gewitter_signal` | Gewitterindikator | `0`=Nein, `1`=Möglich, `2`=Akut |
| `tawes/druck_trend` | Luftdrucktrend (Regression 2h) | hPa pro 10 Min, negativ=fallend |
| `tawes/stationen_anzahl` | Stationen im konfigurierten Radius | Ganzzahl |
| `tawes/letztes_update` | Zeitstempel letzter TAWES-Abruf | ISO-Timestamp |
| `tawes/confidence` | Datenzuverlässigkeit | `0.0`–`1.0` |

### Notifications (notification/)

| Topic | Beschreibung |
|:---|:---|
| `notification/geosphere` | ZAMG-Warnungen kombiniert als Klartext |
| `notification/inca` | INCA-Meldung als Klartext |
| `notification/tawes` | TAWES-Meldung als Klartext |
| `notification/alle` | Alle drei Quellen kombiniert (durch `──` getrennt) |

---

## Installation

1. ZIP-Datei über den **LoxBerry Plugin Manager** installieren
2. In die **Einstellungen** wechseln
3. **Standort festlegen** – Adresse eingeben und auf "Suchen" klicken, oder über den Button "Vom Loxone Miniserver" automatisch den Miniserver-Standort übernehmen
4. Dienste aktivieren: **GeoSphere (ZAMG)**, **INCA Nowcast**, **TAWES 360°**
5. MQTT-Broker einstellen (Standard: LoxBerry MQTT Gateway automatisch)
6. Daemon im **Status-Tab** starten
7. In **Loxone Pro**: MQTT Virtual Inputs für die gewünschten Topics anlegen

### Mindestanforderungen

- LoxBerry 2.x oder höher
- LoxBerry MQTT Gateway installiert und konfiguriert (oder manuell MQTT-Broker)
- Python 3.7+
- Internetverbindung zu GeoSphere Austria APIs

---

## Loxone Integration

### Empfohlene Virtual Inputs

```
# Gesamtstatus (einfachste Integration)
MQTT Topic: unwetter/alarm/gewitter   → Virtueller Eingang "Gewittergefahr"
MQTT Topic: unwetter/alarm/wind       → Virtueller Eingang "Windgefahr"
MQTT Topic: unwetter/alarm/regen      → Virtueller Eingang "Regenrisiko"

# INCA für Echtzeit-Automatisierungen
MQTT Topic: unwetter/inca/boen_max_60min     → Virtueller Eingang "Böen 60min"
MQTT Topic: unwetter/inca/minuten_bis_regen  → Virtueller Eingang "Min bis Regen"
MQTT Topic: unwetter/inca/bald_hagel         → Virtueller Eingang "Hagelgefahr"

# ZAMG für Warnstufen
MQTT Topic: unwetter/zamg/max_stufe          → Virtueller Eingang "ZAMG Warnstufe"
MQTT Topic: unwetter/zamg/wind/stufe         → Virtueller Eingang "Windwarnstufe"
```

### Automatisierungsbeispiele

**Markisen automatisch einfahren:**
- Auslöser: `alarm/wind` ≥ 2 ODER `inca/boen_max_60min` > 50

**Bewässerung stoppen:**
- Auslöser: `inca/minuten_bis_regen` ≥ 0 UND `inca/minuten_bis_regen` < 30

**Push-Benachrichtigung bei Unwetter:**
- Auslöser: `alarm/gewitter` ≥ 2 ODER `zamg/max_stufe` ≥ 2
- Nachricht: `notification/alle`

---

## Einstellungen

| Option | Beschreibung | Standard |
|:---|:---|:---|
| Breitengrad / Längengrad | GPS-Koordinaten deines Standorts | – |
| Abruf-Intervall | Wie oft Daten abgerufen werden | 300 Sekunden |
| INCA aktivieren | INCA Nowcast einschalten | Ja |
| INCA Zeithorizont | Vorschau-Fenster | 60 Minuten |
| Böen-Alarmschwelle | Ab wann INCA Böen-Alarm auslöst | 60 km/h |
| Min. Warnstufe | Ab welcher ZAMG-Stufe Notification | 1 (Gelb) |
| TAWES aktivieren | TAWES 360° einschalten | Ja |
| TAWES Stationsradius | Suchradius für Wetterstationen | 120 km |
| MQTT Präfix | Präfix für alle MQTT Topics | `unwetter/` |
| MQTT Broker | Broker-Host (Standard: LoxBerry auto) | automatisch |

---

## Datenquellen & Rechtliches

Dieses Plugin nutzt ausschließlich öffentlich zugängliche Daten von [GeoSphere Austria](https://www.geosphere.at):

- [GeoSphere Warn-API](https://warnungen.zamg.at) – Offizielle Wetterwarnungen
- [INCA Nowcast](https://www.geosphere.at/de/daten-und-dienste) – Hochauflösende Kurzvorhersage
- [TAWES Stationsdaten](https://dataset.api.hub.geosphere.at) – Echtzeit-Messstationen

Geocoding via [Nominatim / OpenStreetMap](https://nominatim.org) (kostenlos, kein API-Key nötig).

**Haftungsausschluss:** Die Daten dienen der Information und Hausautomation. Für die Richtigkeit der Wettervorhersagen und daraus resultierende automatisierte Handlungen wird keine Haftung übernommen.

---

## Entwickler

- **Autor:** HitSmart / Stefan Hörmandinger
- **GitHub:** [HitsmartDev/Unwetter4Lox](https://github.com/HitsmartDev/Unwetter4Lox)
- **Lizenz:** MIT
