<?php
/**
 * Installed-bot side of the update channel.
 * Talks only to the operator's update server. No GitHub token and no GitHub host.
 */
declare(strict_types=1);

require_once __DIR__ . '/update_protocol.php';

function vb_update_button_label(): string
{
    return '🔄 به‌روزرسانی ویرابات';
}

function vb_update_normalize_settings(string $url, string $key): array
{
    $url = trim($url);
    $key = trim($key);
    $ok = vb_update_client_base_ok($url) && vb_update_client_key_ok($key);
    return [
        'enabled' => $ok,
        'url' => $ok ? 'https://' . strtolower((string) parse_url($url, PHP_URL_HOST)) : '',
        'key' => $ok ? $key : '',
    ];
}

function vb_update_settings(): array
{
    global $UPDATE_SERVER_URL, $UPDATE_CLIENT_KEY;
    return vb_update_normalize_settings(
        (string) ($UPDATE_SERVER_URL ?? ''),
        (string) ($UPDATE_CLIENT_KEY ?? '')
    );
}

function vb_update_local_version(string $root): string
{
    $file = $root . '/VERSION';
    if (!is_file($file)) {
        return '';
    }
    return trim((string) file_get_contents($file));
}

function vb_update_storage(string $root): string
{
    $dir = $root . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
    return $dir;
}

function vb_update_client_http(string $method, string $url, array $headers, int $timeout, array $hosts): array
{
    $hook = $GLOBALS['vb_update_transport'] ?? null;
    if (is_callable($hook)) {
        return $hook($method, $url, $headers, $timeout, $hosts);
    }
    return vb_update_http_request($method, $url, $headers, $timeout, $hosts);
}

/**
 * @return array{state:string,local:string,remote:string,message:string,error:string}
 */
function vb_update_availability(string $root, ?int $now = null): array
{
    $now = $now ?? time();
    $local = vb_update_local_version($root);
    $settings = vb_update_settings();
    $base = [
        'state' => 'disabled',
        'local' => $local,
        'remote' => '',
        'message' => 'کانال به‌روزرسانی خاموش است. نشانی سرور و کلید کلاینت در پیکربندی خالی است و این نصب به گیت‌هاب وصل نمی‌شود.',
        'error' => 'disabled',
    ];
    if (!$settings['enabled']) {
        return $base;
    }
    $cacheFile = vb_update_storage($root) . '/update_release_cache.json';
    $cache = null;
    if (is_file($cacheFile)) {
        $decoded = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }
    if (!is_array($cache) || !vb_update_cache_fresh($cache, $now)) {
        $cache = vb_update_fetch_meta($settings, $now);
        @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($cacheFile, 0640);
    }
    if (empty($cache['ok'])) {
        return [
            'state' => 'error',
            'local' => $local,
            'remote' => '',
            'message' => 'بررسی نسخهٔ تازه ممکن نشد. نصب تغییری نکرده است.',
            'error' => (string) ($cache['error'] ?? 'error'),
        ];
    }
    $remote = (string) ($cache['version'] ?? '');
    if (vb_update_semver_newer($remote, $local)) {
        return [
            'state' => 'available',
            'local' => $local,
            'remote' => $remote,
            'message' => "نسخهٔ تازهٔ ویرابات آماده است: {$remote}\nنسخهٔ نصب‌شده: {$local}\nدکمهٔ تأیید، نصب را فقط در صف می‌گذارد. تا اجرای کارگر ریشه، فایل، پایگاه داده و پیکربندی دست نمی‌خورند.",
            'error' => '',
        ];
    }
    return [
        'state' => 'current',
        'local' => $local,
        'remote' => $remote,
        'message' => "نسخهٔ نصب‌شده ({$local}) از نسخهٔ پایدار منتشرشده عقب نیست.",
        'error' => '',
    ];
}

