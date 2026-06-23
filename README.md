# Unwetter4Lox

**LoxBerry-Plugin für automatische Unwettererkennung und Wetterautomatisierung. Version 0.9.20**

Unwetter4Lox kombiniert drei offizielle österreichische Wetterdatenquellen der GeoSphere Austria und berechnet daraus einen einheitlichen Alarmstatus mit bis zu 30 Minuten Vorlaufzeit. Alle Daten werden via MQTT an den Loxone Miniserver geliefert – ein einziger Wert pro Kategorie reicht für zuverlässige Automatisierungen.

---

## Was macht dieses Plugin?

Das Plugin läuft als Hintergrunddienst auf dem LoxBerry und fragt automatisch drei Datenquellen ab:

1. **Offizielle ZAMG-Warnungen** – Amtliche Wetterwarnungen der GeoSphere Austria für deinen exakten Standort
2. **INCA Nowcast** – Hochauflösende Kurzvorhersage (1 km², 15-Minuten-Schritte, bis 60 Minuten voraus)
3. **TAWES 360°** – Echtzeitmessungen von Wetterstationen in der Umgebung (bis 120 km)

Alle drei Quellen werden intelligent zu einem **aggregierten Gesamtstatus** zusammengefasst. Die `alarm/`-Topics enthalten immer den besten verfügbaren Wert – du musst in Loxone nicht drei verschiedene Quellen auswerten.

---

## Datenquellen im Detail

### 1. GeoSphere Austria – Offizielle Warnungen (ZAMG)

Amtliche Warnungen des österreichischen Wetterdienstes, nach GPS-Koordinaten gefiltert. Das Plugin prüft 8 Wettertypen:

| Typ | Beschreibung |
|:----|:-------------|
| `wind` | Sturmböen |
| `regen` | Starkregen, Überflutungsgefahr |
| `schnee` | Starker Schneefall |
| `glatteis` | Glatteis, Eisregen |
| `gewitter` | Gewitter (mit/ohne Hagel) |
| `hagel` | Hagelschlag |
| `hitze` | Hitzewelle |
| `kaelte` | Kälteeinbruch, Frost |

**Warnstufen:** Gelb = 1 (Vorsicht), Orange = 2 (Warnung), Rot = 3 (erhebliche Gefahr), Lila = 3 (Extrem)

ZAMG-Warnungen werden direkt als Alarmstufe übernommen – ohne Schwellwertprüfung. Das Plugin zeigt außerdem ZAMG-Warnungen die in den nächsten 8 Stunden beginnen als Tageswarnung (`notification/tageswarnung`), damit Morgenroutinen in Loxone bereits früh über bevorstehende Unwetter informiert werden.

### 2. INCA Nowcast

Hochauflösende Kurzvorhersage (1 km²) für deinen GPS-Punkt, aktualisiert alle 15 Minuten:

- Böenstärke jetzt und Spitzenwerte in den nächsten 30/60 Minuten
- Aktuelle Regenrate (mm/h) und maximale Intensität in den nächsten 30 Minuten
- Niederschlagstyp (Regen, Schnee, Hagel, Graupel)
- Hagelgefahr in den nächsten 60 Minuten
- Minuten bis zum nächsten signifikanten Regen (ab 25% der Alarmschwelle, gefiltert von Modellrauschen)

INCA allein hat **beschränkte Vertrauensstufe** – ohne Bestätigung durch TAWES oder ZAMG löst es maximal Alarmstufe 1 aus. Erst bei Bestätigung durch eine zweite Quelle sind alle Stufen möglich.

### 3. TAWES 360° – Wetterstationsnetz

Das Plugin fragt alle TAWES-Stationen im einstellbaren Umkreis (Standard: 120 km, max. 25 Stationen) ab und wertet deren Echtzeitmessungen aus:

