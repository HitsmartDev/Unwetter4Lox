<?php
require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once 'common.php';

$L = LBSystem::readlanguage('language.ini');

// ── Config laden ──
$cfgfile = $lbpconfigdir . '/unwetter4lox.cfg';
$cfg     = parse_ini_file($cfgfile, true) ?: [];
$use_lb  = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
$saved   = false;
$err     = '';

// ── POST speichern ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $use_lb_new = isset($_POST['use_lb_mqtt']) ? '1' : '0';
    $zamg_en    = isset($_POST['zamg_enabled'])  ? '1' : '0';
    $inca_en    = isset($_POST['inca_enabled'])   ? '1' : '0';
    $tawes_en   = isset($_POST['tawes_enabled'])  ? '1' : '0';

    // Koordinaten normalisieren (Komma → Punkt, österreichische Eingabe)
    $lat = (float)str_replace(',', '.', trim($_POST['lat'] ?? '0'));
    $lon = (float)str_replace(',', '.', trim($_POST['lon'] ?? '0'));

    $c  = "[LOCATION]\n";
    $c .= 'LAT='          . number_format($lat, 6, '.', '')                                   . "\n";
    $c .= 'LON='          . number_format($lon, 6, '.', '')                                   . "\n";
    $c .= 'NAME='         . strip_tags(trim($_POST['name']         ?? 'Mein Zuhause'))         . "\n\n";

    $c .= "[MQTT]\n";
    $c .= "USE_LOXBERRY_MQTT={$use_lb_new}\n";
    $c .= 'BROKER='       . strip_tags(trim($_POST['broker']       ?? '127.0.0.1'))            . "\n";
    $c .= 'PORT='         . intval($_POST['port']                  ?? 1883)                   . "\n";
    $c .= 'USER='         . strip_tags(trim($_POST['mqtt_user']    ?? ''))                     . "\n";
    $c .= 'PASS='         . strip_tags(trim($_POST['mqtt_pass']    ?? ''))                     . "\n";
    $c .= 'TOPIC_PREFIX=' . strip_tags(trim($_POST['topic_prefix'] ?? 'unwetter'))             . "\n\n";

    // ZAMG Warntypen: Checkboxen → kommagetrennte IDs
    $zamg_alle_ids = [1=>'wind',2=>'regen',3=>'schnee',4=>'glatteis',5=>'gewitter',6=>'hitze',7=>'kaelte',8=>'hagel'];
    $zamg_typen_ids = [];
    foreach ($zamg_alle_ids as $id => $name) {
        if (isset($_POST["zamg_typ_{$id}"])) $zamg_typen_ids[] = $id;
    }
    if (empty($zamg_typen_ids)) $zamg_typen_ids = [1,2,3,4,5,8]; // Mindest-Default

    $c .= "[ZAMG]\n";
    $c .= "ENABLED={$zamg_en}\n";
    $c .= 'AKTIVE_TYPEN=' . implode(',', $zamg_typen_ids)                                     . "\n\n";

    $c .= "[INCA]\n";
    $c .= "ENABLED={$inca_en}\n";
    $c .= 'HORIZON_MINUTES=' . max(15, min(60, intval($_POST['inca_horizon'] ?? 60)))          . "\n\n";

    $c .= "[SCHEDULE]\n";
    $c .= 'INTERVAL='       . max(60, min(3600, intval($_POST['interval']       ?? 300)))      . "\n";
    $c .= 'ZAMG_INTERVAL='  . max(60, min(3600, intval($_POST['zamg_interval']  ?? 300)))      . "\n";
    $c .= 'INCA_INTERVAL='  . max(60, min(3600, intval($_POST['inca_interval']  ?? 300)))      . "\n";
    $c .= 'TAWES_INTERVAL=' . max(120,min(3600, intval($_POST['tawes_interval'] ?? 480)))      . "\n\n";

    $c .= "[THRESHOLDS]\n";
    $c .= 'BOEN_ALARM='  . number_format((float)str_replace(',','.',$_POST['boen_alarm']  ?? '60'), 1, '.', '') . "\n";
    $c .= 'REGEN_ALARM=' . number_format((float)str_replace(',','.',$_POST['regen_alarm'] ?? '10.0'),1,'.','') . "\n\n";

    $c .= "[NOTIFICATIONS]\n";
    $c .= 'MIN_STUFE=' . max(1, min(4, intval($_POST['min_stufe'] ?? 1)))                     . "\n\n";

    $c .= "[TAWES]\n";
    $c .= "ENABLED={$tawes_en}\n";
    $c .= 'MAX_DISTANCE_KM='      . max(20,  min(150, intval($_POST['tawes_max_km']             ?? 120))) . "\n";
    $c .= 'MAX_STATIONS='         . max(5,   min(50,  intval($_POST['tawes_max_stations']        ?? 25)))  . "\n";
    $c .= 'MIN_ALARM_PROZENT='    . max(10,  min(100, intval($_POST['tawes_min_alarm_prozent']   ?? 30))) . "\n";
    $c .= 'MAX_UPSTREAM_HOEHE_M=' . max(0,   min(3000,intval($_POST['tawes_max_upstream_hoehe'] ?? 1200))). "\n";
    $c .= 'REGEN_LOKAL_KM='       . max(5,   min(100, intval($_POST['tawes_regen_lokal_km']     ?? 25)))  . "\n";
    $c .= 'UPSTREAM_WINKEL_GRAD=' . max(20,  min(90,  intval($_POST['tawes_upstream_winkel']    ?? 45)))  . "\n";

    if (file_put_contents($cfgfile, $c) !== false) {
        $saved = true;
        $cfg   = parse_ini_file($cfgfile, true) ?: [];
        $use_lb = ($cfg['MQTT']['USE_LOXBERRY_MQTT'] ?? '1') == '1';
    } else {
        $err = $L['MAIN.SAVE_ERR'] ?? 'Speichern fehlgeschlagen';
    }
}

