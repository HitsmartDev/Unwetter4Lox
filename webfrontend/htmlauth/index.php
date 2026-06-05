<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";

$L = LBSystem::readlanguage("language.ini");

$navbar[1]['Name']   = "Status";
$navbar[1]['URL']    = "index.php";
$navbar[1]['active'] = true;
$navbar[2]['Name']   = "Einstellungen";
$navbar[2]['URL']    = "settings.php";
$navbar[3]['Name']   = "Log";
$navbar[3]['URL']    = "log.php";

# State laden
$state   = [];
$sf      = $lbpdatadir . "/state.json";
if (file_exists($sf)) $state = json_decode(file_get_contents($sf), true) ?? [];

$cfg     = parse_ini_file($lbpconfigdir . "/unwetter4lox.cfg", true) ?: [];

# Daemon-Status
$pidfile        = $lbplogdir . "/daemon.pid";
$pid            = file_exists($pidfile) ? trim(file_get_contents($pidfile)) : '';
$daemon_running = $pid && file_exists("/proc/{$pid}");

# MQTT Broker-Info - LoxBerry Auto oder manuell
$use_lb_mqtt = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
$mqttcred    = null;
if ($use_lb_mqtt && function_exists('mqtt_connectiondetails')) {
    $mqttcred = mqtt_connectiondetails();
}
$mqtt_display = $mqttcred
    ? htmlspecialchars($mqttcred['brokerhost'] . ':' . $mqttcred['brokerport'])
    : htmlspecialchars(($cfg['MQTT']['BROKER'] ?? '?') . ':' . ($cfg['MQTT']['PORT'] ?? '1883'));

# Hilfsfunktionen
function st($s) { return [0=>"–",1=>"⚠️ GELB",2=>"🟠 ORANGE",3=>"🔴 ROT",4=>"🟣 LILA"][(int)$s]??'–'; }
function jn($v,$j="Ja",$n="Nein") { return $v?"<b style='color:#4CAF50'>$j</b>":"<span style='color:#777'>$n</span>"; }

$akut  = $state['akutwarnung']     ?? 0;
$irdw  = $state['irgendwas_aktiv'] ?? 0;
$maxst = (int)($state['max_stufe'] ?? 0);
$inca  = $state['inca']            ?? [];
$zamg  = $state['zamg']            ?? [];

LBWeb::lbheader("Unwetter4Lox", "https://wiki.loxberry.de", "");
?>

<!-- Daemon Steuerung -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>🔧 Daemon &nbsp;
    <span style="font-size:12px;font-weight:normal;color:<?= $daemon_running ? '#4CAF50' : '#f44336' ?>">
        <?= $daemon_running ? '● läuft (PID ' . htmlspecialchars($pid) . ')' : '● gestoppt' ?>
    </span>
</h3>
<p style="font-size:12px;color:#888;margin:0 0 8px">
    Letztes Update: <?= htmlspecialchars($state['letztes_update'] ?? 'noch kein Update') ?>
</p>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<?php if ($daemon_running): ?>
    <a href="ajax.php?action=restart" data-role="button" data-inline="true" data-mini="true" data-theme="a">↺ Restart</a>
    <a href="ajax.php?action=stop"    data-role="button" data-inline="true" data-mini="true" data-theme="b">■ Stop</a>
<?php else: ?>
    <a href="ajax.php?action=start"   data-role="button" data-inline="true" data-mini="true" data-theme="b">▶ Start</a>
<?php endif; ?>
</div>
<?php if (!$daemon_running): ?>
<p style="font-size:11px;color:#888;margin:8px 0 0">
    Falls Start fehlschlägt, per SSH prüfen:<br>
    <code style="font-size:10px">sudo <?= htmlspecialchars($lbhomedir) ?>/system/daemons/plugins/unwetter4lox start</code>
</p>
<?php endif; ?>
</div>

<!-- Warnlage -->
<?php if ($akut): ?>
<div class="ui-body ui-body-e" style="margin:6px 0;text-align:center;font-weight:bold;font-size:15px">🚨 AKUTWARNUNG – Stationswarnung aktiv!</div>
<?php elseif ($irdw): ?>
<div class="ui-body ui-body-e" style="margin:6px 0;background:#f97316;text-align:center;font-weight:bold">⚠️ Aktive Wetterwarnung</div>
<?php else: ?>
<div class="ui-body ui-body-b" style="margin:6px 0;background:#16a34a;text-align:center;font-weight:bold">✅ Keine aktiven Warnungen</div>
<?php endif; ?>

<!-- Gesamtstatus -->
<ul data-role="listview" data-inset="true">
<li data-role="list-divider">Gesamtstatus</li>
<li><span class="ui-li-count"><?= st($maxst) ?></span>Höchste Warnstufe</li>
<li><span class="ui-li-count"><?= jn($akut, '🚨 AKTIV', 'Nein') ?></span>Akutwarnung (gwa)</li>
</ul>

