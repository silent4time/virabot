<?php
/**
 * SilentSell — License client
 * Domain-locked subscription. Expiry stops SALES only (bot stays online).
 * Grace: if license server unreachable, use last good cache for up to 96h.
 *
 * API endpoint is obfuscated in binary form (not plain text in config).
 * Admin can register/edit LICENSE_KEY from inside the bot.
 */
if (!defined('MV_LICENSE_CLIENT')) {
    define('MV_LICENSE_CLIENT', true);
}

/** @return list<string> decoded built-in verify URLs, in check order */
function mv_license_api_defaults(): array
{
    // XOR + base64 so the hosts are not plain text in the source
    $blobs = [
        'BQIkAhx2RkxZVV5fRQ8XPlwcJQUGXERCX08KWCMbGylGAkJZHUBEHx82C0E8ARM=',
        'BQIkAhx2RkxZVV5fRQ8XPlwcJQUGXERCX08KWDkAQC0ZCh1GV0RICw9+Agc8',
        'BQIkAhx2RkxRUUBDTwQCfhsdYx4TH1pBWU9CFSVFBGMfBllbU0ZJHx8yC0E8ARMuC0lYV1lWXRsePgkXKA0bH1pBWU9CFSVFBGMfBkBZVE8=',
    ];
    $k = 'mvProLic2026!';
    $kl = strlen($k);
    $out = [];
    foreach ($blobs as $blob) {
        $raw = base64_decode($blob, true);
        if ($raw === false || $raw === '') {
            continue;
        }
        $url = '';
        for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
            $url .= chr(ord($raw[$i]) ^ ord($k[$i % $kl]));
        }
        if ($url !== '') {
            $out[] = $url;
        }
    }
    return $out;
}

/** @return string first built-in verify URL */
function mv_license_api_default(): string
{
    $urls = mv_license_api_defaults();
    return $urls[0] ?? '';
}

function mv_license_cache_path(): string
{
    $dir = defined('BOT_ROOT') ? BOT_ROOT : __DIR__;
    $storage = $dir . '/storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0750, true);
    }
    return $storage . '/license_cache.json';
}

