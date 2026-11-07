<?php
/**
 * یک‌بار برای هر نسخهٔ تازه به مدیرهای اصلی پیام می‌دهد.
 * اگر کانال خاموش باشد کاری نمی‌کند و به گیت‌هاب وصل نمی‌شود.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    // درخواست وب هم مجاز است؛ خروجی محرمانه ندارد.
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/function.php';
if (function_exists('vb_update_push_notice')) {
    vb_update_push_notice(dirname(__DIR__));
}
echo "ok\n";
