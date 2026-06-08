<?php
# Unwetter4Lox - Daemon Steuerung
require_once "loxberry_system.php";

$action = $_GET['action'] ?? '';

# Geocoding via Nominatim (OSM)
if ($action === 'geocode') {
    $q = trim($_GET['q'] ?? '');
    if (!$q) { echo json_encode(['error'=>'Keine Adresse angegeben']); exit; }
    
    $url = "https://nominatim.openstreetmap.org/search?format=json&limit=1&q=" . urlencode($q);
    $opts = ['http' => ['header' => "User-Agent: Unwetter4Lox-Plugin\r\n"]];
    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);
    $data = json_decode($res, true);
    
    if ($data && isset($data[0])) {
        echo json_encode([
            'lat' => $data[0]['lat'],
            'lon' => $data[0]['lon'],
            'display_name' => $data[0]['display_name']
        ]);
    } else {
        echo json_encode(['error' => 'Adresse nicht gefunden']);
    }
    exit;
}

# Koordinaten vom Loxone Miniserver via LoxBerry-Config abrufen
if ($action === 'get_miniserver_coords') {
    header('Content-Type: application/json');

    # LoxBerry general.json lesen – enthält Miniserver-Zugangsdaten
    $gen_file = $lbhomedir . '/config/system/general.json';
    $gen      = file_exists($gen_file) ? json_decode(file_get_contents($gen_file), true) : null;

    if (!$gen) {
        echo json_encode(['error' => 'LoxBerry general.json nicht gefunden oder lesbar.']);
        exit;
    }

    # Ersten konfigurierten Miniserver finden
    $ms = null;
    foreach (['Miniserver', 'miniserver'] as $key) {
        if (!empty($gen[$key]) && is_array($gen[$key])) {
            $ms = reset($gen[$key]);
            break;
        }
    }

    if (!$ms) {
        echo json_encode(['error' => 'Kein Miniserver in LoxBerry konfiguriert.']);
        exit;
    }

    $ip   = $ms['Ipaddress'] ?? $ms['ipaddress'] ?? '';
    $port = $ms['Port']      ?? $ms['port']      ?? '80';
    $user = $ms['Admin']     ?? $ms['admin']     ?? 'admin';
    $pass = $ms['Pass']      ?? $ms['pass']      ?? '';
    $name = $ms['Name']      ?? $ms['name']      ?? '';

    if (!$ip) {
        echo json_encode(['error' => 'Miniserver-IP nicht in LoxBerry-Config gefunden.']);
        exit;
    }

    # LoxApp3.json: Enthält msInfo.location ("Lage"-Feld aus Loxone Config → Miniserver → Eigenschaften)
    # sowie ggf. direkte GPS-Koordinaten (latitude/longitude) in neueren Firmware-Versionen.
    # Range-Request: nur die ersten 16 KB lesen – msInfo steht immer am Dateianfang.
    $location_str = '';
    $api_source   = '';

    $lox_url = "http://{$ip}:{$port}/data/LoxApp3.json";
    $lox_ctx = stream_context_create(['http' => [
        'header'        => "Authorization: Basic " . base64_encode("{$user}:{$pass}") . "\r\n"
                         . "Range: bytes=0-16383\r\n",
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);
    # fopen + fread begrenzt den Transfer auf 16 KB, auch wenn der Server Range nicht unterstützt
    $lox_handle = @fopen($lox_url, 'r', false, $lox_ctx);
    $lox_raw = '';
    if ($lox_handle) {
        $lox_raw = fread($lox_handle, 16384);
        fclose($lox_handle);
    }

    if ($lox_raw) {
        # Direkte GPS-Koordinaten aus msInfo (neuere Firmware → keine Geocodierung nötig)
        if (preg_match('/"latitude"\s*:\s*([-\d.]+)/', $lox_raw, $mLat) &&
            preg_match('/"longitude"\s*:\s*([-\d.]+)/', $lox_raw, $mLon)) {
            $lat = (float)$mLat[1];
            $lon = (float)$mLon[1];
            if ($lat != 0.0 || $lon != 0.0) {
                echo json_encode([
                    'lat'          => $lat,
                    'lon'          => $lon,
                    'display_name' => 'Loxone Miniserver Standort',
                    'source'       => 'Loxone Miniserver (GPS-Koordinaten aus Konfiguration)',
                ]);
                exit;
            }
        }
        # "Lage"-Text aus msInfo.location
        if (preg_match('/"location"\s*:\s*"([^"]{2,})"/', $lox_raw, $mLoc)) {
            $location_str = $mLoc[1];
            $api_source   = 'Loxone Miniserver (Lage-Feld aus Konfiguration)';
        }
    }

    if (!$location_str && !$lox_raw) {
        echo json_encode([
            'error' => 'Miniserver nicht erreichbar unter ' . $ip . ':' . $port . '. '
                     . 'Bitte Miniserver-IP und Zugangsdaten in LoxBerry prüfen.',
        ]);
        exit;
    }

    if (!$location_str) {
        echo json_encode([
            'error' => 'Kein Standort in der Miniserver-Konfiguration gefunden. '
                     . 'Bitte in Loxone Config unter Miniserver → Eigenschaften → Lage einen Ortsnamen eintragen '
                     . '(z.B. "Wien" oder "Graz, Österreich") und die Konfiguration auf den Miniserver laden.',
        ]);
        exit;
    }

    # Standortstring via Nominatim geocodieren
    $geo_url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($location_str);
    $geo_ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: Unwetter4Lox-Plugin/0.4.2\r\n",
        'timeout' => 10,
    ]]);
    $geo_res = @file_get_contents($geo_url, false, $geo_ctx);
    $geo     = json_decode($geo_res, true);

    if (!empty($geo[0])) {
        echo json_encode([
            'lat'          => $geo[0]['lat'],
            'lon'          => $geo[0]['lon'],
            'display_name' => $geo[0]['display_name'],
            'source'       => $location_str . ' (' . $api_source . ')',
        ]);
    } else {
        echo json_encode([
            'error'      => "Standort \"" . htmlspecialchars($location_str) . "\" wurde bei Nominatim nicht gefunden. "
                          . "Bitte Adresse manuell in das Suchfeld eingeben.",
            'suggestion' => $location_str,
        ]);
    }
    exit;
}

# Stations-Cache löschen
if ($action === 'reload_stations') {
    header('Content-Type: application/json');
    $cache = $lbpdatadir . '/tawes_stations.json';
    if (file_exists($cache)) {
        unlink($cache);
        echo json_encode(['ok' => true, 'msg' => 'Stations-Cache gelöscht – wird beim nächsten Daemon-Lauf neu geladen.']);
    } else {
        echo json_encode(['ok' => true, 'msg' => 'Kein Cache vorhanden.']);
    }
    exit;
}

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
