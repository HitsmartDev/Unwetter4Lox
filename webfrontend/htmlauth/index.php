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

# Daemon-Status – PID-Check verifiziert zusätzlich den Prozessnamen (verhindert Fehlmeldung bei PID-Wiederverwendung nach Reboot)
$pidfile        = $lbplogdir . "/daemon.pid";
$pid            = file_exists($pidfile) ? trim(file_get_contents($pidfile)) : '';
$daemon_running = false;
if ($pid && is_numeric($pid)) {
    $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
    if ($cmdline !== false) {
        $daemon_running = strpos($cmdline, 'unwetter4lox_daemon') !== false
                       || strpos($cmdline, 'unwetter4lox') !== false;
    }
}

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

$akut   = $state['akutwarnung']     ?? 0;
$irdw   = $state['irgendwas_aktiv'] ?? 0;
$maxst  = (int)($state['max_stufe'] ?? 0);
$inca   = $state['inca']            ?? [];
$zamg   = $state['zamg']            ?? [];
$alarm  = $state['alarm']           ?? [];
$status = $state['status']          ?? 'OK';

function alarm_color($lv) {
    return [0=>'#4CAF50', 1=>'#FFEB3B', 2=>'#FF9800', 3=>'#f44336'][(int)$lv] ?? '#4CAF50';
}
function alarm_text_color($lv) { return ((int)$lv === 1) ? '#333' : 'white'; }
function alarm_label($lv, $L) {
    return [0=>$L['MAIN.ALARM_KEINE'], 1=>$L['MAIN.ALARM_MOEGLICH'],
            2=>$L['MAIN.ALARM_AKTIV'],  3=>$L['MAIN.ALARM_AKUT']][(int)$lv] ?? $L['MAIN.ALARM_KEINE'];
}

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
    /* Alarm-Kacheln: kein Kursiv, kein Schatten */
    .alarm-tile { font-style: normal !important; font-family: Arial, Helvetica, sans-serif !important; text-shadow: none !important; -webkit-font-smoothing: antialiased; }
    .alarm-tile .alarm-icon { font-size: 26px; line-height: 1.2; font-style: normal; }
    .alarm-tile .alarm-label { font-weight: 700; font-size: 13px; margin-top: 4px; font-style: normal; letter-spacing: 0; }
    .alarm-tile .alarm-level { font-size: 12px; font-weight: 600; margin-top: 2px; font-style: normal; }
    .alarm-tile .alarm-desc { font-size: 11px; margin-top: 2px; font-style: normal; opacity: 0.9; }
</style>

<!-- ================================================================
     GESAMTSTATUS – alle Quellen kombiniert (alarm/ MQTT Topics)
     ================================================================ -->
<?php
$alarm_cats = [
    'gewitter' => ['icon'=>'⚡', 'label'=>$L['MAIN.ALARM_GEWITTER'], 'desc_0'=>'Kein Gewitter', 'desc_1'=>'Möglich', 'desc_2'=>'Warnung aktiv', 'desc_3'=>'AKUT – sofort handeln'],
    'wind'     => ['icon'=>'💨', 'label'=>$L['MAIN.ALARM_WIND'],     'desc_0'=>'Kein Wind-Alarm', 'desc_1'=>'Erhöhte Böen', 'desc_2'=>'Sturm aktiv', 'desc_3'=>'Extremsturm'],
    'regen'    => ['icon'=>'🌧', 'label'=>$L['MAIN.ALARM_REGEN'],    'desc_0'=>'Kein Regen', 'desc_1'=>'Regen erwartet', 'desc_2'=>'Stark / bald', 'desc_3'=>'Extrem'],
    'hagel'    => ['icon'=>'🌨', 'label'=>$L['MAIN.ALARM_HAGEL'],    'desc_0'=>'Kein Hagel', 'desc_1'=>'Möglich', 'desc_2'=>'Warnung', 'desc_3'=>'Extrem'],
    'schnee'   => ['icon'=>'❄️', 'label'=>$L['MAIN.ALARM_SCHNEE'],   'desc_0'=>'Kein Schnee/Eis', 'desc_1'=>'Möglich', 'desc_2'=>'Warnung', 'desc_3'=>'Extrem'],
];
$any_alarm = max(array_map(fn($k) => (int)($alarm[$k] ?? 0), array_keys($alarm_cats)));
$header_bg = $any_alarm >= 3 ? '#f44336' : ($any_alarm == 2 ? '#FF9800' : ($any_alarm == 1 ? '#FFEB3B' : '#4CAF50'));
$header_tc = $any_alarm == 1 ? '#333' : 'white';
?>
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3 style="background:<?= $header_bg ?>;color:<?= $header_tc ?>;border-radius:4px;padding:6px 10px;margin:0;font-style:normal;text-shadow:none;font-family:Arial,Helvetica,sans-serif">
    🚦 <?= $L['MAIN.ALARM_TITLE'] ?>
