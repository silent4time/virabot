<?php
/**
 * تازه‌سازی کش انتشار پایدار. این فرمان را با cron و کاربر root اجرا کنید.
 * درخواست‌های وب، توکن گیت‌هاب را نمی‌خوانند مگر allow_web_refresh صریحاً true باشد.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ServerApp.php';

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "config.php نیست؛ سرور خاموش می‌ماند.\n");
    exit(1);
}
$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "config.php نامعتبر است.\n");
    exit(1);
}
$force = in_array('--force', $argv, true);
$app = new VbUpdateServerApp($config, dirname(__DIR__) . '/storage', time());
$result = $app->refreshFromGitHub($force);
if (empty($result['ok'])) {
    $err = (string) ($result['error'] ?? 'failed');
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $err)) {
        $err = 'failed';
    }
    fwrite(STDERR, "refresh failed: {$err}\n");
    exit(1);
}
fwrite(STDOUT, "refresh ok\n");
exit(0);
