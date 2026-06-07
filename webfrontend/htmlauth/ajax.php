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

    # Loxone REST-API: Standort aus /jdev/cfg/api holen
    # Die Antwort kann je nach Firmware unterschiedlich strukturiert sein
    $location_str = '';
    $api_source   = '';
    $api_url      = "http://{$ip}:{$port}/jdev/cfg/api";
    $ctx = stream_context_create(['http' => [
        'header'        => "Authorization: Basic " . base64_encode("{$user}:{$pass}") . "\r\n",
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($api_url, false, $ctx);

    if ($res) {
        $data = json_decode($res, true);
        # value kann je nach Firmware ein Array oder ein JSON-String sein
        $ll_val = $data['LL']['value'] ?? [];
        if (is_string($ll_val)) {
            $ll_val = json_decode($ll_val, true) ?? [];
        }
        # Standort aus diversen möglichen Feldern auslesen
        $location_str = $ll_val['location']  ?? $ll_val['Location']
                     ?? $ll_val['address']   ?? $ll_val['Address']
                     ?? $ll_val['city']      ?? $ll_val['City']
                     ?? '';
        if ($location_str) $api_source = 'Loxone Miniserver API';
    } else {
        # API nicht erreichbar – weiter mit Fallback
        $api_source = 'API nicht erreichbar';
    }

    # Fallback: Miniserver-Name aus LoxBerry-Config (oft leer oder generisch)
    if (!$location_str && $name) {
        $location_str = $name;
        $api_source   = 'LoxBerry Miniserver-Name (Fallback)';
    }

    if (!$location_str) {
        echo json_encode([
            'error' => 'Kein Standort gefunden. Die Loxone API hat keinen Standort geliefert ('
                     . ($res ? 'API erreichbar, aber kein location-Feld' : 'Miniserver nicht erreichbar unter ' . $ip . ':' . $port)
                     . '). Bitte Standort manuell eingeben oder in Loxone Config unter Miniserver → Eigenschaften → Lage eintragen.',
        ]);
        exit;
    }

    # Nur wirklich inhaltsleere generische Platzhalter-Namen blockieren
    # KEIN Längen-Check – kurze Ortsnamen wie "Wien", "Graz", "Linz" sind valide!
    $generic_names = ['miniserver', 'loxone miniserver', 'loxone', 'my miniserver', 'mein miniserver'];
    if (in_array(strtolower(trim($location_str)), $generic_names)) {
        echo json_encode([
            'error' => 'Der Miniserver heißt "' . htmlspecialchars($location_str) . '" – das ist kein Ortsname. '
                     . 'Bitte in Loxone Config unter Miniserver → Eigenschaften → Lage einen echten Standort eintragen.',
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