</h3>
<p style="font-size:11px;color:#888;margin:4px 0"><?= $L['MAIN.ALARM_DESC'] ?></p>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:8px 0">
<?php foreach ($alarm_cats as $key => $cat):
    $lv  = (int)($alarm[$key] ?? 0);
    $col = alarm_color($lv); $tc = alarm_text_color($lv);
    $lbl = alarm_label($lv, $L);
    $desc = $cat["desc_{$lv}"];
?>
<div class="alarm-tile" style="background:<?= $col ?>;border-radius:6px;padding:10px 6px;text-align:center;color:<?= $tc ?>">
    <div class="alarm-icon"><?= $cat['icon'] ?></div>
    <div class="alarm-label"><?= $cat['label'] ?></div>
    <div class="alarm-level"><?= $lbl ?></div>
    <div class="alarm-desc"><?= $desc ?></div>
</div>
<?php endforeach; ?>
</div>
<?php if (!empty($alarm['zusammenfassung'])): ?>
<div style="padding:8px 10px;background:#f5f5f5;border-left:4px solid <?= $header_bg ?>;border-radius:0 4px 4px 0;font-size:13px">
    <b><?= $alarm['stufe'] > 0 ? 'ZAMG Warnstufe '.$alarm['stufe'].': ' : '' ?></b><?= htmlspecialchars($alarm['zusammenfassung']) ?>
</div>
<?php endif; ?>
</div>

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
        <?php
        $last_epoch = (int)($state['letzter_abruf_epoch'] ?? 0);
        $age = time() - $last_epoch;
        $interval = (int)($cfg['SCHEDULE']['INTERVAL'] ?? 300);
        $is_stale = ($coords_set && $daemon_running && $last_epoch > 0 && $age > ($interval * 2 + 60));
        ?>
        <p style="font-size:12px;color:#888;margin:4px 0 0">
            Status: <span class="status-badge <?= (strpos($status,'Error')!==false || $is_stale || !$daemon_running)?'badge-err':'badge-ok' ?>"><?= htmlspecialchars($status) ?></span>
            &nbsp;·&nbsp; Letzter Abruf: <b style="<?= $is_stale ? 'color:#f44336' : '' ?>"><?= htmlspecialchars($state['letztes_update'] ?? '–') ?></b>
            <?php if ($is_stale): ?>
                <br><span style="color:#f44336; font-weight:bold; font-size:11px">⚠️ Warnung: Seit <?= round($age/60) ?> min keine neuen Daten! (Daemon hängt?)</span>
            <?php endif; ?>
        </p>
        <table style="font-size:11px;color:#666;margin-top:5px;border-collapse:collapse">
        <tr>
            <td>🌩️ GeoSphere:</td>
            <td style="padding-left:6px"><b><?= htmlspecialchars($state['zamg_letztes_update'] ?? '–') ?></b></td>
            <td style="padding-left:14px">📊 INCA:</td>
            <td style="padding-left:6px"><b><?= htmlspecialchars($state['inca_letztes_update'] ?? '–') ?></b></td>
            <td style="padding-left:14px">🌐 TAWES:</td>
            <td style="padding-left:6px"><b><?= htmlspecialchars($state['tawes_letztes_update'] ?? $state['tawes']['letztes_update'] ?? '–') ?></b></td>
        </tr>
        </table>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
        <?php if ($daemon_running): ?>
            <a href="#" id="btn-daemon-restart" data-role="button" data-inline="true" data-mini="true" data-theme="a">↺ Restart</a>
            <a href="ajax.php?action=stop"      data-role="button" data-inline="true" data-mini="true" data-theme="b">■ Stop</a>
        <?php else: ?>
            <a href="#" id="btn-daemon-start"   data-role="button" data-inline="true" data-mini="true" data-theme="b" <?= !$coords_set ? 'class="ui-disabled"' : '' ?>>▶ Start</a>
        <?php endif; ?>
        <span id="daemon-action-status" style="font-size:11px;color:#aaa;display:none"></span>
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

