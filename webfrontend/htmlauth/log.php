<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";

$L = LBSystem::readlanguage("language.ini");

$navbar[1]['Name'] = $L['MAIN.STATUS'];
$navbar[1]['URL']  = "index.php";
$navbar[2]['Name'] = $L['MAIN.SETTINGS'];
$navbar[2]['URL']  = "settings.php";
$navbar[3]['Name'] = $L['MAIN.LOG'];
$navbar[3]['URL']  = "log.php";
$navbar[3]['active'] = true;
$navbar[4]['Name'] = $L['MAIN.HELP'];
$navbar[4]['URL']  = "help.php";

# Alle Session-Dateien im Log-Verzeichnis finden, sortiert nach Änderungszeit (neueste zuerst)
$allsessions = glob($lbplogdir . '/*.log') ?: [];
usort($allsessions, function($a, $b) { return filemtime($b) - filemtime($a); });

# Aktuelle Session – Pointer-Datei hat Vorrang, Fallback auf neueste Datei
$ptr_file   = $lbplogdir . '/daemon.log.current';
$active_log = file_exists($ptr_file) ? trim(file_get_contents($ptr_file)) : null;
if ($active_log && !file_exists($active_log)) $active_log = null;
if (!$active_log && !empty($allsessions)) $active_log = $allsessions[0];

$raw_sess = $_GET['session'] ?? null;
$selected = null;
if ($raw_sess) {
    $safe_sess = preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($raw_sess));
    if (file_exists($lbplogdir . '/' . $safe_sess)) $selected = $lbplogdir . '/' . $safe_sess;
}
if (!$selected && !empty($allsessions)) $selected = $allsessions[0];
$logexists = $selected && file_exists($selected) && filesize($selected) > 0;
$log_lines = $logexists ? array_slice(file($selected), -300) : [];

if (isset($_GET['clear']) && $_GET['clear'] === '1' && $selected) {
    file_put_contents($selected, '');
    header("Location: log.php");
    exit;
}

function session_label($filepath) {
    $base = basename($filepath, '.log');
    if (preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $base, $m)) {
        return sprintf('%s.%s.%s %s:%s', $m[3],$m[2],$m[1], $m[4],$m[5]);
    }
    return date('d.m.Y H:i', filemtime($filepath));
}

LBWeb::lbheader($L['MAIN.TITLE'] . " – " . $L['MAIN.LOG'], "https://github.com/HitsmartDev/Unwetter4Lox", "");
?>

<style>
    .log-container { background: #1a1a1a; border-radius: 8px; border: 1px solid #333; overflow: hidden; margin-top: 15px; }
    .log-header { background: #2a2a2a; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; }
    .log-body { padding: 15px; font-family: 'Courier New', Courier, monospace; font-size: 13px; line-height: 1.5; color: #eee; max-height: 600px; overflow-y: auto; white-space: pre-wrap; word-break: break-word; }
    .log-footer { background: #222; padding: 8px 15px; font-size: 11px; color: #777; border-top: 1px solid #333; }
    .log-active { border-left: 4px solid #4CAF50 !important; }
    .log-selected { background-color: #333 !important; }
</style>

<div style="display:flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px">
    <div style="display:flex; gap:8px">
        <a href="log.php" data-role="button" data-icon="refresh" data-mini="true" data-inline="true">Aktualisieren</a>
        <?php if ($logexists): ?>
            <?php
            $_lp = ["PACKAGE" => $lbpplugindir, "NAME" => "Daemon", "LABEL" => $L['MAIN.LOG_VIEWER'], "CLASS" => "ui-btn-inline ui-mini"];
            if ($active_log && file_exists($active_log)) $_lp['LOGFILE'] = $active_log;
            echo LBWeb::logfile_button_html($_lp);
            ?>
            <a href="log.php?clear=1" data-role="button" data-icon="delete" data-mini="true" data-inline="true" onclick="return confirm('Log wirklich leeren?')">Leeren</a>
        <?php endif; ?>
    </div>
    <div style="font-size:12px; color:#888">
        Datei: <b><?= $logexists ? basename($selected) : '–' ?></b>
    </div>
</div>

<?php if (count($allsessions) > 1): ?>
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a" data-mini="true">
    <h3>📂 <?= $L['MAIN.OLDER_SESSIONS'] ?> (<?= count($allsessions) - 1 ?>)</h3>
    <div style="display:flex; flex-direction: column; gap: 2px">
    <?php foreach ($allsessions as $sf):
        $label = session_label($sf);
        $sfbase = basename($sf);
        $is_sel = ($selected && realpath($sf) === realpath($selected));
        $is_act = ($active_log && realpath($sf) === realpath($active_log));
        $class = "log-session-btn" . ($is_sel ? " log-selected" : "") . ($is_act ? " log-active" : "");
    ?>
        <a href="log.php?session=<?= urlencode($sfbase) ?>" class="<?= $class ?>" data-role="button" data-mini="true" data-inline="true" data-theme="a">
            <?= htmlspecialchars($label) ?> (<?= round(filesize($sf) / 1024, 1) ?> KB) <?= $is_act ? '🟢' : '' ?>
        </a>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="log-container">
    <div class="log-header">
        <span style="color:#aaa; font-weight:bold"><?= $L['MAIN.TERMINAL_PREVIEW'] ?></span>
        <span style="font-size:10px; color:#666"><?= $L['MAIN.SHOW_300'] ?></span>
    </div>
    <div class="log-body" id="logbody"><?php
    if ($logexists) {
        foreach ($log_lines as $line) {
            $e = htmlspecialchars($line);
            if (strpos($line, '<OK>') !== false || strpos($line, 'LOGSTART') !== false) echo '<span style="color:#4CAF50">' . $e . '</span>';
            elseif (strpos($line, 'LOGEND') !== false) echo '<span style="color:#888">' . $e . '</span>';
            elseif (strpos($line, '<WARNING>') !== false) echo '<span style="color:#FF9800">' . $e . '</span>';
            elseif (strpos($line, '<ERR>') !== false) echo '<span style="color:#f44336">' . $e . '</span>';
            elseif (strpos($line, '<CRIT>') !== false) echo '<span style="color:#e91e63;font-weight:bold">' . $e . '</span>';
            elseif (strpos($line, '<DEBUG>') !== false) echo '<span style="color:#666">' . $e . '</span>';
            else echo $e;
        }
    } else {
        echo '<div style="text-align:center; padding:50px 0; color:#555">' . $L['MAIN.LOG_EMPTY'] . '<br><br><a href="index.php" data-role="button" data-inline="true" data-mini="true">' . $L['MAIN.START_DAEMON'] . '</a></div>';
    }
    ?></div>
</div>

<script>
$(function(){
    var lb = document.getElementById('logbody');
    if (lb) lb.scrollTop = lb.scrollHeight;
});
</script>

<?php LBWeb::lbfooter(); ?>
