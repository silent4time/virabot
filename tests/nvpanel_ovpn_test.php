<?php
declare(strict_types=1);
// Offline test. Every key, certificate and passphrase here is generated at run time and thrown away.
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require_once dirname(__DIR__) . '/patch/nvpanel.php';
require_once dirname(__DIR__) . '/patch/nvpanel_ovpn_import.php';

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

function rmtree(string $d): void
{
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) {
        is_dir("$d/$f") ? rmtree("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
}

// ---- dummy PKI
$cnf = tempnam(sys_get_temp_dir(), 'vbossl');
file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n");
$cfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'config' => $cnf];
$caKey = openssl_pkey_new($cfg);
$caCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test CA'], $caKey, $cfg), null, $caKey, 1, $cfg);
openssl_x509_export($caCert, $caPem);
$clKey = openssl_pkey_new($cfg);
$clCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'client'], $clKey, $cfg), $caCert, $caKey, 1, $cfg);
openssl_x509_export($clCert, $clPem);
$testPass = bin2hex(random_bytes(12));
openssl_pkey_export($clKey, $encKey, $testPass, $cfg);
$otherKey = openssl_pkey_new($cfg);
openssl_pkey_export($otherKey, $otherEnc, $testPass, $cfg);
check(str_contains($encKey, 'ENCRYPTED PRIVATE KEY'), 'fixture key is encrypted PKCS#8');

function seed_profile(string $remote, string $key, string $ca, string $cert, string $extra = ''): string
{
    return "client\nremote {$remote}\ncipher AES-128-CBC\nauth SHA1\n{$extra}auth-user-pass\nconnect-retry 3\nredirect-gateway def1\n<ca>\n{$ca}</ca>\n<cert>\n{$cert}</cert>\n<key>\n{$key}</key>\n";
}

// ---- grouping (ViraNet rules)
check(nvpanel_ovpn_group('19') === 'mr' && nvpanel_ovpn_files_for('19') === ['new-1', 'new-2'], 'product 19 -> mr, 2 files');
check(count(nvpanel_ovpn_files_for('11')) === 7 && nvpanel_ovpn_files_for('11')[6] === 's7', 'product 11 -> 7 files s1..s7');
check(count(nvpanel_ovpn_files_for('23')) === 7, 'legacy OpenVPN product -> 7 files');
foreach (['59', '61', '66', '67', '68', '69', '73'] as $wg) {
    check(nvpanel_ovpn_group($wg) === 'wireguard' && nvpanel_ovpn_files_for($wg) === [], "WireGuard product $wg -> no file");
}
check(nvpanel_ovpn_files_for('') === [] && nvpanel_ovpn_files_for('x y') === [], 'unknown/invalid product -> no file');

// ---- credentials
check(nvpanel_ovpn_cred_ok('nv12345') && nvpanel_ovpn_cred_ok('p@ss-W0rd!'), 'normal credentials accepted');
foreach (['', "a\nb", "a\rb", "ab\r\n", "a\0b", "a\tb", '</auth-user-pass>', 'x</y', ' lead', 'trail ', str_repeat('a', 257)] as $bad) {
    check(!nvpanel_ovpn_cred_ok($bad), 'credential rejected: ' . json_encode($bad));
}

// ---- import: decrypt, verify, strip auth-nocache
$raw = seed_profile('vpn.test.invalid 1370 tcp', $encKey, $caPem, $clPem, "auth-nocache\n");
$prep = nvpanel_ovpn_imp_prepare($raw, $testPass);
check($prep['ok'] && $prep['nocache'], 'import prepares the template and notes auth-nocache');
check(!str_contains($prep['tpl'], 'ENCRYPTED') && str_contains($prep['tpl'], 'BEGIN PRIVATE KEY'), 'stored key is decrypted PKCS#8');
check(!str_contains($prep['tpl'], 'auth-nocache'), 'auth-nocache removed from the stored template');
check(!str_contains($prep['tpl'], $testPass), 'passphrase not in the stored template');
check(nvpanel_ovpn_imp_prepare($raw, 'wrong-' . $testPass)['err'] === 'bad_passphrase', 'wrong passphrase rejected');
check(nvpanel_ovpn_imp_prepare(seed_profile('h 1 tcp', $otherEnc, $caPem, $clPem), $testPass)['err'] === 'key_cert_mismatch', 'key that does not match the cert rejected');
check(!nvpanel_ovpn_imp_prepare(str_replace("auth-user-pass\n", '', $raw), $testPass)['ok'], 'template without auth-user-pass rejected');
check(!nvpanel_ovpn_template_ok($raw), 'encrypted template is never used for building');

