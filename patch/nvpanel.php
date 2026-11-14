<?php
/**
 * NV Panel gateway consumer, contract v1.3.1; read-only catalog GET /products from v1.3.5 (silent4time/nvpanel-gateway).
 * The bearer token is the gateway consumer token, not a GitHub token.
 * Live base URL is not baked in. Empty panel URL or token stays disabled.
 */
declare(strict_types=1);

const NV_SYNC_TIMEOUT = 55;
const NV_LOW_TOMAN = 1000000;
const NV_LOW_REMIND = 43200;

function nvpanel_product_ok(string $id): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id);
}

function nvpanel_base_ok(string $url): bool
{
    $url = trim($url);
    if ($url === '' || str_contains(strtolower($url), 'example.com')) {
        return false;
    }
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
        return false;
    }
    if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
        return false;
    }
    $host = strtolower((string) ($p['host'] ?? ''));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (isset($p['port']) && (int) $p['port'] !== 443) {
        return false;
    }
    $path = (string) ($p['path'] ?? '');
    return $path === '' || $path === '/';
}

function nvpanel_origin(string $url): string
{
    $p = parse_url(trim($url));
    return 'https://' . strtolower((string) ($p['host'] ?? ''));
}

function nvpanel_configured(array $panel): bool
{
    $url = trim((string) ($panel['url_panel'] ?? ''));
    $token = trim((string) ($panel['password_panel'] ?? ''));
    return ($panel['type'] ?? '') === 'nvpanel' && nvpanel_base_ok($url) && strlen($token) >= 8 && strlen($token) <= 200 && !preg_match("/[\r\n]/", $token);
}

function nvpanel_sales_open(array $panel): bool
{
    return nvpanel_configured($panel) && (string) ($panel['status'] ?? '') === 'active';
}

function nvpanel_key(string $domain, string $panelCode, string $invoice, string $action, string $order = ''): string
{
    $scope = substr(hash('sha256', strtolower($domain) . '|' . $panelCode), 0, 12);
    $key = 'vb:' . $scope . ':' . $invoice . ':' . $action;
    if ($order !== '') {
        $key .= ':' . $order;
    }
    return substr($key, 0, 128);
}

function nvpanel_canonical(array $request): string
{
    ksort($request);
    return json_encode($request, JSON_UNESCAPED_UNICODE);
}

/** @return array{state:string,refund:bool,deliver:bool,alert:bool,op_id:?int} */
function nvpanel_classify(int $http, array $body): array
{
    $status = (string) ($body['status'] ?? '');
    $code = (string) ($body['code'] ?? '');
    $ok = !empty($body['ok']);
    $op = isset($body['op_id']) ? (int) $body['op_id'] : null;
    $base = ['state' => 'unknown', 'refund' => false, 'deliver' => false, 'alert' => false, 'op_id' => $op];
    if ($ok && $status === 'success' && $http === 200) {
        $base['state'] = 'success';
        $base['deliver'] = true;
        return $base;
    }
    if ($status === 'pending' || $code === 'in_progress' || $http === 202 && $code === 'in_progress') {
        $base['state'] = 'pending';
        $base['alert'] = false;
        return $base;
    }
    if ($status === 'needs_review' || $code === 'needs_review') {
        $base['state'] = 'needs_review';
        $base['alert'] = true;
        return $base;
    }
    if ($status === 'resolved') {
        if ($ok && !empty($body['username'])) {
            $base['state'] = 'success';
            $base['deliver'] = true;
            return $base;
        }
        $base['state'] = 'needs_review';
        $base['alert'] = true;
        return $base;
    }
    if ($ok === false && $status === 'failed' && $http !== 0) {
        $base['state'] = 'failed';
        $base['refund'] = in_array($code, ['insufficient_balance', 'unknown_product', 'product_not_allowed', 'unauthorized', 'idempotency_key_required', 'bad_request', 'username_required'], true);
        $base['alert'] = true;
        return $base;
    }
    if (in_array($http, [400, 401, 402, 404], true) && $ok === false && $code !== '' && $code !== 'ambiguous') {
        $base['state'] = 'failed';
        $base['refund'] = true;
        $base['alert'] = true;
        return $base;
    }
    if (in_array($code, ['ambiguous', 'panel_unreachable', 'timeout', 'in_progress'], true) || in_array($http, [0, 409, 502, 504], true)) {
        $base['state'] = $op ? 'pending' : 'unknown';
        $base['alert'] = true;
        return $base;
    }
    $base['alert'] = true;
    return $base;
}

/**
 * Next network action. Never invents a new key.
 * @return array{step:string,refund:bool}
 */
function nvpanel_step(array $row, array $request, bool $cron): array
{
    if ($row !== [] && nvpanel_canonical($request) !== (string) ($row['request_json'] ?? '')) {
        return ['step' => 'conflict', 'refund' => false];
    }
    $state = (string) ($row['state'] ?? '');
    if ($state === 'success' && !empty($row['delivered'])) {
        return ['step' => 'done', 'refund' => false];
    }
    if ($state === 'success') {
        return ['step' => 'deliver', 'refund' => false];
    }
    if ($state === 'needs_review') {
        return ['step' => !empty($row['op_id']) ? 'poll' : 'wait', 'refund' => false];
    }
    if ($state === 'pending' && !empty($row['op_id'])) {
        return ['step' => 'poll', 'refund' => false];
    }
    if ($state === 'failed') {
        return ['step' => 'stop', 'refund' => !empty($row['refund_ok'])];
    }
    if ($state === 'unknown') {
        return ['step' => 'wait', 'refund' => false];
    }
    if ($row === [] || $state === '') {
        return ['step' => 'post', 'refund' => false];
    }
    return ['step' => 'wait', 'refund' => false];
}

function nvpanel_expiry_text(?string $iso, $remaining): string
{
    if ($iso === null || $iso === '') {
        return 'تاریخ انقضا در پاسخ پنل خالی است. این به معنی منقضی‌شدن نیست؛ ممکن است دوره از اتصال بعدی شروع شود.';
    }
    $extra = is_int($remaining) || (is_string($remaining) && preg_match('/^-?\d+$/', $remaining))
        ? "\nروز مانده: " . $remaining
        : '';
    return 'انقضا: ' . $iso . $extra;
}

