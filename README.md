# Unwetter4Lox v0.9.9

**LoxBerry-Plugin für automatische Unwettererkennung und Wetterautomatisierung.**

Unwetter4Lox kombiniert drei offizielle österreichische Wetterdatenquellen der GeoSphere Austria zu einem einheitlichen Alarmstatus und liefert alle Daten via MQTT an deinen Loxone Miniserver. Das Plugin erkennt Gewitter, Sturm, Starkregen, Hagel und Schnee – sowohl aus offiziellen Warnungen als auch aus Echtzeitmessdaten und Kurzvorhersagen.

---

## Was macht dieses Plugin?

Das Plugin läuft als Hintergrunddienst (Daemon) auf deinem LoxBerry und fragt automatisch drei Datenquellen ab:

1. **Offizielle ZAMG-Warnungen** – Behördliche Wetterwarnungen für deinen genauen Standort
2. **INCA Nowcast** – Hochauflösende 15-Minuten-Vorhersage für die nächste Stunde
3. **TAWES 360°** – Echtzeitmessungen von Wetterstationen in deiner Umgebung (Konsens, ETA, Wind-Kaskade, Lokal-Regen)

Alle drei Quellen werden automatisch zu einem **aggregierten Gesamtstatus** zusammengefasst (die `alarm/`-Topics), damit du in Loxone nicht drei verschiedene Werte auswerten musst – ein einziger Wert pro Kategorie reicht für deine Automatisierungen.

---

## Datenquellen im Detail

### 1. GeoSphere Austria – Offizielle Warnungen

Behördliche Warnungen des österreichischen Wetterdienstes, nach Standort gefiltert. Das Plugin prüft 8 Wettertypen:

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

**Warnstufen:** 1 = Gelb (Vorsicht), 2 = Orange (Warnung), 3 = Rot (erhebliche Gefahr), 4 = Lila (Extrem)

### 2. INCA Nowcast

Hochauflösende Kurzvorhersage (1 km²) für deinen GPS-Punkt, aktualisiert alle 15 Minuten:
- Böenstärke jetzt und in den nächsten 30/60 Minuten
- Aktuelle Regenrate (mm/h) und Niederschlagstyp (Regen/Schnee/Hagel/Graupel)
- Hagelgefahr in den nächsten 60 Minuten
- Minuten bis zum nächsten Regen

`inca/bald_regen` löst **keinen** `alarm/regen`-Alarm aus – nur wenn `rr_jetzt ≥ REGEN_ALARM`. So lässt sich `bald_regen` gezielt für Bewässerungssteuerung ohne Fehlalarme nutzen.

### 3. TAWES 360° – Wetterstationsnetz

Das Plugin fragt alle TAWES-Stationen im einstellbaren Umkreis ab (Standard: 120 km, max. 25 Stationen) und wertet deren Echtzeitmessungen aus:

- **Upstream-Erkennung:** Welche Stationen liegen in der Windrichtung (±70°, vektorgewichtet)? Das Wetter kommt von dort.
- **Wind-Konsens:** Mindestens 30 % der Upstream-Tal-Stationen (absolut mind. 2) müssen Böen ≥ BOEN_ALARM melden. Eine einzelne Station löst **nie** `alarm/wind` aus. Alpine Stationen (> konfigurierter Seehöhe, Standard: 1200 m) werden im Log angezeigt, fließen aber nicht in den Konsens ein.
- **Wind-Kaskade:** Erkennt wenn Upstream-Stationen zeitlich gestaffelt Böen melden (weiter weg zuerst → dann näher). Gibt `alarm/wind=1` als Vorwarnung – auch ohne Konsens-Bestätigung. Nur Böen der letzten 60 Minuten werden berücksichtigt.
- **Regen-ETA:** Wenn Upstream-Stationen Regen melden (nur letzten 30 Min im Buffer), wird die Frontgeschwindigkeit berechnet → ETA in Minuten.
- **Lokal-Regen:** Regnet es jetzt innerhalb des konfigurierten Lokal-Umkreises (Standard: 25 km)? Unabhängig von der Windrichtung. Erkennt Regen auch wenn die Front nicht aus der Windrichtung kommt.
- **Gewittersignal:** Druckabfall + hohe Luftfeuchtigkeit → Level 1. Zusätzlich starker Böenanstieg → Level 2 (Akut).
- **Wind-Trend:** Lineare Regression über 60 Minuten – zeigt ob Böen zu- oder abnehmen.

