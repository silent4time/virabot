<?php
/**
 * هش کلید کلاینت را از stdin می‌خواند و sha256 را چاپ می‌کند.
 * کلید را روی آرگومان فرمان نگذارید تا در فهرست فرایندها نماند.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/patch/update_protocol.php';

$key = stream_get_contents(STDIN);
if ($key === false) {
    fwrite(STDERR, "خواندن کلید ناموفق بود.\n");
    exit(1);
}
$key = trim($key);
if (!vb_update_client_key_ok($key)) {
    fwrite(STDERR, "کلید باید ۳۲ تا ۱۲۸ نویسه از حروف، عدد، خط تیره یا زیرخط باشد.\n");
    exit(1);
}
echo vb_update_key_fingerprint($key), "\n";
