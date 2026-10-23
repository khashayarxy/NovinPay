# راهنمای استقرار NovinPay (هاست اشتراکی / cPanel)

> **سایت موجود: توقف قبل از اجرای دستورات عمومی این سند.** راهنمای فعلی و شرایط سازگاری در [MARIADB-COMPATIBILITY.md](MARIADB-COMPATIBILITY.md) است. baseline اعلام‌شدهٔ تولید هفت migration دارد؛ کلید/دادهٔ آن باید حفظ شود. مراحل قدیمی نصب تازه، bootstrap مدیر را کامل پوشش نمی‌دهند. MySQL 5.7 و MariaDB قدیمی پشتیبانی نمی‌شوند. هیچ انتشار یا migration تولید در این بررسی اجرا نشده است.

این سند چک‌لیست عملیاتی برای استقرار وب‌سایت روی هاست اشتراکی لینوکس/cPanel است.
دستورات را دقیقاً به همین ترتیب اجرا کنید. مقادیر واقعی رمز و `.env` را **هرگز** در
این فایل یا هیچ فایل دیگری که متعهد (commit) می‌کنید وارد نکنید.

---

## ۱. پیش‌نیازها (از ارائه‌دهندهٔ هاست بپرسید)

| مورد | حداقل نیاز | چگونه بررسی کنیم |
| --- | --- | --- |
| نسخهٔ PHP | حداقل `8.4.1` برای وابستگی‌های فعلی قفل‌شده؛ هم CLI و هم وب | `php -v` یا «Select PHP Version» در cPanel |
| افزونه‌های PHP | `mbstring`, `openssl`, `pdo_mysql` (یا `pdo_mariadb`), `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `dom`, `bcmath` | `php -m` |
| Composer | نصب و در دسترس CLI | `composer -V` |
| دسترسی CLI/SSH | برای `artisan` (migrate, cache, storage:link) | تماس با پشتیبانی |
| دیتابیس | MySQL 8.0.16+ یا MariaDB 10.6.28 در شاخهٔ 10.6 با اتصال بومی `mariadb`؛ InnoDB، CHECK/FK/trigger و `utf8mb4_unicode_ci`؛ پیش‌شرط‌های سند سازگاری الزامی‌اند | پنل cPanel → MySQL Databases |
| `symlink` | برای `php artisan storage:link` (اختیاری — بخش ۶ را ببینید) | `php artisan storage:link` |
| `exec`/`shell_exec` | برای اجرای artisan (معمولاً در هاست اشتراکی فعال است) | `php -m` / تماس با پشتیبانی |
| SSL | گواهی رایگان Let's Encrypt یا SSL هاست | پنل cPanel → SSL/TLS |

> اگر SSH محدود یا غیرفعال است، بخش «استقرار بدون SSH» (بخش ۸) را ببینید.

---

## ۲. بارگذاری کد و ساختار مسیرها

1. کد را روی سرور بارگذاری کنید (Git clone یا آپلود ZIP).
2. **کل پروژه را در خانهٔ کاربر بگذارید (مثلاً `~/novinpay`) — هرگز داخل
   `public_html`.** `.env`، `vendor/`، `storage/`، `bootstrap/` و `.git` نباید
   از طریق HTTP قابل دسترسی باشند.
3. **ریشهٔ دامنه (Document Root) باید روی پوشهٔ `public/` پروژه تنظیم شود.**

### حالت الف: cPanel اجازهٔ تغییر Document Root می‌دهد (ترجیحی)

- از ابزار «Domains» در cPanel، ریشهٔ دامنه را به `~/novinpay/public` تغییر دهید
  (مسیر دقیق را ابزار فایل‌منیجر نشان می‌دهد).
- رمز عبور دامنه (Directory Password) روی هیچ مسیری فعال نباشد.

### حالت ب: Document Root ثابت روی `public_html` است (fallback)

بعضی هاست‌های اشتراکی ریشهٔ دامنه را تغییر نمی‌دهند. در این حالت به ترتیب:

1. **symlink:** اگر هاست symlink برای دایرکتوری‌های حساب را مجاز می‌داند،
   `public_html` را به پوشهٔ عمومی پروژه لینک کنید (مسیرهای نمونه — با توجه به
   home directory واقعی خود تنظیم کنید):

   ```bash
   # فقط وقتی public_html خالی/قابل جایگزینی است:
   mv ~/public_html ~/public_html.bak
   ln -s ~/novinpay/public ~/public_html
   ```

2. **درخواست از پشتیبانی:** اگر symlink مجاز نیست، از پشتیبانی Aseman Host
   بخواهید Document Root دامنه را به `~/novinpay/public` تغییر دهند (اکثر
   هاست‌های cPanel این کار را درخواستی انجام می‌دهند).

3. **هرگز** کل پروژه (به‌ویژه `.env` یا `vendor/`) را داخل `public_html` کپی
   نکنید؛ در این حالت کد و اسرار از طریق وب قابل دسترسی می‌شوند.

پس از تنظیم ریشه، مطمئن شوید `https://your-domain.com/up` پاسخ سالم می‌دهد.

