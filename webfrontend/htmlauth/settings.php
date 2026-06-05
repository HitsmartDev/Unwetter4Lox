<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_io.php";

$L = LBSystem::readlanguage("language.ini");

$navbar[1]['Name'] = "Status";
$navbar[1]['URL']  = "index.php";
$navbar[2]['Name'] = "Einstellungen";
$navbar[2]['URL']  = "settings.php";
$navbar[2]['active'] = true;
$navbar[3]['Name'] = "Log";
$navbar[3]['URL']  = "log.php";

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
    $c .= "[SCHEDULE]\nINTERVAL=" . max(60, intval($_POST['interval'] ?? 300))            . "\n\n";
    $c .= "[THRESHOLDS]\nBOEN_ALARM=" . floatval($_POST['boen_alarm'] ?? 60)              . "\n\n";
    $c .= "[INCA]\n";
    $c .= "ENABLED="         . (isset($_POST['inca_enabled'])  ? '1' : '0')              . "\n";
    $c .= "HORIZON_MINUTES=" . max(15, min(60, intval($_POST['inca_horizon'] ?? 60)))     . "\n\n";
    $c .= "[NOTIFICATIONS]\nMIN_STUFE=" . max(1, min(4, intval($_POST['min_stufe'] ?? 1))). "\n";

    if (file_put_contents($cfgfile, $c) !== false) {
        $saved  = true;
        $cfg    = parse_ini_file($cfgfile, true) ?: [];
        $use_lb = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
    } else {
        $err = 'Fehler beim Speichern – Berechtigungen prüfen.';
    }
}

function v($s, $k, $d = '') { global $cfg; return htmlspecialchars($cfg[$s][$k] ?? $d); }

LBWeb::lbheader("Unwetter4Lox – Einstellungen", "https://wiki.loxberry.de", "");
?>

<?php if ($saved): ?>
<div class="ui-body ui-body-b" style="background:#16a34a;text-align:center;margin:8px 0">✅ Einstellungen gespeichert – Daemon neu starten für Änderungen</div>
<?php endif; ?>
<?php if ($err): ?>
<div class="ui-body ui-body-e" style="margin:8px 0">❌ <?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<form method="POST">

<!-- STANDORT -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>📍 Standort</h3>
<div class="ui-field-contain">
    <label for="name">Bezeichnung</label>
    <input type="text" id="name" name="name" value="<?= v('LOCATION','NAME','Mein Zuhause') ?>" placeholder="z.B. Zuhause Linz">
</div>
<div class="ui-field-contain">
    <label for="lat">Breitengrad (Latitude)</label>
    <input type="number" id="lat" name="lat" step="0.000001" value="<?= v('LOCATION','LAT','47.952835') ?>">
</div>
<div class="ui-field-contain">
    <label for="lon">Längengrad (Longitude)</label>
    <input type="number" id="lon" name="lon" step="0.000001" value="<?= v('LOCATION','LON','13.791286') ?>">
</div>
</div>

<!-- MQTT -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>📡 MQTT Broker</h3>
<div class="ui-field-contain">
    <label for="use_lb_mqtt">LoxBerry MQTT Gateway automatisch</label>
    <select id="use_lb_mqtt" name="use_lb_mqtt" data-role="flipswitch">
        <option value="1" <?= $use_lb  ? 'selected' : '' ?>>Ein</option>
        <option value="0" <?= !$use_lb ? 'selected' : '' ?>>Aus</option>
    </select>
</div>

<?php if ($mqttcred): ?>
<p style="font-size:12px;color:#888;margin:2px 0 8px">
    ✅ LoxBerry MQTT Gateway erkannt:<br>
    <b><?= htmlspecialchars($mqttcred['brokerhost']) ?></b>:<?= htmlspecialchars($mqttcred['brokerport']) ?>
    <?php if (!empty($mqttcred['brokeruser'])): ?>
    &nbsp;· User: <b><?= htmlspecialchars($mqttcred['brokeruser']) ?></b>
    <?php endif; ?>
</p>
<?php else: ?>
<p style="font-size:12px;color:#f97316;margin:2px 0 8px">
    ⚠️ LoxBerry MQTT Gateway nicht gefunden – manuelle Konfiguration erforderlich
</p>
<?php endif; ?>

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
    <input type="text" id="topic_prefix" name="topic_prefix" value="<?= v('MQTT','TOPIC_PREFIX','haus/wetter') ?>">
</div>
<p style="font-size:11px;color:#666;margin:2px 0 4px">Beispiel: <code>unwetter/warnung/gewitter/stufe</code></p>
</div>

<!-- INTERVALL -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>⏱️ Abfrageintervall</h3>
<div class="ui-field-contain">
    <label for="interval">Sekunden: <span id="iv"><?= v('SCHEDULE','INTERVAL','300') ?></span>s</label>
    <input type="range" id="interval" name="interval" min="60" max="900" step="30"
           value="<?= v('SCHEDULE','INTERVAL','300') ?>"
           oninput="document.getElementById('iv').textContent=this.value">
</div>
</div>

<!-- INCA -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>📊 INCA Nowcast</h3>
<div class="ui-field-contain">
    <label for="inca_enabled">INCA Nowcast aktivieren</label>
    <select id="inca_enabled" name="inca_enabled" data-role="flipswitch">
        <option value="1" <?= ($cfg['INCA']['ENABLED']  ?? '1') == '1' ? 'selected' : '' ?>>Ein</option>
        <option value="0" <?= ($cfg['INCA']['ENABLED']  ?? '1') == '0' ? 'selected' : '' ?>>Aus</option>
    </select>
</div>
<div class="ui-field-contain">
    <label for="inca_horizon">Zeithorizont: <span id="ih"><?= v('INCA','HORIZON_MINUTES','60') ?></span> min</label>
    <input type="range" id="inca_horizon" name="inca_horizon" min="15" max="60" step="15"
           value="<?= v('INCA','HORIZON_MINUTES','60') ?>"
           oninput="document.getElementById('ih').textContent=this.value">
</div>
<div class="ui-field-contain">
    <label for="boen_alarm">Böen-Alarmschwelle: <span id="ba"><?= v('THRESHOLDS','BOEN_ALARM','60') ?></span> km/h</label>
    <input type="range" id="boen_alarm" name="boen_alarm" min="20" max="120" step="5"
           value="<?= v('THRESHOLDS','BOEN_ALARM','60') ?>"
           oninput="document.getElementById('ba').textContent=this.value">
</div>
</div>

<!-- NOTIFICATIONS -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>🔔 Notifications</h3>
<div class="ui-field-contain">
    <label for="min_stufe">Mindeststufe für Notification-Text</label>
    <select id="min_stufe" name="min_stufe" data-native-menu="false">
        <option value="1" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '1' ? 'selected' : '' ?>>1 – Gelb (alle)</option>
        <option value="2" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '2' ? 'selected' : '' ?>>2 – Orange und höher</option>
        <option value="3" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '3' ? 'selected' : '' ?>>3 – Nur Rot</option>
    </select>
</div>
</div>

<div style="padding:8px 0 20px">
    <input type="submit" value="💾  Einstellungen speichern" data-theme="b"
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