// ---- build
$tpl = $prep['tpl'];
$out = nvpanel_ovpn_build($tpl, 'nv12345', 'p@ss');
check(is_string($out) && str_contains($out, "<auth-user-pass>\nnv12345\np@ss\n</auth-user-pass>"), 'inline block inserted');
check(is_string($out) && preg_match('/^\s*auth-user-pass\s*$/m', $out) === 0, 'plain auth-user-pass line replaced');
check(is_string($out) && substr_count($out, '<auth-user-pass>') === 1, 'exactly one block');
check(is_string($out) && !str_contains($out, 'auth-nocache'), 'no auth-nocache in the built file');
check(is_string($out) && strpos($out, '<auth-user-pass>') < strpos($out, '<ca>'), 'block sits where the directive was');
check(nvpanel_ovpn_build($tpl, "nv1\n", 'p') === null && nvpanel_ovpn_build($tpl, 'nv1', "p\r") === null, 'CR/LF credentials: nothing built');
check(nvpanel_ovpn_build($tpl, '', 'p') === null && nvpanel_ovpn_build($tpl, 'u', '') === null, 'empty credentials: nothing built');
check(nvpanel_ovpn_build($tpl, 'u', '</auth-user-pass>') === null, 'closing tag as password: nothing built');
check(nvpanel_ovpn_build($out, 'u', 'p') === null, 'already personalised file is not a template');
check(nvpanel_ovpn_build(str_replace('auth-user-pass', 'auth-user-pass /etc/x', $tpl), 'u', 'p') === null, 'auth-user-pass with a file is not a template');

// ---- source folder (ViraNet seed names) -> plan, s7 merge, write
$src = sys_get_temp_dir() . '/vbovpn-src-' . bin2hex(random_bytes(4));
$dst = sys_get_temp_dir() . '/vbovpn-dst-' . bin2hex(random_bytes(4));
mkdir($src, 0700);
file_put_contents("$src/new-1.ovpn", seed_profile('mr.test.invalid 1370 tcp', $encKey, $caPem, $clPem));
file_put_contents("$src/new-2.ovpn", seed_profile('mr.test.invalid 1360 udp', $encKey, $caPem, $clPem));
for ($i = 1; $i <= 6; $i++) {
    file_put_contents("$src/old-$i.ovpn", seed_profile("s$i.test.invalid 1370 tcp", $encKey, $caPem, $clPem));
}
file_put_contents("$src/old-7.ovpn", seed_profile('s7.test.invalid 71 udp ', $encKey, $caPem, $clPem));
file_put_contents("$src/old-8.ovpn", seed_profile('s7.test.invalid 70 tcp', $encKey, $caPem, $clPem));
$plan = nvpanel_ovpn_imp_plan($src);
check(array_keys($plan) === ['mr', 's1s7'] && count($plan['mr']) === 2 && count($plan['s1s7']) === 7, 'plan: mr 2 files, s1s7 7 files');
check(count($plan['s1s7']['s7']) === 2 && str_ends_with($plan['s1s7']['s7'][0], 'old-7.ovpn'), 's7 is built from old-7 then old-8');
$built = nvpanel_ovpn_imp_build($plan, $testPass);
check($built['ok'] && count($built['files']) === 9, 'import builds 9 templates');
$s7 = $built['files']['s7'] ?? '';
$r71 = strpos($s7, 'remote s7.test.invalid 71 udp');
$r70 = strpos($s7, 'remote s7.test.invalid 70 tcp');
check($r71 !== false && $r70 !== false && $r71 < $r70 && substr_count($s7, "\nremote ") === 2, 's7: one file, UDP 71 first then TCP 70');
check(nvpanel_ovpn_template_ok($s7) && substr_count($s7, '<key>') === 1, 's7 is a valid single template');
check(!nvpanel_ovpn_imp_build($plan, 'nope')['ok'], 'wrong passphrase: whole import refused');
file_put_contents("$src/old-8.ovpn", seed_profile('s7.test.invalid 70 tcp', $encKey, $caPem, $clPem, "comp-lzo\n"));
check(!nvpanel_ovpn_imp_build(nvpanel_ovpn_imp_plan($src), $testPass)['ok'], 's7 halves with different settings are not merged');
check(nvpanel_ovpn_imp_write($dst, $built['files'], null), 'templates written');
check((fileperms($dst) & 0777) === 0750 && (fileperms("$dst/s1.ovpn") & 0777) === 0640, 'dir 0750, files 0640');
check(glob("$dst/.*.*") === [] || count(array_filter(glob("$dst/.*") ?: [], 'is_file')) === 0, 'no temp files left');
$ready = nvpanel_ovpn_ready($dst);
check($ready === ['mr' => true, 's1s7' => true], 'both groups ready');
check(nvpanel_ovpn_ready($src . '-missing') === ['mr' => false, 's1s7' => false], 'missing folder: nothing ready');