- **Upstream-Erkennung:** Welche Stationen liegen in der Windrichtung (konfigurierbarer Halbwinkel, Standard ±45°)? Das Wetter kommt von dort.
- **Wind-Konsens:** Mindestens 30% der Upstream-Tal-Stationen (absolut mind. 2) müssen Böen ≥ BOEN_ALARM melden. Eine einzelne Station löst nie `alarm/wind` aus. Alpine Stationen (> konfigurierte Seehöhe) werden ausgeschlossen.
- **Wind-Kaskade:** Erkennt zeitlich gestaffelte Böen upstream (Station weit weg zuerst, dann näher). Gibt Stufe 1 als Vorwarnung – auch ohne Konsens-Bestätigung.
- **Regen upstream:** Wenn Upstream-Stationen Regen melden (nur letzte 30 Min.), bestätigt das den INCA-Nowcast.
- **Physik-ETA:** Entfernung der nächsten Upstream-Station mit Regen geteilt durch Windgeschwindigkeit = physikalische Ankunftszeit. Vollständig unabhängig vom INCA-Modell.
- **Lokal-Regen:** Regen innerhalb des konfigurierten Lokal-Umkreises (Standard: 25 km), unabhängig von der Windrichtung.

---

## Wie werden Alarmstufen bestimmt?

### Die Grundregel: Vertrauen durch Bestätigung

Jede Datenquelle hat eine unterschiedliche Vertrauensstufe. Erst wenn mehrere Quellen dasselbe sagen, werden höhere Alarmstufen ausgelöst.

| Quelle | Allein | Mit Bestätigung |
|:-------|:-------|:----------------|
| **ZAMG** | Stufe 1–3 direkt | – (amtliche Warnung, kein Konsens nötig) |
| **INCA** | Max. Stufe 1 | + TAWES oder ZAMG → Stufe 1–3 |
| **TAWES** | Max. Stufe 1 | + INCA → Stufe 1–3 |

**Besonderheit:** Wenn INCA seit mindestens 4 aufeinanderfolgenden Zyklen (~20 Minuten) konstant ein Signal zeigt, ist max. Stufe 2 auch ohne TAWES-Bestätigung möglich. Dies erkennt anhaltende Ereignisse bevor TAWES-Stationen upstream reagieren.

### Alarmstufen und Schwellwerte

Die konfigurierten Schwellwerte (REGEN_ALARM, BOEN_ALARM) sind die **einzige** Grenze zwischen den Stufen. Kein anderer Wert bestimmt ob Stufe 1, 2 oder 3 ausgelöst wird:

| Schwellwert | Stufe 1 | Stufe 2 | Stufe 3 |
|:------------|:-------:|:-------:|:-------:|
| `REGEN_ALARM` (Standard 10 mm/h) | ≥ 1× | ≥ 2× | ≥ 3× |
| `BOEN_ALARM` (Standard 60 km/h) | ≥ 1× | ≥ 2× | ≥ 3× |

### Vorlaufzeit: Stufe aus Vorhersage, nicht nur Istwert

Das Plugin berechnet bei bestätigten Ereignissen (INCA + TAWES) die Alarmstufe aus dem **Spitzenwert der nächsten 30 Minuten**, nicht nur aus dem aktuellen Messwert. Wenn INCA jetzt 0.5 mm/h zeigt, aber in 15 Minuten 8 mm/h vorhersagt, und TAWES-Stationen upstream bereits Regen messen – dann ist der Alarm jetzt Stufe 3, mit 15 Minuten Vorlaufzeit.

Wind verhält sich genauso: `fx_max_60min` (Maximum der nächsten 60 Minuten) bestimmt die Stufe.

### Drei unabhängige ETA-Quellen

Das Plugin berechnet die Ankunftszeit (ETA) aus drei unabhängigen Quellen und wählt intelligent:

1. **INCA-Modell-ETA** – `minuten_bis_regen` aus dem Nowcast-Modell
2. **Trend-ETA** – Extrapolation aus dem zeitlichen Verlauf der letzten ~40 Minuten
3. **Physik-ETA** – Entfernung der nächsten upstream Regenstation ÷ Windgeschwindigkeit

Wenn INCA-Modell und Physik-ETA innerhalb von 10 Minuten übereinstimmen, erhöht das die Konfidenz um +15 Punkte. Bei Übereinstimmung wird immer der frühere (konservativere) ETA verwendet.

### Trend-Engine und Beschleunigungserkennung

Das Plugin puffert die letzten ~40 Minuten (8 Zyklen) aller Messwerte und analysiert:

- **Trend:** Steigt oder fällt die Intensität? (lineare Regression)
- **Beschleunigung:** Ist der Anstieg in den letzten 3 Zyklen mehr als doppelt so steil wie im Gesamttrend? → `stark_zunehmend` (typisch für herannahende Gewitter)
- **Konsistenz:** Wie viele Zyklen zeigen in dieselbe Richtung? → Konfidenz-Bonus bis +25 Punkte

### Konfidenz-Score (0–100)

| Quelle aktiv | Punkte |
|:-------------|:------:|
| ZAMG-Warnung aktiv | +40 |
| INCA-Signal | +30 |
| TAWES-Bestätigung | +20 |
| Trend konsistent (pro Zyklus) | +5 (max +25) |
| Beschleunigung erkannt | +10 |
| INCA-ETA und Physik-ETA stimmen überein | +15 |

Der Konfidenz-Score erscheint in `alarm/konfidenz` und in den Notification-Texten als "Sicherheit: gering/mittel/hoch/sehr hoch".

---

## Alarmstufen-Referenz

Alle `alarm/`-Topics verwenden einheitliche Stufen:

| Stufe | Bedeutung | Typische Reaktion |
|:-----:|:----------|:------------------|
| `0` | Ruhig | Keine Aktion |
| `1` | **Vorsicht** – etwas kündigt sich an | Info-Push, vorsorglich handeln |
| `2` | **Warnung** – es wird gefährlich | Schutzmaßnahmen aktiv |
| `3` | **Extrem** – höchste Gefahr | Sofortmaßnahmen, zwingender Alarm |

`alarm/gesamt` = `max(gewitter, wind, regen, hagel, schnee)` – ideal als einziger Gate-Wert in Loxone-Automatisierungen.

### alarm/regen – Detaillogik

| Quelle | Bedingung | → Stufe |
|:-------|:----------|:-------:|
| ZAMG | Regen-Warnung Gelb | **1** |
| INCA allein | `rr_jetzt` ≥ REGEN_ALARM | **1** (max) |
| INCA (≥ 20 Min. Trend) | Spitzenwert ≥ REGEN_ALARM | **1–2** |
| INCA + TAWES | Spitzenwert 30 min ≥ 1× REGEN_ALARM | **1** |
| INCA + TAWES | Spitzenwert 30 min ≥ 2× REGEN_ALARM | **2** |
| INCA + TAWES | Spitzenwert 30 min ≥ 3× REGEN_ALARM | **3** |
| TAWES upstream allein | `regen_upstream_mm` ≥ REGEN_ALARM | **1** (max) |
| TAWES lokal allein | `regen_lokal_mm` ≥ REGEN_ALARM | **1** (max) |
| ZAMG | Regen-Warnung Orange | **2** |
| ZAMG | Regen-Warnung Rot/Lila | **3** |

### alarm/wind – Detaillogik

| Quelle | Bedingung | → Stufe |
|:-------|:----------|:-------:|
| ZAMG | Wind-Warnung Gelb | **1** |
| INCA allein | `fx_max_60min` ≥ BOEN_ALARM | **1** (max) |
| INCA + TAWES | `fx_max_60min` ≥ 1× BOEN_ALARM | **1** |
| INCA + TAWES | `fx_max_60min` ≥ 2× BOEN_ALARM | **2** |
| INCA + TAWES | `fx_max_60min` ≥ 3× BOEN_ALARM | **3** |
| TAWES Konsens | Upstream-Böen ≥ BOEN_ALARM | **1** (max ohne INCA) |
| TAWES Wind-Kaskade | Zeitlich gestaffelte Böen erkannt | **1** |
| ZAMG | Wind-Warnung Orange | **2** |
| ZAMG | Wind-Warnung Rot/Lila | **3** |

### alarm/gewitter

| Quelle | Bedingung | → Stufe |
|:-------|:----------|:-------:|
| ZAMG | Gewitter-Warnung Gelb | **1** |
| TAWES | Gewittersignal (Druck + Feuchte) | **1** |
| ZAMG | Gewitter-Warnung Orange | **2** |
| TAWES | Gewittersignal + starke Böenzunahme | **2** |
| System | Behördliche Akutwarnung (GWA) | **≥ 2** |
| ZAMG | Gewitter-Warnung Rot/Lila | **3** |

### alarm/hagel und alarm/schnee

