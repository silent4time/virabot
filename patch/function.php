<?php
require_once 'vendor/autoload.php';
require 'config.php';
require 'vendor/autoload.php';
ini_set('error_log', 'error_log');

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

#-----------shell helper utilities------------#
function assertSqlIdentifier($name, $allowFieldExpr = false)
{
    if ($name === null) {
        return;
    }
    // Identifiers (table/column names) cannot be bound as parameters, so they
    // are validated against a strict allow-list to prevent SQL injection.
    $pattern = $allowFieldExpr ? '/^[\p{L}\p{N}_*,()\s.`]+$/u' : '/^[\p{L}\p{N}_.`]+$/u';
    if (!preg_match($pattern, (string) $name)) {
        error_log('Blocked unsafe SQL identifier: ' . $name);
        throw new InvalidArgumentException('Invalid SQL identifier');
    }
}
function isShellExecAvailable()
{
    static $isAvailable;

    if ($isAvailable !== null) {
        return $isAvailable;
    }

    if (!function_exists('shell_exec')) {
        $isAvailable = false;
        return $isAvailable;
    }

    $disabledFunctions = ini_get('disable_functions');
    if (!empty($disabledFunctions) && stripos($disabledFunctions, 'shell_exec') !== false) {
        $isAvailable = false;
        return $isAvailable;
    }

    $isAvailable = true;
    return $isAvailable;
}

function getCrontabBinary()
{
    static $resolvedPath;

    if ($resolvedPath !== null) {
        return $resolvedPath ?: null;
    }

    $candidateDirectories = [
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/sbin',
        '/sbin',
    ];

    $environmentPath = getenv('PATH');
    if ($environmentPath !== false && $environmentPath !== '') {
        foreach (explode(PATH_SEPARATOR, $environmentPath) as $pathDirectory) {
            $pathDirectory = trim($pathDirectory);
            if ($pathDirectory !== '' && !in_array($pathDirectory, $candidateDirectories, true)) {
                $candidateDirectories[] = $pathDirectory;
            }
        }
    }

    foreach ($candidateDirectories as $directory) {
        $executablePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'crontab';
        if (@is_file($executablePath) && @is_executable($executablePath)) {
            $resolvedPath = $executablePath;
            return $resolvedPath;
        }
    }

    if (isShellExecAvailable()) {
        $whichOutput = @shell_exec('command -v crontab 2>/dev/null');
        if (is_string($whichOutput)) {
            $whichOutput = trim($whichOutput);
            if ($whichOutput !== '' && @is_executable($whichOutput)) {
                $resolvedPath = $whichOutput;
                return $resolvedPath;
            }
        }
    }

    $resolvedPath = '';
    error_log('Unable to locate the crontab executable on this system.');

    return null;
}

function runShellCommand($command)
{
    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to run command: ' . $command);
        return null;
    }

    if (getenv('PATH') === false || trim((string) getenv('PATH')) === '') {
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
    }

    return shell_exec($command);
}

function deleteDirectory($directory)
{
    if (!file_exists($directory)) {
        return true;
    }

    if (!is_dir($directory)) {
        return @unlink($directory);
    }

    $items = scandir($directory);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($directory);
}

function ensureTableUtf8mb4($table)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $currentCollation = $stmt->fetchColumn();

        if ($currentCollation === false) {
            error_log("Failed to detect current collation for table {$table}");
            return false;
        }

        if (stripos((string) $currentCollation, 'utf8mb4') === 0) {
            return true;
        }

        $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return true;
    } catch (PDOException $e) {
        error_log('Failed to convert table to utf8mb4: ' . $e->getMessage());
        return false;
    }
}

function ensureCardNumberTableSupportsUnicode()
{
    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return;
    }

    try {
        $pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

        $createQuery = "CREATE TABLE IF NOT EXISTS card_number (" .
            "cardnumber varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY," .
            "namecard varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($createQuery);

        ensureTableUtf8mb4('card_number');

        $columnInfo = $pdo->query("SHOW FULL COLUMNS FROM card_number WHERE Field IN ('cardnumber', 'namecard')");
        if ($columnInfo instanceof PDOStatement) {
            while ($column = $columnInfo->fetch(PDO::FETCH_ASSOC)) {
                $collation = $column['Collation'] ?? '';
                if (!is_string($collation) || stripos($collation, 'utf8mb4') === false) {
                    $field = $column['Field'];
                    $type = $field === 'cardnumber' ? 'varchar(500)' : 'varchar(1000)';
                    $alter = sprintf(
                        "ALTER TABLE card_number MODIFY %s %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci%s",
                        $field,
                        $type,
                        $field === 'cardnumber' ? ' PRIMARY KEY' : ' NOT NULL'
                    );
                    $pdo->exec($alter);
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Unexpected error while ensuring card_number utf8mb4 compatibility: ' . $e->getMessage());
    }
}

function normaliseUpdateValue($value)
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $value;
}

function copyDirectoryContents($source, $destination)
{
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            if (!copyDirectoryContents($sourcePath, $destinationPath)) {
                return false;
            }
        } else {
            if (!@copy($sourcePath, $destinationPath)) {
                return false;
            }
        }
    }

    return true;
}

#-----------function------------#
function step($step, $from_id)
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE user SET step = ? WHERE id = ?');
    $stmt->execute([$step, $from_id]);
    clearSelectCache('user');
}

function mv_clear_user_flow($from_id)
{
    step('home', $from_id);
    update("user", "Processing_value", "0", "id", $from_id);
    update("user", "Processing_value_one", "0", "id", $from_id);
    update("user", "Processing_value_tow", "0", "id", $from_id);
    update("user", "Processing_value_four", "0", "id", $from_id);
}

/**
 * منوی اصلی کاربر (کیبورد اولیه)
 */
function mv_go_user_main($from_id, $delete_message_id = null)
{
    global $keyboard, $textbotlang, $message_id;
    if ($delete_message_id) {
        try {
            deletemessage($from_id, $delete_message_id);
        } catch (Throwable $e) {
        }
    }
    $msg = $textbotlang['users']['back'] ?? ($textbotlang['users']['text_start'] ?? 'منوی اصلی');
    sendmessage($from_id, $msg, $keyboard, 'html');
    mv_clear_user_flow($from_id);
}

/**
 * منوی مدیریت ادمین
 */
function mv_go_admin_main($from_id)
{
    global $keyboardadmin, $textbotlang, $version, $buyreport, $otherservice, $otherreport, $paymentreports, $reporttest, $errorreport, $active_panell, $setting_panel;
    if (isset($buyreport) && ($buyreport == "0" || $otherservice == "0" || $otherreport == "0" || $paymentreports == "0" || $reporttest == "0" || $errorreport == "0")) {
        sendmessage($from_id, $textbotlang['Admin']['activeBotText'] ?? 'ربات را فعال کنید', $active_panell ?? $setting_panel ?? null, 'HTML');
        return;
    }
    $version_mini_app = @file_get_contents('app/version');
    $tpl = $textbotlang['Admin']['adminphp']['msg_panel_admin_bot_report'] ?? "پنل مدیریت\nنسخه: %s";
    $text_admin = @sprintf($tpl, $version, $version_mini_app);
    if (!$text_admin) {
        $text_admin = "پنل مدیریت\nنسخه: " . ($version ?: '');
    }
    // ارسال کیبورد مدیریت — باید کیبورد قبلی (فروشگاه و …) را عوض کند
    if (empty($keyboardadmin)) {
        $keyboardadmin = json_encode(['keyboard' => [[['text' => $textbotlang['Admin']['panelAdmin'] ?? 'پنل مدیریت']]], 'resize_keyboard' => true]);
    }
    sendmessage($from_id, $text_admin, $keyboardadmin, 'HTML');
    step('home', $from_id);
}

/**
 * منوی قبلی ادمین بر اساس step فعلی
 * @return bool true اگر هندل شد
 */
