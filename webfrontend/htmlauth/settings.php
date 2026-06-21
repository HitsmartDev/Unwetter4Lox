<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_io.php";

$L = LBSystem::readlanguage("language.ini");

$navbar[1]['Name'] = $L['MAIN.STATUS'];
$navbar[1]['URL']  = "index.php";
$navbar[2]['Name'] = $L['MAIN.SETTINGS'];
$navbar[2]['URL']  = "settings.php";
$navbar[2]['active'] = true;
$navbar[3]['Name'] = $L['MAIN.LOG'];
$navbar[3]['URL']  = "log.php";
$navbar[4]['Name'] = $L['MAIN.HELP'];
$navbar[4]['URL']  = "help.php";

# LoxBerry MQTT Auto-Erkennung
$mqttcred = null;
if (function_exists('mqtt_connectiondetails')) $mqttcred = mqtt_connectiondetails();

# Config laden/speichern
$cfgfile = $lbpconfigdir . "/unwetter4lox.cfg";
$cfg     = parse_ini_file($cfgfile, true) ?: [];
$use_lb  = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
$saved   = false;
$err     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $use_lb_new = isset($_POST['use_lb_mqtt']) ? '1' : '0';
    $zamg_en    = isset($_POST['zamg_enabled'])  ? '1' : '0';
    $inca_en    = isset($_POST['inca_enabled'])  ? '1' : '0';
    $tawes_en   = isset($_POST['tawes_enabled']) ? '1' : '0';

    $c  = "[LOCATION]\n";
    $c .= "LAT="          . floatval($_POST['lat'])  . "\n";
    $c .= "LON="          . floatval($_POST['lon'])  . "\n";
    $c .= "NAME="         . strip_tags(trim($_POST['name']         ?? 'Mein Zuhause'))    . "\n\n";
    
    $c .= "[MQTT]\n";
    $c .= "USE_LOXBERRY_MQTT={$use_lb_new}\n";
    $c .= "BROKER="       . strip_tags(trim($_POST['broker']       ?? '127.0.0.1'))       . "\n";
    $c .= "PORT="         . intval($_POST['port']                  ?? 1883)               . "\n";
    $c .= "USER="         . strip_tags(trim($_POST['mqtt_user']    ?? ''))                . "\n";
    $c .= "PASS="         . strip_tags(trim($_POST['mqtt_pass']    ?? ''))                . "\n";
    $c .= "TOPIC_PREFIX=" . strip_tags(trim($_POST['topic_prefix'] ?? 'unwetter'))        . "\n\n";
    
    $c .= "[ZAMG]\n";
    $c .= "ENABLED={$zamg_en}\n\n";

    $c .= "[INCA]\n";
    $c .= "ENABLED={$inca_en}\n";
    $c .= "HORIZON_MINUTES=" . max(15, min(60, intval($_POST['inca_horizon'] ?? 60)))     . "\n\n";

    $c .= "[SCHEDULE]\nINTERVAL=" . max(60, intval($_POST['interval'] ?? 300))            . "\n\n";
    $c .= "[THRESHOLDS]\n";
    $c .= "BOEN_ALARM="  . floatval($_POST['boen_alarm']  ?? 60)  . "\n";
    $c .= "REGEN_ALARM=" . floatval($_POST['regen_alarm'] ?? 2.0) . "\n\n";
    $c .= "[NOTIFICATIONS]\nMIN_STUFE=" . max(1, min(4, intval($_POST['min_stufe'] ?? 1))). "\n\n";

    $c .= "[TAWES]\n";
    $c .= "ENABLED={$tawes_en}\n";
    $c .= "MAX_DISTANCE_KM="      . max(20,  min(150, intval($_POST['tawes_max_km']             ?? 120))) . "\n";
    $c .= "MAX_STATIONS="         . max(5,   min(50,  intval($_POST['tawes_max_stations']        ?? 25)))  . "\n";
    $c .= "MIN_ALARM_PROZENT="    . max(10,  min(100, intval($_POST['tawes_min_alarm_prozent']   ?? 30))) . "\n";
    $c .= "MAX_UPSTREAM_HOEHE_M=" . max(0,   min(3000,intval($_POST['tawes_max_upstream_hoehe'] ?? 1200))). "\n";
    $c .= "REGEN_LOKAL_KM="       . max(5,   min(100, intval($_POST['tawes_regen_lokal_km']     ?? 25)))  . "\n";
    $c .= "UPSTREAM_WINKEL_GRAD=" . max(20,  min(90,  intval($_POST['tawes_upstream_winkel']    ?? 45)))  . "\n";

    if (file_put_contents($cfgfile, $c) !== false) {
        $saved  = true;
        $cfg    = parse_ini_file($cfgfile, true) ?: [];
        $use_lb = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
    } else {
        $err = $L['MAIN.SAVE_ERR'];
    }
}

