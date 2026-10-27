# همگام‌سازی کاربران پنل و API مالکیت نام‌کاربری (ویرابات v5.7.0)

## چرا؟
وقتی ربات روی سرور جدید نصب و پنل به آن معرفی می‌شد، کاربرانی که از قبل روی پنل بودند هیچ‌جا ثبت نمی‌شدند (جدول `invoice` فقط خریدهای همان ربات را داشت). اگر چند ربات یا سایت به یک پنل وصل باشند، هیچ‌کدام از نام‌کاربری‌های دیگری خبر نداشت.

از v5.7.0 یک **جدول مرکزی مالکیت** (`panel_accounts`) وجود دارد که همهٔ ربات‌ها و سایت‌های متصل از آن استفاده می‌کنند.

## اجزا
| فایل | کار |
|---|---|
| `panel_sync.php` | جداول، منطق مالکیت، هوک‌ها، منوی مدیر و دکمهٔ «افزودن سرویس قبلی» |
| `sync_api.php` | API اچ‌تی‌تی‌پی (فقط در حالت «هاب» فعال است) |
| `cronbot/sync_panel_users.php` | کرون هر ۱۰ دقیقه: خواندن کاربران پنل‌ها |

### جداول
- `panel_accounts`: یک ردیف برای هر (پنل، نام‌کاربری) با کلید یکتای `(panel_key, username)`. ستون‌ها: وضعیت (`reserved/active/disabled/expired/limited/on_hold/deleted/...`)، نوع مالک (`bot`/`site`)، شناسهٔ مالک (نصب/کلاینت)، `owner_user_id` (آیدی کاربر در آن ربات/سایت)، `miss_count`، زمان‌ها.
- `panel_account_keys`: هش SHA-256 از لینک ساب و UUID هر سرویس برای پیدا کردن حساب از روی لینک/کانفیگ (خودِ لینک ذخیره نمی‌شود).
- `sync_clients`: کلاینت‌های مجاز هاب (فقط هش توکن).
- `sync_settings`: تنظیمات.

`panel_key` = آدرس پنل نرمال‌شده (`scheme://host:port/path` با حروف کوچک و بدون `/` انتهایی). همهٔ ربات‌ها باید پنل را با **همان آدرس** تعریف کنند.

## سه حالت (منوی مدیر ← تنظیمات عمومی ← 🔄 همگام‌سازی کاربران پنل)
1. **محلی (پیش‌فرض):** جدول فقط در دیتابیس همین ربات است. برای یک ربات تنها کافی است.
2. **هاب:** همین ربات جدول مرکزی است و `https://<دامنه>/sync_api.php` را باز می‌کند. مدیر از دکمهٔ «➕ توکن کلاینت جدید» برای هر ربات/سایت یک توکن می‌سازد (`bot نام` یا `site نام`). توکن فقط یک‌بار نمایش داده می‌شود.
3. **کلاینت:** آدرس هاب و توکن را وارد کنید، سپس حالت را «کلاینت» کنید. همهٔ رزرو/خرید/تمدید/حذف/claim از هاب پرسیده می‌شود؛ یک نسخهٔ محلی هم نگه داشته می‌شود.

سوئیچ‌ها: ورود کاربران پنل به `invoice` (پیش‌فرض روشن)، دکمهٔ افزودن سرویس قبلی (روشن)، علامت حذف برای سرویس مالک‌دار حذف‌شده از پنل (**خاموش**)، ثبت رویدادها (روشن). دکمهٔ «📡 تست اتصال» اکشن `ping` را صدا می‌زند.

## رفتار
- **خرید/تست/تغییر لوکیشن:** قبل از ساخت در پنل `reserve` می‌شود؛ اگر نام‌کاربری مال مالک دیگری باشد (409) ساخت انجام نمی‌شود. بعد از ساخت `event: buy` (یا در شکست `release`). رزرو بعد از ۱۵ دقیقه منقضی می‌شود.
- **تمدید موفق:** `event: renew`. **حذف:** `event: delete`.
- **کرون:** پنل‌های `marzban`، `rebecca`، `marzneshin` و `connectix` صفحه‌به‌صفحه (۲۰۰ تایی) خوانده می‌شوند؛ انواع دیگر رد می‌شوند. هر نام‌کاربری که در `invoice` نیست با وضعیت `unclaimed` و بدون `id_user` ساخته می‌شود (محصول `panel_sync`، قیمت ۰). کاربری که دو اجرای پشت سر هم دیده نشود `deleted` می‌شود و ردیف unclaimed آن `removedbypanel`.
- **Claim کاربر:** در «سرویس‌های من» دکمهٔ «🔗 افزودن سرویس قبلی» یا دستور `/claim`. کاربر لینک ساب یا کانفیگ را می‌فرستد؛ فقط اگر سرویس مالک نداشته باشد (یا مال خودش باشد) به او وصل می‌شود. محدودیت ۱۰ تلاش در ۱۰ دقیقه. با نام‌کاربری تنها نمی‌توان claim کرد.
- **واگذاری گروهی مدیر:** دکمهٔ «👥 واگذاری گروهی»؛ خط اول آیدی کاربر، خط دوم نام پنل، بقیه نام‌کاربری‌ها (حداکثر ۵۰۰).
- هیچ پیام رسید یا دکمهٔ موجود حذف نشده است.