---

## Alarmstufen – Prinzip

Alle `alarm/`-Topics verwenden **einheitliche Level** mit gleicher Bedeutung über alle Kategorien:

| Level | Bedeutung | Empfehlung |
|:-----:|:----------|:-----------|
| `0` | Ruhig | Keine Aktion |
| `1` | **Vorsicht** – etwas kündigt sich an | Info-Push, vorsorglich handeln |
| `2` | **Warnung** – es wird gefährlich | Schutzmaßnahmen aktiv, Push senden |
| `3` | **Extrem** – höchste Gefahr | Sofortmaßnahmen, zwingender Alarm |

**Grundprinzip:** ZAMG-Warnstufen werden **direkt** gemappt: **Gelb → 1, Orange → 2, Rot/Lila → 3**. Das `aktiv`-Flag spielt keine Rolle für den Level. INCA und TAWES verwenden **Schwellwert-Vielfache**: `1× Schwellwert → Level 1`, `2× → Level 2`, `3× → Level 3`. Alle drei Quellen können **alle Level (0–3) erreichen**.

`alarm/gesamt` = `max(gewitter, wind, regen, hagel, schnee)` – der höchste Wert aller 5 Kategorien. Ideal als einziger Gate-Wert in Loxone-Automatisierungen.

---

## Wie werden die alarm/ Topics berechnet?

### alarm/gewitter

| Quelle | Bedingung | → Level |
|:-------|:----------|:-------:|
| ZAMG | Gewitter-Warnung Gelb (Stufe 1) | **1** |
| TAWES | Gewittersignal Lvl 1 (Druckabfall + hohe Feuchte) | **1** |
| ZAMG | Gewitter-Warnung Orange (Stufe 2) | **2** |
| TAWES | Gewittersignal Lvl 2 (+ starke Böenzunahme) | **2** |
| System | Behördliche Akutwarnung (GWA) | **≥ 2** |
| ZAMG | Gewitter-Warnung Rot/Lila (Stufe 3/4) | **3** |

### alarm/wind

INCA verwendet `fx_max_60min` direkt (kein Konsens nötig – Nowcast-Modell). TAWES benötigt entweder Konsens-Bestätigung (`sturm_upstream=1`) oder eine erkannte Wind-Kaskade (`wind_kaskade=1`).

| Quelle | Bedingung | → Level |
|:-------|:----------|:-------:|
| ZAMG | Wind-Warnung Gelb (Stufe 1) | **1** |
| INCA | `fx_max_60min` ≥ **1 × BOEN_ALARM** | **1** |
| TAWES | Konsens-Sturm upstream ≥ **1 × BOEN_ALARM** (`sturm_upstream=1`) | **1** |
| TAWES | Wind-Kaskade erkannt (`wind_kaskade=1`) – Vorwarnung | **1** |
| ZAMG | Wind-Warnung Orange (Stufe 2) | **2** |
| INCA | `fx_max_60min` ≥ **2 × BOEN_ALARM** | **2** |
| TAWES | Konsens-Sturm upstream ≥ **2 × BOEN_ALARM** | **2** |
| ZAMG | Wind-Warnung Rot/Lila (Stufe 3/4) | **3** |
| INCA | `fx_max_60min` ≥ **3 × BOEN_ALARM** | **3** |
| TAWES | Konsens-Sturm upstream ≥ **3 × BOEN_ALARM** | **3** |

> **TAWES Wind-Konsens (ab v0.4.24):** Mind. 30 % der Upstream-Tal-Stationen (absolut mind. 2) müssen Böen ≥ BOEN_ALARM melden. Alpine Stationen (> konfigurierter Seehöhe) sind ausgeschlossen. Eine Einzelstation allein löst nie Alarm aus.

### alarm/regen

`inca/bald_regen` und Regenraten unter REGEN_ALARM lösen **keinen** Alarm aus. TAWES Lokal-Regen wird nur für Stationen innerhalb des konfigurierten Lokal-Umkreises (Standard: 25 km) gewertet.

