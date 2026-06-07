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
        echo json_encode(['error' => 'Miniserver-IP nicht gefunden.']);
        exit;
    }

    # Loxone REST-API: Standort aus /jdev/cfg/api holen
    $location_str = '';
    $api_url  = "http://{$ip}:{$port}/jdev/cfg/api";
    $ctx = stream_context_create(['http' => [
        'header'        => "Authorization: Basic " . base64_encode("{$user}:{$pass}") . "\r\n",
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($api_url, false, $ctx);
    if ($res) {
        $data = json_decode($res, true);
        $location_str = $data['LL']['value']['location']
                     ?? $data['LL']['value']['Location']
                     ?? '';
    }

    # Fallback: Miniserver-Name aus LoxBerry-Config
    if (!$location_str) $location_str = $name;

    # Generische/nutzlose Namen abfangen – diese können nicht geocodiert werden
    $generic_names = ['miniserver', 'loxone miniserver', 'my miniserver', 'mein miniserver', 'loxone', 'home', 'zuhause', 'haus'];
    if (!$location_str || in_array(strtolower(trim($location_str)), $generic_names) || strlen(trim($location_str)) < 5) {
        echo json_encode([
            'error'      => 'Kein Standort im Loxone Miniserver hinterlegt. Bitte Adresse manuell eingeben oder in Loxone Config unter Miniserver → Einstellungen → Standort eintragen.',
            'suggestion' => ''
        ]);
        exit;
    }

    # Standortstring via Nominatim geocodieren
    $geo_url  = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($location_str);
    $geo_ctx  = stream_context_create(['http' => [
        'header'  => "User-Agent: Unwetter4Lox-Plugin/0.4.0\r\n",
        'timeout' => 10,
    ]]);
    $geo_res  = @file_get_contents($geo_url, false, $geo_ctx);
    $geo      = json_decode($geo_res, true);

    if (!empty($geo[0])) {
        echo json_encode([
            'lat'          => $geo[0]['lat'],
            'lon'          => $geo[0]['lon'],
            'display_name' => $geo[0]['display_name'],
            'source'       => $location_str,
        ]);
    } else {
        echo json_encode([
            'error'      => "Standort \"{$location_str}\" konnte nicht geocodiert werden. Bitte manuell suchen.",
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
