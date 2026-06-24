<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";

$L = LBSystem::readlanguage("language.ini");

$navbar[1]['Name'] = $L['MAIN.STATUS'];    $navbar[1]['URL'] = "index.php";
$navbar[2]['Name'] = $L['MAIN.SETTINGS'];  $navbar[2]['URL'] = "settings.php";
$navbar[3]['Name'] = $L['MAIN.LOG'];       $navbar[3]['URL'] = "log.php"; $navbar[3]['active'] = true;
$navbar[4]['Name'] = $L['MAIN.HELP'];      $navbar[4]['URL'] = "help.php";

# --- Pointer-Datei: daemon.log.current enthält absoluten Pfad zur aktuellen Log-Datei ---
$ptr_file    = $lbplogdir . '/daemon.log.current';
$current_log = null;
if (file_exists($ptr_file)) {
    $ptr_val = trim(file_get_contents($ptr_file));
    if ($ptr_val && file_exists($ptr_val)) $current_log = $ptr_val;
}

# Fallback: daemon.log Symlink direkt nutzen
if (!$current_log) {
    $stable = $lbplogdir . '/daemon.log';
    if (file_exists($stable)) $current_log = $stable;
}

# Wenn daemon.log.current gültig → direkt zum LoxBerry Log-Viewer (überspringt Session-Liste)
# Das funktioniert unabhängig davon welche Extension das LoxBerry SDK verwendet
if (!isset($_GET['session']) && !isset($_GET['list']) && $current_log) {
    $url = "/admin/system/tools/logfile.cgi"
         . "?logfile=" . urlencode($current_log)
         . "&package=" . urlencode($lbpplugindir)
         . "&name=Daemon&header=html&format=template";
    header("Location: " . $url);
    exit;
}

# --- Session-Liste: alle *.log Dateien im Log-Dir (außer daemon.log Symlink) ---
$allsessions_raw = glob($lbplogdir . '/*.log') ?: [];

# Deduplizieren: daemon.log ist Symlink auf eine andere Session-Datei → nur echte Files
$real_map = [];   // realpath → original path
foreach ($allsessions_raw as $f) {
    $real = realpath($f) ?: $f;
    $base = basename($f);
    # daemon.log bevorzugen wir nicht direkt, aber wir nutzen seinen realpath als Eintrag
    if (!isset($real_map[$real])) {
        $real_map[$real] = $f;
    } elseif ($base !== 'daemon.log') {
        # Echter Dateiname hat Vorrang über Symlink
        $real_map[$real] = $f;
    }
}
$allsessions = array_values($real_map);
usort($allsessions, function($a, $b) { return filemtime($b) - filemtime($a); });

# current_log für die Session-Liste normalisieren
if (!$current_log && !empty($allsessions)) $current_log = $allsessions[0];

# Gewünschte Session aus URL-Parameter
$raw_sess = $_GET['session'] ?? null;
if ($raw_sess) {
    $safe_sess = preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($raw_sess));
    $sess_path = $lbplogdir . '/' . $safe_sess;
    if (file_exists($sess_path)) {
        $url = "/admin/system/tools/logfile.cgi"
             . "?logfile=" . urlencode($sess_path)
             . "&package=" . urlencode($lbpplugindir)
             . "&name=Daemon&header=html&format=template";
        header("Location: " . $url);
        exit;
    }
}

LBWeb::lbheader($L['MAIN.TITLE'] . " – " . $L['MAIN.LOG'], "https://github.com/HitsmartDev/Unwetter4Lox", "");
?>

<?php if (empty($allsessions)): ?>
<div style="text-align:center; padding:40px 0; color:#888">
    <p><?= htmlspecialchars($L['MAIN.LOG_EMPTY']) ?></p>
    <a href="index.php" data-role="button" data-inline="true" data-mini="true"><?= htmlspecialchars($L['MAIN.START_DAEMON']) ?></a>
    <p style="font-size:11px;color:#aaa;margin-top:16px">Log-Verzeichnis: <?= htmlspecialchars($lbplogdir) ?></p>
</div>

<?php else: ?>

<style>
.log-list { width:100%; border-collapse:collapse; margin:8px 0; font-size:12px }
.log-list th { background:#e8e8e8; padding:7px 8px; text-align:left; font-weight:bold }
.log-list td { padding:6px 8px; border-bottom:1px solid #f0f0f0; vertical-align:middle }
.log-list tr:hover td { background:#f5f5f5 }
.badge-current { background:#4CAF50; color:white; padding:1px 6px; border-radius:3px; font-size:10px; margin-left:4px }
.badge-old { background:#9e9e9e; color:white; padding:1px 6px; border-radius:3px; font-size:10px; margin-left:4px }
</style>

<p style="font-size:12px;color:#555;margin:8px 4px">
    <b><?= count($allsessions) ?></b> Log-Sessions gefunden. Klicke auf eine Session um sie im LoxBerry Log-Viewer zu öffnen.
    <a href="log.php?list=1" style="margin-left:8px;font-size:11px">↻ Aktualisieren</a>
</p>

<table class="log-list">
<thead>
<tr>
    <th>Session</th>
    <th>Geändert</th>
    <th>Größe</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php
$idx = 0;
foreach ($allsessions as $logfile):
    $basename    = basename($logfile);
    $mtime       = filemtime($logfile);
    $size        = filesize($logfile);
    $size_str    = $size > 1048576 ? round($size/1048576, 1) . ' MB'
                 : ($size > 1024 ? round($size/1024, 1) . ' KB' : $size . ' B');
    $real_cur    = $current_log ? (realpath($current_log) ?: $current_log) : null;
    $real_file   = realpath($logfile) ?: $logfile;
    $is_current  = ($real_file === $real_cur);
    $date_str    = date('d.m.Y H:i:s', $mtime);
    $sess_param  = urlencode($basename);
    $viewer_url  = "log.php?session=" . $sess_param;
    $idx++;
?>
<tr>
    <td>
        <a href="<?= htmlspecialchars($viewer_url) ?>" style="text-decoration:none;color:#1565C0;font-weight:<?= $is_current ? 'bold' : 'normal' ?>">
            <?= htmlspecialchars($basename) ?>
        </a>
        <?php if ($is_current): ?>
            <span class="badge-current">Aktuell</span>
        <?php elseif ($idx === 2): ?>
            <span class="badge-old">Vorherige</span>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap"><?= $date_str ?></td>
    <td style="white-space:nowrap"><?= $size_str ?></td>
    <td>
        <a href="<?= htmlspecialchars($viewer_url) ?>" data-role="button" data-mini="true" data-inline="true" data-icon="arrow-r">Öffnen</a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p style="font-size:11px;color:#999;margin:8px 4px">
    Der Daemon erstellt bei jedem Start eine neue Log-Datei. Es werden maximal 7 Sessions aufbewahrt, ältere werden automatisch gelöscht.
</p>

<?php endif; ?>

<?php LBWeb::lbfooter(); ?>
