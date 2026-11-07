<?php
// Project: SilentSell — Telegram + Bale payment-ready Mirza fork
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$dbhost = '{{DB_HOST}}';
$dbname = '{{DB_NAME}}';
$usernamedb = '{{DB_USER}}';
$passworddb = '{{DB_PASS}}';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("error: database connection failed");
}
$APIKEY = '{{TELEGRAM_TOKEN}}';
$adminnumber = '{{ADMIN_ID}}';
$domainhosts = '{{DOMAIN}}';

// منطقه زمانی پیش‌فرض (قابل تغییر از پنل ادمین ربات)
$BOT_TIMEZONE = 'Asia/Tehran';
$usernamebot = '{{TELEGRAM_USERNAME}}';

// =====================================================================
//  MULTI-PLATFORM SETTINGS (Telegram + Bale)
//  تنظیمات چند پیام‌رسانی (تلگرام + بله)
// =====================================================================
$ENABLE_TELEGRAM = {{ENABLE_TELEGRAM}};
$ENABLE_BALE     = {{ENABLE_BALE}};

// توکن ربات تلگرام (از BotFather) - همان $APIKEY بالا، به صورت صریح
$TELEGRAM_APIKEY = $APIKEY;

// توکن ربات بله (از پنل my.bale.ai دریافت می‌شود)
$BALE_APIKEY = '{{BALE_TOKEN}}';

// آیدیِ عددیِ ادمین در بله (شناسه‌ای کاملاً جدا و بی‌ربط به آیدیِ تلگرام)
$BALE_ADMIN_ID = '{{BALE_ADMIN_ID}}';

// آیدیِ عددیِ گروهِ گزارشاتِ بله (اختیاری) - اگر پر شود، گزارش‌ها (درخواستِ
// خرید، اسکرین‌شاتِ تراکنش و...) عیناً به این گروه هم فرستاده می‌شوند.
$BALE_REPORT_GROUP_ID = '{{BALE_REPORT_GROUP_ID}}';

// توکنِ پرداخت/کیف‌پولِ بله (از بخشِ فعال‌سازیِ پرداختِ ربات در تنظیماتِ
// ربات‌سازِ بله گرفته می‌شود، نه یک شماره‌کارت). اگر نمی‌خواهید، خالی بگذارید.
$BALE_PROVIDER_TOKEN = '{{BALE_PROVIDER_TOKEN}}';

// آدرس پایه‌ی API هرکدام از پلتفرم‌ها - نیازی به تغییر ندارد
$TELEGRAM_API_BASE = 'https://api.telegram.org/bot';
$BALE_API_BASE     = 'https://tapi.bale.ai/bot';

// رمز محرمانه‌ی وبهوک. نکته‌ی مهمِ کشف‌شده در تست عملی: بله برخلاف
// تلگرام، این مقدار را به‌صورت هدر HTTP ارسال نمی‌کند؛ برای همین علاوه
// بر هدر، از طریق پارامتر ?secret=... در خودِ URL وبهوک هم پذیرفته
// می‌شود (نصب‌کننده به‌صورت خودکار وبهوک را با این روش ثبت می‌کند).
$WEBHOOK_SECRET_TOKEN = '{{WEBHOOK_SECRET}}';

// افستِ عددیِ ثابت برای جدا نگه‌داشتن شناسه‌ی کاربران بله از شناسه‌ی
// کاربران تلگرام درون یک دیتابیس مشترک. بعد از اولین نصب هرگز تغییرش
// ندهید، وگرنه رکورد کاربران بله‌ی قبلی با کاربران جدید تداخل می‌کند.
if (!defined('BALE_ID_OFFSET')) {
    define('BALE_ID_OFFSET', 9000000000000); // 9 * 10^12
}

// =====================================================================
//  LICENSE (SilentSell) — domain-locked subscription
//  API: https://license.example.com/api/verify.php
// =====================================================================
$LICENSE_ENABLED = true;
$LICENSE_KEY = '{{LICENSE_KEY}}';
$LICENSE_API_URL = '{{LICENSE_API_URL}}'; // empty = built-in (obfuscated) endpoint
// تأیید گواهی SSL سرور لایسنس روشن است. فقط اگر سرور شما گواهی را تأیید نمی‌کند و خطای SSL در error_log دیدید، true کنید.
$LICENSE_ALLOW_INSECURE_SSL = false;