function v($s, $k, $d = '') { global $cfg; return htmlspecialchars($cfg[$s][$k] ?? $d); }

LBWeb::lbheader($L['MAIN.TITLE'] . " – " . $L['MAIN.SETTINGS'], "https://wiki.loxberry.de", "");
?>

<?php if ($saved): ?>
<div class="ui-body ui-body-b" style="background:#16a34a;text-align:center;margin:8px 0;color:white;text-shadow:none">✅ <?= $L['MAIN.SAVED'] ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="ui-body ui-body-e" style="margin:8px 0">❌ <?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<form method="POST">

<!-- STANDORT -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>📍 <?= $L['MAIN.LOCATION'] ?> & Geocoding</h3>
<div class="ui-field-contain">
    <label for="addr_search"><b><?= $L['MAIN.ADDR_SEARCH'] ?></b></label>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
        <input type="text" id="addr_search" placeholder="<?= $L['MAIN.ADDR_PLACEHOLDER'] ?>" style="flex:1; min-width:150px">
        <button type="button" id="btn_geocode" data-role="button" data-icon="search" data-mini="true" data-inline="true"><?= $L['MAIN.GEOCODE_BTN'] ?></button>
        <button type="button" id="btn_miniserver" data-role="button" data-icon="home" data-mini="true" data-inline="true"><?= $L['MAIN.FROM_MINISERVER'] ?></button>
    </div>
    <div id="ms_status" style="display:none; font-size:11px; margin:4px 0; padding:4px 8px; background:#f0f7ff; border-radius:3px"></div>
    <p style="font-size:10px; color:#888; margin:4px 0">Koordinaten via OpenStreetMap suchen, oder direkt vom verbundenen Loxone Miniserver übernehmen.</p>
</div>

<hr style="opacity:0.2">

<div class="ui-field-contain">
    <label for="name">Bezeichnung</label>
    <input type="text" id="name" name="name" value="<?= v('LOCATION','NAME','Mein Zuhause') ?>" placeholder="z.B. Zuhause Linz">
</div>
<div class="ui-field-contain">
    <label for="lat">Breitengrad (Latitude)</label>
    <input type="number" id="lat" name="lat" step="0.000001" value="<?= v('LOCATION','LAT','') ?>" placeholder="z.B. 48.3069">
</div>
<div class="ui-field-contain">
    <label for="lon">Längengrad (Longitude)</label>
    <input type="number" id="lon" name="lon" step="0.000001" value="<?= v('LOCATION','LON','') ?>" placeholder="z.B. 14.2858">
</div>
</div>

