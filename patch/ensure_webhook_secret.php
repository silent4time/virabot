<?php
// Virabot 5.5.0: make sure every install has a webhook secret (fail-closed auth).
// Run from CLI only:  php ensure_webhook_secret.php [--reregister]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile) || !is_writable($cfgFile)) {
    fwrite(STDERR, "config.php not found or not writable\n");
    exit(1);
}
$src = file_get_contents($cfgFile);
$current = null;
if (preg_match('/^\s*\$WEBHOOK_SECRET_TOKEN\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/m', $src, $m)) {
    $current = $m[1];
}
$invalid = ($current === null || $current === '' || strpos($current, '{') !== false || strlen($current) < 16);
$reregister = in_array('--reregister', $argv, true);
if ($invalid) {
    $secret = bin2hex(random_bytes(24));
    if ($current === null) {
        $src = rtrim($src);
        $hasClose = substr($src, -2) === '?>';
        if ($hasClose) {
            $src = substr($src, 0, -2);
        }
        $src .= "\n\$WEBHOOK_SECRET_TOKEN = '" . $secret . "';\n" . ($hasClose ? "?>\n" : '');
    } else {
        $src = preg_replace('/^(\s*\$WEBHOOK_SECRET_TOKEN\s*=\s*)[\'"][^\'"]*[\'"]\s*;/m', '${1}\'' . $secret . '\';', $src, 1);
    }
    file_put_contents($cfgFile, $src);
    echo "webhook secret generated\n";
    $reregister = true;
} else {
    $secret = $current;
    echo "webhook secret present\n";
}
if (!$reregister) {
    exit(0);
}
// Re-register webhooks so the platforms start sending the new secret.
$APIKEY = $BALE_APIKEY = $domainhosts = '';
$ENABLE_TELEGRAM = $ENABLE_BALE = false;
(function () use ($cfgFile, &$APIKEY, &$BALE_APIKEY, &$domainhosts, &$ENABLE_TELEGRAM, &$ENABLE_BALE) {
    ob_start();
    try {
        require $cfgFile;
    } catch (Throwable $e) {
    }
    ob_end_clean();
    $APIKEY = $APIKEY ?? '';
    $BALE_APIKEY = $BALE_APIKEY ?? '';
    $domainhosts = $domainhosts ?? '';
    $ENABLE_TELEGRAM = $ENABLE_TELEGRAM ?? !empty($APIKEY);
    $ENABLE_BALE = $ENABLE_BALE ?? false;
})();
$domain = trim((string) $domainhosts);
if ($domain === '' || strpos($domain, '{') !== false) {
    echo "domain unknown; re-register webhooks manually\n";
    exit(0);
}
$post = function ($url, array $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $r = curl_exec($ch);
    curl_close($ch);
    return is_string($r) ? $r : '{"ok":false}';
};
if ($ENABLE_TELEGRAM && $APIKEY !== '' && strpos($APIKEY, '{') === false) {
    $r = $post('https://api.telegram.org/bot' . $APIKEY . '/setWebhook', ['url' => 'https://' . $domain . '/index.php', 'secret_token' => $secret]);
    echo "Telegram webhook: " . (strpos($r, '"ok":true') !== false ? 'ok' : 'failed') . "\n";
}
if ($ENABLE_BALE && $BALE_APIKEY !== '' && $BALE_APIKEY !== 'disabled' && strpos($BALE_APIKEY, '{') === false) {
    // Bale does not deliver the secret_token header, so the secret also rides in the URL.
    $r = $post('https://tapi.bale.ai/bot' . $BALE_APIKEY . '/setWebhook', ['url' => 'https://' . $domain . '/index.php?platform=bale&secret=' . $secret, 'secret_token' => $secret]);
    echo "Bale webhook: " . (strpos($r, '"ok":true') !== false ? 'ok' : 'failed') . "\n";
}
