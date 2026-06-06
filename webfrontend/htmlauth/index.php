<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_io.php";

$L = LBSystem::readlanguage("language.ini");

$navbar[1]['Name']   = $L['MAIN.STATUS'];
$navbar[1]['URL']    = "index.php";
$navbar[1]['active'] = true;
$navbar[2]['Name']   = $L['MAIN.SETTINGS'];
$navbar[2]['URL']    = "settings.php";
$navbar[3]['Name']   = $L['MAIN.LOG'];
$navbar[3]['URL']    = "log.php";
$navbar[4]['Name']   = $L['MAIN.HELP'];
$navbar[4]['URL']    = "help.php";

# State laden
$state   = [];
$sf      = $lbpdatadir . "/state.json";
if (file_exists($sf)) $state = json_decode(file_get_contents($sf), true) ?? [];

$cfg     = parse_ini_file($lbpconfigdir . "/unwetter4lox.cfg", true) ?: [];
$lat     = $cfg['LOCATION']['LAT'] ?? '';
$lon     = $cfg['LOCATION']['LON'] ?? '';
$coords_set = ($lat !== '' && $lon !== '');

# Daemon-Status
$pidfile        = $lbplogdir . "/daemon.pid";
$pid            = file_exists($pidfile) ? trim(file_get_contents($pidfile)) : '';
$daemon_running = $pid && file_exists("/proc/{$pid}");

# MQTT Broker-Info
$use_lb_mqtt = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
$mqttcred    = null;
if ($use_lb_mqtt && function_exists('mqtt_connectiondetails')) {
    $mqttcred = mqtt_connectiondetails();
}
$mqtt_display = $mqttcred
    ? htmlspecialchars($mqttcred['brokerhost'] . ':' . $mqttcred['brokerport'])
    : htmlspecialchars(($cfg['MQTT']['BROKER'] ?? '?') . ':' . ($cfg['MQTT']['PORT'] ?? '1883'));

# Hilfsfunktionen
function st_color($s) { return [0=>"#4CAF50",1=>"#FFEB3B",2=>"#FF9800",3=>"#f44336",4=>"#9C27B0"][(int)$s]??'#777'; }
function st_name($s) { global $L; return [0=>$L['MAIN.NO_WARNS'],1=>"GELB",2=>"ORANGE",3=>"ROT",4=>"LILA"][(int)$s]??'–'; }

$akut  = $state['akutwarnung']     ?? 0;
$irdw  = $state['irgendwas_aktiv'] ?? 0;
$maxst = (int)($state['max_stufe'] ?? 0);
$inca  = $state['inca']            ?? [];
$zamg  = $state['zamg']            ?? [];
$status = $state['status']         ?? 'OK';

LBWeb::lbheader($L['MAIN.TITLE'], "https://wiki.loxberry.de", "");
?>

