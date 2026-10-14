<?php
require_once 'config.php';

// اگر ادمین از داخلِ پنلِ مدیریت، توکن/آیدیِ بله را ویرایش کرده باشد
// (در جدولِ PaySetting ذخیره می‌شود)، آن مقدار به مقدارِ config.php
// اولویت دارد - تا تغییرات بدونِ نیاز به نصبِ دوباره اعمال شوند.
if (!empty($ENABLE_BALE) && isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT NamePay, ValuePay FROM PaySetting WHERE NamePay IN ('bale_bot_token','bale_admin_id','bale_report_group_id','merchant_balepay')");
        $baleOverrides = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($baleOverrides['bale_bot_token'])) {
            $BALE_APIKEY = $baleOverrides['bale_bot_token'];
        }
        if (!empty($baleOverrides['bale_admin_id'])) {
            $BALE_ADMIN_ID = $baleOverrides['bale_admin_id'];
        }
        if (!empty($baleOverrides['bale_report_group_id'])) {
            $BALE_REPORT_GROUP_ID = $baleOverrides['bale_report_group_id'];
        }
        if (!empty($baleOverrides['merchant_balepay']) && $baleOverrides['merchant_balepay'] !== '0') {
            $BALE_PROVIDER_TOKEN = $baleOverrides['merchant_balepay'];
        }
    } catch (Exception $e) {
        // جدولِ PaySetting شاید هنوز ساخته نشده باشد (مثلاً حین نصبِ اولیه) - بی‌خطر است
    }
}

// =====================================================================
//  PLATFORM ROUTING (Telegram + Bale)
// =====================================================================
// شناسه‌ی درخواست جاری: هنگام ثبت وبهوک، آدرس تلگرام را بدون پارامتر
// و آدرس بله را با ?platform=bale ثبت می‌کنید، مثلا:
//   Telegram : https://yourdomain.com/index.php
//   Bale     : https://yourdomain.com/index.php?platform=bale
// این مقدار فقط برای درخواست‌هایی که chat_id مشخصی ندارند (getFile،
// answerInlineQuery و ...) استفاده می‌شود؛ برای بقیه‌ی موارد platform
// از روی خودِ chat_id (آفست بله) تشخیص داده می‌شود تا کرون‌جاب‌ها هم
// که هم‌زمان به کاربران هر دو پلتفرم پیام می‌دهند درست کار کنند.
$CURRENT_PLATFORM = 'telegram';
if (isset($_GET['platform']) && strtolower($_GET['platform']) === 'bale') {
    $CURRENT_PLATFORM = 'bale';
}

// آیا این عدد، شناسه‌ی داخلی یک کاربر بله است؟ (یعنی با آفست جمع شده)
// آدرس دانلود فایل (نتیجه‌ی getFile) را متناسب با پلتفرم درخواست جاری می‌سازد.
// نکته: مسیر فایل بله را قبل از استفاده در پروداکشن با مستندات رسمی بله
// (dev.bale.ai) مطابقت دهید.
function resolveFileDownloadUrl($filePath)
{
    global $CURRENT_PLATFORM, $TELEGRAM_APIKEY, $BALE_APIKEY;
    if ($CURRENT_PLATFORM === 'bale') {
        return "https://tapi.bale.ai/file/bot{$BALE_APIKEY}/{$filePath}";
    }
    return "https://api.telegram.org/file/bot{$TELEGRAM_APIKEY}/{$filePath}";
}

// تگ‌های رایجِ HTML که میرزا بات برای تلگرام استفاده می‌کند را به معادلِ
// متنیِ ساده تبدیل می‌کند (فقط برای پیام‌های مقصدِ بله استفاده می‌شود)
function stripHtmlForBale($text)
{
    // <a href="URL">متن</a>  →  متن (URL)
    $text = preg_replace_callback(
        '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
        function ($m) {
            $label = trim(strip_tags($m[2]));
            $url = $m[1];
            return ($label !== '' && $label !== $url) ? "{$label} ({$url})" : $url;
        },
        $text
    );
    // خطِ جدید برای تگ‌های بلوکی، تا بعدِ حذفِ تگ‌ها متن به‌هم نچسبد
    $text = preg_replace('/<\/?(br|p|div)\s*\/?>/i', "\n", $text);
    // بقیه‌ی تگ‌ها (b, strong, i, em, u, code, pre, ...) حذف می‌شوند ولی متنِ داخلشان می‌ماند
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $text;
}

