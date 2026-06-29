<?php
require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once 'common.php';

$L   = LBSystem::readlanguage('language.ini');

// ── State laden ──
$state  = [];
$sf     = $lbpdatadir . '/state.json';
if (file_exists($sf)) $state = json_decode(file_get_contents($sf), true) ?? [];

// ── Config laden ──
$cfg        = parse_ini_file($lbpconfigdir . '/unwetter4lox.cfg', true) ?: [];
$lat        = $cfg['LOCATION']['LAT'] ?? '';
$lon        = $cfg['LOCATION']['LON'] ?? '';
$coords_set = ($lat !== '' && $lon !== '');

// ── Daemon-Status (PID-Check + Prozessname) ──
$pidfile        = $lbplogdir . '/daemon.pid';
$pid            = file_exists($pidfile) ? trim(file_get_contents($pidfile)) : '';
$daemon_running = false;
if ($pid && is_numeric($pid)) {
    $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
    if ($cmdline !== false) {
        $daemon_running = strpos($cmdline, 'unwetter4lox_daemon') !== false
                       || strpos($cmdline, 'unwetter4lox') !== false;
    }
}

// ── MQTT Broker-Anzeige ──
$use_lb_mqtt = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
$mqttcred    = null;
if ($use_lb_mqtt && function_exists('mqtt_connectiondetails')) {
    $mqttcred = mqtt_connectiondetails();
}
$mqtt_display = $mqttcred
    ? h($mqttcred['brokerhost'] . ':' . $mqttcred['brokerport'])
    : h(($cfg['MQTT']['BROKER'] ?? '?') . ':' . ($cfg['MQTT']['PORT'] ?? '1883'));

// ── Hilfsfunktionen ──
function alarm_lv(string $key, array $alarm): int { return (int)($alarm[$key] ?? 0); }
function alarm_color(int $lv): string {
    return ['#27ae60','#f1c40f','#e67e22','#e74c3c'][$lv] ?? '#27ae60';
}

// Liest Tail der Log-Datei und gibt 'green'/'orange'/'red'/'none' zurück.
function log_health_check(?string $logfile): string {
    if (!$logfile || !file_exists($logfile) || !is_readable($logfile)) return 'none';
    $cutoff    = time() - 1800;
    $has_error = false;
    $has_warn  = false;
    $fp = @fopen($logfile, 'rb');
    if (!$fp) return 'none';
    fseek($fp, 0, SEEK_END);
    $size = ftell($fp);
    fseek($fp, -min($size, 61440), SEEK_END);
    $chunk = fread($fp, 61440);
    fclose($fp);
    foreach (explode("\n", $chunk) as $line) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) continue;
        if (strtotime($m[1]) < $cutoff) continue;
        if (strpos($line, ' ERROR') !== false || strpos($line, '<ERR>') !== false) {
            $has_error = true; break;
        }
        if (strpos($line, ' WARNING') !== false || strpos($line, '<WARN>') !== false) {
            $has_warn = true;
        }
    }
    if ($has_error) return 'red';
    if ($has_warn)  return 'orange';
    return 'green';
}

// ── Aktuelle Log-Datei ermitteln (für Health-Dot + Log-Button) ──
$_ptr  = $lbplogdir . '/daemon.log.current';
$_clog = file_exists($_ptr) ? trim(file_get_contents($_ptr)) : null;
if ($_clog && !file_exists($_clog)) $_clog = null;
if (!$_clog) {
    $_logs = glob($lbpdatadir . '/logs/*.log') ?: [];
    if (!$_logs) $_logs = glob($lbplogdir . '/*.log') ?: [];
    if ($_logs) { usort($_logs, fn($a,$b) => filemtime($b) - filemtime($a)); $_clog = $_logs[0]; }
}
$log_health = $daemon_running ? log_health_check($_clog) : 'none';

$inca   = $state['inca']  ?? [];
$zamg   = $state['zamg']  ?? [];
$alarm  = $state['alarm'] ?? [];
$tawes  = $state['tawes'] ?? [];
$status = $state['status'] ?? 'OK';

