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
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>alarm/gewitter</code></td><td>0 / 1 / 2</td><td>0=keiner, 1=möglich (ZAMG oder TAWES-Signal), 2=akut (ZAMG aktiv, Druckabfall+Feuchte+Böen)</td></tr>
<tr><td><code>alarm/wind</code></td><td>0–3</td><td>0=ruhig, 1=erhöhte Böen (INCA oder TAWES), 2=Sturm (ZAMG Stufe 2+ oder INCA &gt;60 km/h), 3=Extremsturm</td></tr>
<tr><td><code>alarm/regen</code></td><td>0–2</td><td>0=trocken, 1=Regen erwartet/upstream, 2=stark oder ETA &lt;30 min</td></tr>
<tr><td><code>alarm/hagel</code></td><td>0–2</td><td>0=kein, 1=möglich (ZAMG oder INCA), 2=Warnung aktiv</td></tr>
<tr><td><code>alarm/schnee</code></td><td>0–2</td><td>0=kein, 1=möglich, 2=Warnung aktiv. Inkl. Glatteis.</td></tr>
<tr><td><code>alarm/stufe</code></td><td>0–4</td><td>Höchste offizielle ZAMG-Warnstufe über alle Kategorien</td></tr>
<tr><td><code>alarm/zusammenfassung</code></td><td>Text</td><td>Lesbarer Kurztext, z.B. "⚡ Gewitter möglich | 🌧 Regen erwartet"</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🌩️ zamg/ – Offizielle GeoSphere Warnungen</h4>
<p>Typen: <code>wind</code> · <code>regen</code> · <code>schnee</code> · <code>glatteis</code> · <code>gewitter</code> · <code>hagel</code> · <code>hitze</code> · <code>kaelte</code></p>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>zamg/max_stufe</code></td><td>0–4</td><td>Höchste aktive Warnstufe über alle Typen</td></tr>
<tr><td><code>zamg/irgendwas_aktiv</code></td><td>0 / 1</td><td>1 wenn mindestens eine Warnung aktiv oder bald</td></tr>
<tr><td><code>zamg/akutwarnung</code></td><td>0 / 1</td><td>1 bei stationsbasierter Akutwarnung (GWA-ID)</td></tr>
<tr><td><code>zamg/{typ}/stufe</code></td><td>0–4</td><td>Warnstufe für diesen Typ</td></tr>
<tr><td><code>zamg/{typ}/aktiv</code></td><td>0 / 1</td><td>1 = Warnung läuft gerade</td></tr>
<tr><td><code>zamg/{typ}/bald</code></td><td>0 / 1</td><td>1 = Warnung beginnt in &lt;30 Minuten</td></tr>
<tr><td><code>zamg/{typ}/start_epoch</code></td><td>Unix-TS</td><td>Startzeit als Unix-Timestamp</td></tr>
<tr><td><code>zamg/{typ}/end_epoch</code></td><td>Unix-TS</td><td>Endzeit als Unix-Timestamp</td></tr>
<tr><td><code>zamg/{typ}/start_text</code></td><td>Text</td><td>Startzeit als lesbarer String (z.B. "heute 14:00")</td></tr>
<tr><td><code>zamg/{typ}/end_text</code></td><td>Text</td><td>Endzeit als lesbarer String</td></tr>
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
<tr><td><code>inca/pt</code></td><td>1/2/3/4/5/255</td><td>Niederschlagstyp-Code: 1=Regen, 2=Schnee, 3=Schneeregen, 4=Graupel, 5=Hagel, 255=kein</td></tr>
<tr><td><code>inca/pt_name</code></td><td>Text</td><td>Niederschlagstyp als Text (Sprache je nach Einstellung)</td></tr>
<tr><td><code>inca/bald_regen</code></td><td>0 / 1</td><td>1 = Regen kommt in &lt;30 Minuten</td></tr>
<tr><td><code>inca/bald_hagel</code></td><td>0 / 1</td><td>1 = Hagel möglich in &lt;60 Minuten</td></tr>
<tr><td><code>inca/bald_graupel</code></td><td>0 / 1</td><td>1 = Graupel möglich in &lt;60 Minuten</td></tr>
<tr><td><code>inca/bald_sturm_30</code></td><td>0 / 1</td><td>1 = Sturmböen (&gt;Alarm-Schwelle) in &lt;30 Minuten</td></tr>
<tr><td><code>inca/bald_sturm_60</code></td><td>0 / 1</td><td>1 = Sturmböen in &lt;60 Minuten</td></tr>
<tr><td><code>inca/minuten_bis_regen</code></td><td>Minuten / -1</td><td>Geschätzte Zeit bis zum nächsten Regen. -1 = kein Regen in Sicht.</td></tr>
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
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🔔 notification/ – Fertige Push-Texte</h4>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>notification/geosphere</code></td><td>Alle aktiven ZAMG-Warnungen als Klartext (ab Mindeststufe)</td></tr>
<tr><td><code>notification/inca</code></td><td>INCA-Zusammenfassung als Klartext</td></tr>
<tr><td><code>notification/tawes</code></td><td>TAWES-Zusammenfassung (z.B. "🌧 Regenfront ~18min | 62km/h aus W")</td></tr>
<tr><td><code>notification/alle</code></td><td>Kombination aller aktiven Warnungen – ideal für Loxone Push-Nachrichten</td></tr>
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
