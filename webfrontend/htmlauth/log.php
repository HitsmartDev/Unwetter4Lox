<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";

$L = LBSystem::readlanguage("language.ini");

# Log-Dateien nach Änderungszeit sortiert (neueste zuerst)
$allsessions = glob($lbplogdir . '/*.log') ?: [];
usort($allsessions, function($a, $b) { return filemtime($b) - filemtime($a); });

# Pointer-Datei hat Vorrang, Fallback auf neueste Datei
$ptr_file   = $lbplogdir . '/daemon.log.current';
$current_log = file_exists($ptr_file) ? trim(file_get_contents($ptr_file)) : null;
if ($current_log && !file_exists($current_log)) $current_log = null;
if (!$current_log && !empty($allsessions)) $current_log = $allsessions[0];

# Spezifische Session aus URL
$raw_sess = $_GET['session'] ?? null;
if ($raw_sess) {
    $safe_sess = preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($raw_sess));
    $sess_path = $lbplogdir . '/' . $safe_sess;
    if (file_exists($sess_path)) $current_log = $sess_path;
}

# Redirect zum LoxBerry Standard-Log-Viewer
if ($current_log && file_exists($current_log)) {
    $url = "/admin/system/tools/logfile.cgi"
         . "?logfile=" . urlencode($current_log)
         . "&package=" . urlencode($lbpplugindir)
         . "&name=Daemon&header=html&format=template";
    header("Location: " . $url);
    exit;
}

# Fallback: kein Log vorhanden
$navbar[1]['Name'] = $L['MAIN.STATUS'];
$navbar[1]['URL']  = "index.php";
$navbar[2]['Name'] = $L['MAIN.SETTINGS'];
$navbar[2]['URL']  = "settings.php";
$navbar[3]['Name'] = $L['MAIN.LOG'];
$navbar[3]['URL']  = "log.php";
$navbar[3]['active'] = true;
$navbar[4]['Name'] = $L['MAIN.HELP'];
$navbar[4]['URL']  = "help.php";

LBWeb::lbheader($L['MAIN.TITLE'] . " – " . $L['MAIN.LOG'], "https://github.com/HitsmartDev/Unwetter4Lox", "");
?>
<div style="text-align:center; padding:40px 0; color:#888">
    <p><?= htmlspecialchars($L['MAIN.LOG_EMPTY']) ?></p>
    <a href="index.php" data-role="button" data-inline="true" data-mini="true"><?= htmlspecialchars($L['MAIN.START_DAEMON']) ?></a>
</div>
<?php LBWeb::lbfooter(); ?>