| Quelle | Bedingung | → Level |
|:-------|:----------|:-------:|
| ZAMG | Regen-Warnung Gelb (Stufe 1) | **1** |
| INCA | Regenrate `rr_jetzt` ≥ **1 × REGEN_ALARM** | **1** |
| TAWES | Upstream-Intensität `regen_upstream_mm` ≥ **1 × REGEN_ALARM** | **1** |
| TAWES | Lokal-Regen `regen_lokal_mm` ≥ **1 × REGEN_ALARM** (≤ Lokal-Umkreis) | **1** |
| ZAMG | Regen-Warnung Orange (Stufe 2) | **2** |
| INCA | Regenrate `rr_jetzt` ≥ **2 × REGEN_ALARM** | **2** |
| TAWES | `regen_upstream_mm` oder `regen_lokal_mm` ≥ **2 × REGEN_ALARM** | **2** |
| ZAMG | Regen-Warnung Rot/Lila (Stufe 3/4) | **3** |
| INCA | Regenrate `rr_jetzt` ≥ **3 × REGEN_ALARM** | **3** |
| TAWES | `regen_upstream_mm` oder `regen_lokal_mm` ≥ **3 × REGEN_ALARM** | **3** |

> `inca/bald_regen` ist ein Info-Topic für Bewässerungssteuerung und löst absichtlich **keinen** `alarm/regen` aus.

### alarm/hagel

| Quelle | Bedingung | → Level |
|:-------|:----------|:-------:|
| ZAMG | Hagel-Warnung Gelb (Stufe 1) | **1** |
| INCA | Hagel oder Graupel möglich (`bald_hagel` / `bald_graupel`) | **1** |
| ZAMG | Hagel-Warnung Orange/höher (Stufe 2+) | **2** |

### alarm/schnee

| Quelle | Bedingung | → Level |
|:-------|:----------|:-------:|
| ZAMG | Schnee- oder Glatteis-Warnung Gelb (Stufe 1) | **1** |
| INCA | Niederschlagstyp = Schnee (PT=2) oder Schneeregen (PT=3) | **1** |
| ZAMG | Schnee- oder Glatteis-Warnung Orange/höher (Stufe 2+) | **2** |

---

## Konfigurierbare Schwellwerte

### Böen-Alarmschwelle (`BOEN_ALARM`)

**Standard: 60 km/h** (= Beaufort 8, Sturmböen)

| Bedingung | `alarm/wind` Level |
|:----------|:------------------:|
| INCA `fx_max_60min` oder TAWES Konsens-Upstream ≥ **1 × BOEN_ALARM** | **1** (Vorsicht) |
| ≥ **2 × BOEN_ALARM** | **2** (Warnung) |
| ≥ **3 × BOEN_ALARM** | **3** (Extrem) |

Empfehlungen: `40 km/h` für Beaufort 6 (sensibel), `60 km/h` für Sturmschutz (Standard), `80 km/h` für schwere Stürme (Beaufort 9+).

### Regen-Alarmschwelle (`REGEN_ALARM`)

**Standard: 10.0 mm/h** (starker Regen)

**Wichtig:** TAWES liefert Regen in mm/10min. Die Umrechnung auf mm/h (× 6) erfolgt intern. Der konfigurierte Wert gilt immer in mm/h.

| Bedingung | `alarm/regen` Level |
|:----------|:-------------------:|
| INCA oder TAWES ≥ **1 × REGEN_ALARM** | **1** (Vorsicht) |
| ≥ **2 × REGEN_ALARM** | **2** (Warnung) |
| ≥ **3 × REGEN_ALARM** | **3** (Extrem) |