function vb_update_fetch_meta(array $settings, int $now): array
{
    $url = vb_update_join_pinned($settings['url'], '/v1/release', []);
    if ($url === null) {
        return ['ok' => false, 'error' => 'unpinned', 'cached_at' => $now];
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $res = vb_update_client_http('GET', $url, [
        'Authorization' => 'Bearer ' . $settings['key'],
        'Accept' => 'application/json',
        'User-Agent' => 'VirabotUpdateClient/5.8.0',
    ], 20, [$host]);
    if ((int) ($res['status'] ?? 0) !== 200) {
        return ['ok' => false, 'error' => 'server', 'cached_at' => $now];
    }
    $data = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($data) || empty($data['ok'])) {
        return ['ok' => false, 'error' => (string) ($data['error'] ?? 'server'), 'cached_at' => $now];
    }
    $version = (string) ($data['version'] ?? '');
    $sha = strtolower((string) ($data['sha256'] ?? ''));
    if (vb_update_semver_parse($version) === null || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
        return ['ok' => false, 'error' => 'meta', 'cached_at' => $now];
    }
    $dl = is_array($data['download'] ?? null) ? $data['download'] : [];
    return [
        'ok' => true,
        'error' => '',
        'version' => $version,
        'sha256' => $sha,
        'size' => (int) ($data['size'] ?? 0),
        'asset' => VB_UPDATE_ASSET,
        'cached_at' => $now,
        'download' => [
            'path' => '/v1/download',
            'exp' => (int) ($dl['exp'] ?? 0),
            'nonce' => (string) ($dl['nonce'] ?? ''),
            'ver' => (string) ($dl['ver'] ?? ''),
            'sig' => (string) ($dl['sig'] ?? ''),
        ],
    ];
}

/**
 * Download the package described by a fresh metadata document into $destZip.
 *
 * @return array{ok:bool,error:string}
 */