`alarm/hagel` max. Stufe 2. `alarm/schnee` max. Stufe 2. Ausgelöst durch ZAMG-Warnungen und INCA Niederschlagstyp-Erkennung.

---

## Vollständige MQTT-Topic-Referenz

Standard-Präfix: `unwetter/` (in den Einstellungen änderbar). Alle Topics werden mit `retain=true` publiziert.

### System

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `status` | Systemzustand | `OK` / `Error - [Quelle]` |
| `status/zamg_ok` | ZAMG API erreichbar | `0` / `1` |
| `status/inca_ok` | INCA API erreichbar | `0` / `1` |
| `status/tawes_ok` | TAWES API erreichbar | `0` / `1` |
| `letzter_abruf_datum` | Zeitpunkt letzter Abruf | `07.06.2026 14:30:00` |
| `letzter_abruf_epoch` | Unix-Timestamp letzter Abruf | `1749303000` |

### Gesamtstatus (alarm/)

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `alarm/gesamt` | Höchster Wert aller Kategorien – primärer Gate-Wert | `0`–`3` |
| `alarm/gewitter` | Gewittergefahr | `0`–`3` |
| `alarm/wind` | Windgefahr | `0`–`3` |
| `alarm/regen` | Regenrisiko | `0`–`3` |
| `alarm/hagel` | Hagelgefahr | `0`–`2` |
| `alarm/schnee` | Schnee/Glatteis | `0`–`2` |
| `alarm/stufe` | Höchste offizielle ZAMG-Warnstufe (nur ZAMG) | `0`–`4` |
| `alarm/konfidenz` | Sicherheits-Score der Vorhersage | `0`–`100` |
| `alarm/eta_min` | Minuten bis Regenfront ankommt (bester verfügbarer ETA) | min / `-1` |
| `alarm/regen_trend` | Intensitätstrend | `stark_zunehmend` / `zunehmend` / `stabil` / `abnehmend` / `unbekannt` |
| `alarm/wind_quelle` | Welche Quelle hat `alarm/wind` ausgelöst | Text |
| `alarm/regen_quelle` | Welche Quelle hat `alarm/regen` ausgelöst | Text |
| `alarm/zusammenfassung` | Lesbarer Alarmtext | z.B. `🌧️ Regen bestätigt (Stufe 2/3) – Ankunft in ~12 min` |
| `alarm/entwarnung` | Wechselt einmalig auf `1` wenn Alarm endet | `0` / `1` |

### GeoSphere Warnungen (zamg/)

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `zamg/max_stufe` | Höchste aktive Warnstufe | `0`–`4` |
| `zamg/irgendwas_aktiv` | Mind. eine aktive/baldige Warnung | `0` / `1` |
| `zamg/akutwarnung` | Behördliche Akutwarnung (GWA) | `0` / `1` |
| `zamg/{typ}/stufe` | Warnstufe je Wettertyp | `0`–`4` |
| `zamg/{typ}/aktiv` | Warnung gerade aktiv | `0` / `1` |
| `zamg/{typ}/bald` | Warnung beginnt in < 30 min | `0` / `1` |
| `zamg/{typ}/start_epoch` | Warnungsbeginn Unix-Timestamp | `0` wenn keine |
| `zamg/{typ}/end_epoch` | Warnungsende Unix-Timestamp | `0` wenn keine |
| `zamg/{typ}/notification` | Klartext für Push | `⚠️ ORANGE – Wind \| 14:00–20:00` |

**Typen `{typ}`:** `wind`, `regen`, `schnee`, `glatteis`, `gewitter`, `hagel`, `hitze`, `kaelte`

### INCA Nowcast (inca/)

