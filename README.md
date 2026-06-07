# Unwetter4Lox v0.4.2

**LoxBerry-Plugin für automatische Unwettererkennung und Wetterautomatisierung.**

Unwetter4Lox kombiniert drei offizielle österreichische Wetterdatenquellen der GeoSphere Austria zu einem einheitlichen Alarmstatus und liefert alle Daten via MQTT an deinen Loxone Miniserver. Das Plugin erkennt Gewitter, Sturm, Starkregen, Hagel und Schnee – sowohl aus offiziellen Warnungen als auch aus Echtzeitmessdaten und Kurzvorhersagen.

---

## Was macht dieses Plugin?

Das Plugin läuft als Hintergrunddienst (Daemon) auf deinem LoxBerry und fragt automatisch drei Datenquellen ab:

1. **Offizielle ZAMG-Warnungen** – Behördliche Wetterwarnungen für deinen genauen Standort
2. **INCA Nowcast** – Hochauflösende 15-Minuten-Vorhersage für die nächste Stunde
3. **TAWES 360°** – Echtzeitmessungen von Wetterstationen in deiner Umgebung

Alle drei Quellen werden automatisch zu einem **aggregierten Gesamtstatus** zusammengefasst (die `alarm/`-Topics), damit du in Loxone nicht drei verschiedene Werte auswerten musst – ein einziger Wert pro Kategorie reicht für deine Automatisierungen.

---

## Datenquellen im Detail

### 1. GeoSphere Austria – Offizielle Warnungen

Die Austrian Weather Service behördlichen Warnungen, nach Standort gefiltert. Das Plugin prüft 8 Wettertypen:

| Typ | Beschreibung |
|:----|:-------------|
| `wind` | Sturmböen (ab ~60 km/h abhängig von der Stufe) |
| `regen` | Starkregen, Überflutungsgefahr |
| `schnee` | Starker Schneefall |
| `glatteis` | Glatteis, Eisregen |
| `gewitter` | Gewitter (mit/ohne Hagel) |
| `hagel` | Hagelschlag |
| `hitze` | Hitzewelle |
| `kaelte` | Kälteeinbruch, Frost |

**Warnstufen:** 1 = Gelb (Vorsicht), 2 = Orange (Warnung), 3 = Rot (erhebliche Gefahr), 4 = Lila (Extrem)

### 2. INCA Nowcast

Hochauflösende Kurzvorhersage für deinen GPS-Punkt, aktualisiert alle 15 Minuten:
- Böenstärke jetzt und in den nächsten 30/60 Minuten
- Aktuelle Regenrate (mm/h) und Niederschlagstyp
- Hagelgefahr in den nächsten 60 Minuten
- Minuten bis zum nächsten Regen

### 3. TAWES 360° – Wetterstationsnetz

Das Plugin sucht alle TAWES-Stationen im einstellbaren Umkreis und wertet deren Echtzeitmessungen aus. Daraus wird berechnet:
- Welche Stationen liegen in der Windrichtung (**Upstream**)? – Diese sind besonders relevant, weil das Wetter von dort kommt.
- Nähert sich eine Regenfront an? → **ETA in Minuten**
- Wie stark sind die Böen aus Windrichtung? → **Upstream-Böen**
- Entwickeln sich gerade Gewitter? → **Gewittersignal** (Druckabfall + Luftfeuchtigkeit)

---

## Alarmstufen verstehen

Alle `alarm/`-Topics verwenden einheitliche Stufen:

| Wert | Bedeutung | Farbe |
|:----:|:----------|:------|
| `0` | Keine Gefahr | Grün |
| `1` | Möglich / Vorsicht | Gelb |
| `2` | Aktiv / Warnung | Orange |
| `3` | AKUT / Extrem | Rot |

### Wie werden Alarmstufen berechnet?

Jede Kategorie aggregiert mehrere Quellen. Beispiel für **Wind**:

| Quelle | Bedingung | → Alarmstufe |
|:-------|:----------|:------------|
| ZAMG | Windwarnung Stufe 1–4 | 1–3 |
| INCA | Böen ≥ **BOEN_ALARM** in < 60 min | 1 |
| INCA | Böen ≥ **BOEN_ALARM** in < 30 min | 2 |
| TAWES | Upstream-Böen ≥ **BOEN_ALARM** | 1 |
| TAWES | Upstream-Böen ≥ **2 × BOEN_ALARM** | 2 |

Beispiel für **Regen**:

| Quelle | Bedingung | → Alarmstufe |
|:-------|:----------|:------------|
| ZAMG | Regenwarnung | 1–2 |
| INCA | Regen erwartet / > 0.1 mm/h | 1 |
| INCA | Aktuelle Regenrate ≥ **REGEN_ALARM** | 2 |
| TAWES | Regenfront > 30 min entfernt | 1 |
| TAWES | Regenfront < 30 min entfernt | 2 |

---

## Konfigurierbare Schwellwerte

Zwei Schwellwerte in den Einstellungen bestimmen, ab wann Alarme ausgelöst werden:

### Böen-Alarmschwelle (`BOEN_ALARM`)

**Standard: 60 km/h** (= Beaufort 8, Sturmböen)

Dieser Wert wird an **drei Stellen** verwendet:
1. **INCA `bald_sturm_*` Flags** – Werden gesetzt wenn Böen ≥ Schwelle vorhergesagt sind
2. **TAWES `sturm_upstream` Flag** – Gesetzt wenn Upstream-Böen ≥ Schwelle
3. **`alarm/wind` Level 1** – Wenn TAWES Upstream-Böen ≥ Schwelle
4. **`alarm/wind` Level 2** – Wenn TAWES Upstream-Böen ≥ 2× Schwelle

Empfehlungen:
- `40 km/h` – Empfindlich, auch für leichte Automatisierungen (Markisen ab Beaufort 6)
- `60 km/h` – Standard für Unwetterschutz
- `80 km/h` – Nur bei wirklich starkem Sturm (Beaufort 9+)

### Regen-Alarmschwelle (`REGEN_ALARM`)

**Standard: 2.0 mm/h** (leichter Regen)

Dieser Wert bestimmt ab welcher **aktuellen Regenrate** `inca/regen_alarm = 1` gesetzt wird und `alarm/regen` auf Level 2 springt.

Empfehlungen:
- `0.5 mm/h` – Schon bei Nieselregen (für Bewässerungsabschaltung)
- `2.0 mm/h` – Standard, deutlich spürbarer Regen
- `10.0 mm/h` – Nur bei starkem Regen
- `20.0 mm/h` – Nur bei Starkregen (Überflutungsgefahr)

---

## Vollständige MQTT-Topic-Referenz

Standard-Präfix: `unwetter/` (in den Einstellungen änderbar)

Alle Topics werden mit `retain=true` publiziert (Loxone bekommt den letzten Wert direkt beim MQTT-Verbindungsaufbau).

### System-Topics

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `status` | Systemzustand des Daemons | `OK` / `Error - [Quelle]` |
| `letzter_abruf_datum` | Zeitpunkt des letzten Abrufs | `07.06.2026 14:30:00` |
| `letzter_abruf_epoch` | Unix-Timestamp des letzten Abrufs | `1749303000` |

### Gesamtstatus (alarm/)

**Empfohlen für Loxone-Automatisierungen.** Kombiniert alle drei Datenquellen.

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `alarm/gesamt` | Höchster Alarmwert aller Kategorien | `0`–`3` |
| `alarm/gewitter` | Gewittergefahr | `0`–`3` |
| `alarm/wind` | Windgefahr / Sturm | `0`–`3` |
| `alarm/regen` | Regenrisiko | `0`–`2` |
| `alarm/hagel` | Hagelgefahr | `0`–`2` |
| `alarm/schnee` | Schnee / Eisrisiko | `0`–`2` |
| `alarm/stufe` | Höchste ZAMG-Warnstufe | `0`–`4` |
| `alarm/zusammenfassung` | Lesbare Zusammenfassung | `⚡ Gewitter AKUT \| 💨 Sturm aktiv` |

