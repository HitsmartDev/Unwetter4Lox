<?php
require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'common.php';

$L = LBSystem::readlanguage('language.ini');

render_header('app_help');
?>

<!-- ================================================================
     WAS IST UNWETTER4LOX
     ================================================================ -->
<details class="sl-details" open>
<summary>📖 Was ist Unwetter4Lox?</summary>
<div class="sl-details-body">
<p>Unwetter4Lox ist ein LoxBerry-Plugin, das österreichische Wetterwarnungen und Nowcast-Daten in Echtzeit via <b>MQTT</b> an deinen <b>Loxone Miniserver</b> liefert. Es kombiniert drei Datenquellen zu einem einheitlichen Alarmsystem:</p>
<ul>
    <li><b>GeoSphere Austria (ZAMG)</b> – Offizielle Warnungen (Stufe 1–4)</li>
    <li><b>INCA Nowcast</b> – Hochauflösende 15min-Vorhersage der nächsten 60 min</li>
    <li><b>TAWES 360°</b> – Live-Messdaten von Wetterstationen im Umkreis → Regen-ETA, Wind-Kaskade, Lokal-Regen, Gewitter-Vorhersage</li>
</ul>
<p>Alle Daten landen als MQTT-Nachrichten auf deinem LoxBerry-Broker und können direkt in Loxone-Logiken (Virtual Input) verwendet werden.</p>
<p><a href="https://github.com/HitsmartDev/Unwetter4Lox" target="_blank">GitHub Repository</a></p>
</div>
</details>

<!-- ================================================================
     DATENQUELLEN
     ================================================================ -->
<details class="sl-details">
<summary>🌍 Datenquellen &amp; APIs</summary>
<div class="sl-details-body">

<details class="sl-details-nested" open>
<summary>1. GeoSphere Austria – Offizielle Warnungen</summary>
<div class="sl-details-body">
<p>API: <code>https://warnungen.zamg.at/wsapp/api/getWarningsForCoords</code></p>
<p>Liefert offizielle Unwetterwarnungen für genau deine Koordinaten. Kategorien: Wind, Regen, Schnee, Glatteis, Gewitter, Hagel, Hitze, Kälte.</p>
<table class="sl-mqtt-tbl"><thead><tr><th>Stufe</th><th>Farbe</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td>0</td><td><span class="sl-tag ok">Grün</span></td><td>Keine Warnung</td></tr>
<tr><td>1</td><td><span class="sl-tag yellow">Gelb</span></td><td>Vorsicht – erhöhte Aufmerksamkeit</td></tr>
<tr><td>2</td><td><span class="sl-tag warn">Orange</span></td><td>Markant – Schäden möglich</td></tr>
<tr><td>3</td><td><span class="sl-tag err">Rot</span></td><td>Unwetter – erhebliche Schäden</td></tr>
<tr><td>4</td><td><span class="sl-tag purple">Lila</span></td><td>Extrem – Lebensgefahr möglich</td></tr>
</tbody></table>
</div>
</details>

<details class="sl-details-nested">
<summary>2. INCA Nowcast</summary>
<div class="sl-details-body">
<p>API: <code>https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km</code></p>
<p>Hochauflösende (1 km²) Kurzfristvorhersage, alle 15 Minuten aktualisiert. Parameter: FF (Wind), FX (Böen), RR (Niederschlag mm/h), PT (Niederschlagstyp).</p>
<p><b>Korrelationslogik:</b> INCA allein → max. Alarm-Stufe 1. INCA + TAWES → volle Stufen 1–3. INCA-Signal seit ≥4 Zyklen (~20 min) → bis Stufe 2 ohne TAWES.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>3. TAWES 360° – Wetterstation-Korrelation</summary>
<div class="sl-details-body">
<p>API: <code>https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min</code></p>
<p>Ruft Live-Messdaten von Wetterstationen im Umkreis ab und erkennt:</p>
<ul>
    <li><b>Upstream-Erkennung:</b> Welche Stationen liegen in Windrichtung? (Vektormittelung, ±45° Standard)</li>
    <li><b>Regen-ETA:</b> Entfernung ÷ Windgeschwindigkeit – unabhängig vom INCA-Modell</li>
    <li><b>Lokal-Regen:</b> Regen im Nahbereich (Standard 25 km), windrichtungsunabhängig</li>
    <li><b>Wind-Kaskade:</b> Zeitlich gestaffelte Böen von fern nach nah → Sturmfront-ETA</li>
    <li><b>Wind-Konsens:</b> ≥30% der Upstream-Tal-Stationen müssen Böen ≥ Schwelle melden</li>
    <li><b>Alpine Stationen:</b> Stationen über konfigurierter Seehöhe aus Konsens ausgeschlossen</li>
