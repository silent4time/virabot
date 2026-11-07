<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/patch/update_channel.php';

$fail = 0;
$pass = 0;
function check(bool $ok, string $name): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
        echo "ok  $name\n";
        return;
    }
    $fail++;
    echo "FAIL $name\n";
}

$now = 1_700_000_000;
check(vb_update_parse_checksum("abc\n") === null, 'bad checksum rejected');
check(vb_update_parse_checksum("0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef  virabot-update.zip\n") !== null, 'sha256sum line accepted');
check(vb_update_parse_checksum("aa\nbb\n") === null, 'two checksum lines rejected');
$picked = vb_update_pick_release([
    ['tag_name' => 'v5.9.0', 'draft' => true, 'prerelease' => false, 'assets' => [
        ['name' => 'virabot-update.zip', 'id' => 1],
        ['name' => 'virabot-update.zip.sha256', 'id' => 2],
    ]],
    ['tag_name' => 'v5.8.0', 'draft' => false, 'prerelease' => false, 'assets' => [
        ['name' => 'virabot-update.zip', 'id' => 8],
        ['name' => 'virabot-update.zip.sha256', 'id' => 9],
    ]],
]);
check(!empty($picked['ok']) && $picked['version'] === '5.8.0' && $picked['zip_id'] === 8, 'stable release with both assets');
$missing = vb_update_pick_release([
    ['tag_name' => 'v5.9.0', 'draft' => false, 'prerelease' => false, 'assets' => [
        ['name' => 'virabot-update.zip', 'id' => 3],
    ]],
    ['tag_name' => 'v5.8.0', 'draft' => false, 'prerelease' => false, 'assets' => [
        ['name' => 'virabot-update.zip', 'id' => 8],
        ['name' => 'virabot-update.zip.sha256', 'id' => 9],
    ]],
]);
check(empty($missing['ok']) && $missing['error'] === 'checksum_unavailable', 'newest release without checksum fails closed');

$zipBody = 'PK-fake-bytes';
$sumBody = hash('sha256', $zipBody) . "  virabot-update.zip\n";
$calls = [];
$GLOBALS['vb_update_transport'] = static function (string $method, string $url, array $headers, int $timeout, array $hosts) use (&$calls, $zipBody, $sumBody): array {
    $calls[] = ['url' => $url, 'headers' => $headers, 'hosts' => $hosts];
    if (isset($headers['Authorization'])) {
        return ['status' => 500, 'headers' => [], 'body' => 'auth', 'error' => 'auth'];
    }
    if (str_contains($url, '/releases?')) {
        $body = json_encode([[
            'tag_name' => 'v5.8.0', 'draft' => false, 'prerelease' => false,
            'assets' => [
                ['name' => 'virabot-update.zip', 'id' => 8, 'browser_download_url' => 'https://evil.test/steal.zip'],
                ['name' => 'virabot-update.zip.sha256', 'id' => 9, 'browser_download_url' => 'https://evil.test/steal.sha'],
            ],
        ]]);
        return ['status' => 200, 'headers' => ['x-ratelimit-remaining' => '40'], 'body' => $body, 'error' => ''];
    }
    if (str_ends_with($url, '/assets/9')) {
        return ['status' => 302, 'headers' => ['location' => 'https://objects.githubusercontent.com/sum'], 'body' => '', 'error' => ''];
    }
    if ($url === 'https://objects.githubusercontent.com/sum') {
        return ['status' => 200, 'headers' => [], 'body' => $sumBody, 'error' => ''];
    }
    if (str_ends_with($url, '/assets/8')) {
        return ['status' => 302, 'headers' => ['location' => 'https://evil.test/zip'], 'body' => '', 'error' => ''];
    }
    return ['status' => 500, 'headers' => [], 'body' => '', 'error' => 'unexpected'];
};
$root = sys_get_temp_dir() . '/vb-up-' . bin2hex(random_bytes(3));
mkdir($root);
file_put_contents($root . '/VERSION', "5.7.1\n");
$got = vb_update_fetch_release($root, $now);
check(empty($got['ok']) && ($got['error'] ?? '') === 'redirect_rejected', 'evil redirect is not fetched');
check(!array_filter($calls, static fn ($c) => str_contains($c['url'], 'evil.test')), 'no request to evil host');
check(!array_filter($calls, static fn ($c) => isset($c['headers']['Authorization'])), 'no Authorization header');

$calls = [];
$GLOBALS['vb_update_transport'] = static function (string $method, string $url, array $headers) use (&$calls, $zipBody, $sumBody): array {
    $calls[] = $url;
    if (str_contains($url, '/releases?')) {
        return ['status' => 200, 'headers' => ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1700009999'], 'body' => json_encode([[
            'tag_name' => 'v5.8.0', 'draft' => false, 'prerelease' => false,
            'assets' => [
                ['name' => 'virabot-update.zip', 'id' => 8],
                ['name' => 'virabot-update.zip.sha256', 'id' => 9],
            ],
        ]]), 'error' => ''];
    }
    if (str_ends_with($url, '/assets/9')) {
        return ['status' => 200, 'headers' => [], 'body' => $sumBody, 'error' => ''];
    }
    if (str_ends_with($url, '/assets/8')) {
        return ['status' => 200, 'headers' => [], 'body' => $zipBody, 'error' => ''];
    }
    return ['status' => 500, 'headers' => [], 'body' => '', 'error' => ''];
};
$root2 = sys_get_temp_dir() . '/vb-up2-' . bin2hex(random_bytes(3));
mkdir($root2);
$ok = vb_update_fetch_release($root2, $now);
check(!empty($ok['ok']) && $ok['sha256'] === hash('sha256', $zipBody), 'checksum matches bytes');
$before = count($calls);
$again = vb_update_fetch_release($root2, $now + 10);
check(empty($again['ok']) && count($calls) === $before, 'rate-limit remaining 0 blocks another call');

$admin = ['from_id' => '7', 'rule' => 'administrator', 'in_admin_ids' => true];
$nonce = vb_update_issue_nonce($root2, '7', '5.8.0', $now);
check(!empty(vb_update_consume_nonce($root2, $nonce['id'], $now)['ok']), 'nonce once');
check((vb_update_consume_nonce($root2, $nonce['id'], $now)['error'] ?? '') === 'replay', 'nonce replay rejected');
check(vb_update_action_allowed(['from_id' => '7', 'rule' => 'Seller', 'in_admin_ids' => true], ['admin' => '7', 'version' => '5.8.0', 'exp' => $now + 10, 'used' => false], $now)['error'] === 'forbidden', 'seller blocked');
check(vb_update_queue_validate(['v' => 1, 'action' => 'rm -rf /', 'cmd' => 'x', 'id' => str_repeat('ab', 16), 'version' => '5.8.0', 'admin_id' => '7', 'expires' => $now + 10], $now)['error'] === 'action', 'queue command rejected');

$bad = tempnam(sys_get_temp_dir(), 'zip');
$za = new ZipArchive();
$za->open($bad, ZipArchive::CREATE);
$za->addFromString('../evil.php', 'x');
$za->close();
$dest = sys_get_temp_dir() . '/vb-slip-' . bin2hex(random_bytes(2));
mkdir($dest);
$slip = vb_update_extract_zip($bad, $dest);
check(empty($slip['ok']), 'zip slip rejected');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
