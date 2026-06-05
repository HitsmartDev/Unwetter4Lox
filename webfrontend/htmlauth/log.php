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

$logfile   = $lbplogdir . "/unwetter4lox.log";
$logexists = file_exists($logfile) && filesize($logfile) > 0;

# Log leeren
if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    if (file_exists($logfile)) file_put_contents($logfile, '');
    header("Location: log.php");
    exit;
}

# LoxBerry Log-System: Logfile registrieren damit es im Log-Manager erscheint
# append=1 verhindert dass ein neues File angelegt wird
if ($logexists) {
    $log = LBLog::newLog([
        "name"     => "Daemon",
        "filename" => $logfile,
        "append"   => 1
    ]);
}

LBWeb::lbheader("Unwetter4Lox – Log", "https://wiki.loxberry.de", "");
?>

<div style="margin:8px 0 10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <a href="log.php"
       data-role="button" data-inline="true" data-mini="true" data-theme="a">
       🔄 Aktualisieren
    </a>
    <?php if ($logexists): ?>
    <?php if (isset($log)): ?>
    <?= LBWeb::logfile_button_html(["NAME" => "Daemon", "LABEL" => "📋 LoxBerry Logviewer"]) ?>
    <?php else: ?>
    <a href="/admin/system/tools/logfile.cgi?logfile=plugins/<?= urlencode($lbpplugindir) ?>/unwetter4lox.log&header=html&format=template"
       data-role="button" data-inline="true" data-mini="true" data-theme="b"
       target="_blank">📋 LoxBerry Logviewer</a>
    <?php endif; ?>
    <a href="log.php?clear=1"
       data-role="button" data-inline="true" data-mini="true" data-theme="a"
       onclick="return confirm('Log leeren?')">🗑 Log leeren</a>
    <?php endif; ?>
</div>

<?php if ($logexists):
    $lines = file($logfile);
    $lines = array_slice($lines, -200);
?>
<pre id="logcontent" style="background:#0d0d0d;color:#c0c0c0;padding:12px;border-radius:4px;font-size:11px;line-height:1.55;overflow-x:auto;white-space:pre-wrap;word-break:break-all;border:1px solid #2a2a2a;max-height:65vh;overflow-y:auto"><?php
foreach ($lines as $line) {
    $e = htmlspecialchars($line);
    if     (strpos($line,'<OK>')      !== false
         || strpos($line,'LOGSTART')  !== false
         || strpos($line,'LOGEND')    !== false)
        echo '<span style="color:#4CAF50">'          . $e . '</span>';
    elseif (strpos($line,'<WARNING>') !== false)
        echo '<span style="color:#FF9800">'          . $e . '</span>';
    elseif (strpos($line,'<ERR>')     !== false)
        echo '<span style="color:#f44336">'          . $e . '</span>';
    elseif (strpos($line,'<CRIT>')    !== false)
        echo '<span style="color:#e91e63;font-weight:bold">' . $e . '</span>';
    elseif (strpos($line,'<DEBUG>')   !== false)
        echo '<span style="color:#888">'             . $e . '</span>';
    else
        echo $e;
}
?></pre>
<?php else: ?>
<div class="ui-body ui-body-a" style="text-align:center;margin:16px 0;padding:20px">
    <p style="color:#888;margin:0 0 6px;font-weight:bold">Noch kein Log vorhanden</p>
    <p style="color:#888;margin:0;font-size:12px">
        Daemon über den <a href="index.php">Status-Tab</a> starten.<br>
        Falls der Start fehlschlägt, per SSH prüfen:<br>
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