</ul>
<p class="sl-hint"><b>TAWES RR-Einheit:</b> Die API liefert mm/10min. Im Plugin werden ×6 → mm/h umgerechnet. Schwellwerte immer in mm/h.</p>
</div>
</details>

</div>
</details>

<!-- ================================================================
     ALARMSTUFEN
     ================================================================ -->
<details class="sl-details">
<summary>🧠 Wie werden Alarmstufen bestimmt?</summary>
<div class="sl-details-body">

<details class="sl-details-nested" open>
<summary>Grundprinzip: Vertrauen durch Bestätigung</summary>
<div class="sl-details-body">
<table class="sl-mqtt-tbl"><thead><tr><th>Quelle</th><th>Allein</th><th>Mit Bestätigung</th></tr></thead><tbody>
<tr><td><b>ZAMG</b></td><td>Stufe 1–3 direkt (amtlich)</td><td>– (kein Konsens nötig)</td></tr>
<tr><td><b>INCA</b></td><td>Max. Stufe 1</td><td>+ TAWES oder ZAMG → Stufe 1–3</td></tr>
<tr><td><b>TAWES</b></td><td>Max. Stufe 1</td><td>+ INCA → Stufe 1–3</td></tr>
</tbody></table>
<p class="sl-hint"><b>Dauersignal:</b> INCA seit ≥4 Zyklen (~20 min) konstant → max. Stufe 2 auch ohne TAWES.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Schwellwerte und Stufen</summary>
<div class="sl-details-body">
<table class="sl-mqtt-tbl"><thead><tr><th>Schwellwert</th><th>Stufe 1</th><th>Stufe 2</th><th>Stufe 3</th></tr></thead><tbody>
<tr><td>REGEN_ALARM (Standard 10 mm/h)</td><td>≥ 1× (≥10)</td><td>≥ 2× (≥20)</td><td>≥ 3× (≥30)</td></tr>
<tr><td>BOEN_ALARM (Standard 60 km/h)</td><td>≥ 1× (≥60)</td><td>≥ 2× (≥120)</td><td>≥ 3× (≥180)</td></tr>
</tbody></table>
<p>ZAMG-Stufen: <b>Gelb → 1, Orange → 2, Rot/Lila → 3</b>.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Drei unabhängige ETA-Quellen</summary>
<div class="sl-details-body">
<ol>
    <li><b>INCA-Modell-ETA</b> – <code>minuten_bis_regen</code> aus dem Nowcast-Modell</li>
    <li><b>Trend-ETA</b> – Extrapolation aus dem zeitlichen ETA-Verlauf der letzten ~40 Minuten</li>
    <li><b>Physik-ETA</b> – Entfernung der nächsten upstream Regenstation ÷ Windgeschwindigkeit</li>
</ol>
<p>Wenn INCA-ETA und Physik-ETA innerhalb 10 Minuten übereinstimmen → Konfidenz +15, früherer ETA wird angezeigt.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Konfidenz-Score (0–100)</summary>
<div class="sl-details-body">
<table class="sl-mqtt-tbl"><thead><tr><th>Bedingung</th><th>Punkte</th></tr></thead><tbody>
<tr><td>ZAMG-Warnung aktiv</td><td>+40</td></tr>
<tr><td>INCA-Signal vorhanden</td><td>+30</td></tr>
<tr><td>TAWES bestätigt</td><td>+20</td></tr>
<tr><td>Trend konsistent (pro Zyklus, max. +25)</td><td>+5 je Zyklus</td></tr>
<tr><td>Beschleunigung erkannt</td><td>+10</td></tr>
<tr><td>INCA-ETA und Physik-ETA stimmen überein</td><td>+15</td></tr>
</tbody></table>
</div>
</details>

