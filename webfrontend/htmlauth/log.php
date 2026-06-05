<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";

$L = LBSystem::readlanguage("language.ini");

$navbar[1]['Name'] = "Status";
$navbar[1]['URL']  = "index.php";
$navbar[2]['Name'] = "Einstellungen";
$navbar[2]['URL']  = "settings.php";
$navbar[3]['Name'] = "Log";
$navbar[3]['URL']  = "log.php";
$navbar[3]['active'] = true;

# Alle Session-Dateien im Log-Verzeichnis finden (LoxBerry::Log erstellt diese)
$allsessions = glob($lbplogdir . '/*.log') ?: [];
rsort($allsessions); // Neueste zuerst

# Aktive Session aus daemon.log.current (von Python-Daemon geschrieben)
$ptr_file   = $lbplogdir . '/daemon.log.current';
$active_log = file_exists($ptr_file) ? trim(file_get_contents($ptr_file)) : null;

# Gewünschte Session (GET-Parameter oder aktive oder neueste)
$raw_sess = isset($_GET['session']) ? $_GET['session'] : null;
$safe_sess = $raw_sess ? preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($raw_sess)) : null;
if ($safe_sess && file_exists($lbplogdir . '/' . $safe_sess)) {
    $selected = $lbplogdir . '/' . $safe_sess;
} elseif ($active_log && file_exists($active_log)) {
    $selected = $active_log;
} elseif (!empty($allsessions)) {
    $selected = $allsessions[0];
} else {
    $selected = null;
}

$logexists = $selected && file_exists($selected) && filesize($selected) > 0;

# Log leeren
if (isset($_GET['clear']) && $_GET['clear'] === '1' && $selected) {
    file_put_contents($selected, '');
    header("Location: log.php");
    exit;
}

# Ausgewählte Session mit LoxBerry Log-System registrieren
$log_obj = null;
if ($logexists) {
    $log_obj = LBLog::newLog([
        "name"     => "Daemon",
        "filename" => $selected,
        "append"   => 1
    ]);
}

# Hilfsfunktion: Zeitstempel aus Log-Dateiname lesen (Fallback: Datei-ctime)
function session_label($filepath) {
    $base = basename($filepath, '.log');
    // LoxBerry::Log erstellt Dateien mit Timestamp im Namen
    if (preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $base, $m)) {
        return sprintf('%s.%s.%s %s:%s', $m[3],$m[2],$m[1], $m[4],$m[5]);
    }
    // LoxBerry::Log internes Format versuchen (<LOGSTART> aus Datei lesen)
    $fh = @fopen($filepath, 'r');
    if ($fh) {
        for ($i = 0; $i < 3; $i++) {
            $line = fgets($fh);
            if ($line && strpos($line, '<LOGSTART>') !== false) {
                fclose($fh);
                return substr(trim($line), 0, 19); // "YYYY-MM-DD HH:MM:SS"
            }
        }
        fclose($fh);
    }
    return date('d.m.Y H:i', filemtime($filepath));
}

LBWeb::lbheader("Unwetter4Lox – Log", "https://github.com/HitsmartDev/Unwetter4Lox", "");
?>

<div style="margin:8px 0 10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <a href="log.php"
       data-role="button" data-inline="true" data-mini="true" data-theme="a">
       🔄 Aktualisieren
    </a>
    <?php if ($logexists && $log_obj): ?>
    <?= LBWeb::logfile_button_html(["NAME" => "Daemon", "LABEL" => "📋 LoxBerry Logviewer"]) ?>
    <?php elseif ($logexists): ?>
    <a href="/admin/system/tools/logfile.cgi?logfile=plugins/<?= urlencode($lbpplugindir) ?>/<?= urlencode(basename($selected)) ?>&header=html&format=template"
       data-role="button" data-inline="true" data-mini="true" data-theme="b"
       target="_blank">📋 LoxBerry Logviewer</a>
    <?php endif; ?>
    <?php if ($logexists): ?>
    <a href="log.php?clear=1"
       data-role="button" data-inline="true" data-mini="true" data-theme="a"
       onclick="return confirm('Aktive Log-Session leeren?')">🗑 Log leeren</a>
    <?php endif; ?>
</div>

<?php if (count($allsessions) > 1): ?>
<!-- Session-Auswahl -->
<div data-role="collapsible" data-collapsed="true" data-theme="a" data-content-theme="a">
<h3>📂 Log-Sitzungen (<?= count($allsessions) ?> gesamt)</h3>
<ul data-role="listview" data-inset="true">
<?php foreach ($allsessions as $sf):
    $label   = session_label($sf);
    $sfbase  = basename($sf);
    $is_sel  = ($selected && realpath($sf) === realpath($selected));
    $is_act  = ($active_log && realpath($sf) === realpath($active_log));
    $badge   = $is_act ? ' 🟢' : '';
    $theme   = $is_sel ? ' data-theme="b"' : '';
?>
<li<?= $theme ?>><a href="log.php?session=<?= urlencode($sfbase) ?>">
    <?= htmlspecialchars($label) ?><?= $badge ?>
    <span class="ui-li-count"><?= round(filesize($sf) / 1024, 1) ?> KB</span>
</a></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>

<?php if ($logexists):
    $lines = file($selected);
    $lines = array_slice($lines, -300); // letzte 300 Zeilen
?>
<pre id="logcontent" style="background:#0d0d0d;color:#c0c0c0;padding:12px;border-radius:4px;font-size:11px;line-height:1.55;overflow-x:auto;white-space:pre-wrap;word-break:break-all;border:1px solid #2a2a2a;max-height:65vh;overflow-y:auto"><?php
foreach ($lines as $line) {
    $e = htmlspecialchars($line);
    if     (strpos($line, '<OK>')      !== false
         || strpos($line, 'LOGSTART')  !== false)
        echo '<span style="color:#4CAF50">'                  . $e . '</span>';
    elseif (strpos($line, 'LOGEND')    !== false)
        echo '<span style="color:#888">'                     . $e . '</span>';
    elseif (strpos($line, '<WARNING>') !== false)
        echo '<span style="color:#FF9800">'                  . $e . '</span>';
    elseif (strpos($line, '<ERR>')     !== false)
        echo '<span style="color:#f44336">'                  . $e . '</span>';
    elseif (strpos($line, '<CRIT>')    !== false)
        echo '<span style="color:#e91e63;font-weight:bold">' . $e . '</span>';
    elseif (strpos($line, '<DEBUG>')   !== false)
        echo '<span style="color:#888">'                     . $e . '</span>';
    else
        echo $e;
}
?></pre>
<?php else: ?>
<div class="ui-body ui-body-a" style="text-align:center;margin:16px 0;padding:20px">
    <p style="color:#888;margin:0 0 6px;font-weight:bold">Noch kein Log vorhanden</p>
    <p style="color:#888;margin:0;font-size:12px">
        Daemon über den <a href="index.php">Status-Tab</a> starten.<br>
        SSH-Test:<br>
        <code>sudo <?= htmlspecialchars($lbhomedir) ?>/system/daemons/plugins/<?= htmlspecialchars($lbpplugindir) ?> start</code>
    </p>
</div>
<?php endif; ?>

<script>
$(function(){
    var el = document.getElementById('logcontent');
    if (el) el.scrollTop = el.scrollHeight;
});
</script>

<?php LBWeb::lbfooter(); ?>
