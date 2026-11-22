<?php
/**
 * 5.8.6: personal OpenVPN files for NV Panel accounts.
 *
 * After a successful NV Panel create or renew, the bot builds the .ovpn files of the account's
 * server group with the account's own username/password inline (<auth-user-pass> block) and
 * sends them to the buyer as documents. Same method ViraNet uses (tested by the owner with
 * OpenVPN Connect on a phone).
 *
 * Templates are NOT in this repository. They are imported on the server by the root-only
 * terminal command `sudo virabot nvpanel-ovpn-import` into NV_OVPN_DIR (outside the web root),
 * with the client key already decrypted there. This file never decrypts anything and never logs
 * a username, password or file body.
 *
 * Groups come from ViraNet's real rules (backend/app/login.py, db.py seed):
 *   NV product 19 ("mr")                -> new-1, new-2
 *   WireGuard products (category 5)     -> no file
 *   every other (OpenVPN) product       -> s1..s7 (s7 = one file, UDP 71 first then TCP 70)
 */
declare(strict_types=1);

const NV_OVPN_DIR = '/etc/virabot/ovpn';
const NV_OVPN_MR_PRODUCT = '19';
/** Same list as ViraNet backend/app/login.py WIREGUARD_PRODUCTS. */
const NV_OVPN_WIREGUARD_PRODUCTS = ['59', '61', '66', '67', '68', '69', '73'];
const NV_OVPN_GROUPS = [
    'mr' => ['new-1', 'new-2'],
    's1s7' => ['s1', 's2', 's3', 's4', 's5', 's6', 's7'],
];
const NV_OVPN_SETTING = 'nvpanel_ovpn_files';
const NV_OVPN_MAX_TEMPLATE = 65536;

/** 'mr' | 's1s7' | 'wireguard' | '' (unknown product) */
function nvpanel_ovpn_group(string $productId): string
{
    $id = trim($productId);
    if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
        return '';
    }
    if ($id === NV_OVPN_MR_PRODUCT) {
        return 'mr';
    }
    if (in_array($id, NV_OVPN_WIREGUARD_PRODUCTS, true)) {
        return 'wireguard';
    }
    return 's1s7';
}

/** @return string[] template names to send for this NV product (empty for WireGuard/unknown). */
function nvpanel_ovpn_files_for(string $productId): array
{
    $g = nvpanel_ovpn_group($productId);
    return NV_OVPN_GROUPS[$g] ?? [];
}

/** Username/password that can be written into an inline block without breaking it. */
function nvpanel_ovpn_cred_ok(string $value): bool
{
    if ($value === '' || strlen($value) > 256) {
        return false;
    }
    // CR, LF, NUL and every other control char would end or corrupt the block.
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        return false;
    }
    // A line like "</auth-user-pass>" (or any closing tag) must never appear inside the block.
    if (str_contains($value, '</') || trim($value) !== $value) {
        return false;
    }
    return true;
}

/**
 * Template sanity: exactly one plain `auth-user-pass` line, no inline auth block already,
 * no encrypted key, a <key> block present.
 */
function nvpanel_ovpn_template_ok(string $tpl): bool
{
    if ($tpl === '' || strlen($tpl) > NV_OVPN_MAX_TEMPLATE || str_contains($tpl, "\0")) {
        return false;
    }
    if (preg_match('/ENCRYPTED PRIVATE KEY|Proc-Type:\s*4,ENCRYPTED/', $tpl)) {
        return false;
    }
    if (stripos($tpl, '<auth-user-pass>') !== false || !str_contains($tpl, '<key>')) {
        return false;
    }
    return preg_match_all('/^[ \t]*auth-user-pass[ \t]*\r?$/m', $tpl) === 1
        && preg_match_all('/^[ \t]*auth-user-pass\b/m', $tpl) === 1;
}

