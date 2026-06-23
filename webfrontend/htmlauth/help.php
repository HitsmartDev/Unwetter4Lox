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
    <li><b>TAWES 360°</b> – Live-Messdaten von Wetterstationen im Umkreis → Regen-ETA, Wind-Kaskade, Lokal-Regen, Gewitter-Vorhersage</li>
</ul>
<p>Alle Daten landen als MQTT-Nachrichten auf deinem LoxBerry-Broker und können direkt in Loxone-Logiken (Virtual Input) verwendet werden.</p>
<p style="font-size:11px;color:#888"><a href="https://github.com/HitsmartDev/Unwetter4Lox" target="_blank">GitHub</a></p>
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
<p style="font-size:11px;color:#666">Das Plugin zeigt auch ZAMG-Warnungen die in den nächsten 8 Stunden beginnen als Tageswarnung (<code>notification/tageswarnung</code>). Ideal für Morgenroutinen: Wenn heute noch Unwetter kommt, erfährst du es um 07:00 Uhr.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>2. INCA Nowcast</h4>
<p>API: <code>https://dataset.api.hub.geosphere.at/v1/timeseries/forecast/nowcast-v1-15min-1km</code></p>
<p>Hochauflösende (1 km²) Kurzfristvorhersage, alle 15 Minuten aktualisiert. Zeigt was in den nächsten 0–60 Minuten direkt an deinem Standort passiert. Ideal für: Markisen einfahren, Bewässerung stoppen, Fenster schließen.</p>
<p>Parameter: Windgeschwindigkeit (FF), Böen (FX), Niederschlag (RR in mm/h), Niederschlagstyp (PT = Regen/Schnee/Hagel).</p>
<p><b>Korrelationslogik:</b> INCA allein → max. Alarm-Stufe 1 (Prognose unbestätigt). INCA + TAWES-Messung bestätigt → volle Stufen 1–3. INCA-Signal seit ≥4 Zyklen (~20 min) → bis Stufe 2 erlaubt ohne TAWES. ZAMG-Warnung → direktes Vertrauen, Stufe 1–3 ohne Bestätigung.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>3. TAWES 360° – Wetterstation-Korrelation</h4>
<p>API: <code>https://dataset.api.hub.geosphere.at/v1/station/current/tawes-v1-10min</code></p>
<p>Ruft Live-Messdaten von Wetterstationen im konfigurierten Umkreis (Standard: 120 km) ab. Analysiert die Daten um Wetterfronten <em>bevor sie ankommen</em> zu erkennen:</p>
<ul>
    <li><b>Upstream-Erkennung:</b> Welche Stationen liegen in Windrichtung? (Vektormittelung, gewichtet nach Windstärke, ±45° Standard, konfigurierbar 20°–90°)</li>
    <li><b>Regen-ETA (Physik):</b> Wenn eine Upstream-Station Regen meldet und die Windgeschwindigkeit bekannt ist: Ankunftszeit = Entfernung ÷ Windgeschwindigkeit. Vollständig unabhängig vom INCA-Modell.</li>
    <li><b>Lokal-Regen:</b> Regnet es jetzt in der Nähe (konfigurierbar, Standard: 25 km)? Unabhängig von der Windrichtung.</li>
    <li><b>Wind-Kaskade:</b> Melden Upstream-Stationen zeitlich gestaffelt hohe Böen (weit → nah)? Zeigt ein herannahendes Sturmsystem. Nur Böen aus den letzten 60 Min berücksichtigt.</li>
    <li><b>Wind-Konsens:</b> Mind. 30% der Upstream-Tal-Stationen (min. 2) müssen Böen ≥ BOEN_ALARM melden, bevor <code>alarm/wind</code> gesetzt wird. Verhindert False-Alarms durch einzelne Ausreißer-Stationen.</li>
    <li><b>Wind-Trend:</b> Nehmen Böen zu oder ab? (Lineare Regression, letzten 60 Minuten)</li>
    <li><b>Gewitter-Signal:</b> Druckabfall + hohe Luftfeuchte → Gewittersignal Level 1. Zusätzlich starker Böenanstieg → Level 2 (Akut).</li>
    <li><b>Alpine Stationen ausschließen:</b> Stationen über konfigurierbarer Seehöhe (Standard: 1200 m) fließen nicht in den Wind-Alarm-Konsens ein (z.B. Feuerkogel 1618 m).</li>
</ul>
<p><b>TAWES RR-Einheit:</b> Die API liefert Regen in <b>mm/10min</b>. In den Status-Topics und der Stationsanzeige wird auf <b>mm/h</b> umgerechnet (×6). Der konfigurierte REGEN_ALARM gilt in mm/h.</p>
</div>
</div>

<!-- WIE ALARME BERECHNET WERDEN -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>🧠 Wie werden Alarmstufen bestimmt?</h3>