</div>
</details>

<!-- ================================================================
     MQTT TOPICS
     ================================================================ -->
<details class="sl-details">
<summary>📡 MQTT Topics – vollständige Referenz</summary>
<div class="sl-details-body">
<p>Standard-Präfix: <code>unwetter/</code> (konfigurierbar). Alle Topics mit <b>retain=true</b>.</p>

<details class="sl-details-nested" open>
<summary>🚦 alarm/ – Aggregierter Gesamtstatus</summary>
<div class="sl-details-body">
<p><b>Stufen:</b> 0 = ruhig · 1 = Vorsicht · 2 = Warnung · 3 = Extrem</p>
<table class="sl-mqtt-tbl"><thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td><code>alarm/gesamt</code></td><td>0–3</td><td>max(gewitter, wind, regen, hagel, schnee)</td></tr>
<tr><td><code>alarm/gewitter</code></td><td>0–3</td><td>Aggregierter Gewitteralarm</td></tr>
<tr><td><code>alarm/wind</code></td><td>0–3</td><td>Aggregierter Windalarm</td></tr>
<tr><td><code>alarm/wind_quelle</code></td><td>Text</td><td>ZAMG / INCA (52km/h) / TAWES_STURM / TAWES_KASKADE / –</td></tr>
<tr><td><code>alarm/regen</code></td><td>0–3</td><td>Aggregierter Regenalarm</td></tr>
<tr><td><code>alarm/regen_quelle</code></td><td>Text</td><td>ZAMG / INCA (3.5mm/h) / TAWES_UP / TAWES_LOK / –</td></tr>
<tr><td><code>alarm/hagel</code></td><td>0–2</td><td>Hagelwarnung</td></tr>
<tr><td><code>alarm/schnee</code></td><td>0–2</td><td>Schnee- / Eiswarnung</td></tr>
<tr><td><code>alarm/stufe</code></td><td>0–4</td><td>Höchste ZAMG-Stufe (nur ZAMG)</td></tr>
<tr><td><code>alarm/konfidenz</code></td><td>0–100</td><td>Sicherheits-Score</td></tr>
<tr><td><code>alarm/eta_min</code></td><td>Min / -1</td><td>Beste ETA-Schätzung (-1 = unbekannt)</td></tr>
<tr><td><code>alarm/regen_trend</code></td><td>Text</td><td>stark_zunehmend / zunehmend / stabil / abnehmend / unbekannt</td></tr>
<tr><td><code>alarm/zusammenfassung</code></td><td>Text</td><td>Lesbarer Alarmtext</td></tr>
<tr><td><code>alarm/entwarnung</code></td><td>0 / 1</td><td>Einmalig 1 wenn Alarm auf 0 fällt</td></tr>
</tbody></table>
</div>
</details>

<details class="sl-details-nested">
<summary>🌩️ zamg/ – Offizielle GeoSphere Warnungen</summary>
<div class="sl-details-body">
<p>Typen: <code>wind · regen · schnee · glatteis · gewitter · hagel · hitze · kaelte</code></p>
<table class="sl-mqtt-tbl"><thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td><code>zamg/max_stufe</code></td><td>0–4</td><td>Höchste aktive Stufe über alle Typen</td></tr>
<tr><td><code>zamg/irgendwas_aktiv</code></td><td>0 / 1</td><td>1 wenn mind. eine Warnung aktiv/bald</td></tr>
<tr><td><code>zamg/akutwarnung</code></td><td>0 / 1</td><td>Stationsbasierte Akutwarnung</td></tr>
<tr><td><code>zamg/{typ}/stufe</code></td><td>0–4</td><td>Warnstufe</td></tr>
<tr><td><code>zamg/{typ}/aktiv</code></td><td>0 / 1</td><td>Warnung läuft gerade</td></tr>
<tr><td><code>zamg/{typ}/bald</code></td><td>0 / 1</td><td>Beginnt in &lt;30 Minuten</td></tr>
<tr><td><code>zamg/{typ}/notification</code></td><td>Text</td><td>Fertiger Push-Text</td></tr>
</tbody></table>
</div>
</details>