/**
 * Personal profile: the plain `auth-user-pass` line replaced by the inline block.
 * Returns null (and builds nothing) when the template or a credential is not safe.
 * `auth-nocache` is never added.
 */
function nvpanel_ovpn_build(string $tpl, string $username, string $password): ?string
{
    if (!nvpanel_ovpn_cred_ok($username) || !nvpanel_ovpn_cred_ok($password) || !nvpanel_ovpn_template_ok($tpl)) {
        return null;
    }
    $tpl = str_replace(["\r\n", "\r"], "\n", $tpl);
    $block = "<auth-user-pass>\n" . $username . "\n" . $password . "\n</auth-user-pass>";
    $out = preg_replace_callback('/^[ \t]*auth-user-pass[ \t]*$/m', static fn () => $block, $tpl, 1, $n);
    if (!is_string($out) || $n !== 1) {
        return null;
    }
    return $out;
}

function nvpanel_ovpn_dir(): string
{
    $o = $GLOBALS['nvpanel_ovpn_dir_override'] ?? null; // offline tests only
    return is_string($o) && $o !== '' ? $o : NV_OVPN_DIR;
}

/** @return array<string,string>|null name => template, null when any file of the group is missing/bad. */
function nvpanel_ovpn_load_group(string $group, ?string $dir = null): ?array
{
    $names = NV_OVPN_GROUPS[$group] ?? null;
    if ($names === null) {
        return null;
    }
    $dir = $dir ?? nvpanel_ovpn_dir();
    $out = [];
    foreach ($names as $name) {
        $f = $dir . '/' . $name . '.ovpn';
        if (!is_file($f) || !is_readable($f) || is_link($f)) {
            return null;
        }
        $tpl = @file_get_contents($f, false, null, 0, NV_OVPN_MAX_TEMPLATE + 1);
        if (!is_string($tpl) || !nvpanel_ovpn_template_ok($tpl)) {
            return null;
        }
        $out[$name] = $tpl;
    }
    return $out;
}

/** @return array<string,bool> group => templates complete */
function nvpanel_ovpn_ready(?string $dir = null): array
{
    $r = [];
    foreach (array_keys(NV_OVPN_GROUPS) as $g) {
        $r[$g] = nvpanel_ovpn_load_group($g, $dir) !== null;
    }
    return $r;
}

function nvpanel_ovpn_enabled(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? (($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null);
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $v = $pdo->query('SELECT ' . NV_OVPN_SETTING . ' FROM setting LIMIT 1');
        return $v !== false && (string) $v->fetchColumn() === '1';
    } catch (Throwable $e) {
        return false; // column not added yet = off
    }
}