function vb_update_download_package(array $settings, array $meta, string $destZip): array
{
    $dl = is_array($meta['download'] ?? null) ? $meta['download'] : [];
    $query = [
        'exp' => (string) ($dl['exp'] ?? ''),
        'nonce' => (string) ($dl['nonce'] ?? ''),
        'ver' => (string) ($dl['ver'] ?? ''),
        'sig' => (string) ($dl['sig'] ?? ''),
    ];
    if (($query['ver'] ?? '') !== (string) ($meta['version'] ?? '')) {
        return ['ok' => false, 'error' => 'meta'];
    }
    $url = vb_update_join_pinned($settings['url'], '/v1/download', $query);
    if ($url === null) {
        return ['ok' => false, 'error' => 'unpinned'];
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $res = vb_update_client_http('GET', $url, [
        'Authorization' => 'Bearer ' . $settings['key'],
        'Accept' => 'application/octet-stream',
        'User-Agent' => 'VirabotUpdateClient/5.8.0',
    ], 180, [$host]);
    if ((int) ($res['status'] ?? 0) !== 200) {
        return ['ok' => false, 'error' => 'download'];
    }
    $body = (string) ($res['body'] ?? '');
    $sha = hash('sha256', $body);
    if (!hash_equals(strtolower((string) $meta['sha256']), $sha)) {
        return ['ok' => false, 'error' => 'integrity'];
    }
    $headerSha = strtolower((string) ($res['headers']['x-content-sha256'] ?? ''));
    if ($headerSha !== '' && !hash_equals($sha, $headerSha)) {
        return ['ok' => false, 'error' => 'integrity'];
    }
    if (file_put_contents($destZip, $body, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'write'];
    }
    return ['ok' => true, 'error' => ''];
}

function vb_update_temp_dest_ok(string $dest): bool
{
    $real = realpath($dest);
    if ($real === false || !is_dir($real)) {
        return false;
    }
    return str_starts_with($real, '/tmp/');
}

/**
 * @return array{ok:bool,error:string,id?:string}
 */
function vb_update_issue_nonce(string $root, string $adminId, string $version, int $now): array
{
    if (vb_update_semver_parse($version) === null || !preg_match('/^-?[0-9]{1,20}$/', $adminId)) {
        return ['ok' => false, 'error' => 'version'];
    }
    $id = bin2hex(random_bytes(8));
    $dir = vb_update_storage($root) . '/update-nonces';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $rec = ['admin' => $adminId, 'version' => $version, 'exp' => $now + VB_UPDATE_NONCE_TTL, 'used' => false];
    if (file_put_contents($dir . '/' . $id . '.json', json_encode($rec), LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'storage'];
    }
    return ['ok' => true, 'error' => '', 'id' => $id];
}

/**
 * @return array{ok:bool,error:string,version?:string,admin?:string}
 */
function vb_update_consume_nonce(string $root, string $id, int $now): array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    $path = vb_update_storage($root) . '/update-nonces/' . $id . '.json';
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'replay'];
    }
    $fh = fopen($path, 'r+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        return ['ok' => false, 'error' => 'storage'];
    }
    try {
        $rec = json_decode((string) stream_get_contents($fh), true);
        if (!is_array($rec)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (!empty($rec['used'])) {
            return ['ok' => false, 'error' => 'replay'];
        }
        if ((int) ($rec['exp'] ?? 0) < $now) {
            return ['ok' => false, 'error' => 'expired'];
        }
        if (vb_update_semver_parse((string) ($rec['version'] ?? '')) === null) {
            return ['ok' => false, 'error' => 'version'];
        }
        if (!preg_match('/^-?[0-9]{1,20}$/', (string) ($rec['admin'] ?? ''))) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $rec['used'] = true;
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($rec));
        fflush($fh);
        return ['ok' => true, 'error' => '', 'version' => (string) $rec['version'], 'admin' => (string) $rec['admin']];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * @param array{from_id:string,rule:string,in_admin_ids:bool} $admin
 * @return array{ok:bool,error:string}
 */
function vb_update_enqueue(string $root, array $admin, string $version, int $now): array
{
    $nonce = [
        'admin' => (string) $admin['from_id'],
        'version' => $version,
        'exp' => $now + 60,
        'used' => false,
    ];
    $gate = vb_update_action_allowed($admin, $nonce, $now);
    if (empty($gate['ok'])) {
        return $gate;
    }
    $avail = vb_update_availability($root, $now);
    if (($avail['state'] ?? '') !== 'available' || ($avail['remote'] ?? '') !== $version) {
        return ['ok' => false, 'error' => 'version'];
    }
    $dir = vb_update_storage($root) . '/update-queue';
    foreach (['pending', 'running', 'done'] as $sub) {
        if (!is_dir($dir . '/' . $sub)) {
            @mkdir($dir . '/' . $sub, 0750, true);
        }
    }
    $lock = fopen(vb_update_storage($root) . '/update.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ok' => false, 'error' => 'busy'];
    }
    try {
        $running = glob($dir . '/running/*.json') ?: [];
        $pending = glob($dir . '/pending/*.json') ?: [];
        if ($running !== [] || $pending !== []) {
            return ['ok' => false, 'error' => 'busy'];
        }
        $id = bin2hex(random_bytes(16));
        $job = [
            'v' => 1,
            'action' => 'apply_release',
            'id' => $id,
            'version' => $version,
            'admin_id' => (string) $admin['from_id'],
            'created' => $now,
            'expires' => $now + VB_UPDATE_JOB_TTL,
        ];
        $check = vb_update_queue_validate($job, $now);
        if (empty($check['ok'])) {
            return $check;
        }
        file_put_contents($dir . '/pending/' . $id . '.json', json_encode($job), LOCK_EX);
        vb_update_write_status($root, 'queued', $version, 'queued');
        return ['ok' => true, 'error' => ''];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function vb_update_write_status(string $root, string $state, string $version, string $detail): void
{
    $allowed = ['idle', 'queued', 'running', 'applied', 'failed'];
    if (!in_array($state, $allowed, true)) {
        $state = 'failed';
    }
    if (!preg_match('/^[a-z_]{1,40}$/', $detail)) {
        $detail = 'failed';
    }
    $payload = [
        'state' => $state,
        'version' => $version,
        'detail' => $detail,
        'at' => time(),
        'rolled_back' => $state === 'failed',
    ];
    file_put_contents(vb_update_storage($root) . '/update-status.json', json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function vb_update_status_text(string $root): string
{
    $file = vb_update_storage($root) . '/update-status.json';
    if (!is_file($file)) {
        return '';
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return '';
    }
    $map = [
        'queued' => 'درخواست در صف است و هنوز نصب نشده.',
        'running' => 'کارگر ریشه در حال نصب است.',
        'applied' => 'آخرین نصب صف‌شده تمام شده است.',
        'failed' => 'آخرین به‌روزرسانی ناموفق بود و موفقیت ثبت نشده. اگر برگشت انجام شده باشد فایل‌های کد قبلی سر جایشان هستند. پایگاه داده خودکار برنمی‌گردد.',
    ];
    $detail = (string) ($data['detail'] ?? '');
    return $map[$detail] ?? ($map[(string) ($data['state'] ?? '')] ?? '');
}

function vb_update_admin_banner_text(string $root): string
{
    $info = vb_update_availability($root);
    if (($info['state'] ?? '') !== 'available') {
        return '';
    }
    return $info['message'];
}

function vb_update_admin_handle(): bool
{
    if (!function_exists('sendmessage')) {
        return false;
    }
    global $text, $datain, $from_id, $adminrulecheck, $admin_ids, $message_id;
    $label = vb_update_button_label();
    $data = (string) ($datain ?? '');
    $isCallback = $data === 'vbup_ask' || str_starts_with($data, 'vbup_go_');
    if (($text ?? '') !== $label && !$isCallback) {
        return false;
    }
    $root = defined('BOT_ROOT') ? (string) BOT_ROOT : __DIR__;
    $admin = [
        'from_id' => (string) $from_id,
        'rule' => (string) ($adminrulecheck['rule'] ?? ''),
        'in_admin_ids' => is_array($admin_ids ?? null) && in_array($from_id, $admin_ids, false),
    ];
    if (!$admin['in_admin_ids'] || $admin['rule'] !== 'administrator') {
        if ($isCallback && function_exists('telegram')) {
            global $callback_query_id;
            telegram('answerCallbackQuery', [
                'callback_query_id' => $callback_query_id,
                'text' => 'فقط مدیر اصلی می‌تواند به‌روزرسانی را صف کند.',
                'show_alert' => true,
            ]);
        } elseif (($text ?? '') === $label) {
            sendmessage($from_id, 'فقط مدیر اصلی به به‌روزرسانی دسترسی دارد.', null, 'HTML');
        }
        return true;
    }
    $now = time();
    if ($data === 'vbup_ask' || ($text ?? '') === $label) {
        $info = vb_update_availability($root, $now);
        $kb = null;
        if ($info['state'] === 'available') {
            $nonce = vb_update_issue_nonce($root, (string) $from_id, $info['remote'], $now);
            if (!empty($nonce['ok'])) {
                $kb = json_encode(['inline_keyboard' => [[
                    ['text' => 'نصب نسخهٔ ' . $info['remote'], 'callback_data' => 'vbup_go_' . $nonce['id']],
                ]]], JSON_UNESCAPED_UNICODE);
            }
        }
        $extra = vb_update_status_text($root);
        $msg = $info['message'] . ($extra !== '' ? "\n\n" . $extra : '');
        sendmessage($from_id, $msg, $kb, 'HTML');
        return true;
    }
    if (preg_match('/^vbup_go_([a-f0-9]{16})$/', $data, $m)) {
        $used = vb_update_consume_nonce($root, $m[1], $now);
        if (empty($used['ok']) || (string) ($used['admin'] ?? '') !== (string) $from_id) {
            $err = (string) ($used['error'] ?? 'forbidden');
            $textErr = [
                'replay' => 'این دکمه یک‌بار مصرف است و دوباره اجرا نمی‌شود.',
                'expired' => 'مهلت این دکمه تمام شده. وضعیت را دوباره باز کنید.',
                'forbidden' => 'این درخواست برای شما نیست.',
            ][$err] ?? 'درخواست رد شد.';
            if (function_exists('telegram')) {
                global $callback_query_id;
                telegram('answerCallbackQuery', [
                    'callback_query_id' => $callback_query_id,
                    'text' => $textErr,
                    'show_alert' => true,
                ]);
            }
            return true;
        }
        $queued = vb_update_enqueue($root, $admin, (string) $used['version'], $now);
        if (!empty($queued['ok'])) {
            $msg = 'درخواست به‌روزرسانی به نسخهٔ ' . $used['version'] . " در صف قرار گرفت.\n"
                . "نصب وقتی انجام می‌شود که کارگر ریشه اجرا شود:\n"
                . "<code>sudo virabot update-worker</code>\n"
                . 'پرداخت، مالکیت سرویس‌ها و پیکربندی بازنویسی نمی‌شوند.';
        } elseif (($queued['error'] ?? '') === 'busy') {
            $msg = 'یک به‌روزرسانی در صف یا در حال اجراست.';
        } else {
            $msg = 'صف ساخته نشد و هیچ نصبی انجام نشد.';
        }
        if (function_exists('Editmessagetext') && !empty($message_id)) {
            Editmessagetext($from_id, $message_id, $msg, json_encode(['inline_keyboard' => []]), 'HTML');
        } else {
            sendmessage($from_id, $msg, null, 'HTML');
        }
        return true;
    }
    return false;
}

function vb_update_push_notice(string $root): void
{
    if (!function_exists('sendmessage') || !isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        return;
    }
    $info = vb_update_availability($root);
    if (($info['state'] ?? '') !== 'available') {
        return;
    }
    $flagFile = vb_update_storage($root) . '/update_notified.json';
    $flag = is_file($flagFile) ? json_decode((string) file_get_contents($flagFile), true) : null;
    if (is_array($flag) && (string) ($flag['version'] ?? '') === $info['remote']) {
        return;
    }
    $pdo = $GLOBALS['pdo'];
    $rows = $pdo->query("SELECT id_admin FROM admin WHERE rule = 'administrator'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) {
        sendmessage($id, $info['message'], null, 'HTML');
    }
    file_put_contents($flagFile, json_encode(['version' => $info['remote'], 'at' => time()]), LOCK_EX);
}

function vb_update_mark_job(string $root, string $result): int
{
    if ($result !== 'applied' && $result !== 'failed') {
        return 1;
    }
    $running = glob(vb_update_storage($root) . '/update-queue/running/*.json') ?: [];
    if ($running === []) {
        return 1;
    }
    $path = $running[0];
    $job = json_decode((string) file_get_contents($path), true);
    if (!is_array($job)) {
        vb_update_write_status($root, 'failed', '', 'schema');
        return 1;
    }
    $version = (string) ($job['version'] ?? '');
    if ($result === 'applied') {
        $installed = vb_update_local_version($root);
        if ($installed !== $version || vb_update_semver_parse($installed) === null) {
            $result = 'failed';
        }
    }
    $done = vb_update_storage($root) . '/update-queue/done/' . basename($path);
    @rename($path, $done);
    vb_update_write_status($root, $result === 'applied' ? 'applied' : 'failed', $version, $result === 'applied' ? 'applied' : 'failed');
    return $result === 'applied' ? 0 : 1;
}

function vb_update_prepare_job(string $root, string $dest, int $now): int
{
    if (!vb_update_temp_dest_ok($dest)) {
        return 1;
    }
    $settings = vb_update_settings();
    if (!$settings['enabled']) {
        vb_update_write_status($root, 'failed', '', 'disabled');
        return 4;
    }
    $queue = vb_update_storage($root) . '/update-queue';
    $running = glob($queue . '/running/*.json') ?: [];
    if ($running !== []) {
        $age = $now - (int) @filemtime($running[0]);
        if ($age < 7200) {
            return 2;
        }
        vb_update_mark_job($root, 'failed');
    }
    $pending = glob($queue . '/pending/*.json') ?: [];
    if ($pending === []) {
        return 2;
    }
    usort($pending, static function (string $a, string $b): int {
        $ja = json_decode((string) file_get_contents($a), true);
        $jb = json_decode((string) file_get_contents($b), true);
        return ((int) ($ja['created'] ?? 0)) <=> ((int) ($jb['created'] ?? 0));
    });
    $jobPath = $pending[0];
    $job = json_decode((string) file_get_contents($jobPath), true);
    if (!is_array($job) || empty(vb_update_queue_validate($job, $now)['ok'])) {
        @unlink($jobPath);
        vb_update_write_status($root, 'failed', '', 'schema');
        return 3;
    }
    $runningPath = $queue . '/running/' . basename($jobPath);
    if (!@rename($jobPath, $runningPath)) {
        return 1;
    }
    vb_update_write_status($root, 'running', (string) $job['version'], 'running');
    $meta = vb_update_fetch_meta($settings, $now);
    if (empty($meta['ok']) || (string) ($meta['version'] ?? '') !== (string) $job['version']) {
        vb_update_mark_job($root, 'failed');
        return 3;
    }
    $zip = $dest . '/package.zip';
    $got = vb_update_download_package($settings, $meta, $zip);
    if (empty($got['ok'])) {
        vb_update_mark_job($root, 'failed');
        return 3;
    }
    $extract = vb_update_extract_zip($zip, $dest . '/pkg');
    @unlink($zip);
    if (empty($extract['ok'])) {
        vb_update_mark_job($root, 'failed');
        return 3;
    }
    $pkg = vb_update_find_package($dest . '/pkg');
    if ($pkg === null) {
        vb_update_mark_job($root, 'failed');
        return 3;
    }
    vb_update_sanitize_package($pkg);
    $check = vb_update_package_ok($pkg);
    if (empty($check['ok']) || vb_update_local_version($pkg) !== (string) $job['version']) {
        vb_update_mark_job($root, 'failed');
        return 3;
    }
    $live = $dest . '/tree';
    if (is_dir($live)) {
        vb_update_rrmdir($live);
    }
    if (!@rename($pkg, $live)) {
        vb_update_mark_job($root, 'failed');
        return 3;
    }
    return 0;
}

function vb_update_find_package(string $root): ?string
{
    if (is_file($root . '/patch/botapi.php') && is_file($root . '/manage.sh')) {
        return $root;
    }
    $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        if (is_file($dir . '/patch/botapi.php') && is_file($dir . '/manage.sh')) {
            return $dir;
        }
    }
    return null;
}

function vb_update_fetch_into(string $root, string $dest, int $now): int
{
    if (!vb_update_temp_dest_ok($dest)) {
        return 1;
    }
    $settings = vb_update_settings();
    if (!$settings['enabled']) {
        return 4;
    }
    $meta = vb_update_fetch_meta($settings, $now);
    if (empty($meta['ok'])) {
        return 3;
    }
    $zip = $dest . '/package.zip';
    $got = vb_update_download_package($settings, $meta, $zip);
    if (empty($got['ok'])) {
        return 3;
    }
    $extract = vb_update_extract_zip($zip, $dest . '/pkg');
    @unlink($zip);
    if (empty($extract['ok'])) {
        return 3;
    }
    $pkg = vb_update_find_package($dest . '/pkg');
    if ($pkg === null) {
        return 3;
    }
    vb_update_sanitize_package($pkg);
    if (empty(vb_update_package_ok($pkg)['ok'])) {
        return 3;
    }
    if ($pkg !== $dest . '/tree') {
        @rename($pkg, $dest . '/tree');
    }
    return is_file($dest . '/tree/patch/botapi.php') ? 0 : 3;
}

function vb_update_read_config_vars(string $root): array
{
    $file = $root . '/config.php';
    if (!is_file($file)) {
        return [];
    }
    $load = static function (string $path): array {
        require $path;
        return [
            'url' => isset($UPDATE_SERVER_URL) ? (string) $UPDATE_SERVER_URL : '',
            'key' => isset($UPDATE_CLIENT_KEY) ? (string) $UPDATE_CLIENT_KEY : '',
            'dbhost' => isset($dbhost) ? (string) $dbhost : '',
            'dbname' => isset($dbname) ? (string) $dbname : '',
            'dbuser' => isset($usernamedb) ? (string) $usernamedb : '',
            'dbpass' => isset($passworddb) ? (string) $passworddb : '',
        ];
    };
    return $load($file);
}

function vb_update_cli(array $argv): void
{
    $cmd = $argv[1] ?? '';
    $root = __DIR__;
    if (is_file($root . '/config.php') && $cmd !== 'extract-zip') {
        $vars = vb_update_read_config_vars($root);
        $GLOBALS['UPDATE_SERVER_URL'] = $vars['url'] ?? '';
        $GLOBALS['UPDATE_CLIENT_KEY'] = $vars['key'] ?? '';
    }
    if ($cmd === 'fetch-into') {
        $dest = $argv[2] ?? '';
        exit(vb_update_fetch_into($root, $dest, time()));
    }
    if ($cmd === 'fetch-job') {
        $dest = '';
        foreach ($argv as $i => $arg) {
            if ($arg === '--dest') {
                $dest = (string) ($argv[$i + 1] ?? '');
            }
        }
        exit(vb_update_prepare_job($root, $dest, time()));
    }
    if ($cmd === 'mark-job') {
        $result = $argv[2] ?? '';
        exit(vb_update_mark_job($root, $result));
    }
    if ($cmd === 'extract-zip') {
        $zip = $argv[2] ?? '';
        $dest = $argv[3] ?? '';
        if (!vb_update_temp_dest_ok($dest) || !is_file($zip)) {
            exit(1);
        }
        $res = vb_update_extract_zip($zip, $dest . '/out');
        if (empty($res['ok'])) {
            exit(1);
        }
        $pkg = vb_update_find_package($dest . '/out');
        if ($pkg === null) {
            exit(1);
        }
        vb_update_sanitize_package($pkg);
        if (empty(vb_update_package_ok($pkg)['ok'])) {
            exit(1);
        }
        if ($pkg !== $dest . '/tree') {
            @rename($pkg, $dest . '/tree');
        }
        exit(is_file($dest . '/tree/patch/botapi.php') ? 0 : 1);
    }
    if ($cmd === 'write-db-cnf') {
        $path = $argv[2] ?? '';
        $realParent = realpath(dirname($path));
        $okParent = $realParent !== false && (str_starts_with($realParent, '/tmp/') || str_starts_with($realParent, '/root/virabot-backups'));
        if (!$okParent) {
            exit(1);
        }
        $vars = vb_update_read_config_vars($root);
        $dbname = (string) ($vars['dbname'] ?? '');
        $dbuser = (string) ($vars['dbuser'] ?? '');
        $dbpass = (string) ($vars['dbpass'] ?? '');
        $dbhost = (string) ($vars['dbhost'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbname) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbuser)) {
            exit(1);
        }
        if (str_contains($dbpass, "\n") || str_contains($dbpass, "\0")) {
            exit(1);
        }
        $esc = static function (string $v): string {
            return str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
        };
        $cnf = "[client]\nhost=\"" . $esc($dbhost) . "\"\nuser=\"" . $esc($dbuser) . "\"\npassword=\"" . $esc($dbpass) . "\"\n";
        if (file_put_contents($path, $cnf, LOCK_EX) === false) {
            exit(1);
        }
        @chmod($path, 0600);
        file_put_contents($realParent . '/dbname.txt', $dbname, LOCK_EX);
        @chmod($realParent . '/dbname.txt', 0600);
        exit(0);
    }
    fwrite(STDERR, "unknown\n");
    exit(1);
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    vb_update_cli($argv);
}
