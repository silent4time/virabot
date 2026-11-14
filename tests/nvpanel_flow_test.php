<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/patch/nvpanel.php';

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

$calls = [];
$replies = [];
$GLOBALS['nvpanel_transport'] = function ($method, $url, $headers, $body, $timeout, $hosts) {
    global $calls, $replies;
    $calls[] = ['m' => $method, 'path' => parse_url($url, PHP_URL_PATH), 'body' => json_decode($body, true)];
    return array_shift($replies) ?? ['status' => 0, 'body' => '', 'error' => 'timeout'];
};

$domainhosts = 'bot.test.invalid';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Same columns as production `invoice`: primary key id_invoice, no `id` column.
$pdo->exec('CREATE TABLE invoice (id_invoice TEXT PRIMARY KEY, id_user TEXT, username TEXT, Service_location TEXT, time_sell TEXT, name_product TEXT, price_product TEXT, Volume TEXT, Service_time TEXT, Status TEXT)');
$pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY, code_product TEXT, name_product TEXT, price_product TEXT, Location TEXT, nv_product_id TEXT)');
$pdo->exec("INSERT INTO product VALUES (74,'c716','OpenVpn','400000','NV Panel','19')");
$panel = ['name_panel' => 'NV Panel', 'type' => 'nvpanel', 'status' => 'active', 'code_panel' => 'a766',
    'url_panel' => 'https://gw.test.invalid', 'password_panel' => 'tok_abcdefgh123'];

// ---- purchase (wallet flow: invoice inserted unpaid with the bot's own username first)
$pdo->exec("INSERT INTO invoice VALUES ('inv1','42','bot_user_1','NV Panel','1794600000','OpenVpn','400000','0','30','unpaid')");
$pdo->exec("INSERT INTO invoice VALUES ('old0','42','bot_user_1','Other','1794500000','x','1','0','30','active')");
$replies[] = ['status' => 200, 'body' => json_encode(['ok' => true, 'status' => 'success', 'op_id' => 501, 'username' => 'nv12345', 'password' => 'p@ss', 'expire_at' => null, 'product_id' => '19']), 'error' => ''];
$out = nvpanel_purchase($panel, 'c716', 'bot_user_1', ['from_id' => '42']);
check(($out['status'] ?? '') === 'successful' && $out['username'] === 'nv12345' && $out['_nv_password'] === 'p@ss', 'purchase success returns gateway username/password');
check(count($calls) === 1 && $calls[0]['m'] === 'POST' && $calls[0]['path'] === '/create', 'exactly one POST /create');
check(($calls[0]['body']['product'] ?? '') === '19' && str_ends_with((string) $calls[0]['body']['ref_id'], ':inv1:create'), 'product 19 and idempotency key bound to invoice');
$inv = $pdo->query("SELECT username, Status FROM invoice WHERE id_invoice='inv1'")->fetch(PDO::FETCH_ASSOC);
check($inv['username'] === 'nv12345' && $inv['Status'] === 'active', 'invoice renamed to gateway username and active');
check($pdo->query("SELECT Status FROM invoice WHERE id_invoice='old0'")->fetchColumn() === 'active', 'other-panel invoice untouched');
$op = $pdo->query("SELECT * FROM nvpanel_ops")->fetch(PDO::FETCH_ASSOC);
check((int) $op['op_id'] === 501 && $op['state'] === 'success' && (int) $op['delivered'] === 1 && $op['invoice_id'] === 'inv1' && $op['customer_id'] === '42', 'nvpanel_ops row success+delivered');

// repeat (e.g. retry of the same invoice) must not POST again
$calls = [];
$pdo->exec("UPDATE invoice SET username='bot_user_1' WHERE id_invoice='inv1'");
$out = nvpanel_purchase($panel, 'c716', 'bot_user_1', ['from_id' => '42']);
check(count($calls) === 0 && ($out['status'] ?? '') === 'successful', 'same invoice: no second create');
$pdo->exec("UPDATE invoice SET username='nv12345' WHERE id_invoice='inv1'");

// pending -> hold, then a later call polls /op instead of posting
$pdo->exec("INSERT INTO invoice VALUES ('inv2','42','bot_user_2','NV Panel','1794600100','OpenVpn','400000','0','30','unpaid')");
$calls = [];
$replies = [['status' => 202, 'body' => json_encode(['ok' => false, 'status' => 'pending', 'code' => 'in_progress', 'op_id' => 502]), 'error' => '']];
$out = nvpanel_purchase($panel, 'c716', 'bot_user_2', []);
check(!empty($out['_nvpanel_hold']) && empty($out['username']), 'pending is a hold, no username');
check($pdo->query("SELECT Status FROM invoice WHERE id_invoice='inv2'")->fetchColumn() === 'unpaid', 'pending invoice stays unpaid');
$replies = [['status' => 200, 'body' => json_encode(['ok' => true, 'status' => 'success', 'op_id' => 502, 'username' => 'nv777', 'password' => 'x']), 'error' => '']];
$out = nvpanel_purchase($panel, 'c716', 'bot_user_2', []);
check($calls[1]['m'] === 'GET' && $calls[1]['path'] === '/op/502', 'second call polls /op/502, no new POST');
check(($out['username'] ?? '') === 'nv777', 'poll success delivers');

// disabled panel never posts
$calls = [];
$out = nvpanel_purchase(['status' => 'disable'] + $panel, 'c716', 'bot_user_2', []);
check(count($calls) === 0 && $out['status'] === 'Unsuccessful', 'disabled panel: no request');

// ---- renew
$calls = [];
$replies = [['status' => 200, 'body' => json_encode(['ok' => true, 'status' => 'success', 'op_id' => 601, 'username' => 'nv12345', 'expire_at' => '2026-12-14T10:00:00+03:30']), 'error' => '']];
$r = nvpanel_renew($panel, 'nv12345', 'inv1', 'auto');
check(($r['status'] ?? false) === true && $r['username'] === 'nv12345', 'renew success');
check(count($calls) === 1 && $calls[0]['m'] === 'POST' && $calls[0]['path'] === '/renew' && $calls[0]['body']['username'] === 'nv12345', 'one POST /renew with username');
check(str_contains((string) $calls[0]['body']['ref_id'], ':inv1:renew:pay:'), 'renew key bound to invoice and a fresh order key');
check((int) $pdo->query("SELECT COUNT(*) FROM nvpanel_ops WHERE action='renew' AND state='success'")->fetchColumn() === 1, 'renew op row');
$calls = [];
check(nvpanel_renew($panel, 'nv12345', 'inv2', 'auto')['status'] === false && count($calls) === 0, 'renew refuses invoice of another service');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