function nvpanel_ovpn_set_enabled(PDO $pdo, bool $on): bool
{
    $val = $on ? '1' : '0';
    try {
        $pdo->prepare('UPDATE setting SET ' . NV_OVPN_SETTING . ' = ?')->execute([$val]);
        return true;
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE setting ADD ' . NV_OVPN_SETTING . " VARCHAR(20) NOT NULL DEFAULT '0'");
        $pdo->prepare('UPDATE setting SET ' . NV_OVPN_SETTING . ' = ?')->execute([$val]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function nvpanel_ovpn_ensure(PDO $pdo): void
{
    if (function_exists('nvpanel_is_sqlite') && nvpanel_is_sqlite($pdo)) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS nvpanel_ovpn_sent (idem_key TEXT PRIMARY KEY, result TEXT NOT NULL, sent_at INTEGER NOT NULL)');
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS nvpanel_ovpn_sent (idem_key VARCHAR(128) NOT NULL PRIMARY KEY, result VARCHAR(32) NOT NULL, sent_at INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** True when this operation already got (or is getting) its files. Claims the key atomically. */
function nvpanel_ovpn_claim(PDO $pdo, string $key): bool
{
    nvpanel_ovpn_ensure($pdo);
    try {
        $pdo->prepare('INSERT INTO nvpanel_ovpn_sent (idem_key, result, sent_at) VALUES (?,?,?)')->execute([$key, 'sending', time()]);
        return true;
    } catch (Throwable $e) {
        return false; // duplicate key: already handled
    }
}

function nvpanel_ovpn_mark(PDO $pdo, string $key, string $result): void
{
    try {
        $pdo->prepare('UPDATE nvpanel_ovpn_sent SET result = ?, sent_at = ? WHERE idem_key = ?')->execute([$result, time(), $key]);
    } catch (Throwable $e) {
    }
}

function nvpanel_ovpn_user_text(): string
{
    return implode("\n", [
        '📄 فایل‌های اتصال OpenVPN سرویس شما',
        'نام کاربری و رمز داخل خود فایل است و هنگام اتصال رمز پرسیده نمی‌شود. یکی از فایل‌ها را در برنامه وارد و وصل کنید.',
        '',
        '⚠️ این فایل‌ها شخصی و فقط مخصوص حساب شماست. آن‌ها را برای هیچ‌کس نفرستید و جایی منتشر نکنید.',
        '',
        'برنامه‌های سازگار: OpenVPN Connect، OpenVPN for Android، و OpenVPN نسخهٔ ۲٫۶ به بالا.',
        'نسخهٔ ۲٫۵ و قدیمی‌تر و openvpn3 لینوکس رمز داخل فایل را نمی‌خوانند.',
    ]);
}

/** Safe file name: letters, digits, dash, underscore only. */
function nvpanel_ovpn_filename(string $username, string $name): string
{
    $u = preg_replace('/[^A-Za-z0-9_-]/', '', $username) ?? '';
    $u = substr($u, 0, 40);
    return ($u !== '' ? $u . '-' : '') . $name . '.ovpn';
}

/**
 * Upload one profile from memory (CURLStringFile, PHP 8.1+) or from a 0600 temp file in the
 * system temp dir that is deleted right after the request. Never under the web root.
 */
function nvpanel_ovpn_send_doc($chatId, string $fileName, string $body): bool
{
    $sender = $GLOBALS['nvpanel_ovpn_sender'] ?? null; // offline tests only
    if (is_callable($sender)) {
        return (bool) $sender($chatId, $fileName, $body);
    }
    if (!function_exists('telegram')) {
        return false;
    }
    $tmp = null;
    try {
        if (class_exists('CURLStringFile')) {
            $doc = new CURLStringFile($body, $fileName, 'application/x-openvpn-profile');
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'vbovpn_');
            if ($tmp === false) {
                return false;
            }
            @chmod($tmp, 0600);
            if (file_put_contents($tmp, $body) !== strlen($body)) {
                return false;
            }
            $doc = new CURLFile($tmp, 'application/x-openvpn-profile', $fileName);
        }
        $res = telegram('sendDocument', ['chat_id' => $chatId, 'document' => $doc]);
        return is_array($res) && !empty($res['ok']);
    } catch (Throwable $e) {
        return false;
    } finally {
        if ($tmp !== null && is_file($tmp)) {
            @unlink($tmp);
        }
        unset($body);
    }
}

function nvpanel_ovpn_notify_admins(PDO $pdo, string $text): void
{
    $notify = $GLOBALS['nvpanel_ovpn_admin_notify'] ?? null; // offline tests only
    if (is_callable($notify)) {
        $notify($text);
        return;
    }
    if (!function_exists('sendmessage')) {
        return;
    }
    try {
        $admins = $pdo->query("SELECT id_admin FROM admin WHERE rule = 'administrator'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $a) {
            sendmessage($a, $text, null, 'HTML');
        }
    } catch (Throwable $e) {
    }
}

/**
 * Product id and password of the account an operation belongs to, from the create op of the
 * same invoice (the renew response has no password and renew keeps the service's own product).
 * @return array{product:string,password:string}
 */
function nvpanel_ovpn_account(PDO $pdo, string $invoiceId, string $username): array
{
    $out = ['product' => '', 'password' => ''];
    try {
        $st = $pdo->prepare("SELECT request_json, secret_pass FROM nvpanel_ops WHERE invoice_id = ? AND action = 'create' AND state = 'success' AND username = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$invoiceId, $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $req = json_decode((string) $row['request_json'], true);
            $out['product'] = is_array($req) ? trim((string) ($req['product'] ?? '')) : '';
            $out['password'] = (string) ($row['secret_pass'] ?? '');
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * Build and send the group's personal files once per NV Panel operation.
 * $action is 'create' or 'renew'. Returns a short status code (no secrets), for logs/tests:
 * off | dup | no_customer | unknown_product | wireguard | no_templates | bad_credentials | sent | partial
 */
function nvpanel_ovpn_deliver(PDO $pdo, string $opKey, string $action, $customerId, string $invoiceId, string $username, string $password = '', string $productId = ''): string
{
    if (!nvpanel_ovpn_enabled($pdo)) {
        return 'off';
    }
    if ((string) $customerId === '' || (int) $customerId === 0) {
        return 'no_customer';
    }
    if ($productId === '' || $password === '') {
        $acc = nvpanel_ovpn_account($pdo, $invoiceId, $username);
        $productId = $productId !== '' ? $productId : $acc['product'];
        $password = $password !== '' ? $password : $acc['password'];
    }
    $group = nvpanel_ovpn_group($productId);
    if ($group === 'wireguard') {
        return 'wireguard';
    }
    $ref = 'فاکتور ' . htmlspecialchars($invoiceId, ENT_QUOTES, 'UTF-8') . ' (' . ($action === 'renew' ? 'تمدید' : 'خرید') . ')';
    if ($group === '') {
        error_log('nvpanel ovpn: unknown product for invoice ' . $invoiceId);
        return 'unknown_product';
    }
    $tpls = nvpanel_ovpn_load_group($group);
    if ($tpls === null) {
        error_log('nvpanel ovpn: templates missing for group ' . $group);
        return 'no_templates';
    }
    if (!nvpanel_ovpn_claim($pdo, $opKey)) {
        return 'dup';
    }
    if (!nvpanel_ovpn_cred_ok($username) || !nvpanel_ovpn_cred_ok($password)) {
        nvpanel_ovpn_mark($pdo, $opKey, 'bad_credentials');
        error_log('nvpanel ovpn: credentials not usable in a file for invoice ' . $invoiceId);
        nvpanel_ovpn_notify_admins($pdo, "⚠️ فایل OpenVPN برای {$ref} ساخته نشد: نام کاربری یا رمز خالی است یا نویسهٔ نامجاز (مثل خط تازه) دارد.\nسرویس ساخته شده است؛ فقط فایل فرستاده نشد.");
        return 'bad_credentials';
    }
    $built = [];
    foreach ($tpls as $name => $tpl) {
        $b = nvpanel_ovpn_build($tpl, $username, $password);
        if ($b === null) {
            nvpanel_ovpn_mark($pdo, $opKey, 'bad_template');
            error_log('nvpanel ovpn: template ' . $name . ' rejected');
            nvpanel_ovpn_notify_admins($pdo, "⚠️ فایل OpenVPN برای {$ref} ساخته نشد: قالب {$name} معتبر نیست. دوباره با <code>sudo virabot nvpanel-ovpn-import</code> وارد کنید.");
            return 'bad_template';
        }
        $built[$name] = $b;
    }
    unset($tpls);
    if (function_exists('sendmessage')) {
        sendmessage($customerId, nvpanel_ovpn_user_text(), null, 'HTML');
    }
    $ok = 0;
    foreach ($built as $name => $body) {
        if (nvpanel_ovpn_send_doc($customerId, nvpanel_ovpn_filename($username, $name), $body)) {
            $ok++;
        }
    }
    $built = [];
    $result = $ok === count(NV_OVPN_GROUPS[$group]) ? 'sent' : 'partial';
    nvpanel_ovpn_mark($pdo, $opKey, $result);
    if ($result !== 'sent') {
        error_log('nvpanel ovpn: sent ' . $ok . ' of ' . count(NV_OVPN_GROUPS[$group]) . ' files for invoice ' . $invoiceId);
        nvpanel_ovpn_notify_admins($pdo, "⚠️ ارسال فایل OpenVPN برای {$ref} کامل نشد ({$ok} از " . count(NV_OVPN_GROUPS[$group]) . ').');
    }
    return $result;
}

/**
 * Queue a delivery to run after the current request has sent its own messages (credentials
 * first, files after). Cron callers use nvpanel_ovpn_deliver() directly.
 */
function nvpanel_ovpn_queue(string $opKey, string $action, $customerId, string $invoiceId, string $username, string $password = '', string $productId = ''): void
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || !nvpanel_ovpn_enabled($pdo)) {
        return;
    }
    if (!isset($GLOBALS['nvpanel_ovpn_queue'])) {
        $GLOBALS['nvpanel_ovpn_queue'] = [];
        register_shutdown_function('nvpanel_ovpn_flush');
    }
    $GLOBALS['nvpanel_ovpn_queue'][$opKey] = [$opKey, $action, $customerId, $invoiceId, $username, $password, $productId];
}

function nvpanel_ovpn_flush(): void
{
    $q = $GLOBALS['nvpanel_ovpn_queue'] ?? [];
    $GLOBALS['nvpanel_ovpn_queue'] = [];
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || $q === []) {
        return;
    }
    if (function_exists('fastcgi_finish_request') && empty($GLOBALS['nvpanel_ovpn_sender'])) {
        @fastcgi_finish_request(); // answer the webhook first; uploads follow
    }
    foreach ($q as $job) {
        try {
            nvpanel_ovpn_deliver($pdo, ...$job);
        } catch (Throwable $e) {
            error_log('nvpanel ovpn: delivery error');
        }
    }
}

/* ------------------------------------------------------------ admin toggle (NV Panel menu) --- */

const NV_OVPN_BTN = '📄 فایل OpenVPN مشتری';

/** @return array{text:string,kb:string} */
function nvpanel_ovpn_admin_screen(PDO $pdo): array
{
    $on = nvpanel_ovpn_enabled($pdo);
    $ready = nvpanel_ovpn_ready();
    $lines = [
        '📄 ارسال فایل OpenVPN شخصی به مشتری NV Panel',
        '',
        'وضعیت: ' . ($on ? '✅ روشن' : '⛔️ خاموش'),
        'قالب گروه mr (محصول ۱۹، فایل new-1 و new-2): ' . ($ready['mr'] ? '✅ آماده' : '❌ وارد نشده'),
        'قالب گروه s1 تا s7 (بقیهٔ محصولات OpenVPN): ' . ($ready['s1s7'] ? '✅ آماده' : '❌ وارد نشده'),
        'محصولات WireGuard فایل ندارند.',
        '',
        'بعد از هر خرید و تمدید موفق، فایل‌های گروه همان محصول با نام کاربری و رمز همان مشتری ساخته و برای خودش فرستاده می‌شود.',
        'قالب‌ها فقط با دستور ریشه روی سرور وارد می‌شوند:',
        '<code>sudo virabot nvpanel-ovpn-import</code>',
        'اگر قالب گروهی وارد نشده باشد، برای آن گروه فایلی فرستاده نمی‌شود.',
    ];
    $kb = json_encode(['inline_keyboard' => [[
        $on ? ['text' => '⛔️ خاموش کردن', 'callback_data' => 'nvovpn_off'] : ['text' => '✅ روشن کردن', 'callback_data' => 'nvovpn_on'],
    ]]], JSON_UNESCAPED_UNICODE);
    return ['text' => implode("\n", $lines), 'kb' => $kb];
}
