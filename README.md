<p align="center">
  <img src="docs/virabot-logo-header.png" alt="Virabot" width="100%">
</p>

<h1 align="center">ویرابات · Virabot</h1>

<p align="center">
  <a href="#fa"><img src="docs/buttons/fa.png" alt="فارسی" height="36"></a>
  <a href="#en"><img src="docs/buttons/en.png" alt="English" height="36"></a>
</p>
<p align="center">
  <a href="https://github.com/silent4time/virabot/blob/main/CHANGELOG.md"><img src="docs/buttons/changelog.png" alt="تغییرات" height="36"></a>
  <a href="https://github.com/silent4time/virabot/releases/tag/v5.2.0"><img src="docs/buttons/release.png" alt="ریلیز" height="36"></a>
  <a href="https://github.com/silent4time/virabot/raw/main/docs/virabot-guide.pdf"><img src="docs/buttons/guide.png" alt="راهنمای مصور" height="36"></a>
</p>

ربات فروش و مدیریت اشتراک سرویس‌های شبکه برای تلگرام و بله.

---

<a id="fa"></a>

# 🇮🇷 فارسی

## ✨ معرفی

ویرابات یک ربات کامل برای فروش اشتراک سرویس‌های شبکه است که همزمان روی تلگرام و پیام‌رسان بله کار می‌کند. خرید، پرداخت، تحویل خودکار سرویس، کیف پول و پنل مدیریت در یک سیستم است.