<script>
$(function(){
    $('#btn_geocode').on('click', function(){
        var q = $('#addr_search').val();
        if(!q) return;
        $(this).addClass('ui-disabled');
        $.getJSON('ajax.php?action=geocode&q=' + encodeURIComponent(q), function(data) {
            $('#btn_geocode').removeClass('ui-disabled');
            if(data.error) {
                alert('Fehler: ' + data.error);
            } else {
                $('#lat').val(parseFloat(data.lat).toFixed(6));
                $('#lon').val(parseFloat(data.lon).toFixed(6));
                alert('<?= $L['MAIN.GEOCODE_SUCCESS'] ?>');
            }
        });
    });

    $('#btn_miniserver').on('click', function(){
        $(this).addClass('ui-disabled');
        $('#ms_status').text('<?= addslashes($L['MAIN.MINISERVER_LOADING']) ?>').show();
        $.getJSON('ajax.php?action=get_miniserver_coords', function(data) {
            $('#btn_miniserver').removeClass('ui-disabled');
            if(data.error) {
                $('#ms_status').html('❌ ' + data.error);
                // Adressfeld nur befüllen wenn es ein echter Ortsname ist (kein generischer Fehler)
                if(data.suggestion && data.suggestion.length > 5 && data.suggestion !== 'Miniserver') {
                    $('#addr_search').val(data.suggestion);
                }
            } else {
                $('#lat').val(parseFloat(data.lat).toFixed(6));
                $('#lon').val(parseFloat(data.lon).toFixed(6));
                $('#ms_status').html('✅ ' + data.display_name.split(',')[0] + ' (Quelle: ' + data.source + ')');
            }
        }).fail(function(){
            $('#btn_miniserver').removeClass('ui-disabled');
            $('#ms_status').text('❌ Verbindungsfehler');
        });
    });
});
</script>

<!-- API SERVICES -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>🔌 API Services</h3>
<div class="ui-field-contain">
    <label for="zamg_enabled">GeoSphere Austria (ZAMG) abrufen</label>
    <select id="zamg_enabled" name="zamg_enabled" data-role="flipswitch">
        <option value="1" <?= ($cfg['ZAMG']['ENABLED']  ?? '1') == '1' ? 'selected' : '' ?>>Ein</option>
        <option value="0" <?= ($cfg['ZAMG']['ENABLED']  ?? '1') == '0' ? 'selected' : '' ?>>Aus</option>
    </select>
</div>
<div class="ui-field-contain">
    <label for="inca_enabled"><?= $L['MAIN.INCA_ENABLED'] ?></label>
    <select id="inca_enabled" name="inca_enabled" data-role="flipswitch">
        <option value="1" <?= ($cfg['INCA']['ENABLED']  ?? '1') == '1' ? 'selected' : '' ?>>Ein</option>
        <option value="0" <?= ($cfg['INCA']['ENABLED']  ?? '1') == '0' ? 'selected' : '' ?>>Aus</option>
    </select>
</div>
<div class="ui-field-contain">
    <label for="tawes_enabled"><?= $L['MAIN.TAWES_ENABLED'] ?></label>
    <select id="tawes_enabled" name="tawes_enabled" data-role="flipswitch">
        <option value="1" <?= ($cfg['TAWES']['ENABLED'] ?? '1') == '1' ? 'selected' : '' ?>>Ein</option>
        <option value="0" <?= ($cfg['TAWES']['ENABLED'] ?? '1') == '0' ? 'selected' : '' ?>>Aus</option>
    </select>
</div>
<p style="font-size:11px; color:#888">Hier kannst du einzelne Wetter-Dienste deaktivieren.</p>
</div>

<!-- TAWES 360° -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>🌐 TAWES 360°</h3>
<?php
$tawes_cache = $lbpdatadir . '/tawes_stations.json';
$st_count    = 0;
if (file_exists($tawes_cache)) {
    $sts = json_decode(file_get_contents($tawes_cache), true);
    $st_count = is_array($sts) ? count($sts) : 0;
}
?>
<?php
// Hilfsfunktion: aktuellen Wert aus Config, mit Fallback
function tvCfg($k, $d) { global $cfg; return htmlspecialchars($cfg['TAWES'][$k] ?? $d); }
?>
<style>
.slider-row { margin: 8px 0 2px 0; }
.slider-val { display:inline-block; background:#1a6ca8; color:#fff; font-weight:bold;
              border-radius:3px; padding:1px 7px; font-size:13px; min-width:38px; text-align:center; }
</style>

<div class="slider-row">
<label for="tawes_max_km"><?= $L['MAIN.TAWES_MAX_KM'] ?> <span class="slider-val" id="tkm"><?= tvCfg('MAX_DISTANCE_KM','120') ?></span> km</label>
<input type="range" id="tawes_max_km" name="tawes_max_km" min="20" max="150" step="10"
       value="<?= tvCfg('MAX_DISTANCE_KM','120') ?>"
       oninput="document.getElementById('tkm').textContent=this.value">