<!-- GeoSphere Warnungen – offizielle ZAMG-Warnkarten -->
<div data-role="collapsible" data-collapsed="<?= $irdw || $akut ? 'false' : 'true' ?>" data-theme="a" data-content-theme="a">
<h3>🌩️ <?= $L['MAIN.GEO_WARNS'] ?> <small style="font-size:11px;font-weight:normal;color:#888">(GeoSphere Austria – offizielle Warnungen, Stufe 0-4)</small></h3>
<p style="font-size:11px;color:#888;margin:2px 0 8px">Grün=keine Warnung · Gelb=Vorsicht · Orange=Markant · Rot=Unwetter · Lila=Extrem. Aktiv=Warnung läuft gerade, Bald=in &lt;30 min.</p>
<div class="warn-grid">
    <?php
    $typen = ['wind','regen','schnee','glatteis','gewitter','hagel','hitze','kaelte'];
    $lbls  = ['wind'=>$L['wind'],'regen'=>$L['regen'],'schnee'=>$L['schnee'],'glatteis'=>$L['glatteis'],
              'gewitter'=>$L['gewitter'],'hagel'=>$L['hagel'],'hitze'=>$L['hitze'],'kaelte'=>$L['kaelte']];
    $icons = ['wind'=>'💨','regen'=>'🌧','schnee'=>'❄️','glatteis'=>'🧊','gewitter'=>'⚡','hagel'=>'🌨','hitze'=>'☀️','kaelte'=>'🥶'];
    foreach ($typen as $t):
        $w = $zamg[$t] ?? []; $s = (int)($w['stufe'] ?? 0);
        $active = ($w['aktiv'] ?? 0) || ($w['bald'] ?? 0);
    ?>
    <div class="warn-card st-<?= $s ?> <?= $active ? 'active' : '' ?>">
        <div style="font-size:18px"><?= $icons[$t] ?? '' ?></div>
        <div style="font-weight:bold; font-size:12px"><?= $lbls[$t] ?></div>
        <div style="font-size:11px; color:<?= st_color($s) ?>; font-weight:bold"><?= st_name($s) ?></div>
        <?php if ($active): ?>
            <div style="font-size:10px; margin-top:4px; background:<?= $w['aktiv']?'#f44336':'#f97316' ?>; color:white; border-radius:3px; padding:1px 4px">
                <?= $w['aktiv'] ? '▶ AKTIV' : '⏱ BALD' ?>
            </div>
            <?php if (!empty($w['end_text'])): ?>
            <div style="font-size:9px;color:#666;margin-top:2px">bis <?= htmlspecialchars($w['end_text']) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($akut): ?>
<div style="background:#f44336;color:white;padding:8px 10px;border-radius:4px;margin-top:8px;font-weight:bold">
    🚨 AKUTWARNUNG: Eine stationsbasierte Unwetterwarnung ist aktiv! Sofortige Gefahr.
</div>
<?php endif; ?>
</div>