<style>
    .status-tile { text-align:center; padding:15px; border-radius:8px; margin-bottom:10px; color:white; text-shadow:none; }
    .warn-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin: 15px 0; }
    .warn-card { background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; padding: 10px; text-align: center; }
    .warn-card.active { border-width: 2px; }
    .st-0 { border-top: 4px solid #4CAF50; }
    .st-1 { border-top: 4px solid #FFEB3B; }
    .st-2 { border-top: 4px solid #FF9800; }
    .st-3 { border-top: 4px solid #f44336; }
    .st-4 { border-top: 4px solid #9C27B0; }
    .status-badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }
    .badge-ok { background: #4CAF50; color: white; }
    .badge-err { background: #f44336; color: white; }
</style>

<!-- Fehlende Konfiguration -->
<?php if (!$coords_set): ?>
<div class="ui-body ui-body-b" style="background:#f44336; color:white; text-shadow:none; text-align:center; border-radius:8px; margin-bottom:15px;">
    <p><b>🚨 <?= $L['MAIN.NOT_CONFIGURED'] ?></b><br><?= $L['MAIN.NOT_CONFIGURED_TXT'] ?></p>
    <a href="settings.php" class="ui-btn ui-btn-inline ui-mini ui-corner-all"><?= $L['MAIN.TO_SETTINGS'] ?></a>
</div>
<?php endif; ?>

<!-- Daemon Steuerung -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>🔧 <?= $L['MAIN.TITLE'] ?> <?= $L['MAIN.STATUS'] ?></h3>
<div style="display:flex; justify-content: space-between; align-items: center; flex-wrap:wrap; gap:10px">
    <div>
        <span style="font-size:16px; font-weight:bold; color:<?= $daemon_running ? '#4CAF50' : '#f44336' ?>">
            ● Daemon <?= $daemon_running ? $L['MAIN.DAEMON_RUNNING'] : $L['MAIN.DAEMON_STOPPED'] ?>
        </span>
        <p style="font-size:12px;color:#888;margin:4px 0 0">
            <?= $L['MAIN.LAST_UPDATE'] ?>: <b><?= htmlspecialchars($state['letztes_update'] ?? '–') ?></b>
            &nbsp; | &nbsp; Status: <span class="status-badge <?= strpos($status,'Error')!==false?'badge-err':'badge-ok' ?>"><?= htmlspecialchars($status) ?></span>
        </p>
    </div>
    <div style="display:flex;gap:8px">
        <?php if ($daemon_running): ?>
            <a href="ajax.php?action=restart" data-role="button" data-inline="true" data-mini="true" data-theme="a">↺ Restart</a>
            <a href="ajax.php?action=stop"    data-role="button" data-inline="true" data-mini="true" data-theme="b">■ Stop</a>
        <?php else: ?>
            <a href="ajax.php?action=start"   data-role="button" data-inline="true" data-mini="true" data-theme="b" <?= !$coords_set ? 'class="ui-disabled"' : '' ?>>▶ Start</a>
        <?php endif; ?>
        <?php
        $_ptr  = $lbplogdir . '/daemon.log.current';
        $_clog = file_exists($_ptr) ? trim(file_get_contents($_ptr)) : null;
        if ($_clog && !file_exists($_clog)) $_clog = null;
        if (!$_clog) {
            $_logs = glob($lbplogdir . '/*.log') ?: [];
            if ($_logs) { usort($_logs, function($a,$b){ return filemtime($b)-filemtime($a); }); $_clog = $_logs[0]; }
        }
        $_lp = ["PACKAGE" => $lbpplugindir, "NAME" => "Daemon", "LABEL" => $L['MAIN.LOG_VIEWER'], "CLASS" => "ui-btn-inline ui-mini"];
        if ($_clog) $_lp['LOGFILE'] = $_clog;
        echo LBWeb::logfile_button_html($_lp);
        ?>
    </div>
</div>
</div>

<!-- Warnlage Kachel -->
<?php 
    $bg = $akut ? '#f44336' : ($irdw ? '#f97316' : '#4CAF50');
    $txt = $akut ? $L['MAIN.AKUT_WARN'] : ($irdw ? $L['MAIN.WETTER_WARN'] : $L['MAIN.NO_WARNS']);
?>
<div class="status-tile" style="background:<?= $bg ?>;">
    <h2 style="margin:0"><?= $txt ?></h2>
    <p style="margin:5px 0 0; opacity:0.9"><?= $L['MAIN.MIN_STUFE'] ?>: <b><?= st_name($maxst) ?></b></p>
</div>

<!-- GeoSphere Warnungen Grid -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>🌩️ <?= $L['MAIN.GEO_WARNS'] ?></h3>
<div class="warn-grid">
    <?php
    $typen = ['wind','regen','schnee','glatteis','gewitter','hagel','hitze','kaelte'];
    $lbls  = ['wind'=>$L['wind'],'regen'=>$L['regen'],'schnee'=>$L['schnee'],'glatteis'=>$L['glatteis'],
              'gewitter'=>$L['gewitter'],'hagel'=>$L['hagel'],'hitze'=>$L['hitze'],'kaelte'=>$L['kaelte']];
    foreach ($typen as $t):
        $w = $zamg[$t] ?? []; $s = (int)($w['stufe'] ?? 0);
        $active = ($w['aktiv'] ?? 0) || ($w['bald'] ?? 0);
    ?>
    <div class="warn-card st-<?= $s ?> <?= $active ? 'active' : '' ?>">
        <div style="font-weight:bold; font-size:12px"><?= $lbls[$t] ?></div>
        <div style="font-size:11px; color:<?= st_color($s) ?>; font-weight:bold"><?= st_name($s) ?></div>
        <?php if ($active): ?>
            <div style="font-size:10px; margin-top:5px; background:<?= $w['aktiv']?'#f44336':'#f97316' ?>; color:white; border-radius:3px; padding:1px 4px">
                <?= $w['aktiv'] ? 'AKTIV' : 'BALD' ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
</div>

<!-- INCA Nowcast Details -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>📊 <?= $L['MAIN.INCA_NOWCAST'] ?></h3>
<ul data-role="listview" data-inset="false">
<li><span class="ui-li-count"><?= number_format($inca['fx_jetzt'] ?? 0,1) ?> km/h</span>Böen jetzt</li>
<li><span class="ui-li-count"><?= number_format($inca['ff_jetzt'] ?? 0,1) ?> km/h</span>Wind jetzt</li>
<li><span class="ui-li-count"><?= number_format($inca['fx_max_30min'] ?? 0,1) ?> km/h</span>Max Böen 30 min</li>
<li><span class="ui-li-count"><?= number_format($inca['fx_max_60min'] ?? 0,1) ?> km/h</span>Max Böen 60 min</li>
<li><span class="ui-li-count"><?= number_format($inca['rr_jetzt'] ?? 0,2) ?> mm/h</span>Niederschlag</li>
<li><span class="ui-li-count"><?= htmlspecialchars($inca['pt_name'] ?? '–') ?></span>Niederschlagstyp</li>
<?php $mbr = $inca['minuten_bis_regen'] ?? -1; ?>
<li><span class="ui-li-count"><?= $mbr >= 0 ? "~{$mbr} min" : '–' ?></span>Regen in</li>
</ul>
</div>

<!-- Notification Vorschau -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>🔔 <?= $L['MAIN.LAST_NOTIF'] ?></h3>
<pre style="white-space:pre-wrap;font-size:13px;color:#333;margin:0;background:#f0f7ff;border:1px solid #d0e0f0;padding:10px;border-radius:4px"><?= htmlspecialchars($state['notification_alle'] ?? $L['MAIN.NO_WARNS']) ?></pre>
</div>

<p style="text-align:center;padding:10px 0;font-size:11px;color:#888">
    📍 <?= htmlspecialchars($cfg['LOCATION']['NAME'] ?? $L['MAIN.NOT_CONFIGURED']) ?>
    &nbsp;·&nbsp; MQTT Broker: <?= $mqtt_display ?>
    &nbsp;·&nbsp; <?= $L['MAIN.INTERVAL'] ?>: <?= (int)($cfg['SCHEDULE']['INTERVAL'] ?? 300) ?>s
</p>

<?php LBWeb::lbfooter(); ?>