// Hilfsfunktion: Wert aus Config mit Fallback
function cv(string $s, string $k, string $d = ''): string {
    global $cfg;
    return h((string)($cfg[$s][$k] ?? $d));
}

// ZAMG Typen-Config
$zamg_typen_cfg  = array_map('trim', explode(',', $cfg['ZAMG']['AKTIVE_TYPEN'] ?? '1,2,3,4,5,8'));
$zamg_typen_info = [
    1 => ['💨 Wind',     'Sturmböen-Warnung'],
    2 => ['🌧️ Regen',   'Starkregen / Überflutung'],
    3 => ['❄️ Schnee',   'Starker Schneefall'],
    4 => ['🧊 Glatteis', 'Glatteis / Eisregen'],
    5 => ['⚡ Gewitter', 'Gewitter (mit/ohne Hagel)'],
    6 => ['🌡️ Hitze',   'Hitzewelle – standardmäßig deaktiviert'],
    7 => ['🥶 Kälte',    'Kälteeinbruch / Frost – standardmäßig deaktiviert'],
    8 => ['🌨 Hagel',    'Hagelschlag'],
];

// TAWES Cache-Infos
$tawes_cache = $lbpdatadir . '/tawes_stations.json';
$st_count = 0;
if (file_exists($tawes_cache)) {
    $sts = json_decode(file_get_contents($tawes_cache), true);
    $st_count = is_array($sts) ? count($sts) : 0;
}

render_header('app_settings');
?>

<?php if ($saved): flash_ok($L['MAIN.SAVED'] ?? '✅ Einstellungen gespeichert'); endif; ?>
<?php if ($err):   flash_err($err); endif; ?>

<form method="POST" class="sl-form">