<!-- INCA Nowcast Details -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>📊 <?= $L['MAIN.INCA_NOWCAST'] ?> <small style="font-size:11px;font-weight:normal;color:#888">(hochauflösend, alle 15 min)</small></h3>
<p style="font-size:11px;color:#888;margin:2px 0 6px">INCA = hochauflösende Nowcast-Analyse von GeoSphere Austria. Zeigt die nächsten 60 Minuten auf 1 km Auflösung.</p>
<ul data-role="listview" data-inset="false">
<li data-icon="false">
    <span class="ui-li-count" style="<?= ($inca['fx_jetzt'] ?? 0) >= ($cfg['THRESHOLDS']['BOEN_ALARM'] ?? 60) ? 'color:#f44336' : '' ?>">
        <?= number_format($inca['fx_jetzt'] ?? 0,1) ?> km/h
    </span>
    <b>Böen jetzt</b> – aktuelle Spitzenböen am Standort
</li>
<li data-icon="false"><span class="ui-li-count"><?= number_format($inca['ff_jetzt'] ?? 0,1) ?> km/h</span><b>Wind jetzt</b> – mittlere Windgeschwindigkeit</li>
<li data-icon="false">
    <span class="ui-li-count" style="<?= ($inca['fx_max_30min'] ?? 0) >= ($cfg['THRESHOLDS']['BOEN_ALARM'] ?? 60) ? 'color:#f44336' : '' ?>">
        <?= number_format($inca['fx_max_30min'] ?? 0,1) ?> km/h
    </span>
    <b>Max Böen 30 min</b> – höchste Böe in den nächsten 30 Minuten
</li>
<li data-icon="false">
    <span class="ui-li-count" style="<?= ($inca['fx_max_60min'] ?? 0) >= ($cfg['THRESHOLDS']['BOEN_ALARM'] ?? 60) ? 'color:#f44336' : '' ?>">
        <?= number_format($inca['fx_max_60min'] ?? 0,1) ?> km/h
    </span>
    <b>Max Böen 60 min</b> – höchste Böe in der nächsten Stunde
</li>
<li data-icon="false"><span class="ui-li-count"><?= number_format($inca['rr_jetzt'] ?? 0,2) ?> mm/h</span><b>Niederschlag jetzt</b> – Intensität aktuell</li>
<?php
$pt_jetzt = (int)($inca['pt_jetzt'] ?? 255);
$pt_name  = htmlspecialchars($inca['pt_name'] ?? '–');
$pt_bald_name = htmlspecialchars($inca['pt_bald_name'] ?? '');
$mbr = $inca['minuten_bis_regen'] ?? -1;
// Wenn aktuell kein Niederschlag aber Regen prognostiziert: Folgetyp anzeigen
if ($pt_jetzt === 255 && $mbr >= 0 && $pt_bald_name !== '') {
    $pt_display = "kein Niederschlag → {$pt_bald_name} in ~{$mbr} min";
} else {
    $pt_display = $pt_name;
}
?>
<li data-icon="false"><span class="ui-li-count"><?= $pt_display ?></span><b>Niederschlagstyp</b> – aktuell / bald erwartet</li>
<li data-icon="false">
    <span class="ui-li-count" style="<?= $mbr >= 0 && $mbr <= 15 ? 'color:#f44336' : '' ?>">
        <?= $mbr >= 0 ? "~{$mbr} min" : '☀️ trocken' ?>
    </span>
    <b>Regen kommt in</b> – Zeit bis zum nächsten Niederschlag
</li>
</ul>
</div>

<!-- TAWES 360° Korrelation -->
<?php
$tawes      = $state['tawes'] ?? [];
$tawes_cfg  = $cfg['TAWES']   ?? [];
$tawes_en   = ($tawes_cfg['ENABLED'] ?? '1') == '1';
if ($tawes_en):
    $regen_up = (int)($tawes['regen_upstream'] ?? 0);
    $sturm_up = (int)($tawes['sturm_upstream']  ?? 0);
    $gewitter = (int)($tawes['gewitter_signal'] ?? 0);
    $eta      = (int)($tawes['regen_eta_min']   ?? -1);
    $t_color  = $gewitter ? '#f44336' : ($sturm_up ? '#FF9800' : ($regen_up ? '#2196F3' : '#4CAF50'));
