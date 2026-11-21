<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/patch/card_payment.php';

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

$tz = 'Asia/Tehran';
// 1405/08/30 16:30:00 Tehran = 2026-11-21 13:00:00 UTC
$now = (new DateTime('2026-11-21 16:30:00', new DateTimeZone($tz)))->getTimestamp();

// ---- 1) copied amount equals the toman amount shown in the message
check(vb_cardpay_copy_amount('180000.0') === '180000', 'decimal string copies integer toman');
foreach (['400000', 400000, '۴۰۰۰۰۰', '400,000'] as $v) {
    check(vb_cardpay_copy_amount($v) === '400000', 'copy amount is toman, not x10: ' . var_export($v, true));
}
$shown = number_format((float) '250000');
check(str_replace(',', '', $shown) === vb_cardpay_copy_amount('250000'), 'copy amount equals number_format shown in message');
check(vb_cardpay_copy_amount('') === '0' && vb_cardpay_copy_amount('abc') === '0', 'non-numeric amount copies 0, never a guess');
$index = file_get_contents(dirname(__DIR__) . '/patch/index.php');
check(strpos($index, "intval(\$user['Processing_value'] . \"0\")") === false, 'patch/index.php no longer copies amount . "0"');
$lang = file_get_contents(dirname(__DIR__) . '/patch/lang-fa.php');
check(strpos($lang, "'copyAmount' => 'کپی مبلغ (تومان)'") !== false, 'copy button says تومان');

// ---- time parsing: Jalali (bot_date) and Gregorian rows
check(vb_cardpay_parse_time('1405/08/30 16:30:00', $tz) === $now, 'jalali time parsed to the right instant');
check(vb_cardpay_parse_time('2026/11/21 16:30:00', $tz) === $now, 'gregorian time parsed');
check(vb_cardpay_parse_time('۱۴۰۵/۰۸/۳۰ ۱۶:۳۰:۰۰', $tz) === $now, 'persian digits parsed');
check(vb_cardpay_parse_time('', $tz) === null && vb_cardpay_parse_time('garbage', $tz) === null, 'unreadable time -> null');
check(vb_cardpay_j2g(1405, 1, 1) === [2026, 3, 21] && vb_cardpay_j2g(1403, 12, 30) === [2025, 3, 20], 'jalali->gregorian incl. leap Esfand');

// ---- schema: same columns as production Payment_report (+ remind_sent)
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE Payment_report (id INTEGER PRIMARY KEY AUTOINCREMENT, id_user TEXT, id_order TEXT, time TEXT, at_updated TEXT, price TEXT, dec_not_confirmed TEXT, Payment_Method TEXT, payment_Status TEXT, bottype TEXT, message_id INT, id_invoice TEXT)');
$ins = $pdo->prepare('INSERT INTO Payment_report (id_user,id_order,time,at_updated,price,Payment_Method,payment_Status,id_invoice) VALUES (?,?,?,?,?,?,?,?)');
$rows = [
    // user 42
    ['42', 'u_fresh', '1405/08/30 16:00:00', null, '400000', 'cart to cart', 'Unpaid', 'x|y'],        // 30 min old
    ['42', 'u_old', '1405/08/29 15:00:00', null, '400000', 'cart to cart', 'Unpaid', 'x|y'],          // 25.5 h old
    ['42', 'w_old', '1405/08/20 10:00:00', '1405/08/20 10:05:00', '400000', 'cart to cart', 'waiting', 'x|y'], // 10 days waiting
    ['42', 'w_new', '1405/08/30 15:00:00', '1405/08/30 15:10:00', '180000', 'cart to cart', 'waiting', 'x|y'], // 80 min waiting
    ['42', 'p1', '1405/08/01 10:00:00', '1405/08/01 10:10:00', '400000', 'cart to cart', 'paid', 'x|y'],
    // user 7: same price, must not affect 42
    ['7', 'o7', '1405/08/30 16:10:00', null, '400000', 'cart to cart', 'Unpaid', 'x|y'],
    ['7', 'w7', '1405/08/30 12:00:00', '1405/08/30 12:00:00', '400000', 'cart to cart', 'waiting', 'x|y'],
    ['9', 'g9', '2026/11/19 10:00:00', null, '50000', 'balepay', 'Unpaid', 'x|y'],                    // gregorian, 2+ days
    ['9', 'bad', 'not-a-date', null, '50000', 'cart to cart', 'Unpaid', 'x|y'],
];
foreach ($rows as $r) {
    $ins->execute($r);
}
$status = function (string $oid) use ($pdo): string {
    $st = $pdo->prepare('SELECT payment_Status FROM Payment_report WHERE id_order = ?');
    $st->execute([$oid]);
    return (string) $st->fetchColumn();
};