**Alarmstufen:** `0`=Keine, `1`=Möglich/Vorsicht, `2`=Aktiv/Warnung, `3`=AKUT/Extrem

### GeoSphere Austria Warnungen (zamg/)

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `zamg/max_stufe` | Höchste aktive Warnstufe | `0`–`4` |
| `zamg/irgendwas_aktiv` | Mind. eine aktive/baldige Warnung | `0` / `1` |
| `zamg/akutwarnung` | Behördliche Akutwarnung | `0` / `1` |
| `zamg/{typ}/stufe` | Warnstufe je Wettertyp | `0`–`4` |
| `zamg/{typ}/aktiv` | Warnung gerade aktiv | `0` / `1` |
| `zamg/{typ}/bald` | Warnung beginnt in < 30 min | `0` / `1` |
| `zamg/{typ}/notification` | Klartext für Push | `ORANGE – Wind \| heute 14:00–20:00` |

**Typen `{typ}`:** `wind`, `regen`, `schnee`, `glatteis`, `gewitter`, `hagel`, `hitze`, `kaelte`

### INCA Nowcast (inca/)

| Topic | Beschreibung | Einheit |
|:------|:-------------|:--------|
| `inca/fx` | Aktuelle Böenstärke | km/h |
| `inca/ff` | Aktuelle Windgeschwindigkeit | km/h |
| `inca/fx_max_30min` | Max. Böen in den nächsten 30 min | km/h |
| `inca/fx_max_60min` | Max. Böen in den nächsten 60 min | km/h |
| `inca/rr` | Aktuelle Regenrate | mm/h |
| `inca/regen_alarm` | Regenrate ≥ REGEN_ALARM | `0` / `1` |
| `inca/minuten_bis_regen` | Zeit bis Regenstart | min (`-1` = bleibt trocken) |
| `inca/bald_regen` | Regen in < 30 min erwartet | `0` / `1` |
| `inca/bald_hagel` | Hagel in < 60 min erwartet | `0` / `1` |
| `inca/bald_graupel` | Graupel in < 60 min möglich | `0` / `1` |
| `inca/bald_sturm_30` | Böen ≥ BOEN_ALARM in < 30 min | `0` / `1` |
| `inca/bald_sturm_60` | Böen ≥ BOEN_ALARM in < 60 min | `0` / `1` |
| `inca/pt` | Niederschlagstyp Code | `1`=Regen, `2`=Schnee, `4`=Graupel, `5`=Hagel, `255`=kein |
| `inca/pt_name` | Niederschlagstyp Text | `Regen`, `Schnee`, `Hagel`, `Trocken` |

### TAWES 360° Stationsdaten (tawes/)

| Topic | Beschreibung | Einheit/Werte |
|:------|:-------------|:-------------|
| `tawes/dominante_windrichtung` | Dominante Windrichtung (vektorgewichtet) | Grad (0–360) |
| `tawes/dominante_windrichtung_name` | Windrichtung als Text | `N`, `NE`, `E`, `SE`, `S`, `SW`, `W`, `NW` |
| `tawes/upstream_aktiv` | Anzahl aktiver Upstream-Stationen | Ganzzahl |
| `tawes/wind_upstream_kmh` | Max. Böen aus Windrichtung | km/h |
| `tawes/sturm_upstream` | Upstream-Böen ≥ BOEN_ALARM | `0` / `1` |
| `tawes/wind_trend` | Wind-Trendrichtung (2h Regression) | `-1`=fallend, `0`=stabil, `1`=steigend |
| `tawes/regen_upstream` | Regen aus Windrichtung kommend | `0` / `1` |
| `tawes/regen_eta_min` | Minuten bis Regenfront ankommt | min (`-1` = unbekannt) |
| `tawes/front_speed_kmh` | Geschwindigkeit der Regenfront | km/h |
| `tawes/regen_konfidenz` | Zuverlässigkeit der Vorhersage | `0`–`100` (%) |
| `tawes/gewitter_signal` | Gewitterindikator | `0`=Nein, `1`=Möglich, `2`=Akut |
| `tawes/druck_trend` | Luftdrucktrend (2h Regression) | hPa/10min, negativ=fallend |
| `tawes/stationen_anzahl` | Stationen im Radius | Ganzzahl |
| `tawes/letztes_update` | Zeitstempel letzter TAWES-Abruf | Datum/Uhrzeit |
| `tawes/naechste_station` | Nächste Upstream-Station | `Klagenfurt (45km, NW)` |

