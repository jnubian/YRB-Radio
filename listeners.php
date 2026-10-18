<?php
// listeners.php - live listener locations for the 3D globe, straight from AzuraCast.
//
//   listeners.php               -> CSV of every location ever seen   (green dots)
//   listeners.php?format=live   -> JSON: listeners active in the last 24 hours (yellow dots) + totals
//
// The AzuraCast API key lives ONLY in this file on your server. It is never sent to browsers.
// Locations are remembered in listeners_store.json (same folder). listeners.csv (same folder) is merged in
// automatically whenever it changes; open listeners.php?reseed=1 to force a re-import.
// Refreshes itself when visitors load the globe. For the most complete 24-hour picture also add a cron job
// that opens  listeners.php?format=live  every minute (see the notes that came with this file).
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Cache-Control: no-store');

const AZURA_BASE      = 'https://a4.asurahosting.com';
const STATION_ID      = 'yrb_radio';        // station short name or numeric ID
const API_KEY         = '044edccfd7a60569:30d711ea47359fe4f249ec42e0001509';
const STORE_FILE      = __DIR__ . '/listeners_store.json';
const SEED_CSV        = __DIR__ . '/listeners.csv';
const REFRESH_SECONDS = 15;                 // how often AzuraCast is asked for the current listeners
const ACTIVE_WINDOW   = 86400;              // "live in the past 24 hours"
const SEEN_KEEP       = 172800;             // how long a listener session is remembered (avoids double counting)
const HISTORY_REFRESH = 120;                // how often the past-24-hours report is pulled from AzuraCast

function fail($code, $msg){
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}

function empty_store(){
    return ['updated' => 0, 'listening' => 0, 'live' => [], 'points' => [], 'seen' => [], 'uniq' => [], 'error' => '', 'seed_mtime' => 0, 'seed_rows' => 0, 'seed_note' => '', 'history_updated' => 0, 'history_rows' => 0, 'history_note' => ''];
}

// Reads listeners.csv by COLUMN NAME (lat/latitude, lon/lng/longitude, city, country, count), so both the slim
// file (lat,lon,count,city,country) and the raw AzuraCast/Last.fm style export ("Location: Latitude", ...) work.
function parse_seed_csv($path){
    $pts = [];
    if (!is_readable($path)) return [$pts, 'listeners.csv not found next to listeners.php'];
    $h = fopen($path, 'r');
    if (!$h) return [$pts, 'listeners.csv could not be opened'];
    $head = fgetcsv($h);
    if (!$head) { fclose($h); return [$pts, 'listeners.csv is empty']; }
    $names = [];
    foreach ($head as $c) $names[] = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$c)));
    $find = function($re) use ($names){ foreach ($names as $i => $n) if (preg_match($re, $n)) return $i; return -1; };
    $li = $find('/(^|[\s:_])(lat|latitude)$/');
    $oi = $find('/(^|[\s:_])(lon|lng|long|longitude)$/');
    $ci = $find('/(^|[\s:_])city$/');
    $ki = $find('/(^|[\s:_])country$/');
    $ni = $find('/^(count|sessions)$/');
    if ($li < 0 || $oi < 0) { fclose($h); return [$pts, 'no latitude/longitude columns found in listeners.csv']; }
    while (($r = fgetcsv($h)) !== false) {
        if (!isset($r[$li]) || !isset($r[$oi]) || !is_numeric($r[$li]) || !is_numeric($r[$oi])) continue;
        $lat = round((float)$r[$li], 4); $lon = round((float)$r[$oi], 4);
        if ($lat == 0.0 && $lon == 0.0) continue;
        if (abs($lat) > 90 || abs($lon) > 180) continue;
        $key = sprintf('%.4f,%.4f', $lat, $lon);
        $n = ($ni >= 0 && isset($r[$ni]) && is_numeric($r[$ni])) ? max(1, (int)$r[$ni]) : 1;
        if (!isset($pts[$key])) $pts[$key] = ['lat' => $lat, 'lon' => $lon, 'count' => 0, 'city' => '', 'cc' => '', 'last' => 0];
        $pts[$key]['count'] += $n;
        if ($pts[$key]['city'] === '' && $ci >= 0 && !empty($r[$ci])) $pts[$key]['city'] = (string)$r[$ci];
        if ($pts[$key]['cc'] === '' && $ki >= 0 && !empty($r[$ki])) $pts[$key]['cc'] = (string)$r[$ki];
    }
    fclose($h);
    return [$pts, count($pts) ? '' : 'listeners.csv had no usable rows'];
}