function mv_go_admin_previous($from_id, $step)
{
    if ($step === 'admin_license' || $step === 'set_mv_license_key') {
        if (function_exists('mv_go_admin_main')) {
            mv_go_admin_main($from_id);
            return true;
        }
    }

    global $pdo, $textbotlang, $shopkeyboard, $channelkeyboard, $setting_panel, $keyboardadmin;
    global $change_product, $keyboard_shop_manage, $keyboard_Category_manage, $keyboard;

    $msg = $textbotlang['Admin']['backMenu'] ?? 'بازگشت';
    $opt = $textbotlang['users']['selectoption'] ?? $msg;
    $u = select("user", "*", "id", $from_id, "select") ?: [];

    // فیلدهای ویرایش محصول → منوی ویرایش همان محصول
    $to_product_edit = [
        'change_price', 'change_time', 'change_volume', 'change_max_users',
        'change_note', 'change_categroy', 'change_name', 'change_type_agent',
        'change_reset_data', 'change_loc_data', 'change_val', 'get_max_users',
    ];
    if (in_array($step, $to_product_edit, true)) {
        sendmessage($from_id, $msg, $change_product ?: $keyboard_shop_manage ?: $shopkeyboard, 'HTML');
        step('product_edit_flow', $from_id);
        return true;
    }

    // منوی ویرایش محصول → مدیریت محصولات (+ لیست اینلاین اگر ممکن)
    if ($step === 'product_edit_flow') {
        $codePanel = $u['Processing_value_one'] ?? '';
        $typeagent = $u['Processing_value_tow'] ?? '';
        if ($codePanel && $typeagent && $codePanel !== '0' && $typeagent !== '0') {
            $panel = select("marzban_panel", "*", "code_panel", $codePanel, "select");
            if ($panel) {
                $st = $pdo->prepare("SELECT * FROM product WHERE (Location = ? OR Location = '/all') AND agent = ?");
                $st->bindValue(1, $panel['name_panel'], PDO::PARAM_STR);
                $st->bindValue(2, $typeagent, PDO::PARAM_STR);
                $st->execute();
                $list = ['inline_keyboard' => []];
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $list['inline_keyboard'][] = [
                        ['text' => $row['name_product'], 'callback_data' => 'productedit_' . $row['id']],
                    ];
                }
                $list['inline_keyboard'][] = [
                    ['text' => $textbotlang['keyboard']['backToPreviousMenu'] ?? '🔙 بازگشت', 'callback_data' => 'backproductadmin'],
                ];
                sendmessage($from_id, $textbotlang['Admin']['Product']['selectEditProduct'] ?? 'انتخاب محصول:', json_encode($list), 'HTML');
            }
        }
        if (!empty($keyboard_shop_manage)) {
            sendmessage($from_id, $opt, $keyboard_shop_manage, 'HTML');
        } elseif (!empty($shopkeyboard)) {
            sendmessage($from_id, $opt, $shopkeyboard, 'HTML');
        }
        step('admin_products', $from_id);
        return true;
    }

    // لیست/حذف محصول → مدیریت محصولات
    if (in_array($step, ['select_product_edit', 'selectloc', 'selectlocedite', 'remove-product'], true)) {
        if (!empty($keyboard_shop_manage)) {
            sendmessage($from_id, $opt, $keyboard_shop_manage, 'HTML');
            step('admin_products', $from_id);
            return true;
        }
        if (!empty($shopkeyboard)) {
            sendmessage($from_id, $opt, $shopkeyboard, 'HTML');
            step('admin_shop', $from_id);
            return true;
        }
    }

    // مدیریت محصولات → تنظیمات فروشگاه
    if ($step === 'admin_products') {
        sendmessage($from_id, $opt, $shopkeyboard ?: $keyboardadmin, 'HTML');
        step('admin_shop', $from_id);
        return true;
    }

    // دسته → فروشگاه
    if (in_array($step, ['admin_category', 'editcategory_name', 'get_name_new_category'], true)) {
        sendmessage($from_id, $opt, $shopkeyboard ?: $keyboardadmin, 'HTML');
        step('admin_shop', $from_id);
        return true;
    }

    // جریان افزودن محصول / کد → فروشگاه
    $add_flow = [
        'get_limit', 'GetPriceExtra', 'GetPriceexstratime', 'GetPricecustomtime', 'GetPricecustomvolume',
        'get_code', 'get_codesell', 'minbalancebulk',
        'add_product_name', 'add_product_price', 'add_product_volume', 'add_product_time',
    ];
    if (in_array($step, $add_flow, true)) {
        sendmessage($from_id, $opt, $shopkeyboard ?: $keyboardadmin, 'HTML');
        step('admin_shop', $from_id);
        return true;
    }

    // تنظیمات فروشگاه → منوی مدیریت (باگ قبلی: دوباره فروشگاه می‌فرستاد)
    if ($step === 'admin_shop') {
        if (function_exists('mv_go_admin_main')) {
            mv_go_admin_main($from_id);
        } else {
            sendmessage($from_id, $msg, $keyboardadmin, 'HTML');
            step('home', $from_id);
        }
        return true;
    }

    // پنل‌ها
    $panel_steps = [
        'updatetime', 'val_usertest', 'getlimitnew', 'GetusernameNew', 'GeturlNew', 'protocolset',
        'updatemethodusername', 'GetNameNew', 'getprotocol', 'getprotocolremove', 'GetpaawordNew',
        'updateextendmethod', 'setpricechangelocation', 'add_panel_name', 'add_panel_url', 'admin_panels',
    ];
    if (in_array($step, $panel_steps, true)) {
        $name = null;
        if (!empty($u['Processing_value']) && $u['Processing_value'] !== '0') {
            $pv = json_decode($u['Processing_value'], true);
            if (is_array($pv) && !empty($pv['name_panel'])) {
                $name = $pv['name_panel'];
            } elseif (is_string($u['Processing_value']) && strpos($u['Processing_value'], '{') === false) {
                $name = $u['Processing_value'];
            }
        }
        if ($name && function_exists('outtypepanel') && $step !== 'admin_panels') {
            $typepanel = select("marzban_panel", "*", "name_panel", $name, "select");
            if ($typepanel) {
                outtypepanel($typepanel['type'], $msg);
                step('admin_panels', $from_id);
                return true;
            }
        }
        if (function_exists('mv_go_admin_main')) {
            mv_go_admin_main($from_id);
        } else {
            sendmessage($from_id, $msg, $keyboardadmin, 'HTML');
            step('home', $from_id);
        }
        return true;
    }

    // کانال
    if (in_array($step, ['addchannel', 'removechannel', 'getremark', 'getlinkjoin', 'admin_channels'], true)) {
        if ($step !== 'admin_channels' && !empty($channelkeyboard)) {
            sendmessage($from_id, $msg, $channelkeyboard, 'HTML');
            step('admin_channels', $from_id);
            return true;
        }
        if (function_exists('mv_go_admin_main')) {
            mv_go_admin_main($from_id);
        } else {
            sendmessage($from_id, $msg, $keyboardadmin, 'HTML');
            step('home', $from_id);
        }
        return true;
    }

    // تنظیمات
    if ($step === 'admin_settings' || strpos((string)$step, 'editshops') === 0) {
        if ($step !== 'admin_settings' && !empty($setting_panel)) {
            sendmessage($from_id, $msg, $setting_panel, 'HTML');
            step('admin_settings', $from_id);
            return true;
        }
        if (function_exists('mv_go_admin_main')) {
            mv_go_admin_main($from_id);
        } else {
            sendmessage($from_id, $msg, $keyboardadmin, 'HTML');
            step('home', $from_id);
        }
        return true;
    }

    // پیش‌فرض → منوی مدیریت
    if (function_exists('mv_go_admin_main')) {
        mv_go_admin_main($from_id);
        return true;
    }
    return false;
}



function determineColumnTypeFromValue($value)
{
    if (is_bool($value)) {
        return 'TINYINT(1)';
    }

    if (is_int($value)) {
        return 'INT(11)';
    }

    if (is_float($value)) {
        return 'DOUBLE';
    }

    if ($value === null) {
        return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    if (is_string($value)) {
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($value, 'UTF-8');
        } else {
            $length = strlen($value);
        }

        if ($length <= 191) {
            return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        if ($length <= 500) {
            return 'VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
}
function ensureColumnExistsForUpdate($tableName, $fieldName, $valueSample = null)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$tableName, $fieldName]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $datatype = determineColumnTypeFromValue($valueSample);

        $defaultValue = null;
        if (is_bool($valueSample)) {
            $defaultValue = $valueSample ? '1' : '0';
        } elseif (is_scalar($valueSample) && $valueSample !== null) {
            $defaultValue = (string) $valueSample;
        }

        addFieldToTable($tableName, $fieldName, $defaultValue, $datatype);
    } catch (PDOException $e) {
        error_log('Failed to ensure column exists: ' . $e->getMessage());
    }
}
function update($table, $field, $newValue, $whereField = null, $whereValue = null)
{
    global $pdo, $user;

    assertSqlIdentifier($table);
    assertSqlIdentifier($field, true);
    assertSqlIdentifier($whereField);

    $valueToStore = normaliseUpdateValue($newValue);

    ensureColumnExistsForUpdate($table, $field, $valueToStore);

    $executeUpdate = function ($value) use ($pdo, $table, $field, $whereField, $whereValue) {
        if ($whereField !== null) {
            $stmt = $pdo->prepare("SELECT $field FROM $table WHERE $whereField = ? FOR UPDATE");
            $stmt->execute([$whereValue]);
            $stmt = $pdo->prepare("UPDATE $table SET $field = ? WHERE $whereField = ?");
            $stmt->execute([$value, $whereValue]);
        } else {
            $stmt = $pdo->prepare("UPDATE $table SET $field = ?");
            $stmt->execute([$value]);
        }
    };

    try {
        $executeUpdate($valueToStore);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Incorrect string value') !== false) {
            $tableConverted = ensureTableUtf8mb4($table);
            if ($tableConverted) {
                try {
                    $executeUpdate($valueToStore);
                } catch (PDOException $retryException) {
                    error_log('Retry after charset conversion failed: ' . $retryException->getMessage());
                    throw $retryException;
                }
            } else {
                $fallbackValue = is_string($valueToStore) ? @iconv('UTF-8', 'UTF-8//IGNORE', $valueToStore) : $valueToStore;
                if ($fallbackValue === false) {
                    $fallbackValue = '';
                }
                $executeUpdate($fallbackValue);
            }
        } else {
            throw $e;
        }
    }

    $date = date("Y-m-d H:i:s");
    if (!isset($user['step'])) {
        $user['step'] = '';
    }
    $logValue = is_scalar($valueToStore) ? $valueToStore : json_encode($valueToStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $logss = "{$table}_{$field}_{$logValue}_{$whereField}_{$whereValue}_{$user['step']}_$date";
    if ($field != "message_count" && $field != "last_message_time") {
        file_put_contents('log.txt', "\n" . $logss, FILE_APPEND);
    }

    clearSelectCache($table);
}
function &getSelectCacheStore()
{
    static $store = [
    'results' => [],
    'tableIndex' => [],
    ];

    return $store;
}

function clearSelectCache($table = null)
{
    $store = &getSelectCacheStore();

    if ($table === null) {
        $store['results'] = [];
        $store['tableIndex'] = [];
        return;
    }

    if (!isset($store['tableIndex'][$table])) {
        return;
    }

    foreach (array_keys($store['tableIndex'][$table]) as $cacheKey) {
        unset($store['results'][$cacheKey]);
    }

    unset($store['tableIndex'][$table]);
}

// بررسی می‌کند آیا $candidateId همان ادمینِ پیکربندی‌شده (id_admin/adminnumber)
// است یا شناسه‌ی داخلیِ (آفست‌دارِ) حسابِ بله‌ی همان ادمین - چون شناسه‌ی یک
// شخص در تلگرام و بله دو عددِ کاملاً بی‌ربط‌اند، این دومی از یک مقدارِ
// جداگانه‌ی پیکربندی‌شده ($BALE_ADMIN_ID) خوانده می‌شود، نه از تبدیلِ ریاضیِ
// روی همان آیدیِ تلگرام.
function isSameAdminIdentity($configuredId, $candidateId)
{
    $configuredId = trim((string) $configuredId);
    $candidateId = trim((string) $candidateId);
    if ($configuredId === '' || $candidateId === '') {
        return false;
    }
    if ($configuredId === $candidateId) {
        return true;
    }
    global $ENABLE_BALE, $BALE_ADMIN_ID, $adminnumber;
    if (!empty($ENABLE_BALE) && !empty($BALE_ADMIN_ID) && function_exists('toBaleInternalId')
        && $configuredId === trim((string) $adminnumber)) {
        if ((string) toBaleInternalId($BALE_ADMIN_ID) === $candidateId) {
            return true;
        }
    }
    return false;
}