$alarm_cats = [
    'gewitter' => ['icon'=>'⚡', 'cat'=>$L['MAIN.ALARM_GEWITTER'] ?? 'Gewitter',
        'descs'=>['Kein Gewitter','Möglich','Warnung aktiv','AKUT']],
    'wind'     => ['icon'=>'💨', 'cat'=>$L['MAIN.ALARM_WIND']     ?? 'Wind',
        'descs'=>['Kein Wind-Alarm','Erhöhte Böen','Sturm aktiv','Extremsturm']],
    'regen'    => ['icon'=>'🌧', 'cat'=>$L['MAIN.ALARM_REGEN']    ?? 'Regen',
        'descs'=>['Kein Regen','Regen erwartet','Stark / bald','Extrem']],
    'hagel'    => ['icon'=>'🌨', 'cat'=>$L['MAIN.ALARM_HAGEL']    ?? 'Hagel',
        'descs'=>['Kein Hagel','Möglich','Warnung','Extrem']],
    'schnee'   => ['icon'=>'❄️', 'cat'=>$L['MAIN.ALARM_SCHNEE']   ?? 'Schnee/Eis',
        'descs'=>['Kein Schnee/Eis','Möglich','Warnung','Extrem']],
];

$alarm_labels = [
    0 => $L['MAIN.ALARM_KEINE']   ?? 'Ruhig',
    1 => $L['MAIN.ALARM_MOEGLICH'] ?? 'Vorsicht',
    2 => $L['MAIN.ALARM_AKTIV']   ?? 'Warnung',
    3 => $L['MAIN.ALARM_AKUT']    ?? 'AKUT',
];

$any_alarm = max(array_map(fn($k) => alarm_lv($k, $alarm), array_keys($alarm_cats)));

$akut  = (int)($state['akutwarnung']     ?? 0);
$irdw  = (int)($state['irgendwas_aktiv'] ?? 0);
$tawes_en = ($cfg['TAWES']['ENABLED'] ?? '1') == '1';

// Staleness-Check
$last_epoch = (int)($state['letzter_abruf_epoch'] ?? 0);
$age        = time() - $last_epoch;
$interval   = (int)($cfg['SCHEDULE']['INTERVAL'] ?? 300);
$is_stale   = ($coords_set && $daemon_running && $last_epoch > 0 && $age > ($interval * 2 + 60));

// ZAMG Typen
$zamg_typen = ['wind'=>['💨','Wind'],'regen'=>['🌧','Regen'],'schnee'=>['❄️','Schnee'],
               'glatteis'=>['🧊','Glatteis'],'gewitter'=>['⚡','Gewitter'],'hagel'=>['🌨','Hagel'],
               'hitze'=>['☀️','Hitze'],'kaelte'=>['🥶','Kälte']];
$zamg_stufe_label = [0=>'–',1=>'GELB',2=>'ORANGE',3=>'ROT',4=>'LILA'];

render_header('app_status');
?>

<?php if (!$coords_set): ?>
<div class="sl-setup-banner">
    <p>🚨 <?= $L['MAIN.NOT_CONFIGURED'] ?? 'Plugin nicht konfiguriert' ?></p>
    <p style="font-size:0.82rem;margin:0 0 0.6rem"><?= $L['MAIN.NOT_CONFIGURED_TXT'] ?? 'Bitte zuerst Koordinaten in den Einstellungen setzen.' ?></p>
    <a href="app_settings.php" class="sl-btn secondary sm">⚙️ <?= $L['MAIN.TO_SETTINGS'] ?? 'Zu den Einstellungen' ?></a>
</div>
<?php endif; ?>

<!-- ================================================================
     GESAMT-ALARM (kombiniert alarm/ MQTT Topics)
     ================================================================ -->
<div class="sl-card <?= ($any_alarm === 0) ? 'collapsed' : '' ?>">
    <div class="sl-card-head">
        <span class="sl-card-head-title">🚦 <?= $L['MAIN.ALARM_TITLE'] ?? 'Gesamtstatus' ?></span>
        <?php if ($any_alarm > 0): ?>
        <span class="sl-badge <?= ['ok','yellow','warn','err'][$any_alarm] ?? 'ok' ?>">
            <?= $alarm_labels[$any_alarm] ?? '' ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="sl-card-body">
        <p class="sl-hint" style="margin:0 0 0.6rem"><?= $L['MAIN.ALARM_DESC'] ?? 'Alle Quellen kombiniert (ZAMG + INCA + TAWES)' ?></p>
        <div class="sl-alarm-grid">