function isBaleInternalId($id)
{
    return is_numeric($id) && (float)$id >= BALE_ID_OFFSET;
}

// شناسه‌ی خام کاربر (بدون آفست) را برای فراخوانی واقعیِ API برمی‌گرداند
function stripBaleOffset($id)
{
    if (isBaleInternalId($id)) {
        return (string) ((float)$id - BALE_ID_OFFSET);
    }
    return $id;
}

// شناسه‌ی خام دریافتی از وبهوک بله را به شناسه‌ی داخلی (آفست‌دار) تبدیل می‌کند
// تا در دیتابیس مشترک با کاربران تلگرام تداخل پیدا نکند
function toBaleInternalId($rawId)
{
    if ($rawId === null || $rawId === '' || !is_numeric($rawId)) {
        return $rawId;
    }
    return (string) ((float)$rawId + BALE_ID_OFFSET);
}

// تشخیص می‌دهد یک chat_id مشخص باید از طریق تلگرام برود یا بله
// خروجی: ['platform' => 'telegram'|'bale', 'base' => ..., 'token' => ..., 'raw_chat_id' => ...]
// $forcePlatform: وقتی 'bale' باشد، صرفِ‌نظر از مقدارِ chat_id، مسیر بله
// انتخاب می‌شود - برای مقاصدی مثل گروهِ گزارشاتِ بله که خودش یک شناسه‌ی
// خامِ بله است (نه شناسه‌ی آفست‌دارِ یک کاربر) و نباید با ترفندِ آفست تشخیص
// داده شود، چون آیدیِ گروه ممکن است عددی بزرگ یا منفی باشد.
function resolvePlatformForRequest($datas, $explicitToken = null, $forcePlatform = null)
{
    global $TELEGRAM_APIKEY, $BALE_APIKEY, $TELEGRAM_API_BASE, $BALE_API_BASE;
    global $ENABLE_BALE, $CURRENT_PLATFORM;

    $chatId = $datas['chat_id'] ?? null;

    if ($forcePlatform === 'bale') {
        return [
            'platform' => 'bale',
            'base' => $BALE_API_BASE,
            'token' => $BALE_APIKEY,
            'raw_chat_id' => $chatId,
        ];
    }

    if ($chatId !== null && $ENABLE_BALE && isBaleInternalId($chatId)) {
        return [
            'platform' => 'bale',
            'base' => $BALE_API_BASE,
            'token' => $BALE_APIKEY,
            'raw_chat_id' => stripBaleOffset($chatId),
        ];
    }

    if ($chatId === null && $ENABLE_BALE && $CURRENT_PLATFORM === 'bale') {
        // درخواست‌هایی که chat_id ندارند (getFile و ...)، بر اساس پلتفرم درخواست جاری
        return [
            'platform' => 'bale',
            'base' => $BALE_API_BASE,
            'token' => $BALE_APIKEY,
            'raw_chat_id' => null,
        ];
    }

    return [
        'platform' => 'telegram',
        'base' => $TELEGRAM_API_BASE,
        'token' => $explicitToken !== null ? $explicitToken : $TELEGRAM_APIKEY,
        'raw_chat_id' => $chatId,
    ];
}

// عکس/فایلی که تلگرام برایمان فرستاده (با یک file_id تلگرامی) را دانلود
// کرده و بایت‌های خام را برمی‌گرداند - چون file_id های تلگرام روی بله
// معتبر نیستند و برای آینه‌کردنِ گزارش‌ها باید فایل واقعی دوباره آپلود شود.
function downloadTelegramFile($fileId)
{
    global $TELEGRAM_APIKEY;
    $fileInfo = telegram('getFile', ['file_id' => $fileId], $TELEGRAM_APIKEY);
    if (empty($fileInfo['ok']) || empty($fileInfo['result']['file_path'])) {
        return null;
    }
    $url = "https://api.telegram.org/file/bot{$TELEGRAM_APIKEY}/{$fileInfo['result']['file_path']}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $bytes = curl_exec($ch);
    curl_close($ch);
    return ($bytes !== false && strlen($bytes) > 0) ? $bytes : null;
}