## API (برای سایت‌ها و ربات‌های دیگر)
```
POST https://HUB/sync_api.php?action=<action>
Authorization: Bearer vbs_xxxxxxxx
Content-Type: application/json
```
- فقط POST (وگرنه 405). اگر نصب در حالت هاب نباشد 403. توکن نامعتبر/غیرفعال 401 (مقایسهٔ هش با `hash_equals`).
- محدودیت ۶۰۰ درخواست در دقیقه برای هر کلاینت؛ بیشتر از آن 429 با `Retry-After`.
- هویت مالک همیشه از توکن خوانده می‌شود (`owner_type` = bot/site و `owner_id` = `c:<نام کلاینت>`)؛ `force` از بدنه حذف می‌شود.
- پاسخ همیشه JSON است: `{"ok":true,...}` یا `{"ok":false,"error":"..."}`.

| action | بدنه | پاسخ |
|---|---|---|
| `ping` | `{}` | `hub`, `you` |
| `reserve` | `panel_url, username, owner_user_id?` | 200 `reserved` / 409 `username taken` |
| `release` | `panel_url, username` | `released` (فقط رزرو خودتان) |
| `event` | `panel_url, username, type: buy\|renew\|delete, owner_user_id?, keys?[]` | 200 / 409 `owned by another` |
| `claim` | `key_hash` یا (`panel_url, username` فقط برای مالک فعلی)، `owner_user_id` | 200 `account` / 404 / 403 / 409 / 410 `account deleted` |
| `upsert` | `panel_url, items:[{username,status,keys?[]}] (≤500), missing?:[username]` | `upserted`, `missing_marked` (مالکیت تغییر نمی‌کند) |
| `lookup` | `key_hash` یا `panel_url, username` | `account` / 404 |
| `list` | `panel_url?, status?, owner_user_id?, include_unowned?, after_id?, limit≤500` | `items`, `next_after_id` |

`account`: `panel_key, username, status, owner_type, owned, owned_by_you, owner_user_id` (فقط اگر مال شما باشد)، `last_seen`.

### ساخت `key_hash`
`sha256("<kind>:<value>")` به‌صورت hex با حروف کوچک:
- `sub:<آخرین بخش مسیر لینک ساب>` مثلاً برای `https://x.com/sub/AbC123` مقدار `sub:AbC123`.
- `uuid:<uuid با حروف کوچک>` از کانفیگ vless/vmess/trojan.

### مثال (PHP، سایت)
```php
$ch = curl_init('https://hub.example.com/sync_api.php?action=reserve');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode(['panel_url' => 'https://panel.example.com:8000', 'username' => 'user123', 'owner_user_id' => '42'])]);
$r = json_decode(curl_exec($ch), true);
if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 409) { /* نام دیگری انتخاب کنید */ }
```
سایت باید قبل از ساخت در پنل `reserve`، بعد از ساخت `event buy`، بعد از تمدید `event renew` و بعد از حذف `event delete` بفرستد.

## محدودیت‌ها
- هوک‌ها fail-open هستند: اگر هاب در دسترس نباشد فروش متوقف نمی‌شود (فقط 409 ساخت را رد می‌کند)؛ کرون بعدی جدول را درست می‌کند.
- فقط Marzban، Rebecca، Marzneshin و Connectix خوانده می‌شوند (X-UI/سنایی/ایلان/WireGuard/... رد می‌شوند و فقط از هوک‌ها ثبت می‌شوند).
- کلاینت‌ها مورد اعتماد فرض شده‌اند: `upsert` و `keys` از کلاینت پذیرفته می‌شود. به هر ربات/سایت فقط توکن جدا بدهید و در صورت نشت، غیرفعالش کنید.
- کرون جدید با اولین ورود مدیر به پنل مدیریت (`activecron`) در crontab ثبت می‌شود.