// ---- 2) duplicate pending invoice is detected per user (id_user, not id)
$p = vb_cardpay_find_pending($pdo, '42', '180000', $now, $tz);
check(count($p['unpaid']) === 2 && $p['unpaid'][0]['id_user'] === '42', 'unpaid invoices of user 42 found by id_user');
check(count($p['waiting_same']) === 1 && $p['waiting_same'][0]['id_order'] === 'w_new', 'same-amount waiting receipt blocks a duplicate');
$p = vb_cardpay_find_pending($pdo, '42', '400000', $now, $tz);
check(count($p['waiting_same']) === 0, 'waiting receipt older than block window does not block forever');
$p = vb_cardpay_find_pending($pdo, '42', '250000', $now, $tz);
check(count($p['waiting_same']) === 0, 'different amount is not a duplicate');
$p = vb_cardpay_find_pending($pdo, '1', '400000', $now, $tz);
check($p['unpaid'] === [] && $p['waiting_same'] === [], 'user id 1 does not match row id 1 (old id bug)');
$p = vb_cardpay_find_pending($pdo, '7', '400000', $now, $tz);
check(count($p['unpaid']) === 1 && $p['unpaid'][0]['id_order'] === 'o7' && count($p['waiting_same']) === 1, 'user 7 sees only own rows');

// ---- 3a) Unpaid expiry after 24h only
$hooked = [];
$n = vb_cardpay_expire_unpaid($pdo, $now, VB_CARDPAY_UNPAID_TTL, $tz, function ($row) use (&$hooked) {
    $hooked[] = $row['id_order'];
});
check($n === 2 && $status('u_old') === 'expire' && $status('g9') === 'expire', 'unpaid older than 24h expired (jalali and gregorian)');
check($status('u_fresh') === 'Unpaid' && $status('o7') === 'Unpaid', '30-minute-old unpaid is NOT expired (old bug expired it within 5 min)');
check($status('bad') === 'Unpaid', 'unreadable time is never expired');
check($hooked === ['u_old', 'g9'], 'message-delete hook runs once per expired row');
check(vb_cardpay_expire_unpaid($pdo, $now, VB_CARDPAY_UNPAID_TTL, $tz) === 0, 'second run expires nothing more');

// ---- 3b) waiting rows are never expired or rejected, even far in the future
vb_cardpay_expire_unpaid($pdo, $now + 86400 * 365, VB_CARDPAY_UNPAID_TTL, $tz);
check($status('w_old') === 'waiting' && $status('w_new') === 'waiting' && $status('w7') === 'waiting', 'waiting receipts never expired');
check($status('p1') === 'paid', 'paid untouched');
// late admin confirm still possible: status still waiting, so the admin.php confirm path (no time check) applies
check($pdo->query("SELECT COUNT(*) FROM Payment_report WHERE payment_Status IN ('reject','expire') AND id_order LIKE 'w%'")->fetchColumn() == 0, 'no waiting row became reject/expire');