Empfehlungen: `5.0 mm/h` für Bewässerungsabschaltung, `10.0 mm/h` Standard, `30.0 mm/h` bei Starkregen.

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
| TAWES Konsens-Schwelle | 30 % | Mindestanteil Upstream-Stationen für Wind-Alarm (absolut mind. 2) |
| TAWES Max. Seehöhe Upstream | 1200 m | Alpine Stationen über dieser Seehöhe aus Wind-Konsens ausschließen |
| **TAWES Lokal-Regen Umkreis** | **25 km** | **Umkreis für `tawes/regen_lokal` und lokalen `alarm/regen`-Beitrag** |
| Abruf-Intervall | 300 s | Wie oft Daten abgerufen werden |
| **Böen-Alarmschwelle** | **60 km/h** | **INCA + TAWES Wind-Alarm ab diesem Wert** |
| **Regen-Alarmschwelle** | **10.0 mm/h** | **INCA + TAWES Regen-Alarm ab dieser Regenrate** |
| Min. Warnstufe | 1 (Gelb) | Ab welcher ZAMG-Stufe Notification-Text erzeugt wird |
| MQTT Präfix | `unwetter/` | Präfix für alle MQTT Topics |
| MQTT Broker | automatisch | Standard: LoxBerry MQTT Gateway |

---

## Vollständige MQTT-Topic-Referenz

Standard-Präfix: `unwetter/` (in den Einstellungen änderbar). Alle Topics werden mit `retain=true` publiziert.

### System-Topics

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `status` | Systemzustand des Daemons | `OK` / `Error - [Quelle]` |
| `status/zamg_ok` | ZAMG API erreichbar | `0` / `1` |
| `status/inca_ok` | INCA API erreichbar | `0` / `1` |
| `status/tawes_ok` | TAWES API erreichbar | `0` / `1` |
| `letzter_abruf_datum` | Zeitpunkt des letzten Abrufs | `07.06.2026 14:30:00` |
| `letzter_abruf_epoch` | Unix-Timestamp des letzten Abrufs | `1749303000` |

### Gesamtstatus (alarm/)

| Topic | Beschreibung | Mögliche Werte |
|:------|:-------------|:---------------|
| `alarm/gesamt` | `max(gewitter, wind, regen, hagel, schnee)` – primärer Gate-Wert | `0`–`3` |
| `alarm/gewitter` | Gewittergefahr aus ZAMG + TAWES-Gewittersignal | `0`–`3` |
| `alarm/wind` | Windgefahr aus ZAMG + INCA + TAWES Konsens/Kaskade | `0`–`3` |
| `alarm/regen` | Regenrisiko aus ZAMG + INCA + TAWES (Upstream + Lokal) | `0`–`3` |
| `alarm/hagel` | Hagelgefahr aus ZAMG + INCA | `0`–`2` |
| `alarm/schnee` | Schnee/Glatteis aus ZAMG + INCA | `0`–`2` |
| `alarm/stufe` | Höchste **offizielle** ZAMG-Warnstufe (nur ZAMG) | `0`–`4` |
| `alarm/zusammenfassung` | Fertiger Anzeigetext mit Emoji-Symbolen | Text |
| `alarm/entwarnung` | Wechselt **einmalig** auf `1` wenn Alarm endet (dann sofort wieder `0`) | `0` / `1` |
| `alarm/wind_quelle` | Welche Quelle hat `alarm/wind` ausgelöst | `ZAMG` / `INCA (52km/h)` / `TAWES_STURM (65km/h)` / `TAWES_KASKADE` / `–` |
| `alarm/regen_quelle` | Welche Quelle hat `alarm/regen` ausgelöst | `ZAMG` / `INCA (3.5mm/h)` / `TAWES_UPSTREAM (12mm/h)` / `TAWES_LOKAL (VÖCKLABRUCK 8km 31.8mm/h)` / `–` |

### GeoSphere Austria Warnungen (zamg/)

| Topic | Beschreibung | Werte |
|:------|:-------------|:------|
| `zamg/max_stufe` | Höchste aktive Warnstufe | `0`–`4` |
| `zamg/irgendwas_aktiv` | Mind. eine aktive/baldige Warnung (Gate für Morgen-Push) | `0` / `1` |
| `zamg/akutwarnung` | Behördliche Akutwarnung (GWA) | `0` / `1` |
| `zamg/letzter_abruf` | Zeitstempel letzter ZAMG-Abruf | Datum/Uhrzeit |
| `zamg/{typ}/stufe` | Warnstufe je Wettertyp | `0`–`4` |
| `zamg/{typ}/aktiv` | Warnung gerade aktiv | `0` / `1` |
| `zamg/{typ}/bald` | Warnung beginnt in < 30 min | `0` / `1` |
| `zamg/{typ}/start_epoch` | Warnungsbeginn Unix-Timestamp | `0` wenn keine Warnung |
| `zamg/{typ}/end_epoch` | Warnungsende Unix-Timestamp | `0` wenn keine Warnung |
| `zamg/{typ}/notification` | Klartext für Push-Benachrichtigung | `ORANGE – Wind \| heute 14:00–20:00` |

