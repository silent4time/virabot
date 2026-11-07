<?php
/**
 * Virabot public-release update helpers.
 * No GitHub token and no distributor server.
 */
declare(strict_types=1);

const VB_UPDATE_REPO = 'silent4time/virabot';
const VB_UPDATE_ASSET = 'virabot-update.zip';
const VB_UPDATE_SUM = 'virabot-update.zip.sha256';
const VB_UPDATE_CACHE_OK = 21600;
const VB_UPDATE_CACHE_ERR = 3600;
const VB_UPDATE_NONCE_TTL = 900;
const VB_UPDATE_JOB_TTL = 86400;
const VB_UPDATE_MAX_ZIP_BYTES = 536870912;
const VB_UPDATE_MAX_ZIP_FILES = 8000;
const VB_UPDATE_MAX_ZIP_RATIO = 80;

function vb_update_redirect_hosts(): array
{
    return [
        'release-assets.githubusercontent.com',
        'objects.githubusercontent.com',
        'github-releases.githubusercontent.com',
    ];
}

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
    if ($host === '' || !in_array($host, $hosts, true) || filter_var($host, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return false;
    }
    return true;
}

/**
 * Checksum asset is an unsigned SHA-256 of the zip, not a publisher signature.
 * Accepts a single line: 64 hex, or `sha256sum` form `HEX[ *]virabot-update.zip`.
 */
function vb_update_parse_checksum(string $body): ?string
{
    $body = str_replace("\r\n", "\n", $body);
    $lines = array_values(array_filter(explode("\n", $body), static fn ($l) => trim($l) !== ''));
    if (count($lines) !== 1) {
        return null;
    }
    $line = trim($lines[0]);
    if (!preg_match('/^([a-fA-F0-9]{64})(?:[ \t]+\*?virabot-update\.zip)?$/', $line, $m)) {
        return null;
    }
    return strtolower($m[1]);
}

/**
 * @param array<int,mixed> $releases
 * @return array{ok:bool,error:string,version:string,zip_id:int,sum_id:int}
 */
function vb_update_pick_release(array $releases): array
{
    $empty = ['ok' => false, 'error' => 'no_stable_release', 'version' => '', 'zip_id' => 0, 'sum_id' => 0];
    $best = null;
    $bestVer = '';
    foreach ($releases as $rel) {
        if (!is_array($rel) || !empty($rel['draft']) || !empty($rel['prerelease'])) {
            continue;
        }
        $parsed = vb_update_semver_parse(trim((string) ($rel['tag_name'] ?? '')));
        if ($parsed === null) {
            continue;
        }
        $ver = $parsed[0] . '.' . $parsed[1] . '.' . $parsed[2];
        if ($best !== null && vb_update_semver_cmp($ver, $bestVer) <= 0) {
            continue;
        }
        $best = $rel;
        $bestVer = $ver;
    }
    if ($best === null) {
        return $empty;
    }
    $zipId = 0;
    $sumId = 0;
    foreach (($best['assets'] ?? []) as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $id = (int) ($asset['id'] ?? 0);
        $name = (string) ($asset['name'] ?? '');
        if ($id <= 0) {
            continue;
        }
        if ($name === VB_UPDATE_ASSET) {
            $zipId = $id;
        } elseif ($name === VB_UPDATE_SUM) {
            $sumId = $id;
        }
    }
    if ($zipId === 0 || $sumId === 0) {
        $empty['error'] = 'checksum_unavailable';
        $empty['version'] = $bestVer;
        return $empty;
    }
    return ['ok' => true, 'error' => '', 'version' => $bestVer, 'zip_id' => $zipId, 'sum_id' => $sumId];
}

function vb_update_asset_api_url(int $id): ?string
{
    if ($id <= 0) {
        return null;
    }
    $url = 'https://api.github.com/repos/' . VB_UPDATE_REPO . '/releases/assets/' . $id;
    return vb_update_url_pinned($url, ['api.github.com']) ? $url : null;
}