// یک نسخه از پیامِ گزارشی که به گروهِ گزارشاتِ تلگرام فرستاده می‌شود را
// عیناً برای ادمینِ بله هم می‌فرستد (فقط اگر بله فعال و آیدیِ ادمینِ بله
// تنظیم شده باشد) - تا درخواست‌های خرید، اسکرین‌شاتِ تراکنش و... در هر
// دو پلتفرم برای ادمین قابل‌مشاهده و قابل‌تأیید باشند.
function mirrorReportToBale($method, $datas)
{
    global $ENABLE_BALE, $BALE_ADMIN_ID, $BALE_REPORT_GROUP_ID, $pdo;
    if (empty($ENABLE_BALE)) {
        return;
    }
    try {
        if (isset($pdo)) {
            $row = $pdo->query("SELECT ValuePay FROM PaySetting WHERE NamePay='bale_report_group_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['ValuePay']) && $row['ValuePay'] !== '0') {
                $BALE_REPORT_GROUP_ID = $row['ValuePay'];
            }
        }
    } catch (Exception $e) {
    }
    if (!empty($BALE_REPORT_GROUP_ID) && $BALE_REPORT_GROUP_ID !== '0') {
        $mirrorChatId = $BALE_REPORT_GROUP_ID;
    } elseif (!empty($BALE_ADMIN_ID)) {
        $mirrorChatId = toBaleInternalId($BALE_ADMIN_ID);
    } else {
        error_log('mirrorReportToBale: no target chat');
        return;
    }

    $methodLower = strtolower((string) $method);
    $caption = '';
    if (isset($datas['caption']) && is_string($datas['caption'])) {
        $caption = $datas['caption'];
    } elseif (isset($datas['text']) && is_string($datas['text'])) {
        $caption = $datas['text'];
    }

    // --- فایل واقعی (بکاپ / document) ---
    if (in_array($methodLower, ['senddocument', 'sendDocument'], true) || $methodLower === 'senddocument') {
        $doc = $datas['document'] ?? null;
        $tmp = null;
        $curlFile = null;
        if ($doc instanceof CURLFile) {
            // هرگز همان CURLFile مصرف‌شده را دوباره نفرست — کپی موقت
            $src = $doc->getFilename();
            if (is_string($src) && is_file($src)) {
                $tmp = tempnam(sys_get_temp_dir(), 'baledoc_');
                @copy($src, $tmp);
                $curlFile = new CURLFile($tmp, 'application/octet-stream', basename($src));
            }
        } elseif (is_string($doc) && is_file($doc)) {
            $tmp = tempnam(sys_get_temp_dir(), 'baledoc_');
            @copy($doc, $tmp);
            $curlFile = new CURLFile($tmp, 'application/octet-stream', basename($doc));
        } elseif (is_string($doc) && $doc !== '') {
            // file_id تلگرام — دانلود و آپلود مجدد برای بله
            $bytes = downloadTelegramFile($doc);
            if ($bytes !== null && strlen($bytes) > 0) {
                $tmp = tempnam(sys_get_temp_dir(), 'baledoc_');
                file_put_contents($tmp, $bytes);
                $curlFile = new CURLFile($tmp, 'application/octet-stream', 'backup.sql');
            }
        }
        if ($curlFile instanceof CURLFile) {
            $payload = [
                'chat_id' => $mirrorChatId,
                'document' => $curlFile,
            ];
            if ($caption !== '') {
                $payload['caption'] = mb_substr(stripHtmlForBale($caption), 0, 900);
            }
            $res = telegram('sendDocument', $payload, null, 'bale');
            if ($tmp) {
                @unlink($tmp);
            }
            if (empty($res['ok'])) {
                error_log('mirrorReportToBale document fail: ' . json_encode($res));
                // fallback متن
                if ($caption !== '') {
                    telegram('sendmessage', ['chat_id' => $mirrorChatId, 'text' => stripHtmlForBale($caption)], null, 'bale');
                }
            }
            return;
        }
        // بدون فایل فقط caption
        if ($caption !== '') {
            telegram('sendmessage', ['chat_id' => $mirrorChatId, 'text' => stripHtmlForBale($caption)], null, 'bale');
        }
        return;
    }

    // --- عکس ---
    if ($methodLower === 'sendphoto') {
        $photo = $datas['photo'] ?? null;
        if (is_string($photo) && $photo !== '') {
            $bytes = downloadTelegramFile($photo);
            if ($bytes !== null) {
                $tmp = tempnam(sys_get_temp_dir(), 'baleph_');
                file_put_contents($tmp, $bytes);
                $payload = [
                    'chat_id' => $mirrorChatId,
                    'photo' => new CURLFile($tmp, 'image/jpeg', 'photo.jpg'),
                ];
                if ($caption !== '') {
                    $payload['caption'] = mb_substr(stripHtmlForBale($caption), 0, 900);
                }
                $res = telegram('sendphoto', $payload, null, 'bale');
                @unlink($tmp);
                if (empty($res['ok'])) {
                    error_log('mirrorReportToBale photo fail: ' . json_encode($res));
                }
                return;
            }
        }
    }

    // --- متن ---
    $text = $caption !== '' ? $caption : '[report]';
    $res = telegram('sendmessage', [
        'chat_id' => $mirrorChatId,
        'text' => stripHtmlForBale($text),
    ], null, 'bale');
    if (empty($res['ok'])) {
        error_log('mirrorReportToBale text fail: ' . json_encode($res));
    }
}