**Typen `{typ}`:** `wind`, `regen`, `schnee`, `glatteis`, `gewitter`, `hagel`, `hitze`, `kaelte`

### INCA Nowcast (inca/)

| Topic | Beschreibung | Einheit |
|:------|:-------------|:--------|
| `inca/fx` | Aktuelle Böenstärke | km/h |
| `inca/ff` | Aktuelle Windgeschwindigkeit | km/h |
| `inca/fx_max_30min` | Max. Böen in den nächsten 30 min | km/h |
| `inca/fx_max_60min` | Max. Böen in den nächsten 60 min – entscheidet über `alarm/wind` | km/h |
| `inca/rr` | Aktuelle Regenrate | mm/h |
| `inca/regen_alarm` | Regenrate ≥ REGEN_ALARM | `0` / `1` |
| `inca/minuten_bis_regen` | Zeit bis Regenstart | min (`-1` = bleibt trocken) |
| `inca/bald_regen` | Regen in < 30 min erwartet (kein alarm/regen-Trigger) | `0` / `1` |
| `inca/bald_hagel` | Hagel in < 60 min erwartet | `0` / `1` |
| `inca/bald_graupel` | Graupel in < 60 min möglich | `0` / `1` |
| `inca/bald_sturm_30` | Böen ≥ BOEN_ALARM in < 30 min (Info-Topic) | `0` / `1` |
| `inca/bald_sturm_60` | Böen ≥ BOEN_ALARM in < 60 min (Info-Topic) | `0` / `1` |
| `inca/pt` | Niederschlagstyp jetzt (Code) | `1`=Regen, `2`=Schnee, `3`=Schneeregen, `4`=Graupel, `5`=Hagel, `255`=kein |
| `inca/pt_name` | Niederschlagstyp jetzt (Text) | `Regen`, `Schnee`, `kein Niederschlag`, … |
| `inca/pt_bald` | Typ des nächsten Regens (Code) | wie `inca/pt`; `255` wenn kein Regen in Sicht |
| `inca/pt_bald_name` | Typ des nächsten Regens (Text) | z.B. `Regen`; leer wenn kein Regen in Sicht |
| `inca/letzter_abruf` | Zeitstempel letzter INCA-Abruf | Datum/Uhrzeit |

### TAWES 360° Stationsdaten (tawes/)