<!-- ================================================================
     STANDORT & GEOCODING
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">📍 <?= h($L['MAIN.LOCATION'] ?? 'Standort') ?> &amp; Geocoding</span></div>
    <div class="sl-card-body">
        <div class="sl-section-title">Adresse suchen</div>
        <div class="sl-input-group" style="margin-bottom:0.5rem">
            <input type="text" id="addr_search" placeholder="<?= h($L['MAIN.ADDR_PLACEHOLDER'] ?? 'z.B. Linz, Österreich') ?>" style="flex:1;min-width:150px;padding:0.42rem 0.7rem;border:1px solid var(--border);border-radius:5px;font-size:0.85rem">
            <button type="button" id="btn_geocode" class="sl-btn secondary sm">🔍 <?= h($L['MAIN.GEOCODE_BTN'] ?? 'Suchen') ?></button>
            <button type="button" id="btn_miniserver" class="sl-btn secondary sm">🏠 <?= h($L['MAIN.FROM_MINISERVER'] ?? 'Vom Miniserver') ?></button>
        </div>
        <div id="ms_status" style="display:none;font-size:0.78rem;margin:4px 0;padding:4px 8px;background:#f0f7ff;border-radius:4px"></div>
        <p class="sl-hint">Koordinaten via OpenStreetMap suchen oder direkt vom verbundenen Loxone Miniserver übernehmen.</p>

        <div class="sl-section-title">Koordinaten</div>
        <div class="sl-field">
            <label for="name">Bezeichnung</label>
            <input type="text" id="name" name="name" value="<?= cv('LOCATION','NAME','Mein Zuhause') ?>" placeholder="z.B. Zuhause Linz">
        </div>
        <div class="sl-field">
            <label for="lat">Breitengrad (Latitude)</label>
            <input type="number" id="lat" name="lat" step="0.000001" value="<?= cv('LOCATION','LAT','') ?>" placeholder="z.B. 48.3069">
        </div>
        <div class="sl-field">
            <label for="lon">Längengrad (Longitude)</label>
            <input type="number" id="lon" name="lon" step="0.000001" value="<?= cv('LOCATION','LON','') ?>" placeholder="z.B. 14.2858">
        </div>
    </div>
</div>

<!-- ================================================================
     API SERVICES
     ================================================================ -->
<div class="sl-card">
    <div class="sl-card-head"><span class="sl-card-head-title">🔌 API Services</span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" name="zamg_enabled" <?= ($cfg['ZAMG']['ENABLED'] ?? '1') == '1' ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label">GeoSphere Austria (ZAMG) aktivieren</span>
            </div>
        </div>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" name="inca_enabled" <?= ($cfg['INCA']['ENABLED'] ?? '1') == '1' ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label"><?= h($L['MAIN.INCA_ENABLED'] ?? 'INCA Nowcast aktivieren') ?></span>
            </div>
        </div>
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" name="tawes_enabled" <?= ($cfg['TAWES']['ENABLED'] ?? '1') == '1' ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label"><?= h($L['MAIN.TAWES_ENABLED'] ?? 'TAWES 360° aktivieren') ?></span>
            </div>
        </div>

        <div class="sl-section-title" style="margin-top:1rem">🌩️ GeoSphere Warntypen</div>
        <p class="sl-hint">Welche offiziellen Warnungen sollen Alarm auslösen? Hitze/Kälte sind standardmäßig deaktiviert.</p>
        <div class="sl-chip-grid">
<?php foreach ($zamg_typen_info as $id => [$label, $title]):
    $checked = in_array((string)$id, $zamg_typen_cfg);
?>
            <label class="sl-chip" title="<?= h($title) ?>">
                <input type="checkbox" name="zamg_typ_<?= $id ?>" value="1" <?= $checked ? 'checked' : '' ?>>
                <span><?= h($label) ?></span>
            </label>
<?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ================================================================
     TAWES 360°
     ================================================================ -->
