# Unwetter4Lox v0.4.8

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

Die `alarm/`-Topics kombinieren alle drei Datenquellen zu **einheitlichen Stufen**:

| Wert | Bedeutung | Typischer Auslöser |
|:----:|:----------|:-------------------|
| `0` | Keine Gefahr | Alles ruhig |
| `1` | Möglich / Vorsicht | Warnung in Sicht oder Messwerte nähern sich Schwelle |
| `2` | Aktiv / Warnung | Warnung läuft, Schwelle überschritten |
| `3` | AKUT / Extrem | Extreme ZAMG-Warnung (Rot/Lila) aktiv |

`alarm/gesamt` = `max(gewitter, wind, regen, hagel, schnee)` – der höchste Einzelwert aller Kategorien in einer Zahl. Ideal als einziger Gate-Wert in Loxone-Automatisierungen.

---

## Wie werden die alarm/ Topics berechnet?

Jede Kategorie kombiniert mehrere Quellen. **Wichtig:** INCA und TAWES feuern nur bei konfigurierten Schwellwerten – nicht bei jedem Nieselregen oder leichter Brise.

### alarm/gewitter

| Quelle | Bedingung | → Level |
|:-------|:----------|:--------|
| ZAMG | Gewitter-Warnung Stufe 1 (Gelb) | 1 |
| TAWES | Gewittersignal Level 1 (Druckabfall + hohe Feuchte) | 1 |
| ZAMG | Gewitter-Warnung aktiv (läuft gerade) | 2 |
| TAWES | Gewittersignal Level 2 (+ starke Böenzunahme) | 2 |
| System | Behördliche Akutwarnung (GWA) | ≥ 2 |

### alarm/wind

| Quelle | Bedingung | → Level |
|:-------|:----------|:--------|
| INCA | Böen ≥ **BOEN_ALARM** in < 60 min | 1 |
| TAWES | Upstream-Böen ≥ **BOEN_ALARM** | 1 |
| ZAMG | Wind-Warnung Stufe 2 Orange (nicht aktiv) | 1 |
| INCA | Böen ≥ **BOEN_ALARM** in < 30 min | 2 |
| TAWES | Upstream-Böen ≥ **2 × BOEN_ALARM** | 2 |
| ZAMG | Wind-Warnung Stufe 2 Orange **aktiv** | 2 |
| ZAMG | Wind-Warnung Stufe 3 Rot **aktiv** | 3 |
| ZAMG | Wind-Warnung Stufe 4 Lila | 3 |

> **Hinweis:** ZAMG Stufe 1 Gelb wird für Wind **ignoriert** – liegt typisch unter der konfigurierten BOEN_ALARM-Schwelle und würde auch ohne echte Gefahr dauerhaft feuern.

### alarm/regen

| Quelle | Bedingung | → Level |
|:-------|:----------|:--------|
| ZAMG | Regen-Warnung Stufe 1 (Gelb) | 1 |
| TAWES | Regenfront upstream, ETA > 30 min | 1 |
| ZAMG | Regen-Warnung aktiv (läuft gerade) | 2 |
| INCA | Regenrate ≥ **REGEN_ALARM** (`inca/regen_alarm = 1`) | 2 |
| TAWES | Regenfront upstream, ETA ≤ 30 min | 2 |

> **Hinweis:** `inca/bald_regen` und Regenrate < REGEN_ALARM werden **nicht** für `alarm/regen` verwendet – zu sensibel für Nieselregen. Für Bewässerungsabschaltung bei jedem Tropfen: `inca/bald_regen` direkt in Loxone verwenden.

### alarm/hagel

| Quelle | Bedingung | → Level |
|:-------|:----------|:--------|
| ZAMG | Hagel-Warnung Stufe 1 | 1 |
| INCA | Hagel möglich in < 60 min (`bald_hagel`) | 1 |
| INCA | Graupel möglich in < 60 min (`bald_graupel`) | 1 |
| ZAMG | Hagel-Warnung aktiv | 2 |

### alarm/schnee

| Quelle | Bedingung | → Level |
|:-------|:----------|:--------|
| ZAMG | Schnee- oder Glatteis-Warnung Stufe 1 | 1 |
| INCA | Niederschlagstyp = Schnee (PT=2) oder Schneeregen (PT=3) | 1 |
| ZAMG | Schnee- oder Glatteis-Warnung aktiv | 2 |

