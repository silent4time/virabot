<?php
/**
 * کارت‌به‌کارت: توابع خالص و قابل‌آزمون (بدون config و بدون تلگرام).
 *
 * - vb_cardpay_copy_amount: مقدار دکمهٔ «کپی مبلغ» دقیقاً همان عدد تومانی پیام.
 * - vb_cardpay_find_pending: فاکتور باز (Unpaid) یا رسید در انتظار (waiting) همان کاربر.
 * - vb_cardpay_expire_unpaid: فقط Unpaid را بعد از مهلت منقضی می‌کند؛ waiting هرگز.
 * - vb_cardpay_waiting_reminders: برای هر رسید منتظر فقط یک یادآوری به ادمین.
 *
 * زمان‌های Payment_report با bot_date() ذخیره می‌شوند که شمسی است (مثلاً 1405/08/30 16:30:00)
 * و بعضی درگاه‌ها میلادی ذخیره می‌کنند؛ هر دو اینجا به timestamp تبدیل می‌شوند.
 */

if (!defined('VB_CARDPAY_UNPAID_TTL')) {
    // مدت اعتبار فاکتور پرداخت‌نشده؛ متن پیام کارت‌به‌کارت هم «۲۴ ساعت» می‌گوید.
    define('VB_CARDPAY_UNPAID_TTL', 86400);
}
if (!defined('VB_CARDPAY_REMIND_AFTER')) {
    // رسیدی که این مدت منتظر بررسی مانده، یک بار به ادمین یادآوری می‌شود.
    define('VB_CARDPAY_REMIND_AFTER', 7200);
}
if (!defined('VB_CARDPAY_WAITING_BLOCK')) {
    // رسید منتظرِ هم‌مبلغ در این بازه، ساخت فاکتور تکراری با همان مبلغ را متوقف می‌کند.
    define('VB_CARDPAY_WAITING_BLOCK', 86400);
}

/** مقدار کپی‌شدنی مبلغ: عدد صحیح تومان، بدون جداکننده و بدون ضرب در ۱۰. */
function vb_cardpay_copy_amount($amount): string
{
    $s = vb_cardpay_latin_digits(trim((string) $amount));
    $s = str_replace([',', '٬', ' '], '', $s);
    if ($s === '' || !is_numeric($s)) {
        return '0';
    }
    return (string) (int) round((float) $s);
}

function vb_cardpay_latin_digits(string $s): string
{
    return strtr($s, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

/** تبدیل تاریخ شمسی به میلادی (الگوریتم استاندارد jdf). */
function vb_cardpay_j2g(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = (($gy % 4 == 0) && ($gy % 100 != 0)) || ($gy % 400 == 0);
    $months = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 1; $gm <= 12 && $gd > $months[$gm]; $gm++) {
        $gd -= $months[$gm];
    }
    return [$gy, $gm, $gd];
}

/**
 * زمان ذخیره‌شده (شمسی یا میلادی، با / یا -) را به unix timestamp تبدیل می‌کند.
 * اگر قابل خواندن نبود null برمی‌گرداند تا هیچ ردیفی بر اساس حدس منقضی نشود.
 */
function vb_cardpay_parse_time($value, string $tz = 'Asia/Tehran'): ?int
{
    if ($value === null) {
        return null;
    }
    $s = vb_cardpay_latin_digits(trim((string) $value));
    if ($s === '') {
        return null;
    }
    if (ctype_digit($s) && strlen($s) >= 9) {
        return (int) $s;
    }
    if (!preg_match('~^(\d{4})[/-](\d{1,2})[/-](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?~', $s, $m)) {
        return null;
    }
    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
        return null;
    }
    if ($y < 1700) {
        [$y, $mo, $d] = vb_cardpay_j2g($y, $mo, $d);
    }
    try {
        $zone = new DateTimeZone($tz !== '' ? $tz : 'Asia/Tehran');
    } catch (Throwable $e) {
        $zone = new DateTimeZone('Asia/Tehran');
    }
    $dt = DateTime::createFromFormat('!Y-n-j G:i:s', sprintf('%d-%d-%d %d:%02d:%02d', $y, $mo, $d, (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)), $zone);
    return $dt ? $dt->getTimestamp() : null;
}

/**
 * پرداخت‌های باز کاربر برای جلوگیری از فاکتور تکراری.
 * unpaid: همهٔ فاکتورهای Unpaid همین کاربر (بر اساس id_user، نه id).
 * waiting_same: رسیدهای کارت‌به‌کارت منتظر بررسی با همین مبلغ که در بازهٔ VB_CARDPAY_WAITING_BLOCK فرستاده شده‌اند.
 */