function nvpanel_balance_alert(array $prev, $balance, int $now, int $threshold = NV_LOW_TOMAN, int $remind = NV_LOW_REMIND): array
{
    if ($balance === null || $balance === '') {
        return ['kind' => null, 'state' => $prev];
    }
    $balance = (int) $balance;
    $lowSince = (int) ($prev['low_since'] ?? 0);
    $last = (int) ($prev['last_alert'] ?? 0);
    if ($balance >= $threshold) {
        if ($lowSince > 0) {
            return ['kind' => 'balance_recovered', 'state' => ['low_since' => 0, 'last_alert' => 0]];
        }
        return ['kind' => null, 'state' => ['low_since' => 0, 'last_alert' => 0]];
    }
    if ($lowSince === 0) {
        return ['kind' => 'low_balance', 'state' => ['low_since' => $now, 'last_alert' => $now]];
    }
    if ($remind > 0 && ($now - $last) < $remind) {
        return ['kind' => null, 'state' => $prev];
    }
    return ['kind' => 'low_balance', 'state' => ['low_since' => $lowSince, 'last_alert' => $now]];
}

function nvpanel_http(string $method, string $url, array $headers, string $body, int $timeout, array $hosts): array
{
    $hook = $GLOBALS['nvpanel_transport'] ?? null;
    if (is_callable($hook)) {
        return $hook($method, $url, $headers, $body, $timeout, $hosts);
    }
    $parts = parse_url($url);
    $hostOk = is_array($parts)
        && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
        && in_array(strtolower((string) ($parts['host'] ?? '')), $hosts, true)
        && !isset($parts['user']);
    if (!function_exists('curl_init') || !$hostOk) {
        return ['status' => 0, 'body' => '', 'error' => 'unpinned'];
    }
    $ch = curl_init($url);
    $hdr = [];
    foreach ($headers as $k => $v) {
        $hdr[] = $k . ': ' . $v;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_POSTFIELDS => $method === 'GET' ? null : $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['status' => 0, 'body' => '', 'error' => 'timeout'];
    }
    return ['status' => $status, 'body' => $raw, 'error' => ''];
}

function nvpanel_request(array $panel, string $method, string $path, ?array $json, int $timeout = 20): array
{
    if (!nvpanel_configured($panel)) {
        return ['status' => 0, 'json' => ['ok' => false, 'code' => 'not_configured', 'error' => 'panel not configured'], 'error' => 'config'];
    }
    $origin = nvpanel_origin((string) $panel['url_panel']);
    $url = $origin . $path;
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $payload = $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE);
    $headers = [
        'Authorization' => 'Bearer ' . trim((string) $panel['password_panel']),
        'Accept' => 'application/json',
        'User-Agent' => 'VirabotNvpanel/1.3.1',
    ];
    if ($json !== null) {
        $headers['Content-Type'] = 'application/json';
    }
    $res = nvpanel_http($method, $url, $headers, (string) $payload, $timeout, [$host]);
    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    return [
        'status' => (int) ($res['status'] ?? 0),
        'json' => is_array($decoded) ? $decoded : [],
        'error' => (string) ($res['error'] ?? ''),
    ];
}

function nvpanel_is_sqlite(PDO $pdo): bool
{
    try {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    } catch (Throwable $e) {
        return false;
    }
}

