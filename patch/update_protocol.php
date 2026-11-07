<?php
/**
 * Virabot update protocol (shared, no secrets).
 * Clients and the trusted update server both load this file.
 * It never contacts GitHub and never accepts a GitHub token.
 */
declare(strict_types=1);

const VB_UPDATE_ASSET = 'virabot-update.zip';
const VB_UPDATE_CACHE_OK = 21600;   // 6h
const VB_UPDATE_CACHE_ERR = 3600;   // 1h
const VB_UPDATE_DOWNLOAD_TTL = 600; // 10 min
const VB_UPDATE_NONCE_TTL = 900;    // 15 min
const VB_UPDATE_JOB_TTL = 86400;    // 24h
const VB_UPDATE_MAX_ZIP_BYTES = 536870912; // 512 MiB uncompressed
const VB_UPDATE_MAX_ZIP_FILES = 8000;
const VB_UPDATE_MAX_ZIP_RATIO = 80;

function vb_update_semver_parse(string $version): ?array
{
    $version = trim($version);
    if (!preg_match('/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $version, $m)) {
        return null;
    }
    return [(int) $m[1], (int) $m[2], (int) $m[3]];
}

function vb_update_semver_cmp(string $a, string $b): int
{
    $pa = vb_update_semver_parse($a);
    $pb = vb_update_semver_parse($b);
    if ($pa === null || $pb === null) {
        return 0;
    }
    return $pa <=> $pb;
}

function vb_update_semver_newer(string $remote, string $local): bool
{
    if (vb_update_semver_parse($remote) === null || vb_update_semver_parse($local) === null) {
        return false;
    }
    return vb_update_semver_cmp($remote, $local) > 0;
}

function vb_update_cache_fresh(array $cache, int $now): bool
{
    $at = (int) ($cache['cached_at'] ?? 0);
    if ($at <= 0 || $at > $now + 60) {
        return false;
    }
    $ttl = !empty($cache['ok']) ? VB_UPDATE_CACHE_OK : VB_UPDATE_CACHE_ERR;
    return ($now - $at) < $ttl;
}

/**
 * HTTPS URL whose host is exactly one of $hosts.
 * Rejects userinfo, IP literals, non-443 ports, query tricks and non-https schemes.
 */
function vb_update_url_pinned(string $url, array $hosts): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || !in_array($host, $hosts, true)) {
        return false;
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return false;
    }
    return true;
}

function vb_update_client_base_ok(string $url): bool
{
    $url = trim($url);
    if ($url === '' || str_contains($url, '{{') || str_contains(strtolower($url), 'example.com')) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return false;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return false;
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path !== '' && $path !== '/') {
        return false;
    }
    return true;
}

function vb_update_join_pinned(string $base, string $path, array $query): ?string
{
    if (!vb_update_client_base_ok($base)) {
        return null;
    }
    if ($path !== '/v1/release' && $path !== '/v1/download' && $path !== '/v1/health') {
        return null;
    }
    $parts = parse_url(trim($base));
    $origin = 'https://' . strtolower((string) $parts['host']);
    $qs = [];
    foreach ($query as $k => $v) {
        $k = (string) $k;
        $v = (string) $v;
        if (!preg_match('/^[a-z]{1,12}$/', $k)) {
            return null;
        }
        if ($k === 'ver') {
            if (vb_update_semver_parse($v) === null) {
                return null;
            }
        } elseif (!preg_match('/^[A-Za-z0-9]{1,128}$/', $v)) {
            return null;
        }
        $qs[$k] = $v;
    }
    $url = $origin . $path;
    if ($qs !== []) {
        $url .= '?' . http_build_query($qs);
    }
    $host = strtolower((string) $parts['host']);
    if (!vb_update_url_pinned($url, [$host])) {
        return null;
    }
    return $url;
}