| Topic | Beschreibung | Einheit |
|:------|:-------------|:--------|
| `inca/fx` | Aktuelle Böenstärke | km/h |
| `inca/ff` | Aktuelle Windgeschwindigkeit | km/h |
| `inca/fx_max_30min` | Max. Böen nächste 30 min | km/h |
| `inca/fx_max_60min` | Max. Böen nächste 60 min (→ `alarm/wind`) | km/h |
| `inca/rr` | Aktuelle Regenrate | mm/h |
| `inca/regen_alarm` | Regenrate ≥ REGEN_ALARM | `0` / `1` |
| `inca/minuten_bis_regen` | Zeit bis signifikanter Regen | min / `-1` |
| `inca/bald_regen` | Regen in < 30 min erwartet | `0` / `1` |
| `inca/bald_hagel` | Hagel in < 60 min erwartet | `0` / `1` |
| `inca/bald_graupel` | Graupel möglich | `0` / `1` |
| `inca/bald_sturm_30` | Böen ≥ BOEN_ALARM in < 30 min | `0` / `1` |
| `inca/bald_sturm_60` | Böen ≥ BOEN_ALARM in < 60 min | `0` / `1` |
| `inca/pt` | Niederschlagstyp jetzt (Code) | `1`=Regen, `2`=Schnee, `3`=Schneeregen, `4`=Graupel, `5`=Hagel, `255`=kein |
| `inca/pt_name` | Niederschlagstyp jetzt (Text) | `Regen`, `Schnee`, … |
| `inca/pt_bald` | Typ des nächsten Regens (Code) | wie `inca/pt` |
| `inca/pt_bald_name` | Typ des nächsten Regens (Text) | z.B. `Regen` |

> `inca/bald_regen` löst **keinen** `alarm/regen`-Alarm aus – es ist ein Info-Topic ideal für Bewässerungssteuerung.

### TAWES 360° (tawes/)

| Topic | Beschreibung | Einheit |
|:------|:-------------|:--------|
| `tawes/dominante_windrichtung` | Dominante Windrichtung (vektorgewichtet) | Grad 0–360 |
| `tawes/dominante_windrichtung_name` | Windrichtung | `N`, `NO`, `O`, `SO`, `S`, `SW`, `W`, `NW` |
| `tawes/upstream_aktiv` | Anzahl aktiver Upstream-Stationen | Ganzzahl |
| `tawes/wind_upstream_kmh` | Max. Böen upstream (Rohwert, kein Konsens) | km/h |
| `tawes/sturm_upstream` | Konsens-Sturm bestätigt (≥ 30% Upstream) | `0` / `1` |
| `tawes/wind_kaskade` | Zeitlich gestaffelte Böen (Sturmfront im Anmarsch) | `0` / `1` |
| `tawes/wind_kaskade_eta_min` | ETA Sturmfront laut Kaskade | min / `-1` |
| `tawes/alpine_upstream` | Anzahl alpiner Upstream-Stationen (ausgeschlossen) | Ganzzahl |
| `tawes/regen_upstream` | Regen aus Windrichtung (letzte 30 min) | `0` / `1` |
| `tawes/regen_upstream_mm` | Max. Upstream-Regen-Intensität | mm/h |
| `tawes/regen_lokal` | Regen im Lokal-Umkreis | `0` / `1` |
| `tawes/regen_lokal_mm` | Max. Regenintensität im Lokal-Umkreis | mm/h |
| `tawes/regen_lokal_station` | Station die Lokal-Regen meldet | z.B. `GMUNDEN (12km) 8.4mm/h` |
| `tawes/gewitter_signal` | Gewitterindikator | `0`=kein, `1`=möglich, `2`=akut |
| `tawes/naechste_station_name` | Nächste Upstream-Station | Text |
| `tawes/letztes_update` | Zeitstempel letzter TAWES-Abruf | Datum/Uhrzeit |

### Notifications (notification/)

Textmeldungen für Push-Benachrichtigungen in lesbarem Deutsch – kein technischer Jargon.

| Topic | Beschreibung | Beispiel |
|:------|:-------------|:---------|
| `notification/geosphere` | ZAMG-Warntext. **Immer aktiv.** | `⚠️ ORANGE – Gewitter \| heute 15:00–21:00` |
| `notification/inca` | Nowcast-Vorhersage in Klartext. | `🌧️ Regen erwartet in ~12 Minuten – durch Wetterstationen bestätigt (Sicherheit: hoch)` |
| `notification/tawes` | Lagebericht der Umgebungsstationen. | `🌧️ Regen aus NW nähert sich – 22 mm/h gemessen, Ankunft in ~15 Minuten, Intensität nimmt zu` |
| `notification/alle` | Kombinierte Hauptmeldung. **Empfehlung für Loxone Push.** | `🌧️ Regen bestätigt (Stufe 2/3) – Ankunft in ~12 min \| Sicherheit: hoch` |
| `notification/tageswarnung` | ZAMG-Warnungen für die nächsten 8 Stunden. | `📅 heute 16:00: ⚠️ GELB Gewitter` |
| `notification/entwarnung` | Einmalig bei Alarmende. | `Entwarnung – kein Unwetter mehr` |