---

## ۳. پیکربندی امن `.env` (بدون نمایش مقادیر)

در ریشهٔ پروژه (غیر از `public/`) فایل `.env` بسازید و فقط این متغیرها را تنظیم کنید
(مقادیر را از `.env.example` کپی کنید و پر کنید):

```bash
cp .env.example .env
```

```ini
APP_NAME="NovinPay"
APP_ENV=production
APP_KEY=                     # بعداً با key:generate پر می‌شود
APP_DEBUG=false              # هرگز در تولید true نکنید
APP_URL=https://your-domain.com

APP_LOCALE=fa

DB_CONNECTION=mysql         # یا mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_db_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true  # نیازمند SSL

LOG_CHANNEL=stack
LOG_LEVEL=error

CACHE_STORE=database
QUEUE_CONNECTION=database

ADMIN_EMAIL=you@example.com  # ایمیل ورود مدیر
ADMIN_PASSWORD=              # گذرواژهٔ قوی (حداقل ۸ نویسه، بهتر ۱۶+)
ADMIN_LOGIN_IP_MAX_ATTEMPTS=30
```

**نکات امنیتی:**
- `APP_DEBUG=false` در تولید **اجباری** است؛ در غیر این صورت جزئیات stack trace و
  مقادیر متغیرهای محیطی در صفحهٔ خطا نمایش داده می‌شود.
- `.env` را هرگز متعهد نکنید (در `.gitignore` هست) و دسترسی آن را `600` کنید:
  `chmod 600 .env`
- `APP_URL` را با `https://` تنظیم کنید تا لینک‌های تولیدشده اجباراً https شوند.
  ریدایرکت اجباری `http` به `https` بخشی از کد برنامه نیست و باید در لایهٔ
  هاست/پراکسی فعال شود (بخش ۱۰). برای امنیت کوکی نشست نیز `SESSION_SECURE_COOKIE=true`
  لازم است.
- گذرواژهٔ دیتابیس و مدیر را از تولیدکنندهٔ رمز استفاده کنید؛ آن‌ها را در تیکت یا
  ایمیل باز نگذارید.

سپس کلید برنامه را بسازید:

```bash
php artisan key:generate
```

---

## ۴. ساخت دیتابیس و اجرای migration

در پنل cPanel یک دیتابیس و کاربر بسازید و به کاربر دسترسی کامل (ALL PRIVILEGES) بدهید.

سپس از CLI (SSH) اجرا کنید:

```bash
php artisan migrate --force
```