?>
<div data-role="collapsible" data-collapsed="<?= empty($tawes) ? 'true' : 'false' ?>" data-theme="a" data-content-theme="a">
<h3>🌐 <?= $L['MAIN.TAWES_TITLE'] ?></h3>
<?php if (empty($tawes)): ?>
<p style="color:#888;padding:10px"><?= $L['MAIN.TAWES_NO_DATA'] ?></p>
<?php else: ?>
<ul data-role="listview" data-inset="false">
    <li><span class="ui-li-count"><?= htmlspecialchars($tawes['dominante_windrichtung_name'] ?? '–') ?> (<?= number_format($tawes['dominante_windrichtung'] ?? 0, 0) ?>°)</span><?= $L['MAIN.TAWES_WINDRICHTUNG'] ?></li>
    <li><span class="ui-li-count"><?= (int)($tawes['upstream_aktiv'] ?? 0) ?></span><?= $L['MAIN.TAWES_UPSTREAM_COUNT'] ?></li>
    <li><span class="ui-li-count"><?= number_format($tawes['wind_upstream_kmh'] ?? 0, 1) ?> km/h</span><?= $L['MAIN.TAWES_WIND_UPSTREAM'] ?></li>
    <?php $trend = (int)($tawes['wind_trend'] ?? 0); ?>
    <li><span class="ui-li-count" style="color:<?= $trend>0?'#f44336':($trend<0?'#4CAF50':'#888') ?>"><?= $trend>0?'↑ zunehmend':($trend<0?'↓ abnehmend':'→ stabil') ?></span><?= $L['MAIN.TAWES_WIND_TREND'] ?></li>
    <?php $regen_up_mm = (float)($tawes['regen_upstream_mm'] ?? 0); ?>
    <li><span class="ui-li-count" style="color:<?= $regen_up?'#2196F3':'#4CAF50' ?>">
        <?= $regen_up ? ($eta>=0 ? "~{$eta} min" : $L['MAIN.TAWES_ETA_UNKNOWN']) : $L['MAIN.TAWES_KEIN_REGEN'] ?>
        <?= ($regen_up && $regen_up_mm > 0) ? " ({$regen_up_mm} mm/h)" : '' ?>
    </span><?= $L['MAIN.TAWES_REGEN'] ?></li>
    <?php if ($regen_up && $eta >= 0): ?>
    <li><span class="ui-li-count"><?= number_format($tawes['front_speed_kmh'] ?? 0, 0) ?> km/h</span><?= $L['MAIN.TAWES_FRONT_SPEED'] ?> <small>(<?= (int)($tawes['regen_konfidenz'] ?? 0) ?>%)</small></li>
    <?php endif; ?>
    <li><span class="ui-li-count" style="color:<?= $gewitter?'#f44336':'#888' ?>"><?= $gewitter ? '⚡ Ja' : $L['MAIN.TAWES_NEIN'] ?></span><?= $L['MAIN.TAWES_GEWITTER'] ?></li>
    <li><span class="ui-li-count" style="color:<?= ($tawes['druck_trend']??0)<-0.3?'#f44336':'#888' ?>"><?= number_format($tawes['druck_trend'] ?? 0, 2) ?> hPa/10min</span><?= $L['MAIN.TAWES_DRUCK_TREND'] ?></li>
</ul>
<?php if (!empty($tawes['alle_stationen'])): ?>
<div data-role="collapsible" data-collapsed="true">
<h4><?= $L['MAIN.TAWES_STATIONEN'] ?> (<?= count($tawes['alle_stationen']) ?>)</h4>
<table style="width:100%;font-size:11px;border-collapse:collapse">
<tr style="background:#eee;font-weight:bold">
    <td style="padding:4px">Station</td>
    <td style="padding:4px;text-align:center" title="Entfernung vom Standort">km</td>
    <td style="padding:4px;text-align:center" title="Himmelsrichtung der Station von deinem Standort">Richtg.</td>
    <td style="padding:4px;text-align:center" title="Mittlere Windgeschwindigkeit (km/h)">Wind</td>
    <td style="padding:4px;text-align:center" title="Böenspitzen (km/h)">Böen</td>
    <td style="padding:4px;text-align:center" title="Niederschlag mm/h (API-Wert mm/10min × 6)">Regen</td>
    <td style="padding:4px;text-align:center" title="⬆ = Upstream: Wind kommt von dieser Station zu dir">⬆</td>