<div class="sl-card collapsed">
    <div class="sl-card-head"><span class="sl-card-head-title">🌐 TAWES 360°</span></div>
    <div class="sl-card-body">
        <div class="sl-slider-row">
            <label><?= h($L['MAIN.TAWES_MAX_KM'] ?? 'Max. Radius') ?> <span class="sl-slider-val" id="tkm"><?= cv('TAWES','MAX_DISTANCE_KM','120') ?></span> km</label>
            <input type="range" name="tawes_max_km" min="20" max="150" step="10"
                   value="<?= cv('TAWES','MAX_DISTANCE_KM','120') ?>"
                   oninput="document.getElementById('tkm').textContent=this.value">
        </div>
        <div class="sl-slider-row">
            <label>Max. Stationen <span class="sl-slider-val" id="tms"><?= cv('TAWES','MAX_STATIONS','25') ?></span></label>
            <input type="range" name="tawes_max_stations" min="5" max="50" step="5"
                   value="<?= cv('TAWES','MAX_STATIONS','25') ?>"
                   oninput="document.getElementById('tms').textContent=this.value">
            <p class="sl-hint">Anzahl nächstgelegener Stationen pro API-Abruf.</p>
        </div>
        <div class="sl-slider-row">
            <label>Upstream-Kegel (Halbwinkel) <span class="sl-slider-val" id="tuw"><?= cv('TAWES','UPSTREAM_WINKEL_GRAD','45') ?></span>° → Gesamtkegel: <span class="sl-slider-val" id="tuw2"><?= intval(cv('TAWES','UPSTREAM_WINKEL_GRAD','45')) * 2 ?></span>°</label>
            <input type="range" name="tawes_upstream_winkel" min="20" max="90" step="5"
                   value="<?= cv('TAWES','UPSTREAM_WINKEL_GRAD','45') ?>"
                   oninput="document.getElementById('tuw').textContent=this.value; document.getElementById('tuw2').textContent=this.value*2">
            <p class="sl-hint"><b>45° = 90° Gesamtkegel</b> (Standard). Kleiner = präziser, weniger Stationen. Empfehlung: 30°–50°.</p>
        </div>
        <div class="sl-slider-row">
            <label>Konsens-Schwelle Alarm <span class="sl-slider-val" id="tmap"><?= cv('TAWES','MIN_ALARM_PROZENT','30') ?></span>%</label>
            <input type="range" name="tawes_min_alarm_prozent" min="10" max="100" step="10"
                   value="<?= cv('TAWES','MIN_ALARM_PROZENT','30') ?>"
                   oninput="document.getElementById('tmap').textContent=this.value">
            <p class="sl-hint">Mindestanteil der Upstream-Stationen die den Schwellwert überschreiten müssen.</p>
        </div>
        <div class="sl-slider-row">
            <label>Max. Seehöhe Upstream (Wind-Alarm) <span class="sl-slider-val" id="tmueh"><?= cv('TAWES','MAX_UPSTREAM_HOEHE_M','1200') ?></span> m</label>
            <input type="range" name="tawes_max_upstream_hoehe" min="0" max="3000" step="100"
                   value="<?= cv('TAWES','MAX_UPSTREAM_HOEHE_M','1200') ?>"
                   oninput="document.getElementById('tmueh').textContent=this.value">
            <p class="sl-hint">Stationen über dieser Seehöhe aus Wind-Konsens ausgeschlossen (z.B. Feuerkogel 1618 m). 0 = alle.</p>
        </div>
        <div class="sl-slider-row">
            <label>Lokal-Regen Umkreis <span class="sl-slider-val" id="trlkm"><?= cv('TAWES','REGEN_LOKAL_KM','25') ?></span> km</label>
            <input type="range" name="tawes_regen_lokal_km" min="5" max="50" step="5"
                   value="<?= cv('TAWES','REGEN_LOKAL_KM','25') ?>"
                   oninput="document.getElementById('trlkm').textContent=this.value">
            <p class="sl-hint">Umkreis für lokalen Regen-Alarm (unabhängig von Windrichtung).</p>
        </div>
        <hr>
        <p class="sl-hint">
            Stations-Cache: <b><?= $st_count ?></b> Stationen geladen.
            <?= $st_count ? '(tawes_stations.json vorhanden)' : '(wird beim ersten Daemon-Start geladen)' ?>
        </p>
        <button type="button" id="btn_reload_st" class="sl-btn secondary sm">🔄 <?= h($L['MAIN.TAWES_RELOAD_STATIONS'] ?? 'Cache neu laden') ?></button>
        <span id="reload_msg" style="font-size:0.78rem;margin-left:0.5rem;display:none"></span>
    </div>
</div>

<!-- ================================================================
     MQTT BROKER
     ================================================================ -->