> `--force` برای اجرای migration در محیط `production` ضروری است؛ بدون آن artisan
> به‌خاطر `APP_ENV=production` از اجرا خودداری می‌کند.
>
> هر دو مهاجرت جستجو (`add_search_text_to_products` و `renormalize_search_text`)
> قابل‌تکرار نوشته شده‌اند: اولی ستون را با گارد `hasColumn` می‌سازد و فقط ردیف‌های
> NULL را ترمیم می‌کند؛ دومی یک بازمحاسبهٔ داده‌ای (idempotent) است. پس اگر هر کدام
> وسط کار شکست خورد، اجرای دوبارهٔ `php artisan migrate --force` امن است. سایر
> مهاجرت‌ها چنین گاردی ندارند؛ شکست آن‌ها باید جداگانه بررسی شود.

**ترتیب مهم:** migration را **پیش از** سید کردن ادمین اجرا کنید (جدول `users` لازم است).

---

## ۵. ساخت حساب مدیر (بدون ثبت‌نام عمومی)

ثبت‌نام عمومی وجود ندارد؛ تنها راه ساخت مدیر، اجرای seeder است:

```bash
php artisan db:seed --class=AdminUserSeeder --force
```

- این دستور از `ADMIN_EMAIL` / `ADMIN_PASSWORD` (از طریق `config/admin.php`) استفاده می‌کند.
- چون از `config()` خوانده می‌شود، **بعد از `config:cache` هم کار می‌کند**.
- اگر مقادیر خالی باشند، seeder با پیام خطای واضح متوقف می‌شود.
- بعد از ساخت مدیر، **`ADMIN_PASSWORD` را از `.env` پاک کنید** (فقط برای ساخت لازم بود).

---

## ۶. تصاویر محصولات و `storage:link`

### بودجهٔ فضای ۱ گیگابایتی

- ابعاد تقریبی خودِ اپ روی سرور: `vendor/` حدود ۴۶ مگابایت (بدون dev-dependency)،
  `public/build/` حدود ۰.۲ مگابایت، بقیهٔ کد کمتر از ۱ مگابایت — جمعاً زیر ۶۰
  مگابایت.
- `node_modules/` (حدود ۶۲ مگابایت) هرگز روی سرور آپلود نشود؛ بیلد فقط لوکال
  انجام می‌شود (بخش ۷).
- رشد اصلی دیسک: تصاویر محصولات (سقف هر تصویر ۲ مگابایت است) و پشتیبان‌های
  دیتابیس. پشتیبان دیتابیس را با phpMyAdmin **خارج از هاست** نگه دارید تا فضا
  مصرف نشود.
- لاگ‌ها در `storage/logs` جمع می‌شوند؛ `LOG_LEVEL=error` در تولید حجم را کم
  نگه می‌دارد و در صورت نیاز می‌توان لاگ‌های قدیمی را دور ریخت.

تصاویر روی دیسک `public` و در `storage/app/public/products/` ذخیره می‌شوند و با
`asset('storage/...')` سرو می‌شوند. این الگو نیازمند یک **symlink** به نام
`public/storage` است.

### حالت الف: هاست symlink پشتیبانی می‌کند (معمول)

```bash
php artisan storage:link
```

### حالت ب: هاست symlink پشتیبانی نمی‌کند (محدود)

در این حالت `public/storage` ساخته نمی‌شود و تصاویر ۴۰۴ می‌شوند. جایگزین امن:

1. پوشهٔ `public/storage` را به‌صورت دستی بسازید (symlink نه — پوشهٔ واقعی).
2. بعد از هر بارگذاری تصویر جدید، محتوای `storage/app/public/products/` را با
   آپلودر فایل cPanel یا اسکریپت کپی، داخل `public/storage/products/` کپی کنید.

> یا: یک تیکت به پشتیبانی هاست بدهید که `symlink` را برای این اکانت فعال کنند (اکثر
> هاست‌های معتبر این کار را انجام می‌دهند). روش الف ساده‌تر و ترجیحی است.

---

## ۷. بیلد فرانت‌اند و کش‌ها (ترتیب اجرا)