این پروژه بر پایه ربات متن‌باز میرزا توسعه یافته است. تشکر از سازنده اصلی: [mahdiMGF2/mirzabot](https://github.com/mahdiMGF2/mirzabot)

## 📊 مقایسه با ربات‌های مشابه

<div align="center">

| موضوع | ربات‌های مشابه | ویرابات |
|--------|----------------|---------|
| معماری | بازنویسی جدید | نسل قبلی + سفارشی‌سازی |
| تلگرام | ✅ | ✅ |
| بله و بله‌پی | ❌ | ✅ |
| کارت‌به‌کارت و کیف پول | ⚠️ محدود | ✅ پایدار |
| پنل ایلان | ❌ | ✅ کامل |
| پنل کانکتیکس | ❌ | ✅ کامل |
| بکاپ زمان‌بندی‌شده | ⚠️ محدود | ✅ کامل |
| منطقه زمانی | — | ✅ |
| تعرفه و کاربر همزمان | — | ✅ |

</div>

## 🚀 امکانات اصلی

<div align="center">

| قابلیت | توضیح |
|--------|--------|
| تلگرام + بله | یک دیتابیس؛ پیام و وب‌هوک جدا |
| فروش خودکار | محصول، پرداخت، ساخت سرویس و ارسال کانفیگ |
| کیف پول | شارژ و خرید از موجودی |
| پنل‌های متعدد | پنل‌های رایج مدیریت سرویس |
| مدیریت ادمین | محصولات، کاربران، پرداخت‌ها، آمار و بکاپ |
| Apache + SSL | نصب روی Apache با گواهی |
| منوی سرور | نصب، آپدیت، حذف، ریست و تمدید SSL |

</div>

## ➕ امکانات اضافه‌شده

<div align="center">

| امکان | توضیح |
|--------|--------|
| تلگرام + بله یکپارچه | وب‌هوک جدا؛ دیتابیس مشترک؛ گزارش به هر دو گروه |
| بله‌پی از تلگرام | هدایت کاربر تلگرام به ربات بله برای پرداخت |
| کارت‌به‌کارت پایدار | فاکتور، کارت، رسید و تأیید ادمین |
| پنل ایلان | جداسازی پروتکل با inboundid |
| کاربر همزمان محصول | تعداد کاربر همزمان؛ صفر یعنی نامحدود |
| تعرفه اشتراک‌ها | حجم، زمان، کاربر و قیمت؛ مرتب بر اساس قیمت |
| بکاپ دستی | SQL فوری به گروه تلگرام و بله |
| زمان‌بندی بکاپ | فاصله ساعتی و ساعت شروع |
| منطقه زمانی | انتخاب از پنل؛ پیش‌فرض تهران؛ تاریخ شمسی |
| گروه گزارش بله | آیدی گروه بله جدا از تلگرام |
| صفحه وب و مینی‌اپ | داشبورد وب، تب بکاپ، فروشگاه تلگرام |

</div>

## 🔑 کد فعال‌سازی

نسخه رایگان تا **۲۰ کاربر** در همه پنل‌ها کار می‌کند. بعد از آن ساخت کاربر جدید متوقف می‌شود. برای استفاده کامل باید کد فعال‌سازی بخرید.

برای خرید یا تمدید به تلگرام **@silentpingvpn** پیام دهید: [t.me/silentpingvpn](https://t.me/silentpingvpn)

### ثبت کلید

1. در ربات وارد منوی مدیریت شوید.
2. **لایسنس ربات** و بعد **ثبت / ویرایش کلید لایسنس** را بزنید و کلید را بفرستید.
3. برای دیدن وضعیت، این دستور را بفرستید:

```text
/license
```

کلید فقط روی دامنه‌ای کار می‌کند که برایش صادر شده است.

## 💳 روش‌های پرداخت

- 💸 **بله‌پی:** پرداخت آنلاین داخل بله؛ از تلگرام با راهنمایی به ربات بله
- 💳 **کارت‌به‌کارت:** نمایش کارت، دریافت رسید و تأیید ادمین
- 👛 **کیف پول:** خرید از موجودی کاربر

## 🧩 پنل‌های پشتیبانی‌شده

<div align="center">

| نوع پنل | شناسه |
|---------|--------|
| Marzban | `marzban` |
| Marzneshin | `marzneshin` |
| پاسارگاد | `pasarguard` |
| اتصال به ربات نماینده دیگر | `mirza_agent` |
| سنایی | `x-ui_single` |
| علیرضا | `alireza_single` |
| Hiddify | `hiddify` |
| فروش دستی | `Manualsale` |
| WGDashboard | `WGDashboard` |
| s-UI | `s_ui` |
| IBSng | `ibsng` |
| MikroTik | `mikrotik` |
| Rebecca | `rebecca` |
| Eylan | `eylan` |
| Connectix | `connectix` |

</div>

افزودن پنل: منوی مدیریت، مدیریت پنل‌ها، افزودن پنل.

## 🔗 پنل کانکتیکس

سایت فروشنده: [seller.connectix.vip](https://seller.connectix.vip)

- نوع پنل: **Connectix**
- آدرس API: `https://seller-api.connectix.vip/externel/v1`
- رمز پنل همان API Token است
- بعد از ذخیره، پلن‌های فعال به‌صورت محصول ساخته می‌شوند
- پلن‌هایی که در عنوانشان free است، پلن تست‌اند و به دکمه اکانت تست وصل می‌شوند

<div align="center">

| کار | درخواست |
|-----|---------|
| ساخت کاربر | `POST /clients/store` و بعد `GET /clients/show?id=` |
| اطلاعات و مصرف | `GET /clients/show?id=` |
| پیدا کردن شناسه | `GET /clients?username=` |
| تمدید | `POST /clients/add-plan` |

</div>

هر add-plan موفق یک خرید واقعی است و ربات آن را تکرار نمی‌کند. املای مسیر `externel` است، نه external.

## 🖥️ چند نصب روی یک سرور

<div align="center">

| باید جدا باشد | مثال |
|---------------|------|
| مسیر | `/home/virabot` و `/home/virabot2` |
| دامنه | `bot1.example.com` و `bot2.example.com` |
| دیتابیس | نام و یوزر جدا |
| توکن | توکن جدا برای هر ربات |

</div>

هر نمونه فایل تنظیم جدا در `/etc/virabot/instances` دارد. اگر چند نصب باشد، آپدیت و حذف می‌پرسد کدام را انتخاب کنید.

## 📁 مسیر نصب

<div align="center">

| مورد | مسیر |
|------|------|
| پوشه ربات | `/home/virabot` |
| منوی مدیریت | `/opt/virabot-src` |
| تنظیمات | `/etc/virabot/instances/<id>.env` |
| بکاپ | `/root/virabot-backups` |
| فرمان | `sudo virabot` |

</div>

## ⚙️ نصب

```bash
curl -fsSL https://raw.githubusercontent.com/silent4time/virabot/main/install.sh | sudo bash
```

از منو گزینه **1) Install** را بزنید. دامنه، توکن تلگرام، توکن بله و اطلاعات دیتابیس پرسیده می‌شود.

## 🔄 آپدیت

```bash
sudo virabot update
```

یا از منو گزینه **2) Update**. قبل از آپدیت از فایل‌های اصلی بکاپ گرفته می‌شود.

## 📋 منوی مدیریت

<div align="center">

| گزینه | کار |
|-------|-----|
| 1 | نصب |
| 2 | آپدیت |
| 3 | حذف کامل |
| 4 | ریست وب‌هوک و دامنه |
| 5 | تمدید SSL |
| 6 | راهنما |
| 7 | وضعیت نصب |
| 8 | تنظیم ربات‌ها و گروه‌ها |
| 9 | فهرست نصب‌ها |
| 0 | خروج |

</div>

## 📦 ساختار ریپو

<div align="center">

| فایل | نقش |
|------|-----|
| `install.sh` | نصب یک‌خطی |
| `virabot-latest.zip` | بسته آخرین نسخه |
| `VERSION` | شماره نسخه |
| `CHANGELOG.md` | تغییرات |
| `README.md` | همین صفحه |
| `docs/virabot-guide.pdf` | راهنمای مصور |

</div>

## 💛 حمایت

USDT / TON روی شبکه BEP20:

```text
0xa1a89602DDB47682091Af3Da1737672bd97f5669
```

## ⚠️ سلب مسئولیت

این نرم‌افزار برای مدیریت سرویس‌های خودتان است. مسئولیت سرور، دامنه، امنیت و رعایت قوانین محلی با بهره‌بردار است.

---

<a id="en"></a>

# 🇬🇧 English

## ✨ About

Virabot is a sales and subscription bot for network services. It runs on Telegram and Bale at the same time: purchase, payment, automatic delivery, wallet, and an admin panel in one system.

It is built on the open-source Mirza bot. Thanks to the original author: [mahdiMGF2/mirzabot](https://github.com/mahdiMGF2/mirzabot)

## 📊 Compared with similar bots

<div align="center">

| Topic | Similar bots | Virabot |
|--------|----------------|---------|
| Architecture | Fresh rewrite | Previous generation, customized |
| Telegram | ✅ | ✅ |
| Bale and Bale Pay | ❌ | ✅ |
| Card transfer and wallet | ⚠️ Limited | ✅ Stable |
| Eylan panel | ❌ | ✅ Full |
| Connectix panel | ❌ | ✅ Full |
| Scheduled backup | ⚠️ Limited | ✅ Full |
| Time zone | — | ✅ |
| Pricing and concurrent users | — | ✅ |

</div>

## 🚀 Main features

<div align="center">

| Feature | What it does |
|---------|----------------|
| Telegram + Bale | One database, separate webhooks |
| Automatic sales | Product, payment, service creation, config delivery |
| Wallet | Top up and buy from balance |
| Multiple panels | Common service-management panels |
| Admin tools | Products, users, payments, stats, backup |
| Apache + SSL | Apache install with a certificate |
| Server menu | Install, update, remove, reset, renew SSL |

</div>

## ➕ Added features

<div align="center">

| Feature | Detail |
|---------|--------|
| Telegram + Bale together | Separate webhooks, shared database, reports to both groups |
| Bale Pay from Telegram | Sends the Telegram user to the Bale bot to pay |
| Stable card transfer | Invoice, card, receipt, admin approval |
| Eylan panel | Protocol split by inbound id |
| Concurrent users | Set per product; zero means unlimited |
| Plan list | Volume, time, users, price; sorted by price |
| Manual backup | Immediate SQL to the Telegram and Bale groups |
| Backup schedule | Hour interval and start hour |
| Time zone | Chosen in the admin panel; Tehran by default; Jalali dates |
| Bale report group | Separate Bale group id |
| Web panel and mini app | Web dashboard, backup tab, Telegram shop |

</div>

## 🔑 Activation code

The free edition supports **20 users** across all panels. After that, new users cannot be created. A paid activation code is required for full use.

To buy or renew, message **@silentpingvpn** on Telegram: [t.me/silentpingvpn](https://t.me/silentpingvpn)

### Register the key

1. Open the admin menu in the bot.
2. Tap **Bot license**, then **Register / edit license key**, and send the key.
3. To see the status, send:

```text
/license
```

A key only works on the domain it was issued for.

## 💳 Payment methods

- 💸 **Bale Pay:** online payment inside Bale; Telegram users are guided to the Bale bot
- 💳 **Card transfer:** show the card, receive the receipt, admin confirms
- 👛 **Wallet:** buy from the user balance

## 🧩 Supported panels

<div align="center">

| Panel | Id |
|-------|----|
| Marzban | `marzban` |
| Marzneshin | `marzneshin` |
| Pasarguard | `pasarguard` |
| Another agent bot | `mirza_agent` |
| Sanaei | `x-ui_single` |
| Alireza | `alireza_single` |
| Hiddify | `hiddify` |
| Manual sale | `Manualsale` |
| WGDashboard | `WGDashboard` |
| s-UI | `s_ui` |
| IBSng | `ibsng` |
| MikroTik | `mikrotik` |
| Rebecca | `rebecca` |
| Eylan | `eylan` |
| Connectix | `connectix` |

</div>

Add a panel from the admin menu: panel management, add panel.

## 🔗 Connectix

Seller site: [seller.connectix.vip](https://seller.connectix.vip)

- Panel type: **Connectix**
- API address: `https://seller-api.connectix.vip/externel/v1`
- The panel password is the API token
- After saving, active plans are created as products
- Plans with `free` in the title are trial plans and go to the test-account button

<div align="center">

| Action | Request |
|--------|---------|
| Create user | `POST /clients/store`, then `GET /clients/show?id=` |
| Usage | `GET /clients/show?id=` |
| Find id | `GET /clients?username=` |
| Renew | `POST /clients/add-plan` |

</div>

A successful add-plan is a real purchase and the bot does not repeat it. The path spelling is `externel`, not external.

## 🖥️ Several installs on one server

<div align="center">

| Must be separate | Example |
|------------------|---------|
| Path | `/home/virabot` and `/home/virabot2` |
| Domain | `bot1.example.com` and `bot2.example.com` |
| Database | Separate name and user |
| Token | A token for each bot |

</div>

Each install has its own file in `/etc/virabot/instances`. If more than one exists, update and remove ask which one to use.

## 📁 Install path

<div align="center">

| Item | Path |
|------|------|
| Bot folder | `/home/virabot` |
| Management menu | `/opt/virabot-src` |
| Settings | `/etc/virabot/instances/<id>.env` |
| Backups | `/root/virabot-backups` |
| Command | `sudo virabot` |

</div>

## ⚙️ Install

```bash
curl -fsSL https://raw.githubusercontent.com/silent4time/virabot/main/install.sh | sudo bash
```

Choose **1) Install**. The script asks for the domain, Telegram token, Bale token, and database details.

## 🔄 Update

```bash
sudo virabot update
```

Or choose **2) Update**. Core files are backed up first.

## 📋 Management menu

<div align="center">

| Option | Action |
|--------|--------|
| 1 | Install |
| 2 | Update |
| 3 | Remove |
| 4 | Reset webhook and domain |
| 5 | Renew SSL |
| 6 | Help |
| 7 | Install status |
| 8 | Bots and groups |
| 9 | List installs |
| 0 | Exit |

</div>

## 📦 Repository

<div align="center">

| File | Role |
|------|------|
| `install.sh` | One-line installer |
| `virabot-latest.zip` | Latest package |
| `VERSION` | Version number |
| `CHANGELOG.md` | Changes |
| `README.md` | This page |
| `docs/virabot-guide.pdf` | Illustrated guide |

</div>

## 💛 Support the project

USDT / TON on BEP20:

```text
0xa1a89602DDB47682091Af3Da1737672bd97f5669
```

## ⚠️ Disclaimer

This software is for managing your own services. The operator is responsible for the server, domain, security, and local laws.

**Repo:** [silent4time/virabot](https://github.com/silent4time/virabot)
