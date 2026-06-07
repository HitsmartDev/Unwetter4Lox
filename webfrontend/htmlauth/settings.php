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
    $c .= "[THRESHOLDS]\nBOEN_ALARM=" . floatval($_POST['boen_alarm'] ?? 60)              . "\n\n";
    $c .= "[NOTIFICATIONS]\nMIN_STUFE=" . max(1, min(4, intval($_POST['min_stufe'] ?? 1))). "\n\n";

    $c .= "[TAWES]\n";
    $c .= "ENABLED={$tawes_en}\n";
    $c .= "MAX_DISTANCE_KM=" . max(20, min(150, intval($_POST['tawes_max_km'] ?? 120))) . "\n";

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
    <div style="display:flex; gap:10px">
        <input type="text" id="addr_search" placeholder="<?= $L['MAIN.ADDR_PLACEHOLDER'] ?>" style="flex-grow:2">
        <button type="button" id="btn_geocode" data-role="button" data-icon="search" data-mini="true" data-inline="true"><?= $L['MAIN.GEOCODE_BTN'] ?></button>
    </div>
    <p style="font-size:10px; color:#888; margin:4px 0">Sucht via OpenStreetMap automatisch nach Koordinaten.</p>
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
                // Bezeichnung wird NICHT ueberschrieben (Anforderung 2)
                alert('<?= $L['MAIN.GEOCODE_SUCCESS'] ?>');
            }
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
<div class="ui-field-contain">
    <label for="tawes_max_km"><?= $L['MAIN.TAWES_MAX_KM'] ?>: <span id="tkm"><?= htmlspecialchars($cfg['TAWES']['MAX_DISTANCE_KM'] ?? '120') ?></span> km</label>
    <input type="range" id="tawes_max_km" name="tawes_max_km" min="20" max="150" step="10"
           value="<?= htmlspecialchars($cfg['TAWES']['MAX_DISTANCE_KM'] ?? '120') ?>"
           oninput="document.getElementById('tkm').textContent=this.value">
</div>
<p style="font-size:11px;color:#888">
    Stations-Cache: <b><?= $st_count ?></b> Stationen geladen.
    <?= $st_count ? '(tawes_stations.json vorhanden)' : '(wird beim ersten Daemon-Start geladen)' ?>
</p>
<a href="ajax.php?action=reload_stations" id="btn_reload_st" data-role="button" data-inline="true" data-mini="true" data-icon="refresh"><?= $L['MAIN.TAWES_RELOAD_STATIONS'] ?></a>
<div id="reload_msg" style="display:none;color:#4CAF50;font-size:12px;margin-top:6px"></div>
<script>
$(function(){
    $('#btn_reload_st').on('click', function(e){
        e.preventDefault();
        $(this).addClass('ui-disabled');
        $.getJSON('ajax.php?action=reload_stations', function(d){
            $('#btn_reload_st').removeClass('ui-disabled');
            $('#reload_msg').text(d.msg || '<?= addslashes($L['MAIN.TAWES_RELOAD_OK']) ?>').show();
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
<h3>⚙️ <?= $L['MAIN.INTERVAL'] ?> & Schwellen</h3>
<div class="ui-field-contain">
    <label for="interval">Sekunden: <span id="iv"><?= v('SCHEDULE','INTERVAL','300') ?></span>s</label>
    <input type="range" id="interval" name="interval" min="60" max="900" step="30"
           value="<?= v('SCHEDULE','INTERVAL','300') ?>"
           oninput="document.getElementById('iv').textContent=this.value">
</div>
<div class="ui-field-contain">
    <label for="inca_horizon"><?= $L['MAIN.HORIZON'] ?>: <span id="ih"><?= v('INCA','HORIZON_MINUTES','60') ?></span> min</label>
    <input type="range" id="inca_horizon" name="inca_horizon" min="15" max="60" step="15"
           value="<?= v('INCA','HORIZON_MINUTES','60') ?>"
           oninput="document.getElementById('ih').textContent=this.value">
</div>
<div class="ui-field-contain">
    <label for="boen_alarm"><?= $L['MAIN.BOEN_ALARM'] ?>: <span id="ba"><?= v('THRESHOLDS','BOEN_ALARM','60') ?></span> km/h</label>
    <input type="range" id="boen_alarm" name="boen_alarm" min="20" max="120" step="5"
           value="<?= v('THRESHOLDS','BOEN_ALARM','60') ?>"
           oninput="document.getElementById('ba').textContent=this.value">
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
