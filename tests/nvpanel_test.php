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

$req = ['ref_id' => 'vb:abc:1:create', 'product' => '19'];
check(nvpanel_step([], $req, false)['step'] === 'post', 'first purchase posts once');
$success = ['state' => 'success', 'request_json' => nvpanel_canonical($req), 'delivered' => 1, 'op_id' => 101];
check(nvpanel_step($success, $req, true)['step'] === 'done', 'success replay does not post');
$review = ['state' => 'needs_review', 'request_json' => nvpanel_canonical($req), 'op_id' => 101, 'delivered' => 0];
$step = nvpanel_step($review, $req, true);
check($step['step'] === 'poll' && $step['refund'] === false, 'needs_review polls and does not refund');
$pending = ['state' => 'pending', 'request_json' => nvpanel_canonical($req), 'op_id' => 101];
check(nvpanel_step($pending, $req, true)['refund'] === false, 'pending does not refund');
$unknown = ['state' => 'unknown', 'request_json' => nvpanel_canonical($req), 'op_id' => null];
check(nvpanel_step($unknown, $req, true)['step'] === 'wait', 'unknown does not post a new purchase');
$failed = ['state' => 'failed', 'request_json' => nvpanel_canonical($req), 'refund_ok' => 0];
check(nvpanel_step($failed, $req, true)['step'] === 'stop', 'failed key is not auto-posted');
$other = $req;
$other['product'] = '20';
check(nvpanel_step($success, $other, false)['step'] === 'conflict', 'changed params do not reuse the key');

$ok = nvpanel_classify(200, ['ok' => true, 'status' => 'success', 'op_id' => 101, 'username' => 'EXAMPLE_USER', 'password' => 'EXAMPLE_PASSWORD', 'expire_at' => null]);
check($ok['deliver'] && !$ok['refund'], 'success delivers once');
$pend = nvpanel_classify(202, ['ok' => false, 'status' => 'pending', 'code' => 'in_progress', 'op_id' => 101]);
check($pend['state'] === 'pending' && !$pend['refund'], '202 pending is not a refund');
$nr = nvpanel_classify(202, ['ok' => false, 'status' => 'needs_review', 'code' => 'needs_review', 'op_id' => 101]);
check($nr['state'] === 'needs_review' && !$nr['refund'], 'needs_review is not a refund');
$res = nvpanel_classify(200, ['ok' => true, 'status' => 'resolved', 'op_id' => 101, 'username' => 'EXAMPLE_USER']);
check($res['deliver'] === true, 'resolved success delivers once');
$amb = nvpanel_classify(409, ['ok' => false, 'status' => 'resolved', 'code' => 'resolved', 'op_id' => 101]);
check($amb['state'] === 'needs_review' && !$amb['refund'], 'resolved without username is manual');
check(nvpanel_classify(402, ['ok' => false, 'status' => 'failed', 'code' => 'insufficient_balance', 'op_id' => 9])['refund'] === true, 'definitive insufficient balance can refund the customer');
check(str_contains(nvpanel_expiry_text(null, null), 'منقضی'), 'null expiry is not treated as expired');
check(nvpanel_product_ok('19') && !nvpanel_product_ok('[19,20]') && !nvpanel_product_ok(''), 'malformed product id rejected');
check(!nvpanel_base_ok('http://panel.test') && !nvpanel_base_ok(''), 'gateway URL must be https and explicit');
$low = nvpanel_balance_alert([], 999999, 1000);
check($low['kind'] === 'low_balance', 'cached balance under 1000000 alerts once');
$again = nvpanel_balance_alert($low['state'], 999999, 1000 + 10);
check($again['kind'] === null, 'reminder waits');
$up = nvpanel_balance_alert($low['state'], 1000000, 5000);
check($up['kind'] === 'balance_recovered', 'recovery at threshold');
$null = nvpanel_balance_alert([], null, 1000);
check($null['kind'] === null, 'null balance does not alert');
check(!nvpanel_configured(['type' => 'nvpanel', 'url_panel' => '', 'password_panel' => '']), 'empty token stays disabled');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