// Adds every location from listeners.csv that the store does not know yet (never lowers a stored count).
// Runs automatically whenever listeners.csv changes, or on demand with  listeners.php?reseed=1
function merge_seed(&$store){
    list($pts, $note) = parse_seed_csv(SEED_CSV);
    foreach ($pts as $key => $pt) {
        if (!isset($store['points'][$key])) $store['points'][$key] = $pt;
        else {
            if ($pt['count'] > $store['points'][$key]['count']) $store['points'][$key]['count'] = $pt['count'];
            if ($store['points'][$key]['city'] === '') $store['points'][$key]['city'] = $pt['city'];
            if ($store['points'][$key]['cc'] === '') $store['points'][$key]['cc'] = $pt['cc'];
        }
    }
    $seedCc = [];
    foreach ($pts as $pt) if ($pt['cc'] !== '') $seedCc[$pt['cc']] = 1;
    $store['seed_cc'] = array_keys($seedCc);
    $store['seed_rows'] = count($pts);
    $store['seed_note'] = $note;
    $store['seed_mtime'] = is_readable(SEED_CSV) ? (int)filemtime(SEED_CSV) : 0;
}

function read_store($fh){
    rewind($fh);
    $txt = stream_get_contents($fh);
    $j = ($txt !== false && $txt !== '') ? json_decode($txt, true) : null;
    if (!is_array($j) || !isset($j['points']) || !is_array($j['points'])) return empty_store();
    foreach (['live', 'seen', 'uniq'] as $k) if (!isset($j[$k]) || !is_array($j[$k])) $j[$k] = [];
    if (!isset($j['updated'])) $j['updated'] = 0;
    if (!isset($j['listening'])) $j['listening'] = 0;
    if (!isset($j['error'])) $j['error'] = '';
    foreach (['seed_mtime' => 0, 'seed_rows' => 0, 'seed_note' => '', 'history_updated' => 0, 'history_rows' => 0, 'history_note' => ''] as $k => $v) if (!isset($j[$k])) $j[$k] = $v;
    return $j;
}

function write_store($fh, $store){
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
}

function http_get_json($url){
    $hdr = ['X-API-Key: ' . API_KEY, 'Accept: application/json'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => $hdr, CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;
        return json_decode($body, true);
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8, 'header' => implode("\r\n", $hdr)]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : json_decode($body, true);
}

// Two-letter country code for a listener: the country field when it is a code, else the trailing code of the description.
function country_code($loc){
    $c = isset($loc['country']) ? strtoupper(trim((string)$loc['country'])) : '';
    if (preg_match('/^[A-Z]{2}$/', $c)) return $c;
    $d = isset($loc['description']) ? (string)$loc['description'] : '';
    if (preg_match('/,\s*([A-Za-z]{2})\s*$/', $d, $m)) return strtoupper($m[1]);
    return $c;
}

// Folds a list of AzuraCast listener rows into the store. $isCurrent = the "who is connected right now" list.
function ingest_rows(&$store, $rows, $now, &$live, $isCurrent){
    $cut = $now - ACTIVE_WINDOW;
    $n = 0;
    foreach ($rows as $l) {
        if (!is_array($l)) continue;
        $n++;
        $until = isset($l['connected_until']) ? (int)$l['connected_until'] : 0;
        $stillOn = ($isCurrent || $until <= 0);
        $stamp = $stillOn ? $now : min($now, $until);       // last moment this listener was on the stream
        if ($stamp < $cut) continue;
        $loc = (isset($l['location']) && is_array($l['location'])) ? $l['location'] : [];
        $lat = isset($loc['lat']) ? $loc['lat'] : null;
        $lon = isset($loc['lon']) ? $loc['lon'] : null;
        if (!is_numeric($lat) || !is_numeric($lon)) continue;
        $lat = round((float)$lat, 4); $lon = round((float)$lon, 4);
        if ($lat == 0.0 && $lon == 0.0) continue;
        $key = sprintf('%.4f,%.4f', $lat, $lon);
        if (!isset($store['points'][$key])) {
            $store['points'][$key] = ['lat' => $lat, 'lon' => $lon, 'count' => 0,
                'city' => (string)(isset($loc['city']) ? $loc['city'] : ''),
                'cc' => country_code($loc), 'last' => 0];
        }
        if ($store['points'][$key]['cc'] === '') $store['points'][$key]['cc'] = country_code($loc);
        $sid = (isset($l['hash']) ? $l['hash'] : md5((string)(isset($l['ip']) ? $l['ip'] : ''))) . '|' . (isset($l['connected_on']) ? $l['connected_on'] : 0);
        if (!isset($store['seen'][$sid])) { $store['points'][$key]['count']++; $store['seen'][$sid] = $stamp; }
        elseif ($store['seen'][$sid] < $stamp) $store['seen'][$sid] = $stamp;
        // one entry per LISTENER (AzuraCast's hash = device + address), so reconnects do not inflate the count
        $uid = isset($l['hash']) ? (string)$l['hash'] : ((isset($l['ip']) && $l['ip'] !== '') ? md5((string)$l['ip']) : $sid);
        if (!isset($store['uniq'][$uid]) || $store['uniq'][$uid] < $stamp) $store['uniq'][$uid] = $stamp;
        if ($store['points'][$key]['city'] === '' && !empty($loc['city'])) $store['points'][$key]['city'] = (string)$loc['city'];
        if ($store['points'][$key]['last'] < $stamp) $store['points'][$key]['last'] = $stamp;
        if ($stillOn) $live[$key] = 1;
    }
    return $n;
}