| Topic | Beschreibung | Einheit/Werte |
|:------|:-------------|:-------------|
| `tawes/dominante_windrichtung` | Dominante Windrichtung (vektorgewichtet) | Grad (0–360) |
| `tawes/dominante_windrichtung_name` | Windrichtung als Himmelsrichtung | `N`, `NO`, `O`, `SO`, `S`, `SW`, `W`, `NW` |
| `tawes/upstream_aktiv` | Anzahl aktiver Upstream-Stationen | Ganzzahl |
| `tawes/wind_upstream_kmh` | Max. Böen an Upstream-Tal-Stationen (Rohwert, kein Konsens) | km/h |
| `tawes/wind_trend` | Böen-Trendrichtung (letzte 60 min, Regression) | `-1`=fallend, `0`=stabil, `1`=steigend |
| `tawes/sturm_upstream` | Konsens-Sturm bestätigt (≥ 30% Upstream-Tal-Stationen) | `0` / `1` |
| `tawes/wind_kaskade` | Zeitlich gestaffelte Böen upstream (Sturmfront im Anmarsch) | `0` / `1` |
| `tawes/wind_kaskade_eta_min` | ETA der Sturmfront laut Kaskade | min (`-1` = unbekannt) |
| `tawes/wind_kaskade_speed_kmh` | Berechnete Frontgeschwindigkeit (Kaskade) | km/h |
| `tawes/alpine_upstream` | Anzahl alpiner Upstream-Stationen (aus Konsens ausgeschlossen) | Ganzzahl |
| `tawes/regen_upstream` | Regen aus Windrichtung (letzte 30 min, ab ~0.6 mm/h) | `0` / `1` |
| `tawes/regen_upstream_mm` | Max. Upstream-Regen-Intensität (nur bei Konsens) | mm/h (0 = kein Konsens) |
| `tawes/regen_eta_min` | Minuten bis Regenfront ankommt | min (`-1` = unbekannt) |
| `tawes/front_speed_kmh` | Berechnete Frontgeschwindigkeit | km/h |
| `tawes/regen_konfidenz` | Zuverlässigkeit der ETA-Berechnung | `0`–`100` (%) |
| `tawes/regen_lokal` | Regen jetzt innerhalb Lokal-Umkreis (konfigurierbar, Standard 25 km) | `0` / `1` |
| `tawes/regen_lokal_mm` | Max. Regenintensität im Lokal-Umkreis | mm/h (0 = kein Lokal-Regen) |
| `tawes/regen_lokal_station` | Station die `regen_lokal_mm` bestimmt | z.B. `VOECKLABRUCK (12km) 8.4mm/h` |
| `tawes/druck_trend` | Luftdrucktendenz nächste Upstream-Station | hPa/10min, negativ=fallend |
| `tawes/gewitter_signal` | Gewitterindikator aus Druck + Feuchte + Böen | `0`=kein, `1`=möglich, `2`=akut |
| `tawes/stationen_anzahl` | Gesamtzahl erreichter Stationen im Radius | Ganzzahl |
| `tawes/naechste_station` | Name, Distanz und Richtung der nächsten Upstream-Station | `Gmunden (23km, SW)` |
| `tawes/api_ok` | Letzter TAWES-API-Abruf erfolgreich | `0` / `1` |
| `tawes/letztes_update` | Zeitstempel letzter TAWES-Abruf | Datum/Uhrzeit |

### Notifications (notification/)

Textmeldungen für Push-Benachrichtigungen. Werden nur publiziert wenn sich der Inhalt ändert.

**Wichtig:** `notification/inca`, `notification/tawes` und `notification/alle` werden **nur publiziert wenn `alarm/gesamt ≥ 1`**. Bei inaktivem Alarm werden diese Topics leer (retained Message im Broker wird gelöscht). `notification/geosphere` ist immer aktiv. `notification/alle` enthält **keine** "kein Alarm"-Texte von Quellen die selbst keinen Alarm haben.

| Topic | Beschreibung | Beispiel |
|:------|:-------------|:---------|
| `notification/geosphere` | ZAMG-Warnungen als Klartext. **Immer aktiv.** | `⚠️ ORANGE – Wind \| heute 14:00 – morgen 06:00` |
| `notification/inca` | INCA Nowcast-Zusammenfassung. Nur bei alarm/gesamt ≥ 1. | `🟠 Sturmböen <30 min: max 75 km/h` |
| `notification/tawes` | TAWES-Lagebericht. Nur bei alarm/gesamt ≥ 1. | `🌧 Regenfront ~18min \| 62km/h aus W \| 78% Konfidenz` |
| `notification/alle` | Alle aktiven Meldungen kombiniert (durch `──` getrennt). **Empfehlung für Loxone Push.** | Kombinierter Text / `✅ Entwarnung` (einmalig) |

**Entwarnung:** Wenn `alarm/gesamt` von ≥1 auf 0 fällt: `alarm/entwarnung` wechselt einmalig auf `1`, `notification/alle` sendet einmalig `✅ Entwarnung – alle Wetterwarnungen aufgehoben.`

---

## Installation

1. ZIP über den **LoxBerry Plugin Manager** installieren
2. In die **Einstellungen** wechseln
3. **Standort festlegen** – Adresse eingeben → "Suchen", oder "Vom Miniserver" Button
4. Dienste aktivieren: ZAMG, INCA Nowcast, TAWES 360°
5. Schwellwerte nach Bedarf anpassen (Böen-Alarm, Regen-Alarm)
6. TAWES Konsens-Schwelle und Seehöhe prüfen (Standard meist sinnvoll)
7. MQTT-Broker einstellen (Standard: LoxBerry MQTT Gateway automatisch)
8. Daemon im **Status-Tab** starten
9. In **Loxone Config**: MQTT Virtual Inputs anlegen (s.u.)

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