</tr>
<?php
$boen_sw  = (float)($cfg['THRESHOLDS']['BOEN_ALARM']  ?? 60);
$regen_sw = (float)($cfg['THRESHOLDS']['REGEN_ALARM'] ?? 10);
foreach ($tawes['alle_stationen'] as $st):
    $up     = (bool)($st['ist_upstream'] ?? false);
    $rr_raw = $st['RR'] ?? null;
    $rr_v   = ($rr_raw !== null) ? round($rr_raw * 6, 1) : null;  // mm/10min × 6 = mm/h
    $ffx_v  = $st['FFX_kmh'] ?? null;
    $rr_c   = ($rr_v !== null && $rr_v >= $regen_sw) ? '#f44336'
            : (($rr_v !== null && $rr_v > 0.5) ? '#2196F3' : '');
    $ffx_c  = ($ffx_v !== null && $ffx_v >= $boen_sw) ? '#f44336' : '';
?>
<tr style="background:<?= $up ? 'rgba(33,150,243,0.07)' : 'transparent' ?>;border-bottom:1px solid #eee">
    <td style="padding:4px;font-weight:<?= $up?'bold':'normal' ?>"><?= htmlspecialchars($st['name'] ?? '') ?></td>
    <td style="padding:4px;text-align:center"><?= number_format($st['dist_km'] ?? 0, 0) ?></td>
    <td style="padding:4px;text-align:center"><?= htmlspecialchars($st['bearing_name'] ?? '–') ?></td>
    <td style="padding:4px;text-align:center"><?= $st['FF_kmh'] !== null ? number_format($st['FF_kmh'], 0) : '–' ?></td>
    <td style="padding:4px;text-align:center;color:<?= $ffx_c ?>"><?= $ffx_v !== null ? number_format($ffx_v, 0) : '–' ?></td>
    <td style="padding:4px;text-align:center;color:<?= $rr_c ?>"><?= $rr_v !== null ? number_format($rr_v, 1) : '–' ?></td>
    <td style="padding:4px;text-align:center"><?= $up ? '⬆' : '' ?></td>
</tr>
<?php endforeach; ?>
</table>
<p style="font-size:10px;color:#888;margin:4px 0">
    <b>⬆ Upstream</b> = der Wind kommt gerade von dieser Station zu dir. Diese Stationen sind besonders relevant für Vorhersagen.<br>
    <b>Wind</b> = mittl. Windgeschwindigkeit km/h &nbsp;·&nbsp; <b>Böen</b> = Spitzenböen km/h &nbsp;·&nbsp; <b>Regen</b> = mm/h &nbsp;·&nbsp; <span style="color:#2196F3">■</span> Regen &nbsp;·&nbsp; <span style="color:#f44336">■</span> ≥ Alarm-Schwelle
</p>
</div>
<?php endif; ?>
<p style="font-size:10px;color:#888;margin:4px 0"><?= $L['MAIN.TAWES_LAST_UPDATE'] ?>: <?= htmlspecialchars($tawes['letztes_update'] ?? '–') ?></p>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- Notification Vorschau -->
<div data-role="collapsible" data-collapsed="false" data-theme="a" data-content-theme="a">
<h3>🔔 <?= $L['MAIN.LAST_NOTIF'] ?></h3>
<pre style="white-space:pre-wrap;font-size:13px;color:#333;margin:0;background:#f0f7ff;border:1px solid #d0e0f0;padding:10px;border-radius:4px"><?= htmlspecialchars($state['notification_alle'] ?? $L['MAIN.NO_WARNS']) ?></pre>
</div>