اگر Node.js روی هاست در دسترس نیست، بیلد را **لوکال** انجام دهید و پوشهٔ
`public/build/` را بارگذاری کنید (در `.gitignore` هست، پس باید جداگانه آپلود شود).

ترتیب اجرای دستورات روی سرور:

```bash
# 1) وابستگی‌ها (اگر SSH/Composer در دسترس است)
composer install --no-dev --optimize-autoloader

# 2) بیلد فرانت‌اند (لوکال: npm ci && npm run build — سپس public/build را آپلود کنید)

# 3) مایگریشن (بخش ۴)
php artisan migrate --force

# 4) ساخت ادمین (بخش ۵)
php artisan db:seed --class=AdminUserSeeder --force

# 5) symlink تصاویر (بخش ۶)
php artisan storage:link

# 6) کش‌ها — آخرین مرحله
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan cache:warm   # در صورت وجود
```

> **نکتهٔ ترتیب:** `config:cache` را **بعد از** پر کردن کامل `.env` اجرا کنید؛ در غیر
> این صورت مقادیر کش‌شده قدیمی می‌مانند. اگر `.env` را تغییر دادید، دوباره
> `php artisan config:cache` را اجرا کنید.

---

## ۸. استقرار بدون SSH / Composer

اگر SSH یا Composer در دسترس نیست:

1. **وابستگی‌ها را لوکال نصب کنید:** `composer install --no-dev --optimize-autoloader`
   سپس کل پوشهٔ `vendor/` را بارگذاری کنید.
2. **بیلد فرانت‌اند را لوکال انجام دهید:** `npm ci && npm run build` (نسخه‌های
   دقیق از `package-lock.json` نصب می‌شوند) و پوشهٔ `public/build/` را
   بارگذاری کنید.
3. **مایگریشن را لوکال اجرا نکنید** روی دیتابیس تولید؛ در عوض از «phpMyAdmin» هاست
   استفاده کنید یا از پشتیبانی بخواهید artisan را اجرا کنند. ساده‌تر: اگر هاست
   «Terminal» در cPanel دارد، همان‌جا `php artisan migrate --force` را بزنید.
4. `.env` را از طریق فایل‌منیجر cPanel بسازید (دسترسی ۶۰۰).
5. کش‌ها را اگر CLI دارید بزنید؛ اگر نه، Laravel بدون کش هم کار می‌کند (کندتر).
6. برای ساخت ادمین بدون CLI: اگر `phpMyAdmin` دارید، نمی‌توانید هش را دستی بسازید.
   راه حل: یک بار CLI کوچک بخواهید یا از قابلیت «SSH محدود» هاست استفاده کنید.

> **بلاکر صریح:** اگر هیچ CLI/SSH در دسترس نباشد، اجرای `php artisan` (migrate, seed,
> cache, storage:link) ممکن نیست. در این حالت از ارائه‌دهنده بخواهید دسترسی اجرا
> بدهد یا از هاستی با Terminal استفاده کنید.

---

## ۹. مجوز دایرکتوری‌های قابل نوشتن

اگر با خطای «Permission denied» مواجه شدید، مجوزها را تنظیم کنید:

```bash
chmod -R 775 storage bootstrap/cache
# در صورت نیاز مالک را با دستور chown پنل تنظیم کنید
```

`storage/` و `bootstrap/cache/` باید برای کاربر وب‌سرور قابل نوشتن باشند. پوشهٔ
`public/` نیازی به نوشتن ندارد (به‌جز `public/storage` در حالت بدون symlink).

---

## ۱۰. SSL و امنیت

1. SSL را در cPanel فعال کنید و «Force HTTPS Redirect» را روشن کنید.
2. `APP_URL=https://...` و `SESSION_SECURE_COOKIE=true` باشند (بخش ۳).
3. بعد از فعال شدن SSL، یک بار `php artisan config:cache` بزنید تا مطمئن شوید.
4. بررسی کنید `http://` به `https://` ریدایرکت می‌شود و کوکیِ `Secure` دارد.