function vb_update_repo_slug(string $repo): ?array
{
    if (!preg_match('#^([A-Za-z0-9_.-]{1,80})/([A-Za-z0-9_.-]{1,80})$#', trim($repo), $m)) {
        return null;
    }
    return [$m[1], $m[2]];
}

function vb_update_client_key_ok(string $key): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{32,128}$/', $key);
}

function vb_update_key_fingerprint(string $key): string
{
    return hash('sha256', $key);
}

/**
 * @param array<string,string> $hashes id => sha256 hex
 * @return array{ok:bool,id:string}
 */
function vb_update_match_key(string $key, array $hashes): array
{
    $dummy = hash('sha256', 'vb-update-dummy-key');
    $fp = vb_update_client_key_ok($key) ? vb_update_key_fingerprint($key) : $dummy;
    $found = '';
    $checked = false;
    foreach ($hashes as $id => $hash) {
        $id = (string) $id;
        $hash = strtolower(trim((string) $hash));
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            continue;
        }
        $checked = true;
        if (hash_equals($hash, $fp)) {
            $found = $id;
        }
    }
    if (!$checked) {
        hash_equals($dummy, $fp);
    }
    return ['ok' => $found !== '', 'id' => $found];
}

function vb_update_sign(string $secret, string $payload): string
{
    return hash_hmac('sha256', $payload, $secret);
}

function vb_update_ticket_payload(string $keyId, string $version, string $sha256, int $exp, string $nonce): string
{
    return $keyId . '|' . $version . '|' . strtolower($sha256) . '|' . $exp . '|' . $nonce;
}

function vb_update_public_meta(array $meta): array
{
    $keep = ['ok', 'error', 'version', 'sha256', 'size', 'asset', 'published_at', 'stale', 'download'];
    $out = [];
    foreach ($keep as $k) {
        if (array_key_exists($k, $meta)) {
            $out[$k] = $meta[$k];
        }
    }
    if (isset($out['download']) && is_array($out['download'])) {
        $dl = $out['download'];
        $out['download'] = [
            'path' => '/v1/download',
            'exp' => (int) ($dl['exp'] ?? 0),
            'nonce' => (string) ($dl['nonce'] ?? ''),
            'ver' => (string) ($dl['ver'] ?? ''),
            'sig' => (string) ($dl['sig'] ?? ''),
        ];
    }
    return $out;
}

function vb_update_text_has_secret(string $text, array $secrets): bool
{
    foreach ($secrets as $secret) {
        $secret = (string) $secret;
        if ($secret !== '' && strlen($secret) >= 8 && str_contains($text, $secret)) {
            return true;
        }
    }
    if (preg_match('/ghp_|github_pat_|Bearer\s+[A-Za-z0-9_\-]{20,}/', $text)) {
        return true;
    }
    return false;
}

/**
 * @return array{allowed:bool,retry_after:int}
 */
function vb_update_rate_allow(string $file, int $now, int $window, int $max): array
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['allowed' => false, 'retry_after' => $window];
    }
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        return ['allowed' => false, 'retry_after' => $window];
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return ['allowed' => false, 'retry_after' => $window];
        }
        rewind($fh);
        $raw = stream_get_contents($fh);
        $hits = json_decode($raw === false || $raw === '' ? '[]' : $raw, true);
        if (!is_array($hits)) {
            $hits = [];
        }
        $hits = array_values(array_filter($hits, static function ($t) use ($now, $window) {
            return is_int($t) || ctype_digit((string) $t) ? ($now - (int) $t) < $window : false;
        }));
        $hits = array_map('intval', $hits);
        if (count($hits) >= $max) {
            $oldest = min($hits);
            return ['allowed' => false, 'retry_after' => max(1, $window - ($now - $oldest))];
        }
        $hits[] = $now;
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($hits));
        fflush($fh);
        return ['allowed' => true, 'retry_after' => 0];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function vb_update_zip_entry_error(string $name): ?string
{
    if ($name === '' || str_contains($name, "\0")) {
        return 'empty_name';
    }
    $name = str_replace('\\', '/', $name);
    if (str_starts_with($name, '/') || str_starts_with($name, '//') || preg_match('#^[A-Za-z]:/#', $name)) {
        return 'absolute';
    }
    if ($name === '..' || str_contains($name, '../') || str_contains($name, '/..')) {
        return 'traversal';
    }
    foreach (explode('/', $name) as $part) {
        if ($part === '..' || $part === '') {
            if ($part === '..') {
                return 'traversal';
            }
        }
    }
    return null;
}