# Diagnose
unwetter/alarm/wind_quelle       → "Wind-Alarm Quelle"  (Text: ZAMG/INCA/TAWES/–)
unwetter/alarm/regen_quelle      → "Regen-Alarm Quelle" (Text: ZAMG/INCA/TAWES/–)

# Push-Benachrichtigung
unwetter/notification/alle       → "Wettermeldung" (Text für Push-Trigger)
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

**Morgen-Zusammenfassung ZAMG (nur wenn Warnung anliegt):**
```
Zeitprogramm: täglich 07:00 Uhr
Bedingung:    zamg/irgendwas_aktiv = 1   ← Gate: 0 = kein Push
Nachricht:    notification/geosphere
```

**Entwarnung nach Unwetter:**
```
Auslöser: alarm/entwarnung = 1  (wechselt einmalig auf 1 wenn Alarm endet)
Nachricht: notification/alle    (enthält "✅ Entwarnung – alle Wetterwarnungen aufgehoben.")
```

**Hagelschutz:**
```
Auslöser: alarm/hagel >= 1  ODER  inca/bald_hagel = 1
Aktion:   Carport-Tor schließen, Push senden
```

---

## Technische Details

### Daemon-Betrieb & Zuverlässigkeit

**Autostart nach Reboot:** Der Daemon startet automatisch 120 Sekunden nach dem Systemstart – die Verzögerung stellt sicher dass der MQTT-Broker schon läuft. Eingerichtet via `/etc/cron.d/unwetter4lox` (root-owned, überleben Updates).

**Täglicher Neustart:** Jeden Tag um 03:00 Uhr wird der Daemon automatisch neu gestartet. Dies bereinigt potenzielle MQTT-Langzeitprobleme (stale TCP-Verbindungen die nach mehreren Tagen auftreten können).

**Watchdog (alle 5 min):** Ein Cron-Job prüft alle 5 Minuten ob der Daemon noch läuft. Bei einem Absturz werden `daemon.pid` und `state.json` automatisch gelöscht und der Daemon neu gestartet. Die Datei `state.json` wird gelöscht damit die UI keine veralteten Wetterdaten anzeigt.

**MQTT-Robustheit (v0.9.9):** paho-mqtt `loop_start()` + `reconnect_delay_set(5s, 60s)` sorgen für automatische Wiederverbindung. Der Daemon erkennt beim Start bestehende Instanzen und beendet diese (verhindert Client-ID-Konflikte). Ein 5-Minuten-Watchdog im Python-Daemon triggert bei dauerhafter Trennung einen Hard-Reset. Zombie-TCP-Verbindungen werden nach 30 Minuten erkannt.

**INCA Parallel-Abruf (v0.9.9):** Die 4 INCA API-Parameter (ff, fx, rr, pt) werden gleichzeitig (parallel) abgerufen. Bei API-Timeouts dauert ein Zyklus maximal ~15 Sekunden statt bis zu 60 Sekunden (4 × 15s sequentiell).

**API-Fehler über MQTT:**  `status/api_ok` (0/1) und `status/api_fehler` (Text) informieren Loxone bei API-Problemen. Damit können Push-Notifications für technische Fehler konfiguriert werden.

### TAWES 360° – So funktioniert es

**Stationen laden:** Der Daemon löscht den Stations-Cache bei **jedem Start** automatisch und lädt alle Stationen im Radius frisch von der GeoSphere-API. Datenlücken in den ersten 10–20 Minuten nach Start sind normal (Buffer füllt sich).

**Windrichtungsberechnung:** Vektorgewichteter Durchschnitt (keine einfache Mittelung die bei 350°+10° = 180° falsch wäre). Stärker blas ende Stationen haben mehr Gewicht.

**Upstream-Erkennung:** Alle Stationen innerhalb ±70° der dominanten Windrichtung. Das Wetter kommt von dort.

