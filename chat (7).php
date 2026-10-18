<?php
// chat.php - chat backend for index.html
// Messages live in chat.txt (one JSON object per line) in the SAME folder as this file.
// Messages are kept until the newest MAX_KEEP are exceeded (set MAX_AGE below to expire old ones).
//
// Optional files in the same folder:
//   chat_badwords.txt  - extra words to mask, one per line (lines starting with # are ignored)
//   chat_blocked.txt   - created automatically when a moderator blocks a visitor
//
// MODERATION: set ADMIN_TOKEN below, then open  index.html?admin=YOUR_TOKEN  once on your own device.
// "del" and "ban" buttons then appear next to every chat line for you only.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const CHAT_FILE    = __DIR__ . '/chat.txt';
const BLOCK_FILE   = __DIR__ . '/chat_blocked.txt';
const WORDS_FILE   = __DIR__ . '/chat_badwords.txt';
const MAX_AGE      = 0;       // seconds to keep a message; 0 = never expire (86400 would be 24 hours)
const MAX_KEEP     = 1000;    // hard cap on stored messages (oldest are dropped past this)
const MAX_LEN      = 300;     // characters per message
const MIN_GAP      = 2.0;     // seconds between messages from one visitor
const SALT         = 'change-this-to-any-random-text';
const ADMIN_TOKEN  = '';      // put a long random string here to turn moderation on

if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null){ return substr($s, $a, $l); } }
if (!function_exists('mb_strlen')) { function mb_strlen($s){ return strlen($s); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s){ return strtolower($s); } }

function fail($code, $msg){ http_response_code($code); echo json_encode(['error' => $msg]); exit; }

// ---------- profanity filter: masks the word, keeps the first letter (f***) ----------
function build_filter(){
    $words = ['fuck','shit','bitch','asshole','cunt','dick','pussy','whore','slut','bastard',
              'nigger','nigga','faggot','retard'];
    if (is_readable(WORDS_FILE)) {
        $extra = file(WORDS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($extra)) {
            foreach ($extra as $w) {
                $w = trim(mb_strtolower($w));
                if ($w !== '' && $w[0] !== '#') $words[] = $w;
            }
        }
    }
    $leet = ['a' => '[a@4]', 'e' => '[e3]', 'i' => '[i1!|]', 'o' => '[o0]', 's' => '[s$5]', 't' => '[t7]', 'l' => '[l1]'];
    $parts = [];
    foreach (array_unique($words) as $w) {
        $p = '';
        $chars = preg_split('//u', $w, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars) continue;
        foreach ($chars as $ch) $p .= isset($leet[$ch]) ? $leet[$ch] : preg_quote($ch, '/');
        $parts[] = $p;
    }
    if (!$parts) return null;
    return '/(?<![\p{L}\p{N}])(?:' . implode('|', $parts) . ')(?:s|es|ed|er|ers|ing|in)?(?![\p{L}\p{N}])/iu';
}
function mask_text($s, $re){
    if (!$re) return $s;
    $out = @preg_replace_callback($re, function($m){
        $len = mb_strlen($m[0]);
        return mb_substr($m[0], 0, 1) . str_repeat('*', max(0, $len - 1));
    }, $s);
    return $out === null ? $s : $out;
}

$fh = @fopen(CHAT_FILE, 'c+');
if (!$fh) fail(500, 'cannot open chat.txt (check folder permissions)');
flock($fh, LOCK_EX);

$now = microtime(true);
$all = [];
while (($line = fgets($fh)) !== false) {
    $j = json_decode(trim($line), true);
    if (is_array($j) && isset($j['t'], $j['m'])) $all[] = $j;
}
$msgs  = array_values(array_filter($all, function($m) use ($now){ return MAX_AGE <= 0 || ($now - $m['t']) < MAX_AGE; }));
$dirty = count($msgs) !== count($all);