---

## Konfigurierbare Schwellwerte

Zwei Schwellwerte in den Einstellungen bestimmen, ab wann INCA und TAWES einen Alarm auslösen:

### Böen-Alarmschwelle (`BOEN_ALARM`)

**Standard: 60 km/h** (= Beaufort 8, Sturmböen)

Wirkt an vier Stellen gleichzeitig:
1. `inca/bald_sturm_30` / `bald_sturm_60` – gesetzt wenn Böen ≥ Schwelle vorhergesagt
2. `tawes/sturm_upstream` – gesetzt wenn Upstream-Böen ≥ Schwelle
3. `alarm/wind` Level 1 – wenn INCA ≥ Schwelle in 60 min oder TAWES ≥ Schwelle
4. `alarm/wind` Level 2 – wenn INCA ≥ Schwelle in 30 min oder TAWES ≥ 2× Schwelle

Empfehlungen:
- `40 km/h` – Empfindlich (Markisen ab Beaufort 6, Beaufort 6 = ~45 km/h)
- `60 km/h` – Standard für Unwetterschutz (Beaufort 8)
- `80 km/h` – Nur bei wirklich starkem Sturm (Beaufort 9+)

### Regen-Alarmschwelle (`REGEN_ALARM`)

**Standard: 10.0 mm/h** (starker Regen)

Wirkt an zwei Stellen gleichzeitig:
1. `inca/regen_alarm = 1` – wenn aktuelle Regenrate ≥ Schwelle
2. `alarm/regen` Level 2 – wenn `regen_alarm = 1`

Empfehlungen:
- `2.0 mm/h` – Deutlich spürbarer Regen (für Bewässerungsabschaltung via `alarm/regen`)
- `10.0 mm/h` – Starkregen (Standard)
- `20.0 mm/h` – Nur bei Starkregen mit Überflutungsgefahr

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

**Empfohlen für Loxone-Automatisierungen.** Kombiniert alle drei Datenquellen zu einem einzigen Wert pro Kategorie.

| Topic | Beschreibung | Mögliche Werte |
|:------|:-------------|:---------------|
| `alarm/gesamt` | `max(gewitter, wind, regen, hagel, schnee)` – der höchste Wert aller 5 Kategorien in einer Zahl. Primärer Gate-Wert für Push-Entscheidungen. | `0`–`3` |
| `alarm/gewitter` | Gewittergefahr aus ZAMG + TAWES-Gewittersignal kombiniert | `0`–`3` |
| `alarm/wind` | Windgefahr aus ZAMG-Warnung + INCA-Böenvorhersage + TAWES-Upstream | `0`–`3` |
| `alarm/regen` | Regenrisiko aus ZAMG + INCA-Nowcast + TAWES-Regenfront-ETA | `0`–`2` |
| `alarm/hagel` | Hagelgefahr aus ZAMG-Warnung + INCA-Hagelvorhersage | `0`–`2` |
| `alarm/schnee` | Schnee/Glatteis aus ZAMG + INCA-Niederschlagstyp | `0`–`2` |
| `alarm/stufe` | Höchste **offizielle** ZAMG-Warnstufe (nur ZAMG, kein INCA/TAWES) | `0`–`4` |
| `alarm/zusammenfassung` | Fertiger Anzeigetext für alle aktiven Kategorien, mit Emoji-Symbolen. Ideal für Loxone-Statusanzeige. Bei keiner Warnung: `✅ Keine Warnungen` | Text |

**Stufen-Bedeutung für `alarm/gesamt`, `alarm/gewitter`, `alarm/wind`, `alarm/regen`, `alarm/hagel`, `alarm/schnee`:**

| Wert | Bedeutung | Empfehlung |
|:----:|:----------|:-----------|
| `0` | Keine Warnung | Kein Push senden |
| `1` | Möglich / Vorsicht | Optionaler Info-Push |
| `2` | Aktiv / Warnung | Push empfohlen |
| `3` | AKUT / Extrem | Sofort-Push |

**Mögliche Texte für `alarm/zusammenfassung`:**