<p style="text-align:center;padding:10px 0;font-size:11px;color:#888">
    📍 <?= htmlspecialchars($cfg['LOCATION']['NAME'] ?? $L['MAIN.NOT_CONFIGURED']) ?>
    &nbsp;·&nbsp; MQTT Broker: <?= $mqtt_display ?>
    &nbsp;·&nbsp; <?= $L['MAIN.INTERVAL'] ?>: <?= (int)($cfg['SCHEDULE']['INTERVAL'] ?? 300) ?>s
    &nbsp;·&nbsp; <span id="refresh-status" style="color:#aaa">⟳ Auto-Aktualisierung aktiv</span>
</p>

<script>
(function() {
    var knownEpoch   = <?= (int)($state['letzter_abruf_epoch'] ?? 0) ?>;
    var knownRunning = <?= $daemon_running ? 'true' : 'false' ?>;
    var statusEl     = document.getElementById('refresh-status');
    var actionStatus = document.getElementById('daemon-action-status');
    var failCount    = 0;
    var waitForStart = false;  // true während wir auf Daemon-Start nach AJAX-Aktion warten

    function checkForUpdate() {
        fetch('ajax.php?action=check_update', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                failCount = 0;
                var newData    = d.epoch   && d.epoch   > knownEpoch;
                var statusFlip = typeof d.running !== 'undefined' && d.running !== knownRunning;
                if (waitForStart && d.running) {
                    // Daemon ist jetzt oben – Seite neu laden
                    if (actionStatus) actionStatus.textContent = '✓ Daemon läuft – Seite wird geladen…';
                    location.reload();
                    return;
                }
                if (newData || statusFlip) {
                    if (statusEl) statusEl.textContent = '⟳ ' + (statusFlip ? 'Daemon-Status geändert' : 'Neue Daten') + ' – wird geladen…';
                    location.reload();
                } else {
                    var now = new Date().toLocaleTimeString('de-AT', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
                    if (statusEl) statusEl.textContent = '⟳ Zuletzt geprüft: ' + now;
                }
            })
            .catch(function() {
                failCount++;
                if (statusEl) statusEl.textContent = '⚠ Verbindung unterbrochen (' + failCount + ')';
            });
    }

    function daemonAction(action_json, label) {
        if (actionStatus) { actionStatus.textContent = label; actionStatus.style.display = 'inline'; }
        // Buttons deaktivieren
        var btns = document.querySelectorAll('#btn-daemon-restart, #btn-daemon-start');
        btns.forEach(function(b){ b.classList.add('ui-disabled'); });
        fetch('ajax.php?action=' + action_json, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function() {
                waitForStart = true;
                // Schnelles Polling bis Daemon oben ist (alle 3s, max 60s)
                var tries = 0;
                var poll = setInterval(function() {
                    tries++;
                    checkForUpdate();
                    if (tries >= 20) { clearInterval(poll); location.reload(); }
                }, 3000);
            })
            .catch(function() {
                if (actionStatus) actionStatus.textContent = '⚠ Fehler bei Daemon-Steuerung';
                btns.forEach(function(b){ b.classList.remove('ui-disabled'); });
            });
    }

    // Restart-Button: AJAX statt Redirect
    var btnRestart = document.getElementById('btn-daemon-restart');
    if (btnRestart) {
        btnRestart.addEventListener('click', function(e) {
            e.preventDefault();
            daemonAction('restart_json', '↺ Neustart läuft…');
        });
    }

    // Start-Button: AJAX statt Redirect
    var btnStart = document.getElementById('btn-daemon-start');
    if (btnStart) {
        btnStart.addEventListener('click', function(e) {
            e.preventDefault();
            if (btnStart.classList.contains('ui-disabled')) return;
            daemonAction('start_json', '▶ Daemon startet…');
        });
    }

    // Erste Prüfung nach 5s (schnelles Feedback nach Daemon-Start), dann alle 30s
    setTimeout(checkForUpdate, 5000);
    setInterval(checkForUpdate, 30000);
})();
</script>

<?php LBWeb::lbfooter(); ?>
