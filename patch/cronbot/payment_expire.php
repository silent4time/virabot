<?php
/**
 * انقضای فاکتورهای پرداخت‌نشده + یادآوری رسیدهای منتظر (۵٫۸٫۵).
 *
 * قبلاً زمان شمسی ذخیره‌شده (1405/...) با تاریخ میلادی (2026/...) به‌صورت رشته مقایسه می‌شد،
 * پس هر فاکتور Unpaid در اولین اجرای کرون (حداکثر ۵ دقیقه) منقضی می‌شد.
 * حالا زمان درست خوانده می‌شود و فقط Unpaid بعد از ۲۴ ساعت منقضی می‌شود.
 * ردیف waiting (رسید فرستاده شده) هرگز منقضی یا رد نمی‌شود؛ فقط یک بار به ادمین یادآوری می‌شود.
 */
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../card_payment.php';

$setting = select("setting", "*");
$tz = function_exists('getBotTimezone') ? (string) getBotTimezone() : 'Asia/Tehran';
$now = time();

vb_cardpay_expire_unpaid($pdo, $now, VB_CARDPAY_UNPAID_TTL, $tz, function (array $row) {
    if (!empty($row['message_id'])) {
        deletemessage($row['id_user'], $row['message_id']);
    }
});

// یک پیام یادآوری برای رسیدهای قدیمی‌تر از ۲ ساعت؛ هر رسید فقط یک بار.
vb_cardpay_waiting_reminders($pdo, $now, function (string $text) use ($setting) {
    $channel = is_array($setting) ? (string) ($setting['Channel_Report'] ?? '') : '';
    if ($channel !== '') {
        $thread = 0;
        try {
            $row = select("topicid", "idreport", "report", "paymentreport", "select");
            $thread = is_array($row) ? (int) ($row['idreport'] ?? 0) : 0;
        } catch (Throwable $e) {
            $thread = 0;
        }
        $payload = ['chat_id' => $channel, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($thread > 0) {
            $payload['message_thread_id'] = $thread;
        }
        $res = telegram('sendmessage', $payload);
        return is_array($res) && !empty($res['ok']);
    }
    // بدون کانال گزارش: به ادمین‌هایی که پشتیبان نیستند
    global $pdo;
    $ok = false;
    foreach ($pdo->query("SELECT id_admin, rule FROM admin")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (($a['rule'] ?? '') === 'support') {
            continue;
        }
        $res = telegram('sendmessage', ['chat_id' => $a['id_admin'], 'text' => $text, 'parse_mode' => 'HTML']);
        $ok = $ok || (is_array($res) && !empty($res['ok']));
    }
    return $ok;
}, VB_CARDPAY_REMIND_AFTER, $tz);
