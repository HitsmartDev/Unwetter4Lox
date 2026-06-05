<?php
# Unwetter4Lox - Daemon Steuerung
require_once "loxberry_system.php";

$action = $_GET['action'] ?? '';
if (!in_array($action, ['start', 'stop', 'restart'])) {
    header("Location: index.php");
    exit;
}

$daemonscript = $lbhomedir . "/system/daemons/plugins/" . $lbpplugindir;
$cmd = "sudo " . escapeshellarg($daemonscript) . " " . escapeshellarg($action) . " 2>&1";
$out = shell_exec($cmd);
sleep(1);

header("Location: index.php");
exit;