function select($table, $field, $whereField = null, $whereValue = null, $type = "select", $options = [])
{
    global $pdo;

    assertSqlIdentifier($table);
    assertSqlIdentifier($field, true);
    assertSqlIdentifier($whereField);

    $useCache = true;
    if (is_array($options) && array_key_exists('cache', $options)) {
        $useCache = (bool) $options['cache'];
    }

    $cacheKey = null;
    if ($useCache) {
        $cacheKey = hash('sha256', json_encode([
            $table,
            $field,
            $whereField,
            $whereValue,
            $type,
        ], JSON_UNESCAPED_UNICODE));

        $store = &getSelectCacheStore();
        if (isset($store['results'][$cacheKey])) {
            return $store['results'][$cacheKey];
        }
    }

    $query = "SELECT $field FROM $table";

    if ($whereField !== null) {
        $query .= " WHERE $whereField = :whereValue";
    }

    try {
        $stmt = $pdo->prepare($query);
        if ($whereField !== null) {
            $stmt->bindParam(':whereValue', $whereValue, PDO::PARAM_STR);
        }

        $stmt->execute();
        if ($type == "count") {
            $result = $stmt->rowCount();
        } elseif ($type == "FETCH_COLUMN") {
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($table === 'admin' && $field === 'id_admin') {
                global $adminnumber;
                if (!is_array($results)) {
                    $results = [];
                }

                $results = array_values(array_unique(array_filter($results, function ($value) {
                    return $value !== null && $value !== '';
                })));

                if (empty($results) && isset($adminnumber) && $adminnumber !== '') {
                    $results[] = (string) $adminnumber;
                    global $ENABLE_BALE, $BALE_ADMIN_ID;
                    if (!empty($ENABLE_BALE) && !empty($BALE_ADMIN_ID) && function_exists('toBaleInternalId')) {
                        $results[] = (string) toBaleInternalId($BALE_ADMIN_ID);
                    }
                }
            }
            $result = $results;
        } elseif ($type == "fetchAll") {
            $result = $stmt->fetchAll();
        } else {
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            $result = $fetched === false ? null : $fetched;
        }
    } catch (PDOException $e) {
        error_log("Query failed: " . $e->getMessage());
        if (!isset($result)) {
            $result = null;
        }
    }

    if (!isset($result)) {
        $result = null;
    }

    if ($useCache && $cacheKey !== null) {
        $store = &getSelectCacheStore();
        $store['results'][$cacheKey] = $result;
        if (!isset($store['tableIndex'][$table])) {
            $store['tableIndex'][$table] = [];
        }
        $store['tableIndex'][$table][$cacheKey] = true;
    }

    return $result;
}