function telegram($method, $datas = [], $token = null, $forcePlatform = null)
{
    global $APIKEY, $TELEGRAM_APIKEY, $setting;

    $route = resolvePlatformForRequest($datas, $token, $forcePlatform);

    // آینه‌کردنِ گزارش‌های ادمین برای بله - فقط پیام‌های گروهِ گزارش تلگرام
    static $mirrorGuard = false;
    if (!$mirrorGuard
        && empty($GLOBALS['MV_SKIP_MIRROR'])
        && $route['platform'] === 'telegram'
        && isset($datas['chat_id'])
        && in_array(strtolower((string) $method), ['sendmessage', 'sendphoto', 'senddocument', 'sendvideo'], true)
    ) {
        $reportChat = '';
        if (isset($setting['Channel_Report']) && $setting['Channel_Report'] !== '' && $setting['Channel_Report'] !== '0') {
            $reportChat = (string) $setting['Channel_Report'];
        }
        // fallback از دیتابیس اگر $setting خالی بود
        if ($reportChat === '' && isset($GLOBALS['pdo'])) {
            try {
                $r = $GLOBALS['pdo']->query("SELECT Channel_Report FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!empty($r['Channel_Report']) && $r['Channel_Report'] !== '0') {
                    $reportChat = (string) $r['Channel_Report'];
                }
            } catch (Exception $e) {
            }
        }
        if ($reportChat !== '' && (string) $datas['chat_id'] === $reportChat) {
            $mirrorGuard = true;
            try {
                mirrorReportToBale($method, $datas);
            } finally {
                $mirrorGuard = false;
            }
        }
    }
    // بله برخی متدها را پشتیبانی نمی‌کند (مثل حالت inline)
    if ($route['platform'] === 'bale' && in_array($method, ['answerInlineQuery'], true)) {
        return ['ok' => false, 'description' => 'This method is not supported on Bale.'];
    }

    // بله (برخلاف تلگرام) parse_mode/HTML را پشتیبانی نمی‌کند؛ اگر بدون این
    // تبدیل پیام را بفرستیم، کاربرِ بله تگ‌های خام مثل <b> و <a href=...> را
    // به‌صورت متنِ عادی می‌بیند. برای همین اینجا (و فقط برای بله) این تگ‌ها
    // را به معادلِ متنیِ ساده تبدیل می‌کنیم، بدون آنکه چیزی برای تلگرام تغییر کند.
    if ($route['platform'] === 'bale') {
        unset($datas['parse_mode']);
        foreach (['text', 'caption'] as $textField) {
            if (isset($datas[$textField]) && is_string($datas[$textField])) {
                $datas[$textField] = stripHtmlForBale($datas[$textField]);
            }
        }
    }

    if (array_key_exists('chat_id', $datas)) {
        $datas['chat_id'] = $route['raw_chat_id'];
    }

    $finalToken = $route['token'];
    $url = $route['base'] . $finalToken . "/" . $method;

    if (isset($datas['message_thread_id']) && intval($datas['message_thread_id']) <= 0) {
        unset($datas['message_thread_id']);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        error_log('Unable to initialise cURL for Telegram request.');
        return [
            'ok' => false,
            'description' => 'Unable to initialise cURL for Telegram request.'
        ];
    }

    $hasFile = false;
    foreach ($datas as $v) {
        if ($v instanceof CURLFile) {
            $hasFile = true;
            break;
        }
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $hasFile ? 180 : 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    // جلوگیری از abort زودهنگام روی آپلود فایل
    if (defined('CURLOPT_NOSIGNAL')) {
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    }

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $curlError = curl_error($ch);

        if ($curlError !== '') {
            error_log('Telegram request failed: ' . $curlError);
        }

        return [
            'ok' => false,
            'description' => $curlError !== '' ? $curlError : 'Telegram request failed.'
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $decodedResponse = json_decode($rawResponse, true);
    if (!is_array($decodedResponse)) {
        $logSnippet = substr($rawResponse, 0, 200);
        error_log(sprintf('Invalid response from Telegram API (HTTP %d): %s', $httpCode, $logSnippet));

        return [
            'ok' => false,
            'error_code' => $httpCode,
            'description' => 'Invalid response received from Telegram.'
        ];
    }

    if (isset($decodedResponse['ok']) && !$decodedResponse['ok']) {
        error_log(json_encode($decodedResponse));
    }

    return $decodedResponse;
}
function sendmessage($chat_id,$text,$keyboard,$parse_mode,$bot_token = null){
    if(intval($chat_id) == 0)return ['ok' => false];
    return telegram('sendmessage',[
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,
        
        ],$bot_token);
}
function sendDocument($chat_id, $documentPath, $caption) {
        return telegram('sendDocument',[
        'chat_id' => $chat_id,
        'document' => new CURLFile($documentPath),
        'caption' => $caption,
        ]);
}

function forwardMessage($chat_id,$message_id,$chat_id_user){
    return telegram('forwardMessage',[
        'from_chat_id'=> $chat_id,
        'message_id'=> $message_id,
        'chat_id'=> $chat_id_user,
    ]);
}
function sendphoto($chat_id,$photoid,$caption){
    telegram('sendphoto',[
        'chat_id' => $chat_id,
        'photo'=> $photoid,
        'caption'=> $caption,
    ]);
}
function sendvideo($chat_id,$videoid,$caption){
    telegram('sendvideo',[
        'chat_id' => $chat_id,
        'video'=> $videoid,
        'caption'=> $caption,
    ]);
}
function senddocumentsid($chat_id,$documentid,$caption){
    telegram('sendDocument',[
        'chat_id' => $chat_id,
        'document'=> $documentid,
        'caption'=> $caption,
    ]);
}
function Editmessagetext($chat_id, $message_id, $text, $keyboard,$parse_mode = 'HTML'){
    return telegram('editmessagetext', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,

    ]);
}
 function deletemessage($chat_id, $message_id){
  telegram('deletemessage', [
'chat_id' => $chat_id, 
'message_id' => $message_id,
]);
 }
function getFileddire($photoid){
  return telegram('getFile', [
'file_id' => $photoid, 
]);
 }
function pinmessage($from_id,$message_id){
  return telegram('pinChatMessage', [
'chat_id' => $from_id, 
'message_id' => $message_id, 
]);
 }
 function unpinmessage($from_id){
  return telegram('unpinAllChatMessages', [
'chat_id' => $from_id, 
]);
 }
  function answerInlineQuery($inline_query_id,$results){
  return telegram('answerInlineQuery', [
      "inline_query_id" => $inline_query_id,
        "results" => json_encode($results)
]);
 }
function convertPersianNumbersToEnglish($string) {
    $persian_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    return str_replace($persian_numbers, $english_numbers, $string);
}

function isDuplicateUpdate($updateId)
{
    if (!is_numeric($updateId) || $updateId <= 0) {
        return false;
    }

    $cacheDir = __DIR__ . '/storage/cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        return false;
    }

    $cacheFile = $cacheDir . '/recent_updates.json';
    $handle = fopen($cacheFile, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $recentUpdates = $contents ? json_decode($contents, true) : [];
        if (!is_array($recentUpdates)) {
            $recentUpdates = [];
        }

        $now = time();
        $timeToLive = 120; // seconds

        // Drop expired entries
        foreach ($recentUpdates as $id => $timestamp) {
            if (!is_numeric($timestamp) || ($now - (int)$timestamp) > $timeToLive) {
                unset($recentUpdates[$id]);
            }
        }

        if (array_key_exists($updateId, $recentUpdates)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return true;
        }

        $recentUpdates[$updateId] = $now;

        // keep size reasonable
        if (count($recentUpdates) > 200) {
            asort($recentUpdates);
            $recentUpdates = array_slice($recentUpdates, -200, null, true);
        }

        $encoded = json_encode($recentUpdates);
        if ($encoded !== false) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $e) {
        try {
            flock($handle, LOCK_UN);
        } catch (Throwable $ignored) {
        }
        fclose($handle);
        return false;
    }

    return false;
}
// #-----------------------------#
$update = json_decode(file_get_contents("php://input"), true);
$update_id = $update['update_id'] ?? 0;
if (isDuplicateUpdate($update_id)) {
    http_response_code(200);
    exit;
}
$from_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? $update["inline_query"]['from']['id'] ?? 0;
$time_message = $update['message']['date'] ?? $update['callback_query']['date'] ?? $update["inline_query"]['date'] ?? 0;
$is_bot = $update['message']['from']['is_bot'] ?? false;
$chat_member = $update['chat_member'] ?? null;
$language_code = strtolower($update['message']['from']['language_code'] ?? $update['callback_query']['from']['language_code'] ?? "fa");
$Chat_type = $update["message"]["chat"]["type"] ?? $update['callback_query']['message']['chat']['type'] ?? '';
$text = $update["message"]["text"]  ?? '';
if(isset($update['pre_checkout_query'])){
    $Chat_type = "private";
    $from_id = $update['pre_checkout_query']['from']['id'];
}
$text =convertPersianNumbersToEnglish($text);
$text_inline = $update["callback_query"]["message"]['text'] ?? '';
$message_id = $update["message"]["message_id"] ?? $update["callback_query"]["message"]["message_id"] ?? 0;
$time_message = $update["message"]["date"] ?? $update["callback_query"]["date"] ?? 0;
$photo = $update["message"]["photo"] ?? 0;
$document = $update["message"]["document"] ?? 0;
$fileid = $update["message"]["document"]["file_id"] ?? 0;
$photoid = $photo ? end($photo)["file_id"] : '';
$caption = $update["message"]["caption"] ?? '';
$video = $update["message"]["video"] ?? 0;
$videoid = $video ? $video["file_id"] : 0;
$forward_from_id = $update["message"]["reply_to_message"]["forward_from"]["id"] ?? 0;
$datain = $update["callback_query"]["data"] ?? '';
$last_name = $update['message']['from']['last_name']  ?? $update["callback_query"]["from"]["last_name"] ?? $update["inline_query"]['from']['last_name'] ?? '';
$first_name = $update['message']['from']['first_name']  ?? $update["callback_query"]["from"]["first_name"] ?? $update["inline_query"]['from']['first_name'] ?? '';
$username = $update['message']['from']['username'] ?? $update['callback_query']['from']['username'] ?? $update["callback_query"]["from"]["username"] ?? 'NOT_USERNAME';
$user_phone =$update["message"]["contact"]["phone_number"] ?? 0;
$contact_id = $update["message"]["contact"]["user_id"] ?? 0;
$callback_query_id = $update["callback_query"]["id"] ?? 0;
$inline_query_id = $update["inline_query"]["id"] ?? 0;
$query = $update["inline_query"]["query"] ?? 0;