> کد برنامه فقط scheme لینک‌های تولیدشده را هنگام `APP_URL` از نوع https اجبار می‌کند؛
> ریدایرکت `http` به `https` و پرچم `Secure` کوکی از تنظیمات هاست و
> `SESSION_SECURE_COOKIE` می‌آیند، نه از کد PHP.

---

## ۱۱. پشتیبان‌گیری و بازگردانی

**چه چیزی را پشتیبان بگیریم:**
- دیتابیس MySQL/MariaDB (از phpMyAdmin یا `mysqldump`) — **مهم‌ترین** بخش.
- پوشهٔ `storage/app/public/products/` (تصاویر محصولات).
- فایل `.env` (محل امن؛ هرگز در ریپو).

**بازگردانی:**
1. کد قبلی را از Git بازگردانی کنید (یا نسخهٔ بارگذاری‌شده را نگه دارید).
2. دیتابیس را از dump برگردانید.
3. تصاویر را برگردانید.
4. کش‌ها را پاک و دوباره بسازید: `php artisan config:cache` و `route:cache`.

---

## ۱۲. چک‌لیست تأیید بعد از استقرار

- [ ] `https://your-domain.com/` بارگذاری می‌شود و RTL/فارسی است.
- [ ] `https://your-domain.com/products` محصولات را نشان می‌دهد.
- [ ] جستجوی فارسی (با ارقام فارسی/عربی، ی/ك و نیم‌فاصله) درست کار می‌کند.
- [ ] `https://your-domain.com/admin/login` فرم ورود را نشان می‌دهد.
- [ ] ورود با `ADMIN_EMAIL`/`ADMIN_PASSWORD` کار می‌کند.
- [ ] بعد از ۵ تلاش ناموفق، پیام محدودیت نرخ فارسی نمایش داده می‌شود.
- [ ] تصویر یک محصول بارگذاری‌شده نمایش داده می‌شود (نه ۴۰۴).
- [ ] صفحهٔ ۴۰۴ ناشناخته فارسی است (نه پیش‌فرض انگلیسی Laravel).
- [ ] پاسخ‌ها هدر `X-Content-Type-Options: nosniff` و `X-Frame-Options: SAMEORIGIN` دارند.
- [ ] `APP_DEBUG=false` است (خطای عمدی نباید stack trace نشان دهد).
- [ ] `/up` (health route) پاسخ سالم می‌دهد.

---

## ۱۳. عیب‌یابی سریع

| علامت | علت محتمل | راه حل |
| --- | --- | --- |
| صفحهٔ خالی یا خطای ۵۰۰ | `.env` ناقص یا `APP_KEY` خالی | `.env` را کامل کنید، `key:generate` بزنید |
| خطای دیتابیس | `DB_*` اشتباه یا عدم دسترسی کاربر | اعتبارنامه و ALL PRIVILEGES بررسی کنید |
| خطای ۵۰۰ با پیام Vite manifest | پوشهٔ `public/build/` آپلود نشده یا ناقص است | بیلد لوکال (`npm ci && npm run build`) و آپلود `public/build/` (بخش ۷) |
| تصویر ۴۰۴ | symlink ساخته نشده | `storage:link` یا روش کپی (بخش ۶) |
| خطای Permission | مجوز `storage/` | `chmod -R 775 storage` |
| کش قدیمی بعد از تغییر `.env` | `config:cache` قدیمی | دوباره `php artisan config:cache` |
| نمایش جزئیات خطا در تولید | `APP_DEBUG=true` | `false` کنید و کش را بزنید |
| فایل‌های `.env` یا کد از وب قابل دانلود هستند | Document Root روی ریشهٔ پروژه است | ریشه را روی `public/` بگذارید (بخش ۲) |