**Hinweis:** `notification/inca` und `notification/tawes` werden immer gesendet (auch ohne aktiven Alarm), damit Loxone eigenständig entscheiden kann, was angezeigt wird. Bei `alarm/gesamt = 0` enthält `notification/alle` den Tageswarnung-Text oder ist leer.

---

## Installation

1. ZIP über den **LoxBerry Plugin Manager** installieren
2. In die **Einstellungen** wechseln
3. **Standort festlegen** – Adresse eingeben → "Suchen", oder "Vom Miniserver" übernehmen
4. Dienste aktivieren: ZAMG, INCA Nowcast, TAWES 360°
5. Schwellwerte anpassen (Böen-Alarm, Regen-Alarm)
6. MQTT-Broker einstellen (Standard: LoxBerry MQTT Gateway automatisch)
7. Daemon im **Status-Tab** starten
8. In Loxone Config: MQTT Virtual Inputs anlegen

---

## Einstellungen Übersicht

| Einstellung | Standard | Beschreibung |
|:------------|:---------|:-------------|
| Breitengrad / Längengrad | – | GPS-Koordinaten des Standorts |
| ZAMG aktivieren | Ja | Offizielle Wetterwarnungen abrufen |
| INCA Nowcast aktivieren | Ja | 15-Min-Vorhersage abrufen |
| INCA Zeithorizont | 60 min | Vorhersagehorizont INCA |
| TAWES 360° aktivieren | Ja | Wetterstationsnetz auswerten |
| TAWES Stationsradius | 120 km | Suchradius für TAWES-Stationen |
| TAWES Max. Stationen | 25 | Anzahl Stationen pro API-Abruf |
| TAWES Konsens-Schwelle | 30 % | Mindestanteil Upstream-Stationen für Wind-Alarm |
| TAWES Upstream-Winkel | 45 ° | Halbwinkel des Upstream-Kegels (45° = 90° Gesamtkegel) |
| TAWES Max. Seehöhe Upstream | 1200 m | Alpine Stationen über dieser Seehöhe ausgeschlossen |
| TAWES Lokal-Regen Umkreis | 25 km | Umkreis für Lokal-Regen-Erkennung |
| Loop-Takt | 300 s | Interne Prüffrequenz (wie oft der Daemon die Intervalle prüft) |
| ZAMG Abruf-Intervall | 300 s | Wie oft die ZAMG-Warnungen abgerufen werden (min. 60 s) |
| INCA Abruf-Intervall | 300 s | Wie oft der INCA Nowcast abgerufen wird (min. 60 s) |
| TAWES Abruf-Intervall | 480 s | Wie oft TAWES-Stationen abgefragt werden (min. 120 s) |
| **Böen-Alarmschwelle** | **60 km/h** | Stufe 1 ab 60 km/h, Stufe 2 ab 120, Stufe 3 ab 180 |
| **Regen-Alarmschwelle** | **10.0 mm/h** | Stufe 1 ab 10, Stufe 2 ab 20, Stufe 3 ab 30 mm/h |
| Min. Warnstufe für Notifications | 1 | Ab welcher ZAMG-Stufe Notification-Text erzeugt wird |
| MQTT Präfix | `unwetter/` | Präfix für alle MQTT Topics |
| MQTT Broker | automatisch | Standard: LoxBerry MQTT Gateway |

---

## Loxone Integration – Praktische Beispiele

### Empfohlene Virtual Inputs für den Einstieg

```
# Einfachste Integration – 1 Wert für alles
unwetter/alarm/gesamt            → "Wetteralarm gesamt"   (0=OK, 1=Vorsicht, 2=Warnung, 3=AKUT)

# Pro Kategorie
unwetter/alarm/wind              → "Wind-Alarm"
unwetter/alarm/regen             → "Regen-Alarm"
unwetter/alarm/gewitter          → "Gewitter-Alarm"
unwetter/alarm/hagel             → "Hagel-Alarm"

# ETA und Sicherheit
unwetter/alarm/eta_min           → "Regen ETA Minuten"
unwetter/alarm/konfidenz         → "Vorhersage-Sicherheit"

# Push-Benachrichtigungen
unwetter/notification/alle       → "Wettermeldung"
unwetter/notification/tageswarnung → "Tageswarnung"
```

