<?php
/**
 * آزمون کانال به‌روزرسانی. هیچ رمز واقعی لازم ندارد.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/patch/update_channel.php';
require_once dirname(__DIR__) . '/update-server/lib/ServerApp.php';

$fail = 0;
$pass = 0;

function check(bool $cond, string $name): void
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "ok  {$name}\n";
        return;
    }
    $fail++;
    echo "FAIL {$name}\n";
}

function tempdir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    return $dir;
}

function serverConfig(string $tokenFile): array
{
    $key = str_repeat('a', 32);
    return [
        'enabled' => true,
        'require_https' => true,
        'token_file' => $tokenFile,
        'github_repository' => 'silent4time/virabot',
        'asset_name' => 'virabot-update.zip',
        'server_secret' => 'SERVERSECRET_SHOULD_NOT_LEAK_0001',
        'client_key_hashes' => ['bot1' => hash('sha256', $key)],
        'download_ttl' => 600,
        'allow_web_refresh' => false,
    ];
}

function bearer(): array
{
    return ['Authorization' => 'Bearer ' . str_repeat('a', 32)];
}

$now = 1_700_000_000;

check(vb_update_semver_parse('5.8.0') === [5, 8, 0], 'semver plain');
check(vb_update_semver_parse('v5.8.0') === [5, 8, 0], 'semver v prefix');
check(vb_update_semver_parse('5.8.0-rc.1') === null, 'reject prerelease tag');
check(vb_update_semver_newer('5.8.0', '5.7.1'), 'newer compare');
check(!vb_update_semver_newer('5.7.1', '5.8.0'), 'older compare');
check(vb_update_cache_fresh(['ok' => true, 'cached_at' => $now - 21599], $now), 'success cache inside 6h');
check(!vb_update_cache_fresh(['ok' => true, 'cached_at' => $now - 21600], $now), 'success cache at 6h');
check(vb_update_cache_fresh(['ok' => false, 'cached_at' => $now - 3599], $now), 'error cache inside 1h');
check(!vb_update_cache_fresh(['ok' => false, 'cached_at' => $now - 3600], $now), 'error cache at 1h');

check(!vb_update_client_base_ok('http://updates.test'), 'reject http client url');
check(!vb_update_client_base_ok('https://user:pass@updates.test'), 'reject userinfo');
check(!vb_update_client_base_ok('https://example.com'), 'reject example placeholder');
check(!vb_update_client_base_ok('https://203.0.113.5'), 'reject ip literal');
check(vb_update_client_base_ok('https://updates.test'), 'accept https origin');
check(vb_update_join_pinned('https://updates.test', '/v1/release', []) === 'https://updates.test/v1/release', 'pinned release url');
check(vb_update_join_pinned('https://updates.test', 'https://evil.test/v1/release', []) === null, 'reject absolute client path');
check(vb_update_http_request('GET', 'https://evil.test/x', [], 5, ['updates.test'])['error'] === 'unpinned', 'http helper refuses unpinned host');

$picked = vb_update_pick_release([
    ['tag_name' => 'v5.9.0', 'draft' => true, 'prerelease' => false, 'assets' => [['name' => 'virabot-update.zip', 'id' => 1]]],
    ['tag_name' => 'v5.8.1-rc.1', 'draft' => false, 'prerelease' => true, 'assets' => [['name' => 'virabot-update.zip', 'id' => 2]]],
    ['tag_name' => 'v5.7.9', 'draft' => false, 'prerelease' => false, 'assets' => [['name' => 'virabot-update.zip', 'id' => 3]]],
    ['tag_name' => 'v5.8.0', 'draft' => false, 'prerelease' => false, 'assets' => [['name' => 'other.zip', 'id' => 4], ['name' => 'virabot-update.zip', 'id' => 8]]],
    ['tag_name' => 'nightly', 'draft' => false, 'prerelease' => false, 'assets' => [['name' => 'virabot-update.zip', 'id' => 9]]],
], 'virabot-update.zip', 'silent4time', 'virabot');
check(!empty($picked['ok']) && $picked['version'] === '5.8.0' && $picked['asset_id'] === 8, 'pick highest stable fixed asset');
check($picked['asset_api_url'] === 'https://api.github.com/repos/silent4time/virabot/releases/assets/8', 'asset url stays on api.github.com');
$missing = vb_update_pick_release([
    ['tag_name' => 'v5.8.0', 'draft' => false, 'prerelease' => false, 'assets' => [['name' => 'other.zip', 'id' => 4]]],
], 'virabot-update.zip', 'silent4time', 'virabot');
check(empty($missing['ok']), 'missing fixed asset is not a release');

$zipBody = '';
$pkg = tempdir('vb-pkg-');
file_put_contents($pkg . '/VERSION', "5.8.0\n");
file_put_contents($pkg . '/manage.sh', "PROJECT_NAME=\"virabot\"\n");
mkdir($pkg . '/patch');
file_put_contents($pkg . '/patch/botapi.php', "<?php\n");
mkdir($pkg . '/update-server');
file_put_contents($pkg . '/update-server/config.php', "<?php return ['github_token' => 'ghp_this_must_not_survive'];\n");
$zipPath = tempdir('vb-zip-') . '/a.zip';
$za = new ZipArchive();
$za->open($zipPath, ZipArchive::CREATE);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pkg, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $za->addFile($file->getPathname(), substr($file->getPathname(), strlen($pkg) + 1));
    }
}
$za->close();
$zipBody = (string) file_get_contents($zipPath);

$slipDir = tempdir('vb-slip-');
$slip = vb_update_extract_zip($zipPath, $slipDir);
check(!empty($slip['ok']), 'safe package extracts');
check(!is_file($slipDir . '/update-server/config.php'), 'sanitize removes packed distributor config');
vb_update_sanitize_package($slipDir);
check(!is_dir($slipDir . '/update-server'), 'distributor directory removed');
check(!empty(vb_update_package_ok($slipDir)['ok']), 'package accepted after sanitize');
check(!str_contains((string) file_get_contents($slipDir . '/manage.sh'), 'ghp_'), 'token text not left in package');

$badZip = tempdir('vb-badzip-') . '/bad.zip';
$bz = new ZipArchive();
$bz->open($badZip, ZipArchive::CREATE);
$bz->addFromString('../evil.php', '<?php echo 1;');
$bz->addFromString('/tmp/abs.php', '<?php echo 1;');
$bz->addFromString('link', 'target');
$bz->setExternalAttributesIndex(2, ZipArchive::OPSYS_UNIX, 0120000 << 16);
$bz->close();
$badDest = tempdir('vb-baddest-');
$bad = vb_update_extract_zip($badZip, $badDest);
check(empty($bad['ok']) && in_array($bad['error'], ['traversal', 'absolute', 'symlink'], true), 'unsafe archive rejected: ' . $bad['error']);
check(!is_dir($badDest), 'rejected archive destination removed');
check(!is_file('/tmp/abs.php') && !is_file(dirname($badDest) . '/evil.php'), 'slip did not write outside');

$src = tempdir('vb-src-');
$dst = tempdir('vb-dst-');
$bak = tempdir('vb-bak-');
file_put_contents($src . '/index.php', 'NEW');
file_put_contents($dst . '/index.php', 'OLD');
file_put_contents($dst . '/config.php', 'SECRET');
$applied = vb_update_apply_files($src, $dst, $bak, ['index.php'], 1);
check(empty($applied['ok']) && !empty($applied['rolled_back']), 'injected failure rolls back');
check(file_get_contents($dst . '/index.php') === 'OLD', 'previous file restored');
check(file_get_contents($dst . '/config.php') === 'SECRET', 'config untouched by apply list');
$refused = vb_update_apply_files($src, $dst, $bak, ['config.php'], null);
check(empty($refused['ok']) && file_get_contents($dst . '/config.php') === 'SECRET', 'config.php cannot be replaced');

$adminOk = ['from_id' => '42', 'rule' => 'administrator', 'in_admin_ids' => true];
$nonceOk = ['admin' => '42', 'version' => '5.8.0', 'exp' => $now + 100, 'used' => false];
check(!empty(vb_update_action_allowed($adminOk, $nonceOk, $now)['ok']), 'administrator may confirm');
check(vb_update_action_allowed(['from_id' => '42', 'rule' => 'Seller', 'in_admin_ids' => true], $nonceOk, $now)['error'] === 'forbidden', 'seller forbidden');
check(vb_update_action_allowed(['from_id' => '42', 'rule' => 'support', 'in_admin_ids' => true], $nonceOk, $now)['error'] === 'forbidden', 'support forbidden');
check(vb_update_action_allowed(['from_id' => '7', 'rule' => 'administrator', 'in_admin_ids' => false], $nonceOk, $now)['error'] === 'forbidden', 'outside admin list forbidden');
check(vb_update_action_allowed($adminOk, ['admin' => '99', 'version' => '5.8.0', 'exp' => $now + 100, 'used' => false], $now)['error'] === 'forbidden', 'nonce bound to another admin');
check(vb_update_action_allowed($adminOk, ['admin' => '42', 'version' => '5.8.0', 'exp' => $now + 100, 'used' => true], $now)['error'] === 'replay', 'used nonce rejected');
check(vb_update_action_allowed($adminOk, ['admin' => '42', 'version' => '5.8.0', 'exp' => $now - 1, 'used' => false], $now)['error'] === 'expired', 'expired nonce rejected');
check(vb_update_queue_validate(['v' => 1, 'action' => 'apply_release', 'id' => str_repeat('ab', 16), 'version' => '5.8.0', 'admin_id' => '42', 'expires' => $now + 10], $now)['ok'] === true, 'fixed action accepted');
check(vb_update_queue_validate(['v' => 1, 'action' => 'touch /tmp/x', 'cmd' => 'rm -rf /', 'id' => str_repeat('ab', 16), 'version' => '5.8.0', 'admin_id' => '42', 'expires' => $now + 10], $now)['error'] === 'action', 'command payload rejected');

$root = tempdir('vb-root-');
file_put_contents($root . '/VERSION', "5.7.1\n");
$issued = vb_update_issue_nonce($root, '42', '5.8.0', $now);
check(!empty($issued['ok']), 'nonce issued');
$once = vb_update_consume_nonce($root, (string) $issued['id'], $now);
check(!empty($once['ok']) && $once['admin'] === '42', 'nonce consumed');
$twice = vb_update_consume_nonce($root, (string) $issued['id'], $now);
check(($twice['error'] ?? '') === 'replay', 'nonce cannot be replayed');

$GLOBALS['UPDATE_SERVER_URL'] = '';
$GLOBALS['UPDATE_CLIENT_KEY'] = '';
$disabled = vb_update_availability($root, $now);
check($disabled['state'] === 'disabled', 'empty config stays disabled');
$calls = [];
$GLOBALS['vb_update_transport'] = static function () use (&$calls): array {
    $calls[] = 'hit';
    return ['status' => 500, 'headers' => [], 'body' => '', 'error' => ''];
};
vb_update_availability($root, $now);
check($calls === [], 'disabled client does not call the network');

$GLOBALS['UPDATE_SERVER_URL'] = 'https://updates.test';
$GLOBALS['UPDATE_CLIENT_KEY'] = str_repeat('a', 32);
$meta = [
    'ok' => true,
    'version' => '5.8.0',
    'sha256' => hash('sha256', $zipBody),
    'size' => strlen($zipBody),
    'download' => ['path' => '/v1/download', 'exp' => (string) ($now + 100), 'nonce' => str_repeat('b', 32), 'ver' => '5.8.0', 'sig' => str_repeat('c', 64)],
];
$seen = [];
$GLOBALS['vb_update_transport'] = static function (string $method, string $url, array $headers, int $timeout, array $hosts) use (&$seen, $meta, $zipBody): array {
    $seen[] = ['url' => $url, 'headers' => $headers, 'hosts' => $hosts];
    if (str_contains($url, '/v1/release')) {
        return ['status' => 200, 'headers' => [], 'body' => json_encode($meta), 'error' => ''];
    }
    return ['status' => 200, 'headers' => ['x-content-sha256' => hash('sha256', $zipBody)], 'body' => $zipBody, 'error' => ''];
};
$avail = vb_update_availability($root, $now + 10);
check($avail['state'] === 'available' && $avail['remote'] === '5.8.0', 'client sees newer stable');
check($seen !== [] && !str_contains($seen[0]['url'], 'github.com'), 'client metadata url is not GitHub');
check($seen[0]['hosts'] === ['updates.test'], 'client pins the configured host');
check(($seen[0]['headers']['Authorization'] ?? '') === 'Bearer ' . str_repeat('a', 32), 'client sends its own key');
$queued = vb_update_enqueue($root, $adminOk, '5.8.0', $now + 10);
check(!empty($queued['ok']), 'administrator enqueue');
$seller = vb_update_enqueue($root, ['from_id' => '42', 'rule' => 'Seller', 'in_admin_ids' => true], '5.8.0', $now + 10);
check(($seller['error'] ?? '') === 'forbidden', 'seller enqueue refused');
$again = vb_update_enqueue($root, $adminOk, '5.8.0', $now + 10);
check(($again['error'] ?? '') === 'busy', 'second enqueue blocked');

$jobDir = tempdir('vb-job-');
file_put_contents($jobDir . '/VERSION', "1.0.0\n");
mkdir($jobDir . '/storage/update-queue/running', 0700, true);
file_put_contents($jobDir . '/storage/update-queue/running/job.json', json_encode([
    'v' => 1, 'action' => 'apply_release', 'id' => str_repeat('ab', 16), 'version' => '9.9.9', 'admin_id' => '42', 'expires' => $now + 50,
]));
check(vb_update_mark_job($jobDir, 'applied') === 1, 'false success rejected when version differs');
$status = json_decode((string) file_get_contents($jobDir . '/storage/update-status.json'), true);
check(($status['state'] ?? '') === 'failed', 'status records failure');

$badHash = $meta;
$badHash['sha256'] = str_repeat('d', 64);
$mismatch = vb_update_download_package(vb_update_settings(), $badHash, tempdir('vb-dl-') . '/p.zip');
check(($mismatch['error'] ?? '') === 'integrity', 'hash mismatch rejected');

$token = 'ghp_test_example_token_not_real_0123456789';
$storage = tempdir('vb-srv-');
$tokenFile = $storage . '/token';
file_put_contents($tokenFile, $token);
chmod($tokenFile, 0600);
$redirects = [];
$github = new VbGitHubRelease(static function (string $method, string $url, array $headers, int $timeout, array $hosts) use (&$redirects, $token, $zipBody): array {
    $redirects[] = ['url' => $url, 'auth' => $headers['Authorization'] ?? '', 'hosts' => $hosts];
    if (str_contains($url, '/releases?')) {
        $body = json_encode([[
            'tag_name' => 'v5.8.0', 'draft' => false, 'prerelease' => false,
            'assets' => [['name' => 'virabot-update.zip', 'id' => 8]],
        ]]);
        return ['status' => 200, 'headers' => [], 'body' => $body, 'error' => ''];
    }
    if (str_contains($url, '/releases/assets/')) {
        return ['status' => 302, 'headers' => ['location' => 'https://objects.githubusercontent.com/release/pkg'], 'body' => '', 'error' => ''];
    }
    if (str_starts_with($url, 'https://objects.githubusercontent.com/')) {
        return ['status' => 200, 'headers' => [], 'body' => $zipBody, 'error' => ''];
    }
    return ['status' => 500, 'headers' => [], 'body' => '', 'error' => 'unexpected'];
});
$app = new VbUpdateServerApp(serverConfig($tokenFile), $storage, $now, static function (string $path) use ($tokenFile, $token): string {
    return $path === $tokenFile ? $token : '';
}, $github);
$cold = $app->handle('GET', '/v1/release', bearer(), [], true, '203.0.113.10');
check($cold['status'] === 503 && $app->tokenReads === [], 'web request does not read the GitHub token');
check($redirects === [], 'cold web request does not call GitHub');
$refreshed = $app->refreshFromGitHub(false);
check(!empty($refreshed['ok']), 'cli refresh stores the release');
check(count($redirects) === 3, 'list, api asset, then pinned redirect');
check($redirects[0]['auth'] === 'Bearer ' . $token && $redirects[0]['hosts'] === ['api.github.com'], 'token only on api.github.com list');
check($redirects[1]['auth'] === 'Bearer ' . $token, 'token on asset api');
check($redirects[2]['auth'] === '' && $redirects[2]['hosts'] === ['objects.githubusercontent.com'], 'redirect host gets no bearer token');
$warm = $app->handle('GET', '/v1/release', bearer(), [], true, '203.0.113.10');
check($warm['status'] === 200, 'cached metadata served');
check(!str_contains($warm['body'], $token) && !str_contains($warm['body'], 'SERVERSECRET_SHOULD_NOT_LEAK_0001'), 'response has no token or signing secret');
check(!str_contains($warm['body'], 'github.com'), 'client payload has no GitHub URL');
$payload = json_decode($warm['body'], true);
$dl = $app->handle('GET', '/v1/download', bearer(), [
    'exp' => (string) $payload['download']['exp'],
    'nonce' => (string) $payload['download']['nonce'],
    'ver' => (string) $payload['download']['ver'],
    'sig' => (string) $payload['download']['sig'],
], true, '203.0.113.10');
check($dl['status'] === 200 && is_file((string) $dl['file']), 'expiring url downloads the zip');
check(hash_file('sha256', (string) $dl['file']) === $payload['sha256'], 'downloaded bytes match sha256');
$replay = $app->handle('GET', '/v1/download', bearer(), [
    'exp' => (string) $payload['download']['exp'],
    'nonce' => (string) $payload['download']['nonce'],
    'ver' => (string) $payload['download']['ver'],
    'sig' => (string) $payload['download']['sig'],
], true, '203.0.113.10');
check($replay['status'] === 403 && str_contains($replay['body'], 'replay'), 'download url is single use');

$evilCalls = 0;
$evil = new VbGitHubRelease(static function (string $method, string $url, array $headers, int $timeout, array $hosts) use (&$evilCalls): array {
    $evilCalls++;
    if ($evilCalls === 1) {
        return ['status' => 200, 'headers' => [], 'body' => json_encode([[
            'tag_name' => 'v5.8.0', 'draft' => false, 'prerelease' => false,
            'assets' => [['name' => 'virabot-update.zip', 'id' => 3]],
        ]]), 'error' => ''];
    }
    return ['status' => 302, 'headers' => ['location' => 'https://evil.test/steal'], 'body' => '', 'error' => ''];
});
$evilStorage = tempdir('vb-evil-');
$evilApp = new VbUpdateServerApp(serverConfig($tokenFile), $evilStorage, $now, static fn (): string => $token, $evil);
$evilResult = $evilApp->refreshFromGitHub(true);
check(empty($evilResult['ok']) && $evilCalls === 2, 'rejected redirect is not fetched');

$unauth = $app->handle('GET', '/v1/release', ['Authorization' => 'Bearer ' . str_repeat('b', 32)], [], true, '203.0.113.20');
check($unauth['status'] === 401, 'wrong key rejected');
$plain = serverConfig($tokenFile);
$plain['require_https'] = true;
$httpApp = new VbUpdateServerApp($plain, tempdir('vb-http-'), $now);
check($httpApp->handle('GET', '/v1/health', [], [], false, '203.0.113.21')['status'] === 403, 'plain http rejected');
$off = $plain;
$off['enabled'] = false;
$offApp = new VbUpdateServerApp($off, tempdir('vb-off-'), $now);
check($offApp->handle('GET', '/v1/release', bearer(), [], true, '203.0.113.22')['status'] === 503, 'disabled server refuses work');

$limitStorage = tempdir('vb-limit-');
file_put_contents($limitStorage . '/release-cache.json', json_encode([
    'ok' => true, 'version' => '5.8.0', 'sha256' => hash('sha256', $zipBody), 'size' => strlen($zipBody),
    'asset' => 'virabot-update.zip', 'file' => 'packages/' . hash('sha256', $zipBody) . '.zip', 'cached_at' => $now,
]));
mkdir($limitStorage . '/packages', 0700, true);
file_put_contents($limitStorage . '/packages/' . hash('sha256', $zipBody) . '.zip', $zipBody);
$limit = new VbUpdateServerApp(serverConfig($tokenFile), $limitStorage, $now);
$saw429 = false;
for ($i = 0; $i < 11; $i++) {
    $res = $limit->handle('GET', '/v1/release', bearer(), [], true, '203.0.113.30');
    if ($res['status'] === 429) {
        $saw429 = true;
    }
}
check($saw429, 'ticket issuance rate limit is 10 per 10 minutes');
$fail429 = false;
$failApp = new VbUpdateServerApp(serverConfig($tokenFile), tempdir('vb-fail-'), $now);
for ($i = 0; $i < 21; $i++) {
    $res = $failApp->handle('GET', '/v1/release', ['Authorization' => 'Bearer ' . str_repeat('c', 32)], [], true, '203.0.113.31');
    if ($i < 20) {
        check($res['status'] === 401, 'failed auth ' . ($i + 1) . ' stays 401');
    } elseif ($res['status'] === 429) {
        $fail429 = true;
    }
}
check($fail429, '21st failed auth from one IP is rate limited');

$tampered = $payload;
$tampered['download']['sig'] = str_repeat('a', 64);
$badSig = $limit->handle('GET', '/v1/download', bearer(), [
    'exp' => (string) $payload['download']['exp'],
    'nonce' => (string) $payload['download']['nonce'],
    'ver' => (string) $payload['download']['ver'],
    'sig' => str_repeat('ab', 32),
], true, '203.0.113.40');
check($badSig['status'] === 400, 'unknown ticket rejected');

$expiredStorage = tempdir('vb-exp-');
$secret = 'SERVERSECRET_SHOULD_NOT_LEAK_0001';
$exp = $now - 5;
$nonce = str_repeat('e', 32);
$sha = hash('sha256', $zipBody);
$sig = vb_update_sign($secret, vb_update_ticket_payload('bot1', '5.8.0', $sha, $exp, $nonce));
mkdir($expiredStorage . '/tickets', 0700, true);
file_put_contents($expiredStorage . '/tickets/' . $nonce . '.json', json_encode([
    'key_id' => 'bot1', 'version' => '5.8.0', 'sha256' => $sha, 'exp' => $exp, 'nonce' => $nonce, 'used' => false, 'file' => 'packages/' . $sha . '.zip',
]));
$expiredApp = new VbUpdateServerApp(serverConfig($tokenFile), $expiredStorage, $now);
$expired = $expiredApp->handle('GET', '/v1/download', bearer(), [
    'exp' => (string) $exp, 'nonce' => $nonce, 'ver' => '5.8.0', 'sig' => $sig,
], true, '203.0.113.41');
check($expired['status'] === 403 && str_contains($expired['body'], 'expired'), 'expired signature rejected');

$dlStorage = tempdir('vb-dlrate-');
file_put_contents($dlStorage . '/release-cache.json', json_encode([
    'ok' => true, 'version' => '5.8.0', 'sha256' => hash('sha256', $zipBody), 'size' => strlen($zipBody),
    'asset' => 'virabot-update.zip', 'file' => 'packages/' . hash('sha256', $zipBody) . '.zip', 'cached_at' => $now,
]));
mkdir($dlStorage . '/packages', 0700, true);
file_put_contents($dlStorage . '/packages/' . hash('sha256', $zipBody) . '.zip', $zipBody);
$dlApp = new VbUpdateServerApp(serverConfig($tokenFile), $dlStorage, $now);
$dl429 = false;
for ($i = 0; $i < 7; $i++) {
    $metaRes = $dlApp->handle('GET', '/v1/release', bearer(), [], true, '203.0.113.50');
    $metaJson = json_decode($metaRes['body'], true);
    $one = $dlApp->handle('GET', '/v1/download', bearer(), [
        'exp' => (string) ($metaJson['download']['exp'] ?? ''),
        'nonce' => (string) ($metaJson['download']['nonce'] ?? ''),
        'ver' => (string) ($metaJson['download']['ver'] ?? ''),
        'sig' => (string) ($metaJson['download']['sig'] ?? ''),
    ], true, '203.0.113.50');
    if ($one['status'] === 429) {
        $dl429 = true;
    }
}
check($dl429, 'download rate limit is 6 per 10 minutes');

$clientSrc = (string) file_get_contents(dirname(__DIR__) . '/patch/update_channel.php');
$protocolSrc = (string) file_get_contents(dirname(__DIR__) . '/patch/update_protocol.php');
check(!str_contains($clientSrc, 'api.github.com') && !str_contains($protocolSrc, 'api.github.com'), 'installed client code has no GitHub API host');
check(!str_contains((string) file_get_contents(dirname(__DIR__) . '/install.sh'), 'Authorization: token'), 'installer does not attach a GitHub token');
check(!str_contains((string) file_get_contents(dirname(__DIR__) . '/manage.sh'), 'codeload.github.com'), 'manage.sh update no longer downloads codeload');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