<?php
$cat_keys = array_keys($alarm_cats);
foreach ($cat_keys as $key):
    $cat  = $alarm_cats[$key];
    $lv   = alarm_lv($key, $alarm);
?>
            <div class="sl-alarm-tile lv-<?= $lv ?>">
                <div class="sl-alarm-icon"><?= $cat['icon'] ?></div>
                <div class="sl-alarm-cat"><?= h($cat['cat']) ?></div>
                <div class="sl-alarm-lbl"><?= h($alarm_labels[$lv] ?? '') ?></div>
                <div class="sl-alarm-desc"><?= h($cat['descs'][$lv] ?? '') ?></div>
            </div>
<?php endforeach; ?>
        </div>
<?php if (!empty($alarm['zusammenfassung'])): ?>
        <div class="sl-notif" style="margin-top:0.5rem"><?= h($alarm['zusammenfassung']) ?></div>
<?php endif; ?>
<?php if ($akut): ?>
        <div class="sl-flash err" style="margin-top:0.5rem;margin-bottom:0">
            🚨 AKUTWARNUNG aktiv – stationsbasierte Extremwarnung!
        </div>
<?php endif; ?>
    </div>
</div>

<!-- ================================================================
     DAEMON STATUS & STEUERUNG
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head">
        <span class="sl-card-head-title">🔧 <?= h($L['MAIN.TITLE'] ?? 'Unwetter4Lox') ?> <?= h($L['MAIN.STATUS'] ?? 'Status') ?></span>
        <?php
        $log_titles = [
            'green'  => 'Log OK – keine Fehler/Warnungen in den letzten 30 min',
            'orange' => 'Warnungen in den letzten 30 min – Log prüfen',
            'red'    => 'Fehler in den letzten 30 min – Log prüfen',
            'none'   => '',
        ];
        if ($log_health !== 'none'):
        ?>
        <span class="sl-log-light <?= $log_health ?>" title="<?= $log_titles[$log_health] ?>"></span>
        <?php endif; ?>
        <span class="sl-badge <?= $daemon_running ? 'ok' : 'err' ?>">
            <?= $daemon_running ? ($L['MAIN.DAEMON_RUNNING'] ?? 'Läuft') : ($L['MAIN.DAEMON_STOPPED'] ?? 'Gestoppt') ?>
        </span>
    </div>
    <div class="sl-card-body">
        <div class="sl-daemon-row">
            <div>
                <div class="sl-daemon-name">
                    <span class="sl-status-dot <?= $daemon_running ? 'on' : 'off' ?>"></span>
                    Daemon
                    <?= $daemon_running ? ($L['MAIN.DAEMON_RUNNING'] ?? 'läuft') : ($L['MAIN.DAEMON_STOPPED'] ?? 'gestoppt') ?>
                </div>
                <div class="sl-daemon-meta">
                    Status:
                    <span class="sl-badge <?= strpos($status, 'Error') !== false || $is_stale ? 'err' : 'ok' ?>">
                        <?= h($status) ?>
                    </span>
                    &nbsp;·&nbsp; Letzter Abruf:
                    <b <?= $is_stale ? 'class="stale"' : '' ?>><?= h($state['letztes_update'] ?? '–') ?></b>
                    <?php if ($is_stale): ?>
                    <br><span class="stale">⚠️ Seit <?= round($age/60) ?> min keine Daten – Daemon hängt?</span>
                    <?php endif; ?>
                    <br>
                    🌩️ GeoSphere: <b><?= h($state['zamg_letztes_update'] ?? '–') ?></b>
                    &nbsp;·&nbsp; 📊 INCA: <b><?= h($state['inca_letztes_update'] ?? '–') ?></b>
                    &nbsp;·&nbsp; 🌐 TAWES: <b><?= h($state['tawes_letztes_update'] ?? $tawes['letztes_update'] ?? '–') ?></b>
                    <br>
                    📍 <?= h($cfg['LOCATION']['NAME'] ?? ($L['MAIN.NOT_CONFIGURED'] ?? '–')) ?>
                    &nbsp;·&nbsp; MQTT: <?= $mqtt_display ?>
                    &nbsp;·&nbsp; Interval: <?= (int)$interval ?>s
                </div>
            </div>
            <div class="sl-daemon-btns">