### Notifications (notification/)

Textmeldungen für Loxone Push-Benachrichtigungen. Werden **nur publiziert wenn sich der Inhalt ändert** (keine Spam-Wiederholungen).

| Topic | Beschreibung |
|:------|:-------------|
| `notification/geosphere` | ZAMG-Warnungen als Klartext. Bei Entwarnung: `✅ Entwarnung – alle Wetterwarnungen aufgehoben.` |
| `notification/inca` | INCA-Lage als Klartext |
| `notification/tawes` | TAWES-Meldung als Klartext |
| `notification/alle` | Alle drei kombiniert (durch `──` getrennt) |

---

## Installation

1. ZIP über den **LoxBerry Plugin Manager** installieren
2. In die **Einstellungen** wechseln
3. **Standort festlegen** – Adresse eingeben → "Suchen", oder "Vom Miniserver" Button
4. Dienste aktivieren: ZAMG, INCA Nowcast, TAWES 360°
5. Schwellwerte nach Bedarf anpassen (Böen-Alarm, Regen-Alarm)
6. MQTT-Broker einstellen (Standard: LoxBerry MQTT Gateway automatisch)
7. Daemon im **Status-Tab** starten
8. In **Loxone Pro**: MQTT Virtual Inputs anlegen

---

## Einstellungen Übersicht

| Einstellung | Standard | Beschreibung |
|:------------|:---------|:-------------|
| Breitengrad / Längengrad | – | GPS-Koordinaten deines Standorts |
| ZAMG aktivieren | Ja | Offizielle Wetterwarnungen abrufen |
| INCA Nowcast aktivieren | Ja | 15-Min-Vorhersage abrufen |
| INCA Zeithorizont | 60 min | Wie weit INCA vorausschaut |
| TAWES 360° aktivieren | Ja | Wetterstationsnetz auswerten |
| TAWES Stationsradius | 120 km | Suchradius für TAWES-Stationen |
| Abruf-Intervall | 300 s | Wie oft Daten abgerufen werden |
| **Böen-Alarmschwelle** | **60 km/h** | **INCA + TAWES Wind-Alarm ab diesem Wert** |
| **Regen-Alarmschwelle** | **2.0 mm/h** | **INCA Regen-Alarm ab dieser Regenrate** |
| Min. Warnstufe | 1 (Gelb) | Ab welcher ZAMG-Stufe Notification-Text erzeugt wird |
| MQTT Präfix | `unwetter/` | Präfix für alle MQTT Topics |
| MQTT Broker | automatisch | Standard: LoxBerry MQTT Gateway |

---

## Loxone Integration – Praktische Beispiele

### Empfohlene Virtual Inputs für den Einstieg

```
# Einfachste Integration – 1 Wert für alles
unwetter/alarm/gesamt         → "Wetteralarm gesamt"   (0=OK, 1=Vorsicht, 2=Warnung, 3=AKUT)

# Pro Kategorie (für gezielte Automatisierungen)
unwetter/alarm/wind           → "Wind-Alarm"
unwetter/alarm/regen          → "Regen-Alarm"
unwetter/alarm/gewitter       → "Gewitter-Alarm"
unwetter/alarm/hagel          → "Hagel-Alarm"

# Loxone Push-Benachrichtigung
unwetter/notification/alle    → "Wettermeldung" (Text für Push)
```

### Automatisierungsbeispiele