<div class="sl-card collapsed">
    <div class="sl-card-head"><span class="sl-card-head-title">📡 <?= h($L['MAIN.MQTT_BROKER'] ?? 'MQTT Broker') ?></span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <div class="sl-toggle-wrap">
                <label class="sl-toggle">
                    <input type="checkbox" id="use_lb_mqtt" name="use_lb_mqtt" <?= $use_lb ? 'checked' : '' ?>>
                    <span class="sl-toggle-slider"></span>
                </label>
                <span class="sl-toggle-label"><?= h($L['MAIN.MQTT_AUTO'] ?? 'LoxBerry MQTT Auto-Erkennung') ?></span>
            </div>
        </div>
        <div id="mqtt_manual" <?= $use_lb ? 'style="display:none"' : '' ?>>
            <div class="sl-field">
                <label for="broker">Broker IP / Hostname</label>
                <input type="text" id="broker" name="broker" value="<?= cv('MQTT','BROKER','127.0.0.1') ?>">
            </div>
            <div class="sl-field">
                <label for="port">Port</label>
                <input type="number" id="port" name="port" value="<?= cv('MQTT','PORT','1883') ?>">
            </div>
            <div class="sl-field">
                <label for="mqtt_user">Benutzername (optional)</label>
                <input type="text" id="mqtt_user" name="mqtt_user" value="<?= cv('MQTT','USER','') ?>">
            </div>
            <div class="sl-field">
                <label for="mqtt_pass">Passwort (optional)</label>
                <input type="password" id="mqtt_pass" name="mqtt_pass" value="<?= cv('MQTT','PASS','') ?>">
            </div>
        </div>
        <div class="sl-field">
            <label for="topic_prefix">MQTT Topic Prefix</label>
            <input type="text" id="topic_prefix" name="topic_prefix" value="<?= cv('MQTT','TOPIC_PREFIX','unwetter') ?>">
            <p class="sl-hint">Alle Topics beginnen mit diesem Präfix: <code><?= cv('MQTT','TOPIC_PREFIX','unwetter') ?>/alarm/gesamt</code> etc.</p>
        </div>
    </div>
</div>

<!-- ================================================================
     INCA NOWCAST
     ================================================================ -->
<div class="sl-card collapsed">
    <div class="sl-card-head"><span class="sl-card-head-title">🛰️ INCA Nowcast</span></div>
    <div class="sl-card-body">
        <p class="sl-hint">INCA-Signal allein → max. Stufe 1 (unbestätigt). INCA + TAWES bestätigt → voller Alarm Stufe 1–3.</p>
        <div class="sl-slider-row">
            <label><?= h($L['MAIN.HORIZON'] ?? 'Nowcast-Horizont') ?> <span class="sl-slider-val" id="sih"><?= cv('INCA','HORIZON_MINUTES','60') ?></span> min</label>
            <input type="range" name="inca_horizon" min="15" max="60" step="15"
                   value="<?= cv('INCA','HORIZON_MINUTES','60') ?>"
                   oninput="document.getElementById('sih').textContent=this.value">
            <p class="sl-hint"><b>60 min</b> = Maximum (empfohlen für 15–60 min Vorwarnzeit).</p>
        </div>
        <p class="sl-hint">
            <b>Böen-Schwellwert:</b> aus Alarmschwellen → aktuell <b><?= cv('THRESHOLDS','BOEN_ALARM','60') ?> km/h</b><br>
            <b>Regen-Schwellwert:</b> aus Alarmschwellen → aktuell <b><?= cv('THRESHOLDS','REGEN_ALARM','10.0') ?> mm/h</b>
        </p>
    </div>
</div>

<!-- ================================================================
     ABRUF-INTERVALLE & ALARMSCHWELLEN
     ================================================================ -->