<?php if ($daemon_running): ?>
                <button id="btn-restart" class="sl-btn primary sm">↺ Restart</button>
                <a href="ajax.php?action=stop" class="sl-btn danger sm">■ Stop</a>
<?php else: ?>
                <button id="btn-start" class="sl-btn success sm" <?= !$coords_set ? 'disabled' : '' ?>>▶ Start</button>
<?php endif; ?>
<?php
// Log-Viewer Button ($_clog wurde bereits oben ermittelt)
if ($_clog):
?>
                <a href="/admin/system/tools/logfile.cgi?logfile=<?= urlencode($_clog) ?>&package=<?= urlencode($lbpplugindir) ?>&name=Daemon&header=html&format=template"
                   target="_blank" class="sl-btn secondary sm">📋 Log</a>
<?php endif; ?>
                <span id="daemon-action-status" style="font-size:0.75rem;color:#aaa;display:none"></span>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================
     GEOSPHERE WARNUNGEN (offizielle ZAMG)
     ================================================================ -->
<?php $notif_tages = trim($state['notification_tageswarnung'] ?? ''); ?>
<div class="sl-card <?= (!$irdw && !$akut && !$notif_tages) ? 'collapsed' : '' ?>">
    <div class="sl-card-head">
        <span class="sl-card-head-title">🌩️ <?= h($L['MAIN.GEO_WARNS'] ?? 'GeoSphere Austria – offizielle Warnungen') ?></span>
        <?php if ($akut || $irdw): ?>
        <span class="sl-badge <?= $akut ? 'err' : 'warn' ?>">
            <?= $akut ? 'AKUT' : 'Aktiv' ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="sl-card-body">
        <p class="sl-hint" style="margin:0 0 0.6rem">
            <span class="sl-tag ok">Grün</span>=keine &nbsp;
            <span class="sl-tag yellow">Gelb</span>=Vorsicht &nbsp;
            <span class="sl-tag warn">Orange</span>=Markant &nbsp;
            <span class="sl-tag err">Rot</span>=Unwetter &nbsp;
            <span class="sl-tag purple">Lila</span>=Extrem
        </p>
        <div class="sl-warn-grid">
<?php foreach ($zamg_typen as $t => [$icon, $name]):
    $w      = $zamg[$t] ?? [];
    $s      = (int)($w['stufe'] ?? 0);
    $active = ($w['aktiv'] ?? 0) || ($w['bald'] ?? 0);
?>
            <div class="sl-warn-card st-<?= $s ?> <?= $active ? 'active' : '' ?>">
                <div class="sl-warn-icon"><?= $icon ?></div>
                <div class="sl-warn-name"><?= h($name) ?></div>
                <div class="sl-warn-stufe" style="color:<?= ['#27ae60','#c9a500','#e67e22','#e74c3c','#8e44ad'][$s] ?? '#888' ?>">
                    <?= $zamg_stufe_label[$s] ?? '–' ?>
                </div>
<?php if ($active): ?>
                <span class="sl-warn-badge <?= ($w['aktiv'] ?? 0) ? 'aktiv' : 'bald' ?>">
                    <?= ($w['aktiv'] ?? 0) ? '▶ AKTIV' : '⏱ BALD' ?>
                </span>
<?php if (!empty($w['end_text'])): ?>
                <div style="font-size:0.6rem;color:#666;margin-top:3px">bis <?= h($w['end_text']) ?></div>
<?php endif; ?>
<?php endif; ?>
            </div>
<?php endforeach; ?>
        </div>
<?php if ($notif_tages):
?>
        <div class="sl-section-title" style="margin-top:0.9rem">📅 Tagesvorschau – was heute noch kommt</div>
        <div class="sl-notif" style="background:rgba(255,200,87,0.12);border-left:3px solid var(--amber)">
            <?= h($notif_tages) ?>
        </div>
        <p class="sl-hint" style="margin:0.3rem 0 0">Amtliche GeoSphere-Warnung – beginnt heute, aber noch nicht unmittelbar bevorstehend. Wird an Loxone als <code>notification/tageswarnung</code> gesendet.</p>