**Markisen automatisch einfahren:**
```
Auslöser: alarm/wind >= 2  ODER  inca/bald_sturm_30 = 1
Aktion:   Markisen einfahren, Meldung senden
```

**Bewässerung stoppen:**
```
Auslöser: inca/minuten_bis_regen >= 0  UND  inca/minuten_bis_regen <= 20
          ODER  alarm/regen >= 1
Aktion:   Bewässerungsprogramm abbrechen
```

**Push bei Unwetter:**
```
Auslöser: alarm/gesamt >= 2  (Wert geändert)
Nachricht: notification/alle
```

**Entwarnung nach Unwetter:**
```
Auslöser: alarm/gesamt = 0  UND  vorheriger Wert > 0
Nachricht: notification/geosphere  (enthält "✅ Entwarnung")
```

**Hagelschutz für Fahrzeuge:**
```
Auslöser: alarm/hagel >= 1  ODER  inca/bald_hagel = 1
Aktion:   Carport-Tor schließen, Notification senden
```

---

## Technische Details

### Wie funktioniert TAWES 360°?

Das Plugin lädt täglich die Liste aller TAWES-Messstationen aus der GeoSphere-API und speichert sie als lokalen Cache. Bei jeder Messung (alle 10 Minuten) werden die nächsten Stationen im Radius abgefragt.

Dann wird berechnet:
1. **Dominante Windrichtung** – Vektorgewichteter Durchschnitt über alle Stationen (mathematisch korrekte Kreisstatistik, kein einfacher Mittelwert der das Nord-Problem hätte: 350°+10° = 0°, nicht 180°)
2. **Upstream-Stationen** – Alle Stationen die innerhalb ±70° in der Windrichtung liegen
3. **Regenfront-ETA** – Wenn ≥2 Upstream-Stationen Regen melden, wird aus Distanz und Zeitversatz die Frontgeschwindigkeit berechnet → ETA in Minuten
4. **Wind-Trend** – Lineare Regression über 2 Stunden für die nächste Upstream-Station
5. **Gewittersignal** – Level 1 wenn Druckabfall > 0.5 hPa/10min + Luftfeuchtigkeit > 85%; Level 2 zusätzlich wenn Böen rasch zunehmen

### Notification-Deduplizierung

Notifications (`notification/*`) werden nur an MQTT publiziert wenn sich der Inhalt tatsächlich geändert hat. Das verhindert, dass Loxone-Automationen alle 5 Minuten ausgelöst werden ohne dass sich die Wetterlage verändert hat.

### Entwarnung

Wenn in einem Zyklus aktive ZAMG-Warnungen vorhanden waren und im nächsten Zyklus keine mehr aktiv sind, wird automatisch `✅ Entwarnung – alle Wetterwarnungen aufgehoben.` auf `notification/geosphere` publiziert.

---

## Datenquellen & Rechtliches

Dieses Plugin nutzt ausschließlich öffentlich zugängliche Daten von [GeoSphere Austria](https://www.geosphere.at):

- [GeoSphere Warn-API](https://warnungen.zamg.at) – Offizielle Wetterwarnungen
- [INCA Nowcast](https://dataset.api.hub.geosphere.at) – Hochauflösende Kurzvorhersage
- [TAWES Stationsdaten](https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min) – Echtzeit-Messstationen

Geocoding via [Nominatim / OpenStreetMap](https://nominatim.org) (kostenlos, kein API-Key nötig).

**Haftungsausschluss:** Die Daten dienen der Information und Hausautomation. Für die Richtigkeit der Wettervorhersagen und daraus resultierende automatisierte Handlungen wird keine Haftung übernommen. Bei Sturm, Unwetter oder Extremereignissen zählen immer die aktuellen Meldungen der Behörden.

---

## Entwickler

- **Autor:** HitSmart / Stefan Hörmandinger
- **GitHub:** [HitsmartDev/Unwetter4Lox](https://github.com/HitsmartDev/Unwetter4Lox)
- **Lizenz:** MIT