</div>

<div class="slider-row">
<label for="tawes_max_stations">Max. Stationen (API) <span class="slider-val" id="tms"><?= tvCfg('MAX_STATIONS','25') ?></span></label>
<input type="range" id="tawes_max_stations" name="tawes_max_stations" min="5" max="50" step="5"
       value="<?= tvCfg('MAX_STATIONS','25') ?>"
       oninput="document.getElementById('tms').textContent=this.value">
<p style="font-size:11px;color:#888;margin:2px 0">Anzahl der nächstgelegenen Stationen die pro Zyklus von der API abgefragt werden. Mehr = genauerer Konsens, aber mehr Netzlast.</p>
</div>

<div class="slider-row">
<label for="tawes_upstream_winkel">Upstream-Kegel (Halbwinkel) <span class="slider-val" id="tuw"><?= tvCfg('UPSTREAM_WINKEL_GRAD','45') ?></span>° → Gesamtkegel: <span id="tuw2"><?= intval(tvCfg('UPSTREAM_WINKEL_GRAD','45')) * 2 ?></span>°</label>
<input type="range" id="tawes_upstream_winkel" name="tawes_upstream_winkel" min="20" max="90" step="5"
       value="<?= tvCfg('UPSTREAM_WINKEL_GRAD','45') ?>"
       oninput="document.getElementById('tuw').textContent=this.value; document.getElementById('tuw2').textContent=this.value*2">
<p style="font-size:11px;color:#888;margin:2px 0">
Wie weit links/rechts von der Windrichtung eine Station als „Upstream" gilt. <b>45° (Standard)</b> = 90° Gesamtkegel = nur Stationen nahe der Windrichtung.
70° (alt) = 140° Gesamtkegel = zu breit, SW und NW können beide upstream sein.
<br>Kleiner Winkel = präziser aber weniger Stationen. Empfehlung: <b>30°–50°</b>.
</p>
</div>

<div class="slider-row">
<label for="tawes_min_alarm_prozent">Konsens-Schwelle (Alarm) <span class="slider-val" id="tmap"><?= tvCfg('MIN_ALARM_PROZENT','30') ?></span>%</label>
<input type="range" id="tawes_min_alarm_prozent" name="tawes_min_alarm_prozent" min="10" max="100" step="10"
       value="<?= tvCfg('MIN_ALARM_PROZENT','30') ?>"
       oninput="document.getElementById('tmap').textContent=this.value">
<p style="font-size:11px;color:#888;margin:2px 0">Mindestanteil der Upstream-Stationen die den Schwellwert überschreiten müssen. Verhindert False-Positives durch einzelne Ausreißer.</p>
</div>

<div class="slider-row">
<label for="tawes_max_upstream_hoehe">Max. Seehöhe Upstream (Wind-Alarm) <span class="slider-val" id="tmueh"><?= tvCfg('MAX_UPSTREAM_HOEHE_M','1200') ?></span> m</label>
<input type="range" id="tawes_max_upstream_hoehe" name="tawes_max_upstream_hoehe" min="0" max="3000" step="100"
       value="<?= tvCfg('MAX_UPSTREAM_HOEHE_M','1200') ?>"
       oninput="document.getElementById('tmueh').textContent=this.value">
<p style="font-size:11px;color:#888;margin:2px 0">Upstream-Stationen über dieser Seehöhe werden aus dem Wind-Alarm-Konsens ausgeschlossen (z.B. Feuerkogel 1618m, Schafberg 1783m). 0 = alle einbeziehen.</p>
</div>

<div class="slider-row">
<label for="tawes_regen_lokal_km">Lokal-Regen Umkreis <span class="slider-val" id="trlkm"><?= tvCfg('REGEN_LOKAL_KM','25') ?></span> km</label>
<input type="range" id="tawes_regen_lokal_km" name="tawes_regen_lokal_km" min="5" max="50" step="5"
       value="<?= tvCfg('REGEN_LOKAL_KM','25') ?>"
       oninput="document.getElementById('trlkm').textContent=this.value">