// ---- delivery
$GLOBALS['nvpanel_ovpn_dir_override'] = $dst;
$sent = [];
$admin = [];
$GLOBALS['nvpanel_ovpn_sender'] = function ($chat, $name, $body) use (&$sent) {
    $sent[] = ['chat' => $chat, 'name' => $name, 'body' => $body];
    return true;
};
$GLOBALS['nvpanel_ovpn_admin_notify'] = function ($t) use (&$admin) {
    $admin[] = $t;
};
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo'] = $pdo;
$pdo->exec('CREATE TABLE setting (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO setting (id) VALUES (1)');
check(!nvpanel_ovpn_enabled($pdo), 'off when the setting column does not exist yet');
check(nvpanel_ovpn_deliver($pdo, 'k0', 'create', '42', 'inv0', 'nv1', 'pw', '19') === 'off' && $sent === [], 'off: nothing sent');
check(nvpanel_ovpn_set_enabled($pdo, true) && nvpanel_ovpn_enabled($pdo), 'admin toggle adds the column and turns it on');

check(nvpanel_ovpn_deliver($pdo, 'k1', 'create', '42', 'inv1', 'nv12345', 'p@ss', '19') === 'sent', 'mr create delivered');
check(count($sent) === 2 && $sent[0]['name'] === 'nv12345-new-1.ovpn' && $sent[1]['name'] === 'nv12345-new-2.ovpn', 'mr: new-1 and new-2 sent');
check($sent[0]['chat'] === '42' && str_contains($sent[0]['body'], "<auth-user-pass>\nnv12345\np@ss\n</auth-user-pass>"), 'sent to the buyer with their own credentials');
check(nvpanel_ovpn_deliver($pdo, 'k1', 'create', '42', 'inv1', 'nv12345', 'p@ss', '19') === 'dup' && count($sent) === 2, 'same operation is never sent twice');
$sent = [];
check(nvpanel_ovpn_deliver($pdo, 'k2', 'create', '43', 'inv2', 'nv2', 'pw2', '11') === 'sent' && count($sent) === 7, 'OpenVPN product: 7 files');
check($sent[6]['name'] === 'nv2-s7.ovpn', 'last file is s7');
$sent = [];
check(nvpanel_ovpn_deliver($pdo, 'k3', 'create', '44', 'inv3', 'nv3', 'pw3', '61') === 'wireguard' && $sent === [], 'WireGuard: no file');
check(nvpanel_ovpn_deliver($pdo, 'k4', 'create', '44', 'invX4', "nv4\n", 'pw', '19') === 'bad_credentials' && $sent === [], 'LF in username: nothing sent');
check(count($admin) === 1 && !str_contains($admin[0], 'nv4') && !str_contains($admin[0], 'pw'), 'admin told, without the credentials');
$GLOBALS['nvpanel_ovpn_dir_override'] = $src . '-missing';
check(nvpanel_ovpn_deliver($pdo, 'k5', 'create', '45', 'inv5', 'nv5', 'pw5', '19') === 'no_templates' && $sent === [], 'templates missing: skipped quietly');
$GLOBALS['nvpanel_ovpn_dir_override'] = $dst;