<div class="sl-card collapsed">
    <div class="sl-card-head"><span class="sl-card-head-title">⚙️ Abruf-Intervalle &amp; Alarmschwellen</span></div>
    <div class="sl-card-body">
        <p class="sl-hint">Loop-Takt = wie oft der Daemon intern prüft. API-Intervalle = wie oft jede Quelle wirklich abgerufen wird.</p>
        <div class="sl-slider-row">
            <label>Loop-Takt <span class="sl-slider-val" id="siv"><?= cv('SCHEDULE','INTERVAL','300') ?></span> s</label>
            <input type="range" name="interval" min="60" max="600" step="30"
                   value="<?= cv('SCHEDULE','INTERVAL','300') ?>"
                   oninput="document.getElementById('siv').textContent=this.value">
        </div>
        <hr>
        <div class="sl-slider-row">
            <label>🌩️ ZAMG-Intervall <span class="sl-slider-val" id="sziv"><?= cv('SCHEDULE','ZAMG_INTERVAL','300') ?></span> s</label>
            <input type="range" name="zamg_interval" min="60" max="3600" step="60"
                   value="<?= cv('SCHEDULE','ZAMG_INTERVAL','300') ?>"
                   oninput="document.getElementById('sziv').textContent=this.value">
        </div>
        <div class="sl-slider-row">
            <label>🛰️ INCA-Intervall <span class="sl-slider-val" id="siiv"><?= cv('SCHEDULE','INCA_INTERVAL','300') ?></span> s</label>
            <input type="range" name="inca_interval" min="60" max="3600" step="60"
                   value="<?= cv('SCHEDULE','INCA_INTERVAL','300') ?>"
                   oninput="document.getElementById('siiv').textContent=this.value">
        </div>
        <div class="sl-slider-row">
            <label>🌐 TAWES-Intervall <span class="sl-slider-val" id="stiv"><?= cv('SCHEDULE','TAWES_INTERVAL','480') ?></span> s</label>
            <input type="range" name="tawes_interval" min="120" max="3600" step="60"
                   value="<?= cv('SCHEDULE','TAWES_INTERVAL','480') ?>"
                   oninput="document.getElementById('stiv').textContent=this.value">
        </div>
        <hr>
        <div class="sl-section-title">Alarmschwellen</div>
        <p class="sl-hint">Stufe 1 = 1× Schwellwert · Stufe 2 = 2× · Stufe 3 = 3×</p>
        <div class="sl-slider-row">
            <label><?= h($L['MAIN.BOEN_ALARM'] ?? 'Böen-Alarm') ?> <span class="sl-slider-val" id="sba"><?= cv('THRESHOLDS','BOEN_ALARM','60') ?></span> km/h</label>
            <input type="range" name="boen_alarm" min="20" max="120" step="5"
                   value="<?= cv('THRESHOLDS','BOEN_ALARM','60') ?>"
                   oninput="document.getElementById('sba').textContent=this.value">
            <p class="sl-hint">60 km/h = Beaufort 8 (Sturm). Empfehlung: 40 sensibel · 60 Standard · 80 schwere Stürme.</p>
        </div>
        <div class="sl-slider-row">
            <label><?= h($L['MAIN.REGEN_ALARM'] ?? 'Regen-Alarm') ?> <span class="sl-slider-val" id="sra"><?= cv('THRESHOLDS','REGEN_ALARM','10.0') ?></span> mm/h</label>
            <input type="range" name="regen_alarm" min="0.5" max="60" step="0.5"
                   value="<?= cv('THRESHOLDS','REGEN_ALARM','10.0') ?>"
                   oninput="document.getElementById('sra').textContent=this.value">
            <p class="sl-hint">2 = leicht · 10 = stark · 20+ = Starkregen. Immer in mm/h.</p>
        </div>
    </div>
</div>

<!-- ================================================================
     NOTIFICATIONS
     ================================================================ -->
<div class="sl-card collapsed">
    <div class="sl-card-head"><span class="sl-card-head-title">🔔 Notifications</span></div>
    <div class="sl-card-body">
        <div class="sl-field">
            <label for="min_stufe"><?= h($L['MAIN.MIN_STUFE'] ?? 'Mindest-Alarmstufe für Notification') ?></label>
            <select id="min_stufe" name="min_stufe">
                <option value="1" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '1' ? 'selected' : '' ?>><?= h($L['MAIN.STUFE_1'] ?? 'Stufe 1 – Vorsicht (alle)') ?></option>
                <option value="2" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '2' ? 'selected' : '' ?>><?= h($L['MAIN.STUFE_2'] ?? 'Stufe 2 – Warnung') ?></option>
                <option value="3" <?= ($cfg['NOTIFICATIONS']['MIN_STUFE'] ?? '1') == '3' ? 'selected' : '' ?>><?= h($L['MAIN.STUFE_3'] ?? 'Stufe 3 – Extrem') ?></option>
            </select>
        </div>
    </div>