<?php endif; ?>
    </div>
</div>

<!-- ================================================================
     INCA NOWCAST
     ================================================================ -->
<div class="sl-card collapsed">
    <div class="sl-card-head">
        <span class="sl-card-head-title">📊 <?= h($L['MAIN.INCA_NOWCAST'] ?? 'INCA Nowcast') ?></span>
        <span style="font-size:0.72rem;color:#6b7f96">hochauflösend · alle 15 min</span>
    </div>
    <div class="sl-card-body">
        <p class="sl-hint" style="margin:0 0 0.6rem">Hochauflösende Kurzfristvorhersage von GeoSphere Austria (1 km², nächste 60 Minuten).</p>
        <?php
        $boen_sw  = (float)($cfg['THRESHOLDS']['BOEN_ALARM']  ?? 60);
        $regen_sw = (float)($cfg['THRESHOLDS']['REGEN_ALARM'] ?? 10);
        $fx_jetzt    = (float)($inca['fx_jetzt']    ?? 0);
        $ff_jetzt    = (float)($inca['ff_jetzt']    ?? 0);
        $fx_max_30   = (float)($inca['fx_max_30min'] ?? 0);
        $fx_max_60   = (float)($inca['fx_max_60min'] ?? 0);
        $rr_jetzt    = (float)($inca['rr_jetzt']    ?? 0);
        $pt_jetzt    = (int)($inca['pt_jetzt']      ?? 255);
        $pt_name     = $inca['pt_name']             ?? '–';
        $pt_bald_name = $inca['pt_bald_name']       ?? '';
        $mbr = (int)($inca['minuten_bis_regen']     ?? -1);
        if ($pt_jetzt === 255 && $mbr >= 0 && $pt_bald_name !== '') {
            $pt_display = "kein Niederschlag → {$pt_bald_name} in ~{$mbr} min";
        } else {
            $pt_display = $pt_name;
        }
        ?>
        <div class="sl-stat-grid">
            <div class="sl-stat">
                <div class="sl-stat-val" <?= $fx_jetzt >= $boen_sw ? 'style="color:var(--red)"' : '' ?>><?= number_format($fx_jetzt,1) ?></div>
                <div class="sl-stat-lbl">Böen jetzt (km/h)</div>
            </div>
            <div class="sl-stat">
                <div class="sl-stat-val"><?= number_format($ff_jetzt,1) ?></div>
                <div class="sl-stat-lbl">Wind jetzt (km/h)</div>
            </div>
            <div class="sl-stat">
                <div class="sl-stat-val" <?= $fx_max_30 >= $boen_sw ? 'style="color:var(--red)"' : '' ?>><?= number_format($fx_max_30,1) ?></div>
                <div class="sl-stat-lbl">Max Böen 30 min</div>
            </div>
            <div class="sl-stat">
                <div class="sl-stat-val" <?= $fx_max_60 >= $boen_sw ? 'style="color:var(--red)"' : '' ?>><?= number_format($fx_max_60,1) ?></div>
                <div class="sl-stat-lbl">Max Böen 60 min</div>
            </div>
            <div class="sl-stat">
                <div class="sl-stat-val" <?= $rr_jetzt >= $regen_sw ? 'style="color:var(--red)"' : ($rr_jetzt > 0.1 ? 'style="color:var(--blue)"' : '') ?>><?= number_format($rr_jetzt,2) ?></div>
                <div class="sl-stat-lbl">Regen jetzt (mm/h)</div>
            </div>
            <div class="sl-stat">
                <div class="sl-stat-val" style="font-size:0.88rem"><?= $mbr >= 0 ? "~{$mbr} min" : '☀️ trocken' ?></div>
                <div class="sl-stat-lbl">Regen kommt in</div>
            </div>
        </div>
        <ul class="sl-info-list">
            <li><span class="sl-info-key">Niederschlagstyp</span> <span class="sl-info-val"><?= h($pt_display) ?></span></li>
<?php if (!empty($inca['regen_trend'])): ?>
            <li><span class="sl-info-key">Regen-Trend</span> <span class="sl-info-val"><?= h($inca['regen_trend']) ?></span></li>