<div data-role="collapsible" data-collapsed="false">
<h4>Das Grundprinzip: Vertrauen durch Bestätigung</h4>
<p>Jede Datenquelle hat eine unterschiedliche Vertrauensstufe. Erst wenn mehrere Quellen dasselbe sagen, werden höhere Alarmstufen ausgelöst:</p>
<table class="mqtt-table">
<thead><tr><th>Quelle</th><th>Allein</th><th>Mit Bestätigung durch andere Quelle</th></tr></thead>
<tbody>
<tr><td><b>ZAMG</b></td><td>Stufe 1–3 direkt (amtliche Warnung)</td><td>– (kein Konsens nötig)</td></tr>
<tr><td><b>INCA</b></td><td>Max. Stufe 1</td><td>+ TAWES oder ZAMG → Stufe 1–3</td></tr>
<tr><td><b>TAWES</b></td><td>Max. Stufe 1</td><td>+ INCA → Stufe 1–3</td></tr>
</tbody>
</table>
<p style="font-size:11px;color:#666"><b>Besonderheit Dauersignal:</b> Wenn INCA seit mindestens 4 aufeinanderfolgenden Zyklen (~20 Minuten) konstant ein Signal zeigt, ist max. Stufe 2 auch ohne TAWES-Bestätigung möglich. Dies erkennt anhaltende Ereignisse bevor Upstream-Stationen reagieren.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Schwellwerte bestimmen die Alarmstufe</h4>
<p>Die konfigurierten Schwellwerte (REGEN_ALARM, BOEN_ALARM) sind die einzige Grenze zwischen den Stufen:</p>
<table class="mqtt-table">
<thead><tr><th>Schwellwert</th><th>Stufe 1</th><th>Stufe 2</th><th>Stufe 3</th></tr></thead>
<tbody>
<tr><td>REGEN_ALARM (Standard 10 mm/h)</td><td>≥ 1× (≥ 10 mm/h)</td><td>≥ 2× (≥ 20 mm/h)</td><td>≥ 3× (≥ 30 mm/h)</td></tr>
<tr><td>BOEN_ALARM (Standard 60 km/h)</td><td>≥ 1× (≥ 60 km/h)</td><td>≥ 2× (≥ 120 km/h)</td><td>≥ 3× (≥ 180 km/h)</td></tr>
</tbody>
</table>
<p>Kein anderer Wert bestimmt ob Stufe 1, 2 oder 3 ausgelöst wird. ZAMG-Stufen werden direkt gemappt: <b>Gelb → 1, Orange → 2, Rot/Lila → 3</b>.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Warum zeigt das System manchmal hohe Stufen bevor es regnet?</h4>
<p>Das ist korrekt und gewollt – hier die Erklärung:</p>
<p>Das Plugin berechnet die Stufe aus dem <b>Spitzenwert der nächsten 30 Minuten</b> (<code>rr_max_30min</code>), nicht nur aus dem aktuellen Messwert – aber nur wenn TAWES oder ZAMG das bestätigen. So erhältst du die richtige Warnstufe mit Vorlaufzeit, bevor der Starkregen ankommt.</p>
<p><b>Beispiel:</b> INCA zeigt jetzt 0.5 mm/h, aber in 15 Minuten 8 mm/h. TAWES meldet Regen upstream. Das Plugin zeigt Stufe 1 jetzt (mit 15 Minuten Vorlaufzeit) statt Stufe 0 bis der Regen wirklich da ist.</p>
<p>Wind verhält sich genauso: <code>fx_max_60min</code> (Maximum der nächsten 60 Minuten) bestimmt die Stufe.</p>
<p style="font-size:11px;color:#666"><b>Ohne Bestätigung kein Peak:</b> Wenn INCA allein (kein TAWES, keine ZAMG) ein Signal zeigt, wird immer nur der aktuelle Wert (<code>rr_jetzt</code>) verwendet. Der Peak greift erst bei bestätigten Ereignissen.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Drei unabhängige ETA-Quellen</h4>
<p>Das Plugin berechnet die Ankunftszeit (ETA) aus drei unabhängigen Quellen und wählt intelligent:</p>
<ol>
    <li><b>INCA-Modell-ETA</b> – <code>minuten_bis_regen</code> aus dem Nowcast-Modell (interpoliert ab 25% der Alarmschwelle, damit Modellrauschen keinen ETA von 0 auslöst)</li>
    <li><b>Trend-ETA</b> – Extrapolation aus dem zeitlichen Verlauf der letzten ~40 Minuten (z.B. ETA war 45→30→15 min in den letzten Zyklen → nächster Wert ~5 min)</li>
    <li><b>Physik-ETA</b> – Entfernung der nächsten upstream Regenstation ÷ Windgeschwindigkeit (vollständig unabhängig vom INCA-Modell)</li>
</ol>
<p>Wenn INCA-Modell und Physik-ETA innerhalb von 10 Minuten übereinstimmen, erhöht das die Konfidenz um +15 Punkte und der frühere (konservativere) ETA wird angezeigt – du bekommst mehr Zeit zum Reagieren.</p>
<p>Sichtbar in <code>alarm/eta_min</code>.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Trend-Engine und Beschleunigungserkennung</h4>
<p>Das Plugin puffert die letzten ~40 Minuten (8 Zyklen) aller Messwerte und analysiert:</p>
<table class="mqtt-table">
<thead><tr><th>Funktion</th><th>Beschreibung</th></tr></thead>
<tbody>
<tr><td><b>Regen-Trend</b></td><td>Nimmt Regen zu oder ab? (lineare Regression auf INCA-Werte allein – nicht gemischt mit TAWES, da das verschiedene Orte wären)</td></tr>
<tr><td><b>Wind-Trend</b></td><td>Wie entwickeln sich die Böen über die letzten Zyklen?</td></tr>
<tr><td><b>Beschleunigung</b></td><td>Ist der Anstieg in den letzten 3 Zyklen mehr als doppelt so steil wie im Gesamttrend? → <code>stark_zunehmend</code>. Typisch für herannahende Gewitter die sich rasch verstärken.</td></tr>
<tr><td><b>Konfidenz-Bonus</b></td><td>+5 Punkte je konsistentem Zyklus in gleiche Richtung, +10 bei Beschleunigung erkannt. Max. +25 Gesamtbonus.</td></tr>
<tr><td><b>Eskalation</b></td><td>Wenn INCA seit ≥4 Zyklen (~20 min) konstant Regen/Wind signalisiert, wird die Alarm-Maximal-Stufe auf 2 erhöht – auch ohne TAWES-Bestätigung.</td></tr>
</tbody>
</table>
<p style="font-size:11px;color:#888">Die Trend-Engine startet kalt (leer) und braucht ~15–20 Minuten nach Daemon-Start bis sie zuverlässige Werte liefert.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Konfidenz-Score (0–100)</h4>
<p>Der Score <code>alarm/konfidenz</code> zeigt wie sicher die Vorhersage ist:</p>
<table class="mqtt-table">
<thead><tr><th>Bedingung</th><th>Punkte</th></tr></thead>
<tbody>
<tr><td>ZAMG-Warnung aktiv</td><td>+40</td></tr>
<tr><td>INCA-Signal vorhanden</td><td>+30</td></tr>
<tr><td>TAWES bestätigt</td><td>+20</td></tr>
<tr><td>Trend konsistent (pro Zyklus, max. +25)</td><td>+5 je Zyklus</td></tr>
<tr><td>Beschleunigung erkannt</td><td>+10</td></tr>
<tr><td>INCA-ETA und Physik-ETA stimmen überein (±10 min)</td><td>+15</td></tr>
</tbody>
</table>
<p>In den Notification-Texten erscheint der Score als "Sicherheit: gering / mittel / hoch / sehr hoch".</p>
</div>
</div>