function vb_update_zip_is_symlink(int $opsys, int $attr): bool
{
    if ($opsys !== 3 && $opsys !== ZipArchive::OPSYS_UNIX) {
        return false;
    }
    $mode = ($attr >> 16) & 0177777;
    return ($mode & 0170000) === 0120000;
}

/**
 * Extract a zip into $dest or reject it. On failure the destination is removed.
 *
 * @return array{ok:bool,error:string}
 */
function vb_update_extract_zip(string $zipPath, string $dest, array $limits = []): array
{
    $maxBytes = (int) ($limits['max_bytes'] ?? VB_UPDATE_MAX_ZIP_BYTES);
    $maxFiles = (int) ($limits['max_files'] ?? VB_UPDATE_MAX_ZIP_FILES);
    $maxRatio = (int) ($limits['max_ratio'] ?? VB_UPDATE_MAX_ZIP_RATIO);
    if (!class_exists(ZipArchive::class)) {
        return ['ok' => false, 'error' => 'zip_extension'];
    }
    if (is_dir($dest)) {
        vb_update_rrmdir($dest);
    }
    if (!@mkdir($dest, 0700, true) && !is_dir($dest)) {
        return ['ok' => false, 'error' => 'dest'];
    }
    $destReal = realpath($dest);
    if ($destReal === false) {
        return ['ok' => false, 'error' => 'dest'];
    }
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) {
        vb_update_rrmdir($destReal);
        return ['ok' => false, 'error' => 'open'];
    }
    $total = 0;
    $count = 0;
    $comp = filesize($zipPath);
    $comp = ($comp === false || $comp < 1) ? 1 : $comp;
    $names = [];
    for ($i = 0, $n = $za->numFiles; $i < $n; $i++) {
        $stat = $za->statIndex($i);
        if (!is_array($stat)) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'stat'];
        }
        $name = str_replace('\\', '/', (string) $stat['name']);
        $err = vb_update_zip_entry_error($name);
        if ($err !== null) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => $err];
        }
        $opsys = 0;
        $attr = 0;
        $za->getExternalAttributesIndex($i, $opsys, $attr);
        if (vb_update_zip_is_symlink($opsys, $attr)) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'symlink'];
        }
        if (str_ends_with($name, '/')) {
            continue;
        }
        $count++;
        $size = (int) ($stat['size'] ?? 0);
        if ($size < 0) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'size'];
        }
        $total += $size;
        if ($count > $maxFiles || $total > $maxBytes || ($total > 1048576 && ($total / $comp) > $maxRatio)) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'bomb'];
        }
        $names[] = $name;
    }
    foreach ($names as $name) {
        $target = $destReal . '/' . $name;
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'mkdir'];
        }
        $parentReal = realpath($parent);
        $prefix = $destReal . DIRECTORY_SEPARATOR;
        if ($parentReal === false || ($parentReal !== $destReal && !str_starts_with($parentReal . DIRECTORY_SEPARATOR, $prefix))) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'escape'];
        }
        $in = $za->getStream($name);
        if ($in === false) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'read'];
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'write'];
        }
        $written = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        if ($written === false) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'write'];
        }
        $fileReal = realpath($target);
        if ($fileReal === false || !str_starts_with($fileReal, $prefix)) {
            $za->close();
            vb_update_rrmdir($destReal);
            return ['ok' => false, 'error' => 'escape'];
        }
    }
    $za->close();
    vb_update_sanitize_package($destReal);
    return ['ok' => true, 'error' => ''];
}