// #-----------------------------#
// اگر درخواست مربوط به پلتفرمی باشد که غیرفعال است، رد شود
if ($CURRENT_PLATFORM === 'bale' && empty($ENABLE_BALE)) {
    http_response_code(403);
    exit;
}
if ($CURRENT_PLATFORM === 'telegram' && empty($ENABLE_TELEGRAM)) {
    http_response_code(403);
    exit;
}

// شناسه‌های کاربر بله را آفست می‌دهیم تا با شناسه‌ی یک کاربر تلگرام
// در همان دیتابیس اشتراکی تداخل پیدا نکند. از این نقطه به بعد در کل
// کد، $from_id/$contact_id/$forward_from_id همان چیزی است که باید در
// دیتابیس ذخیره/جستجو شود؛ هنگام ارسال واقعی پیام، تابع telegram()
// خودش آفست را کم کرده و به آدرس/توکن درست بله می‌فرستد.
if ($CURRENT_PLATFORM === 'bale') {
    if (!empty($from_id)) {
        $from_id = toBaleInternalId($from_id);
    }
    if (!empty($contact_id)) {
        $contact_id = toBaleInternalId($contact_id);
    }
    if (!empty($forward_from_id)) {
        $forward_from_id = toBaleInternalId($forward_from_id);
    }
}