<p style="font-size:11px;color:#888;margin:2px 0">Umkreis für lokalen Regen-Alarm (unabhängig von Windrichtung). Standard: 25 km.</p>
</div>
<p style="font-size:11px;color:#888">
    Stations-Cache: <b><?= $st_count ?></b> Stationen geladen.
    <?= $st_count ? '(tawes_stations.json vorhanden)' : '(wird beim ersten Daemon-Start geladen)' ?>
</p>
<a href="ajax.php?action=reload_stations" id="btn_reload_st" data-role="button" data-inline="true" data-mini="true" data-icon="refresh"><?= $L['MAIN.TAWES_RELOAD_STATIONS'] ?></a>
<div id="reload_msg" style="display:none;font-size:12px;margin-top:6px"></div>
<script>
$(function(){
    $('#btn_reload_st').on('click', function(e){
        e.preventDefault();
        var $btn = $(this).addClass('ui-disabled');
        var $msg = $('#reload_msg').css('color','#aaa').text('⟳ Cache gelöscht, Daemon startet neu…').show();
        $.getJSON('ajax.php?action=reload_stations', function(d){
            if (d.restart) {
                // Auf Daemon-Start warten, dann Seite neu laden
                var tries = 0;
                var poll = setInterval(function(){
                    tries++;
                    $.getJSON('ajax.php?action=check_update', function(s){
                        if (s.running) {
                            clearInterval(poll);
                            $msg.css('color','#4CAF50').text('✓ Daemon neugestartet – Seite wird geladen…');
                            setTimeout(function(){ location.reload(); }, 800);
                        }
                    });
                    if (tries >= 20) { clearInterval(poll); $btn.removeClass('ui-disabled'); $msg.css('color','#f97316').text('⚠ Timeout – bitte Seite manuell neu laden.'); }
                }, 3000);
            } else {
                $btn.removeClass('ui-disabled');
                $msg.css('color','#4CAF50').text(d.msg || '<?= addslashes($L['MAIN.TAWES_RELOAD_OK']) ?>');
            }
        });
    });
});
</script>
</div>

<!-- MQTT -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>📡 <?= $L['MAIN.MQTT_BROKER'] ?></h3>
<div class="ui-field-contain">
    <label for="use_lb_mqtt"><?= $L['MAIN.MQTT_AUTO'] ?></label>
    <select id="use_lb_mqtt" name="use_lb_mqtt" data-role="flipswitch">
        <option value="1" <?= $use_lb  ? 'selected' : '' ?>>Ein</option>
        <option value="0" <?= !$use_lb ? 'selected' : '' ?>>Aus</option>
    </select>
</div>

<div id="mqtt_manual" <?= $use_lb ? 'style="display:none"' : '' ?>>
<div class="ui-field-contain">
    <label for="broker">Broker IP / Hostname</label>
    <input type="text" id="broker" name="broker" value="<?= v('MQTT','BROKER','127.0.0.1') ?>">
</div>
<div class="ui-field-contain">
    <label for="port">Port</label>
    <input type="number" id="port" name="port" value="<?= v('MQTT','PORT','1883') ?>">
</div>
<div class="ui-field-contain">
    <label for="mqtt_user">Benutzername (optional)</label>
    <input type="text" id="mqtt_user" name="mqtt_user" value="<?= v('MQTT','USER','') ?>">
</div>
<div class="ui-field-contain">
    <label for="mqtt_pass">Passwort (optional)</label>
    <input type="password" id="mqtt_pass" name="mqtt_pass" value="<?= v('MQTT','PASS','') ?>">
</div>
</div>

<div class="ui-field-contain">
    <label for="topic_prefix">MQTT Topic Prefix</label>
    <input type="text" id="topic_prefix" name="topic_prefix" value="<?= v('MQTT','TOPIC_PREFIX','unwetter') ?>">
</div>
</div>