function vb_update_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            vb_update_rrmdir($path);
        }
    }
    @rmdir($dir);
}

/** Drop distributor secrets if a full git tree was packed by mistake. */
function vb_update_sanitize_package(string $root): void
{
    vb_update_rrmdir($root . '/update-server');
    foreach (['.env', 'github_token.php', 'update-server/config.php'] as $rel) {
        $path = $root . '/' . $rel;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * @return array{ok:bool,error:string}
 */
function vb_update_package_ok(string $root): array
{
    vb_update_sanitize_package($root);
    $versionFile = $root . '/VERSION';
    $manage = $root . '/manage.sh';
    $bot = $root . '/patch/botapi.php';
    if (!is_file($versionFile) || !is_file($manage) || !is_file($bot)) {
        return ['ok' => false, 'error' => 'incomplete'];
    }
    $ver = trim((string) file_get_contents($versionFile));
    if (vb_update_semver_parse($ver) === null) {
        return ['ok' => false, 'error' => 'version'];
    }
    $ms = (string) file_get_contents($manage);
    if (!str_contains($ms, 'PROJECT_NAME="virabot"')) {
        return ['ok' => false, 'error' => 'not_virabot'];
    }
    if (is_file($root . '/update-server/config.php')) {
        return ['ok' => false, 'error' => 'secret_packed'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{ok:bool,error:string}
 */
function vb_update_queue_validate(array $job, int $now): array
{
    if (($job['v'] ?? null) !== 1) {
        return ['ok' => false, 'error' => 'schema'];
    }
    if (($job['action'] ?? '') !== 'apply_release') {
        return ['ok' => false, 'error' => 'action'];
    }
    $id = (string) ($job['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        return ['ok' => false, 'error' => 'id'];
    }
    if (vb_update_semver_parse((string) ($job['version'] ?? '')) === null) {
        return ['ok' => false, 'error' => 'version'];
    }
    $admin = (string) ($job['admin_id'] ?? '');
    if (!preg_match('/^-?[0-9]{1,20}$/', $admin)) {
        return ['ok' => false, 'error' => 'admin'];
    }
    $exp = (int) ($job['expires'] ?? 0);
    if ($exp < $now) {
        return ['ok' => false, 'error' => 'expired'];
    }
    foreach (['cmd', 'command', 'shell', 'url', 'token'] as $banned) {
        if (array_key_exists($banned, $job)) {
            return ['ok' => false, 'error' => 'action'];
        }
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * @param array{from_id?:string,rule?:string,in_admin_ids?:bool} $admin
 * @param array{admin?:string,version?:string,exp?:int,used?:bool} $nonce
 * @return array{ok:bool,error:string}
 */
function vb_update_action_allowed(array $admin, array $nonce, int $now): array
{
    $from = (string) ($admin['from_id'] ?? '');
    if (($admin['rule'] ?? '') !== 'administrator' || empty($admin['in_admin_ids']) || $from === '') {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    if ($from !== (string) ($nonce['admin'] ?? '')) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    if (!empty($nonce['used'])) {
        return ['ok' => false, 'error' => 'replay'];
    }
    if ((int) ($nonce['exp'] ?? 0) < $now) {
        return ['ok' => false, 'error' => 'expired'];
    }
    if (vb_update_semver_parse((string) ($nonce['version'] ?? '')) === null) {
        return ['ok' => false, 'error' => 'version'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * Copy a whitelist of files with backup and rollback.
 * $failAfter aborts after that many successful copies (tests).
 *
 * @param list<string> $files
 * @return array{ok:bool,error:string,rolled_back:bool}
 */
function vb_update_apply_files(string $src, string $dst, string $backup, array $files, ?int $failAfter = null): array
{
    if (!is_dir($backup) && !@mkdir($backup, 0700, true)) {
        return ['ok' => false, 'error' => 'backup', 'rolled_back' => false];
    }
    $done = [];
    $created = [];
    $n = 0;
    foreach ($files as $rel) {
        $rel = str_replace('\\', '/', (string) $rel);
        if (vb_update_zip_entry_error($rel) !== null || !vb_update_preserve_ok($rel)) {
            vb_update_restore_files($dst, $backup, $done, $created);
            return ['ok' => false, 'error' => 'path', 'rolled_back' => true];
        }
        $from = $src . '/' . $rel;
        $to = $dst . '/' . $rel;
        if (!is_file($from)) {
            vb_update_restore_files($dst, $backup, $done, $created);
            return ['ok' => false, 'error' => 'missing', 'rolled_back' => true];
        }
        $parent = dirname($to);
        if (!is_dir($parent) && !@mkdir($parent, 0750, true)) {
            vb_update_restore_files($dst, $backup, $done, $created);
            return ['ok' => false, 'error' => 'mkdir', 'rolled_back' => true];
        }
        if (is_file($to)) {
            $snap = $backup . '/' . $rel;
            $snapParent = dirname($snap);
            if (!is_dir($snapParent)) {
                @mkdir($snapParent, 0700, true);
            }
            if (!@copy($to, $snap)) {
                vb_update_restore_files($dst, $backup, $done, $created);
                return ['ok' => false, 'error' => 'snapshot', 'rolled_back' => true];
            }
            $done[] = $rel;
        } else {
            $created[] = $rel;
        }
        if (!@copy($from, $to)) {
            vb_update_restore_files($dst, $backup, $done, $created);
            return ['ok' => false, 'error' => 'copy', 'rolled_back' => true];
        }
        $n++;
        if ($failAfter !== null && $n >= $failAfter) {
            vb_update_restore_files($dst, $backup, $done, $created);
            return ['ok' => false, 'error' => 'injected', 'rolled_back' => true];
        }
    }
    return ['ok' => true, 'error' => '', 'rolled_back' => false];
}

function vb_update_preserve_ok(string $rel): bool
{
    if ($rel === 'config.php' || $rel === 'config.template.php' || $rel === 'api/hash.txt') {
        return false;
    }
    if ($rel === 'storage' || str_starts_with($rel, 'storage/') || str_starts_with($rel, 'update-server/')) {
        return false;
    }
    return true;
}

function vb_update_restore_files(string $dst, string $backup, array $done, array $created): void
{
    foreach ($done as $rel) {
        $snap = $backup . '/' . $rel;
        $to = $dst . '/' . $rel;
        if (is_file($snap)) {
            @copy($snap, $to);
        }
    }
    foreach ($created as $rel) {
        $to = $dst . '/' . $rel;
        if (is_file($to)) {
            @unlink($to);
        }
    }
}

/**
 * HTTPS client that does not follow redirects and does not talk to non-HTTPS URLs.
 */
function vb_update_http_request(string $method, string $url, array $headers, int $timeoutSeconds, array $allowedHosts): array
{
    $fail = ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'transport'];
    if ($allowedHosts === [] || !vb_update_url_pinned($url, $allowedHosts)) {
        $fail['error'] = 'unpinned';
        return $fail;
    }
    if (!function_exists('curl_init')) {
        $fail['error'] = 'curl';
        return $fail;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return $fail;
    }
    $hdrs = [];
    foreach ($headers as $k => $v) {
        $k = (string) $k;
        $v = (string) $v;
        if (str_contains($k, "\r") || str_contains($k, "\n") || str_contains($v, "\r") || str_contains($v, "\n")) {
            return $fail;
        }
        $hdrs[] = $k . ': ' . $v;
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => 0,
    ];
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $fail['error'] = 'timeout';
        curl_close($ch);
        return $fail;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerText = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $parsed = [];
    foreach (preg_split("/\r\n|\n|\r/", $headerText) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $parsed[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    return ['status' => $status, 'headers' => $parsed, 'body' => $body, 'error' => ''];
}