<?php endif; ?>
<?php if (isset($alarm['konfidenz'])): ?>
            <li><span class="sl-info-key">Alarm-Konfidenz</span> <span class="sl-info-val"><?= (int)$alarm['konfidenz'] ?>/100</span></li>
<?php endif; ?>
<?php if (isset($alarm['eta_min']) && (int)$alarm['eta_min'] >= 0): ?>
            <li><span class="sl-info-key">ETA Regen</span> <span class="sl-info-val alert">~<?= (int)$alarm['eta_min'] ?> min</span></li>
<?php endif; ?>
        </ul>
    </div>
</div>

<!-- ================================================================
     TAWES 360°
     ================================================================ -->
<?php if ($tawes_en): ?>
<div class="sl-card <?= empty($tawes) ? 'collapsed' : '' ?>">
    <div class="sl-card-head">
        <span class="sl-card-head-title">🌐 <?= h($L['MAIN.TAWES_TITLE'] ?? 'TAWES 360°') ?></span>
        <?php
        $regen_up = (int)($tawes['regen_upstream'] ?? 0);
        $sturm_up = (int)($tawes['sturm_upstream']  ?? 0);
        $gewitter_sig = (int)($tawes['gewitter_signal'] ?? 0);
        if ($gewitter_sig > 0) echo '<span class="sl-badge err">⚡ Gewitter</span>';
        elseif ($sturm_up)     echo '<span class="sl-badge warn">💨 Sturm upstream</span>';
        elseif ($regen_up)     echo '<span class="sl-badge info">🌧 Regen upstream</span>';
        ?>
    </div>
    <div class="sl-card-body">
<?php if (empty($tawes)): ?>
        <p class="sl-hint"><?= $L['MAIN.TAWES_NO_DATA'] ?? 'Noch keine TAWES-Daten – Daemon läuft seit kurzem.' ?></p>
<?php else: ?>
        <ul class="sl-info-list">
            <li>
                <span class="sl-info-key"><?= $L['MAIN.TAWES_WINDRICHTUNG'] ?? 'Dominante Windrichtung' ?></span>
                <span class="sl-info-val"><?= h($tawes['dominante_windrichtung_name'] ?? '–') ?> (<?= number_format($tawes['dominante_windrichtung'] ?? 0, 0) ?>°)</span>
            </li>
            <li>
                <span class="sl-info-key"><?= $L['MAIN.TAWES_UPSTREAM_COUNT'] ?? 'Upstream-Stationen' ?></span>
                <span class="sl-info-val"><?= (int)($tawes['upstream_aktiv'] ?? 0) ?></span>
            </li>
            <li>
                <span class="sl-info-key"><?= $L['MAIN.TAWES_WIND_UPSTREAM'] ?? 'Max Böen upstream' ?></span>
                <span class="sl-info-val <?= (float)($tawes['wind_upstream_kmh'] ?? 0) >= $boen_sw ? 'alert' : '' ?>">
                    <?= number_format($tawes['wind_upstream_kmh'] ?? 0, 1) ?> km/h
                </span>
            </li>
            <?php $trend = (int)($tawes['wind_trend'] ?? 0); ?>
            <li>
                <span class="sl-info-key"><?= $L['MAIN.TAWES_WIND_TREND'] ?? 'Wind-Trend' ?></span>
                <span class="sl-info-val <?= $trend > 0 ? 'alert' : ($trend < 0 ? 'ok' : '') ?>">
                    <?= $trend > 0 ? '↑ zunehmend' : ($trend < 0 ? '↓ abnehmend' : '→ stabil') ?>
                </span>
            </li>
            <?php
            $regen_up_mm = (float)($tawes['regen_upstream_mm'] ?? 0);
            $eta         = (int)($tawes['regen_eta_min'] ?? -1);
            ?>
            <li>
                <span class="sl-info-key"><?= $L['MAIN.TAWES_REGEN'] ?? 'Regen upstream' ?></span>
                <span class="sl-info-val <?= $regen_up ? 'warn' : 'ok' ?>">
                    <?= $regen_up
                        ? ($eta >= 0 ? "~{$eta} min" : ($L['MAIN.TAWES_ETA_UNKNOWN'] ?? 'Ankunft unbekannt'))
                        : ($L['MAIN.TAWES_KEIN_REGEN'] ?? 'Kein Regen') ?>
                    <?= ($regen_up && $regen_up_mm > 0) ? " ({$regen_up_mm} mm/h)" : '' ?>
                </span>
            </li>
            <?php if (!empty($tawes['regen_lokal_station'])): ?>
            <li>
                <span class="sl-info-key">Lokal-Regen</span>
                <span class="sl-info-val warn"><?= h($tawes['regen_lokal_station']) ?></span>
            </li>
            <?php endif; ?>
            <li>
                <span class="sl-info-key"><?= $L['MAIN.TAWES_GEWITTER'] ?? 'Gewitter-Signal' ?></span>
                <span class="sl-info-val <?= $gewitter_sig ? 'alert' : '' ?>">
                    <?= $gewitter_sig ? "⚡ Stufe {$gewitter_sig}" : ($L['MAIN.TAWES_NEIN'] ?? 'Kein Signal') ?>
                </span>
            </li>
            <li>
                <span class="sl-info-key"><?= $L['MAIN.TAWES_DRUCK_TREND'] ?? 'Drucktendenz' ?></span>
                <span class="sl-info-val <?= (float)($tawes['druck_trend'] ?? 0) < -0.3 ? 'alert' : '' ?>">
                    <?= number_format($tawes['druck_trend'] ?? 0, 2) ?> hPa/10min
                </span>
            </li>
        </ul>

