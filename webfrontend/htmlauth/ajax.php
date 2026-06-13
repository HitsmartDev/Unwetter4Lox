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

# Koordinaten vom Loxone Miniserver via LoxBerry-SDK abrufen
if ($action === 'get_miniserver_coords') {
    header('Content-Type: application/json');

    # LoxBerry SDK-Funktion: liefert Miniserver-Array mit korrektem Transport, Port, FullURI
    # FullURI-Format: transport://user:pass@ip:port  (HTTPS nutzt PortHttps, nicht Port)
    $miniservers = [];
    if (function_exists('LBSystem::get_miniservers') || method_exists('LBSystem', 'get_miniservers')) {
        try { $miniservers = LBSystem::get_miniservers(); } catch (Exception $e) { $miniservers = []; }
    }

    # Fallback: general.json direkt lesen wenn SDK-Funktion nicht verfügbar
    if (empty($miniservers)) {
        $gen_file = $lbhomedir . '/config/system/general.json';
        $gen      = file_exists($gen_file) ? json_decode(file_get_contents($gen_file), true) : null;
        foreach (['Miniserver', 'miniserver'] as $key) {
            if (!empty($gen[$key]) && is_array($gen[$key])) {
                $raw = reset($gen[$key]);
                # SDK-kompatibles Format nachbauen: Transport + FullURI korrekt ableiten
                $port_https = $raw['Porthttps'] ?? $raw['porthttps'] ?? '443';
                $port_http  = $raw['Port']      ?? $raw['port']      ?? '80';
                $use_https  = !empty($raw['Preferhttps']) || !empty($raw['preferhttps'])
                           || !empty($raw['UseSSL'])      || !empty($raw['usessl'])
                           || (string)$port_http === '443';
                $transport  = $use_https ? 'https' : 'http';
                $port_used  = $use_https ? $port_https : $port_http;
                $ip         = $raw['Ipaddress'] ?? $raw['ipaddress'] ?? '';
                $user       = $raw['Admin']     ?? $raw['admin']     ?? 'admin';
                $pass       = $raw['Pass']      ?? $raw['pass']      ?? '';
                $raw['Transport'] = $transport;
                $raw['Port']      = $port_http;
                $raw['PortHttps'] = $port_https;
                $raw['IPAddress'] = $ip;
                $raw['FullURI']   = "{$transport}://" . urlencode($user) . ':' . urlencode($pass) . "@{$ip}:{$port_used}";
                $miniservers[1]   = $raw;
                break;
            }
        }
    }

    if (empty($miniservers)) {
        echo json_encode(['error' => 'Kein Miniserver in LoxBerry konfiguriert.']);
        exit;
    }

    $ms = reset($miniservers);

    # 1. Koordinaten direkt in LoxBerry-Konfiguration gespeichert? (kein Miniserver-Request nötig)
    $lat_cfg = $ms['Latitude'] ?? $ms['latitude'] ?? null;
    $lon_cfg = $ms['Longitude'] ?? $ms['longitude'] ?? null;
    if ($lat_cfg !== null && $lon_cfg !== null && ((float)$lat_cfg != 0.0 || (float)$lon_cfg != 0.0)) {
        echo json_encode([
            'lat'          => (float)$lat_cfg,
            'lon'          => (float)$lon_cfg,
            'display_name' => $ms['Name'] ?? $ms['name'] ?? 'Loxone Miniserver',
            'source'       => 'LoxBerry-Konfiguration (gespeicherte Koordinaten)',
        ]);
        exit;
    }

    # 2. LoxApp3.json vom Miniserver abrufen
    # FullURI enthält bereits korrektes Schema + Port (https→PortHttps, http→Port)
    $full_uri    = rtrim($ms['FullURI'] ?? '', '/');
    $location_str = '';
    $api_source   = '';
    $lox_raw      = '';

    if ($full_uri) {
        $lox_url = $full_uri . '/data/LoxApp3.json';
        $lox_ctx = stream_context_create([
            'http' => [
                'header'        => "Range: bytes=0-16383\r\n",  # msInfo steht am Dateianfang
                'timeout'       => 6,
                'ignore_errors' => true,
            ],
            'ssl'  => [
                'verify_peer'      => false,  # Miniserver nutzt selbstsigniertes Zertifikat
                'verify_peer_name' => false,
            ],
        ]);
        $lox_raw = @file_get_contents($lox_url, false, $lox_ctx);

        # Fallback: wenn FullURI-Schema fehlschlägt, anderes Schema probieren
        if (!$lox_raw) {
            $alt_transport = (strpos($full_uri, 'https://') === 0) ? 'http' : 'https';
            # Port beim Wechsel anpassen
            $port_alt = ($alt_transport === 'https')
                      ? ($ms['PortHttps'] ?? $ms['Porthttps'] ?? '443')
                      : ($ms['Port'] ?? '80');
            $ip_raw   = $ms['IPAddress'] ?? $ms['Ipaddress'] ?? $ms['ipaddress'] ?? '';
            $user_raw = $ms['Admin']     ?? $ms['admin']     ?? 'admin';
            $pass_raw = $ms['Pass']      ?? $ms['pass']      ?? '';
            if ($ip_raw) {
                $alt_url  = "{$alt_transport}://" . urlencode($user_raw) . ':' . urlencode($pass_raw)
                           . "@{$ip_raw}:{$port_alt}/data/LoxApp3.json";
                $lox_raw  = @file_get_contents($alt_url, false, $lox_ctx);
            }
        }
    }

    if ($lox_raw) {
        # Direkte GPS-Koordinaten aus msInfo (neuere Firmware)
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
        # "Lage"-Text aus msInfo.location → Geocodierung via Nominatim
        if (preg_match('/"location"\s*:\s*"([^"]{2,})"/', $lox_raw, $mLoc)) {
            $location_str = $mLoc[1];
            $api_source   = 'Loxone Miniserver (Lage-Feld aus Konfiguration)';
        }
    }

    if (!$location_str && !$lox_raw) {
        # Hilfreiche Fehlermeldung: zeige welche URL tatsächlich versucht wurde
        $tried_url = $full_uri ? (preg_replace('/:[^:@]*@/', ':***@', $full_uri) . '/data/LoxApp3.json') : 'keine URL ermittelbar';
        echo json_encode([
            'error' => 'Miniserver nicht erreichbar. Versucht: ' . $tried_url . '. '
                     . 'Bitte Miniserver-IP, Port und Zugangsdaten in LoxBerry unter System → Miniserver prüfen. '
                     . 'Tipp: Ist HTTPS aktiviert? Dann muss auch der HTTPS-Port (Standard: 443) eingetragen sein.',
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
        'header'  => "User-Agent: Unwetter4Lox-Plugin/0.4.28\r\n",
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

# Letzten Update-Timestamp + Daemon-Status zurückgeben (für Auto-Refresh in index.php)
if ($action === 'check_update') {
    header('Content-Type: application/json');
    $sf    = $lbpdatadir . '/state.json';
    $state = file_exists($sf) ? (json_decode(file_get_contents($sf), true) ?? []) : [];

    # Daemon-PID prüfen (gleiche Logik wie index.php)
    $pidfile = $lbplogdir . '/daemon.pid';
    $pid     = file_exists($pidfile) ? trim(file_get_contents($pidfile)) : '';
    $running = false;
    if ($pid && is_numeric($pid)) {
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
        $running = ($cmdline !== false && (
            strpos($cmdline, 'unwetter4lox_daemon') !== false ||
            strpos($cmdline, 'unwetter4lox') !== false
        ));
    }

    echo json_encode([
        'epoch'   => (int)($state['letzter_abruf_epoch'] ?? 0),
        'status'  => $state['status'] ?? 'OK',
        'running' => $running,
    ]);
    exit;
}

# Stations-Cache löschen + Daemon neustarten (Stationen werden beim nächsten Daemon-Start frisch geladen)
if ($action === 'reload_stations') {
    header('Content-Type: application/json');
    $cache = $lbpdatadir . '/tawes_stations.json';
    if (file_exists($cache)) {
        unlink($cache);
    }
    # Daemon neustarten damit der startup-fresh-load direkt Stationen lädt
    $daemonscript = $lbhomedir . "/system/daemons/plugins/" . $lbpplugindir;
    if (file_exists($daemonscript)) {
        shell_exec("sudo " . escapeshellarg($daemonscript) . " restart 2>&1");
        echo json_encode(['ok' => true, 'restart' => true, 'msg' => 'Stations-Cache gelöscht, Daemon wird neu gestartet…']);
    } else {
        echo json_encode(['ok' => true, 'restart' => false, 'msg' => 'Stations-Cache gelöscht – wird beim nächsten Daemon-Start neu geladen.']);
    }
    exit;
}

# Daemon-Steuerung als JSON (für AJAX-Aufrufe ohne Seiten-Redirect)
if ($action === 'restart_json' || $action === 'start_json' || $action === 'stop_json') {
    header('Content-Type: application/json');
    $cmd_action = str_replace('_json', '', $action);
    $daemonscript = $lbhomedir . "/system/daemons/plugins/" . $lbpplugindir;
    if (!file_exists($daemonscript)) {
        echo json_encode(['ok' => false, 'msg' => 'Daemon-Script nicht gefunden.']);
        exit;
    }
    $cmd = "sudo " . escapeshellarg($daemonscript) . " " . escapeshellarg($cmd_action) . " 2>&1";
    shell_exec($cmd);
    echo json_encode(['ok' => true, 'action' => $cmd_action]);
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
