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
        $datac = array(
            'expire' => $timestamp,
            'data_limit' => $get_invoice['Volume'] * pow(1024, 3),
            'from_id' => $Balance_id['id'],
            'username' => $Balance_id['username'],
            'type' => 'buy'
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
        sendMessageService($marzban_list_get, $dataoutput['configs'], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $get_invoice['id_invoice'], $get_invoice['id_user'], $image);
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
                    $dateacc = date('Y/m/d H:i:s');
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
                $dateacc = date('Y/m/d H:i:s');
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
        $dateacc = date('Y/m/d H:i:s');
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
        $dateacc = date('Y/m/d H:i:s');
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
        $dateacc = date('Y/m/d H:i:s');
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
    global $from_id, $optionMarzban, $optionX_ui_single, $optionhiddfy, $option_mirza, $optionalireza_single, $optionmarzneshin, $option_mikrotik, $optionwg, $options_ui, $optionibsng, $optionrebecca;
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
        "0 */5 * * * curl https://$domainhosts/cronbot/backupbot.php",
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
    $STATUS_SEND_MESSAGE_PHOTO = $panel_info['config'] == "onconfig" && count($config) != 1 ? false : true;
    $out_put_qrcode = "";
    if ($panel_info['type'] == "Manualsale" || $panel_info['type'] == "ibsng" || $panel_info['type'] == "mikrotik") {
    }
    if ($panel_info['sublink'] == "onsublink" && $panel_info['config']) {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['sublink'] == "onsublink") {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['config'] == "onconfig") {
        $out_put_qrcode = $config[0];
    }
    if ($STATUS_SEND_MESSAGE_PHOTO) {
        if ($panel_info['type'] == "WGDashboard") {
            $urlimage = "{$panel_info['inboundid']}_{$invoice_id}.conf";
            file_put_contents($urlimage, $sub_link);
            telegram('senddocument', [
                'chat_id' => $user_id,
                'document' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => $caption,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        } else {
            $urlimage = "$user_id$invoice_id.png";
            $qrCode = createqrcode($out_put_qrcode);
            file_put_contents($urlimage, $qrCode->getString());
            addBackgroundImage($urlimage, $qrCode, $image);
            telegram('sendphoto', [
                'chat_id' => $user_id,
                'photo' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => $caption,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        }
    } else {
        sendmessage($user_id, $caption, $reply_markup, 'HTML');
    }
    if ($panel_info['config'] == "onconfig" && $setting['status_keyboard_config'] == "1") {
        if (is_array($config)) {
            sendmessage($user_id, $textbotlang['hardcoded']['getConfigHint'], keyboard_config($config, $invoice_id, false), 'HTML');
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
 * Virabot 5.5.2: serialize admin decisions on one payment (confirm / reject /
 * add-balance) so two admins pressing at the same moment can never process it
 * twice. Uses a MariaDB/MySQL named lock (no new payment_Status value); the
 * lock is released automatically at the end of the request. The caller must
 * re-read Payment_report AFTER taking the lock.
 */
function virabot_payment_lock($order_id, $timeout = 10)
{
    global $pdo;
    static $held = [];
    $order_id = (string) $order_id;
    if (isset($held[$order_id])) {
        return true;
    }
    if (!($pdo instanceof PDO)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT GET_LOCK(CONCAT('vbpay_', MD5(CONCAT(IFNULL(DATABASE(), ''), ':', ?))), ?)");
        $stmt->execute([$order_id, (int) $timeout]);
        if ((string) $stmt->fetchColumn() !== '1') {
            return false;
        }
    } catch (Throwable $e) {
        error_log('virabot_payment_lock: ' . $e->getMessage());
        return false;
    }
    $held[$order_id] = true;
    register_shutdown_function(function () use ($order_id) {
        global $pdo;
        try {
            if ($pdo instanceof PDO) {
                $stmt = $pdo->prepare("SELECT RELEASE_LOCK(CONCAT('vbpay_', MD5(CONCAT(IFNULL(DATABASE(), ''), ':', ?))))");
                $stmt->execute([$order_id]);
            }
        } catch (Throwable $e) {
        }
    });
    return true;
}

/* =====================================================================
 * Virabot 5.6.0 — category tools
 *  - virabot_ensure_category(): INSERT ... WHERE NOT EXISTS
 *  - default category per panel (marzban_panel.default_category)
 *  - «📦 سایر» for products whose category is NULL / '' / has no row
 *  - admin bulk checkbox screen (callback prefix vbc_)
 * Same block lives in patch/function.php and base/function.php.
 * ===================================================================== */
if (!function_exists('virabot_cat_t')) {

function virabot_cat_t($key, $default)
{
    global $textbotlang;
    $v = $textbotlang['vbcat'][$key] ?? null;
    return (is_string($v) && $v !== '') ? $v : $default;
}

/** Category values that were written automatically (not by an admin). */
function virabot_cat_legacy_defaults()
{
    return ['کانکتیکس'];
}

/** Create the category row if it does not exist yet. Returns the trimmed name or ''. */
function virabot_ensure_category($remark)
{
    global $pdo;
    $remark = trim((string) $remark);
    if ($remark === '' || mb_strlen($remark) > 400) {
        return '';
    }
    try {
        $st = $pdo->prepare("INSERT INTO category (remark) SELECT ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM category WHERE remark = ?)");
        $st->execute([$remark, $remark]);
    } catch (Throwable $e) {
        error_log('virabot_ensure_category: ' . $e->getMessage());
        return '';
    }
    return $remark;
}

/** Default category of a panel ('' when unset, '/all', or before migration). */
function virabot_panel_default_category($panelName)
{
    global $pdo;
    if ($panelName === null || $panelName === '' || $panelName === '/all') {
        return '';
    }
    try {
        $st = $pdo->prepare("SELECT default_category FROM marzban_panel WHERE name_panel = ? LIMIT 1");
        $st->execute([$panelName]);
        $v = $st->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
    return ($v === false || $v === null) ? '' : trim((string) $v);
}

/** Is the «سایر» button enabled? (on by default, also before migration) */
function virabot_other_enabled()
{
    global $setting, $pdo;
    if (is_array($setting) && array_key_exists('statusothercategory', $setting)) {
        return ($setting['statusothercategory'] ?? 'on') !== 'off';
    }
    try {
        $v = $pdo->query("SELECT statusothercategory FROM setting LIMIT 1")->fetchColumn();
        return $v !== 'off';
    } catch (Throwable $e) {
        return true;
    }
}

/** SQL condition: product (alias $a) has no usable category. */
function virabot_other_cond($a = 'p')
{
    return "($a.category IS NULL OR $a.category = '' OR NOT EXISTS (SELECT 1 FROM category vc WHERE vc.remark = $a.category))";
}

/** Panels (active, visible to agent) that have «سایر» products for that agent. */
function virabot_other_panels($agent)
{
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT DISTINCT m.name_panel, m.code_panel FROM marzban_panel m
            INNER JOIN product p ON (p.Location = m.name_panel OR p.Location = '/all')
            WHERE m.status = 'active' AND (m.agent = ? OR m.agent = 'all')
              AND p.agent = ?
              AND (p.one_buy_status IS NULL OR p.one_buy_status <> 'test')
              AND " . virabot_other_cond('p') . "
            ORDER BY m.name_panel ASC");
        $st->execute([$agent, $agent]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('virabot_other_panels: ' . $e->getMessage());
        return [];
    }
}

/** Does this panel have «سایر» products for the agent? */
function virabot_other_has_location($location, $agent)
{
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT 1 FROM product p WHERE (p.Location = ? OR p.Location = '/all') AND p.agent = ?
            AND (p.one_buy_status IS NULL OR p.one_buy_status <> 'test') AND " . virabot_other_cond('p') . " LIMIT 1");
        $st->execute([$location, $agent]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** [sql, params] for the «سایر» products of one panel (optionally one month). */
function virabot_other_product_query($location, $agent, $month = null)
{
    $sql = "SELECT p.* FROM product p WHERE (p.Location = ? OR p.Location = '/all') AND p.agent = ? AND " . virabot_other_cond('p');
    $params = [$location, $agent];
    if ($month !== null && $month !== '') {
        $sql .= " AND p.Service_time = ?";
        $params[] = (string) $month;
    }
    return [$sql, $params];
}

function virabot_other_button_text()
{
    return virabot_cat_t('btn_other', '📦 سایر');
}

/** Inline keyboard: panels that have «سایر» products (global category-first flow). */
function KeyboardPanelsForOther($agent)
{
    global $textbotlang;
    $list = ['inline_keyboard' => []];
    foreach (virabot_other_panels($agent) as $row) {
        $list['inline_keyboard'][] = [
            ['text' => $row['name_panel'], 'callback_data' => 'location_' . $row['code_panel']],
        ];
    }
    $list['inline_keyboard'][] = [
        ['text' => $textbotlang['keyboard']['backToPreviousMenu'] ?? '🔙 بازگشت', 'callback_data' => 'backtocategories'],
    ];
    return json_encode($list);
}

/* ---------------- admin: bulk checkbox screen ---------------- */

function virabot_cat_answer($text = '', $alert = false)
{
    global $callback_query_id;
    if (empty($callback_query_id)) {
        return;
    }
    $d = ['callback_query_id' => $callback_query_id];
    if ($text !== '') {
        $d['text'] = $text;
        $d['show_alert'] = $alert;
    }
    telegram('answerCallbackQuery', $d);
}

function virabot_cat_state_get()
{
    global $pdo, $from_id;
    try {
        $st = $pdo->prepare("SELECT Processing_value FROM user WHERE id = ? LIMIT 1");
        $st->execute([$from_id]);
        $raw = $st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    $d = json_decode((string) $raw, true);
    if (!is_array($d) || !isset($d['vbcat']) || !is_array($d['vbcat'])) {
        return null;
    }
    return $d['vbcat'];
}

function virabot_cat_state_put($state)
{
    global $pdo, $from_id;
    $st = $pdo->prepare("UPDATE user SET Processing_value = ? WHERE id = ?");
    $st->execute([json_encode(['vbcat' => $state], JSON_UNESCAPED_UNICODE), $from_id]);
}

function virabot_cat_short($s, $n)
{
    $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
    return mb_strlen($s) > $n ? (mb_substr($s, 0, $n - 1) . '…') : $s;
}

/** Product ids of a filter ('a' all, 'u' uncategorized, 'p<id>' one panel). Test products are excluded. */
function virabot_cat_filter_ids($filter)
{
    global $pdo;
    $where = "(p.one_buy_status IS NULL OR p.one_buy_status <> 'test')";
    $params = [];
    if ($filter === 'u') {
        $where .= " AND " . virabot_other_cond('p');
    } elseif (preg_match('/^p(\d+)$/', $filter, $m)) {
        $st = $pdo->prepare("SELECT name_panel FROM marzban_panel WHERE id = ? LIMIT 1");
        $st->execute([intval($m[1])]);
        $pn = $st->fetchColumn();
        if ($pn === false) {
            return null;
        }
        $where .= " AND p.Location = ?";
        $params[] = $pn;
    } elseif ($filter !== 'a') {
        return null;
    }
    $st = $pdo->prepare("SELECT p.id FROM product p WHERE $where ORDER BY p.Location ASC, p.name_product ASC, p.id ASC");
    $st->execute($params);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function virabot_cat_filter_label($filter)
{
    global $pdo;
    if ($filter === 'a') {
        return virabot_cat_t('f_all', 'همه پنل‌ها');
    }
    if ($filter === 'u') {
        return virabot_cat_t('f_uncat', 'فقط محصولات بدون دسته («سایر»)');
    }
    if (preg_match('/^p(\d+)$/', $filter, $m)) {
        $st = $pdo->prepare("SELECT name_panel FROM marzban_panel WHERE id = ? LIMIT 1");
        $st->execute([intval($m[1])]);
        $pn = $st->fetchColumn();
        return virabot_cat_t('f_panel', 'پنل') . ': ' . ($pn === false ? '?' : $pn);
    }
    return $filter;
}

function virabot_cat_filter_keyboard($catId)
{
    global $pdo;
    $catId = intval($catId);
    $kb = ['inline_keyboard' => []];
    $kb['inline_keyboard'][] = [['text' => '🌐 ' . virabot_cat_t('f_all', 'همه پنل‌ها'), 'callback_data' => "vbc_f_{$catId}_a"]];
    $kb['inline_keyboard'][] = [['text' => '📦 ' . virabot_cat_t('f_uncat', 'فقط محصولات بدون دسته («سایر»)'), 'callback_data' => "vbc_f_{$catId}_u"]];
    $rows = $pdo->query("SELECT id, name_panel FROM marzban_panel ORDER BY name_panel ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $kb['inline_keyboard'][] = [['text' => '🖥 ' . virabot_cat_short($r['name_panel'], 40), 'callback_data' => "vbc_f_{$catId}_p" . intval($r['id'])]];
    }
    $kb['inline_keyboard'][] = [['text' => virabot_cat_t('btn_close', '❌ بستن'), 'callback_data' => "vbc_xq_{$catId}"]];
    return json_encode($kb);
}

function virabot_cat_page_size()
{
    return 8;
}

/** Text + keyboard of the checkbox page. */
function virabot_cat_render($state)
{
    global $pdo;
    $ids = $state['ids'];
    $sel = array_flip($state['sel']);
    $total = count($ids);
    $ps = virabot_cat_page_size();
    $pages = max(1, (int) ceil($total / $ps));
    $page = min(max(1, intval($state['page'])), $pages);
    $slice = array_slice($ids, ($page - 1) * $ps, $ps);
    $selInSet = 0;
    foreach ($ids as $i) {
        if (isset($sel[$i])) {
            $selInSet++;
        }
    }
    $text = sprintf(
        virabot_cat_t('screen', "☑️ <b>انتخاب گروهی برای دسته «%s»</b>\nفیلتر: %s\nانتخاب‌شده: %d از %d — صفحه %d از %d\n\nروی هر محصول بزنید تا تیک بخورد یا برداشته شود، بعد «💾 ذخیره» را بزنید.\nبرداشتن تیک یعنی محصول بدون دسته می‌شود و زیر «📦 سایر» نمایش داده می‌شود."),
        htmlspecialchars($state['remark'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars(virabot_cat_filter_label($state['f']), ENT_QUOTES, 'UTF-8'),
        $selInSet, $total, $page, $pages
    );
    $kb = ['inline_keyboard' => []];
    if ($total === 0) {
        $text .= "\n\n" . virabot_cat_t('empty', '⚠️ در این فیلتر محصولی نیست.');
    }
    $info = [];
    if ($slice) {
        $in = implode(',', array_fill(0, count($slice), '?'));
        $st = $pdo->prepare("SELECT id, name_product, Location, agent, category FROM product WHERE id IN ($in)");
        $st->execute($slice);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $info[intval($r['id'])] = $r;
        }
    }
    foreach ($slice as $pid) {
        if (!isset($info[$pid])) {
            continue; // deleted meanwhile
        }
        $r = $info[$pid];
        $mark = isset($sel[$pid]) ? '☑️' : '⬜';
        $agentTag = ($r['agent'] !== 'f' && $r['agent'] !== null && $r['agent'] !== '') ? (' [' . $r['agent'] . ']') : '';
        $label = $mark . ' ' . virabot_cat_short($r['name_product'], 28) . $agentTag . ' | ' . virabot_cat_short($r['Location'], 14);
        $kb['inline_keyboard'][] = [['text' => $label, 'callback_data' => 'vbc_t_' . $pid]];
    }
    if ($pages > 1) {
        $nav = [];
        if ($page > 1) {
            $nav[] = ['text' => '◀️', 'callback_data' => 'vbc_p_' . ($page - 1)];
        }
        $nav[] = ['text' => "$page / $pages", 'callback_data' => 'vbc_p_' . $page];
        if ($page < $pages) {
            $nav[] = ['text' => '▶️', 'callback_data' => 'vbc_p_' . ($page + 1)];
        }
        $kb['inline_keyboard'][] = $nav;
    }
    if ($total > 0) {
        $kb['inline_keyboard'][] = [
            ['text' => virabot_cat_t('btn_all', '✅ انتخاب همه'), 'callback_data' => 'vbc_all'],
            ['text' => virabot_cat_t('btn_none', '⬜ هیچ‌کدام'), 'callback_data' => 'vbc_none'],
        ];
        $kb['inline_keyboard'][] = [['text' => virabot_cat_t('btn_save', '💾 ذخیره'), 'callback_data' => 'vbc_save']];
    }
    $kb['inline_keyboard'][] = [
        ['text' => virabot_cat_t('btn_filter', '🔙 تغییر فیلتر'), 'callback_data' => 'vbc_b'],
        ['text' => virabot_cat_t('btn_cancel', '❌ انصراف'), 'callback_data' => 'vbc_xq_s'],
    ];
    return [$text, json_encode($kb)];
}

/**
 * Apply the selection in one transaction.
 * Returns ['ok'=>bool,'added'=>n,'removed'=>n,'unchanged'=>n,'error'=>?]
 */
function virabot_cat_save($state)
{
    global $pdo;
    $remark = (string) $state['remark'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM category WHERE id = ? AND remark = ?");
    $st->execute([intval($state['cat']), $remark]);
    if (intval($st->fetchColumn()) === 0) {
        return ['ok' => false, 'error' => 'category_missing'];
    }
    $ids = array_values(array_unique(array_map('intval', $state['ids'])));
    $sel = array_flip(array_map('intval', $state['sel']));
    $added = 0;
    $removed = 0;
    $unchanged = 0;
    try {
        $pdo->beginTransaction();
        $current = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT id, category FROM product WHERE id IN ($in) FOR UPDATE");
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $current[intval($r['id'])] = $r['category'];
            }
        }
        $toAdd = [];
        $toRemove = [];
        foreach ($current as $pid => $cat) {
            $inCat = ($cat !== null && (string) $cat === $remark);
            if (isset($sel[$pid]) && !$inCat) {
                $toAdd[] = $pid;
            } elseif (!isset($sel[$pid]) && $inCat) {
                $toRemove[] = $pid;
            } else {
                $unchanged++;
            }
        }
        foreach (array_chunk($toAdd, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $u = $pdo->prepare("UPDATE product SET category = ? WHERE id IN ($in)");
            $u->execute(array_merge([$remark], $chunk));
            $added += count($chunk);
        }
        foreach (array_chunk($toRemove, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $u = $pdo->prepare("UPDATE product SET category = NULL WHERE id IN ($in) AND category = ?");
            $u->execute(array_merge($chunk, [$remark]));
            $removed += count($chunk);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('virabot_cat_save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'db'];
    }
    return ['ok' => true, 'added' => $added, 'removed' => $removed, 'unchanged' => $unchanged];
}

/** Reply-keyboard texts of the new admin buttons. */
function virabot_cat_btn_bulk()
{
    return virabot_cat_t('btn_bulk', '☑️ انتخاب گروهی محصولات');
}
function virabot_cat_btn_other_toggle()
{
    return virabot_cat_t('btn_other_toggle', '📦 روشن/خاموش دکمه «سایر»');
}
function virabot_cat_btn_defcat()
{
    return virabot_cat_t('btn_defcat', '🗂 دسته پیش‌فرض پنل');
}
function virabot_cat_btn_defcat_clear()
{
    return virabot_cat_t('btn_defcat_clear', '🚫 بدون دسته پیش‌فرض');
}

/** Category reply keyboard with an extra first row. */
function virabot_cat_reply_keyboard($extraFirst = null)
{
    global $pdo, $textbotlang;
    $kb = ['keyboard' => [], 'resize_keyboard' => true];
    if ($extraFirst !== null) {
        $kb['keyboard'][] = [['text' => $extraFirst]];
    }
    foreach ($pdo->query("SELECT remark FROM category ORDER BY remark ASC")->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $kb['keyboard'][] = [['text' => $r]];
    }
    $kb['keyboard'][] = [['text' => $textbotlang['Admin']['backAdminBtn']]];
    return json_encode($kb);
}

/** 5.6.1: find a category from the text of a reply-keyboard button.
 * Exact match first (raw text, before Persian-digit conversion), then a
 * whitespace-normalized match, because Telegram trims/collapses spaces in
 * button texts and $text has its Persian digits converted. */
function virabot_cat_find_by_text($text)
{
    global $pdo, $update;
    $cands = [];
    $raw = $update['message']['text'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $cands[] = $raw;
    }
    $cands[] = (string) $text;
    $st = $pdo->prepare("SELECT id, remark FROM category WHERE remark = ? LIMIT 1");
    foreach (array_unique($cands) as $c) {
        $st->execute([$c]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    $norm = function ($s) {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        return function_exists('convertPersianNumbersToEnglish') ? convertPersianNumbersToEnglish($s) : $s;
    };
    $want = array_map($norm, $cands);
    foreach ($pdo->query("SELECT id, remark FROM category ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (in_array($norm($row['remark']), $want, true)) {
            return $row;
        }
    }
    return null;
}

/** 5.6.1: keyboard currently shown ('s' = checkbox screen from state, digits = filter screen of that category). */
function virabot_cat_current_kb($which)
{
    if ($which === 's') {
        $state = virabot_cat_state_get();
        if (!$state || !isset($state['ids'], $state['sel'], $state['cat'])) {
            return null;
        }
        [, $k] = virabot_cat_render($state);
        return json_decode($k, true);
    }
    return json_decode(virabot_cat_filter_keyboard(intval($which)), true);
}

/**
 * Admin handler. Called at the top of admin.php; returns true when it handled the update.
 */
function virabot_cat_admin_handle()
{
    global $text, $datain, $user, $from_id, $message_id, $adminrulecheck, $textbotlang, $pdo, $setting, $keyboard_Category_manage;
    $vbRule = select("admin", "*", "id_admin", $from_id, "select");
    $isAdmin = is_array($vbRule) && ($vbRule['rule'] ?? '') == "administrator";
    if (!$isAdmin) {
        return false;
    }
    $text = isset($text) ? $text : '';
    $datain = isset($datain) ? $datain : '';
    $backTexts = [$textbotlang['Admin']['backAdminBtn'] ?? '', $textbotlang['Admin']['backMenuBtn'] ?? '', 'panel', '/panel', $textbotlang['Admin']['panelAdmin'] ?? ''];
    $isBack = ($text !== '' && in_array($text, $backTexts, true));

    // ---- reply buttons ----
    if ($text !== '' && $text === virabot_cat_btn_bulk()) {
        $n = intval($pdo->query("SELECT COUNT(*) FROM category")->fetchColumn());
        if ($n === 0) {
            sendmessage($from_id, virabot_cat_t('no_category', '⚠️ هنوز دسته‌ای تعریف نشده. اول از «افزودن دسته» یک دسته بسازید.'), $keyboard_Category_manage, 'HTML');
            return true;
        }
        sendmessage($from_id, virabot_cat_t('ask_bulk_cat', 'دسته‌ای را که می‌خواهید محصولاتش را انتخاب کنید، بفرستید:'), virabot_cat_reply_keyboard(), 'HTML');
        step('vbcat_bulk_pick', $from_id);
        return true;
    }
    if ($text !== '' && $text === virabot_cat_btn_other_toggle()) {
        $new = virabot_other_enabled() ? 'off' : 'on';
        try {
            $pdo->prepare("UPDATE setting SET statusothercategory = ?")->execute([$new]);
            $setting['statusothercategory'] = $new;
        } catch (Throwable $e) {
            sendmessage($from_id, virabot_cat_t('need_migration', '⚠️ ستون جدید هنوز ساخته نشده؛ یک بار به‌روزرسانی (table.php) را اجرا کنید.'), $keyboard_Category_manage, 'HTML');
            return true;
        }
        $msg = $new === 'on'
            ? virabot_cat_t('other_on', '✅ دکمه «📦 سایر» در خرید روشن شد. محصولات بدون دسته زیر آن نمایش داده می‌شوند.')
            : virabot_cat_t('other_off', '⛔️ دکمه «📦 سایر» خاموش شد. محصولات بدون دسته در مسیر خرید دسته‌ای نمایش داده نمی‌شوند.');
        sendmessage($from_id, $msg, $keyboard_Category_manage, 'HTML');
        return true;
    }
    if ($text !== '' && $text === virabot_cat_btn_defcat()) {
        $panel = select("marzban_panel", "*", "name_panel", $user['Processing_value'], "select");
        if (!$panel) {
            sendmessage($from_id, virabot_cat_t('defcat_no_panel', '⚠️ اول از «مدیریت پنل» پنل را انتخاب کنید.'), null, 'HTML');
            return true;
        }
        $cur = trim((string) ($panel['default_category'] ?? ''));
        $msg = sprintf(
            virabot_cat_t('defcat_ask', "🗂 دسته پیش‌فرض پنل «%s»\nمقدار فعلی: %s\n\nمحصول جدیدِ این پنل (ساخت دستی یا همگام‌سازی) که دسته ندارد، این دسته را می‌گیرد.\nیک دسته از لیست انتخاب کنید یا نام دسته جدید را بفرستید (اگر نباشد ساخته می‌شود)."),
            htmlspecialchars($panel['name_panel'], ENT_QUOTES, 'UTF-8'),
            $cur === '' ? virabot_cat_t('none', '— ندارد —') : htmlspecialchars($cur, ENT_QUOTES, 'UTF-8')
        );
        sendmessage($from_id, $msg, virabot_cat_reply_keyboard(virabot_cat_btn_defcat_clear()), 'HTML');
        step('vbcat_defcat', $from_id);
        return true;
    }

    // ---- steps ----
    if ($user['step'] === 'vbcat_bulk_pick' && $text !== '' && !$isBack) {
        $cat = virabot_cat_find_by_text($text);
        if (!$cat) {
            sendmessage($from_id, virabot_cat_t('cat_not_found', '❌ این دسته پیدا نشد؛ از دکمه‌ها انتخاب کنید.'), virabot_cat_reply_keyboard(), 'HTML');
            return true;
        }
        step('home', $from_id);
        sendmessage($from_id, virabot_cat_t('bulk_started', '✅ دسته انتخاب شد.'), $keyboard_Category_manage, 'HTML');
        sendmessage($from_id, sprintf(virabot_cat_t('ask_filter', "☑️ دسته «%s»\nکدام محصولات نمایش داده شوند؟"), htmlspecialchars($cat['remark'], ENT_QUOTES, 'UTF-8')), virabot_cat_filter_keyboard($cat['id']), 'HTML');
        return true;
    }
    if ($user['step'] === 'vbcat_defcat' && $text !== '' && !$isBack) {
        $panel = select("marzban_panel", "*", "name_panel", $user['Processing_value'], "select");
        if (!$panel) {
            step('home', $from_id);
            sendmessage($from_id, virabot_cat_t('defcat_no_panel', '⚠️ اول از «مدیریت پنل» پنل را انتخاب کنید.'), null, 'HTML');
            return true;
        }
        try {
            if ($text === virabot_cat_btn_defcat_clear()) {
                $pdo->prepare("UPDATE marzban_panel SET default_category = NULL WHERE id = ?")->execute([$panel['id']]);
                $msg = virabot_cat_t('defcat_cleared', '✅ دسته پیش‌فرض این پنل برداشته شد.');
            } else {
                $name = virabot_ensure_category($text);
                if ($name === '') {
                    sendmessage($from_id, virabot_cat_t('defcat_invalid', '❌ نام دسته معتبر نیست (حداکثر ۴۰۰ کاراکتر).'), null, 'HTML');
                    return true;
                }
                $pdo->prepare("UPDATE marzban_panel SET default_category = ? WHERE id = ?")->execute([$name, $panel['id']]);
                $msg = sprintf(virabot_cat_t('defcat_saved', "✅ دسته پیش‌فرض پنل روی «%s» تنظیم شد.\nمحصولات فعلی تغییر نمی‌کنند؛ برای آن‌ها از «☑️ انتخاب گروهی محصولات» استفاده کنید."), htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
            }
        } catch (Throwable $e) {
            error_log('vbcat_defcat: ' . $e->getMessage());
            $msg = virabot_cat_t('need_migration', '⚠️ ستون جدید هنوز ساخته نشده؛ یک بار به‌روزرسانی (table.php) را اجرا کنید.');
        }
        step('home', $from_id);
        if (function_exists('outtypepanel')) {
            outtypepanel($panel['type'], $msg);
        } else {
            sendmessage($from_id, $msg, null, 'HTML');
        }
        return true;
    }

    // ---- callbacks ----
    if ($datain === '' || strpos($datain, 'vbc_') !== 0) {
        return false;
    }
    // 5.6.1: «بستن/انصراف» needs a second tap (an accidental tap used to close the screen at once)
    if (preg_match('/^vbc_xq_(s|\d+)$/', $datain, $m)) {
        virabot_cat_answer(virabot_cat_t('close_confirm_hint', 'برای بستن، «✅ بله، ببند» را بزنید.'));
        $kbArr = virabot_cat_current_kb($m[1]);
        if ($kbArr === null) {
            virabot_cat_answer(virabot_cat_t('expired', '⛔️ این صفحه منقضی شده؛ دوباره از «☑️ انتخاب گروهی محصولات» شروع کنید.'), true);
            return true;
        }
        $last = count($kbArr['inline_keyboard']) - 1;
        $kbArr['inline_keyboard'][$last] = [
            ['text' => virabot_cat_t('btn_close_yes', '✅ بله، ببند'), 'callback_data' => 'vbc_x'],
            ['text' => virabot_cat_t('btn_close_no', '↩️ ادامه'), 'callback_data' => 'vbc_xn_' . $m[1]],
        ];
        telegram('editMessageReplyMarkup', ['chat_id' => $from_id, 'message_id' => $message_id, 'reply_markup' => json_encode($kbArr)]);
        return true;
    }
    if (preg_match('/^vbc_xn_(s|\d+)$/', $datain, $m)) {
        virabot_cat_answer();
        $kbArr = virabot_cat_current_kb($m[1]);
        if ($kbArr !== null) {
            telegram('editMessageReplyMarkup', ['chat_id' => $from_id, 'message_id' => $message_id, 'reply_markup' => json_encode($kbArr)]);
        }
        return true;
    }
    if ($datain === 'vbc_x') {
        virabot_cat_answer();
        Editmessagetext($from_id, $message_id, virabot_cat_t('closed', 'بسته شد؛ تغییری ذخیره نشد.'), json_encode(['inline_keyboard' => []]));
        update("user", "Processing_value", "{}", "id", $from_id);
        return true;
    }
    if (preg_match('/^vbc_f_(\d+)_(a|u|p\d+)$/', $datain, $m)) {
        $st = $pdo->prepare("SELECT id, remark FROM category WHERE id = ? LIMIT 1");
        $st->execute([intval($m[1])]);
        $cat = $st->fetch(PDO::FETCH_ASSOC);
        $ids = $cat ? virabot_cat_filter_ids($m[2]) : null;
        if (!$cat || $ids === null) {
            virabot_cat_answer(virabot_cat_t('cat_not_found', '❌ این دسته پیدا نشد؛ از دکمه‌ها انتخاب کنید.'), true);
            return true;
        }
        $sel = [];
        if ($ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $q = $pdo->prepare("SELECT id FROM product WHERE id IN ($in) AND category = ?");
                $q->execute(array_merge($chunk, [$cat['remark']]));
                $sel = array_merge($sel, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
            }
        }
        $state = ['cat' => intval($cat['id']), 'remark' => $cat['remark'], 'f' => $m[2], 'page' => 1, 'ids' => $ids, 'sel' => $sel, 'mid' => intval($message_id)];
        virabot_cat_state_put($state);
        virabot_cat_answer();
        [$t, $k] = virabot_cat_render($state);
        Editmessagetext($from_id, $message_id, $t, $k);
        return true;
    }
    $state = virabot_cat_state_get();
    if (!$state || !isset($state['ids'], $state['sel'], $state['cat'])) {
        virabot_cat_answer(virabot_cat_t('expired', '⛔️ این صفحه منقضی شده؛ دوباره از «☑️ انتخاب گروهی محصولات» شروع کنید.'), true);
        return true;
    }
    if ($datain === 'vbc_b') {
        virabot_cat_answer();
        Editmessagetext($from_id, $message_id, sprintf(virabot_cat_t('ask_filter', "☑️ دسته «%s»\nکدام محصولات نمایش داده شوند؟"), htmlspecialchars($state['remark'], ENT_QUOTES, 'UTF-8')), virabot_cat_filter_keyboard($state['cat']));
        return true;
    }
    if (preg_match('/^vbc_t_(\d+)$/', $datain, $m)) {
        $pid = intval($m[1]);
        if (!in_array($pid, $state['ids'], true)) {
            virabot_cat_answer(virabot_cat_t('not_in_filter', 'این محصول در فیلتر فعلی نیست.'));
            return true;
        }
        $k = array_search($pid, $state['sel'], true);
        if ($k === false) {
            $state['sel'][] = $pid;
            virabot_cat_answer(virabot_cat_t('checked', '☑️ انتخاب شد'));
        } else {
            unset($state['sel'][$k]);
            $state['sel'] = array_values($state['sel']);
            virabot_cat_answer(virabot_cat_t('unchecked', '⬜ برداشته شد'));
        }
    } elseif (preg_match('/^vbc_p_(\d+)$/', $datain, $m)) {
        $state['page'] = max(1, intval($m[1]));
        virabot_cat_answer();
    } elseif ($datain === 'vbc_all') {
        $state['sel'] = $state['ids'];
        virabot_cat_answer(virabot_cat_t('all_checked', '✅ همه محصولات این فیلتر انتخاب شدند'));
    } elseif ($datain === 'vbc_none') {
        $state['sel'] = [];
        virabot_cat_answer(virabot_cat_t('none_checked', '⬜ همه تیک‌ها برداشته شد'));
    } elseif ($datain === 'vbc_save') {
        $r = virabot_cat_save($state);
        if (!$r['ok']) {
            virabot_cat_answer($r['error'] === 'category_missing'
                ? virabot_cat_t('save_cat_missing', '❌ این دسته در این فاصله حذف یا تغییرنام داده شده؛ چیزی ذخیره نشد.')
                : virabot_cat_t('save_failed', '❌ ذخیره ناموفق بود؛ هیچ تغییری اعمال نشد.'), true);
            return true;
        }
        virabot_cat_answer(virabot_cat_t('saved_short', '💾 ذخیره شد'));
        update("user", "Processing_value", "{}", "id", $from_id);
        $sum = sprintf(
            virabot_cat_t('saved', "💾 <b>ذخیره شد</b> — دسته «%s» (%s)\n➕ اضافه‌شده به دسته: %d\n➖ برداشته‌شده (به «سایر» رفت): %d\n▫️ بدون تغییر: %d"),
            htmlspecialchars($state['remark'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(virabot_cat_filter_label($state['f']), ENT_QUOTES, 'UTF-8'),
            $r['added'], $r['removed'], $r['unchanged']
        );
        Editmessagetext($from_id, $message_id, $sum, json_encode(['inline_keyboard' => []]));
        return true;
    } else {
        virabot_cat_answer();
        return true;
    }
    virabot_cat_state_put($state);
    [$t, $k] = virabot_cat_render($state);
    Editmessagetext($from_id, $message_id, $t, $k);
    return true;
}

}