<?php if (!empty($tawes['alle_stationen'])): ?>
        <div class="sl-section-title">Stationen (<?= count($tawes['alle_stationen']) ?>)</div>
        <div style="overflow-x:auto">
        <table class="sl-tbl">
            <thead>
                <tr>
                    <th>Station</th>
                    <th title="Entfernung">km</th>
                    <th title="Himmelsrichtung">Richt.</th>
                    <th title="Mittlerer Wind km/h">Wind</th>
                    <th title="Böenspitzen km/h">Böen</th>
                    <th title="Niederschlag mm/h">Regen</th>
                    <th title="Upstream">⬆</th>
                </tr>
            </thead>
            <tbody>
<?php
foreach ($tawes['alle_stationen'] as $st):
    $up     = (bool)($st['ist_upstream'] ?? false);
    $rr_raw = $st['RR'] ?? null;
    $rr_v   = $rr_raw !== null ? round($rr_raw * 6, 1) : null;
    $ffx_v  = $st['FFX_kmh'] ?? null;
    $rr_c   = $rr_v !== null && $rr_v >= $regen_sw ? 'color:var(--red)'
            : ($rr_v !== null && $rr_v > 0.5 ? 'color:var(--blue)' : '');
    $ffx_c  = $ffx_v !== null && $ffx_v >= $boen_sw ? 'color:var(--red)' : '';
?>
                <tr class="<?= $up ? 'upstream' : '' ?>">
                    <td><?= h($st['name'] ?? '') ?></td>
                    <td style="text-align:center"><?= number_format($st['dist_km'] ?? 0, 0) ?></td>
                    <td style="text-align:center"><?= h($st['bearing_name'] ?? '–') ?></td>
                    <td style="text-align:center"><?= $st['FF_kmh'] !== null ? number_format($st['FF_kmh'], 0) : '–' ?></td>
                    <td style="text-align:center;<?= $ffx_c ?>"><?= $ffx_v !== null ? number_format($ffx_v, 0) : '–' ?></td>
                    <td style="text-align:center;<?= $rr_c ?>"><?= $rr_v !== null ? number_format($rr_v, 1) : '–' ?></td>
                    <td style="text-align:center"><?= $up ? '⬆' : '' ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="sl-hint" style="margin-top:0.4rem">
            <b>⬆ Upstream</b> = Wind kommt gerade von dieser Station zu dir.
            <span style="color:var(--blue)">■</span> Regen &nbsp;
            <span style="color:var(--red)">■</span> ≥ Alarm-Schwelle
        </p>
<?php endif; ?>
        <p class="sl-hint">Letztes Update: <?= h($tawes['letztes_update'] ?? '–') ?></p>