// ---- 3c) reminder: skipped without the column, then once per receipt
$sent = [];
$send = function (string $text, array $due) use (&$sent) {
    $sent[] = [$text, array_column($due, 'id_order')];
    return true;
};
check(vb_cardpay_waiting_reminders($pdo, $now, $send) === 0 && $sent === [], 'no remind_sent column -> no reminder, no error');
$pdo->exec('ALTER TABLE Payment_report ADD remind_sent TEXT NULL');
check(vb_cardpay_has_column($pdo, 'Payment_report', 'remind_sent'), 'column detected');
$n = vb_cardpay_waiting_reminders($pdo, $now, $send);
check($n === 2 && count($sent) === 1, 'one message for all due receipts');
sort($sent[0][1]);
check($sent[0][1] === ['w7', 'w_old'], 'only receipts waiting >2h (w_new is 80 min) are reminded');
check(strpos($sent[0][0], 'w_old') !== false && strpos($sent[0][0], '400,000 تومان') !== false, 'reminder lists code and toman amount');
check(vb_cardpay_waiting_reminders($pdo, $now + 600, $send) === 0 && count($sent) === 1, 'idempotent: no second reminder for same receipts');
$n = vb_cardpay_waiting_reminders($pdo, $now + 3 * 3600, $send);
check($n === 1 && count($sent) === 2 && $sent[1][1] === ['w_new'], 'later only the newly due receipt is reminded');
check($status('w_old') === 'waiting' && $status('w_new') === 'waiting', 'reminder never changes payment status');
// failed send is not marked, so it retries next run
$ins->execute(['5', 'w5', '1405/08/30 10:00:00', '1405/08/30 10:00:00', '90000', 'cart to cart', 'waiting', 'x|y']);
$n = vb_cardpay_waiting_reminders($pdo, $now, function () { return false; });
check($n === 0 && (string) $pdo->query("SELECT remind_sent FROM Payment_report WHERE id_order='w5'")->fetchColumn() === '', 'failed send leaves receipt unmarked');
check(vb_cardpay_waiting_reminders($pdo, $now, $send) === 1, 'retried after failure');

// ---- a receipt sent while the cron is running is never expired (guarded UPDATE)
$ins->execute(['8', 'race', '1405/08/28 10:00:00', null, '100000', 'cart to cart', 'Unpaid', 'x|y']);
$n = vb_cardpay_expire_unpaid($pdo, $now, VB_CARDPAY_UNPAID_TTL, $tz, null);
check($n === 1 && $status('race') === 'expire', 'old unpaid expires');
$ins->execute(['8', 'race2', '1405/08/28 10:00:00', null, '100000', 'cart to cart', 'Unpaid', 'x|y']);
$pdo->exec("UPDATE Payment_report SET payment_Status='waiting' WHERE id_order='race2'");
vb_cardpay_expire_unpaid($pdo, $now, VB_CARDPAY_UNPAID_TTL, $tz, null);
check($status('race2') === 'waiting', 'row that became waiting is not expired');

// ---- cart text and cron wiring
check(strpos($lang, 'این تراکنش به مدت ۲۴ ساعت اعتبار دارد پس از آن امکان پرداخت') !== false, 'card text states 24 hours');
$cron = file_get_contents(dirname(__DIR__) . '/patch/cronbot/payment_expire.php');
check(strpos($cron, 'vb_cardpay_expire_unpaid') !== false && strpos($cron, 'vb_cardpay_waiting_reminders') !== false && strpos($cron, "payment_Status\",\"expire\"") === false, 'cron uses the safe helpers');
$table = file_get_contents(dirname(__DIR__) . '/patch/table.php');
check(strpos($table, "SHOW COLUMNS FROM Payment_report LIKE 'remind_sent'") !== false, 'table.php adds remind_sent with a guarded ALTER');
check(strpos($table, "['autoconfirmcart', 'offauto']") !== false, 'autoconfirmcart default stays off');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
