<?php
$candidates = [
    getcwd() . '/lang/fa.php',
    __DIR__ . '/lang/fa.php',
    dirname(__DIR__) . '/lang/fa.php',
];
$fa = null;
foreach ($candidates as $c) {
    if (is_file($c)) { $fa = $c; break; }
}
if (!$fa) {
    fwrite(STDERR, "lang/fa.php not found\n");
    exit(1);
}
$t = file_get_contents($fa);
$orig = $t;

function sb_set_text(string $src, string $key, string $value, &$count): string
{
    $count = 0;
    $quoted = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    $out = preg_replace_callback(
        "/('" . preg_quote($key, '/') . "'\\s*=>\\s*)'(?:[^'\\\\]|\\\\.)*'/s",
        function ($m) use ($quoted) { return $m[1] . $quoted; },
        $src,
        -1,
        $count
    );
    return $out === null ? $src : $out;
}

// Admin panel header. The single %s is the content of the "version" file; admin.php
// runs sprintf on it twice, so the text must keep exactly one %s and no other "%".
// The mini app version is read from app/version so the text never goes stale.
$miniVer = '';
foreach ([dirname(dirname($fa)) . '/app/version', getcwd() . '/app/version'] as $vf) {
    if (is_file($vf)) { $miniVer = trim((string) file_get_contents($vf)); break; }
}
if ($miniVer === '' || strpos($miniVer, '%') !== false) { $miniVer = '2.2.0'; }

$adminAbout = '💎 | Version Bot: %s
📌 | Version Mini App: ' . $miniVer . '

<blockquote>🔹 | این ربات اشتراکی است و توسط تیم ویرا توسعه داده شده است</blockquote>

<blockquote>🔹 | نسخه رایگان ربات تنها 20 کاربر را پشتیبانی میکند</blockquote>

<blockquote>🐞 | اگر در عملکرد ربات با باگ یا مشکلی مواجه شدید، از طریق دکمهٔ <b>📬 گزارش ربات</b> در پنل ادمین با ما در ارتباط باشید.</blockquote>';

$hits = 0;
foreach (['msg_panel_admin_bot_report', 'aboutBot'] as $key) {
    $t = sb_set_text($t, $key, $adminAbout, $n);
    if ($n > 0) {
        echo "OK {$key}\n";
        $hits += $n;
    }
}
if ($hits === 0) {
    echo "WARN admin about text not found\n";
}

$newReport = '💬 | گزارش ربات

🔹 | اگر در عملکرد ربات با باگ یا مشکلی روبه‌رو شدید، لطفاً مورد را برای بررسی به ما اطلاع دهید.
➖➖➖➖➖➖➖➖➖➖➖
🔹 | در صورتی که با باگ جدی یا رفتار غیرعادی مواجه شدید، سریع‌تر گزارش دهید تا رفع شود.
➖➖➖➖➖➖➖➖➖➖➖
🔹 | اگر پیشنهادی برای افزودن قابلیت جدید دارید یا ایده‌ای برای بهبود عملکرد ربات در نظر دارید، خوشحال می‌شویم بشنویم.
➖➖➖➖➖➖➖➖➖➖➖
🔹 | همچنین اگر نیاز به راهنمایی یا کمک دارید، می‌توانید از طریق دایرکت با تیم پشتیبانی در ارتباط باشید.

📩 | برای ارسال گزارش، پیشنهاد یا درخواست راهنمایی، ایمیل بگذارید:
<a href="mailto:silentping.vpn@gmail.com">silent4time</a> (silentping.vpn@gmail.com)';
$reportHits = 0;
foreach (['msg_help_bot_group_message', 'botReportIntro'] as $key) {
    $t = sb_set_text($t, $key, $newReport, $n);
    if ($n > 0) {
        echo "OK {$key}\n";
        $reportHits += $n;
    }
}
if ($reportHits === 0) {
    echo "WARN bot report text not found\n";
}

if ($t === $orig) {
    echo "No change\n";
    exit(0);
}
$check = @eval('?>' . $t);
if (!is_array($check)) {
    fwrite(STDERR, "branded lang file does not evaluate to an array; not saved\n");
    exit(1);
}
file_put_contents($fa, $t);
echo "OK saved: $fa\n";
exit(0);