| Wert | Bedeutung |
|:-----|:----------|
| `✅ Keine Warnungen` | Alle Kategorien = 0 |
| `⚡ Gewitter möglich` | gewitter = 1 |
| `⚡ Gewitter AKUT` | gewitter ≥ 2 |
| `💨 Erhöhte Windgefahr` | wind = 1 |
| `💨 Sturm aktiv` | wind = 2 |
| `💨 Extremsturm` | wind = 3 |
| `🌧 Regen erwartet` | regen = 1 |
| `🌧 Starkregen` | regen = 2 |
| `🌨 Hagelgefahr` | hagel = 1 |
| `🌨 Hagel AKTIV` | hagel ≥ 2 |
| `❄️ Schnee/Eis möglich` | schnee = 1 |
| `❄️ Schnee/Eis AKTIV` | schnee ≥ 2 |

Mehrere aktive Kategorien werden mit ` \| ` verbunden, z.B.: `⚡ Gewitter AKUT \| 💨 Sturm aktiv \| 🌧 Starkregen`

### GeoSphere Austria Warnungen (zamg/)

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `zamg/max_stufe` | Höchste aktive Warnstufe | `0`–`4` |
| `zamg/irgendwas_aktiv` | Mind. eine aktive/baldige Warnung | `0` / `1` |
| `zamg/akutwarnung` | Behördliche Akutwarnung | `0` / `1` |
| `zamg/letzter_abruf` | Zeitstempel letzter ZAMG-Abruf | `08.06.2026 07:30:00` |
| `zamg/{typ}/stufe` | Warnstufe je Wettertyp | `0`–`4` |
| `zamg/{typ}/aktiv` | Warnung gerade aktiv | `0` / `1` |
| `zamg/{typ}/bald` | Warnung beginnt in < 30 min | `0` / `1` |
| `zamg/{typ}/start_epoch` | Warnungsbeginn als Unix-Timestamp | `0` wenn keine Warnung |
| `zamg/{typ}/end_epoch` | Warnungsende als Unix-Timestamp | `0` wenn keine Warnung |
| `zamg/{typ}/notification` | Klartext für Push | `ORANGE – Wind \| heute 14:00–20:00` |

**Typen `{typ}`:** `wind`, `regen`, `schnee`, `glatteis`, `gewitter`, `hagel`, `hitze`, `kaelte`

### INCA Nowcast (inca/)

| Topic | Beschreibung | Einheit |
|:------|:-------------|:--------|
| `inca/letzter_abruf` | Zeitstempel letzter INCA-Abruf | `08.06.2026 07:30:00` |
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

Textmeldungen für Loxone Push-Benachrichtigungen. Werden **nur publiziert wenn sich der Inhalt ändert** (keine Spam-Wiederholungen bei gleichbleibendem Status).

| Topic | Beschreibung | Beispieltext |
|:------|:-------------|:-------------|
| `notification/geosphere` | ZAMG-Warnungen als Klartext ab konfigurierter Mindeststufe. Nur pushen wenn `zamg/irgendwas_aktiv = 1`! | `⚠️ ORANGE – Wind \| heute 14:00 – morgen 06:00 \| Sturmböen` |
| `notification/inca` | INCA Nowcast-Zusammenfassung | `✅ kein Alarm \| Böen: 12.6 km/h` oder `⚠️ Sturm in 20 min \| Böen bis 75 km/h` |
| `notification/tawes` | TAWES-Meldung bei aktiver Regenfront oder Sturm upstream | `🌧 Regenfront ~18min \| 62km/h aus W` |
| `notification/alle` | Alle drei Quellen kombiniert, durch `──` getrennt | Kombination der obigen |

**Entwarnung** (automatisch nach ZAMG-Warnung): `notification/geosphere` enthält dann `✅ Entwarnung – alle Wetterwarnungen aufgehoben.`

**Kein Alarm** (Normalzustand): `notification/inca` und `notification/alle` enthalten `✅ kein Alarm | Böen: X km/h`

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

**Morgen-Zusammenfassung ZAMG (nur wenn Warnung anliegt):**
```
Zeitprogramm: täglich 07:00 Uhr
Bedingung:    zamg/irgendwas_aktiv = 1   ← Gate: 0 = kein Push, 1 = Push senden
Nachricht: notification/geosphere
```
> Ohne diesen Gate würde jeden Morgen "keine aktiven Warnungen" gepusht, auch wenn alles ruhig ist.

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