function refresh_store(&$store){
    $now = time();
    $store['updated'] = $now;                           // also on failure, so a broken API is not hammered
    $base = AZURA_BASE . '/api/station/' . rawurlencode(STATION_ID) . '/listeners';

    // 1) who is connected right now
    $rows = http_get_json($base);
    if (!is_array($rows)) {
        $store['error'] = 'AzuraCast request failed (check API_KEY and STATION_ID)';
    } else {
        $store['error'] = '';
        $live = [];
        $store['listening'] = count($rows);
        ingest_rows($store, $rows, $now, $live, true);
        $store['live'] = array_keys($live);
    }

    // 2) everyone who listened in the past 24 hours (AzuraCast's listener report for that time range)
    if ($now - (int)$store['history_updated'] >= HISTORY_REFRESH) {
        $store['history_updated'] = $now;
        $url = $base . '?start=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $now - ACTIVE_WINDOW)) . '&end=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $now));
        $hist = http_get_json($url);
        if (is_array($hist)) {
            $ignored = [];
            $store['history_rows'] = ingest_rows($store, $hist, $now, $ignored, false);
            $store['history_note'] = '';
        } else {
            $store['history_rows'] = 0;
            $store['history_note'] = 'past-24-hours request failed; only listeners seen while this script ran are shown';
        }
    }
    foreach ($store['seen'] as $k => $t) if ($now - $t > SEEN_KEEP) unset($store['seen'][$k]);
    foreach ($store['uniq'] as $k => $t) if ($now - $t > SEEN_KEEP) unset($store['uniq'][$k]);
}

// Country codes that exist only because of live listeners (not in listeners.csv). Empty = no new country yet.
function new_countries($store){
    $seed = isset($store['seed_cc']) && is_array($store['seed_cc']) ? array_flip($store['seed_cc']) : [];
    $new = [];
    foreach ($store['points'] as $p) if ($p['cc'] !== '' && !isset($seed[$p['cc']])) $new[$p['cc']] = 1;
    return array_keys($new);
}

function totals($store){
    $cities = []; $countries = [];
    foreach ($store['points'] as $p) {
        if ($p['city'] !== '') $cities[$p['city'] . '|' . $p['cc']] = 1;
        if ($p['cc'] !== '') $countries[$p['cc']] = 1;
    }
    return ['locations' => count($store['points']), 'cities' => count($cities), 'countries' => count($countries)];
}

$fh = @fopen(STORE_FILE, 'c+');
if (!$fh) fail(500, 'cannot open listeners_store.json (check folder permissions)');
if (flock($fh, LOCK_EX | LOCK_NB)) {
    $store = read_store($fh);
    $dirty = false;
    $mt = is_readable(SEED_CSV) ? (int)filemtime(SEED_CSV) : 0;
    if (isset($_GET['reseed']) || (int)$store['seed_mtime'] !== $mt || !isset($store['seed_cc'])) { merge_seed($store); $dirty = true; }
    if (time() - (int)$store['updated'] >= REFRESH_SECONDS) {
        if (API_KEY !== '') refresh_store($store); else $store['updated'] = time();
        $dirty = true;
    }
    if ($dirty) write_store($fh, $store);
    flock($fh, LOCK_UN);
} else {                                                // another request is refreshing: serve what is stored
    flock($fh, LOCK_SH);
    $store = read_store($fh);
    flock($fh, LOCK_UN);
}
fclose($fh);

if (isset($_GET['format']) && $_GET['format'] === 'live') {
    header('Content-Type: application/json; charset=utf-8');
    $now = time(); $cut = $now - ACTIVE_WINDOW; $liveSet = array_flip($store['live']); $active = [];
    $sessions = 0; $listeners = 0;
    foreach ($store['seen'] as $t) if ($t >= $cut) $sessions++;
    foreach ($store['uniq'] as $t) if ($t >= $cut) $listeners++;
    foreach ($store['points'] as $key => $p) {
        if ($p['last'] >= $cut) $active[] = [$p['lat'], $p['lon'], $p['city'], $p['cc'], $p['last'], isset($liveSet[$key]) ? 1 : 0];
    }
    echo json_encode([
        'now' => $now, 'updated' => $store['updated'], 'listening' => $store['listening'],
        'stats' => totals($store), 'new_countries' => new_countries($store), 'active' => $active, 'error' => $store['error'],
        'seed' => ['rows' => $store['seed_rows'], 'note' => $store['seed_note']],
        'listeners24h' => $listeners,      // different listeners in the last 24 hours (rolls off as they age out)
        'sessions24h' => $sessions,        // every connection, including reconnects (for comparison only)
        'history' => ['rows' => $store['history_rows'], 'note' => $store['history_note']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['lat', 'lon', 'count', 'city', 'country']);
    foreach ($store['points'] as $p) fputcsv($out, [$p['lat'], $p['lon'], $p['count'], $p['city'], $p['cc']]);
    fclose($out);
}