<!-- MQTT TOPICS – VOLLSTÄNDIGE LISTE -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>📡 MQTT Topics – vollständige Referenz</h3>
<p>Standard-Präfix: <code>unwetter/</code> (in Einstellungen änderbar). Alle Topics werden mit <b>retain=true</b> gespeichert.</p>

<div data-role="collapsible" data-collapsed="false">
<h4>🚦 alarm/ – Aggregierter Gesamtstatus (Kombination aller Quellen)</h4>
<p>Diese Topics sind für Loxone-Logiken optimiert: ein Wert fasst alle Quellen zusammen.</p>
<p><b>Stufen-Bedeutung (gilt für alle alarm/-Topics):</b> 0 = ruhig, 1 = Vorsicht, 2 = Warnung, 3 = Extrem.</p>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>alarm/gesamt</code></td><td>0–3</td><td><b>max(gewitter, wind, regen, hagel, schnee)</b>. Primärer Gate-Wert für Loxone-Automatisierungen.</td></tr>
<tr><td><code>alarm/gewitter</code></td><td>0–3</td><td><b>1</b>=möglich (ZAMG Gelb od. TAWES Lvl 1), <b>2</b>=Warnung (ZAMG Orange od. TAWES Lvl 2), <b>3</b>=Extrem (ZAMG Rot/Lila)</td></tr>
<tr><td><code>alarm/wind</code></td><td>0–3</td><td><b>1</b>=Vorsicht (ZAMG Gelb od. INCA/TAWES ≥ 1×BOEN_ALARM od. Wind-Kaskade), <b>2</b>=Warnung, <b>3</b>=Extrem</td></tr>
<tr><td><code>alarm/wind_quelle</code></td><td>Text</td><td>Welche Quelle hat <code>alarm/wind</code> ausgelöst: <code>ZAMG</code>, <code>INCA (52km/h)</code>, <code>INCA+TAWES (48→85km/h)</code>, <code>TAWES_STURM</code>, <code>TAWES_KASKADE</code>, <code>–</code></td></tr>
<tr><td><code>alarm/regen</code></td><td>0–3</td><td><b>1</b>=erwartet, <b>2</b>=Starkregen, <b>3</b>=Extrem. Nieselregen und bald_regen lösen <b>keinen</b> Alarm aus.</td></tr>
<tr><td><code>alarm/regen_quelle</code></td><td>Text</td><td>Welche Quelle hat <code>alarm/regen</code> ausgelöst: <code>ZAMG</code>, <code>INCA (3.5mm/h)</code>, <code>INCA+TAWES (0.5→12mm/h)</code>, <code>TAWES_UP</code>, <code>TAWES_LOK</code>, <code>–</code></td></tr>
<tr><td><code>alarm/hagel</code></td><td>0–2</td><td><b>1</b>=möglich (ZAMG Gelb od. INCA bald_hagel/graupel), <b>2</b>=Warnung (ZAMG Orange+)</td></tr>
<tr><td><code>alarm/schnee</code></td><td>0–2</td><td><b>1</b>=möglich (ZAMG Gelb od. INCA PT=Schnee/Schneeregen), <b>2</b>=Warnung (ZAMG Orange+). Inkl. Glatteis.</td></tr>
<tr><td><code>alarm/stufe</code></td><td>0–4</td><td>Höchste <b>offizielle ZAMG</b>-Warnstufe (nur ZAMG, kein INCA/TAWES)</td></tr>
<tr><td><code>alarm/konfidenz</code></td><td>0–100</td><td>Sicherheits-Score. 0–39=schwach (eine Quelle), 40–69=bestätigt, 70+=hoch (alle Quellen + Trend)</td></tr>
<tr><td><code>alarm/eta_min</code></td><td>Minuten / -1</td><td>Beste verfügbare ETA-Schätzung (Physik, Trend oder INCA-Modell). -1 = kein ETA bekannt.</td></tr>
<tr><td><code>alarm/regen_trend</code></td><td>Text</td><td>Trend der Regenintensität: <code>stark_zunehmend</code> / <code>zunehmend</code> / <code>stabil</code> / <code>abnehmend</code> / <code>unbekannt</code></td></tr>
<tr><td><code>alarm/zusammenfassung</code></td><td>Text</td><td>Lesbarer Alarmtext. z.B. <code>🌧️ Regen bestätigt (Stufe 2/3) – Ankunft in ~12 min | Sicherheit: hoch</code></td></tr>
<tr><td><code>alarm/entwarnung</code></td><td>0 / 1</td><td>Wechselt einmalig auf <code>1</code> wenn <code>alarm/gesamt</code> von ≥1 auf 0 fällt. Gate für Entwarnung-Push.</td></tr>
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

<p style="margin-top:8px"><b>alarm/wind</b> – INCA nutzt <code>fx_max_60min</code> direkt. TAWES braucht Konsens (<code>sturm_upstream=1</code>) ODER erkennt Kaskade (<code>wind_kaskade=1</code>). Mit TAWES-Bestätigung sind alle Stufen (1–3) erreichbar.</p>
<table class="mqtt-table">
<thead><tr><th>Quelle</th><th>Bedingung</th><th>→ Level</th></tr></thead>
<tbody>
<tr><td>ZAMG</td><td>Wind Gelb</td><td><b>1</b></td></tr>
<tr><td>INCA</td><td><code>fx_max_60min</code> ≥ 1 × BOEN_ALARM (allein → max 1)</td><td><b>1</b></td></tr>
<tr><td>INCA+TAWES</td><td><code>fx_max_60min</code> ≥ 1 × BOEN_ALARM (bestätigt)</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td>Konsens-Sturm upstream ≥ 1 × BOEN_ALARM</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td>Wind-Kaskade erkannt – Vorwarnung ohne Konsens</td><td><b>1</b></td></tr>
<tr><td>ZAMG</td><td>Wind Orange</td><td><b>2</b></td></tr>
<tr><td>INCA+TAWES</td><td><code>fx_max_60min</code> ≥ 2 × BOEN_ALARM</td><td><b>2</b></td></tr>
<tr><td>TAWES</td><td>Konsens-Sturm upstream ≥ 2 × BOEN_ALARM</td><td><b>2</b></td></tr>
<tr><td>ZAMG</td><td>Wind Rot/Lila</td><td><b>3</b></td></tr>
<tr><td>INCA+TAWES</td><td><code>fx_max_60min</code> ≥ 3 × BOEN_ALARM</td><td><b>3</b></td></tr>
<tr><td>TAWES</td><td>Konsens-Sturm upstream ≥ 3 × BOEN_ALARM</td><td><b>3</b></td></tr>
</tbody>
</table>
<p style="font-size:11px;color:#888"><b>TAWES Wind-Konsens:</b> Mind. max(2, 30%) der Upstream-Tal-Stationen (ohne alpine Stationen) müssen Böen ≥ BOEN_ALARM melden. Eine einzelne Station löst nie Alarm aus.</p>