function vb_cardpay_find_pending(PDO $pdo, $userId, $price, int $now, string $tz = 'Asia/Tehran'): array
{
    $st = $pdo->prepare("SELECT * FROM Payment_report WHERE id_user = ? AND payment_Status = 'Unpaid'");
    $st->execute([(string) $userId]);
    $unpaid = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT * FROM Payment_report WHERE id_user = ? AND payment_Status = 'waiting' AND Payment_Method = 'cart to cart'");
    $st->execute([(string) $userId]);
    $want = vb_cardpay_copy_amount($price);
    $same = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (vb_cardpay_copy_amount($row['price'] ?? '') !== $want) {
            continue;
        }
        $t = vb_cardpay_parse_time(($row['at_updated'] ?? '') !== '' ? $row['at_updated'] : ($row['time'] ?? null), $tz);
        if ($t === null || ($now - $t) < VB_CARDPAY_WAITING_BLOCK) {
            $same[] = $row;
        }
    }
    return ['unpaid' => $unpaid, 'waiting_same' => $same];
}

/**
 * فقط ردیف‌های Unpaid قدیمی‌تر از $ttl را expire می‌کند. waiting/paid/reject دست نمی‌خورند.
 * UPDATE شرط payment_Status = 'Unpaid' دارد تا اگر کاربر همان لحظه رسید فرستاد، رسیدش منقضی نشود.
 * ردیف با زمان ناخوانا منقضی نمی‌شود.
 */
function vb_cardpay_expire_unpaid(PDO $pdo, int $now, int $ttl = VB_CARDPAY_UNPAID_TTL, string $tz = 'Asia/Tehran', ?callable $onExpire = null): int
{
    $rows = $pdo->query("SELECT * FROM Payment_report WHERE payment_Status = 'Unpaid'")->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'expire' WHERE id_order = ? AND payment_Status = 'Unpaid'");
    $n = 0;
    foreach ($rows as $row) {
        $t = vb_cardpay_parse_time($row['time'] ?? null, $tz);
        if ($t === null || ($now - $t) < $ttl) {
            continue;
        }
        $upd->execute([(string) $row['id_order']]);
        if ($upd->rowCount() > 0) {
            $n++;
            if ($onExpire) {
                try {
                    $onExpire($row);
                } catch (Throwable $e) {
                    error_log('cardpay expire hook: ' . $e->getMessage());
                }
            }
        }
    }
    return $n;
}

function vb_cardpay_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (strcasecmp((string) $c['name'], $column) === 0) {
                    return true;
                }
            }
            return false;
        }
        $st = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
        $st->execute([$column]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * رسیدهای کارت‌به‌کارت/ارز آفلاین که بیش از $after ثانیه منتظر بررسی مانده‌اند را در یک پیام به $send می‌دهد.
 * برای هر رسید فقط یک بار (ستون remind_sent). وضعیت پرداخت هرگز تغییر نمی‌کند.
 * اگر ارسال ناموفق بود چیزی علامت نمی‌خورد تا اجرای بعدی دوباره تلاش کند.
 */
function vb_cardpay_waiting_reminders(PDO $pdo, int $now, callable $send, int $after = VB_CARDPAY_REMIND_AFTER, string $tz = 'Asia/Tehran', int $max = 20): int
{
    if (!vb_cardpay_has_column($pdo, 'Payment_report', 'remind_sent')) {
        return 0;
    }
    $rows = $pdo->query("SELECT * FROM Payment_report WHERE payment_Status = 'waiting' AND Payment_Method IN ('cart to cart','arze digital offline') AND (remind_sent IS NULL OR remind_sent = '')")->fetchAll(PDO::FETCH_ASSOC);
    $due = [];
    foreach ($rows as $row) {
        $t = vb_cardpay_parse_time(($row['at_updated'] ?? '') !== '' ? $row['at_updated'] : ($row['time'] ?? null), $tz);
        if ($t === null || ($now - $t) < $after) {
            continue;
        }
        $due[] = $row;
        if (count($due) >= $max) {
            break;
        }
    }
    if (!$due) {
        return 0;
    }
    $hours = (int) round($after / 3600);
    $lines = ["⏰ یادآوری: این رسیدها بیش از {$hours} ساعت است منتظر بررسی‌اند و خودکار رد یا منقضی نمی‌شوند:"];
    foreach ($due as $row) {
        $lines[] = '• کد <code>' . htmlspecialchars((string) $row['id_order']) . '</code> | کاربر <code>' . htmlspecialchars((string) $row['id_user']) . '</code> | '
            . number_format((float) vb_cardpay_copy_amount($row['price'] ?? 0)) . ' تومان | رسید: ' . htmlspecialchars((string) (($row['at_updated'] ?? '') !== '' ? $row['at_updated'] : ($row['time'] ?? '-')));
    }
    $lines[] = "برای تأیید یا رد، از دکمه‌های پیام همان رسید استفاده کنید. برای هر رسید فقط یک بار یادآوری فرستاده می‌شود.";
    if (!$send(implode("\n", $lines), $due)) {
        return 0;
    }
    $mark = $pdo->prepare("UPDATE Payment_report SET remind_sent = ? WHERE id_order = ? AND payment_Status = 'waiting' AND (remind_sent IS NULL OR remind_sent = '')");
    $n = 0;
    foreach ($due as $row) {
        $mark->execute([(string) $now, (string) $row['id_order']]);
        $n += $mark->rowCount();
    }
    return $n;
}