// ---- create + renew through the real NV Panel code paths
$replies = [];
$GLOBALS['nvpanel_transport'] = function () use (&$replies) {
    return array_shift($replies) ?? ['status' => 0, 'body' => '', 'error' => 'timeout'];
};
$domainhosts = 'bot.test.invalid';
$pdo->exec('CREATE TABLE invoice (id_invoice TEXT PRIMARY KEY, id_user TEXT, username TEXT, Service_location TEXT, time_sell TEXT, name_product TEXT, price_product TEXT, Volume TEXT, Service_time TEXT, Status TEXT)');
$pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY, code_product TEXT, name_product TEXT, price_product TEXT, Location TEXT, nv_product_id TEXT)');
$pdo->exec("INSERT INTO product VALUES (74,'c716','OpenVpn','400000','NV Panel','19')");
$pdo->exec("INSERT INTO invoice VALUES ('inv9','77','bot_user_9','NV Panel','1794600000','OpenVpn','400000','0','30','unpaid')");
$panel = ['name_panel' => 'NV Panel', 'type' => 'nvpanel', 'status' => 'active', 'code_panel' => 'a766',
    'url_panel' => 'https://gw.test.invalid', 'password_panel' => 'tok_abcdefgh123'];
$sent = [];
$replies[] = ['status' => 200, 'body' => json_encode(['ok' => true, 'status' => 'success', 'op_id' => 901, 'username' => 'nv999', 'password' => 'pw999']), 'error' => ''];
$o = nvpanel_purchase($panel, 'c716', 'bot_user_9', ['from_id' => '77']);
check(($o['username'] ?? '') === 'nv999' && $sent === [], 'purchase queues files (nothing before the credentials message)');
nvpanel_ovpn_flush();
check(count($sent) === 2 && $sent[0]['chat'] === '77' && str_contains($sent[1]['body'], "\nnv999\npw999\n"), 'after the request: c716 (NV 19) buyer gets new-1/new-2');
$sent = [];
$replies[] = ['status' => 200, 'body' => json_encode(['ok' => true, 'status' => 'success', 'op_id' => 902, 'username' => 'nv999']), 'error' => ''];
$r = nvpanel_renew($panel, 'nv999', 'inv9', 'auto');
nvpanel_ovpn_flush();
check(($r['status'] ?? false) === true && count($sent) === 2 && str_contains($sent[0]['body'], "\nnv999\npw999\n"), 'renew: files rebuilt with the password from the create op');
$msg = nvpanel_message('nv999', 'pw999', 'فعال', null);
check(str_contains($msg, 'فایل‌های اتصال') && !str_contains($msg, 'فایل کانفیگ جدا نمی‌دهد'), 'credentials message mentions the files when on');
$txt = nvpanel_ovpn_user_text();
check(str_contains($txt, 'شخصی') && str_contains($txt, 'OpenVPN Connect') && str_contains($txt, 'OpenVPN for Android') && str_contains($txt, '۲٫۶'), 'user warning: personal file + compatible clients');
nvpanel_ovpn_set_enabled($pdo, false);
check(!nvpanel_ovpn_enabled($pdo) && str_contains(nvpanel_message('a', 'b', 'فعال', null), 'فایل کانفیگ جدا نمی‌دهد'), 'toggle off restores the old text');

// ---- no secrets in the repo
$repo = dirname(__DIR__);
$hits = trim((string) shell_exec('grep -rlE "BEGIN (ENCRYPTED |RSA )?PRIVATE KEY-----[A-Za-z0-9+/=\n]{40}" ' . escapeshellarg($repo . '/patch') . ' 2>/dev/null'));
check($hits === '', 'no private key material under patch/');
check(!is_dir($repo . '/patch/ovpn') && glob($repo . '/patch/*.ovpn') === [], 'no .ovpn template in the repo');

rmtree($src);
rmtree($dst);
@unlink($cnf);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
