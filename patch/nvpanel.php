<?php
/**
 * NV Panel gateway consumer, contract v1.3.1 (silent4time/nvpanel-gateway).
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

function nvpanel_ensure(PDO $pdo): void
{
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
    $inv = $pdo->prepare('SELECT id_invoice, id_user FROM invoice WHERE username = ? ORDER BY id DESC LIMIT 1');
    $inv->execute([$usernameHint]);
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
        $pdo->prepare('UPDATE invoice SET username = ? WHERE id_invoice = ?')->execute([$out['username'], $invoice['id_invoice']]);
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

function nvpanel_admin_handle(): bool
{
    if (!function_exists('sendmessage')) {
        return false;
    }
    global $text, $from_id, $adminrulecheck, $user, $pdo, $option_nvpanel, $keyboardadmin;
    if (($adminrulecheck['rule'] ?? '') !== 'administrator') {
        return false;
    }
    $hasPdo = isset($pdo) && $pdo instanceof PDO;
    $selected = (string) ($user['Processing_value'] ?? '');
    $menu = isset($option_nvpanel) && is_string($option_nvpanel) ? $option_nvpanel : null;
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
    sendmessage($from_id, $upd->rowCount() > 0 ? 'شناسه محصول درگاه ذخیره شد.' : 'محصولی با این کد پیدا نشد.', $menu, 'HTML');
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