<!-- INTERVALL & SCHWELLEN -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>⚙️ <?= $L['MAIN.INTERVAL'] ?> & Alarmschwellen</h3>
<div class="slider-row">
<label for="interval"><?= $L['MAIN.INTERVAL'] ?> <span class="slider-val" id="siv"><?= v('SCHEDULE','INTERVAL','300') ?></span> s</label>
<input type="range" id="interval" name="interval" min="60" max="900" step="30"
       value="<?= v('SCHEDULE','INTERVAL','300') ?>"
       oninput="document.getElementById('siv').textContent=this.value">
<p style="font-size:10px;color:#888;margin:2px 0">Wie oft der Daemon die Wetter-APIs abfragt (Sekunden). TAWES wird immer nur alle 10 Minuten abgerufen.</p>
</div>

<div class="slider-row">
<label for="inca_horizon"><?= $L['MAIN.HORIZON'] ?> <span class="slider-val" id="sih"><?= v('INCA','HORIZON_MINUTES','60') ?></span> min</label>
<input type="range" id="inca_horizon" name="inca_horizon" min="15" max="60" step="15"
       value="<?= v('INCA','HORIZON_MINUTES','60') ?>"
       oninput="document.getElementById('sih').textContent=this.value">
<p style="font-size:10px;color:#888;margin:2px 0">Wie weit der INCA Nowcast vorausschaut (15–60 min). 60 min empfohlen.</p>
</div>

<div class="slider-row">
<label for="boen_alarm"><?= $L['MAIN.BOEN_ALARM'] ?> <span class="slider-val" id="sba"><?= v('THRESHOLDS','BOEN_ALARM','60') ?></span> km/h</label>
<input type="range" id="boen_alarm" name="boen_alarm" min="20" max="120" step="5"
       value="<?= v('THRESHOLDS','BOEN_ALARM','60') ?>"
       oninput="document.getElementById('sba').textContent=this.value">
<p style="font-size:10px;color:#888;margin:2px 0">Ab welcher Böenstärke ein Wind-Alarm ausgelöst wird. 60 km/h = Beaufort 8 (Sturm). INCA allein → max Stufe 1; INCA+TAWES → voller Alarm.</p>
</div>

<div class="slider-row">
<label for="regen_alarm"><?= $L['MAIN.REGEN_ALARM'] ?> <span class="slider-val" id="sra"><?= v('THRESHOLDS','REGEN_ALARM','10.0') ?></span> mm/h</label>
<input type="range" id="regen_alarm" name="regen_alarm" min="0.5" max="60" step="0.5"
       value="<?= v('THRESHOLDS','REGEN_ALARM','10.0') ?>"
       oninput="document.getElementById('sra').textContent=this.value">
<p style="font-size:10px;color:#888;margin:2px 0">Ab welcher Regenrate ein Alarm ausgelöst wird. INCA allein → max Stufe 1; INCA+TAWES → voller Alarm. 10 mm/h = starker Regen.</p>
</div>
</div>

<!-- NOTIFICATIONS -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>🔔 Notifications</h3>
<div class="ui-field-contain">
    <label for="min_stufe"><?= $L['MAIN.MIN_STUFE'] ?></label>
    <select id="min_stufe" name="min_stufe" data-native-menu="false">
        <option value="1" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '1' ? 'selected' : '' ?>><?= $L['MAIN.STUFE_1'] ?></option>
        <option value="2" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '2' ? 'selected' : '' ?>><?= $L['MAIN.STUFE_2'] ?></option>
        <option value="3" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '3' ? 'selected' : '' ?>><?= $L['MAIN.STUFE_3'] ?></option>
    </select>
</div>
</div>

<div style="padding:8px 0 20px">
    <input type="submit" value="💾  <?= $L['MAIN.SAVE'] ?>" data-theme="b"
           class="ui-btn ui-btn-b ui-corner-all">
</div>
</form>

<script>
$(function(){
    function toggleMqtt(v) { $('#mqtt_manual')[v==='1'?'hide':'show'](); }
    toggleMqtt($('#use_lb_mqtt').val());
    $('#use_lb_mqtt').on('change', function(){ toggleMqtt($(this).val()); });
});
</script>

<?php LBWeb::lbfooter(); ?>