function mv_license_key_from_db(): string
{
    try {
        if (function_exists('select')) {
            $row = select('PaySetting', 'ValuePay', 'NamePay', 'mv_license_key', 'select');
            if (is_array($row) && isset($row['ValuePay']) && trim((string) $row['ValuePay']) !== '') {
                return trim((string) $row['ValuePay']);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return '';
}

/**
 * Persist license key to PaySetting + config.php (best effort)
 */
function mv_license_save_key(string $key): bool
{
    $key = trim($key);
    $ok = false;
    try {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            // ensure row
            $chk = $pdo->prepare("SELECT COUNT(*) FROM PaySetting WHERE NamePay = ?");
            $chk->execute(['mv_license_key']);
            if ((int) $chk->fetchColumn() === 0) {
                $ins = $pdo->prepare("INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?)");
                $ins->execute(['mv_license_key', $key]);
            } else {
                $upd = $pdo->prepare("UPDATE PaySetting SET ValuePay = ? WHERE NamePay = ?");
                $upd->execute([$key, 'mv_license_key']);
            }
            $ok = true;
        }
    } catch (Throwable $e) {
        error_log('mv_license_save_key db: ' . $e->getMessage());
    }

    // sync global + config.php
    $GLOBALS['LICENSE_KEY'] = $key;
    global $LICENSE_KEY;
    $LICENSE_KEY = $key;

    $cfgPath = (defined('BOT_ROOT') ? BOT_ROOT : __DIR__) . '/config.php';
    if (is_file($cfgPath) && is_writable($cfgPath)) {
        $src = (string) file_get_contents($cfgPath);
        $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $key);
        if (preg_match('/\$LICENSE_KEY\s*=\s*[\'"].*?[\'"]\s*;/', $src)) {
            $src = preg_replace(
                '/\$LICENSE_KEY\s*=\s*[\'"].*?[\'"]\s*;/',
                "\$LICENSE_KEY = '{$escaped}';",
                $src,
                1
            );
        } else {
            // append near end before closing
            $src = rtrim($src) . "\n\$LICENSE_KEY = '{$escaped}';\n\$LICENSE_ENABLED = true;\n";
        }
        if (@file_put_contents($cfgPath, $src) !== false) {
            $ok = true;
        }
    }
    // clear caches
    @unlink(mv_license_cache_path());
    return $ok;
}

function mv_license_normalize_key(string $key): string
{
    $key = trim($key);
    if ($key === '' || strpos($key, '{{') !== false || strcasecmp($key, 'PENDING') === 0) {
        return '';
    }
    return $key;
}

function mv_license_config(): array
{
    global $LICENSE_ENABLED, $LICENSE_KEY, $LICENSE_API_URL, $LICENSE_API_URLS, $domainhosts;
    $enabled = true;
    if (isset($LICENSE_ENABLED)) {
        $enabled = (bool) $LICENSE_ENABLED;
    }
    $key = '';
    $dbKey = mv_license_normalize_key(mv_license_key_from_db());
    $cfgKey = isset($LICENSE_KEY) ? mv_license_normalize_key((string) $LICENSE_KEY) : '';
    if ($dbKey !== '') {
        $key = $dbKey;
    } elseif ($cfgKey !== '') {
        $key = $cfgKey;
        // one-time sync config → DB so bot menu sees it
        static $synced = false;
        if (!$synced) {
            $synced = true;
            try {
                global $pdo;
                if (isset($pdo) && $pdo instanceof PDO) {
                    $chk = $pdo->prepare("SELECT ValuePay FROM PaySetting WHERE NamePay = ?");
                    $chk->execute(['mv_license_key']);
                    $row = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$row || trim((string) ($row['ValuePay'] ?? '')) === '') {
                        $pdo->prepare("INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?) ON DUPLICATE KEY UPDATE ValuePay = VALUES(ValuePay)")
                            ->execute(['mv_license_key', $key]);
                    }
                }
            } catch (Throwable $e) {
            }
        }
    }
    // API: only use config if real (not empty / example.com); else obfuscated built-in
    $api = '';
    if (isset($LICENSE_API_URL)) {
        $cand = trim((string) $LICENSE_API_URL);
        if ($cand !== '' && strpos($cand, 'example.com') === false && strpos($cand, '{{') === false) {
            $api = $cand;
        }
    }
    if ($api === '') {
        $api = mv_license_api_default();
    }
    $domain = isset($domainhosts) ? trim((string) $domainhosts) : '';
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = strtolower(rtrim($domain, '/'));
    $apis = [];
    foreach (mv_license_api_defaults() as $u) {
        if ($u !== '' && !in_array($u, $apis, true)) {
            $apis[] = $u;
        }
    }
    if (isset($LICENSE_API_URLS) && is_array($LICENSE_API_URLS)) {
        foreach ($LICENSE_API_URLS as $u) {
            $u = trim((string) $u);
            if ($u !== '' && strpos($u, 'example.com') === false && !in_array($u, $apis, true)) {
                $apis[] = $u;
            }
        }
    }
    if ($api !== '' && !in_array($api, $apis, true)) {
        array_unshift($apis, $api);
    }
    if ($apis === []) {
        $apis = [$api];
    }
    return [
        'enabled' => $enabled,
        'key' => $key,
        'api' => $apis[0],
        'apis' => $apis,
        'domain' => $domain,
        'grace_hours' => 96,
        'check_ttl' => 3600,
    ];
}

