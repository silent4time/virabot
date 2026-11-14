<?php
/**
 * Public GitHub release updater for an installed Virabot.
 * Unauthenticated. No token is read, sent, or stored.
 */
declare(strict_types=1);

require_once __DIR__ . '/update_protocol.php';

function vb_update_button_label(): string
{
    return '🔄 به‌روزرسانی ویرابات';
}

function vb_update_local_version(string $root): string
{
    $file = $root . '/VERSION';
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

function vb_update_storage(string $root): string
{
    $dir = $root . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

function vb_update_http(string $method, string $url, array $headers, int $timeout, array $hosts): array
{
    $hook = $GLOBALS['vb_update_transport'] ?? null;
    if (is_callable($hook)) {
        return $hook($method, $url, $headers, $timeout, $hosts);
    }
    return vb_update_http_request($method, $url, $headers, $timeout, $hosts);
}

function vb_update_rate_blocked(string $root, int $now): bool
{
    $file = vb_update_storage($root) . '/github_rate.json';
    if (!is_file($file)) {
        return false;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) && (int) ($data['until'] ?? 0) > $now;
}

function vb_update_note_rate(string $root, array $headers, int $now): void
{
    $remain = $headers['x-ratelimit-remaining'] ?? null;
    if ($remain === null || (int) $remain > 1) {
        return;
    }
    $reset = (int) ($headers['x-ratelimit-reset'] ?? ($now + VB_UPDATE_CACHE_ERR));
    file_put_contents(vb_update_storage($root) . '/github_rate.json', json_encode(['until' => max($reset, $now + 60)]), LOCK_EX);
}

/** @return array{ok:bool,error:string,version?:string,sha256?:string,cached_at?:int} */
function vb_update_fetch_release(string $root, int $now): array
{
    if (vb_update_rate_blocked($root, $now)) {
        return ['ok' => false, 'error' => 'rate_limit', 'cached_at' => $now];
    }
    $headers = [
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'VirabotUpdate/5.8.4',
        'X-GitHub-Api-Version' => '2022-11-28',
    ];
    $listUrl = 'https://api.github.com/repos/' . VB_UPDATE_REPO . '/releases?per_page=20';
    $list = vb_update_http('GET', $listUrl, $headers, 20, ['api.github.com']);
    vb_update_note_rate($root, $list['headers'] ?? [], $now);
    if ((int) ($list['status'] ?? 0) === 403 || (int) ($list['status'] ?? 0) === 429) {
        return ['ok' => false, 'error' => 'rate_limit', 'cached_at' => $now];
    }
    if ((int) ($list['status'] ?? 0) !== 200) {
        return ['ok' => false, 'error' => 'github_list', 'cached_at' => $now];
    }
    $decoded = json_decode((string) ($list['body'] ?? ''), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'github_list', 'cached_at' => $now];
    }
    $picked = vb_update_pick_release($decoded);
    if (empty($picked['ok'])) {
        return ['ok' => false, 'error' => (string) $picked['error'], 'cached_at' => $now];
    }
    $sumUrl = vb_update_asset_api_url((int) $picked['sum_id']);
    $zipUrl = vb_update_asset_api_url((int) $picked['zip_id']);
    if ($sumUrl === null || $zipUrl === null) {
        return ['ok' => false, 'error' => 'unpinned', 'cached_at' => $now];
    }
    $sum = vb_update_download_asset($root, $sumUrl, $now);
    if (empty($sum['ok'])) {
        return ['ok' => false, 'error' => (string) ($sum['error'] ?? 'checksum_unavailable'), 'cached_at' => $now];
    }
    $expect = vb_update_parse_checksum((string) $sum['body']);
    if ($expect === null) {
        return ['ok' => false, 'error' => 'checksum_unavailable', 'cached_at' => $now];
    }
    $zip = vb_update_download_asset($root, $zipUrl, $now);
    if (empty($zip['ok'])) {
        return ['ok' => false, 'error' => (string) ($zip['error'] ?? 'download'), 'cached_at' => $now];
    }
    $actual = hash('sha256', (string) $zip['body']);
    if (!hash_equals($expect, $actual)) {
        return ['ok' => false, 'error' => 'integrity', 'cached_at' => $now];
    }
    $cache = [
        'ok' => true,
        'error' => '',
        'version' => (string) $picked['version'],
        'sha256' => $expect,
        'cached_at' => $now,
        'checksum_kind' => 'unsigned-sha256',
    ];
    $bin = vb_update_storage($root) . '/update-package.zip';
    file_put_contents($bin, (string) $zip['body'], LOCK_EX);
    @chmod($bin, 0640);
    return $cache;
}

/** @return array{ok:bool,body?:string,error?:string} */
function vb_update_download_asset(string $root, string $apiUrl, int $now): array
{
    $headers = [
        'Accept' => 'application/octet-stream',
        'User-Agent' => 'VirabotUpdate/5.8.4',
    ];
    $first = vb_update_http('GET', $apiUrl, $headers, 30, ['api.github.com']);
    vb_update_note_rate($root, $first['headers'] ?? [], $now);
    $status = (int) ($first['status'] ?? 0);
    if ($status >= 300 && $status < 400) {
        $location = (string) ($first['headers']['location'] ?? '');
        if (!vb_update_url_pinned($location, vb_update_redirect_hosts())) {
            return ['ok' => false, 'error' => 'redirect_rejected'];
        }
        $host = strtolower((string) parse_url($location, PHP_URL_HOST));
        $second = vb_update_http('GET', $location, [
            'Accept' => 'application/octet-stream',
            'User-Agent' => 'VirabotUpdate/5.8.4',
        ], 180, [$host]);
        if ((int) ($second['status'] ?? 0) !== 200 || (string) ($second['body'] ?? '') === '') {
            return ['ok' => false, 'error' => 'download'];
        }
        return ['ok' => true, 'body' => (string) $second['body']];
    }
    if ($status !== 200 || (string) ($first['body'] ?? '') === '') {
        return ['ok' => false, 'error' => 'download'];
    }
    return ['ok' => true, 'body' => (string) $first['body']];
}

/** @return array{state:string,local:string,remote:string,message:string,error:string} */
function vb_update_availability(string $root, ?int $now = null): array
{
    $now = $now ?? time();
    $local = vb_update_local_version($root);
    $cacheFile = vb_update_storage($root) . '/update_release_cache.json';
    $cache = null;
    if (is_file($cacheFile)) {
        $decoded = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }
    if (!is_array($cache) || !vb_update_cache_fresh($cache, $now)) {
        $cache = vb_update_fetch_release($root, $now);
        @file_put_contents($cacheFile, json_encode([
            'ok' => !empty($cache['ok']),
            'error' => (string) ($cache['error'] ?? ''),
            'version' => (string) ($cache['version'] ?? ''),
            'sha256' => (string) ($cache['sha256'] ?? ''),
            'cached_at' => $now,
            'checksum_kind' => 'unsigned-sha256',
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    if (empty($cache['ok'])) {
        return [
            'state' => 'error',
            'local' => $local,
            'remote' => '',
            'message' => 'بررسی انتشار پایدار ممکن نشد. نصب انجام نشد. فایل جمع‌بررسی اگر نباشد، به‌روزرسانی رد می‌شود.',
            'error' => (string) ($cache['error'] ?? 'error'),
        ];
    }
    $remote = (string) ($cache['version'] ?? '');
    if (vb_update_semver_newer($remote, $local)) {
        return [
            'state' => 'available',
            'local' => $local,
            'remote' => $remote,
            'message' => "نسخهٔ تازهٔ ویرابات آماده است: {$remote}\nنسخهٔ نصب‌شده: {$local}\nجمع‌بررسی SHA-256 کنار بسته است و امضای ناشر نیست.\nدکمه فقط درخواست را در صف می‌گذارد.",
            'error' => '',
        ];
    }
    return [
        'state' => 'current',
        'local' => $local,
        'remote' => $remote,
        'message' => "نسخهٔ نصب‌شده ({$local}) از انتشار پایدار عقب نیست.",
        'error' => '',
    ];
}

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
    file_put_contents($dir . '/' . $id . '.json', json_encode($rec), LOCK_EX);
    return ['ok' => true, 'error' => '', 'id' => $id];
}

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
        if (!is_array($rec) || !empty($rec['used'])) {
            return ['ok' => false, 'error' => 'replay'];
        }
        if ((int) ($rec['exp'] ?? 0) < $now) {
            return ['ok' => false, 'error' => 'expired'];
        }
        if (vb_update_semver_parse((string) ($rec['version'] ?? '')) === null) {
            return ['ok' => false, 'error' => 'version'];
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

function vb_update_enqueue(string $root, array $admin, string $version, int $now): array
{
    $gate = vb_update_action_allowed($admin, [
        'admin' => (string) $admin['from_id'],
        'version' => $version,
        'exp' => $now + 60,
        'used' => false,
    ], $now);
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
        vb_update_write_status($root, 'queued', $version, 'queued', false);
        return ['ok' => true, 'error' => ''];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function vb_update_write_status(string $root, string $state, string $version, string $detail, bool $rolledBack): void
{
    if (!in_array($state, ['idle', 'queued', 'running', 'applied', 'failed'], true)) {
        $state = 'failed';
    }
    if (!preg_match('/^[a-z_]{1,40}$/', $detail)) {
        $detail = 'failed';
    }
    file_put_contents(vb_update_storage($root) . '/update-status.json', json_encode([
        'state' => $state,
        'version' => $version,
        'detail' => $detail,
        'at' => time(),
        'rolled_back' => $rolledBack,
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
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
    $state = (string) ($data['state'] ?? '');
    if ($state === 'queued') {
        return 'درخواست در صف است و هنوز نصب نشده.';
    }
    if ($state === 'running') {
        return 'کارگر ریشه در حال نصب است.';
    }
    if ($state === 'applied') {
        return 'آخرین نصب صف‌شده تمام شده است.';
    }
    if ($state === 'failed' && !empty($data['rolled_back'])) {
        return 'آخرین به‌روزرسانی ناموفق بود و فایل‌های کد از آرشیو برگشت. پایگاه داده خودکار برنگشته است.';
    }
    if ($state === 'failed') {
        return 'آخرین به‌روزرسانی ناموفق بود و برگشت کامل تأیید نشد. موفقیت ثبت نشده. آرشیو کد در بکاپ ریشه است.';
    }
    return '';
}

function vb_update_admin_banner_text(string $root): string
{
    $info = vb_update_availability($root);
    return ($info['state'] ?? '') === 'available' ? (string) $info['message'] : '';
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
        sendmessage($from_id, $info['message'] . ($extra !== '' ? "\n\n" . $extra : ''), $kb, 'HTML');
        return true;
    }
    if (preg_match('/^vbup_go_([a-f0-9]{16})$/', $data, $m)) {
        $used = vb_update_consume_nonce($root, $m[1], $now);
        if (empty($used['ok']) || (string) ($used['admin'] ?? '') !== (string) $from_id) {
            $err = (string) ($used['error'] ?? 'forbidden');
            $textErr = [
                'replay' => 'این دکمه یک‌بار مصرف است و دوباره اجرا نمی‌شود.',
                'expired' => 'مهلت این دکمه تمام شده.',
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
            $msg = 'درخواست به‌روزرسانی به نسخهٔ ' . $used['version'] . " در صف قرار گرفت.\nنصب وقتی انجام می‌شود که کارگر ریشه اجرا شود:\n<code>sudo virabot update-worker</code>";
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
    $rows = $GLOBALS['pdo']->query("SELECT id_admin FROM admin WHERE rule = 'administrator'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) {
        sendmessage($id, $info['message'], null, 'HTML');
    }
    file_put_contents($flagFile, json_encode(['version' => $info['remote'], 'at' => time()]), LOCK_EX);
}

function vb_update_mark_job(string $root, string $result, bool $rolledBack = false): int
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
        vb_update_write_status($root, 'failed', '', 'schema', false);
        return 1;
    }
    $version = (string) ($job['version'] ?? '');
    if ($result === 'applied' && vb_update_local_version($root) !== $version) {
        $result = 'failed';
        $rolledBack = false;
    }
    @rename($path, vb_update_storage($root) . '/update-queue/done/' . basename($path));
    vb_update_write_status($root, $result === 'applied' ? 'applied' : 'failed', $version, $result === 'applied' ? 'applied' : 'failed', $result === 'failed' && $rolledBack);
    return $result === 'applied' ? 0 : 1;
}

function vb_update_find_package(string $root): ?string
{
    if (is_file($root . '/patch/botapi.php') && is_file($root . '/manage.sh')) {
        return $root;
    }
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_file($dir . '/patch/botapi.php') && is_file($dir . '/manage.sh')) {
            return $dir;
        }
    }
    return null;
}

function vb_update_temp_dest_ok(string $dest): bool
{
    $real = realpath($dest);
    return $real !== false && is_dir($real) && str_starts_with($real, '/tmp/');
}

function vb_update_materialize(string $root, string $dest, int $now, ?string $expectVersion): int
{
    if (!vb_update_temp_dest_ok($dest)) {
        return 1;
    }
    $info = vb_update_availability($root, $now);
    if (($info['state'] ?? '') === 'error') {
        return 3;
    }
    $cache = json_decode((string) @file_get_contents(vb_update_storage($root) . '/update_release_cache.json'), true);
    if (!is_array($cache) || empty($cache['ok']) || !preg_match('/^[a-f0-9]{64}$/', (string) ($cache['sha256'] ?? ''))) {
        return 3;
    }
    if ($expectVersion !== null && (string) $cache['version'] !== $expectVersion) {
        return 3;
    }
    $zip = vb_update_storage($root) . '/update-package.zip';
    if (!is_file($zip) || !hash_equals((string) $cache['sha256'], hash_file('sha256', $zip) ?: '')) {
        return 3;
    }
    $extract = vb_update_extract_zip($zip, $dest . '/pkg');
    if (empty($extract['ok'])) {
        return 3;
    }
    $pkg = vb_update_find_package($dest . '/pkg');
    if ($pkg === null || empty(vb_update_package_ok($pkg)['ok'])) {
        return 3;
    }
    if (vb_update_local_version($pkg) !== (string) $cache['version']) {
        return 3;
    }
    if ($pkg !== $dest . '/tree' && !@rename($pkg, $dest . '/tree')) {
        return 3;
    }
    return is_file($dest . '/tree/patch/botapi.php') ? 0 : 3;
}

function vb_update_prepare_job(string $root, string $dest, int $now): int
{
    $queue = vb_update_storage($root) . '/update-queue';
    $running = glob($queue . '/running/*.json') ?: [];
    if ($running !== []) {
        if ($now - (int) @filemtime($running[0]) < 7200) {
            return 2;
        }
        vb_update_mark_job($root, 'failed', false);
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
    $job = json_decode((string) file_get_contents($pending[0]), true);
    if (!is_array($job) || empty(vb_update_queue_validate($job, $now)['ok'])) {
        @unlink($pending[0]);
        vb_update_write_status($root, 'failed', '', 'schema', false);
        return 3;
    }
    if (!@rename($pending[0], $queue . '/running/' . basename($pending[0]))) {
        return 1;
    }
    vb_update_write_status($root, 'running', (string) $job['version'], 'running', false);
    $rc = vb_update_materialize($root, $dest, $now, (string) $job['version']);
    if ($rc !== 0) {
        vb_update_mark_job($root, 'failed', false);
    }
    return $rc;
}

function vb_update_cli(array $argv): void
{
    $cmd = $argv[1] ?? '';
    $root = __DIR__;
    if ($cmd === 'fetch-into') {
        $dest = $argv[2] ?? '';
        $root = $argv[3] ?? __DIR__;
        if (!is_dir($root)) {
            exit(1);
        }
        exit(vb_update_materialize($root, $dest, time(), null));
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
        $arg = $argv[2] ?? '';
        if ($arg === 'failed-rolled') {
            exit(vb_update_mark_job($root, 'failed', true));
        }
        exit(vb_update_mark_job($root, $arg, false));
    }
    if ($cmd === 'extract-zip') {
        $zip = $argv[2] ?? '';
        $dest = $argv[3] ?? '';
        $sum = $argv[4] ?? '';
        if (!vb_update_temp_dest_ok($dest) || !is_file($zip) || !is_file($sum)) {
            exit(1);
        }
        $expect = vb_update_parse_checksum((string) file_get_contents($sum));
        $actual = hash_file('sha256', $zip) ?: '';
        if ($expect === null || !hash_equals($expect, $actual)) {
            exit(1);
        }
        $res = vb_update_extract_zip($zip, $dest . '/out');
        if (empty($res['ok'])) {
            exit(1);
        }
        $pkg = vb_update_find_package($dest . '/out');
        if ($pkg === null || empty(vb_update_package_ok($pkg)['ok'])) {
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
        if ($realParent === false || (!str_starts_with($realParent, '/tmp/') && !str_starts_with($realParent, '/root/virabot-backups'))) {
            exit(1);
        }
        if (!is_file($root . '/config.php')) {
            exit(1);
        }
        $load = static function (string $file): array {
            require $file;
            return [
                'dbhost' => isset($dbhost) ? (string) $dbhost : 'localhost',
                'dbname' => isset($dbname) ? (string) $dbname : '',
                'dbuser' => isset($usernamedb) ? (string) $usernamedb : '',
                'dbpass' => isset($passworddb) ? (string) $passworddb : '',
            ];
        };
        $vars = $load($root . '/config.php');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $vars['dbname']) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $vars['dbuser'])) {
            exit(1);
        }
        if (str_contains($vars['dbpass'], "\n") || str_contains($vars['dbpass'], "\0")) {
            exit(1);
        }
        $esc = static fn (string $v): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
        $cnf = "[client]\nhost=\"" . $esc($vars['dbhost']) . "\"\nuser=\"" . $esc($vars['dbuser']) . "\"\npassword=\"" . $esc($vars['dbpass']) . "\"\n";
        file_put_contents($path, $cnf, LOCK_EX);
        @chmod($path, 0600);
        file_put_contents($realParent . '/dbname.txt', $vars['dbname'], LOCK_EX);
        @chmod($realParent . '/dbname.txt', 0600);
        exit(0);
    }
    fwrite(STDERR, "unknown\n");
    exit(1);
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    vb_update_cli($argv);
}
