<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";

$L = LBSystem::readlanguage("language.ini");

$navbar[1]['Name'] = $L['MAIN.STATUS'];    $navbar[1]['URL'] = "index.php";
$navbar[2]['Name'] = $L['MAIN.SETTINGS'];  $navbar[2]['URL'] = "settings.php";
$navbar[3]['Name'] = $L['MAIN.LOG'];       $navbar[3]['URL'] = "log.php";
$navbar[4]['Name'] = $L['MAIN.HELP'];      $navbar[4]['URL'] = "help.php"; $navbar[4]['active'] = true;

LBWeb::lbheader($L['MAIN.TITLE'] . " – " . $L['MAIN.HELP'], "https://github.com/HitsmartDev/Unwetter4Lox", "");
?>

<style>
.mqtt-table { width:100%; border-collapse:collapse; font-size:11px; margin:8px 0 }
.mqtt-table th { background:#e8e8e8; padding:6px 4px; text-align:left; font-weight:bold }
.mqtt-table td { padding:5px 4px; border-bottom:1px solid #f0f0f0; vertical-align:top }
.mqtt-table tr:hover td { background:#fafafa }
code { background:#f0f0f0; padding:1px 4px; border-radius:3px; font-size:10px; font-family:monospace }
.tag-ok { background:#4CAF50; color:white; padding:1px 5px; border-radius:3px; font-size:10px }
.tag-warn { background:#FF9800; color:white; padding:1px 5px; border-radius:3px; font-size:10px }
.tag-err { background:#f44336; color:white; padding:1px 5px; border-radius:3px; font-size:10px }
</style>

<!-- WAS MACHT DIESES PLUGIN -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>📖 Was ist Unwetter4Lox?</h3>
<p>Unwetter4Lox ist ein LoxBerry-Plugin, das österreichische Wetterwarnungen und Nowcast-Daten in Echtzeit via <b>MQTT</b> an deinen <b>Loxone Miniserver</b> liefert.</p>
<p>Es kombiniert drei Datenquellen zu einem einheitlichen Alarmsystem:</p>
<ul>
    <li><b>GeoSphere Austria (ZAMG)</b> – Offizielle Warnungen (Stufe 1–4)</li>
    <li><b>INCA Nowcast</b> – Hochauflösende 15min-Vorhersage der nächsten 60 min</li>
    <li><b>TAWES 360°</b> – Live-Messdaten von Wetterstationen im Umkreis → Regen-ETA, Wind-Trend, Gewitter-Vorhersage</li>
</ul>
<p>Alle Daten landen als MQTT-Nachrichten auf deinem LoxBerry-Broker und können direkt in Loxone-Logiken (Virtual Input) verwendet werden.</p>
</div>

<!-- DATENQUELLEN -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>🌍 Datenquellen & APIs</h3>

<div data-role="collapsible" data-collapsed="false">
<h4>1. GeoSphere Austria – Offizielle Warnungen</h4>
<p>API: <code>https://warnungen.zamg.at/wsapp/api/getWarningsForCoords</code></p>
<p>Liefert offizielle Unwetterwarnungen für genau deine Koordinaten. Kategorien: Wind, Regen, Schnee, Glatteis, Gewitter, Hagel, Hitze, Kälte.</p>
<p><b>Warnstufen:</b></p>
<table class="mqtt-table">
<tr><th>Stufe</th><th>Farbe</th><th>Bedeutung</th></tr>
<tr><td>0</td><td><span class="tag-ok">Grün</span></td><td>Keine Warnung</td></tr>
<tr><td>1</td><td><span style="background:#FFEB3B;color:#333;padding:1px 5px;border-radius:3px;font-size:10px">Gelb</span></td><td>Vorsicht – erhöhte Aufmerksamkeit</td></tr>
<tr><td>2</td><td><span class="tag-warn">Orange</span></td><td>Markant – Schäden möglich</td></tr>
<tr><td>3</td><td><span class="tag-err">Rot</span></td><td>Unwetter – erhebliche Schäden</td></tr>
<tr><td>4</td><td><span style="background:#9C27B0;color:white;padding:1px 5px;border-radius:3px;font-size:10px">Lila</span></td><td>Extrem – Lebensgefahr möglich</td></tr>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>2. INCA Nowcast</h4>
<p>API: <code>https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km</code></p>
<p>Hochauflösende (1 km²) Kurzfristvorhersage, alle 15 Minuten aktualisiert. Zeigt was in den nächsten 0–60 Minuten direkt an deinem Standort passiert. Ideal für: Markisen einfahren, Bewässerung stoppen, Fenster schließen.</p>
<p>Parameter: Windgeschwindigkeit (FF), Böen (FX), Niederschlag (RR), Niederschlagstyp (PT = Regen/Schnee/Hagel).</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>3. TAWES 360° – Wetterstation-Korrelation</h4>
<p>API: <code>https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min</code></p>
<p>Ruft alle 10 Minuten Live-Messdaten von Wetterstationen im konfigurierten Umkreis (Standard: 120 km) ab. Analysiert die Daten um Wetterfronten <em>bevor sie ankommen</em> zu erkennen:</p>
<ul>
    <li><b>Upstream-Erkennung:</b> Welche Stationen liegen in Windrichtung? (Vektormittelung, gewichtet nach Windstärke)</li>
    <li><b>Regen-ETA:</b> Wann kommt die Regenfront an? (Frontgeschwindigkeit aus mehreren Upstream-Stationen)</li>
    <li><b>Wind-Trend:</b> Nehmen Böen zu oder ab? (Lineare Regression, letzten 60 Minuten)</li>
    <li><b>Gewitter-Signal:</b> Druckabfall + hohe Luftfeuchte → Gewittersignal Level 1. Zusätzlich starker Böenanstieg → Level 2 (Akut).</li>
    <li><b>Konfidenz:</b> 0–100% gibt an, wie verlässlich die ETA-Berechnung ist.</li>
</ul>
</div>
</div>

<!-- MQTT TOPICS – VOLLSTÄNDIGE LISTE -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>📡 MQTT Topics – vollständige Referenz</h3>
<p>Standard-Präfix: <code>unwetter/</code> (in Einstellungen änderbar). Alle Topics werden mit <b>retain=true</b> gespeichert.</p>

<div data-role="collapsible" data-collapsed="false">
<h4>🚦 alarm/ – Aggregierter Gesamtstatus (Kombination aller Quellen)</h4>
<p>Diese Topics sind für Loxone-Logiken optimiert: ein Wert fasst alle Quellen zusammen.</p>
<p><b>Level-Prinzip (alle alarm/-Topics):</b> ZAMG-Stufen werden direkt gemappt – <b>Gelb→1, Orange→2, Rot/Lila→3</b>. Das <code>aktiv</code>-Flag ändert den Level nicht. INCA/TAWES können auf max. Level 2 heben (bei überschrittenen Schwellwerten).</p>
<p><b>Wann soll ich pushen?</b> Gate: <code>alarm/gesamt</code> wechselt von <code>0</code> → <code>≥ 1</code> = Push senden. Bei <code>0</code> = keine Meldung.</p>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>alarm/gesamt</code></td><td>0–3</td><td><b>max(gewitter, wind, regen, hagel, schnee)</b>. <b>0</b>=ruhig, <b>1</b>=Vorsicht (Gelb/Schwelle), <b>2</b>=Warnung (Orange/überschritten), <b>3</b>=Extrem (Rot/Lila). <b>Primärer Gate-Wert.</b></td></tr>
<tr><td><code>alarm/gewitter</code></td><td>0–3</td><td><b>1</b>=möglich (ZAMG Gelb od. TAWES Lvl 1), <b>2</b>=Warnung (ZAMG Orange od. TAWES Lvl 2 od. Akutwarnung), <b>3</b>=Extrem (ZAMG Rot/Lila)</td></tr>
<tr><td><code>alarm/wind</code></td><td>0–3</td><td><b>1</b>=Vorsicht (ZAMG Gelb od. INCA/TAWES ≥ BOEN_ALARM), <b>2</b>=Warnung (ZAMG Orange od. INCA 30min od. TAWES 2×BOEN), <b>3</b>=Extrem (ZAMG Rot/Lila)</td></tr>
<tr><td><code>alarm/regen</code></td><td>0–2</td><td><b>1</b>=erwartet (ZAMG Gelb od. TAWES upstream), <b>2</b>=Starkregen (ZAMG Orange od. INCA ≥ REGEN_ALARM od. TAWES ETA ≤30min). Nieselregen/bald_regen löst <b>keinen</b> Alarm aus.</td></tr>
<tr><td><code>alarm/hagel</code></td><td>0–2</td><td><b>1</b>=möglich (ZAMG Gelb od. INCA bald_hagel/graupel), <b>2</b>=Warnung (ZAMG Orange+)</td></tr>
<tr><td><code>alarm/schnee</code></td><td>0–2</td><td><b>1</b>=möglich (ZAMG Gelb od. INCA PT=Schnee/Schneeregen), <b>2</b>=Warnung (ZAMG Orange+). Inkl. Glatteis.</td></tr>
<tr><td><code>alarm/stufe</code></td><td>0–4</td><td>Höchste <b>offizielle ZAMG</b>-Warnstufe (nur ZAMG, kein INCA/TAWES)</td></tr>
<tr><td><code>alarm/zusammenfassung</code></td><td>Text</td><td>Fertiger Anzeigetext aus allen aktiven Kategorien. Ideal für Loxone-Statusfeld. Mögliche Werte: <br>
<code>✅ Keine Warnungen</code> (alles 0)<br>
<code>⚡ Gewitter möglich</code> / <code>⚡ Gewitter Warnung</code> / <code>⚡ Gewitter EXTREM</code><br>
<code>💨 Wind Vorsicht</code> / <code>💨 Sturm Warnung</code> / <code>💨 Extremsturm</code><br>
<code>🌧 Regen erwartet</code> / <code>🌧 Starkregen</code><br>
<code>🌨 Hagelgefahr</code> / <code>🌨 Hagel Warnung</code><br>
<code>❄️ Schnee/Eis möglich</code> / <code>❄️ Schnee/Eis Warnung</code><br>
Mehrere Kategorien: <code>⚡ Gewitter Warnung | 💨 Sturm Warnung</code></td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🔢 Wie werden alarm/ Topics berechnet? (Detaillogik)</h4>
<p>Jede Kategorie kombiniert ZAMG, INCA und TAWES. <b>Es gewinnt immer der höchste Wert</b> (max). ZAMG-Stufe entscheidet den Basis-Level, INCA/TAWES können auf max. Level 2 heben.</p>

<p><b>alarm/gewitter</b></p>
<table class="mqtt-table">
<thead><tr><th>Quelle</th><th>Bedingung</th><th>→ Level</th></tr></thead>
<tbody>
<tr><td>ZAMG</td><td>Gewitter Gelb (Stufe 1)</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td>Gewittersignal Lvl 1 (Druckabfall + hohe Feuchte)</td><td><b>1</b></td></tr>
<tr><td>ZAMG</td><td>Gewitter Orange (Stufe 2)</td><td><b>2</b></td></tr>
<tr><td>TAWES</td><td>Gewittersignal Lvl 2 (+ starke Böenzunahme)</td><td><b>2</b></td></tr>
<tr><td>System</td><td>Behördliche Akutwarnung (GWA)</td><td><b>≥ 2</b></td></tr>
<tr><td>ZAMG</td><td>Gewitter Rot/Lila (Stufe 3/4)</td><td><b>3</b></td></tr>
</tbody>
</table>

<p style="margin-top:8px"><b>alarm/wind</b></p>
<table class="mqtt-table">
<thead><tr><th>Quelle</th><th>Bedingung</th><th>→ Level</th></tr></thead>
<tbody>
<tr><td>ZAMG</td><td>Wind Gelb (Stufe 1)</td><td><b>1</b></td></tr>
<tr><td>INCA</td><td>Böen ≥ BOEN_ALARM in &lt;60 min</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td>Upstream-Böen ≥ BOEN_ALARM</td><td><b>1</b></td></tr>
<tr><td>ZAMG</td><td>Wind Orange (Stufe 2)</td><td><b>2</b></td></tr>
<tr><td>INCA</td><td>Böen ≥ BOEN_ALARM in &lt;30 min</td><td><b>2</b></td></tr>
<tr><td>TAWES</td><td>Upstream-Böen ≥ 2 × BOEN_ALARM</td><td><b>2</b></td></tr>
<tr><td>ZAMG</td><td>Wind Rot/Lila (Stufe 3/4)</td><td><b>3</b></td></tr>
</tbody>
</table>

<p style="margin-top:8px"><b>alarm/regen</b> – <code>inca/bald_regen</code> und Regenraten unter REGEN_ALARM lösen keinen Alarm aus. Für Bewässerungsabschaltung direkt <code>inca/bald_regen</code> in Loxone verwenden.</p>
<table class="mqtt-table">
<thead><tr><th>Quelle</th><th>Bedingung</th><th>→ Level</th></tr></thead>
<tbody>
<tr><td>ZAMG</td><td>Regen Gelb (Stufe 1)</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td>Regenfront upstream, ETA &gt;30 min</td><td><b>1</b></td></tr>
<tr><td>ZAMG</td><td>Regen Orange/höher (Stufe 2+)</td><td><b>2</b></td></tr>
<tr><td>INCA</td><td>Regenrate ≥ REGEN_ALARM (= <code>inca/regen_alarm</code>)</td><td><b>2</b></td></tr>
<tr><td>TAWES</td><td>Regenfront upstream, ETA ≤30 min</td><td><b>2</b></td></tr>
</tbody>
</table>

<p style="margin-top:8px"><b>alarm/hagel &amp; alarm/schnee</b></p>
<table class="mqtt-table">
<thead><tr><th>Kategorie</th><th>Level 1 (Vorsicht)</th><th>Level 2 (Warnung)</th></tr></thead>
<tbody>
<tr><td><code>alarm/hagel</code></td><td>ZAMG Gelb od. INCA bald_hagel/graupel</td><td>ZAMG Orange+</td></tr>
<tr><td><code>alarm/schnee</code></td><td>ZAMG Gelb od. INCA PT=Schnee/Schneeregen</td><td>ZAMG Orange+</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🌩️ zamg/ – Offizielle GeoSphere Warnungen</h4>
<p>Typen: <code>wind</code> · <code>regen</code> · <code>schnee</code> · <code>glatteis</code> · <code>gewitter</code> · <code>hagel</code> · <code>hitze</code> · <code>kaelte</code></p>
<p><b>Morgen-Push Empfehlung:</b> Zeitprogramm 07:00 → prüfe <code>zamg/irgendwas_aktiv</code>. Wenn <code>= 1</code> → Push mit Text aus <code>notification/geosphere</code>. Bei <code>= 0</code> keine Meldung senden.</p>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>zamg/max_stufe</code></td><td>0–4</td><td>Höchste aktive Warnstufe über alle Typen</td></tr>
<tr><td><code>zamg/irgendwas_aktiv</code></td><td>0 / 1</td><td><b>Gate für Morgen-Push:</b> 1 wenn mindestens eine Warnung aktiv oder bald erwartet</td></tr>
<tr><td><code>zamg/akutwarnung</code></td><td>0 / 1</td><td>1 bei stationsbasierter Akutwarnung (GWA-ID)</td></tr>
<tr><td><code>zamg/letzter_abruf</code></td><td>Datum/Uhrzeit</td><td>Zeitstempel des letzten erfolgreichen ZAMG-Abrufs</td></tr>
<tr><td><code>zamg/{typ}/stufe</code></td><td>0–4</td><td>Warnstufe für diesen Typ (0=keine, 1=Gelb, 2=Orange, 3=Rot, 4=Lila)</td></tr>
<tr><td><code>zamg/{typ}/aktiv</code></td><td>0 / 1</td><td>1 = Warnung läuft gerade</td></tr>
<tr><td><code>zamg/{typ}/bald</code></td><td>0 / 1</td><td>1 = Warnung beginnt in &lt;30 Minuten</td></tr>
<tr><td><code>zamg/{typ}/start_epoch</code></td><td>Unix-TS</td><td>Startzeit als Unix-Timestamp (0 = keine Warnung)</td></tr>
<tr><td><code>zamg/{typ}/end_epoch</code></td><td>Unix-TS</td><td>Endzeit als Unix-Timestamp (0 = keine Warnung)</td></tr>
<tr><td><code>zamg/{typ}/notification</code></td><td>Text</td><td>Fertiger Push-Text, z.B. "ORANGE – Wind | heute 14:00 – morgen 06:00 | Sturmböen erwartet"</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>📊 inca/ – INCA Nowcast (nächste 60 Minuten)</h4>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>inca/fx</code></td><td>km/h</td><td>Böenspitzen jetzt (Spitzenwindgeschwindigkeit)</td></tr>
<tr><td><code>inca/ff</code></td><td>km/h</td><td>Mittlere Windgeschwindigkeit jetzt</td></tr>
<tr><td><code>inca/fx_max_30min</code></td><td>km/h</td><td>Max. Böen in den nächsten 30 Minuten</td></tr>
<tr><td><code>inca/fx_max_60min</code></td><td>km/h</td><td>Max. Böen in der nächsten Stunde</td></tr>
<tr><td><code>inca/rr</code></td><td>mm/h</td><td>Aktuelle Niederschlagsintensität</td></tr>
<tr><td><code>inca/regen_alarm</code></td><td>0 / 1</td><td>1 = aktuelle Regenrate ≥ konfigurierter REGEN_ALARM-Schwelle (Standard: 10 mm/h)</td></tr>
<tr><td><code>inca/pt</code></td><td>1/2/3/4/5/255</td><td>Niederschlagstyp jetzt (Code): 1=Regen, 2=Schnee, 3=Schneeregen, 4=Graupel, 5=Hagel, 255=kein</td></tr>
<tr><td><code>inca/pt_name</code></td><td>Text</td><td>Niederschlagstyp jetzt als Text (z.B. "Regen", "kein Niederschlag")</td></tr>
<tr><td><code>inca/pt_bald</code></td><td>1/2/3/4/5/255</td><td>Niederschlagstyp des nächsten Regens (Code). Gleiche Werte wie <code>inca/pt</code>. 255 = kein Regen in Sicht.</td></tr>
<tr><td><code>inca/pt_bald_name</code></td><td>Text / leer</td><td>Niederschlagstyp des nächsten Regens als Text. Leer wenn kein Regen erwartet wird.</td></tr>
<tr><td><code>inca/bald_regen</code></td><td>0 / 1</td><td>1 = Regen kommt in &lt;30 Minuten</td></tr>
<tr><td><code>inca/bald_hagel</code></td><td>0 / 1</td><td>1 = Hagel möglich in &lt;60 Minuten</td></tr>
<tr><td><code>inca/bald_graupel</code></td><td>0 / 1</td><td>1 = Graupel möglich in &lt;60 Minuten</td></tr>
<tr><td><code>inca/bald_sturm_30</code></td><td>0 / 1</td><td>1 = Sturmböen (&gt;Alarm-Schwelle) in &lt;30 Minuten</td></tr>
<tr><td><code>inca/bald_sturm_60</code></td><td>0 / 1</td><td>1 = Sturmböen in &lt;60 Minuten</td></tr>
<tr><td><code>inca/minuten_bis_regen</code></td><td>Minuten / -1</td><td>Geschätzte Zeit bis zum nächsten Regen. -1 = kein Regen in Sicht.</td></tr>
<tr><td><code>inca/letzter_abruf</code></td><td>Datum/Uhrzeit</td><td>Zeitstempel des letzten erfolgreichen INCA-Abrufs</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🌐 tawes/ – Wetterstation-Korrelation 360°</h4>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>tawes/dominante_windrichtung</code></td><td>0–360°</td><td>Woher der Wind kommt (Grad, vektorbasiert gewichtet)</td></tr>
<tr><td><code>tawes/dominante_windrichtung_name</code></td><td>N/NNO/NO/…</td><td>Windrichtung als Himmelsrichtung</td></tr>
<tr><td><code>tawes/upstream_aktiv</code></td><td>Anzahl</td><td>Wieviele Stationen gerade Upstream sind (Wind kommt von dort)</td></tr>
<tr><td><code>tawes/wind_upstream_kmh</code></td><td>km/h</td><td>Max. Böen an Upstream-Stationen – kommt bald zu dir</td></tr>
<tr><td><code>tawes/wind_trend</code></td><td>-1 / 0 / 1</td><td>-1=abnehmend, 0=stabil, 1=zunehmend (letzte 60 min)</td></tr>
<tr><td><code>tawes/sturm_upstream</code></td><td>0 / 1</td><td>1 = Sturmböen an Upstream-Stationen (über Böen-Schwelle)</td></tr>
<tr><td><code>tawes/regen_upstream</code></td><td>0 / 1</td><td>1 = Regen wird an mind. einer Upstream-Station gemessen</td></tr>
<tr><td><code>tawes/regen_eta_min</code></td><td>Minuten / -1</td><td>Geschätzte Zeit bis Regenfront ankommt. -1 = unbekannt (zu wenig Daten).</td></tr>
<tr><td><code>tawes/regen_konfidenz</code></td><td>0–100</td><td>Konfidenz der ETA-Berechnung in Prozent</td></tr>
<tr><td><code>tawes/front_speed_kmh</code></td><td>km/h</td><td>Berechnete Geschwindigkeit der Regenfront</td></tr>
<tr><td><code>tawes/druck_trend</code></td><td>hPa/10min</td><td>Luftdrucktendenz der nächstgelegenen Upstream-Station. Negativ = fallend.</td></tr>
<tr><td><code>tawes/gewitter_signal</code></td><td>0 / 1 / 2</td><td>0=kein, 1=Gewittergefahr (Druckabfall + hohe Feuchte), 2=akut (zusätzl. starke Böen)</td></tr>
<tr><td><code>tawes/naechste_station</code></td><td>Text</td><td>Name, Distanz und Richtung der nächsten Upstream-Station</td></tr>
<tr><td><code>tawes/stationen_anzahl</code></td><td>Anzahl</td><td>Gesamtzahl erreichter TAWES-Stationen im konfigurierten Radius</td></tr>
<tr><td><code>tawes/letztes_update</code></td><td>Datum/Uhrzeit</td><td>Zeitstempel des letzten erfolgreichen TAWES-Abrufs</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🔔 notification/ – Fertige Push-Texte</h4>
<p>Werden <b>nur bei Änderung</b> publiziert (kein Spam). Die <b>Einzeltopics</b> haben immer Inhalt – auch wenn keine Warnung aktiv ist (Fallback-Text). <code>notification/alle</code> enthält nur aktive Meldungen und eignet sich am besten für Loxone Push.</p>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Bedeutung</th><th>Beispielwerte</th></tr></thead>
<tbody>
<tr><td><code>notification/geosphere</code></td><td>ZAMG-Warnungen ab konfigurierter Mindeststufe. <b>Immer befüllt.</b></td><td>
<code>⚠️ ORANGE – Wind | heute 14:00 – morgen 06:00</code><br>
<code>keine aktiven Warnungen</code> (kein Alarm)<br>
<code>✅ Entwarnung – alle Wetterwarnungen aufgehoben.</code> (nach Ende)</td></tr>
<tr><td><code>notification/inca</code></td><td>INCA Nowcast-Lage. <b>Immer befüllt.</b></td><td>
<code>✅ kein Alarm | Böen: 12.6 km/h</code> (normal)<br>
<code>🟠 Sturmböen &lt;30 min: max 75 km/h</code><br>
<code>🌧️ Regen in ~8 min</code></td></tr>
<tr><td><code>notification/tawes</code></td><td>TAWES-Lagebericht. <b>Immer befüllt.</b></td><td>
<code>keine aktiven Warnungen</code> (ruhig)<br>
<code>💨 Sturmböen upstream 85 km/h</code><br>
<code>🌧️ Regenfront ~18min | 62km/h aus W | 78% Konfidenz</code><br>
<code>⚡ Gewittergefahr | Druck 1.2 hPa/10min + 89% Feuchte</code><br>
<code>🔴 AKUTE GEWITTERGEFAHR | Druck … + Böenzunahme</code></td></tr>
<tr><td><code>notification/alle</code></td><td>Alle <b>aktiven</b> Meldungen kombiniert (durch ── getrennt). Kein Fallback-Text. – <b>Empfehlung für Loxone Push</b></td><td>
<code>✅ kein Alarm | Böen: 12.6 km/h</code> (nur INCA, wenn sonst ruhig)<br>
<code>⚠️ ORANGE – Wind | ... ── 🟠 Sturmböen &lt;30 min ── 💨 Sturmböen upstream</code></td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>⚙️ System-Topics</h4>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>status</code></td><td>OK / Error - …</td><td>Systemstatus. "OK" = alles läuft. "Error - INCA API" = INCA nicht erreichbar.</td></tr>
<tr><td><code>letzter_abruf_datum</code></td><td>DD.MM.YYYY HH:MM:SS</td><td>Zeitpunkt der letzten erfolgreichen Datenaktualisierung</td></tr>
<tr><td><code>letzter_abruf_epoch</code></td><td>Unix-Timestamp</td><td>Gleicher Zeitpunkt als Unix-Timestamp (für Loxone-Zeitvergleiche)</td></tr>
</tbody>
</table>
</div>
</div>

<!-- LOXONE INTEGRATION -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>🔧 Integration in Loxone</h3>

<div data-role="collapsible" data-collapsed="false">
<h4>Wann soll eine Notification gesendet werden?</h4>
<p>Verwende numerische Topics als Gate – pushe <b>nur wenn der Wert &gt; 0 ist</b>. So vermeidest du leere Meldungen oder Spam wenn keine Warnung anliegt.</p>
<table class="mqtt-table">
<thead><tr><th>Anwendungsfall</th><th>Gate</th><th>Loxone-Logik</th></tr></thead>
<tbody>
<tr><td><b>Echtzeit-Unwetter-Push</b></td><td><code>alarm/gesamt</code></td><td>Wert wechselt <code>0 → ≥ 1</code>: Push mit <code>notification/alle</code> senden</td></tr>
<tr><td><b>Morgen-Zusammenfassung ZAMG</b> (z.B. 07:00 Uhr)</td><td><code>zamg/irgendwas_aktiv</code></td><td>Wenn <code>= 1</code> → Push mit <code>notification/geosphere</code>. Bei <code>= 0</code> nichts senden.</td></tr>
<tr><td><b>Sofort-Alarm bei Warnung Stufe ≥ Orange</b></td><td><code>alarm/stufe</code></td><td>Wert wechselt auf <code>≥ 2</code>: sofortiger Push</td></tr>
<tr><td><b>Entwarnung</b></td><td><code>alarm/gesamt</code></td><td>Wert fällt von <code>≥ 1 → 0</code>: Push mit <code>notification/geosphere</code> (enthält "✅ Entwarnung")</td></tr>
<tr><td><b>Markise / Sturmschutz</b></td><td><code>alarm/wind</code></td><td>Wert wechselt auf <code>≥ 2</code>: Markise einfahren</td></tr>
<tr><td><b>Hagelschutz</b></td><td><code>alarm/hagel</code> oder <code>inca/bald_hagel</code></td><td>Wert wird <code>≥ 1</code>: Aktion auslösen</td></tr>
</tbody>
</table>
<p><b>Stufen-Bedeutung für alarm/* Topics:</b></p>
<ul style="font-size:12px">
    <li><code>0</code> = <span class="tag-ok">Ruhig</span> – kein Push senden</li>
    <li><code>1</code> = <span style="background:#FFEB3B;color:#333;padding:1px 5px;border-radius:3px;font-size:10px">Vorsicht</span> – optionaler Info-Push</li>
    <li><code>2</code> = <span class="tag-warn">Warnung</span> – Push empfohlen</li>
    <li><code>3</code> = <span class="tag-err">AKUT</span> – Push zwingend</li>
</ul>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Empfohlene Loxone-Bausteine</h4>
<ul data-role="listview">
<li><b>Virtual Input (MQTT)</b> – für numerische Werte wie <code>alarm/gewitter</code>, <code>inca/minuten_bis_regen</code></li>
<li><b>Virtual Text Input (MQTT)</b> – für Texte wie <code>notification/alle</code>, <code>alarm/zusammenfassung</code></li>
<li><b>Analog-Schwellenschalter</b> – auf <code>alarm/wind</code> ≥ 2 reagieren → Markise einfahren</li>
<li><b>Push-Benachrichtigung</b> – auf <code>notification/alle</code> bei Änderung senden</li>
</ul>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Typische Automatisierungsbeispiele</h4>
<ul>
    <li>📽 <b>Markise / Sonnensegel:</b> Wenn <code>alarm/wind</code> ≥ 2 ODER <code>inca/bald_sturm_30</code> = 1 → Markise einfahren</li>
    <li>💧 <b>Bewässerung:</b> Wenn <code>alarm/regen</code> ≥ 1 ODER <code>inca/minuten_bis_regen</code> &lt; 30 → Bewässerung pausieren</li>
    <li>🪟 <b>Dachfenster:</b> Wenn <code>alarm/regen</code> ≥ 1 → Fenster schließen</li>
    <li>🔔 <b>Push-Alarm:</b> Wenn <code>alarm/gewitter</code> = 2 → Sofort-Push auf Handy</li>
    <li>⚡ <b>Smart Home Sicherheit:</b> Wenn <code>zamg/max_stufe</code> ≥ 3 → Pool-Pumpe aus, alle Dachluken zu</li>
</ul>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>MQTT Virtual Input einrichten</h4>
<ol>
    <li>In Loxone Config: <b>Virtuell → MQTT Virtual Input</b> anlegen</li>
    <li>Broker-IP = IP des LoxBerry (MQTT Gateway)</li>
    <li>Topic = z.B. <code>unwetter/alarm/wind</code></li>
    <li>Typ = Analog für Zahlen, Text für Strings</li>
    <li>Der Wert aktualisiert sich automatisch wenn der Daemon neue Daten holt</li>
</ol>
</div>
</div>

<!-- FAQ -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>❓ Häufige Fragen</h3>

<div data-role="collapsible" data-collapsed="true">
<h4>Wie oft werden die Daten aktualisiert?</h4>
<p>Konfigurierbar (Standard: 300 Sekunden = 5 Minuten). TAWES-Stationsdaten werden immer nur alle 10 Minuten abgerufen (GeoSphere API-Limit), unabhängig vom Intervall.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was ist der Unterschied zwischen ZAMG-Warnung und alarm/?</h4>
<p>ZAMG-Warnungen sind offizielle, manuell ausgegebene Warnungen (oft Stunden im Voraus). Die <code>alarm/</code>-Topics kombinieren ZAMG + INCA Nowcast + TAWES und geben so ein aktuelleres Bild. Zum Beispiel kann <code>alarm/regen</code>=1 sein, obwohl keine ZAMG-Warnung aktiv ist, weil INCA Regen in 15 Minuten vorhersagt.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Warum ist regen_eta_min = -1?</h4>
<p>-1 bedeutet, die ETA konnte nicht berechnet werden. Das passiert wenn: (a) es keine Regen-Stationen upstream gibt, (b) nur eine Station Regen meldet (für Frontgeschwindigkeit braucht man mindestens 2), oder (c) der Buffer noch nicht genug 10-Minuten-Messungen hat (dauert ~20 Min nach Start).</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Warum zeigt TAWES keine Stationen?</h4>
<p>Beim ersten Start lädt der Daemon die Stationsliste von der GeoSphere API. Das kann 1–2 Minuten dauern. Danach wird die Liste täglich gecacht. Falls der Cache beschädigt ist: In den Einstellungen → TAWES → "Stations-Cache neu laden".</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was bedeutet "Upstream-Station"?</h4>
<p>Eine Station gilt als Upstream, wenn die dominante Windrichtung (+/- 70°) von ihr zu deinem Standort zeigt. Das bedeutet: Das Wetter, das diese Station gerade misst, kommt in der Regel auch zu dir. Diese Stationen sind für die Vorhersage besonders relevant.</p>
</div>
</div>

<div style="margin-top:20px; text-align:center; padding-bottom:10px">
    <a href="https://github.com/HitsmartDev/Unwetter4Lox" target="_blank" data-role="button" data-icon="star" data-mini="true" data-inline="true">GitHub Repository</a>
    &nbsp;
    <a href="https://www.geosphere.at" target="_blank" data-role="button" data-icon="info" data-mini="true" data-inline="true">GeoSphere Austria</a>
</div>

<?php LBWeb::lbfooter(); ?>
