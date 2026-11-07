<?php
/**
 * نمونهٔ پیکربندی سرور به‌روزرسانی.
 * این فایل را کپی کنید به config.php و فقط روی میزبان مورد اعتماد پر کنید.
 * config.php را در گیت نگذارید. توکن گیت‌هاب داخل این فایل نیست.
 *
 * تا وقتی enabled برابر false باشد، یا رمز امضا، مخزن، یا هش کلید کلاینت
 * خالی باشد، سرور هیچ تماسی با گیت‌هاب نمی‌گیرد و به کلاینت بسته می‌گوید.
 */
declare(strict_types=1);

return [
    'enabled' => false,
    'require_https' => true,
    // فقط از خط فرمان root خوانده می‌شود. فایل جدا، مالک root، حالت 600.
    // مثال ساخت: umask 077; printf '%s' "$TOKEN" > /root/virabot-update-token
    'token_file' => '',
    // owner/name بدون نشانی. خالی = پیکربندی نشده.
    'github_repository' => '',
    'asset_name' => 'virabot-update.zip',
    // openssl rand -hex 32
    'server_secret' => '',
    // شناسه => sha256 کلید کلاینت. خود کلید اینجا نیست.
    // php update-server/bin/hash-key.php <<<"$KEY"
    'client_key_hashes' => [
        // 'bot-1' => '',
    ],
    'download_ttl' => 600,
    // درخواست وب به‌صورت پیش‌فرض گیت‌هاب را صدا نمی‌زند. تازه‌سازی با cron و refresh.php است.
    'allow_web_refresh' => false,
];
