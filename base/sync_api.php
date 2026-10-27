<?php
/* Virabot 5.7.0 — Panel sync HTTP API (hub only). See docs/SYNC_API.md
 * POST JSON, header  Authorization: Bearer <client token>
 * ?action=ping|reserve|release|event|claim|upsert|lookup|list
 * Fail-closed: 403 unless this install is in "hub" mode; 401 on any token problem. */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ps_api_out($code, $data, $extraHeaders = [])
{
    http_response_code($code);
    foreach ($extraHeaders as $h) {
        header($h);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ps_api_out(405, ['ok' => false, 'error' => 'POST only'], ['Allow: POST']);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/botapi.php';
require_once __DIR__ . '/function.php';
if (!function_exists('ps_dispatch') && is_file(__DIR__ . '/panel_sync.php')) {
    require_once __DIR__ . '/panel_sync.php';
}
if (!function_exists('ps_dispatch') || empty($pdo)) {
    ps_api_out(503, ['ok' => false, 'error' => 'sync unavailable']);
}
try {
    if (ps_mode() !== 'hub') {
        ps_api_out(403, ['ok' => false, 'error' => 'hub disabled']);
    }
} catch (Throwable $e) {
    ps_api_out(503, ['ok' => false, 'error' => 'sync unavailable']);
}

// ---- auth (sha256 lookup + constant-time compare) ----
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0) {
            $auth = $v;
        }
    }
}
if (!preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,200})$/', trim((string) $auth), $m)) {
    ps_api_out(401, ['ok' => false, 'error' => 'unauthorized'], ['WWW-Authenticate: Bearer']);
}
$hash = hash('sha256', $m[1]);
try {
    $st = $pdo->prepare("SELECT * FROM sync_clients WHERE token_hash = ? LIMIT 1");
    $st->execute([$hash]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    ps_api_out(503, ['ok' => false, 'error' => 'sync unavailable']);
}
if (!$client || !hash_equals((string) $client['token_hash'], $hash) || (int) $client['active'] !== 1) {
    ps_api_out(401, ['ok' => false, 'error' => 'unauthorized'], ['WWW-Authenticate: Bearer']);
}

// ---- rate limit (fixed 60s window per client) ----
$win = (int) floor(time() / 60);
$pdo->prepare("UPDATE sync_clients SET rl_count = IF(rl_window = ?, rl_count + 1, 1), rl_window = ?, last_used = ? WHERE id = ?")
    ->execute([$win, $win, time(), $client['id']]);
$st = $pdo->prepare("SELECT rl_count FROM sync_clients WHERE id = ?");
$st->execute([$client['id']]);
$cnt = (int) $st->fetchColumn();
if ($cnt > PS_RATE_LIMIT_PER_MIN) {
    ps_api_out(429, ['ok' => false, 'error' => 'rate limited'], ['Retry-After: ' . (60 - time() % 60)]);
}
header('X-RateLimit-Limit: ' . PS_RATE_LIMIT_PER_MIN);
header('X-RateLimit-Remaining: ' . max(0, PS_RATE_LIMIT_PER_MIN - $cnt));

// ---- body ----
$raw = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
$body = ($raw === '' || $raw === false) ? [] : json_decode($raw, true);
if (!is_array($body)) {
    ps_api_out(400, ['ok' => false, 'error' => 'invalid json']);
}
$action = (string) ($_GET['action'] ?? ($body['action'] ?? ''));
if (!preg_match('/^[a-z]{2,20}$/', $action)) {
    ps_api_out(400, ['ok' => false, 'error' => 'invalid action']);
}
// owner identity always comes from the token, never from the request body
$ctx = ['owner_type' => $client['owner_type'] === 'site' ? 'site' : 'bot', 'owner_id' => 'c:' . $client['name'], 'admin' => false];
unset($body['force']);
try {
    list($code, $out) = ps_dispatch($ctx, $action, $body);
} catch (Throwable $e) {
    error_log('[sync_api] ' . $e->getMessage());
    ps_api_out(500, ['ok' => false, 'error' => 'server error']);
}
ps_api_out($code, $out);
