<?php
/**
 * Trusted update distributor.
 * Web requests never read the GitHub token unless allow_web_refresh is explicitly true.
 */
declare(strict_types=1);

require_once __DIR__ . '/GitHubRelease.php';

final class VbUpdateServerApp
{
    private array $config;
    private string $storage;
    private int $now;
    private $tokenReader;
    private VbGitHubRelease $github;
    /** @var list<string> */
    public array $tokenReads = [];

    public function __construct(array $config, string $storage, int $now, ?callable $tokenReader = null, ?VbGitHubRelease $github = null)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->now = $now;
        $this->tokenReader = $tokenReader ?? static function (string $path): string {
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                return '';
            }
            $raw = trim((string) file_get_contents($path));
            if ($raw === '' || preg_match('/\s/', $raw)) {
                return '';
            }
            return $raw;
        };
        $this->github = $github ?? new VbGitHubRelease();
        if (!is_dir($this->storage)) {
            @mkdir($this->storage, 0700, true);
        }
    }

    public function configured(): bool
    {
        if (empty($this->config['enabled'])) {
            return false;
        }
        if (strlen((string) ($this->config['server_secret'] ?? '')) < 32) {
            return false;
        }
        if (vb_update_repo_slug((string) ($this->config['github_repository'] ?? '')) === null) {
            return false;
        }
        $hashes = $this->config['client_key_hashes'] ?? [];
        if (!is_array($hashes) || $hashes === []) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @return array{status:int,body:string,headers:array<string,string>,file?:string}
     */
    public function handle(string $method, string $path, array $headers, array $query, bool $https, string $ip = '0.0.0.0'): array
    {
        $method = strtoupper($method);
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $ip = $this->normalizeIp($ip);
        if (!empty($this->config['require_https']) && !$https) {
            return $this->json(403, ['ok' => false, 'error' => 'https_required']);
        }
        if ($path === '/v1/health' && $method === 'GET') {
            $rl = $this->rate('ip:' . $ip, 60, 600);
            if (!$rl['allowed']) {
                return $this->json(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $rl['retry_after']]);
            }
            return $this->json(200, ['ok' => true, 'configured' => $this->configured()]);
        }
        if (!$this->configured()) {
            return $this->json(503, ['ok' => false, 'error' => 'disabled', 'message' => 'سرور به‌روزرسانی پیکربندی نشده است.']);
        }
        $auth = $this->authenticate($headers);
        if (!$auth['ok']) {
            $rl = $this->rate('fail:' . $ip, 20, 600);
            if (!$rl['allowed']) {
                return $this->json(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $rl['retry_after']]);
            }
            return $this->json(401, ['ok' => false, 'error' => 'unauthorized']);
        }
        if ($path === '/v1/release' && $method === 'GET') {
            $rl = $this->rate('meta:' . $auth['id'], 30, 600);
            if (!$rl['allowed']) {
                return $this->json(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $rl['retry_after']]);
            }
            $meta = $this->metadata(false, !empty($this->config['allow_web_refresh']));
            if (empty($meta['ok'])) {
                $code = ($meta['error'] ?? '') === 'not_ready' ? 503 : 502;
                return $this->json($code, vb_update_public_meta($meta));
            }
            $ticketRl = $this->rate('ticket:' . $auth['id'], 10, 600);
            if (!$ticketRl['allowed']) {
                return $this->json(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $ticketRl['retry_after']]);
            }
            $ticket = $this->issueTicket($auth['id'], $meta);
            if (empty($ticket['ok'])) {
                return $this->json(500, ['ok' => false, 'error' => 'ticket']);
            }
            $public = vb_update_public_meta($meta);
            $public['download'] = $ticket['download'];
            $body = json_encode($public, JSON_UNESCAPED_UNICODE);
            if (!is_string($body) || vb_update_text_has_secret($body, $this->secretStrings())) {
                return $this->json(500, ['ok' => false, 'error' => 'response']);
            }
            return ['status' => 200, 'body' => $body, 'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']];
        }
        if ($path === '/v1/download' && $method === 'GET') {
            $rl = $this->rate('dl:' . $auth['id'], 6, 600);
            if (!$rl['allowed']) {
                return $this->json(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $rl['retry_after']]);
            }
            $got = $this->openDownload($auth['id'], $query);
            if (empty($got['ok'])) {
                $code = ($got['error'] ?? '') === 'expired' || ($got['error'] ?? '') === 'replay' ? 403 : 400;
                return $this->json($code, ['ok' => false, 'error' => (string) $got['error']]);
            }
            return [
                'status' => 200,
                'body' => '',
                'file' => (string) $got['file'],
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="virabot-update.zip"',
                    'Cache-Control' => 'no-store',
                    'X-Content-SHA256' => (string) $got['sha256'],
                ],
            ];
        }
        return $this->json(404, ['ok' => false, 'error' => 'not_found']);
    }

    /**
     * CLI refresh. This is the only path that reads the GitHub token by default.
     *
     * @return array{ok:bool,error:string}
     */
    public function refreshFromGitHub(bool $force = false): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'disabled'];
        }
        $meta = $this->metadata($force, true);
        return ['ok' => !empty($meta['ok']), 'error' => (string) ($meta['error'] ?? '')];
    }

    private function metadata(bool $forceRefresh, bool $mayFetch): array
    {
        $cache = $this->readCache();
        $fresh = is_array($cache) && vb_update_cache_fresh($cache, $this->now);
        if ($fresh && !$forceRefresh) {
            return $cache;
        }
        if (!$mayFetch) {
            if (is_array($cache) && !empty($cache['ok'])) {
                $cache['stale'] = true;
                return $cache;
            }
            return ['ok' => false, 'error' => 'not_ready', 'cached_at' => $this->now];
        }
        $slug = vb_update_repo_slug((string) $this->config['github_repository']);
        if ($slug === null) {
            return ['ok' => false, 'error' => 'disabled', 'cached_at' => $this->now];
        }
        $token = $this->readToken();
        if ($token === '') {
            $err = ['ok' => false, 'error' => 'token', 'cached_at' => $this->now];
            $this->writeCache($err);
            return $err;
        }
        $asset = (string) ($this->config['asset_name'] ?? VB_UPDATE_ASSET);
        if ($asset === '') {
            $asset = VB_UPDATE_ASSET;
        }
        $got = $this->github->refresh($slug[0], $slug[1], $token, $asset, $this->storage, $this->now);
        unset($token);
        if (empty($got['ok'])) {
            $err = ['ok' => false, 'error' => (string) ($got['error'] ?? 'github'), 'cached_at' => $this->now];
            $this->writeCache($err);
            return $err;
        }
        $this->writeCache($got);
        return $got;
    }

    private function readToken(): string
    {
        $path = (string) ($this->config['token_file'] ?? '');
        $this->tokenReads[] = $path;
        $reader = $this->tokenReader;
        return (string) $reader($path);
    }

    private function readCache(): ?array
    {
        $file = $this->storage . '/release-cache.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function writeCache(array $cache): void
    {
        $clean = $cache;
        unset($clean['token'], $clean['authorization'], $clean['github_token']);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        $file = $this->storage . '/release-cache.json';
        file_put_contents($file, $json, LOCK_EX);
        @chmod($file, 0640);
    }

    /**
     * @return array{ok:bool,download?:array<string,mixed>,error?:string}
     */
    private function issueTicket(string $keyId, array $meta): array
    {
        $version = (string) ($meta['version'] ?? '');
        $sha = strtolower((string) ($meta['sha256'] ?? ''));
        if (vb_update_semver_parse($version) === null || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return ['ok' => false, 'error' => 'meta'];
        }
        $exp = $this->now + (int) ($this->config['download_ttl'] ?? VB_UPDATE_DOWNLOAD_TTL);
        $nonce = bin2hex(random_bytes(16));
        $payload = vb_update_ticket_payload($keyId, $version, $sha, $exp, $nonce);
        $sig = vb_update_sign((string) $this->config['server_secret'], $payload);
        $dir = $this->storage . '/tickets';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $record = [
            'key_id' => $keyId,
            'version' => $version,
            'sha256' => $sha,
            'exp' => $exp,
            'nonce' => $nonce,
            'used' => false,
            'file' => (string) ($meta['file'] ?? ''),
        ];
        file_put_contents($dir . '/' . $nonce . '.json', json_encode($record), LOCK_EX);
        @chmod($dir . '/' . $nonce . '.json', 0640);
        return ['ok' => true, 'download' => [
            'path' => '/v1/download',
            'exp' => $exp,
            'nonce' => $nonce,
            'ver' => $version,
            'sig' => $sig,
        ]];
    }

    /**
     * @param array<string,string> $query
     * @return array{ok:bool,error:string,file?:string,sha256?:string}
     */
    private function openDownload(string $keyId, array $query): array
    {
        $exp = (int) ($query['exp'] ?? 0);
        $nonce = (string) ($query['nonce'] ?? '');
        $ver = (string) ($query['ver'] ?? '');
        $sig = strtolower((string) ($query['sig'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
            return ['ok' => false, 'error' => 'bad_request'];
        }
        if (vb_update_semver_parse($ver) === null) {
            return ['ok' => false, 'error' => 'bad_request'];
        }
        if ($exp < $this->now) {
            return ['ok' => false, 'error' => 'expired'];
        }
        $path = $this->storage . '/tickets/' . $nonce . '.json';
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'bad_request'];
        }
        $fh = fopen($path, 'r+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            return ['ok' => false, 'error' => 'bad_request'];
        }
        try {
            rewind($fh);
            $raw = stream_get_contents($fh);
            $rec = json_decode($raw === false ? '' : $raw, true);
            if (!is_array($rec) || !empty($rec['used'])) {
                return ['ok' => false, 'error' => 'replay'];
            }
            if ((string) ($rec['key_id'] ?? '') !== $keyId || (string) ($rec['version'] ?? '') !== $ver || (int) ($rec['exp'] ?? 0) !== $exp) {
                return ['ok' => false, 'error' => 'bad_request'];
            }
            $sha = strtolower((string) ($rec['sha256'] ?? ''));
            $payload = vb_update_ticket_payload($keyId, $ver, $sha, $exp, $nonce);
            $expect = vb_update_sign((string) $this->config['server_secret'], $payload);
            if (!hash_equals($expect, $sig)) {
                return ['ok' => false, 'error' => 'tampered'];
            }
            $rel = (string) ($rec['file'] ?? '');
            if (!preg_match('#^packages/[a-f0-9]{64}\.zip$#', $rel)) {
                return ['ok' => false, 'error' => 'integrity'];
            }
            $file = $this->storage . '/' . $rel;
            if (!is_file($file)) {
                return ['ok' => false, 'error' => 'missing'];
            }
            $disk = hash_file('sha256', $file) ?: '';
            if (!hash_equals($sha, $disk)) {
                return ['ok' => false, 'error' => 'integrity'];
            }
            $rec['used'] = true;
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($rec));
            fflush($fh);
            return ['ok' => true, 'error' => '', 'file' => $file, 'sha256' => $sha];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool,id:string}
     */
    private function authenticate(array $headers): array
    {
        $value = '';
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'authorization') {
                $value = (string) $v;
            }
        }
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,128})$/', $value, $m)) {
            return ['ok' => false, 'id' => ''];
        }
        $hashes = $this->config['client_key_hashes'] ?? [];
        if (!is_array($hashes)) {
            return ['ok' => false, 'id' => ''];
        }
        return vb_update_match_key($m[1], $hashes);
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '0.0.0.0';
    }

    /**
     * @return array{allowed:bool,retry_after:int}
     */
    private function rate(string $bucket, int $max, int $window): array
    {
        $safe = hash('sha256', $bucket);
        return vb_update_rate_allow($this->storage . '/rl/' . $safe . '.json', $this->now, $window, $max);
    }

    /** @return list<string> */
    private function secretStrings(): array
    {
        return [
            (string) ($this->config['server_secret'] ?? ''),
        ];
    }

    private function json(int $status, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '{}',
            'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        ];
    }
}
