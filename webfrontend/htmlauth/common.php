<?php
/**
 * common.php – gemeinsamer Seitenkopf/Fuß für app_*.php (Unwetter4Lox)
 *
 * Setzt voraus, dass loxberry_system.php bereits vom aufrufenden Skript
 * eingebunden wurde und $L, $lbpconfigdir, $lbpplugindir verfügbar sind.
 *
 * Kein loxberry_web.php hier – jQuery Mobile darf nicht ins iframe gelangen.
 */

// Plugin-Version aus plugin.cfg lesen
$_pcfg_path = $lbpconfigdir . '/plugin.cfg';
$_pcfg      = file_exists($_pcfg_path) ? parse_ini_file($_pcfg_path, true) : [];
$PLUGIN_VERSION = $_pcfg['PLUGIN']['VERSION'] ?? '?';

// CSRF-Session
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['u4l_csrf'])) {
    $_SESSION['u4l_csrf'] = bin2hex(random_bytes(16));
}
function u4l_csrf(): string { return $_SESSION['u4l_csrf']; }

// XSS-sicheres Escaping
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render_header(string $active): void
{
    global $L, $PLUGIN_VERSION, $lbpplugindir;

    $tabs = [
        'app_status'   => '🚦 ' . ($L['MAIN.STATUS']   ?? 'Status'),
        'app_settings' => '⚙️ '  . ($L['MAIN.SETTINGS'] ?? 'Einstellungen'),
        'app_log'      => '📋 ' . ($L['MAIN.LOG']      ?? 'Log'),
        'app_help'     => '❓ '  . ($L['MAIN.HELP']     ?? 'Hilfe'),
    ];

    // Cache-Buster für Assets
    $_cssVer = is_file(__DIR__ . '/assets/style.css') ? filemtime(__DIR__ . '/assets/style.css') : '0';
    $_jsVer  = is_file(__DIR__ . '/assets/app.js')   ? filemtime(__DIR__ . '/assets/app.js')   : '0';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="sl-csrf" content="<?= h(u4l_csrf()) ?>">
<title><?= h($L['MAIN.TITLE'] ?? 'Unwetter4Lox') ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= h((string)$_cssVer) ?>">
<script src="assets/app.js?v=<?= h((string)$_jsVer) ?>"></script>
</head>
<body class="sl-embed">
<header class="sl-header">
    <div class="sl-header-banner">
        <div class="sl-brand">
            <span class="sl-title">⛈ <?= h($L['MAIN.TITLE'] ?? 'Unwetter4Lox') ?></span>
            <span class="sl-subtitle">GeoSphere &amp; INCA &amp; TAWES 360° → MQTT → Loxone</span>
        </div>
        <span class="sl-version">v<?= h($PLUGIN_VERSION) ?></span>
    </div>
    <div class="sl-header-nav">
        <nav class="sl-nav">
<?php foreach ($tabs as $page => $label): ?>
            <a href="<?= h($page) ?>.php" class="sl-tab <?= $active === $page ? 'active' : '' ?>"><?= h($label) ?></a>
<?php endforeach; ?>
        </nav>
    </div>
</header>
<main class="sl-main">
<?php
}

function render_footer(): void
{
?>
</main>
<footer class="sl-footer">
    &copy; <?= date('Y') ?> HitSmart / Stefan &nbsp;·&nbsp;
    <a href="https://github.com/HitsmartDev/Unwetter4Lox" target="_blank" style="color:inherit">GitHub</a>
</footer>
<div id="sl-toast" class="sl-toast"></div>
<script>
// Iframe-Höhe regelmäßig berichten (zusätzlich zu app.js – für Inhalt der nach DOMContentLoaded kommt)
(function () {
    if (window.parent === window) return;
    var lastH = -1, pending = null;
    function report() {
        var h = Math.ceil(document.documentElement.scrollHeight / 10) * 10;
        if (h === lastH) return;
        lastH = h;
        try { window.parent.postMessage({ type: 'sl-height', value: h }, '*'); } catch (e) {}
    }
    function schedule() { if (!pending) pending = setTimeout(function(){ pending = null; report(); }, 50); }
    if (document.readyState === 'complete') report(); else window.addEventListener('load', report);
    window.addEventListener('resize', schedule);
    if ('MutationObserver' in window) {
        var mo = new MutationObserver(schedule);
        mo.observe(document.body, { childList:true, subtree:true, attributes:true });
    }
    setInterval(report, 1500);
})();
</script>
</body>
</html>
<?php
}

function flash_err(string $m):  void { echo '<div class="sl-flash err">',  h($m), '</div>'; }
function flash_ok(string $m):   void { echo '<div class="sl-flash ok">',   h($m), '</div>'; }
function flash_warn(string $m): void { echo '<div class="sl-flash warn">', h($m), '</div>'; }