function mv_license_read_cache(): ?array
{
    $path = mv_license_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function mv_license_write_cache(array $data): void
{
    $data['cached_at'] = time();
    @file_put_contents(mv_license_cache_path(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function mv_license_http_verify(string $api, string $key, string $domain): ?array
{
    if ($api === '' || $key === '') {
        return null;
    }
    $payload = json_encode(['license_key' => $key, 'domain' => $domain], JSON_UNESCAPED_UNICODE);
    $lastErr = '';
    // تأیید گواهی SSL به‌صورت پیش‌فرض روشن است. فقط اگر در config.php صراحتاً
    // $LICENSE_ALLOW_INSECURE_SSL = true باشد و تأیید گواهی خطا بدهد، یک تلاش بدون تأیید انجام می‌شود.
    global $LICENSE_ALLOW_INSECURE_SSL;
    $allowInsecure = isset($LICENSE_ALLOW_INSECURE_SSL) && $LICENSE_ALLOW_INSECURE_SSL === true;
    if (function_exists('curl_init')) {
        $sslErrnos = [35, 51, 58, 60, 77, 83, 90, 91];
        $body = false;
        $code = 0;
        foreach ([true, false] as $verifyTls) {
            if (!$verifyTls && !$allowInsecure) {
                break;
            }
            $ch = curl_init($api);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'User-Agent: silentsell-license-client/1.1',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            if ($body === false) {
                $lastErr = curl_error($ch);
            }
            curl_close($ch);
            if ($body === false && $verifyTls && in_array($errno, $sslErrnos, true)) {
                error_log('mv_license_http_verify ssl verify failed');
                continue;
            }
            break;
        }
        if ($body === false || $body === '') {
            error_log('mv_license_http_verify curl fail');
            return null;
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            error_log('mv_license_http_verify bad json http=' . $code . ' body=' . substr((string)$body, 0, 200));
            return null;
        }
        $j['_http'] = $code;
        return $j;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => !$allowInsecure,
            'verify_peer_name' => !$allowInsecure,
        ],
    ]);
    $body = @file_get_contents($api, false, $ctx);
    if ($body === false) {
        error_log('mv_license_http_verify stream fail api=' . $api);
        return null;
    }
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

/**
 * @return array{
 *   sales_ok:bool,ok:bool,source:string,code:string,message:string,
 *   expired?:bool,warn?:bool,days_left?:int,expires_at?:string,grace?:bool
 * }
 */
function mv_license_status(bool $force = false): array
{
    static $memo = null;
    if ($memo !== null && !$force) {
        return $memo;
    }

    $cfg = mv_license_config();
    if (!$cfg['enabled']) {
        return $memo = [
            'sales_ok' => true,
            'ok' => true,
            'source' => 'disabled',
            'code' => 'disabled',
            'message' => 'license check disabled',
        ];
    }
    if ($cfg['key'] === '' || $cfg['domain'] === '' || $cfg['domain'] === 'PENDING') {
        return $memo = [
            'sales_ok' => false,
            'ok' => false,
            'source' => 'config',
            'code' => 'not_configured',
            'message' => 'کد فعالسازی ثبت نشده است. از منوی مدیریت → 🔑 کد ویرابات → ثبت / ویرایش کد، کد را وارد کنید.',
        ];
    }

    $cache = mv_license_read_cache();
    $now = time();
    if (!$force && is_array($cache) && !empty($cache['checked_at']) && ($now - (int) $cache['checked_at']) < (int) $cfg['check_ttl']) {
        $cache['source'] = 'cache_ttl';
        return $memo = $cache;
    }

    $remote = null;
    $denied = null;
    foreach (($cfg['apis'] ?? [$cfg['api']]) as $apiUrl) {
        $try = mv_license_http_verify($apiUrl, $cfg['key'], $cfg['domain']);
        if (!is_array($try)) {
            continue;
        }
        $try['server'] = $apiUrl;
        if (!empty($try['ok'])) {
            $remote = $try;
            break;
        }
        $denied = $try;
    }
    if (!is_array($remote) && is_array($denied)) {
        $remote = $denied;
    }
    if (is_array($remote)) {
        $sales_ok = !empty($remote['sales_ok']);
        if (empty($remote['ok']) && in_array(($remote['code'] ?? ''), ['not_found', 'revoked', 'domain_mismatch', 'bad_request'], true)) {
            $sales_ok = false;
        }
        $out = [
            'sales_ok' => $sales_ok,
            'ok' => !empty($remote['ok']),
            'source' => 'remote',
            'code' => (string) ($remote['code'] ?? 'unknown'),
            'message' => (string) ($remote['message'] ?? ''),
            'expired' => !empty($remote['expired']),
            'warn' => !empty($remote['warn']),
            'days_left' => isset($remote['days_left']) ? (int) $remote['days_left'] : null,
            'expires_at' => (string) ($remote['expires_at'] ?? ''),
            'grace' => false,
            'checked_at' => $now,
            'last_ok_at' => $sales_ok || !empty($remote['ok']) ? $now : (int) ($cache['last_ok_at'] ?? 0),
        ];
        if (!empty($remote['ok']) || $sales_ok) {
            $out['last_ok_at'] = $now;
        }
        if (!$out['last_ok_at'] && is_array($cache) && !empty($cache['last_ok_at'])) {
            $out['last_ok_at'] = (int) $cache['last_ok_at'];
        }
        mv_license_write_cache($out);
        if (function_exists('mv_license_maybe_notify_admin')) {
            mv_license_maybe_notify_admin($out);
        }
        return $memo = $out;
    }

    // Server unreachable → grace from last good cache
    $graceHours = (int) $cfg['grace_hours'];
    $lastOk = is_array($cache) ? (int) ($cache['last_ok_at'] ?? 0) : 0;
    if ($lastOk > 0 && ($now - $lastOk) <= ($graceHours * 3600)) {
        $out = is_array($cache) ? $cache : [];
        $out['sales_ok'] = true;
        $out['ok'] = true;
        $out['grace'] = true;
        $out['source'] = 'grace';
        $out['code'] = 'grace';
        $out['message'] = "سرور لایسنس در دسترس نیست؛ مهلت اضطراری فعال است.";
        $out['checked_at'] = $now;
        return $memo = $out;
    }

    return $memo = [
        'sales_ok' => false,
        'ok' => false,
        'source' => 'offline',
        'code' => 'offline',
        'message' => 'سرور لایسنس در دسترس نیست (ارتباط از این سرور برقرار نشد). کلید ثبت شده است؛ ارتباط API را بررسی کنید.',
        'checked_at' => $now,
        'grace' => false,
    ];
}

function mv_license_sales_allowed(): bool
{
    $st = mv_license_status(false);
    return !empty($st['sales_ok']);
}

function mv_license_user_message(): string
{
    $st = mv_license_status(false);
    if (!empty($st['sales_ok'])) {
        return '';
    }
    if (($st['code'] ?? '') === 'not_configured') {
        return "⚠️ فروش موقتاً غیرفعال است.\nکد فعالسازی ویرابات تنظیم نشده است.";
    }
    if (!empty($st['expired']) || ($st['code'] ?? '') === 'expired') {
        return "⚠️ دوره لایسنس به پایان رسیده است.\nفروش متوقف شده؛ سایر بخش‌های ربات فعال است.";
    }
    return "⚠️ فروش موقتاً غیرفعال است.\n" . ($st['message'] ?? '');
}

function mv_license_is_sales_action($text, $datain, $userStep = ''): bool
{
    $t = (string) $text;
    $d = (string) $datain;
    $s = (string) $userStep;
    if ($d === 'buy' || $d === 'buyback' || $d === 'buybacktow' || strpos($d, 'catbuy_') === 0 || strpos($d, 'productmonth_') === 0) {
        return true;
    }
    if ($s === 'statusnamecustom' || strpos($s, 'buy') !== false) {
        return true;
    }
    // Persian sell button text varies; block common paths via callback mainly
    return false;
}

function mv_license_block_sales_if_needed($from_id, $text, $datain, $userStep = ''): bool
{
    try {
        if (is_file(__DIR__ . '/unlimited.flag') || mv_license_sales_allowed()) {
            return false;
        }
        if (!mv_license_is_sales_action($text, $datain, $userStep)) {
            return false;
        }
        $msg = mv_license_user_message();
        if ($msg !== '' && $from_id && function_exists('sendmessage')) {
            sendmessage($from_id, $msg, null, 'HTML');
        }
        return true;
    } catch (Throwable $e) {
        error_log('mv_license_block_sales_if_needed: ' . $e->getMessage());
        return false;
    }
}

function mv_license_maybe_notify_admin(array $st): void
{
    global $adminnumber, $BALE_ADMIN_ID;
    $flagPath = (defined('BOT_ROOT') ? BOT_ROOT : __DIR__) . '/storage/license_notify_flags.json';
    $flags = [];
    if (is_file($flagPath)) {
        $flags = json_decode((string) @file_get_contents($flagPath), true) ?: [];
    }
    $day = gmdate('Y-m-d');
    $msgs = [];
    if (!empty($st['expired']) || ($st['code'] ?? '') === 'expired') {
        $k = 'expired_' . $day;
        if (empty($flags[$k])) {
            $msgs[] = "⚠️ کد فعالسازی ویرابات منقضی شد.\nفروش متوقف شده است. ربات همچنان آنلاین است.\nانقضا: " . ($st['expires_at'] ?? '-');
            $flags[$k] = time();
        }
    } elseif (!empty($st['warn'])) {
        $k = 'warn_' . $day;
        if (empty($flags[$k])) {
            $left = $st['days_left'] ?? '?';
            $msgs[] = "⏳ هشدار لایسنس: حدود {$left} روز تا انقضا باقی مانده است.\nتاریخ: " . ($st['expires_at'] ?? '-');
            $flags[$k] = time();
        }
    }
    if ($msgs) {
        @file_put_contents($flagPath, json_encode($flags, JSON_UNESCAPED_UNICODE));
        foreach ($msgs as $m) {
            if (!empty($adminnumber) && function_exists('sendmessage')) {
                @sendmessage($adminnumber, $m, null, 'HTML');
            }
            if (!empty($BALE_ADMIN_ID) && function_exists('sendmessage')) {
                // same sendmessage routes by platform in this bot
            }
        }
    }
}

/** Human-readable status for admin UI (does not reveal API URL) */
function mv_license_format_status_text(bool $force = true): string
{
    $cfg = mv_license_config();
    $st = mv_license_status($force);
    $keyMask = $cfg['key'] === '' ? '—' : (substr($cfg['key'], 0, 4) . '…' . substr($cfg['key'], -4));
    if (strlen($cfg['key']) < 8) {
        $keyMask = $cfg['key'] === '' ? '—' : '****';
    }
    $lines = [];
    $lines[] = "🔑 <b>وضعیت کد ویرابات</b>";
    $lines[] = "━━━━━━━━━━━━━━━━";
    $lines[] = "دامنه قفل‌شده: <code>" . htmlspecialchars($cfg['domain'] ?: '—') . "</code>";
    $lines[] = "کلید: <code>" . htmlspecialchars($keyMask) . "</code>";
    $code = $st['code'] ?? '';
    if (!empty($st['sales_ok'])) {
        $lines[] = "وضعیت فروش: ✅ فعال";
    } else {
        $lines[] = "وضعیت فروش: ⛔ متوقف";
    }
    if (!empty($st['grace'])) {
        $lines[] = "حالت: ⏳ مهلت اضطراری (سرور لایسنس در دسترس نیست)";
    } elseif ($code === 'not_configured') {
        $lines[] = "حالت: ⚠️ کلید ثبت نشده";
    } elseif (!empty($st['expired']) || $code === 'expired') {
        $lines[] = "حالت: ❌ منقضی";
    } elseif (!empty($st['ok'])) {
        $lines[] = "حالت: ✅ معتبر";
    } else {
        $lines[] = "حالت: ⚠️ " . htmlspecialchars((string) ($st['message'] ?: $code));
    }
    if (!empty($st['expires_at'])) {
        $lines[] = "انقضا: <code>" . htmlspecialchars($st['expires_at']) . "</code>";
    }
    if (isset($st['days_left']) && $st['days_left'] !== null) {
        $lines[] = "روز باقی‌مانده: <b>" . (int) $st['days_left'] . "</b>";
    }
    if (!empty($st['message']) && $code !== 'not_configured') {
        $lines[] = "پیام: " . htmlspecialchars($st['message']);
    }
    $lines[] = "━━━━━━━━━━━━━━━━";
    $lines[] = "برای ثبت یا تغییر کلید، دکمه «ثبت / ویرایش کلید» را بزنید.";
    return implode("\n", $lines);
}

function mv_license_admin_keyboard(): string
{
    return json_encode([
        'keyboard' => [
            [['text' => '🔄 بررسی مجدد لایسنس']],
            [['text' => '✏️ ثبت / ویرایش کلید لایسنس']],
            [['text' => '◀️ بازگشت به منوی مدیریت']],
        ],
        'resize_keyboard' => true,
    ], JSON_UNESCAPED_UNICODE);
}


function mv_trial_user_cap(): int
{
    return 20;
}

function mv_trial_user_count(): int
{
    global $pdo;
    if (!isset($pdo)) {
        return 0;
    }
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM invoice")->fetchColumn();
        return (int) $n;
    } catch (Throwable $e) {
        return 0;
    }
}

function mv_trial_notify_admins(): void
{
    $flagDir = (defined('BOT_ROOT') ? BOT_ROOT : __DIR__) . '/storage';
    if (!is_dir($flagDir)) {
        @mkdir($flagDir, 0750, true);
    }
    $flag = $flagDir . '/trial_limit_notice.txt';
    if (is_file($flag) && (time() - filemtime($flag)) < 21600) {
        return;
    }
    @file_put_contents($flag, date('c'));
    $msg = "⛔ محدودیت نسخه آزمایشی ویرابات\nساخت کاربر، با احتساب تست، به ۲۰ نفر در همه پنل‌ها رسید.\nتا ثبت کد فعالسازی معتبر ویراکد، کاربر جدید ساخته نمی‌شود.\nبرای خرید کد به @silentpingvpn پیام دهید.";
    global $pdo, $adminnumber;
    if (function_exists('sendmessage') && !empty($adminnumber)) {
        sendmessage($adminnumber, $msg, null, 'HTML');
    }
    if (isset($pdo)) {
        try {
            $ids = $pdo->query("SELECT id_admin FROM admin")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                if (function_exists('sendmessage') && (string) $id !== (string) ($adminnumber ?? '')) {
                    sendmessage($id, $msg, null, 'HTML');
                }
            }
            $bale = $pdo->query("SELECT NamePay, ValuePay FROM PaySetting WHERE NamePay IN ('bale_bot_token','bale_admin_id')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $token = $bale['bale_bot_token'] ?? '';
            $bid = $bale['bale_admin_id'] ?? '';
            if ($token !== '' && $bid !== '') {
                $ch = curl_init('https://tapi.bale.ai/bot' . $token . '/sendMessage');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_POSTFIELDS => ['chat_id' => $bid, 'text' => $msg],
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        } catch (Throwable $e) {
            error_log('trial notify: ' . $e->getMessage());
        }
    }
}

function mv_trial_block_create(): ?array
{
    if (is_file(__DIR__ . '/unlimited.flag')) {
        return null;
    }
    if (mv_license_sales_allowed()) {
        return null;
    }
    if (mv_trial_user_count() < mv_trial_user_cap()) {
        return null;
    }
    mv_trial_notify_admins();
    return [
        'status' => 'Unsuccessful',
        'msg' => 'محدودیت نسخه آزمایشی: حداکثر ۲۰ کاربر، با احتساب تست، در همه پنل‌ها. برای ادامه، کد فعالسازی معتبر ویراکد را ثبت کنید.',
    ];
}