<details class="sl-details-nested">
<summary>📊 inca/ – INCA Nowcast (nächste 60 Minuten)</summary>
<div class="sl-details-body">
<table class="sl-mqtt-tbl"><thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td><code>inca/fx</code></td><td>km/h</td><td>Böenspitzen jetzt</td></tr>
<tr><td><code>inca/ff</code></td><td>km/h</td><td>Mittlere Windgeschwindigkeit jetzt</td></tr>
<tr><td><code>inca/fx_max_30min</code></td><td>km/h</td><td>Max. Böen in den nächsten 30 Minuten</td></tr>
<tr><td><code>inca/fx_max_60min</code></td><td>km/h</td><td>Max. Böen in der nächsten Stunde → bestimmt alarm/wind</td></tr>
<tr><td><code>inca/rr</code></td><td>mm/h</td><td>Aktuelle Niederschlagsintensität</td></tr>
<tr><td><code>inca/pt</code></td><td>1/2/3/4/5/255</td><td>Typ: 1=Regen, 2=Schnee, 3=Schneeregen, 4=Graupel, 5=Hagel, 255=kein</td></tr>
<tr><td><code>inca/bald_regen</code></td><td>0 / 1</td><td>Regen in &lt;30 Min – kein alarm-Trigger, nur Info</td></tr>
<tr><td><code>inca/bald_hagel</code></td><td>0 / 1</td><td>Hagel möglich in &lt;60 Min</td></tr>
<tr><td><code>inca/bald_sturm_30</code></td><td>0 / 1</td><td>Sturmböen in &lt;30 Min</td></tr>
<tr><td><code>inca/minuten_bis_regen</code></td><td>Min / -1</td><td>ETA nächster Regen (-1 = trocken)</td></tr>
</tbody></table>
</div>
</details>

<details class="sl-details-nested">
<summary>🌐 tawes/ – Wetterstation-Korrelation 360°</summary>
<div class="sl-details-body">
<table class="sl-mqtt-tbl"><thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td><code>tawes/dominante_windrichtung_name</code></td><td>N/NNO/…</td><td>Windrichtung als Himmelsrichtung</td></tr>
<tr><td><code>tawes/upstream_aktiv</code></td><td>Anzahl</td><td>Aktuelle Upstream-Stationen</td></tr>
<tr><td><code>tawes/sturm_upstream</code></td><td>0 / 1</td><td>Konsens-Sturm upstream</td></tr>
<tr><td><code>tawes/wind_kaskade</code></td><td>0 / 1</td><td>Herannahende Sturmfront erkannt</td></tr>
<tr><td><code>tawes/wind_kaskade_eta_min</code></td><td>Min / -1</td><td>ETA der Sturmfront</td></tr>
<tr><td><code>tawes/regen_upstream</code></td><td>0 / 1</td><td>Regen upstream (letzte 30 Min)</td></tr>
<tr><td><code>tawes/regen_upstream_mm</code></td><td>mm/h</td><td>Max. Regenintensität upstream</td></tr>
<tr><td><code>tawes/regen_lokal</code></td><td>0 / 1</td><td>Regen im Lokal-Umkreis</td></tr>
<tr><td><code>tawes/regen_lokal_station</code></td><td>Text</td><td>Station mit Lokal-Regen, z.B. "VÖCKLABRUCK (12km) 8.4mm/h"</td></tr>
<tr><td><code>tawes/druck_trend</code></td><td>hPa/10min</td><td>Drucktendenz (negativ = fallend)</td></tr>
<tr><td><code>tawes/gewitter_signal</code></td><td>0 / 1 / 2</td><td>0=kein · 1=Gewittergefahr · 2=akut</td></tr>
</tbody></table>
</div>
</details>