function getPaySettingValue($name, $default = null)
{
    $result = select("PaySetting", "ValuePay", "NamePay", $name, "select");
    if (!is_array($result) || !array_key_exists('ValuePay', $result)) {
        return $default;
    }

    return $result['ValuePay'];
}
function generateUUID()
{
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    return $uuid;
}
function rate_arze()
{
    $arze_rate = [];
    $requests_tron = json_decode(file_get_contents('https://api.diadata.org/v1/assetQuotation/Tron/0x0000000000000000000000000000000000000000'), true);
    $html_read = file_get_contents("https://www.bon-bast.com/");
    preg_match('/<span>\s*([\d,]+)\s*<\/span>/', $html_read, $matches);
    if (!empty($matches[1])) {
        $requestsusd = str_replace(',', '', $matches[1]);
    }
    $arze_rate['USD'] = intval($requestsusd);
    $arze_rate['TRX'] = intval($requests_tron['Price'] * $arze_rate['USD']);

    return $arze_rate;
}
function updatePaymentMessageId($response, $orderId)
{
    if (!is_array($response)) {
        error_log("Failed to send payment message for order {$orderId}: unexpected response");
        return false;
    }

    if (empty($response['ok'])) {
        error_log("Failed to send payment message for order {$orderId}: " . json_encode($response));
        return false;
    }

    if (!isset($response['result']['message_id'])) {
        error_log("Missing message_id for order {$orderId}: " . json_encode($response));
        return false;
    }

    update("Payment_report", "message_id", intval($response['result']['message_id']), "id_order", $orderId);
    return true;
}
function nowPayments($payment, $price_amount, $order_id, $order_description)
{
    global $domainhosts;
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/' . $payment,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 7000,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments,
            'Content-Type: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'price_amount' => $price_amount,
        'price_currency' => 'usd',
        'order_id' => $order_id,
        'order_description' => $order_description,
        'ipn_callback_url' => "https://" . $domainhosts . "/payment/nowpayment.php"
    ]));

    $response = curl_exec($curl);
    return json_decode($response, true);
}
function StatusPayment($paymentid)
{
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/payment/' . $paymentid,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments
        ),
    ));
    $response = curl_exec($curl);
    $response = json_decode($response, true);
    return $response;
}
function channel(array $id_channel)
{
    global $from_id;
    $channel_link = array();
    foreach ($id_channel as $channel) {
        $response = telegram('getChatMember', [
            'chat_id' => $channel,
            'user_id' => $from_id
        ]);
        if ($response['ok']) {
            if (!in_array($response['result']['status'], ['member', 'creator', 'administrator'])) {
                $channel_link[] = $channel;
            }
        }
    }
    if (count($channel_link) == 0) {
        return [];
    } else {
        return $channel_link;
    }
}
function isValidDate($date)
{
    return (strtotime($date) != false);
}
function trnado($order_id, $price)
{
    global $domainhosts;
    $apitronseller = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $urlpay = select("PaySetting", "*", "NamePay", "urlpaymenttron", "select")['ValuePay'];
    $curl = curl_init();
    $data = array(
        "PaymentID" => $order_id,
        "WalletAddress" => $walletaddress,
        "TronAmount" => $price,
        "CallbackUrl" => "https://" . $domainhosts . "/payment/tronado.php"
    );
    $datasend = json_encode($data);
    curl_setopt_array($curl, array(
        CURLOPT_URL => "$urlpay",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apitronseller,
            'Content-Type: application/json',
            'Cookie: ASP.NET_SessionId=spou2s5lo4nnxkjtavscrrlo'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $datasend);

    $response = curl_exec($curl);

    return json_decode($response, true);
}
function formatBytes($bytes, $precision = 2): string
{
    global $textbotlang;
    $base = log($bytes, 1024);
    $power = $bytes > 0 ? floor($base) : 0;
    $suffixes = [
        $textbotlang['hardcoded']['unitByte'],
        $textbotlang['hardcoded']['unitKilobyte'],
        $textbotlang['hardcoded']['unitMegabyte'],
        $textbotlang['hardcoded']['unitGigabyteFn'],
        $textbotlang['hardcoded']['unitTerabyte'],
    ];
    return round(pow(1024, $base - $power), $precision) . ' ' . $suffixes[$power];
}
function generateUsername($from_id, $Metode, $username, $randomString, $text, $namecustome, $usernamecustom)
{
    global $textbotlang;
    $setting = select("setting", "*", null, null, "select");
    $user = select("user", "*", "id", $from_id, "select");
    if ($user == false) {
        $user = array();
        $user = array(
            'number_username' => '',
        );
    }
    if ($Metode == $textbotlang['keyboard']['numericIdRandom']) {
        return $from_id . "_" . $randomString;
    } elseif ($Metode == $textbotlang['keyboard']['usernameSequential']) {
        if ($username == "NOT_USERNAME") {
            if (preg_match('/^\w{3,32}$/', $namecustome)) {
                $username = $namecustome;
            }
        }
        return $username . "_" . $user['number_username'];
    } elseif ($Metode == $textbotlang['keyboard']['customUsername'])
        return $text;
    elseif ($Metode == $textbotlang['keyboard']['customUsernameRandom']) {
        $random_number = rand(1000000, 9999999);
        return $text . "_" . $random_number;
    } elseif ($Metode == $textbotlang['keyboard']['customTextRandom']) {
        return $namecustome . "_" . $randomString;
    } elseif ($Metode == $textbotlang['keyboard']['customTextSequential']) {
        return $namecustome . "_" . $setting['numbercount'];
    } elseif ($Metode == $textbotlang['keyboard']['numericIdSequential']) {
        return $from_id . "_" . $user['number_username'];
    } elseif ($Metode == $textbotlang['keyboard']['agentCustomTextSequential']) {
        if ($usernamecustom == "none") {
            return $namecustome . "_" . $setting['numbercount'];
        }
        return $usernamecustom . "_" . $user['number_username'];
    }
}
function outputlink($text)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $text);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 10000);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        return null;
    } else {
        return $response;
    }
}
function DirectPayment($order_id, $image = 'images.jpg')
{
    global $pdo, $ManagePanel, $textbotlang, $keyboardextendfnished, $keyboard, $Confirm_pay, $from_id, $message_id;
    $buyreport = select("topicid", "idreport", "report", "buyreport", "select")['idreport'];
    $admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    $otherservice = select("topicid", "idreport", "report", "otherservice", "select")['idreport'];
    $otherreport = select("topicid", "idreport", "report", "otherreport", "select")['idreport'];
    $errorreport = select("topicid", "idreport", "report", "errorreport", "select")['idreport'];
    $porsantreport = select("topicid", "idreport", "report", "porsantreport", "select")['idreport'];
    $setting = select("setting", "*");
    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    $format_price_cart = number_format($Payment_report['price']);
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    $steppay = explode("|", $Payment_report['id_invoice']);
    update("user", "Processing_value", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_one", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_tow", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_four", "0", "id", $Balance_id['id']);
    if ($steppay[0] == "getconfigafterpay") {
        $get_invoice = select("invoice", "*", "username", $steppay[1], "select");
        $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
        $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
        $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
        $stmt->execute();
        $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($get_invoice['name_product'] == $textbotlang['extracted']['index_php']['customVolumeButton'] || $get_invoice['name_product'] == $textbotlang['extracted']['index_php']['customServiceButton']) {
            $info_product['data_limit_reset'] = "no_reset";
            $info_product['Volume_constraint'] = $get_invoice['Volume'];
            $info_product['name_product'] = $textbotlang['users']['customSellVolume']['title'];
            $info_product['code_product'] = "customvolume";
            $info_product['Service_time'] = $get_invoice['Service_time'];
            $info_product['price_product'] = $get_invoice['price_product'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
            $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
            $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
            $stmt->execute();
            $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $username_ac = $get_invoice['username'];
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $get_invoice['Service_location'], "select");
        $date = strtotime("+" . $get_invoice['Service_time'] . "days");
        if (intval($get_invoice['Service_time']) == 0) {
            $timestamp = 0;
        } else {
            $timestamp = strtotime(date("Y-m-d H:i:s", $date));
        }
        $maxUsersBuy = 1;
        if (isset($info_product['max_users']) && $info_product['max_users'] !== '' && $info_product['max_users'] !== null) {
            $maxUsersBuy = max(0, intval($info_product['max_users']));
        }
        $datac = array(
            'expire' => $timestamp,
            'data_limit' => $get_invoice['Volume'] * pow(1024, 3),
            'from_id' => $Balance_id['id'],
            'username' => $Balance_id['username'],
            'type' => 'buy',
            'max_clients' => $maxUsersBuy,
            'max_users' => $maxUsersBuy,
            'limit_ip' => $maxUsersBuy,
        );
        $dataoutput = $ManagePanel->createUser($marzban_list_get['name_panel'], $info_product['code_product'], $username_ac, $datac);
        if ($dataoutput['username'] == null) {
            $dataoutput['msg'] = json_encode($dataoutput['msg']);
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['errorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['serviceCreateFailedRefund'], $balance), $keyboard, 'HTML');
            $texterros = sprintf($textbotlang['hardcoded']['configCreateError'], $dataoutput['msg'], $Balance_id['id'], $Balance_id['username'], $marzban_list_get['name_panel']);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $texterros,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $Shoppinginfo = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['keyboard']['viewTutorial'], 'callback_data' => "helpbtn"],
                ]
            ]
        ]);
        $output_config_link = "";
        $config = "";
        if ($marzban_list_get['config'] == "onconfig" && is_array($dataoutput['configs'])) {
            foreach ($dataoutput['configs'] as $link) {
                $config .= "\n" . $link;
            }
        }
        $output_config_link = $marzban_list_get['sublink'] == "onsublink" ? $dataoutput['subscription_url'] : "";
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "Manualsale" ? $textbotlang['textbot']['manual'] : $textbotlang['textbot']['afterPay'];
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "WGDashboard" ? $textbotlang['textbot']['wgDashboard'] : $textbotlang['textbot']['afterPay'];
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik" ? $textbotlang['textbot']['afterPayIbsng'] : $textbotlang['textbot']['afterPay'];
        if (intval($get_invoice['Service_time']) == 0)
            $get_invoice['Service_time'] = $textbotlang['users']['status']['unlimited'];
        $textcreatuser = str_replace('{username}', $dataoutput['username'], $textbotlang['textbot']['afterPay']);
        $textcreatuser = str_replace('{name_service}', $get_invoice['name_product'], $textcreatuser);
        $textcreatuser = str_replace('{location}', $marzban_list_get['name_panel'], $textcreatuser);
        $textcreatuser = str_replace('{day}', $get_invoice['Service_time'], $textcreatuser);
        $textcreatuser = str_replace('{volume}', $get_invoice['Volume'], $textcreatuser);
        $textcreatuser = str_replace('{config}', "<code>{$output_config_link}</code>", $textcreatuser);
        $textcreatuser = str_replace('{links}', $config, $textcreatuser);
        $textcreatuser = str_replace('{links2}', "{$output_config_link}", $textcreatuser);
        if ($marzban_list_get['type'] == "Manualsale" || $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik") {
            $textcreatuser = str_replace('{password}', $dataoutput['subscription_url'], $textcreatuser);
            update("invoice", "user_info", $dataoutput['subscription_url'], "id_invoice", $get_invoice['id_invoice']);
        }
        if (($marzban_list_get['type'] ?? '') === 'eylan' && function_exists('eylan_send_to_user')) {
            try {
                $ey_proto = $marzban_list_get['inboundid'] ?? ($dataoutput['_protocol'] ?? 'multi');
                $ey_body = $dataoutput['_body'] ?? null;
                $inv = $get_invoice['id_invoice'] ?? '';
                if (function_exists('eylan_lock') && !eylan_lock('msg|' . $dataoutput['username'] . '|' . $inv)) {
                    // قبلاً ارسال شده — تکرار نکن
                } else {
                    $sub = $dataoutput['subscription_url'] ?? $output_config_link ?? '';
                    if ($sub !== '' && function_exists('eylan_public_url')) {
                        $sub = eylan_public_url($sub, $marzban_list_get);
                    }
                    if (function_exists('eylan_format_success_message')) {
                        $msg = eylan_format_success_message(
                            $dataoutput['username'],
                            $get_invoice['name_product'] ?? '',
                            $marzban_list_get['name_panel'] ?? '',
                            $get_invoice['Service_time'] ?? '',
                            $get_invoice['Volume'] ?? '',
                            $ey_proto,
                            $sub,
                            $marzban_list_get['name_panel'] ?? null,
                            $ey_body
                        );
                    } else {
                        $msg = $textcreatuser;
                    }
                    sendmessage($get_invoice['id_user'], $msg, $Shoppinginfo, 'HTML');
                    eylan_send_to_user($get_invoice['id_user'], $marzban_list_get['name_panel'], $dataoutput['username'], $ey_proto, $ey_body);
                }
            } catch (Throwable $e) {
                error_log('eylan deliver: ' . $e->getMessage());
                sendmessage($get_invoice['id_user'], $textcreatuser, $Shoppinginfo, 'HTML');
            }
        } elseif (($marzban_list_get['type'] ?? '') === 'connectix' || !empty($dataoutput['_connectix'])) {
            try {
                $cxPass = $dataoutput['_cx_password'] ?? '';
                $cxText = $dataoutput['_cx_text'] ?? '';
                $cxPlan = $dataoutput['_cx_plan'] ?? ($get_invoice['name_product'] ?? '');
                $cxUser = $dataoutput['username'] ?? '';
                if (function_exists('connectix_format_message')) {
                    $cxSub = $dataoutput['subscription_url'] ?? ($dataoutput['_cx_sub'] ?? '');
                    if ($cxSub === '' && function_exists('connectix_pick_subscription')) {
                        $cxSub = connectix_pick_subscription($cxText);
                    }
                    $msg = connectix_format_message($cxPlan, $cxUser, $cxPass, $cxText, $cxSub);
                } else {
                    $msg = "✅ سرویس کانکتیکس\n👤 <code>{$cxUser}</code>\n🔑 <code>{$cxPass}</code>";
                }
                sendmessage($get_invoice['id_user'], $msg, $Shoppinginfo, 'HTML');
            } catch (Throwable $e) {
                error_log('connectix deliver: ' . $e->getMessage());
                sendmessage($get_invoice['id_user'], $textcreatuser, $Shoppinginfo, 'HTML');
            }
        } else {
            sendMessageService($marzban_list_get, $dataoutput['configs'], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $get_invoice['id_invoice'], $get_invoice['id_user'], $image);
        }
        $partsdic = explode("_", $Balance_id['Processing_value_four'], $get_invoice['id_user']);
        if ($partsdic[0] == "dis") {
            $SellDiscountlimit = select("DiscountSell", "*", "codeDiscount", $partsdic[1], "select");
            $value = intval($SellDiscountlimit['usedDiscount']) + 1;
            update("DiscountSell", "usedDiscount", $value, "codeDiscount", $partsdic[1]);
            $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user,code) VALUES (:id_user,:code)");
            $stmt->bindParam(':id_user', $Balance_id['id']);
            $stmt->bindParam(':code', $partsdic[1]);
            $stmt->execute();
            $text_report = sprintf($textbotlang['hardcoded']['discountCodeUsedAdmin'], $Balance_id['username'], $Balance_id['id'], $partsdic[1]);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $affiliatescommission = select("affiliates", "*", null, null, "select");
        $marzbanporsant_one_buy = select("affiliates", "*", null, null, "select");
        $stmt = $pdo->prepare("SELECT * FROM invoice WHERE name_product != :name_product  AND id_user = :id_user AND Status != 'Unpaid'");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':name_product', $textbotlang['Admin']['adminphp']['db_test_service_name']);
        $stmt->execute();
        $countinvoice = $stmt->rowCount();
        if ($affiliatescommission['status_commission'] == "oncommission" && ($Balance_id['affiliates'] != null && intval($Balance_id['affiliates']) != 0)) {
            if ($marzbanporsant_one_buy['porsant_one_buy'] == "on_buy_porsant") {
                if ($countinvoice <= 1) {
                    $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                    $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                    if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                        sendmessage($Balance_id['affiliates'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
                        $scorenew = $user_Balance['score'] + 2;
                        update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                    }
                    $Balance_prim = $user_Balance['Balance'] + $result;
                    $dateacc = bot_date('Y/m/d H:i:s');
                    update("user", "Balance", $Balance_prim, "id", $Balance_id['affiliates']);
                    $result = number_format($result);
                    $textadd = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidUserFn'], $result);
                    $textreportport = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidLogFn'], $result, $Balance_id['affiliates'], $Balance_id['id'], $dateacc);
                    if (strlen($setting['Channel_Report']) > 0) {
                        telegram('sendmessage', [
                            'chat_id' => $setting['Channel_Report'],
                            'message_thread_id' => $porsantreport,
                            'text' => $textreportport,
                            'parse_mode' => "HTML"
                        ]);
                    }
                    sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
                }
            } else {

                $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                    sendmessage($Balance_id['affiliates'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
                    $scorenew = $user_Balance['score'] + 2;
                    update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                }
                $Balance_prim = $user_Balance['Balance'] + $result;
                $dateacc = bot_date('Y/m/d H:i:s');
                update("user", "Balance", $Balance_prim, "id", $Balance_id['affiliates']);
                $result = number_format($result);
                $textadd = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidUserFn2'], $result);
                $textreportport = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidLogFn2'], $result, $Balance_id['affiliates'], $Balance_id['id'], $dateacc);
                if (strlen($setting['Channel_Report']) > 0) {
                    telegram('sendmessage', [
                        'chat_id' => $setting['Channel_Report'],
                        'message_thread_id' => $porsantreport,
                        'text' => $textreportport,
                        'parse_mode' => "HTML"
                    ]);
                }
                sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
            }
        }
        if ($marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['customTextSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['usernameSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['numericIdSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['agentCustomTextSequential']) {
            $value = intval($Balance_id['number_username']) + 1;
            update("user", "number_username", $value, "id", $Balance_id['id']);
            if ($marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['customTextSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['agentCustomTextSequential']) {
                $value = intval($setting['numbercount']) + 1;
                update("setting", "numbercount", $value);
            }
        }
        $Balance_prims = $Balance_id['Balance'] - $get_invoice['price_product'];
        if ($Balance_prims <= 0)
            $Balance_prims = 0;
        update("user", "Balance", $Balance_prims, "id", $Balance_id['id']);
        $balanceformatsell = select("user", "Balance", "id", $get_invoice['id_user'], "select")['Balance'];
        $balanceformatsell = number_format($balanceformatsell, 0);
        $balancebefore = number_format($Balance_id['Balance'], 0);
        $timejalali = jdate('Y/m/d H:i:s');
        $textonebuy = "";
        if ($countinvoice == 1) {
            $textonebuy = $textbotlang['extracted']['index_php']['firstPurchaseLabel'];
        }
        $Response = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['Admin']['manageUser']['manageUserBtn'], 'callback_data' => 'manageuser_' . $Balance_id['id']],
                ],
            ]
        ]);
        $text_report = sprintf($textbotlang['hardcoded']['accountCreateReportAfterPay'], $textonebuy, $Balance_id['id'], $Balance_id['username'], $username_ac, $get_invoice['Service_location'], $get_invoice['Service_time'], $get_invoice['name_product'], $get_invoice['Volume'], $balancebefore, $balanceformatsell, $get_invoice['id_invoice'], $Balance_id['agent'], $Balance_id['number'], $get_invoice['price_product'], $Payment_report['price'], $timejalali);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $buyreport,
                'text' => $text_report,
                'parse_mode' => "HTML",
                'reply_markup' => $Response
            ]);
        }
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        update("invoice", "Status", "active", "username", $get_invoice['username']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            update("invoice", "Status", "active", "id_invoice", $get_invoice['id_invoice']);
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedNewService'], $username_ac, $get_invoice['Service_location'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart, $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
    } elseif ($steppay[0] == "getextenduser") {
        $balanceformatsell = number_format(select("user", "Balance", "id", $Balance_id['id'], "select")['Balance'], 0);
        $partsdic = explode("%", $steppay[1]);
        $usernamepanel = $partsdic[0];
        $sql = "SELECT * FROM service_other WHERE username = :username  AND value  LIKE CONCAT('%', :value, '%') AND id_user = :id_user ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':username', $usernamepanel, PDO::PARAM_STR);
        $stmt->bindParam(':value', $partsdic[1], PDO::PARAM_STR);
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $data_order = $stmt->fetch(PDO::FETCH_ASSOC);
        $service_other = $data_order;
        if ($service_other == false) {
            sendmessage($Balance_id['id'], $textbotlang['hardcoded']['renewGenericError'], $keyboard, 'HTML');
            return;
        }
        $service_other = json_decode($service_other['value'], true);
        $codeproduct = $service_other['code_product'];
        $nameloc = select("invoice", "*", "username", $usernamepanel, "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        if ($codeproduct == "custom_volume") {
            $prodcut['code_product'] = "custom_volume";
            $prodcut['name_product'] = $nameloc['name_product'];
            $prodcut['price_product'] = $data_order['price'];
            $prodcut['Service_time'] = $service_other['Service_time'];
            $prodcut['Volume_constraint'] = $service_other['volumebuy'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE (Location = :mp2 OR Location = '/all') AND agent= :mp3 AND code_product = :mp4");
            $stmt->execute([':mp2' => $nameloc['Service_location'], ':mp3' => $Balance_id['agent'], ':mp4' => $codeproduct]);
            $prodcut = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($nameloc['name_product'] == $textbotlang['hardcoded']['testServiceNameFn']) {
            update("invoice", "name_product", $prodcut['name_product'], "id_invoice", $nameloc['id_invoice']);
            update("invoice", "price_product", $prodcut['price_product'], "id_invoice", $nameloc['id_invoice']);
        }
        $dateacc = bot_date('Y/m/d H:i:s');
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $nameloc['username']);
        $Balance_Low_user = 0;
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $extend = $ManagePanel->extend($marzban_list_get['Methodextend'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $nameloc['username'], $prodcut['code_product'], $marzban_list_get['code_panel']);
        if ($extend['status'] == false) {
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['errorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['serviceRenewFailedRefund'], $balance), $keyboard, 'HTML');
            $extend['msg'] = json_encode($extend['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['renewServiceErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extend['msg']);
            sendmessage($nameloc['id_user'], $textbotlang['extracted']['index_php']['renewServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }

        update("service_other", "output", json_encode($extend), "id", $data_order['id']);
        update("service_other", "status", "paid", "id", $data_order['id']);
        $partsdic = explode("_", $Balance_id['Processing_value_four']);
        if ($partsdic[0] == "dis") {
            $SellDiscountlimit = select("DiscountSell", "*", "codeDiscount", $partsdic[1], "select");
            $value = intval($SellDiscountlimit['usedDiscount']) + 1;
            update("DiscountSell", "usedDiscount", $value, "codeDiscount", $partsdic[1]);
            $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user,code) VALUES (:id_user,:code)");
            $stmt->bindParam(':id_user', $Balance_id['id']);
            $stmt->bindParam(':code', $partsdic[1]);
            $stmt->execute();
            $text_report = sprintf($textbotlang['hardcoded']['discountCodeUsedAdminFn'], $Balance_id['username'], $Balance_id['id'], $partsdic[1]);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $keyboardextendfnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backlist'], 'callback_data' => "backorder"],
                ],
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        if ($Balance_id['agent'] == "f") {
            $valurcashbackextend = select("shopSetting", "*", "Namevalue", "chashbackextend", "select")['value'];
        } else {
            $valurcashbackextend = json_decode(select("shopSetting", "*", "Namevalue", "chashbackextend_agent", "select")['value'], true)[$Balance_id['agenr']];
        }
        if (intval($valurcashbackextend) != 0) {
            $result = ($prodcut['price_product'] * $valurcashbackextend) / 100;
            $pricelastextend = $result;
            update("user", "Balance", $pricelastextend, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['renewGiftChargedFn'], $result), null, 'HTML');
        }
        $priceproductformat = number_format($prodcut['price_product']);
        $textextend = sprintf($textbotlang['hardcoded']['renewServiceSuccessFn'], $usernamepanel, $prodcut['name_product'], $priceproductformat);
        sendmessage($Balance_id['id'], $textextend, $keyboardextendfnished, 'HTML');
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
            $scorenew = $Balance_id['score'] + 2;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $timejalali = jdate('Y/m/d H:i:s');
        $text_report = sprintf($textbotlang['hardcoded']['renewReportAdminFn'], $Balance_id['id'], $Balance_id['username'], $usernamepanel, $nameloc['Service_location'], $prodcut['name_product'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $priceproductformat, $balanceformatsell, $timejalali);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {

            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedRenew'], $usernamepanel, $prodcut['name_product'], $nameloc['Service_location'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart, $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
    } elseif ($steppay[0] == "getextravolumeuser") {
        $steppay = explode("%", $steppay[1]);
        $volume = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != null) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'volume_value' => $volume,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = bot_date('Y/m/d H:i:s');
        $type = "extra_user";
        $extra_volume = $ManagePanel->extra_volume($nameloc['username'], $marzban_list_get['code_panel'], $volume);
        if ($extra_volume['status'] == false) {
            $extra_volume['msg'] = json_encode($extra_volume['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['extraVolumeErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extra_volume['msg']);
            sendmessage($nameloc['id_user'], $textbotlang['extracted']['index_php']['extraVolumeServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindParam(':output', json_encode($extra_volume));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price'], 0);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textvolume = sprintf($textbotlang['hardcoded']['extraVolumeSuccessFn'], $steppay[0], $volume, $volumesformat);
        sendmessage($Balance_id['id'], $textvolume, $keyboardextrafnished, 'HTML');
        $volumes = $volume;
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedExtraVolume'], $volumes, $steppay[0], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = sprintf($textbotlang['hardcoded']['extraVolumeReportAdminFn'], $Balance_id['id'], $volumes, $Payment_report['price'], $steppay[0], $Balance_id['Balance']);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
    } elseif ($steppay[0] == "getextratimeuser") {
        $steppay = explode("%", $steppay[1]);
        $tmieextra = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != false) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $nameloc['id_user']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'day' => $tmieextra,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = bot_date('Y/m/d H:i:s');
        $type = "extra_time_user";
        $timeservice = $DataUserOut['expire'] - time();
        $day = floor($timeservice / 86400);
        $extra_time = $ManagePanel->extra_time($nameloc['username'], $marzban_list_get['code_panel'], $tmieextra);
        if ($extra_time['status'] == false) {
            $extra_time['msg'] = json_encode($extra_time['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['extraTimeErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extra_time['msg']);
            sendmessage($from_id, $textbotlang['extracted']['index_php']['extraVolumeServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindParam(':output', json_encode($extra_time));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price']);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textextratime = sprintf($textbotlang['hardcoded']['extraTimeSuccessFn'], $steppay[0], $tmieextra, $volumesformat);
        sendmessage($Balance_id['id'], $textextratime, $keyboardextrafnished, 'HTML');
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $volumes = $tmieextra;
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedExtraTime'], $volumes, $steppay[0], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = sprintf($textbotlang['hardcoded']['extraTimeReportAdminFn'], $Balance_id['id'], $volumes, $Payment_report['price'], $steppay[0]);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
            ]);
        }
    } else {
        $Balance_confrim = intval($Balance_id['Balance']) + intval($Payment_report['price']);
        update("user", "Balance", $Balance_confrim, "id", $Payment_report['id_user']);
        update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
        $Payment_report['price'] = number_format($Payment_report['price'], 0);
        $format_price_cart = $Payment_report['price'];
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = sprintf($textbotlang['hardcoded']['newPaymentBalanceChargeFn'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $format_price_cart, $Balance_id['Balance'], $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        sendmessage($Payment_report['id_user'], sprintf($textbotlang['hardcoded']['balanceChargedThanks'], $Payment_report['price'], $Payment_report['id_order']), null, 'HTML');
    }
}
function plisio($order_id, $price, $from_id)
{
    $apinowpayments = select("PaySetting", "ValuePay", "NamePay", "apinowpayment", "select")['ValuePay'];
    $api_key = $apinowpayments;

    $url = 'https://api.plisio.net/api/v1/invoices/new';
    $url .= '?source_currency=USD';
    $url .= '&source_amount=' . urlencode($price);
    $url .= '&order_number=' . urlencode($order_id);
    $url .= '&email=customer@plisio.net';
    $url .= '&order_name=' . urlencode('TopUp - ' . $from_id);
    $url .= '&language=fa';
    $url .= '&api_key=' . urlencode($api_key);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = json_decode(curl_exec($ch), true);
    return $response['data'];
}
function checkConnection($address, $port)
{
    $socket = @stream_socket_client("tcp://$address:$port", $errno, $errstr, 5);
    if ($socket) {
        fclose($socket);
        return true;
    } else {
        return false;
    }
}
function savedata($type, $namefiled, $valuefiled)
{
    global $from_id;
    if ($type == "clear") {
        $datauser = [];
        $datauser[$namefiled] = $valuefiled;
        $data = json_encode($datauser);
        update("user", "Processing_value", $data, "id", $from_id);
    } elseif ($type == "save") {
        $userdata = select("user", "*", "id", $from_id, "select");
        $dataperevieos = json_decode($userdata['Processing_value'], true);
        $dataperevieos[$namefiled] = $valuefiled;
        update("user", "Processing_value", json_encode($dataperevieos), "id", $from_id);
    }
}
function addFieldToTable($tableName, $fieldName, $defaultValue = null, $datatype = "VARCHAR(500)")
{
    global $pdo;

    assertSqlIdentifier($tableName);
    assertSqlIdentifier($fieldName);
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_name = :tableName");
    $stmt->bindParam(':tableName', $tableName);
    $stmt->execute();
    $tableExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tableExists['count'] == 0)
        return;
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$pdo->query("SELECT DATABASE()")->fetchColumn(), $tableName, $fieldName]);
    $filedExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($filedExists['count'] != 0)
        return;
    $query = "ALTER TABLE $tableName ADD $fieldName $datatype";
    $statement = $pdo->prepare($query);
    $statement->execute();
    if ($defaultValue != null) {
        $stmt = $pdo->prepare("UPDATE $tableName SET $fieldName= ?");
        $stmt->bindParam(1, $defaultValue);
        $stmt->execute();
    }
    echo "The $fieldName field was added ✅";
}
function outtypepanel($typepanel, $message)
{
    global $from_id, $optionMarzban, $optionX_ui_single, $optionhiddfy, $option_mirza, $optionalireza_single, $optionmarzneshin, $option_mikrotik, $optionwg, $options_ui, $optionibsng, $optionrebecca, $option_connectix, $option_eylan;
    if ($typepanel == "marzban") {
        sendmessage($from_id, $message, $optionMarzban, 'HTML');
    } elseif ($typepanel == "x-ui_single") {
        sendmessage($from_id, $message, $optionX_ui_single, 'HTML');
    } elseif ($typepanel == "hiddify") {
        sendmessage($from_id, $message, $optionhiddfy, 'HTML');
    } elseif ($typepanel == "alireza_single") {
        sendmessage($from_id, $message, $optionalireza_single, 'HTML');
    } elseif ($typepanel == "marzneshin") {
        sendmessage($from_id, $message, $optionmarzneshin, 'HTML');
    } elseif ($typepanel == "WGDashboard") {
        sendmessage($from_id, $message, $optionwg, 'HTML');
    } elseif ($typepanel == "s_ui") {
        sendmessage($from_id, $message, $options_ui, 'HTML');
    } elseif ($typepanel == "ibsng") {
        sendmessage($from_id, $message, $optionibsng, 'HTML');
    } elseif ($typepanel == "mikrotik") {
        sendmessage($from_id, $message, $option_mikrotik, 'HTML');
    } elseif ($typepanel == "mirza_agent") {
        sendmessage($from_id, $message, $option_mirza, 'HTML');
    } elseif ($typepanel == "rebecca") {
        sendmessage($from_id, $message, $optionrebecca, 'HTML');
    } elseif ($typepanel == "eylan") {
        sendmessage($from_id, $message, $option_eylan, 'HTML');
    } elseif ($typepanel == "connectix") {
        sendmessage($from_id, $message, $option_connectix, 'HTML');
    }
}

function addBackgroundImage($urlimage, $qrCodeResult, $backgroundPath)
{
    if (!file_exists($backgroundPath)) {
        error_log("addBackgroundImage: File not found at $backgroundPath");
        file_put_contents($urlimage, $qrCodeResult->getString());
        return;
    }

    $qrString = $qrCodeResult->getString();
    $qrCodeImage = imagecreatefromstring($qrString);
    if (!$qrCodeImage) {
        error_log("addBackgroundImage: Failed to create QR Code resource");
        return;
    }

    $backgroundImage = null;

    try {
        $backgroundImage = imagecreatefromjpeg($backgroundPath);
    } catch (Throwable $t) {
        error_log("addBackgroundImage::EXCEPTION loading image: " . $t->getMessage());
    }

    if (!$backgroundImage) {
        $lastError = error_get_last();
        error_log("addBackgroundImage::System Error: " . $lastError['message']);

        imagepng($qrCodeImage, $urlimage);
        imagedestroy($qrCodeImage);
        return;
    }

    $qrCodeWidth = imagesx($qrCodeImage);
    $qrCodeHeight = imagesy($qrCodeImage);
    $backgroundWidth = imagesx($backgroundImage);
    $backgroundHeight = imagesy($backgroundImage);

    $x = ($backgroundWidth - $qrCodeWidth) / 2;
    $y = ($backgroundHeight - $qrCodeHeight) / 2;

    imagecopy($backgroundImage, $qrCodeImage, $x, $y, 0, 0, $qrCodeWidth, $qrCodeHeight);

    imagepng($backgroundImage, $urlimage);

    imagedestroy($qrCodeImage);
    imagedestroy($backgroundImage);
}

function checktelegramip()
{
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($clientIp) || $clientIp === '') {
        return false;
    }

    $clientIp = trim($clientIp);
    if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
        return false;
    }

    $telegramIpRanges = [
        ['lower' => '149.154.160.0', 'upper' => '149.154.175.255'],
        ['lower' => '91.108.4.0', 'upper' => '91.108.7.255'],
        ['lower' => '2001:67c:4e8::', 'upper' => '2001:67c:4e8:ffff:ffff:ffff:ffff:ffff']
    ];

    foreach ($telegramIpRanges as $range) {
        if (isClientIpInRange($clientIp, $range['lower'], $range['upper'])) {
            return true;
        }
    }

    return false;
}

function isClientIpInRange($clientIp, $lowerBound, $upperBound)
{
    $clientPacked = inet_pton($clientIp);
    $lowerPacked = inet_pton($lowerBound);
    $upperPacked = inet_pton($upperBound);

    if ($clientPacked === false || $lowerPacked === false || $upperPacked === false) {
        return false;
    }

    $length = strlen($clientPacked);
    if ($length !== strlen($lowerPacked) || $length !== strlen($upperPacked)) {
        return false;
    }

    return strcmp($clientPacked, $lowerPacked) >= 0 && strcmp($clientPacked, $upperPacked) <= 0;
}

// وضعیت مقدار محرمانه وبهوک (secret_token) در config.php
function webhookSecretConfigured()
{
    global $WEBHOOK_SECRET_TOKEN;
    $s = (string) ($WEBHOOK_SECRET_TOKEN ?? '');
    return $s !== '' && strpos($s, '{') === false;
}

// بررسی secret وبهوک.
// - هدر X-Telegram-Bot-Api-Secret-Token همیشه اولویت دارد (در لاگ وب‌سرور ثبت نمی‌شود).
// - بله هنگام تحویل وبهوک هدر را نمی‌فرستد؛ فقط برای بله ?secret= در URL پذیرفته می‌شود.
// - اگر secret تنظیم نشده باشد نتیجه همیشه false است (fail-closed).
function checkWebhookSecret($platform = 'telegram')
{
    global $WEBHOOK_SECRET_TOKEN;
    if (!webhookSecretConfigured()) {
        return false;
    }
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']
        ?? $_SERVER['HTTP_X_BALE_BOT_API_SECRET_TOKEN']
        ?? null;
    if (is_string($header) && $header !== '') {
        return hash_equals((string) $WEBHOOK_SECRET_TOKEN, $header);
    }
    if ($platform === 'bale' && isset($_GET['secret']) && is_string($_GET['secret'])) {
        return hash_equals((string) $WEBHOOK_SECRET_TOKEN, $_GET['secret']);
    }
    return false;
}

// مجوز درخواست وبهوک برای هر دو پلتفرم:
// - تلگرام: secret در هدر؛ در غیر این صورت فقط IPهای رسمی تلگرام (سازگاری با وبهوک‌های قدیمی).
// - بله: فقط secret معتبر. بدون secret درخواست رد می‌شود (fail-closed).
function checkWebhookAuthorized($platform = 'telegram')
{
    if (checkWebhookSecret($platform)) {
        return true;
    }
    if ($platform === 'bale') {
        if (!webhookSecretConfigured()) {
            error_log('Bale webhook rejected: WEBHOOK_SECRET_TOKEN is not set. Run: php ensure_webhook_secret.php');
        }
        return false;
    }
    return checktelegramip();
}

function addCronIfNotExists($cronCommand)
{
    $commands = is_array($cronCommand) ? $cronCommand : [$cronCommand];
    $commands = array_values(array_filter(array_map('trim', $commands), static function ($command) {
        return $command !== '';
    }));

    if (empty($commands)) {
        return true;
    }

    $logContext = implode('; ', $commands);

    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $crontabBinary = getCrontabBinary();
    if ($crontabBinary === null) {
        error_log('crontab executable not found; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $existingCronJobs = runShellCommand(sprintf('%s -l 2>/dev/null', escapeshellarg($crontabBinary)));
    $existingCronJobs = trim((string) $existingCronJobs);
    $cronLines = $existingCronJobs === '' ? [] : preg_split('/\r?\n/', $existingCronJobs);
    $cronLines = array_values(array_filter(array_map('trim', $cronLines), static function ($line) {
        return $line !== '' && strpos($line, '#') !== 0;
    }));

    $newLineAdded = false;
    foreach ($commands as $command) {
        if (!in_array($command, $cronLines, true)) {
            $cronLines[] = $command;
            $newLineAdded = true;
        }
    }

    if (!$newLineAdded) {
        return true;
    }

    $cronLines = array_values(array_unique($cronLines));
    $cronContent = implode(PHP_EOL, $cronLines) . PHP_EOL;

    $temporaryFile = tempnam(sys_get_temp_dir(), 'cron');
    if ($temporaryFile === false) {
        error_log('Unable to create temporary file for cron job registration.');
        return false;
    }

    if (file_put_contents($temporaryFile, $cronContent) === false) {
        error_log('Unable to write cron configuration to temporary file: ' . $temporaryFile);
        unlink($temporaryFile);
        return false;
    }

    runShellCommand(sprintf('%s %s', escapeshellarg($crontabBinary), escapeshellarg($temporaryFile)));
    unlink($temporaryFile);

    return true;
}

function activecron()
{
    global $domainhosts;

    $cronCommands = [
        "*/15 * * * * curl https://$domainhosts/cronbot/statusday.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/croncard.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/NoticationsService.php",
        "*/5 * * * * curl https://$domainhosts/cronbot/payment_expire.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/sendmessage.php",
        "*/3 * * * * curl https://$domainhosts/cronbot/plisio.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/activeconfig.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/disableconfig.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/iranpay1.php",
        "5 * * * * curl -fsS https://$domainhosts/cronbot/backupbot.php",
        "*/2 * * * * curl https://$domainhosts/cronbot/gift.php",
        "*/30 * * * * curl https://$domainhosts/cronbot/expireagent.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/on_hold.php",
        "*/2 * * * * curl https://$domainhosts/cronbot/configtest.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/uptime_node.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/uptime_panel.php",
    ];

    addCronIfNotExists($cronCommands);
}
function createInvoice($amount)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];

    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.com/api/factor/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array('amount' => $amount, 'address' => $walletaddress, 'base' => 'trx'),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);
    return json_decode($response, true);
}
function verifpay($id)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.ir/api/factor/status?id=' . $id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);


    return $response;
}
function createInvoiceiranpay1($amount, $id_invoice)
{
    global $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "marchent_floypay", "select")['ValuePay'];
    $curl = curl_init();
    $amount = intval($amount);
    $data = [
        "ApiKey" => $PaySetting,
        "Hash_id" => $id_invoice,
        "Amount" => $amount . "0",
        "CallbackURL" => "https://$domainhosts/payment/iranpay1.php"
    ];
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://tetra98.com/api/create_order",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'accept: application/json',
            'Content-Type: application/json'
        ),
    ));

    $response = curl_exec($curl);
    return json_decode($response, true);
}
function sanitizeUserName($userName)
{
    $forbiddenCharacters = [
        "'",
        "\"",
        "<",
        ">",
        "--",
        "#",
        ";",
        "\\",
        "%",
        "(",
        ")"
    ];

    foreach ($forbiddenCharacters as $char) {
        $userName = str_replace($char, "", $userName);
    }

    return $userName;
}
function publickey()
{
    $privateKey = sodium_crypto_box_keypair();
    $privateKeyEncoded = base64_encode(sodium_crypto_box_secretkey($privateKey));
    $publicKey = sodium_crypto_box_publickey($privateKey);
    $publicKeyEncoded = base64_encode($publicKey);
    $presharedKey = base64_encode(random_bytes(32));
    return [
        'private_key' => $privateKeyEncoded,
        'public_key' => $publicKeyEncoded,
        'preshared_key' => $presharedKey
    ];
}
function languagechange($path_dir = null, string $lang = 'fa')
{
    global $from_id;
    $user_lang = select("user", "*", "id", $from_id);
    $lang = $user_lang ? $user_lang['lang'] : $lang;
    $allowed = ['fa', 'en', 'ar', 'ru', 'zh'];
    if (!in_array($lang, $allowed, true))
        $lang = 'fa';
    $base_dir = $path_dir ?: __DIR__;
    $texts = require $base_dir . '/lang/' . $lang . '.php';
    if (is_array($texts))
        bottext_apply_overrides($texts, $lang);
    return $texts;
}
function bottext_apply_overrides(array &$base, $lang)
{
    $row = select("setting", "*", null, null, "select");
    $raw = is_array($row) ? ($row['text_edit'] ?? null) : null;
    if (!is_string($raw) || $raw === '')
        return;
    $map = json_decode($raw, true);
    if (!is_array($map))
        return;
    $langMap = $map[$lang] ?? null;
    if (!is_array($langMap))
        return;
    foreach ($langMap as $group => $pairs) {
        if (!is_array($pairs))
            continue;
        if (!isset($base[$group]) || !is_array($base[$group]))
            $base[$group] = [];
        foreach ($pairs as $k => $v) {
            if (is_string($v))
                $base[$group][$k] = $v;
        }
    }
}
function generateAuthStr($length = 10)
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return substr(str_shuffle(str_repeat($characters, ceil($length / strlen($characters)))), 0, $length);
}