### Automatisierungsbeispiele

**Markisen automatisch einfahren:**
```
Auslöser: alarm/wind >= 2  ODER  inca/bald_sturm_30 = 1
Aktion:   Markisen einfahren, Push senden
```

**Bewässerung stoppen:**
```
Auslöser: inca/bald_regen = 1  ODER  alarm/regen >= 1  ODER  tawes/regen_lokal = 1
Aktion:   Bewässerungsprogramm abbrechen
```

**Dachfenster schließen:**
```
Auslöser: alarm/regen >= 1  ODER  tawes/regen_lokal = 1
Aktion:   Fenster schließen, Push senden
```

**Push bei Unwetter:**
```
Auslöser: alarm/gesamt >= 2  (Wert geändert, von kleinerem Wert)
Nachricht: notification/alle
```

**Morgen-Zusammenfassung (täglich 07:00):**
```
Zeitprogramm: täglich 07:00 Uhr
Bedingung:    zamg/irgendwas_aktiv = 1  ← Gate: 0 = kein Push
Nachricht:    notification/geosphere
              + notification/tageswarnung  (Warnungen im Tagesverlauf)
```

**Hagelschutz:**
```
Auslöser: alarm/hagel >= 1  ODER  inca/bald_hagel = 1
Aktion:   Carport-Tor schließen, Push senden
```

**Entwarnung nach Unwetter:**
```
Auslöser: alarm/entwarnung = 1  (wechselt einmalig auf 1 wenn Alarm endet)
Nachricht: notification/alle
```

---

## Technische Details

### Daemon-Betrieb & Zuverlässigkeit

**Autostart nach Reboot:** Der Daemon startet automatisch 120 Sekunden nach dem Systemstart (Verzögerung damit der MQTT-Broker bereits läuft). Eingerichtet via `/etc/cron.d/unwetter4lox` (root-owned, überlebt Plugin-Updates).

**Täglicher Neustart:** Täglich um 03:00 Uhr wird der Daemon automatisch neu gestartet. Dies bereinigt potenzielle MQTT-Langzeitprobleme.

**Watchdog (alle 5 min):** Ein Cron-Job prüft ob der Daemon noch läuft. Bei einem Absturz werden PID-Datei und state.json gelöscht und der Daemon neu gestartet.

**Log-Historie:** Bei jedem Daemon-Start wird eine neue Log-Datei angelegt (Zeitstempel im Dateinamen). Im Log-Tab sind alle vergangenen Sitzungen der letzten 7 Tage auswählbar – so gehen Logs bei einem Neustart nicht verloren.

**MQTT-Robustheit:** paho-mqtt `loop_start()` + `reconnect_delay_set(5s, 60s)` sorgen für automatische Wiederverbindung. Die Client-ID enthält den Hostnamen um Konflikte bei mehreren Instanzen zu vermeiden. Ein Watchdog erkennt Zombie-TCP-Verbindungen nach 30 Minuten.

**INCA Parallel-Abruf:** Die 4 INCA API-Parameter (ff, fx, rr, pt) werden gleichzeitig abgerufen. Bei API-Timeouts dauert ein Zyklus maximal ~15 Sekunden.

### TAWES – So funktioniert es

**Windrichtungsberechnung:** Vektorgewichteter Durchschnitt (keine einfache Mittelung – die würde bei 350° und 10° fälschlicherweise 180° ergeben). Stärker blasende Stationen haben mehr Gewicht.

**Upstream-Kegel:** Alle Stationen innerhalb des konfigurierten Halbwinkels (Standard ±45°, also 90° Gesamtkegel) der dominanten Windrichtung gelten als upstream. Das Wetter kommt von dort.

**TAWES Messwerte:** Die GeoSphere-API liefert Niederschlag in mm/10min. Das Plugin rechnet intern auf mm/h um (×6). Der konfigurierte REGEN_ALARM gilt immer in mm/h.

**Stationen-Cache:** Der Daemon löscht den Stations-Cache bei jedem Start und lädt alle Stationen frisch. Datenlücken in den ersten 10–20 Minuten nach Start sind normal.