<details class="sl-details-nested">
<summary>🔔 notification/ – Fertige Push-Texte</summary>
<div class="sl-details-body">
<p>Alle Benachrichtigungstexte sind in <b>einfacher Alltagssprache</b> verfasst – verständlich ohne Meteorologie-Kenntnisse. Jede Meldung erklärt:</p>
<ul>
    <li><b>Was</b> wird gewarnt (Gewitter, Sturmböen, Regen, Hagel, Eis/Schnee)</li>
    <li><b>Warum / Woher</b> die Warnung kommt (z.B. „Amtliche Warnung GELB", „Radar + Wetterstationen bestätigt")</li>
    <li><b>Wie lange</b> die Warnung gilt (z.B. „Warnung gültig bis Fr 22:00")</li>
    <li><b>Wie zuverlässig</b> die Prognose ist (sehr zuverlässig / zuverlässig / wahrscheinlich)</li>
</ul>
<table class="sl-mqtt-tbl"><thead><tr><th>Topic</th><th>Bedeutung</th><th>Beispiel</th></tr></thead><tbody>
<tr><td><code>notification/alle</code></td><td><b>Empfehlung für Loxone Push</b> – vollständige Zusammenfassung</td><td><i>⚡ Gewitter wahrscheinlich (Warnstufe 2/3) | Blitz und Donner erwartet | Amtliche Warnung ORANGE (GeoSphere Austria) | Warnung gültig bis Fr 22:00 | Prognose-Zuverlässigkeit: sehr zuverlässig</i></td></tr>
<tr><td><code>notification/geosphere</code></td><td>Amtliche ZAMG-Warnungen – <b>immer aktiv</b></td><td><i>⚠️ ORANGE – Gewitter | heute 15:00–21:00</i></td></tr>
<tr><td><code>notification/inca</code></td><td>Nowcast: Was kommt in den nächsten 60 Minuten?</td><td><i>🌧️ Regen ist vor Ort: 8.5 mm/h – von Wetterstationen bestätigt | Intensität nimmt zu</i></td></tr>
<tr><td><code>notification/tawes</code></td><td>Was messen die Wetterstationen in der Umgebung gerade?</td><td><i>🌧️ Regen aus NW nähert sich – 22 mm/h gemessen, Ankunft in ca. 15 Minuten</i></td></tr>
<tr><td><code>notification/tageswarnung</code></td><td>Warnungen die heute noch kommen – ideal für 07:00 Morgenroutine</td><td><i>📅 heute 16:00: ⚠️ GELB Gewitter</i></td></tr>
<tr><td><code>notification/entwarnung</code></td><td>Einmalig wenn der Alarm endet</td><td><i>Entwarnung – kein Unwetter mehr aktiv</i></td></tr>
</tbody></table>
<p class="sl-hint"><b>Loxone Morgenroutine:</b> Zeitprogramm 07:00 → Gate auf <code>zamg/irgendwas_aktiv = 1</code> → Push mit <code>notification/tageswarnung</code>.</p>
<p class="sl-hint"><b>Echtzeit-Alarm:</b> <code>alarm/gesamt</code> wechselt auf ≥1 → Push mit <code>notification/alle</code>.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>⚙️ System-Topics</summary>
<div class="sl-details-body">
<table class="sl-mqtt-tbl"><thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead><tbody>
<tr><td><code>status</code></td><td>OK / Error</td><td>Systemstatus</td></tr>
<tr><td><code>status/api_ok</code></td><td>0 / 1</td><td>0 = mind. eine API meldet Fehler</td></tr>
<tr><td><code>status/mqtt_reconnects</code></td><td>Zahl</td><td>MQTT-Reconnects seit Daemon-Start</td></tr>
<tr><td><code>letzter_abruf_epoch</code></td><td>Unix-TS</td><td>Wenn &gt;10 Min nicht aktualisiert → Daemon prüfen</td></tr>
</tbody></table>
</div>
</details>

</div>
</details>

<!-- ================================================================
     LOXONE INTEGRATION
     ================================================================ -->
<details class="sl-details">
<summary>🔧 Integration in Loxone</summary>
<div class="sl-details-body">
<table class="sl-mqtt-tbl"><thead><tr><th>Anwendungsfall</th><th>Gate</th><th>Loxone-Logik</th></tr></thead><tbody>
<tr><td><b>Echtzeit-Unwetter-Push</b></td><td><code>alarm/gesamt</code></td><td>0→≥1: Push mit <code>notification/alle</code></td></tr>
<tr><td><b>Morgen-Zusammenfassung</b> (07:00)</td><td><code>zamg/irgendwas_aktiv</code></td><td>= 1: Push mit <code>notification/tageswarnung</code></td></tr>
<tr><td><b>Markise / Sturmschutz</b></td><td><code>alarm/wind</code></td><td>≥2: Markise einfahren</td></tr>
<tr><td><b>Hagelschutz</b></td><td><code>alarm/hagel</code></td><td>≥1: Aktion auslösen</td></tr>
<tr><td><b>Bewässerung</b></td><td><code>inca/bald_regen</code></td><td>= 1: Bewässerung pausieren</td></tr>
<tr><td><b>Entwarnung</b></td><td><code>alarm/entwarnung</code></td><td>= 1: Entwarnung-Push senden</td></tr>
</tbody></table>

<div class="sl-section-title" style="margin-top:1rem">MQTT Virtual Input einrichten</div>
<ol>
    <li>In Loxone Config: <b>Virtuell → MQTT Virtual Input</b> anlegen</li>
    <li>Broker-IP = IP des LoxBerry (MQTT Gateway)</li>
    <li>Topic = z.B. <code>unwetter/alarm/wind</code></li>
    <li>Typ = Analog für Zahlen, Text für Strings</li>
</ol>
</div>
</details>

<!-- ================================================================
     FAQ
     ================================================================ -->
<details class="sl-details">
<summary>❓ Häufige Fragen</summary>
<div class="sl-details-body">

<details class="sl-details-nested">
<summary>Warum zeigt das System hohe Stufen bevor der Regen ankommt?</summary>
<div class="sl-details-body">
<p>Das ist korrekt und gewollt. Bei bestätigten Ereignissen (INCA + TAWES oder ZAMG) berechnet das Plugin die Stufe aus dem Spitzenwert der nächsten 30 Minuten. Wenn INCA in 15 Minuten 35 mm/h vorhersagt und TAWES upstream bereits Regen misst, zeigt das System jetzt Stufe 3 – du bekommst Vorlaufzeit.</p>
<p class="sl-hint">INCA allein (ohne TAWES) gibt nie Vorhersage-Spitzenwerte weiter – immer nur aktueller Wert, max. Stufe 1.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Warum löst eine einzelne Station keinen Wind-Alarm aus?</summary>
<div class="sl-details-body">
<p>TAWES Wind-Alarm erfordert Konsens: ≥30% der Upstream-Tal-Stationen (absolut ≥2) müssen Böen ≥ BOEN_ALARM melden. Prüfe <code>alarm/wind_quelle</code>. <code>TAWES_KASKADE</code> = Stufe 1 als Vorwarnung ohne Konsens (beabsichtigt).</p>
</div>
</details>

<details class="sl-details-nested">
<summary>MQTT-Verbindung bricht regelmäßig ab?</summary>
<div class="sl-details-body">
<p>Zwei gleichzeitig laufende Daemon-Instanzen kicken sich gegenseitig. Der Daemon beendet alte Instanzen beim Start automatisch. Überwache <code>status/mqtt_reconnects</code> – sollte bei stabiler Verbindung nicht wachsen.</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Was bedeuten die Benachrichtigungstexte?</summary>
<div class="sl-details-body">
<p>Jede Benachrichtigung erklärt auf einen Blick vier Dinge – kein technischer Jargon, auch für Familienmitglieder verständlich:</p>
<ul>
    <li><b>Was</b> wird gewarnt – z.B. „Gewitter wahrscheinlich", „Sturm erwartet", „Regen im Anmarsch"</li>
    <li><b>Woher</b> die Warnung kommt:<br>
        – <i>„Amtliche Warnung GELB/ORANGE/ROT (GeoSphere Austria)"</i> = offizielle Behördenwarnung<br>
        – <i>„Radar + Wetterstationen bestätigt"</i> = zwei unabhängige Quellen stimmen überein<br>
        – <i>„Wetterradar-Prognose"</i> = Nowcast-Modell allein (noch nicht durch Stationen bestätigt)
    </li>
    <li><b>Wann</b> – z.B. „Warnung gültig bis Fr 22:00", „Ankunft in ca. 12 Minuten"</li>
    <li><b>Wie sicher</b> – z.B. „Prognose-Zuverlässigkeit: sehr zuverlässig" (≥80 Punkte), „zuverlässig" (≥60), „wahrscheinlich" (≥40)</li>
</ul>
<p>Beispiele:</p>
<p><code>⚡ Gewitter möglich (Warnstufe 1/3) | Blitz und Donner erwartet | Amtliche Warnung GELB (GeoSphere Austria) | Warnung gültig bis So 19:00 | Prognose-Zuverlässigkeit: sehr zuverlässig</code></p>
<p><code>💨 Sturm erwartet (Warnstufe 2/3) – Böen bis 92 km/h aus NW | Radar + Wetterstationen bestätigt | Prognose-Zuverlässigkeit: zuverlässig</code></p>
<p><code>🌧️ Regen im Anmarsch (Warnstufe 1/3) – 5.2 mm/h in der näheren Umgebung gemessen | Ankunft in ca. 15 Minuten | Wetterradar-Prognose</code></p>
</div>
</details>

<details class="sl-details-nested">
<summary>Was bedeutet alarm/konfidenz?</summary>
<div class="sl-details-body">
<ul>
    <li><b>0–39:</b> Schwaches Signal – nur eine Quelle</li>
    <li><b>40–69:</b> Bestätigt – zwei Quellen stimmen überein</li>
    <li><b>70+:</b> Hohe Sicherheit – alle Quellen + konsistenter Trend</li>
</ul>
</div>
</details>

<details class="sl-details-nested">
<summary>Wie oft werden die Daten aktualisiert?</summary>
<div class="sl-details-body">
<ul>
    <li><b>ZAMG:</b> Standard 300 s – Warnungen ändern sich selten</li>
    <li><b>INCA:</b> Standard 300 s – Nowcast aktualisiert alle 15 min</li>
    <li><b>TAWES:</b> Standard 480 s – Stationsdaten alle 10 min verfügbar</li>
</ul>
</div>
</details>

<details class="sl-details-nested">
<summary>Was ist die Wind-Kaskade?</summary>
<div class="sl-details-body">
<p>Erkennt wenn Upstream-Stationen zeitlich gestaffelt hohe Böen melden: zuerst die weiter entfernte, dann eine nähere. → Sturmfront nähert sich aus der Windrichtung. Löst <code>alarm/wind=1</code> als Vorwarnung, berechnet ETA (<code>tawes/wind_kaskade_eta_min</code>).</p>
</div>
</details>

<details class="sl-details-nested">
<summary>Wo finde ich ältere Logs?</summary>
<div class="sl-details-body">
<p>Der Log-Tab zeigt alle verfügbaren Sessions (max. 7) der letzten Daemon-Starts. Jeder Start erstellt eine eigene Log-Datei mit Zeitstempel im Namen.</p>
</div>
</details>

</div>
</details>

<div style="text-align:center;margin-top:1rem">
    <a href="https://github.com/HitsmartDev/Unwetter4Lox" target="_blank" class="sl-btn secondary sm">⭐ GitHub Repository</a>
    &nbsp;
    <a href="https://www.geosphere.at" target="_blank" class="sl-btn secondary sm">🌍 GeoSphere Austria</a>
</div>

<?php render_footer(); ?>