/**
 * فاکتورهای پرداخت‌نشده کاربر را نشان می‌دهد تا بتواند لغو یا ادامه دهد.
 * به‌جای قفل کامل با پیام isSetPay
 */
function showPendingPayments($from_id)
{
    global $pdo, $textbotlang;
    $st = $pdo->prepare("SELECT * FROM Payment_report WHERE id_user = ? AND payment_Status = 'Unpaid' ORDER BY time DESC LIMIT 10");
    $st->execute([(string) $from_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return false;
    }
    $lines = ["⚠️ شما پرداخت تأییدنشده دارید.\nمی‌توانید آن را لغو کنید یا ادامه دهید:\n"];
    $keyboard = ['inline_keyboard' => []];
    foreach ($rows as $r) {
        $price = number_format((float) $r['price']);
        $method = $r['Payment_Method'] ?? '-';
        $oid = $r['id_order'];
        $lines[] = "• مبلغ: {$price} تومان\n  روش: {$method}\n  کد: <code>{$oid}</code>";
        $keyboard['inline_keyboard'][] = [
            ['text' => "❌ لغو این فاکتور", 'callback_data' => "cancel_unpaid_{$oid}"],
        ];
        // ادامه فقط برای کارت‌به‌کارت معنی‌دار است (دوباره شماره کارت)
        if (stripos((string) $method, 'cart') !== false) {
            $keyboard['inline_keyboard'][] = [
                ['text' => "💳 ادامه پرداخت (کارت‌به‌کارت)", 'callback_data' => "continue_unpaid_{$oid}"],
            ];
        }
    }
    $keyboard['inline_keyboard'][] = [
        ['text' => "🗑 لغو همه فاکتورهای باز", 'callback_data' => "cancel_all_unpaid"],
    ];
    $keyboard['inline_keyboard'][] = [
        ['text' => $textbotlang['keyboard']['closeList'] ?? 'بستن', 'callback_data' => "colselist"],
    ];
    sendmessage($from_id, implode("\n\n", $lines), json_encode($keyboard), 'HTML');
    return true;
}

function cancelUnpaidPayment($from_id, $id_order = null)
{
    global $pdo;
    if ($id_order === null || $id_order === '') {
        $st = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'expire' WHERE id_user = ? AND payment_Status = 'Unpaid'");
        $st->execute([(string) $from_id]);
        return $st->rowCount();
    }
    $st = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'expire' WHERE id_user = ? AND id_order = ? AND payment_Status = 'Unpaid'");
    $st->execute([(string) $from_id, (string) $id_order]);
    return $st->rowCount();
}


function createqrcode($contents)
{
    $builder = new Builder(
        writer: new PngWriter(),
        writerOptions: [],
        data: $contents,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::High,
        size: 500,
        margin: 10,
    );

    $result = $builder->build();
    return $result;
}
function sanitize_recursive(array $data): array
{
    $sanitized_data = [];
    foreach ($data as $key => $value) {
        $sanitized_key = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        if (is_array($value)) {
            $sanitized_data[$sanitized_key] = sanitize_recursive($value);
        } elseif (is_string($value)) {
            $sanitized_data[$sanitized_key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        } elseif (is_int($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        } elseif (is_float($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        } elseif (is_bool($value) || is_null($value)) {
            $sanitized_data[$sanitized_key] = $value;
        } else {
            $sanitized_data[$sanitized_key] = $value;
        }
    }
    return $sanitized_data;
}

function check_active_btn($keyboard, $text_var)
{
    $trace_keyboard = json_decode($keyboard, true)['keyboard'];
    $status = false;
    foreach ($trace_keyboard as $key => $callback_set) {
        foreach ($callback_set as $keyboard_key => $keyboard) {
            if ($keyboard['text'] == $text_var) {
                $status = true;
                break;
            }
        }
    }
    return $status;
}
function deleteFolder($folderPath)
{
    if (!is_dir($folderPath))
        return false;

    $files = array_diff(scandir($folderPath), ['.', '..']);

    foreach ($files as $file) {
        $filePath = $folderPath . DIRECTORY_SEPARATOR . $file;
        if (is_dir($filePath)) {
            deleteFolder($filePath);
        } else {
            unlink($filePath);
        }
    }

    return rmdir($folderPath);
}
function isBase64($string)
{
    if (base64_encode(base64_decode($string, true)) === $string) {
        return true;
    }
    return false;
}
function sendMessageService($panel_info, $config, $sub_link, $username_service, $reply_markup, $caption, $invoice_id, $user_id = null, $image = 'images.jpg')
{
    global $setting, $from_id, $textbotlang;
    if (!check_active_btn($setting['keyboardmain'], "text_help"))
        $reply_markup = null;
    $user_id = $user_id == null ? $from_id : $user_id;

    // داده QR: اولویت با لینک اشتراک؛ در غیر این صورت اولین کانفیگ
    $out_put_qrcode = "";
    if (!empty($sub_link) && ($panel_info['sublink'] ?? '') == "onsublink") {
        $out_put_qrcode = $sub_link;
    } elseif (is_array($config) && count($config) > 0) {
        $out_put_qrcode = $config[0];
    } elseif (is_string($config) && $config !== "") {
        $out_put_qrcode = $config;
    }

    // کپشن کوتاه برای عکس (حد تلگرام ~1024) — لینک‌های طولانی vless را از کپشن حذف نکن؛ در پیام جدا می‌آیند
    $caption_photo = $caption;
    if (mb_strlen(strip_tags($caption_photo)) > 900) {
        // فقط بخش قبل از اولین vless/vmess را نگه دار
        $caption_photo = preg_replace('/(vless:\/\/|vmess:\/\/|trojan:\/\/).*/us', '', $caption_photo);
        $caption_photo = trim($caption_photo) . "\n\n📱 برای دریافت کانفیگ‌ها از دکمه‌های زیر استفاده کنید.";
        if (mb_strlen($caption_photo) > 1000) {
            $caption_photo = mb_substr($caption_photo, 0, 990) . "...";
        }
    }

    $cwd = defined('BOT_ROOT') ? BOT_ROOT : (isset($GLOBALS['bot_root']) ? $GLOBALS['bot_root'] : getcwd());
    if (!is_dir($cwd)) {
        $cwd = sys_get_temp_dir();
    }

    $photo_sent = false;
    if ($panel_info['type'] == "WGDashboard" && !empty($sub_link)) {
        $urlimage = rtrim($cwd, '/') . "/{$panel_info['inboundid']}_{$invoice_id}.conf";
        file_put_contents($urlimage, $sub_link);
        $r = telegram('senddocument', [
            'chat_id' => $user_id,
            'document' => new CURLFile($urlimage),
            'reply_markup' => $reply_markup,
            'caption' => mb_substr($caption_photo, 0, 1024),
            'parse_mode' => "HTML",
        ]);
        @unlink($urlimage);
        $photo_sent = !empty($r['ok']);
    } elseif ($out_put_qrcode !== "") {
        try {
            $urlimage = rtrim($cwd, '/') . "/{$user_id}_{$invoice_id}.png";
            $qrCode = createqrcode($out_put_qrcode);
            file_put_contents($urlimage, $qrCode->getString());
            $img_path = rtrim($cwd, '/') . '/' . $image;
            if (!is_file($img_path)) {
                $img_path = $image;
            }
            addBackgroundImage($urlimage, $qrCode, $img_path);
            $r = telegram('sendphoto', [
                'chat_id' => $user_id,
                'photo' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => mb_substr($caption_photo, 0, 1024),
                'parse_mode' => "HTML",
            ]);
            @unlink($urlimage);
            $photo_sent = !empty($r['ok']);
            if (!$photo_sent) {
                error_log('sendMessageService sendphoto failed: ' . json_encode($r));
            }
        } catch (Throwable $e) {
            error_log('sendMessageService QR error: ' . $e->getMessage());
            $photo_sent = false;
        }
    }

    if (!$photo_sent) {
        // بدون دیوار متنی کانفیگ: کپشن تمیز
        $caption_text = preg_replace('/(vless:\/\/|vmess:\/\/|trojan:\/\/).*/us', '', $caption);
        $caption_text = trim($caption_text);
        if ($caption_text === '') {
            $caption_text = $caption;
        }
        if (!empty($sub_link)) {
            $caption_text .= "\n\n🔗 <code>" . htmlspecialchars($sub_link) . "</code>";
        }
        sendmessage($user_id, $caption_text, $reply_markup, 'HTML');
    }

    // کانفیگ‌های تکی فقط با دکمه (نه چسباندن همه در یک پیام)
    if (($panel_info['config'] ?? '') == "onconfig" && ($setting['status_keyboard_config'] ?? '1') == "1") {
        if (is_array($config) && count($config) > 0) {
            $hint = $textbotlang['hardcoded']['getConfigHint'] ?? "برای دریافت هر کانفیگ روی دکمه مربوط کلیک کنید:";
            sendmessage($user_id, $hint, keyboard_config($config, $invoice_id, false), 'HTML');
        }
    }
}
function isValidInvitationCode($setting, $fromId, $verfy_status)
{
    global $textbotlang;

    if ($setting['verifybucodeuser'] == "onverify" && $verfy_status != 1) {
        sendmessage($fromId, $textbotlang['hardcoded']['accountVerifiedSuccess'], null, 'html');
        update("user", "verify", "1", "id", $fromId);
        update("user", "cardpayment", "1", "id", $fromId);
    }
}
function createPayZarinpal($price, $order_id)
{
    global $domainhosts;
    $marchent_zarinpal = select("PaySetting", "ValuePay", "NamePay", "merchant_zarinpal", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://payment.zarinpal.com/pg/v4/payment/request.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        "merchant_id" => $marchent_zarinpal,
        "currency" => "IRT",
        "amount" => $price,
        "callback_url" => "https://$domainhosts/payment/zarinpal.php",
        "description" => $order_id,
        "metadata" => array(
            "order_id" => $order_id
        )
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function createPayaqayepardakht($price, $order_id)
{
    global $domainhosts;
    $merchant_aqayepardakht = select("PaySetting", "ValuePay", "NamePay", "merchant_id_aqayepardakht", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://panel.aqayepardakht.ir/api/v2/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'pin' => $merchant_aqayepardakht,
        'amount' => $price,
        'callback' => $domainhosts . "/payment/aqayepardakht.php",
        'invoice_id' => $order_id,
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function parseConfigs($input)
{
    $lines = explode("\n", $input);
    $configs = [];

    $currentName = null;
    $currentData = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, '#') === 0) {
            if ($currentName && $currentData) {
                $configs[] = [
                    'name' => $currentName,
                    'config' => implode("\n", $currentData)
                ];
            }
            $currentName = trim(substr($line, 1));
            $currentData = [];
        } else {
            if ($line !== '') {
                $currentData[] = $line;
            }
        }
    }
    if ($currentName && $currentData) {
        $configs[] = [
            'name' => $currentName,
            'config' => implode("\n", $currentData)
        ];
    }

    return $configs;
}


/**
 * منطقه زمانی ربات — اولویت: PaySetting bot_timezone → $BOT_TIMEZONE در config → Asia/Tehran
 */
function getBotTimezone()
{
    global $BOT_TIMEZONE;
    $tz = 'Asia/Tehran';
    try {
        $row = select("PaySetting", "ValuePay", "NamePay", "bot_timezone", "select");
        if (is_array($row) && !empty($row['ValuePay'])) {
            $tz = trim((string) $row['ValuePay']);
        } elseif (!empty($BOT_TIMEZONE)) {
            $tz = trim((string) $BOT_TIMEZONE);
        }
    } catch (Throwable $e) {
        if (!empty($BOT_TIMEZONE)) {
            $tz = trim((string) $BOT_TIMEZONE);
        }
    }
    try {
        new DateTimeZone($tz);
    } catch (Throwable $e) {
        $tz = 'Asia/Tehran';
    }
    return $tz;
}

function applyBotTimezone()
{
    $tz = getBotTimezone();
    @date_default_timezone_set($tz);
    return $tz;
}

/**
 * تاریخ/ساعت نمایشی شمسی بر اساس timezone ربات
 * $timestamp: null = الان | int unix | قابل strtotime
 */
function bot_date($format = 'Y/m/d H:i:s', $timestamp = null, $digits = 'en')
{
    if (function_exists('applyBotTimezone')) {
        applyBotTimezone();
    }
    if ($timestamp === null || $timestamp === '') {
        $ts = time();
    } elseif (is_numeric($timestamp)) {
        $ts = (int) $timestamp;
    } else {
        $ts = strtotime((string) $timestamp);
        if ($ts === false) {
            $ts = time();
        }
    }
    if (function_exists('jdate')) {
        // ارقام لاتین (en) یا فارسی (fa) — پیش‌فرض لاتین
        $tr = ($digits === 'fa') ? 'fa' : 'en';
        return jdate($format, $ts, '', getBotTimezone(), $tr);
    }
    return date($format, $ts);
}

/**
 * Delete a panel (server) and every row that only makes sense while it exists:
 * its products, panel-scoped discounts and manual-sell stock. Invoices are kept
 * for accounting history. Returns the number of deleted products.
 */
function virabot_delete_panel_cascade(PDO $pdo, array $panel)
{
    $name = (string) ($panel['name_panel'] ?? '');
    $code = (string) ($panel['code_panel'] ?? '');
    $deletedProducts = 0;
    $pdo->beginTransaction();
    try {
        if ($name !== '') {
            $st = $pdo->prepare("DELETE FROM product WHERE Location = :loc");
            $st->execute([':loc' => $name]);
            $deletedProducts = $st->rowCount();
        }
        if ($code !== '') {
            foreach ([['DiscountSell', 'code_panel'], ['manualsell', 'codepanel']] as $t) {
                try {
                    $st = $pdo->prepare("DELETE FROM `{$t[0]}` WHERE `{$t[1]}` = :code");
                    $st->execute([':code' => $code]);
                } catch (Throwable $e) {
                    // optional table/column may be missing on old installs
                }
            }
        }
        $st = $pdo->prepare("DELETE FROM marzban_panel WHERE id = :id");
        $st->execute([':id' => (int) ($panel['id'] ?? 0)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $deletedProducts;
}

/**
 * SQL fragment restricting product.Location to panels that still exist and are
 * active ("/all" products stay visible). Used by the tariff list.
 */
function virabot_live_panel_location_sql($alias = 'product')
{
    return "({$alias}.Location = '/all' OR {$alias}.Location IN (SELECT mp.name_panel COLLATE utf8mb4_unicode_ci FROM marzban_panel mp WHERE mp.status = 'active'))";
}