**Wind-Konsens (ab v0.4.24):** Mind. max(2, 30%) der Upstream-Tal-Stationen müssen Böen ≥ BOEN_ALARM melden. Alpine Stationen (> MAX_UPSTREAM_HOEHE_M) erscheinen im Log aber fließen nicht in den Konsens ein. Verhindert False-Alarms durch Ausreißer-Stationen.

**Wind-Kaskade (ab v0.4.26):** Erkennt zeitlich gestaffelte Böen (weit weg → nah). Vorwarnung ohne Konsens. Nur Böen der letzten 60 Minuten werden berücksichtigt (WIND_KASKADE_FENSTER = 6 × 10 min).

**Regen-Buffer (ab v0.4.27):** `regen_upstream` prüft nur die letzten 30 Minuten im Buffer (REGEN_PUFFER_FENSTER = 3 × 10 min). Verhindert dass Regen der vor Stunden gefallen ist noch stundenlang `regen_upstream=1` zeigt.

**Lokal-Regen (ab v0.4.25/v0.4.28):** Stationen innerhalb des konfigurierten Lokal-Umkreises (Standard: 25 km) werden unabhängig von der Windrichtung auf aktuellen Regen geprüft. Stationen zwischen 25–40 km erscheinen im Info-Log, lösen aber keinen Alarm aus. Der Radius ist in den Einstellungen konfigurierbar.

**TAWES RR-Einheit:** Die GeoSphere-API liefert Niederschlag in **mm/10min**. Das Plugin rechnet intern auf **mm/h** um (× 6). Der konfigurierte REGEN_ALARM gilt immer in mm/h. Beispiel: Station zeigt 5.0 mm/10min = 30 mm/h.

### Alarm-Diagnose-Topics

Ab v0.4.27/v0.4.28 gibt es zwei neue Topics die sofort zeigen welche Quelle den Alarm ausgelöst hat, ohne Log-Analyse:

- `alarm/wind_quelle`: z.B. `INCA (52km/h)`, `TAWES_STURM (65km/h)`, `TAWES_KASKADE`, `ZAMG`, `–`
- `alarm/regen_quelle`: z.B. `INCA (3.5mm/h)`, `TAWES_UPSTREAM (12mm/h)`, `TAWES_LOKAL (VÖCKLABRUCK 8km 31.8mm/h)`, `ZAMG`, `–`

### Notification-Gate und Deduplizierung

- `notification/inca`, `notification/tawes`, `notification/alle`: nur bei `alarm/gesamt ≥ 1`
- Wird nur publiziert wenn sich der Inhalt ändert (kein Spam)
- Bei `alarm = 0`: leerer String → löscht retained Message im Broker
- `notification/alle` enthält keine "kein Alarm"-Fallback-Texte wenn nur TAWES/ZAMG Alarm aktiv ist (ab v0.4.28)

---

## FAQ

**Warum löst eine einzelne Wetterstation einen Wind-Alarm aus?**
Sollte seit v0.4.26 nicht mehr passieren. TAWES benötigt Konsens (min. 2 Stationen, 30% der Upstream-Tal-Stationen). Prüfe `alarm/wind_quelle`: wenn dort `INCA (Xkm/h)` steht kommt der Alarm vom Nowcast-Modell – das braucht keinen Konsens.

**Regen-Alarm obwohl kein Regen sichtbar?**
Prüfe `alarm/regen_quelle`. `TAWES_LOKAL (Station 28km ...)` bedeutet eine Station im Lokal-Umkreis hat starken Regen gemeldet. Lokal-Umkreis in den Einstellungen verkleinern (Standard 25 km) oder REGEN_ALARM erhöhen.

**Warum ist regen_eta_min = -1?**
Für die ETA-Berechnung braucht man mind. 2 Stationen mit Regen in den letzten 30 Minuten. Bei nur einer Station oder ohne Daten im 30-min-Fenster bleibt der Wert -1.

**Was ist der Unterschied ZAMG vs. alarm/?**
ZAMG-Warnungen sind offizielle, oft Stunden im Voraus ausgegebene Warnungen. Die `alarm/`-Topics kombinieren ZAMG + INCA + TAWES und können auch kurzfristig ohne aktive ZAMG-Warnung anspringen (z.B. bei lokalem Starkregen laut TAWES).

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
