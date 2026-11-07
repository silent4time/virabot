<?php
/**
 * تنها ورودی وب سرور به‌روزرسانی. ریشهٔ وب باید همین پوشه باشد، نه مخزن ربات.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ServerApp.php';

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    $configFile = dirname(__DIR__) . '/config.example.php';
}
$config = require $configFile;
if (!is_array($config)) {
    $config = ['enabled' => false, 'require_https' => true];
}

$storage = dirname(__DIR__) . '/storage';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$headers = [];
if (function_exists('getallheaders')) {
    foreach (getallheaders() ?: [] as $name => $value) {
        $headers[(string) $name] = (string) $value;
    }
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = (string) $_SERVER['HTTP_AUTHORIZATION'];
}
$query = [];
foreach ($_GET as $k => $v) {
    if (is_string($k) && (is_string($v) || is_numeric($v))) {
        $query[$k] = (string) $v;
    }
}

try {
    $app = new VbUpdateServerApp($config, $storage, time());
    $res = $app->handle($method, $path, $headers, $query, $https, $ip);
} catch (Throwable $e) {
    error_log('virabot update server failed');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"ok":false,"error":"server"}';
    exit;
}

if (isset($res['file'])) {
    $root = realpath($storage);
    $file = realpath((string) $res['file']);
    if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false,"error":"integrity"}';
        exit;
    }
    http_response_code(200);
    foreach ($res['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
    exit;
}

http_response_code((int) $res['status']);
foreach ($res['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $res['body'];
