<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/function.php';
if (is_file(__DIR__ . '/Eylan.php')) require_once __DIR__ . '/Eylan.php';

$namepanel = $argv[1] ?? 'تیلان wireguard';
$username = $argv[2] ?? 'tylan_wireguard__63';
$panel = eylan_get_panel($namepanel);
$base = rtrim($panel['url_panel'], '/');
$user = eylan_get_user($namepanel, $username);
$token = '34e11b';
$sub = eylan_get_sub($namepanel, $username);
if (is_array($sub) && !empty($sub['sub_url']) && preg_match('#/sub/([^/]+)/#', $sub['sub_url'], $m)) {
    $token = $m[1];
}
echo "base=$base token=$token\n";

$path = "/get_subpage_download_data/$token/$username";
$r = eylan_http_get_raw($base . $path, $panel);
echo "download_data http=" . ($r['code']??0) . " len=" . strlen($r['body']??'') . "\n";
$j = json_decode($r['body'] ?? '', true);
$urls = [];
if (is_array($j)) {
    $stack = [$j];
    while ($stack) {
        $n = array_pop($stack);
        if (!is_array($n)) continue;
        foreach ($n as $k => $v) {
            if ($k === 'download_url' && is_string($v)) {
                $u = str_starts_with($v, 'http') ? $v : $base . $v;
                $urls[] = $u;
                echo "  URL $u\n";
            } elseif (is_array($v)) {
                $stack[] = $v;
            }
        }
    }
}

foreach ($urls as $u) {
    $body = eylan_http_get_body($u, $panel);
    $ok = $body !== '' && strpos($body, 'PrivateKey') !== false;
    $ep = preg_match('/Endpoint\s*=\s*(\S+)/i', $body, $m) ? $m[1] : '-';
    echo "  DL ok=" . ($ok?'yes':'no') . " endpoint=$ep len=" . strlen($body) . "\n";
}

$files = eylan_get_wg_all_files($namepanel, $username, is_array($user)?$user:null);
echo "collected_count=" . count($files) . "\n";
foreach ($files as $i => $item) {
    $b = str_starts_with($item,'RAWCFG:') ? substr($item,7) : $item;
    $ep = preg_match('/Endpoint\s*=\s*(\S+)/i', $b, $m) ? $m[1] : '?';
    echo "  [$i] endpoint=$ep len=" . strlen($b) . "\n";
}
echo "Done.\n";