<p style="margin-top:8px"><b>alarm/regen</b> – Kein Alarm ohne Schwellwertüberschreitung. Die Stufe berechnet sich aus dem Spitzenwert der nächsten 30 Min wenn TAWES bestätigt.</p>
<table class="mqtt-table">
<thead><tr><th>Quelle</th><th>Bedingung</th><th>→ Level</th></tr></thead>
<tbody>
<tr><td>ZAMG</td><td>Regen Gelb</td><td><b>1</b></td></tr>
<tr><td>INCA allein</td><td><code>rr_jetzt</code> ≥ REGEN_ALARM (max. Stufe 1)</td><td><b>1</b></td></tr>
<tr><td>INCA+TAWES</td><td>Spitzenwert 30min ≥ 1 × REGEN_ALARM</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td><code>regen_upstream_mm</code> ≥ REGEN_ALARM (allein → max 1)</td><td><b>1</b></td></tr>
<tr><td>TAWES</td><td><code>regen_lokal_mm</code> ≥ REGEN_ALARM (≤ Lokal-Umkreis, allein → max 1)</td><td><b>1</b></td></tr>
<tr><td>ZAMG</td><td>Regen Orange</td><td><b>2</b></td></tr>
<tr><td>INCA+TAWES</td><td>Spitzenwert 30min ≥ 2 × REGEN_ALARM</td><td><b>2</b></td></tr>
<tr><td>ZAMG</td><td>Regen Rot/Lila</td><td><b>3</b></td></tr>
<tr><td>INCA+TAWES</td><td>Spitzenwert 30min ≥ 3 × REGEN_ALARM</td><td><b>3</b></td></tr>
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
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>zamg/max_stufe</code></td><td>0–4</td><td>Höchste aktive Warnstufe über alle Typen</td></tr>
<tr><td><code>zamg/irgendwas_aktiv</code></td><td>0 / 1</td><td><b>Gate für Morgen-Push:</b> 1 wenn mindestens eine Warnung aktiv oder bald erwartet</td></tr>
<tr><td><code>zamg/akutwarnung</code></td><td>0 / 1</td><td>1 bei stationsbasierter Akutwarnung (GWA-ID)</td></tr>
<tr><td><code>zamg/letzter_abruf</code></td><td>Datum/Uhrzeit</td><td>Zeitstempel des letzten erfolgreichen ZAMG-Abrufs</td></tr>
<tr><td><code>zamg/{typ}/stufe</code></td><td>0–4</td><td>Warnstufe für diesen Typ</td></tr>
<tr><td><code>zamg/{typ}/aktiv</code></td><td>0 / 1</td><td>1 = Warnung läuft gerade</td></tr>
<tr><td><code>zamg/{typ}/bald</code></td><td>0 / 1</td><td>1 = Warnung beginnt in &lt;30 Minuten</td></tr>
<tr><td><code>zamg/{typ}/start_epoch</code></td><td>Unix-TS</td><td>Startzeit als Unix-Timestamp (0 = keine Warnung)</td></tr>
<tr><td><code>zamg/{typ}/end_epoch</code></td><td>Unix-TS</td><td>Endzeit als Unix-Timestamp (0 = keine Warnung)</td></tr>
<tr><td><code>zamg/{typ}/notification</code></td><td>Text</td><td>Fertiger Push-Text, z.B. "⚠️ ORANGE – Wind | heute 14:00 – morgen 06:00"</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>📊 inca/ – INCA Nowcast (nächste 60 Minuten)</h4>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>inca/fx</code></td><td>km/h</td><td>Böenspitzen jetzt</td></tr>
<tr><td><code>inca/ff</code></td><td>km/h</td><td>Mittlere Windgeschwindigkeit jetzt</td></tr>
<tr><td><code>inca/fx_max_30min</code></td><td>km/h</td><td>Max. Böen in den nächsten 30 Minuten</td></tr>
<tr><td><code>inca/fx_max_60min</code></td><td>km/h</td><td>Max. Böen in der nächsten Stunde – entscheidet über <code>alarm/wind</code></td></tr>
<tr><td><code>inca/rr</code></td><td>mm/h</td><td>Aktuelle Niederschlagsintensität</td></tr>
<tr><td><code>inca/regen_alarm</code></td><td>0 / 1</td><td>1 = aktuelle Regenrate ≥ REGEN_ALARM</td></tr>
<tr><td><code>inca/pt</code></td><td>1/2/3/4/5/255</td><td>Niederschlagstyp jetzt: 1=Regen, 2=Schnee, 3=Schneeregen, 4=Graupel, 5=Hagel, 255=kein</td></tr>
<tr><td><code>inca/pt_name</code></td><td>Text</td><td>Niederschlagstyp jetzt als Text</td></tr>
<tr><td><code>inca/pt_bald</code></td><td>1/2/3/4/5/255</td><td>Niederschlagstyp des nächsten Regens. 255 = kein Regen in Sicht.</td></tr>
<tr><td><code>inca/pt_bald_name</code></td><td>Text / leer</td><td>Niederschlagstyp des nächsten Regens als Text.</td></tr>
<tr><td><code>inca/bald_regen</code></td><td>0 / 1</td><td>1 = Regen kommt in &lt;30 Minuten. <b>Kein alarm/regen-Trigger</b> – nur Info. Ideal für Bewässerungssteuerung.</td></tr>
<tr><td><code>inca/bald_hagel</code></td><td>0 / 1</td><td>1 = Hagel möglich in &lt;60 Minuten</td></tr>
<tr><td><code>inca/bald_graupel</code></td><td>0 / 1</td><td>1 = Graupel möglich in &lt;60 Minuten</td></tr>
<tr><td><code>inca/bald_sturm_30</code></td><td>0 / 1</td><td>1 = Sturmböen (&gt;Alarm-Schwelle) in &lt;30 Minuten</td></tr>
<tr><td><code>inca/bald_sturm_60</code></td><td>0 / 1</td><td>1 = Sturmböen in &lt;60 Minuten</td></tr>
<tr><td><code>inca/minuten_bis_regen</code></td><td>Minuten / -1</td><td>Geschätzte Zeit bis zum nächsten Regen (ab 25% der Alarmschwelle). -1 = kein Regen in Sicht.</td></tr>
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
<tr><td><code>tawes/upstream_aktiv</code></td><td>Anzahl</td><td>Wieviele Stationen gerade Upstream sind</td></tr>
<tr><td><code>tawes/wind_upstream_kmh</code></td><td>km/h</td><td>Max. Böen an Upstream-Tal-Stationen – Rohwert, ohne Konsens-Bestätigung</td></tr>
<tr><td><code>tawes/sturm_upstream</code></td><td>0 / 1</td><td>1 = Sturmböen an mind. 30% der Upstream-Tal-Stationen. Löst <code>alarm/wind</code> aus.</td></tr>
<tr><td><code>tawes/wind_kaskade</code></td><td>0 / 1</td><td>1 = Upstream-Stationen melden zeitlich gestaffelt hohe Böen. Herannahendes Sturmsystem. Löst <code>alarm/wind=1</code> als Vorwarnung.</td></tr>
<tr><td><code>tawes/wind_kaskade_eta_min</code></td><td>Minuten / -1</td><td>ETA der Sturmfront laut Wind-Kaskade.</td></tr>
<tr><td><code>tawes/wind_kaskade_speed_kmh</code></td><td>km/h</td><td>Berechnete Geschwindigkeit der Sturmfront (aus Wind-Kaskade).</td></tr>
<tr><td><code>tawes/alpine_upstream</code></td><td>Anzahl</td><td>Anzahl alpiner Upstream-Stationen die aus dem Wind-Konsens ausgeschlossen wurden.</td></tr>
<tr><td><code>tawes/regen_upstream</code></td><td>0 / 1</td><td>1 = Regen an mind. einer Upstream-Station in den letzten 30 Minuten gemessen</td></tr>
<tr><td><code>tawes/regen_upstream_mm</code></td><td>mm/h</td><td>Max. Regenintensität an Upstream-Stationen (letzten 30 Min)</td></tr>
<tr><td><code>tawes/regen_lokal</code></td><td>0 / 1</td><td>1 = Regen jetzt innerhalb des Lokal-Umkreises (Standard: 25 km). Unabhängig von Windrichtung.</td></tr>
<tr><td><code>tawes/regen_lokal_mm</code></td><td>mm/h</td><td>Max. Regenintensität im Lokal-Umkreis</td></tr>
<tr><td><code>tawes/regen_lokal_station</code></td><td>Text</td><td>Station die <code>regen_lokal_mm</code> bestimmt. z.B. <code>VOECKLABRUCK (12km) 8.4mm/h</code></td></tr>
<tr><td><code>tawes/druck_trend</code></td><td>hPa/10min</td><td>Luftdrucktendenz der nächstgelegenen Upstream-Station. Negativ = fallend.</td></tr>
<tr><td><code>tawes/gewitter_signal</code></td><td>0 / 1 / 2</td><td>0=kein, 1=Gewittergefahr (Druckabfall + hohe Feuchte), 2=akut</td></tr>
<tr><td><code>tawes/naechste_station</code></td><td>Text</td><td>Name, Distanz und Richtung der nächsten Upstream-Station</td></tr>
<tr><td><code>tawes/stationen_anzahl</code></td><td>Anzahl</td><td>Gesamtzahl erreichter TAWES-Stationen im konfigurierten Radius</td></tr>
<tr><td><code>tawes/api_ok</code></td><td>0 / 1</td><td>1 = letzter TAWES-API-Abruf erfolgreich</td></tr>
<tr><td><code>tawes/letztes_update</code></td><td>Datum/Uhrzeit</td><td>Zeitstempel des letzten erfolgreichen TAWES-Abrufs</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🔔 notification/ – Fertige Push-Texte</h4>
<p>Alle Notification-Topics liefern lesbaren Text ohne technischen Jargon – kein "INCA", kein "TAWES" in den Meldungen für Loxone.</p>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Bedeutung</th><th>Beispielwerte</th></tr></thead>
<tbody>
<tr><td><code>notification/geosphere</code></td><td>ZAMG aktive Warnungen. <b>Immer aktiv.</b></td><td>
<code>⚠️ ORANGE – Wind | heute 14:00 – morgen 06:00</code><br>
<code>keine aktiven Warnungen</code></td></tr>
<tr><td><code>notification/tageswarnung</code></td><td>ZAMG Warnungen die in den nächsten 8h beginnen. Ideal für Morgenroutine 07:00. Leer wenn nichts geplant.</td><td>
<code>📅 heute 16:00: GELB Gewitter</code><br>
<code>📅 heute 14:00: ORANGE Regen | 📅 morgen: GELB Schnee ab 22:00</code></td></tr>
<tr><td><code>notification/inca</code></td><td>INCA Nowcast-Status in lesbarem Deutsch.</td><td>
<code>🟠 Sturmböen in &lt;30 Minuten: max 75 km/h</code><br>
<code>🌧️ Regen erwartet in ~12 Minuten – durch Wetterstationen bestätigt (Sicherheit: hoch)</code><br>
<code>✅ Ruhig | Wind: 12 km/h | Kein Niederschlag</code></td></tr>
<tr><td><code>notification/tawes</code></td><td>Lagebericht der Umgebungsstationen.</td><td>
<code>💨 Sturmböen aus WNW: 85 km/h – 4 Stationen bestätigen</code><br>
<code>🌧️ Regen aus NW nähert sich – 22 mm/h gemessen, Ankunft in ~15 Minuten, Intensität nimmt zu</code><br>
<code>🌧️ Regen in der Nähe: VÖCKLABRUCK (12 km) – 8 mm/h</code></td></tr>
<tr><td><code>notification/alle</code></td><td>Hauptnachricht für Loxone Push. <b>Empfehlung für Push-Button in Loxone.</b></td><td>
<code>🌧️ Regen bestätigt (Stufe 2/3) – Ankunft in ~12 min | Sicherheit: hoch</code><br>
<code>📅 heute 16:00: GELB Gewitter</code> (kein aktiver Alarm)<br>
<code>✅ Entwarnung – alle Wetterwarnungen aufgehoben.</code></td></tr>
</tbody>
</table>
<p style="font-size:11px;color:#888"><b>Loxone Morgenroutine (Empfehlung):</b> Zeitprogramm 07:00 → Gate auf <code>zamg/irgendwas_aktiv = 1</code> → Push mit <code>notification/tageswarnung</code>. So bekommst du nur dann eine Morgenmeldung wenn heute noch Unwetter kommt. <code>notification/alle</code> als Loxone-Textfeld für die laufende Wetterwarnung.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>⚙️ System-Topics</h4>
<table class="mqtt-table">
<thead><tr><th>Topic</th><th>Werte</th><th>Bedeutung</th></tr></thead>
<tbody>
<tr><td><code>status</code></td><td>OK / Error</td><td>Systemstatus. "OK" = alles läuft. "Error - INCA API" = INCA nicht erreichbar.</td></tr>
<tr><td><code>status/api_ok</code></td><td>0 / 1</td><td>Gate für API-Fehler-Push: 0 = mindestens eine API meldet Fehler</td></tr>
<tr><td><code>status/api_fehler</code></td><td>Text / leer</td><td>Beschreibung welche APIs gerade nicht erreichbar sind. Leer = alles OK.</td></tr>
<tr><td><code>status/zamg_ok</code></td><td>0 / 1</td><td>1 = letzter ZAMG-Abruf erfolgreich</td></tr>
<tr><td><code>status/inca_ok</code></td><td>0 / 1</td><td>1 = letzter INCA-Abruf erfolgreich</td></tr>
<tr><td><code>status/tawes_ok</code></td><td>0 / 1</td><td>1 = letzter TAWES-Abruf erfolgreich</td></tr>
<tr><td><code>status/mqtt_reconnects</code></td><td>Zahl</td><td>Anzahl MQTT-Reconnects seit Daemon-Start. Sollte bei stabiler Verbindung nicht wachsen.</td></tr>
<tr><td><code>letzter_abruf_datum</code></td><td>DD.MM.YYYY HH:MM:SS</td><td>Zeitpunkt der letzten erfolgreichen Datenaktualisierung</td></tr>
<tr><td><code>letzter_abruf_epoch</code></td><td>Unix-Timestamp</td><td>Wenn dieser Wert seit &gt;10 Minuten nicht aktualisiert wird → Daemon prüfen</td></tr>
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
<tr><td><b>Böen-Alarm (BOEN_ALARM)</b></td><td>60 km/h</td><td>Ab welcher Böenstärke ein Wind-Alarm ausgelöst wird. 60 km/h = Beaufort 8. Stufe 1 = 60 km/h, Stufe 2 = 120 km/h, Stufe 3 = 180 km/h. Empfehlung: 40 km/h (sensibel), 60 km/h (Standard), 80 km/h (schwere Stürme).</td></tr>
<tr><td><b>Regen-Alarm (REGEN_ALARM)</b></td><td>10 mm/h</td><td>Ab welcher Regenrate ein Regen-Alarm ausgelöst wird. <b>Wert immer in mm/h!</b> Stufe 1 = 10, Stufe 2 = 20, Stufe 3 = 30 mm/h. TAWES-Stationen zeigen in der Tabelle mm/10min – diese Werte ×6 ergeben mm/h. Beispiel: 5.3 mm/10min = 31.8 mm/h.</td></tr>
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
<tr><td><b>Max. Seehöhe Upstream</b></td><td>1200 m</td><td>Upstream-Stationen über dieser Höhe werden aus dem Wind-Alarm-Konsens ausgeschlossen. Bergstationen messen natürlich stärkere Winde als das Tal.</td></tr>
<tr><td><b>Lokal-Regen Umkreis</b></td><td>25 km</td><td>Umkreis für <code>tawes/regen_lokal</code>. Stationen innerhalb die aktuell Regen melden aktivieren den lokalen Regen-Check (unabhängig von Windrichtung).</td></tr>
<tr><td><b>Upstream-Kegel</b></td><td>45°</td><td>Halbwinkel des Kegels in dem Stationen als Upstream gelten. 45° = 90° Gesamtkegel. Kleiner Winkel = präziser aber weniger Stationen. Empfehlung: 30°–50°.</td></tr>
</tbody>
</table>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>🛰️ INCA Nowcast – Parameter</h4>
<table class="mqtt-table">
<thead><tr><th>Parameter</th><th>Standard</th><th>Erklärung</th></tr></thead>
<tbody>
<tr><td><b>Nowcast Horizont</b></td><td>60 min</td><td>Wie weit INCA vorausschaut. 60 min = Maximum, empfohlen für 15–60 min Vorwarnzeit.</td></tr>
<tr><td><b>Böen-Schwellwert</b></td><td>60 km/h</td><td>Geteilt mit TAWES. INCA allein → max. Stufe 1. INCA + TAWES bestätigt → Stufe 1–3.</td></tr>
<tr><td><b>Regen-Schwellwert</b></td><td>10 mm/h</td><td>Geteilt mit TAWES. INCA allein → max. Stufe 1. INCA + TAWES bestätigt → Stufe 1–3.</td></tr>
</tbody>
</table>
</div>
</div>

