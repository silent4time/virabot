<?php
/**
 * GitHub release fetch for the trusted update server only.
 * The bearer token is attached only to api.github.com and is never sent to a redirect host.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/patch/update_protocol.php';

function vb_update_asset_redirect_hosts(): array
{
    return [
        'release-assets.githubusercontent.com',
        'objects.githubusercontent.com',
        'github-releases.githubusercontent.com',
    ];
}

/**
 * @param array<int,mixed> $releases
 * @return array{ok:bool,error:string,version:string,tag:string,asset_id:int,asset_api_url:string}
 */
function vb_update_pick_release(array $releases, string $assetName, string $owner, string $repo): array
{
    $empty = ['ok' => false, 'error' => 'no_stable_release', 'version' => '', 'tag' => '', 'asset_id' => 0, 'asset_api_url' => ''];
    if ($assetName !== VB_UPDATE_ASSET && !preg_match('/^[A-Za-z0-9._-]{1,80}$/', $assetName)) {
        $empty['error'] = 'bad_asset_name';
        return $empty;
    }
    $best = null;
    $bestVer = '';
    $bestAsset = 0;
    foreach ($releases as $rel) {
        if (!is_array($rel) || !empty($rel['draft']) || !empty($rel['prerelease'])) {
            continue;
        }
        $tag = trim((string) ($rel['tag_name'] ?? ''));
        $parsed = vb_update_semver_parse($tag);
        if ($parsed === null) {
            continue;
        }
        $ver = $parsed[0] . '.' . $parsed[1] . '.' . $parsed[2];
        if ($best !== null && vb_update_semver_cmp($ver, $bestVer) <= 0) {
            continue;
        }
        $assetId = 0;
        foreach (($rel['assets'] ?? []) as $asset) {
            if (!is_array($asset) || (string) ($asset['name'] ?? '') !== $assetName) {
                continue;
            }
            $id = (int) ($asset['id'] ?? 0);
            if ($id > 0) {
                $assetId = $id;
                break;
            }
        }
        if ($assetId === 0) {
            continue;
        }
        $best = $rel;
        $bestVer = $ver;
        $bestAsset = $assetId;
    }
    if ($best === null || $bestAsset === 0) {
        return $empty;
    }
    $api = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/releases/assets/' . $bestAsset;
    if (!vb_update_url_pinned($api, ['api.github.com'])) {
        $empty['error'] = 'bad_asset_url';
        return $empty;
    }
    return [
        'ok' => true,
        'error' => '',
        'version' => $bestVer,
        'tag' => (string) ($best['tag_name'] ?? $bestVer),
        'asset_id' => $bestAsset,
        'asset_api_url' => $api,
    ];
}

final class VbGitHubRelease
{
    /** @var callable(string,string,array<string,string>,int,array<int,string>):array{status:int,headers:array<string,string>,body:string,error:string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? static function (string $method, string $url, array $headers, int $timeout, array $hosts): array {
            return vb_update_http_request($method, $url, $headers, $timeout, $hosts);
        };
    }

    /**
     * @return array{ok:bool,error:string,version?:string,sha256?:string,size?:int,asset?:string,file?:string,cached_at?:int}
     */
    public function refresh(string $owner, string $repo, string $token, string $asset, string $storage, int $now): array
    {
        if ($token === '' || preg_match('/\s/', $token)) {
            return ['ok' => false, 'error' => 'token'];
        }
        $listUrl = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/releases?per_page=20';
        if (!vb_update_url_pinned($listUrl, ['api.github.com'])) {
            return ['ok' => false, 'error' => 'unpinned'];
        }
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'VirabotUpdateServer/5.8.0',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $res = ($this->transport)('GET', $listUrl, $headers, 20, ['api.github.com']);
        if (($res['status'] ?? 0) !== 200 || !is_string($res['body'] ?? null)) {
            return ['ok' => false, 'error' => 'github_list', 'cached_at' => $now];
        }
        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'github_list', 'cached_at' => $now];
        }
        $picked = vb_update_pick_release($decoded, $asset, $owner, $repo);
        if (empty($picked['ok'])) {
            return ['ok' => false, 'error' => (string) ($picked['error'] ?? 'no_stable_release'), 'cached_at' => $now];
        }
        $file = $this->downloadAsset((string) $picked['asset_api_url'], $token, $storage);
        if (empty($file['ok'])) {
            return ['ok' => false, 'error' => (string) ($file['error'] ?? 'download'), 'cached_at' => $now];
        }
        return [
            'ok' => true,
            'error' => '',
            'version' => (string) $picked['version'],
            'sha256' => (string) $file['sha256'],
            'size' => (int) $file['size'],
            'asset' => $asset,
            'file' => (string) $file['rel'],
            'cached_at' => $now,
        ];
    }

    /**
     * @return array{ok:bool,error:string,sha256?:string,size?:int,rel?:string}
     */
    private function downloadAsset(string $apiUrl, string $token, string $storage): array
    {
        if (!vb_update_url_pinned($apiUrl, ['api.github.com'])) {
            return ['ok' => false, 'error' => 'unpinned'];
        }
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/octet-stream',
            'User-Agent' => 'VirabotUpdateServer/5.8.0',
        ];
        $first = ($this->transport)('GET', $apiUrl, $headers, 30, ['api.github.com']);
        $status = (int) ($first['status'] ?? 0);
        $body = (string) ($first['body'] ?? '');
        if ($status >= 300 && $status < 400) {
            $location = (string) ($first['headers']['location'] ?? '');
            if (!vb_update_url_pinned($location, vb_update_asset_redirect_hosts())) {
                return ['ok' => false, 'error' => 'redirect_rejected'];
            }
            $host = strtolower((string) parse_url($location, PHP_URL_HOST));
            $secondHeaders = [
                'Accept' => 'application/octet-stream',
                'User-Agent' => 'VirabotUpdateServer/5.8.0',
            ];
            if (isset($secondHeaders['Authorization']) || array_key_exists('Authorization', $secondHeaders)) {
                return ['ok' => false, 'error' => 'token_forwarded'];
            }
            $second = ($this->transport)('GET', $location, $secondHeaders, 180, [$host]);
            if ((int) ($second['status'] ?? 0) !== 200) {
                return ['ok' => false, 'error' => 'download'];
            }
            $body = (string) ($second['body'] ?? '');
        } elseif ($status !== 200) {
            return ['ok' => false, 'error' => 'download'];
        }
        if ($body === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        $sha = hash('sha256', $body);
        $dir = $storage . '/packages';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return ['ok' => false, 'error' => 'storage'];
        }
        $rel = 'packages/' . $sha . '.zip';
        $path = $storage . '/' . $rel;
        if (file_put_contents($path, $body, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'storage'];
        }
        @chmod($path, 0640);
        if (!hash_equals($sha, hash_file('sha256', $path) ?: '')) {
            @unlink($path);
            return ['ok' => false, 'error' => 'integrity'];
        }
        return ['ok' => true, 'error' => '', 'sha256' => $sha, 'size' => strlen($body), 'rel' => $rel];
    }
}