function vb_update_zip_entry_error(string $name): ?string
{
    if ($name === '' || str_contains($name, "\0")) {
        return 'empty_name';
    }
    $name = str_replace('\\', '/', $name);
    if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name)) {
        return 'absolute';
    }
    if ($name === '..' || str_contains($name, '../') || str_contains($name, '/..')) {
        return 'traversal';
    }
    foreach (explode('/', $name) as $part) {
        if ($part === '..') {
            return 'traversal';
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

function vb_update_sanitize_package(string $root): void
{
    foreach (['config.php', '.env', 'github_token.php'] as $rel) {
        if (is_file($root . '/' . $rel)) {
            @unlink($root . '/' . $rel);
        }
    }
}

/** @return array{ok:bool,error:string} */
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
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
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

/** @return array{ok:bool,error:string} */
function vb_update_package_ok(string $root): array
{
    vb_update_sanitize_package($root);
    if (!is_file($root . '/VERSION') || !is_file($root . '/manage.sh') || !is_file($root . '/patch/botapi.php')) {
        return ['ok' => false, 'error' => 'incomplete'];
    }
    if (vb_update_semver_parse(trim((string) file_get_contents($root . '/VERSION'))) === null) {
        return ['ok' => false, 'error' => 'version'];
    }
    if (!str_contains((string) file_get_contents($root . '/manage.sh'), 'PROJECT_NAME="virabot"')) {
        return ['ok' => false, 'error' => 'not_virabot'];
    }
    if (is_file($root . '/config.php')) {
        return ['ok' => false, 'error' => 'secret_packed'];
    }
    return ['ok' => true, 'error' => ''];
}

/** @return array{ok:bool,error:string} */
function vb_update_queue_validate(array $job, int $now): array
{
    if (($job['v'] ?? null) !== 1 || ($job['action'] ?? '') !== 'apply_release') {
        return ['ok' => false, 'error' => 'action'];
    }
    if (!preg_match('/^[a-f0-9]{32}$/', (string) ($job['id'] ?? ''))) {
        return ['ok' => false, 'error' => 'id'];
    }
    if (vb_update_semver_parse((string) ($job['version'] ?? '')) === null) {
        return ['ok' => false, 'error' => 'version'];
    }
    if (!preg_match('/^-?[0-9]{1,20}$/', (string) ($job['admin_id'] ?? ''))) {
        return ['ok' => false, 'error' => 'admin'];
    }
    if ((int) ($job['expires'] ?? 0) < $now) {
        return ['ok' => false, 'error' => 'expired'];
    }
    foreach (['cmd', 'command', 'shell', 'url', 'token'] as $banned) {
        if (array_key_exists($banned, $job)) {
            return ['ok' => false, 'error' => 'action'];
        }
    }
    return ['ok' => true, 'error' => ''];
}

/** @return array{ok:bool,error:string} */
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

function vb_update_preserve_ok(string $rel): bool
{
    return !in_array($rel, ['config.php', 'config.template.php', 'api/hash.txt'], true)
        && !str_starts_with($rel, 'storage/');
}

/** @param list<string> $files @return array{ok:bool,error:string,rolled_back:bool} */
function vb_update_apply_files(string $src, string $dst, string $backup, array $files, ?int $failAfter = null): array
{
    if (!is_dir($backup) && !@mkdir($backup, 0700, true)) {
        return ['ok' => false, 'error' => 'backup', 'rolled_back' => false];
    }
    $done = [];
    $created = [];
    $n = 0;
    foreach ($files as $rel) {
        $rel = str_replace('\\', '/', $rel);
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
        if (!is_dir(dirname($to))) {
            @mkdir(dirname($to), 0750, true);
        }
        if (is_file($to)) {
            @mkdir(dirname($backup . '/' . $rel), 0700, true);
            if (!@copy($to, $backup . '/' . $rel)) {
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

function vb_update_restore_files(string $dst, string $backup, array $done, array $created): void
{
    foreach ($done as $rel) {
        if (is_file($backup . '/' . $rel)) {
            @copy($backup . '/' . $rel, $dst . '/' . $rel);
        }
    }
    foreach ($created as $rel) {
        if (is_file($dst . '/' . $rel)) {
            @unlink($dst . '/' . $rel);
        }
    }
}

/** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string,error:string} */
function vb_update_http_request(string $method, string $url, array $headers, int $timeoutSeconds, array $allowedHosts): array
{
    $fail = ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'transport'];
    if ($allowedHosts === [] || !vb_update_url_pinned($url, $allowedHosts)) {
        $fail['error'] = 'unpinned';
        return $fail;
    }
    if (isset($headers['Authorization']) || isset($headers['authorization'])) {
        $fail['error'] = 'auth_forbidden';
        return $fail;
    }
    if (!function_exists('curl_init')) {
        $fail['error'] = 'curl';
        return $fail;
    }
    $hdrs = [];
    foreach ($headers as $k => $v) {
        if (preg_match("/[\r\n]/", (string) $k . (string) $v)) {
            return $fail;
        }
        $hdrs[] = $k . ': ' . $v;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return $fail;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => 0,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        $fail['error'] = 'timeout';
        return $fail;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $parsed = [];
    foreach (preg_split("/\r\n|\n|\r/", substr($raw, 0, $headerSize)) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $parsed[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    return ['status' => $status, 'headers' => $parsed, 'body' => substr($raw, $headerSize), 'error' => ''];
}