$tok     = isset($_SERVER['HTTP_X_CHAT_ADMIN']) ? (string)$_SERVER['HTTP_X_CHAT_ADMIN'] : '';
$isAdmin = (ADMIN_TOKEN !== '' && hash_equals(ADMIN_TOKEN, $tok));

$postedT = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;

    if (isset($in['action'])) {
        // ----- moderator actions -----
        if (!$isAdmin) { flock($fh, LOCK_UN); fclose($fh); fail(403, 'not allowed'); }
        if ($in['action'] === 'delete' && isset($in['t'])) {
            $target = (float)$in['t'];
            $msgs = array_values(array_filter($msgs, function($m) use ($target){ return abs($m['t'] - $target) > 0.0005; }));
            $dirty = true;
        } elseif ($in['action'] === 'block' && isset($in['h']) && preg_match('/^[a-f0-9]{12}$/', (string)$in['h'])) {
            $h = (string)$in['h'];
            $blocked = is_readable(BLOCK_FILE) ? array_map('trim', file(BLOCK_FILE)) : [];
            if (!in_array($h, $blocked, true)) @file_put_contents(BLOCK_FILE, $h . "\n", FILE_APPEND | LOCK_EX);
            $msgs = array_values(array_filter($msgs, function($m) use ($h){ return ($m['h'] ?? '') !== $h; }));
            $dirty = true;
        }
    } else {
        // ----- normal visitor message -----
        $re = build_filter();

        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($in['m'] ?? '')));
        $text = mb_substr($text, 0, MAX_LEN);
        $nick = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($in['n'] ?? '')));
        $nick = mb_substr($nick, 0, 20);
        if ($nick === '') $nick = 'guest';
        if ($text === '') { flock($fh, LOCK_UN); fclose($fh); fail(400, 'empty message'); }
        $text = mask_text($text, $re);
        $nick = mask_text($nick, $re);

        // country: trust Cloudflare's header when present, otherwise the code the page looked up
        $cc = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'XX' || $cc === 'T1') $cc = strtoupper((string)($in['c'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $cc)) $cc = 'XX';

        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $h  = substr(hash('sha256', SALT . $ip), 0, 12);   // used for rate limit + blocking; only moderators ever see it

        $blocked = is_readable(BLOCK_FILE) ? array_map('trim', file(BLOCK_FILE)) : [];
        if (in_array($h, $blocked, true)) { flock($fh, LOCK_UN); fclose($fh); fail(403, 'blocked'); }

        for ($i = count($msgs) - 1; $i >= 0; $i--) {
            if (($msgs[$i]['h'] ?? '') === $h) {
                if ($now - $msgs[$i]['t'] < MIN_GAP) { flock($fh, LOCK_UN); fclose($fh); fail(429, 'slow down'); }
                break;
            }
        }

        $postedT = round($now, 3);
        $msgs[] = ['t' => $postedT, 'n' => $nick, 'c' => $cc, 'm' => $text, 'h' => $h];
        if (count($msgs) > MAX_KEEP) $msgs = array_slice($msgs, -MAX_KEEP);
        $dirty = true;
    }
}

if ($dirty) {
    ftruncate($fh, 0);
    rewind($fh);
    foreach ($msgs as $m) fwrite($fh, json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fh);
}
flock($fh, LOCK_UN);
fclose($fh);

$since = isset($_GET['since']) ? (float)$_GET['since'] : 0.0;
$out   = [];
$live  = [];
foreach ($msgs as $m) {
    $live[] = $m['t'];
    if ($m['t'] > $since) {
        $row = ['t' => $m['t'], 'n' => $m['n'], 'c' => $m['c'], 'm' => $m['m']];
        if ($isAdmin) $row['h'] = $m['h'] ?? '';
        $out[] = $row;
    }
}
$resp = ['messages' => $out, 'live' => $live, 'now' => $now];
if ($postedT !== null) { $resp['ok'] = true; $resp['t'] = $postedT; }
echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
