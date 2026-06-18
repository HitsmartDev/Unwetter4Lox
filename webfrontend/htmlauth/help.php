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
.tag-new { background:#2196F3; color:white; padding:1px 5px; border-radius:3px; font-size:9px; margin-left:3px }
</style>

<!-- WAS MACHT DIESES PLUGIN -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>📖 Was ist Unwetter4Lox?</h3>
<p>Unwetter4Lox ist ein LoxBerry-Plugin, das österreichische Wetterwarnungen und Nowcast-Daten in Echtzeit via <b>MQTT</b> an deinen <b>Loxone Miniserver</b> liefert.</p>
<p>Es kombiniert drei Datenquellen zu einem einheitlichen Alarmsystem:</p>
<ul>
    <li><b>GeoSphere Austria (ZAMG)</b> – Offizielle Warnungen (Stufe 1–4)</li>
    <li><b>INCA Nowcast</b> – Hochauflösende 15min-Vorhersage der nächsten 60 min</li>
    <li><b>TAWES 360°</b> – Live-Messdaten von Wetterstationen im Umkreis → Regen-ETA, Wind-Kaskade, Lokal-Regen, Gewitter-Vorhersage</li>
</ul>
<p>Alle Daten landen als MQTT-Nachrichten auf deinem LoxBerry-Broker und können direkt in Loxone-Logiken (Virtual Input) verwendet werden.</p>
<p style="font-size:11px;color:#888">Aktuelle Version: <b>0.9.8</b> | <a href="https://github.com/HitsmartDev/Unwetter4Lox" target="_blank">GitHub</a></p>
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
<p>Parameter: Windgeschwindigkeit (FF), Böen (FX), Niederschlag (RR in mm/h), Niederschlagstyp (PT = Regen/Schnee/Hagel).</p>
<p><b>Wichtig:</b> INCA-Werte benötigen keinen "Konsens" – das Nowcast-Modell hat eine eigene Qualitätskontrolle. Wenn INCA <code>fx_max_60min ≥ BOEN_ALARM</code> meldet, löst das direkt <code>alarm/wind</code> aus.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>3. TAWES 360° – Wetterstation-Korrelation</h4>
<p>API: <code>https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min</code></p>
<p>Ruft alle 10 Minuten Live-Messdaten von Wetterstationen im konfigurierten Umkreis (Standard: 120 km) ab. Analysiert die Daten um Wetterfronten <em>bevor sie ankommen</em> zu erkennen:</p>
<ul>
    <li><b>Upstream-Erkennung:</b> Welche Stationen liegen in Windrichtung? (Vektormittelung, gewichtet nach Windstärke, ±70°)</li>
    <li><b>Regen-ETA:</b> Wann kommt die Regenfront an? (Frontgeschwindigkeit aus mehreren Upstream-Stationen, max. 30-min Buffer)</li>
    <li><b>Lokal-Regen:</b> Regnet es jetzt in der Nähe (konfigurierbar, Standard: 25 km)? Unabhängig von der Windrichtung.</li>
    <li><b>Wind-Kaskade:</b> Melden Upstream-Stationen zeitlich gestaffelt hohe Böen (weit → nah)? Zeigt ein herannahendes Sturmsystem. Nur Böen aus den letzten 60 Min berücksichtigt.</li>
    <li><b>Wind-Konsens:</b> Mind. 30% der Upstream-Tal-Stationen (min. 2) müssen Böen ≥ BOEN_ALARM melden, bevor <code>alarm/wind</code> gesetzt wird. Verhindert False-Alarms durch einzelne Ausreißer-Stationen.</li>
    <li><b>Wind-Trend:</b> Nehmen Böen zu oder ab? (Lineare Regression, letzten 60 Minuten)</li>
    <li><b>Gewitter-Signal:</b> Druckabfall + hohe Luftfeuchte → Gewittersignal Level 1. Zusätzlich starker Böenanstieg → Level 2 (Akut).</li>
    <li><b>Alpine Stationen ausschließen:</b> Stationen über konfigurierbarer Seehöhe (Standard: 1200 m) fließen nicht in den Wind-Alarm-Konsens ein (z.B. Feuerkogel 1618 m).</li>
    <li><b>Konfidenz:</b> 0–100% gibt an, wie verlässlich die ETA-Berechnung ist.</li>
</ul>
<p><b>TAWES RR-Einheit:</b> Die API liefert Regen in <b>mm/10min</b>. In den Status-Topics und der Stationsanzeige wird auf <b>mm/h</b> umgerechnet (×6). Der konfigurierte REGEN_ALARM gilt in mm/h.</p>
</div>
</div>

<!-- MQTT TOPICS – VOLLSTÄNDIGE LISTE -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>📡 MQTT Topics – vollständige Referenz</h3>
<p>Standard-Präfix: <code>unwetter/</code> (in Einstellungen änderbar). Alle Topics werden mit <b>retain=true</b> gespeichert.</p>

<div data-role="collapsible" data-collapsed="false">
<h4>🚦 alarm/ – Aggregierter Gesamtstatus (Kombination aller Quellen)</h4>
<p>Diese Topics sind für Loxone-Logiken optimiert: ein Wert fasst alle Quellen zusammen.</p>
<p><b>Level-Prinzip (alle alarm/-Topics):</b> ZAMG-Stufen werden direkt gemappt – <b>Gelb→1, Orange→2, Rot/Lila→3</b>. Das <code>aktiv</code>-Flag ändert den Level nicht. INCA/TAWES verwenden Schwellwert-Vielfache: <b>1× Schwelle→1, 2× Schwelle→2, 3× Schwelle→3</b> – alle drei Quellen können Level 3 erreichen.</p>
<p><b>Wann soll ich pushen?</b> Gate: <code>alarm/gesamt</code> wechselt von <code>0</code> → <code>≥ 1</code> = Push senden. Bei <code>0</code> = keine Meldung.</p>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>alarm/gesamt</code></td><td>0–3</td><td><b>max(gewitter, wind, regen, hagel, schnee)</b>. <b>0</b>=ruhig, <b>1</b>=Vorsicht (Gelb/Schwelle), <b>2</b>=Warnung (Orange/überschritten), <b>3</b>=Extrem (Rot/Lila). <b>Primärer Gate-Wert.</b></td></tr>
<tr><td><code>alarm/gewitter</code></td><td>0–3</td><td><b>1</b>=möglich (ZAMG Gelb od. TAWES Lvl 1), <b>2</b>=Warnung (ZAMG Orange od. TAWES Lvl 2 od. Akutwarnung), <b>3</b>=Extrem (ZAMG Rot/Lila)</td></tr>
<tr><td><code>alarm/wind</code></td><td>0–3</td><td><b>1</b>=Vorsicht (ZAMG Gelb od. INCA/TAWES ≥ 1×BOEN_ALARM od. Wind-Kaskade), <b>2</b>=Warnung (ZAMG Orange od. ≥ 2×BOEN_ALARM), <b>3</b>=Extrem (ZAMG Rot/Lila od. ≥ 3×BOEN_ALARM). TAWES braucht Konsens-Bestätigung (s.u.).</td></tr>
<tr><td><code>alarm/wind_quelle</code> <span class="tag-new">NEU</span></td><td>Text</td><td>Welche Quelle hat <code>alarm/wind</code> ausgelöst: <code>ZAMG</code>, <code>INCA (52km/h)</code>, <code>TAWES_STURM (65km/h)</code>, <code>TAWES_KASKADE</code>, <code>–</code> (kein Alarm). Für schnelle Diagnose ohne Log-Analyse.</td></tr>
<tr><td><code>alarm/regen</code></td><td>0–3</td><td><b>1</b>=erwartet (ZAMG Gelb od. INCA/TAWES ≥ 1×REGEN_ALARM), <b>2</b>=Starkregen (≥ 2×REGEN_ALARM), <b>3</b>=Extrem (≥ 3×REGEN_ALARM). Nieselregen/bald_regen löst <b>keinen</b> Alarm aus. TAWES lokal nur innerhalb des konfigurierten Lokal-Umkreises (Standard: 25 km).</td></tr>
<tr><td><code>alarm/regen_quelle</code> <span class="tag-new">NEU</span></td><td>Text</td><td>Welche Quelle hat <code>alarm/regen</code> ausgelöst: <code>ZAMG</code>, <code>INCA (3.5mm/h)</code>, <code>TAWES_UPSTREAM (12mm/h)</code>, <code>TAWES_LOKAL (GALLSPACH 28km 31.8mm/h)</code>, <code>–</code> (kein Alarm).</td></tr>
<tr><td><code>alarm/hagel</code></td><td>0–2</td><td><b>1</b>=möglich (ZAMG Gelb od. INCA bald_hagel/graupel), <b>2</b>=Warnung (ZAMG Orange+)</td></tr>
<tr><td><code>alarm/schnee</code></td><td>0–2</td><td><b>1</b>=möglich (ZAMG Gelb od. INCA PT=Schnee/Schneeregen), <b>2</b>=Warnung (ZAMG Orange+). Inkl. Glatteis.</td></tr>
<tr><td><code>alarm/stufe</code></td><td>0–4</td><td>Höchste <b>offizielle ZAMG</b>-Warnstufe (nur ZAMG, kein INCA/TAWES)</td></tr>
<tr><td><code>alarm/zusammenfassung</code></td><td>Text</td><td>Fertiger Anzeigetext aus allen aktiven Kategorien. Ideal für Loxone-Statusfeld.</td></tr>
<tr><td><code>alarm/entwarnung</code></td><td>0 / 1</td><td>Wechselt einmalig auf <code>1</code> wenn <code>alarm/gesamt</code> von ≥1 auf 0 fällt. Danach sofort wieder 0. Gate für Entwarnung-Push.</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🔢 Wie werden alarm/ Topics berechnet? (Detaillogik)</h4>

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

<p style="margin-top:8px"><b>alarm/wind</b> – INCA nutzt <code>fx_max_60min</code> direkt (kein Konsens nötig – Modell-Daten). TAWES braucht Konsens-Bestätigung (<code>sturm_upstream=1</code>) ODER erkennt eine Kaskade (<code>wind_kaskade=1</code>).</p>
<table class="mqtt-table">
<thead><tr><th>Quelle</th><th>Bedingung</th><th>→ Level</th></tr></thead>
<tbody>
<tr><td>ZAMG</td><td>Wind Gelb (Stufe 1)</td><td><b>1</b></td></tr>
<tr><td>INCA</td><td><code>fx_max_60min</code> ≥ 1 × BOEN_ALARM</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td>Konsens-Sturm upstream ≥ 1 × BOEN_ALARM (<code>sturm_upstream=1</code>)</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td>Wind-Kaskade erkannt (<code>wind_kaskade=1</code>) – Vorwarnung ohne Konsens</td><td><b>1</b></td></tr>
<tr><td>ZAMG</td><td>Wind Orange (Stufe 2)</td><td><b>2</b></td></tr>
<tr><td>INCA</td><td><code>fx_max_60min</code> ≥ 2 × BOEN_ALARM</td><td><b>2</b></td></tr>
<tr><td>TAWES</td><td>Konsens-Sturm upstream ≥ 2 × BOEN_ALARM</td><td><b>2</b></td></tr>
<tr><td>ZAMG</td><td>Wind Rot/Lila (Stufe 3/4)</td><td><b>3</b></td></tr>
<tr><td>INCA</td><td><code>fx_max_60min</code> ≥ 3 × BOEN_ALARM</td><td><b>3</b></td></tr>
<tr><td>TAWES</td><td>Konsens-Sturm upstream ≥ 3 × BOEN_ALARM</td><td><b>3</b></td></tr>
</tbody>
</table>
<p style="font-size:11px;color:#888"><b>TAWES Wind-Konsens:</b> Mind. max(2, 30%) der Upstream-Tal-Stationen (ohne alpine Stationen > konfigurierter Höhe) müssen Böen ≥ BOEN_ALARM melden. Eine einzelne Station löst nie Alarm aus. Alpine Stationen (über konfigurierter Seehöhe) erscheinen im Log aber fließen nicht in den Konsens ein.</p>

<p style="margin-top:8px"><b>alarm/regen</b> – <code>inca/bald_regen</code> und Regenraten unter REGEN_ALARM lösen keinen Alarm aus. TAWES Lokal-Regen nur innerhalb des konfigurierten Lokal-Umkreises (Standard: 25 km).</p>
<table class="mqtt-table">
<thead><tr><th>Quelle</th><th>Bedingung</th><th>→ Level</th></tr></thead>
<tbody>
<tr><td>ZAMG</td><td>Regen Gelb (Stufe 1)</td><td><b>1</b></td></tr>
<tr><td>INCA</td><td><code>rr_jetzt</code> ≥ 1 × REGEN_ALARM</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td><code>regen_upstream_mm</code> ≥ 1 × REGEN_ALARM (Konsens: ≥30% Upstream-Stationen)</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td><code>regen_lokal_mm</code> ≥ 1 × REGEN_ALARM (Station ≤ Lokal-Umkreis)</td><td><b>1</b></td></tr>
<tr><td>ZAMG</td><td>Regen Orange (Stufe 2)</td><td><b>2</b></td></tr>
<tr><td>INCA</td><td><code>rr_jetzt</code> ≥ 2 × REGEN_ALARM</td><td><b>2</b></td></tr>
<tr><td>TAWES</td><td><code>regen_upstream_mm</code> / <code>regen_lokal_mm</code> ≥ 2 × REGEN_ALARM</td><td><b>2</b></td></tr>
<tr><td>ZAMG</td><td>Regen Rot/Lila (Stufe 3/4)</td><td><b>3</b></td></tr>
<tr><td>INCA</td><td><code>rr_jetzt</code> ≥ 3 × REGEN_ALARM</td><td><b>3</b></td></tr>
<tr><td>TAWES</td><td><code>regen_upstream_mm</code> / <code>regen_lokal_mm</code> ≥ 3 × REGEN_ALARM</td><td><b>3</b></td></tr>
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
<tr><td><code>inca/fx_max_60min</code></td><td>km/h</td><td>Max. Böen in der nächsten Stunde – entscheidet über <code>alarm/wind</code> (wenn ≥ BOEN_ALARM)</td></tr>
<tr><td><code>inca/rr</code></td><td>mm/h</td><td>Aktuelle Niederschlagsintensität</td></tr>
<tr><td><code>inca/regen_alarm</code></td><td>0 / 1</td><td>1 = aktuelle Regenrate ≥ konfigurierter REGEN_ALARM-Schwelle (Standard: 10 mm/h)</td></tr>
<tr><td><code>inca/pt</code></td><td>1/2/3/4/5/255</td><td>Niederschlagstyp jetzt (Code): 1=Regen, 2=Schnee, 3=Schneeregen, 4=Graupel, 5=Hagel, 255=kein</td></tr>
<tr><td><code>inca/pt_name</code></td><td>Text</td><td>Niederschlagstyp jetzt als Text (z.B. "Regen", "kein Niederschlag")</td></tr>
<tr><td><code>inca/pt_bald</code></td><td>1/2/3/4/5/255</td><td>Niederschlagstyp des nächsten Regens (Code). 255 = kein Regen in Sicht.</td></tr>
<tr><td><code>inca/pt_bald_name</code></td><td>Text / leer</td><td>Niederschlagstyp des nächsten Regens als Text.</td></tr>
<tr><td><code>inca/bald_regen</code></td><td>0 / 1</td><td>1 = Regen kommt in &lt;30 Minuten. <b>Kein alarm/regen-Trigger</b> – nur Info. Für Bewässerungssteuerung geeignet.</td></tr>
<tr><td><code>inca/bald_hagel</code></td><td>0 / 1</td><td>1 = Hagel möglich in &lt;60 Minuten</td></tr>
<tr><td><code>inca/bald_graupel</code></td><td>0 / 1</td><td>1 = Graupel möglich in &lt;60 Minuten</td></tr>
<tr><td><code>inca/bald_sturm_30</code></td><td>0 / 1</td><td>1 = Sturmböen (&gt;Alarm-Schwelle) in &lt;30 Minuten. <b>Info-Topic</b> – alarm/wind verwendet fx_max_60min direkt.</td></tr>
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
<tr><td><code>tawes/wind_upstream_kmh</code></td><td>km/h</td><td>Max. Böen an Upstream-Tal-Stationen – Rohwert, ohne Konsens-Bestätigung. Nur bei <code>sturm_upstream=1</code> alarmrelevant.</td></tr>
<tr><td><code>tawes/wind_trend</code></td><td>-1 / 0 / 1</td><td>-1=abnehmend, 0=stabil, 1=zunehmend (letzte 60 min)</td></tr>
<tr><td><code>tawes/sturm_upstream</code></td><td>0 / 1</td><td>1 = Sturmböen an mind. 30% der Upstream-Tal-Stationen (Konsens bestätigt). Löst <code>alarm/wind</code> aus.</td></tr>
<tr><td><code>tawes/wind_kaskade</code> <span class="tag-new">NEU</span></td><td>0 / 1</td><td>1 = Upstream-Stationen melden zeitlich gestaffelt hohe Böen (weiter weg zuerst, dann näher). Zeigt herannahendes Sturmsystem. Löst <code>alarm/wind=1</code> (Vorwarnung) auch ohne Konsens. Nur Böen der letzten 60 Min berücksichtigt.</td></tr>
<tr><td><code>tawes/wind_kaskade_eta_min</code> <span class="tag-new">NEU</span></td><td>Minuten / -1</td><td>ETA der Sturmfront laut Wind-Kaskade. -1 = unbekannt.</td></tr>
<tr><td><code>tawes/wind_kaskade_speed_kmh</code> <span class="tag-new">NEU</span></td><td>km/h</td><td>Berechnete Geschwindigkeit der Sturmfront (aus Wind-Kaskade).</td></tr>
<tr><td><code>tawes/alpine_upstream</code> <span class="tag-new">NEU</span></td><td>Anzahl</td><td>Anzahl alpiner Upstream-Stationen die über der konfigurierten Höhe liegen und aus dem Wind-Konsens ausgeschlossen wurden.</td></tr>
<tr><td><code>tawes/regen_upstream</code></td><td>0 / 1</td><td>1 = Regen an mind. einer Upstream-Station in den letzten 30 Minuten gemessen (ab ~0,6 mm/h). Kein Konsens nötig, aber nur aktueller Buffer (30 min).</td></tr>
<tr><td><code>tawes/regen_upstream_mm</code></td><td>mm/h</td><td>Max. Regenintensität an Upstream-Stationen (letzten 30 Min). 0 = kein Regen oder Konsens nicht erreicht. Nur bei Konsens (≥30% Stationen) gesetzt.</td></tr>
<tr><td><code>tawes/regen_eta_min</code></td><td>Minuten / -1</td><td>Geschätzte Zeit bis Regenfront ankommt. -1 = unbekannt (zu wenig Daten).</td></tr>
<tr><td><code>tawes/regen_konfidenz</code></td><td>0–100</td><td>Konfidenz der ETA-Berechnung in Prozent</td></tr>
<tr><td><code>tawes/front_speed_kmh</code></td><td>km/h</td><td>Berechnete Geschwindigkeit der Regenfront</td></tr>
<tr><td><code>tawes/regen_lokal</code> <span class="tag-new">NEU</span></td><td>0 / 1</td><td>1 = Regen jetzt an mind. einer Station innerhalb des Lokal-Umkreises (konfigurierbar, Standard: 25 km). Unabhängig von der Windrichtung. Erkennt "es regnet gerade hier" auch wenn die Front nicht upstream kommt.</td></tr>
<tr><td><code>tawes/regen_lokal_mm</code> <span class="tag-new">NEU</span></td><td>mm/h</td><td>Max. Regenintensität im Lokal-Umkreis. 0 = kein Regen innerhalb des Radius oder Intensität unter 0,6 mm/h.</td></tr>
<tr><td><code>tawes/regen_lokal_station</code> <span class="tag-new">NEU</span></td><td>Text</td><td>Name, Distanz und mm/h der Station die <code>regen_lokal_mm</code> bestimmt. Leer wenn kein Lokal-Regen. Beispiel: <code>VOECKLABRUCK (12km) 8.4mm/h</code></td></tr>
<tr><td><code>tawes/druck_trend</code></td><td>hPa/10min</td><td>Luftdrucktendenz der nächstgelegenen Upstream-Station. Negativ = fallend.</td></tr>
<tr><td><code>tawes/gewitter_signal</code></td><td>0 / 1 / 2</td><td>0=kein, 1=Gewittergefahr (Druckabfall + hohe Feuchte), 2=akut (zusätzl. starke Böen)</td></tr>
<tr><td><code>tawes/naechste_station</code></td><td>Text</td><td>Name, Distanz und Richtung der nächsten Upstream-Station</td></tr>
<tr><td><code>tawes/stationen_anzahl</code></td><td>Anzahl</td><td>Gesamtzahl erreichter TAWES-Stationen im konfigurierten Radius</td></tr>
<tr><td><code>tawes/api_ok</code> <span class="tag-new">NEU</span></td><td>0 / 1</td><td>1 = letzter TAWES-API-Abruf erfolgreich; 0 = API-Fehler (alte Daten bleiben erhalten)</td></tr>
<tr><td><code>tawes/letztes_update</code></td><td>Datum/Uhrzeit</td><td>Zeitstempel des letzten erfolgreichen TAWES-Abrufs</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🔔 notification/ – Fertige Push-Texte</h4>
<p>Werden <b>nur bei Änderung</b> publiziert (kein Spam). <b>INCA, TAWES und Alle werden nur publiziert wenn <code>alarm/gesamt ≥ 1</code></b> – kein Push bei normalem Wetter ohne Alarm. Bei Entwarnung (Alarm fällt auf 0) wird einmalig der Entwarnung-Text gesendet, danach sind die Topics leer. <code>notification/geosphere</code> ist immer aktiv (offizielle ZAMG-Warnungen).</p>
<p><b>Hinweis notification/alle:</b> Enthält nur Quellen die aktiv zu einem Alarm beitragen. Wenn INCA keinen eigenen Alarm hat (aber TAWES schon), erscheint der INCA-Status <i>nicht</i> in <code>notification/alle</code>.</p>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Bedeutung</th><th>Beispielwerte</th></tr></thead>
<tbody>
<tr><td><code>notification/geosphere</code></td><td>ZAMG-Warnungen ab konfigurierter Mindeststufe. <b>Immer befüllt.</b></td><td>
<code>⚠️ ORANGE – Wind | heute 14:00 – morgen 06:00</code><br>
<code>keine aktiven Warnungen</code> (kein Alarm)<br>
<code>✅ Entwarnung – alle Wetterwarnungen aufgehoben.</code> (nach Ende)</td></tr>
<tr><td><code>notification/inca</code></td><td>INCA Nowcast-Lage. <b>Nur bei alarm/gesamt ≥ 1.</b> Leer wenn kein Alarm.</td><td>
<code>🟠 Sturmböen &lt;30 min: max 75 km/h</code><br>
<code>🌧️ Regen in ~8 min</code><br>
<code></code> (leer wenn kein aktiver Alarm)</td></tr>
<tr><td><code>notification/tawes</code></td><td>TAWES-Lagebericht. <b>Nur bei alarm/gesamt ≥ 1.</b> Leer wenn kein Alarm.</td><td>
<code>💨 Sturmböen upstream 85 km/h</code><br>
<code>🌧️ Regenfront ~18min | 62km/h aus W | 78% Konfidenz</code><br>
<code>💨 Sturmfront naht aus NW | ETA ~12min | Gmunden+Vöcklabruck</code><br>
<code></code> (leer wenn kein aktiver Alarm)</td></tr>
<tr><td><code>notification/alle</code></td><td>Alle aktiven Alarm-Meldungen kombiniert (durch ── getrennt). <b>Nur bei alarm/gesamt ≥ 1</b> oder Entwarnung. Enthält <b>keine</b> "kein Alarm"-Texte aus Quellen die selbst keinen Alarm haben. – <b>Empfehlung für Loxone Push</b></td><td>
<code>⚠️ ORANGE – Wind | ... ── 🟠 Sturmböen &lt;30 min ── 💨 Sturmfront naht</code><br>
<code>🌧️ Regenfront ~10min | 110km/h aus W | 90% Konfidenz</code><br>
<code>✅ Entwarnung – alle Wetterwarnungen aufgehoben.</code> (einmalig)<br>
<code></code> (leer wenn kein aktiver Alarm)</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>⚙️ System-Topics</h4>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>status</code></td><td>OK / Error - …</td><td>Systemstatus. "OK" = alles läuft. "Error - INCA API" = INCA nicht erreichbar.</td></tr>
<tr><td><code>status/zamg_ok</code> <span class="tag-new">NEU</span></td><td>0 / 1</td><td>1 = letzter ZAMG-Abruf erfolgreich</td></tr>
<tr><td><code>status/inca_ok</code> <span class="tag-new">NEU</span></td><td>0 / 1</td><td>1 = letzter INCA-Abruf erfolgreich</td></tr>
<tr><td><code>status/tawes_ok</code> <span class="tag-new">NEU</span></td><td>0 / 1</td><td>1 = letzter TAWES-Abruf erfolgreich</td></tr>
<tr><td><code>letzter_abruf_datum</code></td><td>DD.MM.YYYY HH:MM:SS</td><td>Zeitpunkt der letzten erfolgreichen Datenaktualisierung</td></tr>
<tr><td><code>letzter_abruf_epoch</code></td><td>Unix-Timestamp</td><td>Gleicher Zeitpunkt als Unix-Timestamp (für Loxone-Zeitvergleiche)</td></tr>
</tbody>
</table>
</div>
</div>

<!-- EINSTELLUNGEN ERKLÄRT -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>⚙️ Einstellungen – Erklärungen</h3>

<div data-role="collapsible" data-collapsed="false">
<h4>Alarmschwellen</h4>
<table class="mqtt-table">
<thead><tr><th>Parameter</th><th>Standard</th><th>Erklärung</th></tr></thead>
<tbody>
<tr><td><b>Böen-Alarm (BOEN_ALARM)</b></td><td>60 km/h</td><td>Ab welcher Böenstärke ein Wind-Alarm ausgelöst wird. 60 km/h = Beaufort 8. Gilt für INCA <code>fx_max_60min</code> UND TAWES Upstream. Level 1 = 1×, Level 2 = 2×, Level 3 = 3× dieses Werts.</td></tr>
<tr><td><b>Regen-Alarm (REGEN_ALARM)</b></td><td>10 mm/h</td><td>Ab welcher Regenrate ein Regen-Alarm ausgelöst wird. <b>Achtung: Wert in mm/h!</b> TAWES-Stationen zeigen in der Tabelle mm/10min – diese Werte ×6 ergeben mm/h. 2 mm/h = leichter Regen, 10 mm/h = starker Regen, 30 mm/h = Starkregen.</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>TAWES 360° – Parameter</h4>
<table class="mqtt-table">
<thead><tr><th>Parameter</th><th>Standard</th><th>Erklärung</th></tr></thead>
<tbody>
<tr><td><b>Max. Radius</b></td><td>120 km</td><td>Stationen außerhalb dieses Radius werden nicht abgefragt.</td></tr>
<tr><td><b>Max. Stationen</b></td><td>25</td><td>Anzahl der nächstgelegenen Stationen pro API-Abruf. Mehr = besserer Konsens, mehr Netzlast.</td></tr>
<tr><td><b>Konsens-Schwelle</b></td><td>30 %</td><td>Mindestanteil der Upstream-Tal-Stationen die den Schwellwert überschreiten müssen. Min. 2 Stationen absolut. Verhindert False-Alarms durch einzelne Ausreißer.</td></tr>
<tr><td><b>Max. Seehöhe Upstream</b></td><td>1200 m</td><td>Upstream-Stationen über dieser Höhe werden aus dem Wind-Alarm-Konsens ausgeschlossen. Bergstationen messen natürlich stärkere Winde als das Tal. 0 = alle Höhen einbeziehen.</td></tr>
<tr><td><b>Lokal-Regen Umkreis</b></td><td>25 km</td><td>Umkreis für <code>tawes/regen_lokal</code>. Stationen in diesem Radius die aktuell Regen melden, aktivieren den lokalen Regen-Check (unabhängig von Windrichtung). Stationen außerhalb sind in der Anzeige sichtbar, lösen aber keinen <code>alarm/regen</code> aus.</td></tr>
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
<tr><td><b>Entwarnung</b></td><td><code>alarm/entwarnung</code></td><td>Wechselt einmalig auf <code>1</code> wenn Alarm endet → Push mit <code>notification/alle</code> (enthält "✅ Entwarnung"). Danach wieder <code>0</code>.</td></tr>
<tr><td><b>Markise / Sturmschutz</b></td><td><code>alarm/wind</code></td><td>Wert wechselt auf <code>≥ 2</code>: Markise einfahren</td></tr>
<tr><td><b>Hagelschutz</b></td><td><code>alarm/hagel</code> oder <code>inca/bald_hagel</code></td><td>Wert wird <code>≥ 1</code>: Aktion auslösen</td></tr>
<tr><td><b>Alarm-Diagnose</b></td><td><code>alarm/wind_quelle</code> / <code>alarm/regen_quelle</code></td><td>Text zeigt exakt welche Quelle den Alarm ausgelöst hat – kein Log-Lesen nötig</td></tr>
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
<li><b>Virtual Text Input (MQTT)</b> – für Texte wie <code>notification/alle</code>, <code>alarm/zusammenfassung</code>, <code>alarm/wind_quelle</code></li>
<li><b>Analog-Schwellenschalter</b> – auf <code>alarm/wind</code> ≥ 2 reagieren → Markise einfahren</li>
<li><b>Push-Benachrichtigung</b> – auf <code>notification/alle</code> bei Änderung senden</li>
</ul>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Typische Automatisierungsbeispiele</h4>
<ul>
    <li>📽 <b>Markise / Sonnensegel:</b> Wenn <code>alarm/wind</code> ≥ 2 ODER <code>inca/bald_sturm_30</code> = 1 → Markise einfahren</li>
    <li>💧 <b>Bewässerung:</b> Wenn <code>alarm/regen</code> ≥ 1 ODER <code>inca/bald_regen</code> = 1 ODER <code>tawes/regen_lokal</code> = 1 → Bewässerung pausieren</li>
    <li>🪟 <b>Dachfenster:</b> Wenn <code>alarm/regen</code> ≥ 1 ODER <code>tawes/regen_lokal</code> = 1 → Fenster schließen</li>
    <li>🔔 <b>Push-Alarm:</b> Wenn <code>alarm/gewitter</code> = 2 → Sofort-Push auf Handy</li>
    <li>⚡ <b>Smart Home Sicherheit:</b> Wenn <code>zamg/max_stufe</code> ≥ 3 → Pool-Pumpe aus, alle Dachluken zu</li>
    <li>📊 <b>Diagnose:</b> <code>alarm/wind_quelle</code> als Text-Anzeige → sofort sehen ob Alarm von INCA/TAWES/ZAMG kommt</li>
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
<h4>Warum löst eine einzelne Wetterstation einen Wind-Alarm aus?</h4>
<p>Sollte seit v0.4.26 nicht mehr passieren. TAWES Wind-Alarm erfordert jetzt Konsens: mind. 30% aller Upstream-Tal-Stationen (und absolut mind. 2 Stationen) müssen Böen ≥ BOEN_ALARM melden. Eine Einzelstation allein kann keinen <code>alarm/wind</code> auslösen.</p>
<p>Falls trotzdem Alarm: Prüfe <code>alarm/wind_quelle</code>. Wenn dort <code>INCA (52km/h)</code> steht, kommt der Alarm vom INCA Nowcast-Modell – das braucht keinen Konsens, da es bereits ein qualitätskontrolliertes Modell ist. In dem Fall: BOEN_ALARM erhöhen wenn es zu sensibel ist.</p>
<p>Alternativ: <code>TAWES_KASKADE</code> bedeutet eine Sturmfront nähert sich gestaffelt aus der Windrichtung (Vorwarnung Level 1 ohne Konsens).</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Regen-Alarm obwohl ich keinen Regen sehe – was tun?</h4>
<p>Prüfe <code>alarm/regen_quelle</code> im MQTT. Das zeigt exakt welche Quelle und Station den Alarm ausgelöst hat.</p>
<ul>
    <li><b>TAWES_LOKAL (GALLSPACH 28km 31.8mm/h):</b> Eine Station im Lokal-Umkreis hat starken Regen gemeldet, der Regen ist aber evtl. nicht bei dir angekommen. Lokal-Umkreis in den Einstellungen verkleinern (Standard: 25 km) oder REGEN_ALARM erhöhen.</li>
    <li><b>INCA (3.5mm/h):</b> Das Nowcast-Modell sagt Regen voraus. Schwelle REGEN_ALARM erhöhen wenn zu sensibel.</li>
    <li><b>TAWES_UPSTREAM (12mm/h):</b> Regen kommt laut Upstream-Stationen auf dich zu – war evtl. noch nicht da zum Zeitpunkt der Meldung.</li>
</ul>
<p><b>Einheit beachten:</b> Die Stationsanzeige zeigt Regen in <b>mm/10min</b>. Der REGEN_ALARM ist in <b>mm/h</b>. Umrechnung: mm/10min × 6 = mm/h. Beispiel: 5.3 mm/10min = 31.8 mm/h.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Wie oft werden die Daten aktualisiert?</h4>
<p>Konfigurierbar (Standard: 300 Sekunden = 5 Minuten). TAWES-Stationsdaten werden immer nur alle 10 Minuten abgerufen (GeoSphere API-Limit), unabhängig vom Intervall.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was ist der Unterschied zwischen ZAMG-Warnung und alarm/?</h4>
<p>ZAMG-Warnungen sind offizielle, manuell ausgegebene Warnungen (oft Stunden im Voraus). Die <code>alarm/</code>-Topics kombinieren ZAMG + INCA Nowcast + TAWES und geben so ein aktuelleres Bild. Zum Beispiel kann <code>alarm/regen</code>=1 sein, obwohl keine ZAMG-Warnung aktiv ist, weil TAWES lokalen Regen erkennt.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Warum ist regen_eta_min = -1?</h4>
<p>-1 bedeutet, die ETA konnte nicht berechnet werden. Das passiert wenn: (a) es keine Regen-Stationen upstream in den letzten 30 Minuten gibt, (b) nur eine Station Regen meldet (für Frontgeschwindigkeit braucht man mindestens 2), oder (c) der Buffer noch nicht genug 10-Minuten-Messungen hat (dauert ~20 Min nach Start).</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Warum zeigt TAWES keine oder wenige Stationen?</h4>
<p>Der Daemon löscht den Stations-Cache <b>bei jedem Start automatisch</b> und lädt die Liste frisch von der API. Das dauert ~30 Sekunden. Danach füllt sich der Daten-Buffer (Regen, Wind, Böen) in den ersten 10–20 Minuten auf.</p>
<p>Stationen können aus zwei Gründen leer erscheinen:</p>
<ul>
    <li><b>Fehlende Messwerte (–):</b> Die Station antwortet, hat aber keinen Wind- oder Regensensor (TAWES-Klimastationen ohne Anemometer – normal im TAWES-Netz).</li>
    <li><b>Keine Stationszeile:</b> Die API hat aktuell keine Messung für diese Station geliefert (kurze Sendepause, technische Störung). Tritt nach kurzer Zeit von selbst weg.</li>
</ul>
<p>Bei dauerhaften Problemen: In den Einstellungen → TAWES → "Stations-Cache neu laden". Das löscht den Cache <b>und</b> startet den Daemon neu.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was bedeutet "Upstream-Station"?</h4>
<p>Eine Station gilt als Upstream, wenn die dominante Windrichtung (+/- 70°) von ihr zu deinem Standort zeigt. Das bedeutet: Das Wetter, das diese Station gerade misst, kommt in der Regel auch zu dir. Diese Stationen sind für die Vorhersage besonders relevant.</p>
<p>Alpine Stationen (über der konfigurierten Max-Seehöhe, Standard: 1200 m) sind in der Anzeige markiert, aber fließen nicht in den Wind-Alarm-Konsens ein – Bergstationen messen natürlich stärkere Winde als das Tal.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was ist die Wind-Kaskade?</h4>
<p>Die Wind-Kaskade erkennt, wenn Upstream-Stationen zeitlich gestaffelt hohe Böen melden: zuerst die weiter entfernte Station, dann eine näher gelegene. Das ist ein Indikator, dass eine Sturmfront gerade aus der Windrichtung auf dich zukommt.</p>
<p>Die Kaskade löst <code>alarm/wind=1</code> (Vorsicht/Vorwarnung) aus, auch wenn der reguläre Konsens noch nicht erreicht ist. Dazu berechnet sie eine ETA (<code>tawes/wind_kaskade_eta_min</code>) und Frontgeschwindigkeit. Nur Böen der letzten 60 Minuten werden berücksichtigt – ältere Buffer-Daten fließen nicht ein.</p>
</div>
</div>

<div style="margin-top:20px; text-align:center; padding-bottom:10px">
    <a href="https://github.com/HitsmartDev/Unwetter4Lox" target="_blank" data-role="button" data-icon="star" data-mini="true" data-inline="true">GitHub Repository</a>
    &nbsp;
    <a href="https://www.geosphere.at" target="_blank" data-role="button" data-icon="info" data-mini="true" data-inline="true">GeoSphere Austria</a>
</div>

<?php LBWeb::lbfooter(); ?>