<?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ================================================================
     AKTUELLE MELDUNGEN (notification/ Topics)
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head">
        <span class="sl-card-head-title">🔔 <?= h($L['MAIN.LAST_NOTIF'] ?? 'Aktuelle Meldungen') ?></span>
    </div>
    <div class="sl-card-body">
<?php
$notif_alle  = $state['notification_alle'] ?? '';
if ($notif_alle):
?>
        <div class="sl-section-title">🚨 Echtzeit-Alarm <span style="font-weight:400;font-size:0.72rem;color:#888">(notification/alle – für sofortige Push-Meldung)</span></div>
        <div class="sl-notif"><?= h($notif_alle) ?></div>
<?php else: ?>
        <div class="sl-notif" style="color:#888"><?= h($L['MAIN.NO_WARNS'] ?? 'Kein aktiver Alarm') ?></div>
<?php endif; ?>
<?php if ($notif_tages): ?>
        <div class="sl-section-title" style="margin-top:0.8rem">📅 Tagesvorschau <span style="font-weight:400;font-size:0.72rem;color:#888">(notification/tageswarnung – für Morgenroutine 07:00 Uhr)</span></div>
        <div class="sl-notif" style="background:rgba(255,200,87,0.12);border-left:3px solid var(--amber)"><?= h($notif_tages) ?></div>
<?php endif; ?>
    </div>
</div>

<!-- Auto-Refresh Statuszeile -->
<p class="sl-hint" style="text-align:center;margin-top:0.5rem">
    <span id="refresh-status">⟳ Auto-Aktualisierung aktiv</span>
</p>

<script>
(function() {
    var knownEpoch   = <?= (int)($state['letzter_abruf_epoch'] ?? 0) ?>;
    var knownRunning = <?= $daemon_running ? 'true' : 'false' ?>;
    var statusEl     = document.getElementById('refresh-status');
    var actionEl     = document.getElementById('daemon-action-status');
    var failCount    = 0;
    var waitForStart = false;

    function checkForUpdate() {
        fetch('ajax.php?action=check_update', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                failCount = 0;
                var newData    = d.epoch && d.epoch > knownEpoch;
                var statusFlip = typeof d.running !== 'undefined' && d.running !== knownRunning;
                if (waitForStart && d.running) {
                    if (actionEl) actionEl.textContent = '✓ Daemon läuft – wird geladen…';
                    location.reload(); return;
                }
                if (newData || statusFlip) {
                    if (statusEl) statusEl.textContent = '⟳ ' + (statusFlip ? 'Status geändert' : 'Neue Daten') + ' – wird geladen…';
                    location.reload();
                } else {
                    var now = new Date().toLocaleTimeString('de-AT', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
                    if (statusEl) statusEl.textContent = '⟳ Zuletzt geprüft: ' + now;
                }
            })
            .catch(function() {
                failCount++;
                if (statusEl) statusEl.textContent = '⚠ Verbindungsunterbrechung (' + failCount + ')';
            });
    }

    function daemonAction(action, label) {
        if (actionEl) { actionEl.textContent = label; actionEl.style.display = 'inline'; }
        document.querySelectorAll('#btn-restart, #btn-start').forEach(function(b) { b.disabled = true; });
        fetch('ajax.php?action=' + action, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function() {
                waitForStart = true;
                var tries = 0;
                var poll = setInterval(function() {
                    tries++;
                    checkForUpdate();
                    if (tries >= 20) { clearInterval(poll); location.reload(); }
                }, 3000);
            })
            .catch(function() {
                if (actionEl) actionEl.textContent = '⚠ Fehler';
                document.querySelectorAll('#btn-restart, #btn-start').forEach(function(b) { b.disabled = false; });
            });
    }

    var btnRestart = document.getElementById('btn-restart');
    if (btnRestart) btnRestart.addEventListener('click', function(e) {
        e.preventDefault();
        daemonAction('restart_json', '↺ Neustart läuft…');
    });
    var btnStart = document.getElementById('btn-start');
    if (btnStart) btnStart.addEventListener('click', function(e) {
        e.preventDefault();
        if (!btnStart.disabled) daemonAction('start_json', '▶ Startet…');
    });

    setTimeout(checkForUpdate, 5000);
    setInterval(checkForUpdate, 30000);
})();
</script>

<?php render_footer(); ?>