<!-- LOXONE INTEGRATION -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>🔧 Integration in Loxone</h3>

<div data-role="collapsible" data-collapsed="false">
<h4>Wann soll eine Notification gesendet werden?</h4>
<table class="mqtt-table">
<thead><tr><th>Anwendungsfall</th><th>Gate</th><th>Loxone-Logik</th></tr></thead>
<tbody>
<tr><td><b>Echtzeit-Unwetter-Push</b></td><td><code>alarm/gesamt</code></td><td>Wert wechselt <code>0 → ≥ 1</code>: Push mit <code>notification/alle</code> senden</td></tr>
<tr><td><b>Morgen-Zusammenfassung</b> (07:00 Uhr)</td><td><code>zamg/irgendwas_aktiv</code></td><td>Wenn <code>= 1</code>: Push mit <code>notification/tageswarnung</code>. Bei <code>= 0</code> nichts senden.</td></tr>
<tr><td><b>Sofort-Alarm Stufe ≥ Orange</b></td><td><code>alarm/stufe</code></td><td>Wert wechselt auf <code>≥ 2</code>: sofortiger Push</td></tr>
<tr><td><b>Entwarnung</b></td><td><code>alarm/entwarnung</code></td><td>Wechselt einmalig auf <code>1</code>: Push mit <code>notification/alle</code></td></tr>
<tr><td><b>Markise / Sturmschutz</b></td><td><code>alarm/wind</code></td><td>Wert wechselt auf <code>≥ 2</code>: Markise einfahren</td></tr>
<tr><td><b>Hagelschutz</b></td><td><code>alarm/hagel</code> oder <code>inca/bald_hagel</code></td><td>Wert wird <code>≥ 1</code>: Aktion auslösen</td></tr>
<tr><td><b>Alarm-Diagnose</b></td><td><code>alarm/wind_quelle</code> / <code>alarm/regen_quelle</code></td><td>Text zeigt exakt welche Quelle den Alarm ausgelöst hat</td></tr>
</tbody>
</table>
<p><b>Stufen für alarm/* Topics:</b></p>
<ul style="font-size:12px">
    <li><code>0</code> = <span class="tag-ok">Ruhig</span> – kein Push senden</li>
    <li><code>1</code> = <span style="background:#FFEB3B;color:#333;padding:1px 5px;border-radius:3px;font-size:10px">Vorsicht</span> – optionaler Info-Push</li>
    <li><code>2</code> = <span class="tag-warn">Warnung</span> – Push empfohlen</li>
    <li><code>3</code> = <span class="tag-err">AKUT</span> – Push zwingend</li>
</ul>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Typische Automatisierungsbeispiele</h4>
<ul>
    <li>📽 <b>Markise / Sonnensegel:</b> Wenn <code>alarm/wind ≥ 2</code> ODER <code>inca/bald_sturm_30 = 1</code> → Markise einfahren</li>
    <li>💧 <b>Bewässerung:</b> Wenn <code>alarm/regen ≥ 1</code> ODER <code>inca/bald_regen = 1</code> ODER <code>tawes/regen_lokal = 1</code> → Bewässerung pausieren</li>
    <li>🪟 <b>Dachfenster:</b> Wenn <code>alarm/regen ≥ 1</code> ODER <code>tawes/regen_lokal = 1</code> → Fenster schließen</li>
    <li>🔔 <b>Gewitter-Push:</b> Wenn <code>alarm/gewitter = 2</code> → Sofort-Push auf Handy</li>
    <li>⚡ <b>Smart Home Sicherheit:</b> Wenn <code>zamg/max_stufe ≥ 3</code> → Pool-Pumpe aus, alle Dachluken zu</li>
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
<h4>Warum zeigt das System Stufe 3 bevor der Regen ankommt?</h4>
<p>Das ist korrekt und gewollt. Bei bestätigten Ereignissen (INCA + TAWES oder ZAMG) berechnet das Plugin die Stufe aus dem Spitzenwert der nächsten 30 Minuten, nicht nur aus dem aktuellen Wert. Du bekommst so die richtige Warnstufe mit Vorlaufzeit.</p>
<p>Wenn INCA in 15 Minuten 35 mm/h vorhersagt und TAWES-Stationen in der Windrichtung bereits Regen messen, zeigt das System jetzt Stufe 3 – nicht erst wenn der Starkregen bei dir ist.</p>
<p><b>INCA allein</b> (ohne TAWES-Bestätigung) gibt nie die Vorhersage-Spitzenwerte weiter – dort wird immer nur der aktuelle <code>rr_jetzt</code> verwendet, und die Stufe ist auf max. 1 begrenzt.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was ist der Unterschied zwischen den drei ETA-Quellen?</h4>
<p>Das System berechnet die Ankunftszeit aus drei unabhängigen Quellen:</p>
<ul>
    <li><b>INCA-Modell:</b> Das Nowcast-Modell interpoliert wann Regen an deinen Koordinaten ankommen wird. Schnell verfügbar, aber Modellrauschen möglich.</li>
    <li><b>Trend-ETA:</b> Wenn der ETA in den letzten Zyklen stetig kleiner wird (z.B. 45→30→15 min), wird der nächste Wert extrapoliert. Glättet Ausreißer.</li>
    <li><b>Physik-ETA:</b> Entfernung der nächsten Upstream-Station mit Regen ÷ aktuelle Windgeschwindigkeit. Vollständig unabhängig vom INCA-Modell. Wenn beide ETAs innerhalb von 10 Minuten übereinstimmen, erhöht das die Konfidenz um 15 Punkte.</li>
</ul>
<p>Sichtbar in <code>alarm/eta_min</code>. In den Notification-Texten steht welche Quelle verwendet wurde.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Warum löst eine einzelne Wetterstation einen Wind-Alarm aus?</h4>
<p>TAWES Wind-Alarm erfordert Konsens: mind. 30% aller Upstream-Tal-Stationen (absolut mind. 2) müssen Böen ≥ BOEN_ALARM melden. Eine Einzelstation allein kann keinen <code>alarm/wind</code> auslösen.</p>
<p>Prüfe <code>alarm/wind_quelle</code>. Wenn dort <code>INCA (52km/h)</code> steht, kommt der Alarm vom INCA Nowcast-Modell – das braucht keinen Konsens. Wenn zu sensibel: BOEN_ALARM erhöhen.</p>
<p><code>TAWES_KASKADE</code> bedeutet eine Sturmfront nähert sich gestaffelt aus der Windrichtung (Vorwarnung Level 1 ohne Konsens – das ist beabsichtigt).</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Regen-Alarm obwohl ich keinen Regen sehe – was tun?</h4>
<p>Prüfe <code>alarm/regen_quelle</code> im MQTT. Das zeigt exakt welche Quelle und Station den Alarm ausgelöst hat.</p>
<ul>
    <li><b>TAWES_LOK (Station 28km ...):</b> Station im Lokal-Umkreis hat Regen gemeldet, der evtl. noch nicht angekommen ist. Lokal-Umkreis in den Einstellungen verkleinern oder REGEN_ALARM erhöhen.</li>
    <li><b>INCA+TAWES (0.5→35mm/h):</b> INCA sagt Spitze von 35 mm/h in 15 min vorher, TAWES bestätigt. Der hohe Wert ist ein Vorhersage-Spitzenwert – Regen kommt erst noch.</li>
    <li><b>TAWES_UP (12mm/h):</b> Regen kommt laut Upstream-Stationen auf dich zu – war evtl. noch nicht da.</li>
</ul>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was bedeutet alarm/konfidenz?</h4>
<p>Ein Score von 0–100 der angibt, wie verlässlich die aktuelle Alarm-Bewertung ist. Werte und ihre Bedeutung:</p>
<ul>
    <li><b>0–39:</b> Schwaches Signal – nur eine Quelle (z.B. INCA allein)</li>
    <li><b>40–69:</b> Bestätigt – zwei Quellen stimmen überein</li>
    <li><b>70+:</b> Hohe Sicherheit – alle Quellen + konsistenter Trend</li>
</ul>
<p>In <code>notification/inca</code> und <code>notification/alle</code> erscheint der Score als Text: "Sicherheit: gering / mittel / hoch / sehr hoch".</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was bedeutet "Upstream-Station"?</h4>
<p>Eine Station gilt als Upstream, wenn sie innerhalb des konfigurierten Winkels (Standard ±45°) der dominanten Windrichtung liegt. Das Wetter, das diese Station gerade misst, kommt in der Regel auch zu dir.</p>
<p>Alpine Stationen (über der konfigurierten Max-Seehöhe) sind in der Anzeige markiert, fließen aber nicht in den Wind-Konsens ein – Bergstationen messen natürlich stärkere Winde als das Tal.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Wie oft werden die Daten aktualisiert?</h4>
<p>Jede API kann <b>unabhängig konfiguriert</b> werden (Einstellungen → Abruf-Intervalle):</p>
<ul style="font-size:12px">
    <li><b>ZAMG:</b> Standard 300 s (5 min), Minimum 60 s – ZAMG-Warnungen ändern sich selten</li>
    <li><b>INCA:</b> Standard 300 s (5 min), Minimum 60 s – Nowcast alle 15 min aktualisiert</li>
    <li><b>TAWES:</b> Standard 480 s (8 min), Minimum 120 s – Stationsdaten alle 10 min verfügbar</li>
</ul>
<p>Der Loop-Takt (Standard: 300 s) gibt an, wie oft der Daemon intern prüft ob ein API-Abruf fällig ist. Einzelne APIs können so kürzer als der Loop-Takt laufen, indem Loop-Takt kleiner als die API-Intervalle gesetzt wird.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Warum ist alarm/eta_min = -1?</h4>
<p>-1 bedeutet, keine der drei ETA-Quellen konnte eine zuverlässige Schätzung liefern: INCA sieht keinen bevorstehenden Regen, der Trend hat noch zu wenige Zyklen, und keine Upstream-Station meldet Regen. Das ist normal wenn es wirklich keinen Regen in Sicht gibt.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Wo finde ich ältere Logs nach einem Daemon-Neustart?</h4>
<p>Bei jedem Daemon-Start wird eine neue Log-Datei mit Zeitstempel erstellt. Der Log-Tab zeigt alle verfügbaren Sitzungen der letzten 7 Tage in einer Liste. Klicke auf eine ältere Sitzung um das Log des früheren Starts zu öffnen.</p>
<p>Logs werden automatisch nach 7 Sitzungen gelöscht – so ist die Fehlersuche nach einem unerwarteten Neustart trotzdem möglich.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Warum zeigt TAWES keine oder wenige Stationen?</h4>
<p>Der Daemon löscht den Stations-Cache bei jedem Start und lädt die Liste frisch von der API. Das dauert ~30 Sekunden. Danach füllt sich der Daten-Buffer in den ersten 10–20 Minuten auf.</p>
<p>Stationen können aus zwei Gründen leer erscheinen:</p>
<ul>
    <li><b>Fehlende Messwerte (–):</b> Die Station antwortet, hat aber keinen Wind- oder Regensensor – normal bei TAWES-Klimastationen ohne Anemometer.</li>
    <li><b>Keine Stationszeile:</b> Die API hat aktuell keine Messung geliefert (kurze Sendepause). Tritt nach kurzer Zeit von selbst weg.</li>
</ul>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was ist der Unterschied ZAMG-Warnung und alarm/?</h4>
<p>ZAMG-Warnungen sind offizielle, manuell ausgegebene Warnungen (oft Stunden im Voraus). Die <code>alarm/</code>-Topics kombinieren ZAMG + INCA + TAWES und reagieren damit auch auf lokale, kurzfristige Ereignisse – zum Beispiel wenn TAWES Lokal-Regen erkennt ohne dass eine ZAMG-Warnung aktiv ist.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>MQTT-Verbindung bricht regelmäßig ab – was tun?</h4>
<p>Häufige Ursachen:</p>
<ul>
    <li><b>Zwei Daemon-Instanzen:</b> Wenn zwei Instanzen gleichzeitig laufen, kicken sie sich gegenseitig im 5-10 Sekunden Rhythmus. Der Daemon beendet alte Instanzen beim Start automatisch.</li>
    <li><b>MQTT-Broker-Neustart:</b> paho-mqtt reconnectet automatisch innerhalb 5–60 Sekunden.</li>
</ul>
<p><b>Diagnose:</b> <code>status/mqtt_reconnects</code> überwachen – sollte bei stabiler Verbindung über Stunden nicht wachsen. Täglicher Neustart um 03:00 Uhr bereinigt Speicher und Verbindungszustand. Watchdog-Cron alle 5 Minuten startet den Daemon bei Absturz neu.</p>
</div>

<div data-role="collapsible" data-collapsed="true">
<h4>Was ist die Wind-Kaskade?</h4>
<p>Die Wind-Kaskade erkennt, wenn Upstream-Stationen zeitlich gestaffelt hohe Böen melden: zuerst die weiter entfernte Station, dann eine näher gelegene. Das ist ein Indikator, dass eine Sturmfront aus der Windrichtung auf dich zukommt.</p>
<p>Die Kaskade löst <code>alarm/wind=1</code> als Vorwarnung aus, auch wenn der reguläre Konsens noch nicht erreicht ist. Sie berechnet dabei eine ETA (<code>tawes/wind_kaskade_eta_min</code>) und Frontgeschwindigkeit. Nur Böen der letzten 60 Minuten fließen ein.</p>
</div>
</div>

<div style="margin-top:20px; text-align:center; padding-bottom:10px">
    <a href="https://github.com/HitsmartDev/Unwetter4Lox" target="_blank" data-role="button" data-icon="star" data-mini="true" data-inline="true">GitHub Repository</a>
    &nbsp;
    <a href="https://www.geosphere.at" target="_blank" data-role="button" data-icon="info" data-mini="true" data-inline="true">GeoSphere Austria</a>
</div>

<?php LBWeb::lbfooter(); ?>