### Diagnose-Topics

Diese Topics zeigen sofort welche Quelle den Alarm ausgelöst hat:

- `alarm/wind_quelle`: z.B. `INCA (52km/h)`, `INCA+TAWES (48→85km/h)`, `TAWES_STURM`, `ZAMG`
- `alarm/regen_quelle`: z.B. `INCA+TAWES (0.5→8.2mm/h)`, `TAWES_UP (12mm/h)`, `ZAMG`
- `alarm/konfidenz`: z.B. `75` (Sicherheit: hoch)
- `alarm/eta_min`: z.B. `12` (Regen in 12 Minuten)
- `alarm/regen_trend`: `stark_zunehmend`, `zunehmend`, `stabil`, `abnehmend`

---

## FAQ

**Warum wird manchmal Stufe 3 angezeigt obwohl es noch nicht regnet?**
Das ist korrekt und gewollt. Das Plugin berechnet die Stufe aus dem Spitzenwert der nächsten 30 Minuten (nicht nur dem aktuellen Wert) – aber nur wenn TAWES oder ZAMG das bestätigen. So erhältst du die richtige Warnstufe mit Vorlaufzeit, bevor der Starkregen ankommt.

**Warum sehe ich manchmal Stufe 1 obwohl die Schwelle nicht überschritten wurde?**
Bei einem ETA-Signal (Regen kommt in X Minuten laut INCA) und gleichzeitiger TAWES-Bestätigung ist Stufe 1 als Vorwarnung korrekt – auch wenn der aktuelle Messwert unter der Schwelle liegt. Die Schwelle bestimmt ab wann Stufe 2 und 3 ausgelöst werden.

**Warum ist alarm/konfidenz manchmal gering?**
Wenn nur INCA ein Signal zeigt (keine TAWES-Bestätigung, keine ZAMG-Warnung) ist die Konfidenz naturgemäß niedrig. Das ist das System das korrekt funktioniert – einzelne Quellen werden skeptisch behandelt.

**Warum löst eine einzelne Wetterstation einen Wind-Alarm aus?**
Prüfe `alarm/wind_quelle`. Wenn dort `INCA (Xkm/h)` steht, kommt der Alarm vom Nowcast-Modell – das braucht keinen Stations-Konsens. Wenn es `TAWES_STURM` ist: TAWES benötigt mind. 2 Stationen und 30% der Upstream-Stationen.

**Regen-Alarm obwohl kein Regen sichtbar?**
Prüfe `alarm/regen_quelle`. `TAWES_LOK (Station 12km ...)` bedeutet eine Station im Lokal-Umkreis hat Regen gemeldet. Lokal-Umkreis in den Einstellungen verkleinern oder REGEN_ALARM erhöhen.

**Was bedeutet notification/tageswarnung?**
Wenn ZAMG Warnungen für die nächsten 8 Stunden hat, erscheint dort eine Zusammenfassung. Ideal für eine Loxone Morgenroutine (Zeitprogramm 07:00) damit du beim Aufstehen weißt ob heute Unwetter kommen.

---

## Datenquellen & Rechtliches

Dieses Plugin nutzt ausschließlich öffentlich zugängliche Daten von [GeoSphere Austria](https://www.geosphere.at):

- [GeoSphere Warn-API](https://warnungen.zamg.at) – Offizielle Wetterwarnungen
- [INCA Nowcast](https://dataset.api.hub.geosphere.at) – Hochauflösende Kurzvorhersage
- [TAWES Stationsdaten](https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min) – Echtzeit-Messstationen

Geocoding via [Nominatim / OpenStreetMap](https://nominatim.org) (kostenlos, kein API-Key nötig).

**Haftungsausschluss:** Die Daten dienen der Information und Hausautomation. Für die Richtigkeit der Wettervorhersagen und daraus resultierende automatisierte Handlungen wird keine Haftung übernommen. Bei Unwetter oder Extremereignissen zählen immer die aktuellen Meldungen der Behörden.

---

## Entwickler

- **Autor:** HitSmart / Stefan Hörmandinger
- **GitHub:** [HitsmartDev/Unwetter4Lox](https://github.com/HitsmartDev/Unwetter4Lox)
- **Lizenz:** MIT