<!-- GeoSphere Warnungen -->
<?php
$typen = ['wind','regen','schnee','glatteis','gewitter','hagel','hitze','kaelte'];
$lbls  = ['wind'=>'💨 Wind/Sturm','regen'=>'🌧️ Regen','schnee'=>'❄️ Schnee','glatteis'=>'🧊 Glatteis',
          'gewitter'=>'⚡ Gewitter','hagel'=>'🧊 Hagel','hitze'=>'🌡️ Hitze','kaelte'=>'🥶 Kälte'];
$haswarn = false;
foreach ($typen as $t) { if (($zamg[$t]['stufe'] ?? 0) > 0) { $haswarn = true; break; } }
?>
<ul data-role="listview" data-inset="true">
<li data-role="list-divider">🌩️ GeoSphere Austria – Offizielle Warnungen</li>
<?php if ($haswarn):
    foreach ($typen as $t):
        $w = $zamg[$t] ?? []; $s = (int)($w['stufe'] ?? 0); if (!$s) continue;
        $a = $w['aktiv'] ?? 0; $b = $w['bald'] ?? 0;
        $sl = $a ? '▶ AKTIV' : ($b ? '⏱ BALD' : '📅 VORAB');
        $sc = $a ? '#3b82f6' : ($b ? '#f97316' : '#6b7280');
?>
<li>
    <p style="margin:0 0 2px;font-weight:bold"><?= $lbls[$t] ?> — <?= st($s) ?></p>
    <p style="margin:0;font-size:12px;color:#aaa">
        <span style="color:<?= $sc ?>"><?= $sl ?></span> &nbsp;
        <?= htmlspecialchars($w['start_text'] ?? '–') ?> → <?= htmlspecialchars($w['end_text'] ?? '–') ?>
    </p>
</li>
<?php endforeach; else: ?>
<li><span style="color:#888">Keine aktiven Warnungen von GeoSphere</span></li>
<?php endif; ?>
</ul>

<!-- INCA -->
<ul data-role="listview" data-inset="true">
<li data-role="list-divider">📊 INCA Nowcast – nächste 60 Minuten</li>
<li><span class="ui-li-count"><?= number_format($inca['fx_jetzt'] ?? 0,1) ?> km/h</span>Böen jetzt</li>
<li><span class="ui-li-count"><?= number_format($inca['ff_jetzt'] ?? 0,1) ?> km/h</span>Wind jetzt</li>
<li><span class="ui-li-count"><?= number_format($inca['fx_max_30min'] ?? 0,1) ?> km/h</span>Max Böen 30 min</li>
<li><span class="ui-li-count"><?= number_format($inca['fx_max_60min'] ?? 0,1) ?> km/h</span>Max Böen 60 min</li>
<li><span class="ui-li-count"><?= number_format($inca['rr_jetzt'] ?? 0,2) ?> mm/h</span>Niederschlag</li>
<li><span class="ui-li-count"><?= htmlspecialchars($inca['pt_name'] ?? '–') ?></span>Niederschlagstyp</li>
<?php $mbr = $inca['minuten_bis_regen'] ?? -1; ?>
<li><span class="ui-li-count"><?= $mbr >= 0 ? "~{$mbr} min" : '–' ?></span>Regen in</li>
<li><span class="ui-li-count"><?= jn($inca['bald_hagel'] ?? 0, '🧊 JA', 'Nein') ?></span>Hagel &lt; 60 min</li>
<li><span class="ui-li-count"><?= jn($inca['bald_sturm_30'] ?? 0, '💨 JA', 'Nein') ?></span>Sturm &lt; 30 min</li>
<li><span class="ui-li-count"><?= jn($inca['bald_sturm_60'] ?? 0, '💨 JA', 'Nein') ?></span>Sturm &lt; 60 min</li>
</ul>

<!-- Notification -->
<ul data-role="listview" data-inset="true">
<li data-role="list-divider">🔔 Notification → Loxone Push</li>
<li><pre style="white-space:pre-wrap;font-size:12px;color:#90b8e0;margin:0;background:transparent;border:none;padding:4px 0"><?= htmlspecialchars($state['notification_alle'] ?? 'keine aktiven Warnungen') ?></pre></li>
</ul>

<p style="text-align:center;padding:6px 0 4px;font-size:11px;color:#555">
    📍 <?= htmlspecialchars($cfg['LOCATION']['NAME'] ?? '?') ?>
    &nbsp;·&nbsp; MQTT: <?= $mqtt_display ?>
    &nbsp;·&nbsp; <?= (int)($cfg['SCHEDULE']['INTERVAL'] ?? 300) ?>s
</p>

<?php LBWeb::lbfooter(); ?>