function nvpanel_ensure(PDO $pdo): void
{
    if (nvpanel_is_sqlite($pdo)) {
        // Offline tests only. Production is MySQL/MariaDB.
        $pdo->exec("CREATE TABLE IF NOT EXISTS nvpanel_ops (
            id INTEGER PRIMARY KEY AUTOINCREMENT, idem_key TEXT NOT NULL UNIQUE, panel_code TEXT NOT NULL, invoice_id TEXT NOT NULL,
            customer_id TEXT NOT NULL, action TEXT NOT NULL, request_json TEXT NOT NULL, op_id INTEGER NULL, state TEXT NOT NULL,
            refund_ok INTEGER NOT NULL DEFAULT 0, username TEXT NULL, secret_pass TEXT NULL, delivered INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL)");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS nvpanel_ops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idem_key VARCHAR(128) NOT NULL UNIQUE,
        panel_code VARCHAR(64) NOT NULL,
        invoice_id VARCHAR(64) NOT NULL,
        customer_id VARCHAR(32) NOT NULL,
        action VARCHAR(16) NOT NULL,
        request_json TEXT NOT NULL,
        op_id INT NULL,
        state VARCHAR(32) NOT NULL,
        refund_ok TINYINT NOT NULL DEFAULT 0,
        username VARCHAR(128) NULL,
        secret_pass TEXT NULL,
        delivered TINYINT NOT NULL DEFAULT 0,
        updated_at INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function nvpanel_load(PDO $pdo, string $key): array
{
    $st = $pdo->prepare('SELECT * FROM nvpanel_ops WHERE idem_key = ?');
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function nvpanel_save(PDO $pdo, array $row): void
{
    $sql = 'INSERT INTO nvpanel_ops (idem_key,panel_code,invoice_id,customer_id,action,request_json,op_id,state,refund_ok,username,secret_pass,delivered,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE op_id=VALUES(op_id), state=VALUES(state), refund_ok=VALUES(refund_ok), username=VALUES(username), secret_pass=VALUES(secret_pass), delivered=VALUES(delivered), updated_at=VALUES(updated_at)';
    if (nvpanel_is_sqlite($pdo)) {
        $sql = 'INSERT INTO nvpanel_ops (idem_key,panel_code,invoice_id,customer_id,action,request_json,op_id,state,refund_ok,username,secret_pass,delivered,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(idem_key) DO UPDATE SET op_id=excluded.op_id, state=excluded.state, refund_ok=excluded.refund_ok, username=excluded.username, secret_pass=excluded.secret_pass, delivered=excluded.delivered, updated_at=excluded.updated_at';
    }
    $pdo->prepare($sql)->execute([
        $row['idem_key'], $row['panel_code'], $row['invoice_id'], $row['customer_id'], $row['action'], $row['request_json'],
        $row['op_id'], $row['state'], $row['refund_ok'], $row['username'], $row['secret_pass'], $row['delivered'], $row['updated_at'],
    ]);
}

function nvpanel_run(PDO $pdo, array $panel, array $request, string $key, string $path, bool $cron, array $meta = []): array
{
    nvpanel_ensure($pdo);
    $row = nvpanel_load($pdo, $key);
    $step = nvpanel_step($row, $request, $cron);
    if ($step['step'] === 'conflict') {
        return ['status' => false, '_nvpanel_hold' => true, 'msg' => 'کلید تکرار با درخواست دیگری ثبت شده و ارسال نشد.'];
    }
    if ($step['step'] === 'stop') {
        return ['status' => false, '_nvpanel_hold' => empty($row['refund_ok']), 'msg' => 'عملیات درگاه ناموفق است و خودکار دوباره خرید نمی‌شود.'];
    }
    if ($step['step'] === 'wait') {
        return ['status' => false, '_nvpanel_hold' => true, 'msg' => 'نتیجه هنوز قطعی نیست. مبلغ برنمی‌گردد و خرید تازه زده نمی‌شود.'];
    }
    if ($step['step'] === 'deliver' && $row !== []) {
        return nvpanel_success_from_row($row);
    }
    if ($step['step'] === 'done') {
        return nvpanel_success_from_row($row);
    }
    if ($step['step'] === 'poll') {
        $res = nvpanel_request($panel, 'GET', '/op/' . (int) $row['op_id'], null, 20);
    } else {
        $res = nvpanel_request($panel, 'POST', $path, $request, NV_SYNC_TIMEOUT + 5);
    }
    if ((int) $res['status'] === 0) {
        $row = nvpanel_merge($row, $key, $panel, $request, ['state' => 'unknown', 'op_id' => $row['op_id'] ?? null, 'refund_ok' => 0], $meta);
        nvpanel_save($pdo, $row);
        return ['status' => false, '_nvpanel_hold' => true, 'msg' => 'پاسخ درگاه قطع شد. همان کلید نگه داشته شد و برگشت خودکار انجام نمی‌شود.'];
    }
    $class = nvpanel_classify((int) $res['status'], $res['json']);
    $row = nvpanel_merge($row, $key, $panel, $request, [
        'state' => $class['state'],
        'op_id' => $class['op_id'] ?: ($row['op_id'] ?? null),
        'refund_ok' => $class['refund'] ? 1 : 0,
        'username' => (string) ($res['json']['username'] ?? ($row['username'] ?? '')),
        'secret_pass' => array_key_exists('password', $res['json']) ? (string) $res['json']['password'] : ($row['secret_pass'] ?? null),
    ], $meta);
    nvpanel_save($pdo, $row);
    if ($class['deliver']) {
        return nvpanel_success_from_row($row);
    }
    return [
        'status' => false,
        '_nvpanel_hold' => !$class['refund'],
        'msg' => (string) ($res['json']['error'] ?? $class['state']),
        '_nv_state' => $class['state'],
    ];
}

function nvpanel_merge(array $row, string $key, array $panel, array $request, array $patch, array $meta = []): array
{
    return array_merge([
        'idem_key' => $key,
        'panel_code' => (string) ($panel['code_panel'] ?? ''),
        'invoice_id' => (string) ($meta['invoice'] ?? ($row['invoice_id'] ?? '')),
        'customer_id' => (string) ($meta['customer'] ?? ($row['customer_id'] ?? '')),
        'action' => (string) ($meta['action'] ?? ($row['action'] ?? '')),
        'request_json' => nvpanel_canonical(array_diff_key($request, ['_invoice' => 1, '_customer' => 1, '_action' => 1])),
        'op_id' => null,
        'state' => 'unknown',
        'refund_ok' => 0,
        'username' => null,
        'secret_pass' => null,
        'delivered' => 0,
        'updated_at' => time(),
    ], $row, $patch, ['updated_at' => time(), 'idem_key' => $key]);
}

function nvpanel_success_from_row(array $row): array
{
    return [
        'status' => 'successful',
        'username' => (string) ($row['username'] ?? ''),
        'subscription_url' => '',
        'configs' => [],
        '_nv_password' => (string) ($row['secret_pass'] ?? ''),
        '_nvpanel' => true,
        '_nv_state' => 'success',
    ];
}

function nvpanel_bind_order(string $invoice, string $order): void
{
    $GLOBALS['nvpanel_order'] = ['invoice' => $invoice, 'order' => $order];
}

function nvpanel_purchase(array $panel, string $codeProduct, string $usernameHint, array $cfg): array
{
    if (in_array($codeProduct, ['customvolume', 'custom_volume', 'usertest'], true)) {
        return ['status' => 'Unsuccessful', 'msg' => 'حجم دلخواه و اکانت تست برای nvpanel پشتیبانی نمی‌شود.'];
    }
    if (!nvpanel_sales_open($panel)) {
        return ['status' => 'Unsuccessful', 'msg' => 'پنل nvpanel خاموش است یا توکن از ترمینال ثبت نشده است.'];
    }
    global $pdo, $domainhosts;
    if (!isset($pdo) || !$pdo instanceof PDO) {
        return ['status' => 'Unsuccessful', 'msg' => 'پایگاه داده در دسترس نیست.'];
    }
    $productId = '';
    $st = $pdo->prepare('SELECT nv_product_id FROM product WHERE code_product = ? AND Location = ? LIMIT 1');
    $st->execute([$codeProduct, $panel['name_panel']]);
    $productId = trim((string) $st->fetchColumn());
    if (!nvpanel_product_ok($productId)) {
        return ['status' => 'Unsuccessful', 'msg' => 'شناسه محصول درگاه برای این محصول تنظیم نشده است.'];
    }
    // The invoice table has no `id` column (primary key is id_invoice); newest sale first.
    $inv = $pdo->prepare('SELECT id_invoice, id_user FROM invoice WHERE username = ? AND Service_location = ? ORDER BY time_sell DESC LIMIT 1');
    $inv->execute([$usernameHint, (string) $panel['name_panel']]);
    $invoice = $inv->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return ['status' => 'Unsuccessful', 'msg' => 'فاکتور این خرید پیدا نشد.'];
    }
    $body = [
        'ref_id' => nvpanel_key((string) $domainhosts, (string) $panel['code_panel'], (string) $invoice['id_invoice'], 'create'),
        'product' => $productId,
        'note' => substr((string) ($cfg['from_id'] ?? ''), 0, 200),
    ];
    $out = nvpanel_run($pdo, $panel, $body, $body['ref_id'], '/create', false, [
        'invoice' => (string) $invoice['id_invoice'],
        'customer' => (string) $invoice['id_user'],
        'action' => 'create',
    ]);
    if (!empty($out['_nvpanel'])) {
        // The gateway picks the username, so callers that later mark the invoice active by the old
        // username would miss it. Activate it here, by id, together with the real username.
        $pdo->prepare("UPDATE invoice SET username = ?, Status = 'active' WHERE id_invoice = ?")->execute([$out['username'], $invoice['id_invoice']]);
        $pdo->prepare('UPDATE nvpanel_ops SET delivered = 1 WHERE idem_key = ?')->execute([$body['ref_id']]);
    }
    return $out;
}

function nvpanel_renew(array $panel, string $username, string $invoiceId, string $orderKey, bool $cron = false): array
{
    if (!nvpanel_sales_open($panel)) {
        return ['status' => false, 'msg' => 'پنل nvpanel خاموش است. تمدید انجام نشد.'];
    }
    global $pdo, $domainhosts;
    if (!isset($pdo) || !$pdo instanceof PDO || $orderKey === '') {
        return ['status' => false, 'msg' => 'شناسه پرداخت تمدید مشخص نیست.'];
    }
    $owned = $pdo->prepare('SELECT id_user FROM invoice WHERE id_invoice = ? AND username = ? LIMIT 1');
    $owned->execute([$invoiceId, $username]);
    $customer = $owned->fetchColumn();
    if ($customer === false) {
        return ['status' => false, 'msg' => 'این فاکتور متعلق به همین سرویس نیست.'];
    }
    if ($orderKey === 'auto') {
        $busy = $pdo->prepare("SELECT idem_key FROM nvpanel_ops WHERE invoice_id = ? AND action = 'renew' AND state IN ('unknown','pending','needs_review') ORDER BY id DESC LIMIT 1");
        $busy->execute([$invoiceId]);
        $existing = (string) $busy->fetchColumn();
        if ($existing !== '') {
            $key = $existing;
            $body = ['username' => $username, 'ref_id' => $key];
            $out = nvpanel_run($pdo, $panel, $body, $key, '/renew', $cron, [
                'invoice' => $invoiceId,
                'customer' => (string) $customer,
                'action' => 'renew',
            ]);
            if (!empty($out['_nvpanel'])) {
                return ['status' => true, 'msg' => 'extended', 'username' => $out['username']];
            }
            return ['status' => false, '_nvpanel_hold' => !empty($out['_nvpanel_hold']), 'msg' => (string) ($out['msg'] ?? '')];
        }
        $orderKey = 'pay:' . bin2hex(random_bytes(8));
    }
    $key = nvpanel_key((string) $domainhosts, (string) $panel['code_panel'], $invoiceId, 'renew', $orderKey);
    $body = ['username' => $username, 'ref_id' => $key];
    $out = nvpanel_run($pdo, $panel, $body, $key, '/renew', $cron, [
        'invoice' => $invoiceId,
        'customer' => (string) $customer,
        'action' => 'renew',
    ]);
    if (!empty($out['_nvpanel'])) {
        return ['status' => true, 'msg' => 'extended', 'username' => $out['username']];
    }
    return ['status' => false, '_nvpanel_hold' => !empty($out['_nvpanel_hold']), 'msg' => (string) ($out['msg'] ?? '')];
}

function nvpanel_data_user(array $panel, string $username): array
{
    global $pdo;
    if (!nvpanel_sales_open($panel)) {
        return ['status' => 'Unsuccessful', 'msg' => 'پنل nvpanel خاموش است.'];
    }
    if (!isset($pdo) || !$pdo instanceof PDO) {
        return ['status' => 'Unsuccessful', 'msg' => 'db'];
    }
    $st = $pdo->prepare('SELECT id_invoice FROM invoice WHERE username = ? AND Service_location = ? LIMIT 1');
    $st->execute([$username, $panel['name_panel']]);
    if (!$st->fetchColumn()) {
        return ['status' => 'Unsuccessful', 'msg' => 'سرویس در فاکتورهای همین ربات نیست.'];
    }
    $res = nvpanel_request($panel, 'GET', '/user/' . rawurlencode($username), null, 20);
    $body = $res['json'];
    if ((int) $res['status'] !== 200 || empty($body['ok'])) {
        return ['status' => 'Unsuccessful', 'msg' => (string) ($body['error'] ?? 'lookup')];
    }
    $expire = $body['expire_at'] ?? null;
    return [
        'status' => 'active',
        'username' => (string) ($body['username'] ?? $username),
        'expire' => 0,
        'data_limit' => null,
        'used_traffic' => null,
        'online_status' => false,
        'links' => [],
        'subscription_url' => '',
        '_nvpanel' => true,
        '_nv_status' => (string) ($body['status'] ?? ''),
        '_nv_expire' => is_string($expire) ? $expire : null,
        '_nv_remaining' => $body['remaining_days'] ?? null,
        '_nv_product_id' => $body['product_id'] ?? null,
        'msg' => nvpanel_expiry_text(is_string($expire) ? $expire : null, $body['remaining_days'] ?? null),
    ];
}

function nvpanel_may_map_product(array $product, array $panel): bool
{
    if (($panel['type'] ?? '') !== 'nvpanel') {
        return false;
    }
    $loc = (string) ($product['Location'] ?? '');
    if ($loc === '' || $loc === '/all') {
        return false;
    }
    return $loc === (string) ($panel['name_panel'] ?? '');
}

function nvpanel_is_nav_text(string $text): bool
{
    global $textbotlang;
    $t = trim($text);
    if ($t === '') {
        return false;
    }
    $exact = [
        (string) ($textbotlang['Admin']['backAdminBtn'] ?? ''),
        (string) ($textbotlang['Admin']['backMenuBtn'] ?? ''),
        (string) ($textbotlang['users']['backbtn'] ?? ''),
        (string) ($textbotlang['Admin']['panelAdmin'] ?? ''),
        '🏠 بازگشت به منوی مدیریت',
        '▶️ بازگشت به منوی قبل',
        '◀️ بازگشت به منوی مدیریت',
        'panel',
        '/panel',
        '/cancel',
    ];
    if (in_array($t, array_filter($exact, 'strlen'), true)) {
        return true;
    }
    // Same labels with or without a leading emoji.
    $core = trim((string) preg_replace('/^[^\p{L}\p{N}\/]+/u', '', $t));
    return in_array($core, [
        'بازگشت به منوی قبل',
        'بازگشت به منوی اصلی',
        'بازگشت به منوی مدیریت',
        'بازگشت',
        'لغو',
        'انصراف',
    ], true);
}

function nvpanel_is_panel_action_text(string $text): bool
{
    global $textbotlang;
    $t = trim($text);
    if ($t === '') {
        return false;
    }
    $labels = [
        (string) ($textbotlang['keyboard']['panelFeatureStatus'] ?? ''),
        (string) ($textbotlang['keyboard']['panelName'] ?? ''),
        (string) ($textbotlang['keyboard']['deletePanel'] ?? ''),
        (string) ($textbotlang['keyboard']['editPanelUrl'] ?? ''),
        '🔑 ویرایش کلید API',
        '🗂 دسته پیش‌فرض پنل',
        NV_CATALOG_BTN,
    ];
    if (function_exists('virabot_cat_btn_defcat')) {
        $labels[] = (string) virabot_cat_btn_defcat();
    }
    return in_array($t, array_filter($labels, 'strlen'), true);
}

/** The NV Panel row the admin is working on: the selected panel, else the only nvpanel row. */
function nvpanel_admin_panel(PDO $pdo, string $selected): array
{
    try {
        if ($selected !== '') {
            $st = $pdo->prepare('SELECT name_panel, type, status FROM marzban_panel WHERE name_panel = ? LIMIT 1');
            $st->execute([$selected]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if (($row['type'] ?? '') === 'nvpanel') {
                return $row + ['_selected' => true];
            }
        }
        $st = $pdo->query("SELECT name_panel, type, status FROM marzban_panel WHERE type = 'nvpanel' LIMIT 2");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        if (count($rows) === 1) {
            return $rows[0] + ['_selected' => false];
        }
    } catch (Throwable $e) {
    }
    return [];
}

function nvpanel_product_map_prompt(?PDO $pdo, array $panel): string
{
    $lines = [
        'کد محصول ویرابات و شناسه محصول درگاه را با یک فاصله بفرستید.',
        'مثال:',
        '<code>c716 19</code>',
        'شناسه را خودتان از درگاه می‌آورید. عدد پیش‌فرضی استفاده نمی‌شود.',
    ];
    $name = (string) ($panel['name_panel'] ?? '');
    if ($pdo instanceof PDO && $name !== '') {
        try {
            $st = $pdo->prepare('SELECT code_product, name_product, nv_product_id FROM product WHERE Location = ? ORDER BY name_product LIMIT 40');
            $st->execute([$name]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $lines[] = '';
            $lines[] = 'محصولات پنل «' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '»:';
            if (!$rows) {
                $lines[] = 'محصولی برای این پنل ثبت نشده است.';
            }
            foreach ($rows as $r) {
                $id = trim((string) ($r['nv_product_id'] ?? ''));
                $lines[] = '• <code>' . htmlspecialchars((string) $r['code_product'], ENT_QUOTES, 'UTF-8') . '</code> — '
                    . htmlspecialchars((string) ($r['name_product'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . ' — شناسه درگاه: ' . ($id === '' ? 'ثبت‌نشده' : '<code>' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '</code>');
            }
        } catch (Throwable $e) {
        }
    }
    $lines[] = '';
    $lines[] = 'برای لغو، دکمه بازگشت را بزنید.';
    return implode("\n", $lines);
}

/* ---------------------------------------------------------------- 5.8.3 catalog --- */

const NV_CATALOG_BTN = '📋 محصولات درگاه nvpanel';

/** Full marzban_panel row (url + token) for the admin's NV Panel, or []. */
function nvpanel_admin_full_panel(PDO $pdo, string $selected): array
{
    $p = nvpanel_admin_panel($pdo, $selected);
    if ($p === []) {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT * FROM marzban_panel WHERE name_panel = ? AND type = ? LIMIT 1');
        $st->execute([(string) $p['name_panel'], 'nvpanel']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row + ['_selected' => !empty($p['_selected'])] : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Read-only gateway catalog: GET /products?category=4 (nvpanel-gateway >= 1.3.5).
 * Works while the panel is disabled (no money moves). Never opens configure/checkout.
 * @return array{ok:bool,code:string,products:array,stale:bool,msg:string}
 */
function nvpanel_catalog(array $panel, int $timeout = 20): array
{
    $fail = static function (string $code, string $msg): array {
        return ['ok' => false, 'code' => $code, 'products' => [], 'stale' => false, 'msg' => $msg];
    };
    if (!nvpanel_configured($panel)) {
        return $fail('not_configured', 'نشانی یا توکن پنل nvpanel تنظیم نشده است.');
    }
    $res = nvpanel_request($panel, 'GET', '/products?category=4', null, $timeout);
    $http = (int) $res['status'];
    $body = is_array($res['json']) ? $res['json'] : [];
    $code = (string) ($body['code'] ?? '');
    if ($http === 0) {
        return $fail('timeout', 'درگاه nvpanel پاسخ نداد (قطع ارتباط یا پایان مهلت). کمی بعد دوباره امتحان کنید.');
    }
    if ($http === 401) {
        return $fail('unauthorized', 'درگاه توکن این پنل را نپذیرفت.');
    }
    if ($http === 404 || $http === 405) {
        return $fail('unsupported', 'این نسخهٔ درگاه فهرست محصول ندارد؛ درگاه باید ۱٫۳٫۵ یا بالاتر باشد.');
    }
    if ($http !== 200 || $body === []) {
        return $fail($code !== '' ? $code : 'http_' . $http, 'پاسخ نامعتبر از درگاه (HTTP ' . $http . ').');
    }
    $messages = [
        'catalog_session_required' => 'نشست پنل در درگاه خارج شده است؛ مدیر درگاه باید دوباره وارد پنل شود.',
        'catalog_unparseable' => 'صفحهٔ محصولات پنل خوانده نشد (قالب صفحه عوض شده است).',
        'category_not_verified' => 'این دسته در درگاه تأیید نشده است.',
        'panel_unreachable' => 'درگاه به پنل nvpanel دسترسی ندارد.',
        'panel_unavailable' => 'پنل nvpanel در دسترس نیست.',
        'catalog_upstream_error' => 'خواندن صفحهٔ محصولات از پنل ناموفق بود.',
    ];
    if (empty($body['ok']) || ($body['available'] ?? true) === false || !in_array($code, ['ok', 'catalog_empty', ''], true)) {
        if ($code === 'catalog_empty') {
            return ['ok' => true, 'code' => 'catalog_empty', 'products' => [], 'stale' => false, 'msg' => ''];
        }
        return $fail($code !== '' ? $code : 'error', $messages[$code] ?? ('خواندن فهرست محصولات ناموفق بود (کد: ' . $code . ').'));
    }
    $out = [];
    foreach ((array) ($body['products'] ?? []) as $p) {
        if (!is_array($p)) {
            continue;
        }
        $id = trim((string) ($p['id'] ?? ''));
        if (!nvpanel_product_ok($id) || isset($out[$id])) {
            continue;
        }
        $w = $p['wholesale'] ?? null;
        $wv = is_array($w) ? ($w['value'] ?? null) : null;
        $wholesale = (is_int($wv) || (is_string($wv) && ctype_digit($wv)) || (is_float($wv) && $wv >= 0)) ? (int) $wv : null;
        $dur = is_array($p['duration'] ?? null) ? (string) ($p['duration']['raw'] ?? '') : '';
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($p['name'] ?? '')));
        $out[$id] = [
            'id' => $id,
            'name' => mb_substr($name, 0, 120),
            'wholesale' => $wholesale,
            'duration' => mb_substr($dur, 0, 40),
        ];
    }
    $stale = is_array($body['cache'] ?? null) && !empty($body['cache']['stale']);
    return ['ok' => true, 'code' => $code === '' ? 'ok' : $code, 'products' => array_values($out), 'stale' => $stale, 'msg' => ''];
}

function nvpanel_toman($n): string
{
    return number_format((int) $n) . ' تومان';
}

/** Warning text when the bot selling price is below the gateway wholesale price, else ''. */
function nvpanel_price_warning($price, ?int $wholesale): string
{
    if ($wholesale === null || !is_numeric((string) $price)) {
        return '';
    }
    $price = (int) $price;
    if ($price >= $wholesale) {
        return '';
    }
    return '⚠️ قیمت فروش ربات (' . nvpanel_toman($price) . ') از قیمت عمدهٔ درگاه (' . nvpanel_toman($wholesale) . ') کمتر است؛ هر فروش ' . nvpanel_toman($wholesale - $price) . ' زیان دارد.';
}

function nvpanel_bot_products(PDO $pdo, string $panelName): array
{
    try {
        $st = $pdo->prepare('SELECT id, code_product, name_product, price_product, Location, nv_product_id FROM product WHERE Location = ? ORDER BY name_product LIMIT 40');
        $st->execute([$panelName]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function nvpanel_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** @return array{text:string,kb:?string} */
function nvpanel_catalog_screen(PDO $pdo, array $panel, ?array $cat = null): array
{
    $cat = $cat ?? nvpanel_catalog($panel);
    if (!$cat['ok']) {
        return ['text' => "📋 محصولات درگاه nvpanel\n\n⛔️ " . nvpanel_h($cat['msg']) . "\nنگاشت دستی با «شناسه محصول nvpanel» همچنان کار می‌کند.", 'kb' => json_encode(['inline_keyboard' => [[['text' => '🔄 تلاش دوباره', 'callback_data' => 'nvcat_r']]]], JSON_UNESCAPED_UNICODE)];
    }
    $mine = nvpanel_bot_products($pdo, (string) $panel['name_panel']);
    $lines = ['📋 محصولات درگاه nvpanel (دستهٔ ۴)', ''];
    if ($cat['stale']) {
        $lines[] = '⚠️ تازه‌سازی در درگاه ناموفق بود؛ این فهرست آخرین نسخهٔ ذخیره‌شده است.';
        $lines[] = '';
    }
    if ($cat['products'] === []) {
        $lines[] = 'درگاه محصولی در این دسته نشان نداد.';
    }
    $rows = [];
    foreach ($cat['products'] as $p) {
        $lines[] = '🔹 شناسه <code>' . nvpanel_h($p['id']) . '</code> — ' . nvpanel_h($p['name']);
        $lines[] = '   قیمت عمده: ' . ($p['wholesale'] === null ? 'نامعلوم' : nvpanel_toman($p['wholesale'])) . ($p['duration'] !== '' ? ' — مدت: ' . nvpanel_h($p['duration']) : '');
        foreach ($mine as $m) {
            if (trim((string) $m['nv_product_id']) === $p['id']) {
                $lines[] = '   ↔️ وصل به <code>' . nvpanel_h((string) $m['code_product']) . '</code> (فروش ' . nvpanel_toman($m['price_product']) . ')';
                $warn = nvpanel_price_warning($m['price_product'], $p['wholesale']);
                if ($warn !== '') {
                    $lines[] = '   ' . $warn;
                }
            }
        }
        $rows[] = [['text' => '✅ انتخاب ' . $p['id'] . ' — ' . mb_substr($p['name'], 0, 30), 'callback_data' => 'nvcat_p_' . $p['id']]];
    }
    $ids = array_column($cat['products'], 'id');
    foreach ($mine as $m) {
        $id = trim((string) $m['nv_product_id']);
        if ($id !== '' && !in_array($id, $ids, true)) {
            $lines[] = '⚠️ محصول <code>' . nvpanel_h((string) $m['code_product']) . '</code> به شناسهٔ <code>' . nvpanel_h($id) . '</code> وصل است که در فهرست درگاه نیست.';
        }
    }
    $lines[] = '';
    $lines[] = 'حجم و تعداد اتصال در فهرست درگاه نیست و مدت از عنوان محصول است. قیمت فروش را خودتان تعیین کنید.';
    $lines[] = 'برای وصل‌کردن، یک محصول درگاه را انتخاب کنید.';
    $rows[] = [['text' => '🔄 تازه‌سازی', 'callback_data' => 'nvcat_r']];
    return ['text' => implode("\n", $lines), 'kb' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE)];
}

function nvpanel_catalog_find(array $cat, string $id): ?array
{
    foreach ($cat['products'] as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

function nvpanel_answer_cb(string $t = ''): void
{
    global $callback_query_id;
    if (empty($callback_query_id) || !function_exists('telegram')) {
        return;
    }
    $d = ['callback_query_id' => $callback_query_id];
    if ($t !== '') {
        $d['text'] = $t;
    }
    telegram('answerCallbackQuery', $d);
}

/** Inline callbacks nvcat_*: refresh, pick gateway product, map to a bot product. */
function nvpanel_catalog_callback(PDO $pdo, string $data, string $selected, $from_id, ?string $menu): bool
{
    if (!preg_match('/^nvcat_(r|p_([A-Za-z0-9_-]{1,32})|m_([A-Za-z0-9_-]{1,32})_(\d{1,10}))$/', $data, $m)) {
        return false;
    }
    nvpanel_answer_cb();
    $panel = nvpanel_admin_full_panel($pdo, $selected);
    if ($panel === []) {
        sendmessage($from_id, 'پنل nvpanel پیدا نشد. اول پنل را از «مدیریت پنل» انتخاب کنید.', $menu, 'HTML');
        return true;
    }
    if ($m[1] === 'r') {
        $s = nvpanel_catalog_screen($pdo, $panel);
        sendmessage($from_id, $s['text'], $s['kb'], 'HTML');
        return true;
    }
    $cat = nvpanel_catalog($panel);
    if (!$cat['ok']) {
        sendmessage($from_id, '⛔️ ' . nvpanel_h($cat['msg']) . "\nبدون فهرست تازهٔ درگاه چیزی ذخیره نشد.", $menu, 'HTML');
        return true;
    }
    $nvid = ($m[2] ?? '') !== '' ? $m[2] : $m[3];
    $gp = nvpanel_catalog_find($cat, $nvid);
    if ($gp === null) {
        sendmessage($from_id, 'این شناسه دیگر در فهرست درگاه نیست. فهرست را تازه کنید.', $menu, 'HTML');
        return true;
    }
    $mine = nvpanel_bot_products($pdo, (string) $panel['name_panel']);
    if (($m[2] ?? '') !== '') {
        $lines = [
            'محصول درگاه: <code>' . nvpanel_h($gp['id']) . '</code> — ' . nvpanel_h($gp['name']),
            'قیمت عمده: ' . ($gp['wholesale'] === null ? 'نامعلوم' : nvpanel_toman($gp['wholesale'])),
            '',
        ];
        if ($mine === []) {
            $lines[] = 'هنوز محصولی برای پنل «' . nvpanel_h((string) $panel['name_panel']) . '» در ربات نساخته‌اید. اول محصول را بسازید.';
            sendmessage($from_id, implode("\n", $lines), $menu, 'HTML');
            return true;
        }
        $lines[] = 'کدام محصول ربات به این شناسه وصل شود؟';
        $rows = [];
        foreach ($mine as $b) {
            $warn = nvpanel_price_warning($b['price_product'], $gp['wholesale']);
            $cur = trim((string) $b['nv_product_id']);
            $lines[] = '• <code>' . nvpanel_h((string) $b['code_product']) . '</code> — ' . nvpanel_h((string) $b['name_product'])
                . ' — فروش ' . nvpanel_toman($b['price_product']) . ' — شناسهٔ فعلی: ' . ($cur === '' ? 'ثبت‌نشده' : nvpanel_h($cur))
                . ($warn !== '' ? "\n  " . $warn : '');
            $rows[] = [['text' => ($warn !== '' ? '⚠️ ' : '') . (string) $b['code_product'] . ' — ' . mb_substr((string) $b['name_product'], 0, 28), 'callback_data' => 'nvcat_m_' . $gp['id'] . '_' . (int) $b['id']]];
        }
        $rows[] = [['text' => '🔙 فهرست درگاه', 'callback_data' => 'nvcat_r']];
        sendmessage($from_id, implode("\n", $lines), json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE), 'HTML');
        return true;
    }
    $row = null;
    foreach ($mine as $b) {
        if ((int) $b['id'] === (int) $m[4]) {
            $row = $b;
        }
    }
    if ($row === null || !nvpanel_may_map_product($row, $panel)) {
        sendmessage($from_id, 'فقط محصولی که مال همین پنل nvpanel است وصل می‌شود.', $menu, 'HTML');
        return true;
    }
    $upd = $pdo->prepare('UPDATE product SET nv_product_id = ? WHERE id = ? AND Location = ?');
    $upd->execute([$gp['id'], (int) $row['id'], (string) $panel['name_panel']]);
    $old = trim((string) $row['nv_product_id']);
    $msg = '✅ محصول <code>' . nvpanel_h((string) $row['code_product']) . '</code> به شناسهٔ <code>' . nvpanel_h($gp['id']) . '</code> درگاه (' . nvpanel_h($gp['name']) . ') وصل شد.'
        . ($old !== '' && $old !== $gp['id'] ? "\nشناسهٔ قبلی: <code>" . nvpanel_h($old) . '</code>' : '');
    $warn = nvpanel_price_warning($row['price_product'], $gp['wholesale']);
    if ($warn !== '') {
        $msg .= "\n\n" . $warn . "\nقیمت محصول را از «مدیریت محصول» اصلاح کنید.";
    }
    sendmessage($from_id, $msg, $menu, 'HTML');
    return true;
}

/** After a manual «c716 19» mapping: best-effort catalog check, '' when nothing to add. */
function nvpanel_manual_map_note(PDO $pdo, string $panelName, string $code, string $nvid): string
{
    try {
        $st = $pdo->prepare('SELECT * FROM marzban_panel WHERE name_panel = ? AND type = ? LIMIT 1');
        $st->execute([$panelName, 'nvpanel']);
        $panel = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($panel) || !nvpanel_configured($panel)) {
            return '';
        }
        $cat = nvpanel_catalog($panel, 12);
        if (!$cat['ok']) {
            return '';
        }
        $gp = nvpanel_catalog_find($cat, $nvid);
        if ($gp === null) {
            return '⚠️ شناسهٔ <code>' . nvpanel_h($nvid) . '</code> در فهرست فعلی درگاه نیست.';
        }
        $p = $pdo->prepare('SELECT price_product FROM product WHERE code_product = ? AND Location = ? LIMIT 1');
        $p->execute([$code, $panelName]);
        return nvpanel_price_warning($p->fetchColumn(), $gp['wholesale']);
    } catch (Throwable $e) {
        return '';
    }
}

function nvpanel_admin_handle(): bool
{
    if (!function_exists('sendmessage')) {
        return false;
    }
    global $text, $from_id, $adminrulecheck, $user, $pdo, $option_nvpanel, $keyboardadmin, $datain;
    if (($adminrulecheck['rule'] ?? '') !== 'administrator') {
        return false;
    }
    $hasPdo = isset($pdo) && $pdo instanceof PDO;
    $selected = (string) ($user['Processing_value'] ?? '');
    $menu = isset($option_nvpanel) && is_string($option_nvpanel) ? $option_nvpanel : null;
    $data = (string) ($datain ?? '');
    if ($hasPdo && str_starts_with($data, 'nvcat_')) {
        return nvpanel_catalog_callback($pdo, $data, $selected, $from_id, $menu);
    }
    if (($text ?? '') === NV_CATALOG_BTN) {
        if (($user['step'] ?? '') === 'nvpanel_product_map' && function_exists('step')) {
            step('home', $from_id);
        }
        $panel = $hasPdo ? nvpanel_admin_full_panel($pdo, $selected) : [];
        if ($panel === []) {
            sendmessage($from_id, 'پنل nvpanel پیدا نشد. اول پنل را از «مدیریت پنل» انتخاب کنید.', $menu, 'HTML');
            return true;
        }
        $s = nvpanel_catalog_screen($pdo, $panel);
        sendmessage($from_id, $s['text'], $s['kb'], 'HTML');
        return true;
    }
    if (($text ?? '') === 'شناسه محصول nvpanel') {
        $panel = $hasPdo ? nvpanel_admin_panel($pdo, $selected) : [];
        sendmessage($from_id, nvpanel_product_map_prompt($hasPdo ? $pdo : null, $panel), $menu, 'HTML');
        if (function_exists('step')) {
            step('nvpanel_product_map', $from_id);
        }
        return true;
    }
    if (($user['step'] ?? '') !== 'nvpanel_product_map') {
        return false;
    }
    $input = trim((string) ($text ?? ''));
    if (nvpanel_is_nav_text($input) || nvpanel_is_panel_action_text($input)) {
        if (function_exists('step')) {
            step('home', $from_id);
        }
        $user['step'] = 'home';
        if (nvpanel_is_panel_action_text($input)) {
            // Let admin.php handle the other NV Panel menu button in this same request.
            return false;
        }
        $panel = $hasPdo ? nvpanel_admin_panel($pdo, $selected) : [];
        $toAdmin = in_array(trim((string) preg_replace('/^[^\p{L}\p{N}\/]+/u', '', $input)), ['بازگشت به منوی مدیریت', 'بازگشت به منوی اصلی', 'panel', '/panel'], true);
        $kb = (!$toAdmin && !empty($panel['_selected']) && $menu !== null) ? $menu : ($keyboardadmin ?? $menu);
        sendmessage($from_id, 'ثبت شناسه محصول لغو شد.', $kb, 'HTML');
        return true;
    }
    if (!preg_match('/^(\S+)\s+([A-Za-z0-9_-]{1,32})$/u', $input, $m) || !$hasPdo) {
        sendmessage($from_id, "قالب نامعتبر است. مثال: <code>c716 19</code>\nبرای لغو، دکمه بازگشت را بزنید.", $menu, 'HTML');
        return true;
    }
    $st = $pdo->prepare('SELECT code_product, Location FROM product WHERE code_product = ?');
    $st->execute([$m[1]]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) {
        sendmessage($from_id, 'این کد باید دقیقاً یک محصول متعلق به همین پنل nvpanel باشد.', $menu, 'HTML');
        return true;
    }
    $panel = $pdo->prepare('SELECT name_panel, type, status FROM marzban_panel WHERE name_panel = ? LIMIT 1');
    $panel->execute([$rows[0]['Location']]);
    $panelRow = $panel->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!nvpanel_may_map_product($rows[0], $panelRow)) {
        sendmessage($from_id, 'فقط محصولی که از قبل مال همین پنل nvpanel است تغییر می‌کند. محصول پنل‌های دیگر دست نمی‌خورد.', $menu, 'HTML');
        return true;
    }
    $upd = $pdo->prepare('UPDATE product SET nv_product_id = ? WHERE code_product = ? AND Location = ?');
    $upd->execute([$m[2], $m[1], $rows[0]['Location']]);
    $saved = $upd->rowCount() > 0;
    $note = $saved ? nvpanel_manual_map_note($pdo, (string) $rows[0]['Location'], (string) $m[1], (string) $m[2]) : '';
    sendmessage($from_id, ($saved ? 'شناسه محصول درگاه ذخیره شد.' : 'محصولی با این کد پیدا نشد.') . ($note !== '' ? "\n" . $note : ''), $menu, 'HTML');
    if (function_exists('step')) {
        step('home', $from_id);
    }
    $user['step'] = 'home';
    return true;
}

function nvpanel_message(string $username, string $password, string $statusText, ?string $expire): string
{
    $lines = [
        'سرویس nvpanel ساخته شد.',
        'نام کاربری: <code>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</code>',
    ];
    if ($password !== '') {
        $lines[] = 'رمز: <code>' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</code>';
    }
    $lines[] = 'وضعیت پنل: ' . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8');
    $lines[] = nvpanel_expiry_text($expire, null);
    $lines[] = 'این پنل لینک اشتراک یا فایل کانفیگ جدا نمی‌دهد.';
    return implode("\n", $lines);
}