</div>

<div style="padding:0.5rem 0 1.5rem">
    <button type="submit" class="sl-btn primary">💾 <?= h($L['MAIN.SAVE'] ?? 'Speichern') ?></button>
</div>

</form>

<script>
// MQTT Toggle: manuelle Felder ein-/ausblenden
(function() {
    var toggle = document.getElementById('use_lb_mqtt');
    var manual = document.getElementById('mqtt_manual');
    if (!toggle || !manual) return;
    function update() { manual.style.display = toggle.checked ? 'none' : ''; }
    update();
    toggle.addEventListener('change', update);
})();

// Geocoding per Nominatim (OpenStreetMap)
document.getElementById('btn_geocode').addEventListener('click', function() {
    var q = document.getElementById('addr_search').value;
    if (!q) return;
    this.disabled = true;
    var btn = this;
    fetch('ajax.php?action=geocode&q=' + encodeURIComponent(q), { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.error) {
                SL.toast('Fehler: ' + d.error, 'err');
            } else {
                document.getElementById('lat').value = parseFloat(d.lat).toFixed(6);
                document.getElementById('lon').value = parseFloat(d.lon).toFixed(6);
                SL.toast('<?= addslashes($L['MAIN.GEOCODE_SUCCESS'] ?? 'Koordinaten übernommen') ?>', 'ok');
            }
        })
        .catch(function() { btn.disabled = false; SL.toast('Verbindungsfehler', 'err'); });
});

// Koordinaten vom Loxone Miniserver
document.getElementById('btn_miniserver').addEventListener('click', function() {
    var btn = this;
    var ms = document.getElementById('ms_status');
    btn.disabled = true;
    ms.textContent = '<?= addslashes($L['MAIN.MINISERVER_LOADING'] ?? 'Lade…') ?>';
    ms.style.display = 'block';
    fetch('ajax.php?action=get_miniserver_coords', { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.error) {
                ms.textContent = '❌ ' + d.error;
                if (d.suggestion && d.suggestion.length > 5) {
                    document.getElementById('addr_search').value = d.suggestion;
                }
            } else {
                document.getElementById('lat').value = parseFloat(d.lat).toFixed(6);
                document.getElementById('lon').value = parseFloat(d.lon).toFixed(6);
                ms.textContent = '✅ ' + (d.display_name || '').split(',')[0] + ' (Quelle: ' + d.source + ')';
            }
        })
        .catch(function() { btn.disabled = false; ms.textContent = '❌ Verbindungsfehler'; });
});

// TAWES Cache neu laden
document.getElementById('btn_reload_st').addEventListener('click', function() {
    var btn = this;
    var msg = document.getElementById('reload_msg');
    btn.disabled = true;
    msg.textContent = '⟳ Cache gelöscht, Daemon startet neu…';
    msg.style.display = 'inline';
    fetch('ajax.php?action=reload_stations', { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.restart) {
                var tries = 0;
                var poll = setInterval(function() {
                    tries++;
                    fetch('ajax.php?action=check_update', { cache:'no-store' })
                        .then(function(r) { return r.json(); })
                        .then(function(s) {
                            if (s.running) {
                                clearInterval(poll);
                                msg.textContent = '✓ Daemon neugestartet – wird geladen…';
                                setTimeout(function() { location.reload(); }, 800);
                            }
                        });
                    if (tries >= 20) {
                        clearInterval(poll);
                        btn.disabled = false;
                        msg.textContent = '⚠ Timeout – bitte Seite manuell neu laden.';
                    }
                }, 3000);
            } else {
                btn.disabled = false;
                msg.textContent = d.msg || '<?= addslashes($L['MAIN.TAWES_RELOAD_OK'] ?? '✓ Cache neu geladen') ?>';
            }
        })
        .catch(function() { btn.disabled = false; msg.textContent = '❌ Fehler'; });
});
</script>

<?php render_footer(); ?>
