<?php
/**
 * Root TTY setup for an NV Panel consumer token.
 * Not a web script. The token is read from the terminal with echo off.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
    }
    exit;
}

require_once __DIR__ . '/nvpanel.php';

const NV_SETUP_DEFAULT_URL = 'https://nvpanel.silentping.site';

function nvpanel_setup_gate(int $uid, bool $tty, string $sapi, bool $web): ?string
{
    if ($sapi !== 'cli' || $web) {
        return 'web';
    }
    if ($uid !== 0) {
        return 'root';
    }
    if (!$tty) {
        return 'tty';
    }
    return null;
}

function nvpanel_setup_name_ok(string $name): bool
{
    if ($name === '' || strlen($name) > 64) {
        return false;
    }
    return (bool) preg_match('/^[\p{L}\p{N} _.-]+$/u', $name);
}

function nvpanel_setup_dir_ok(string $dir): bool
{
    if (!preg_match('#^/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$#', $dir)) {
        return false;
    }
    return !str_contains($dir, '..');
}

function nvpanel_setup_db_ok(array $cfg): bool
{
    $host = (string) ($cfg['dbhost'] ?? '');
    $name = (string) ($cfg['dbname'] ?? '');
    $user = (string) ($cfg['usernamedb'] ?? '');
    if (strpbrk($host, " \t\r\n;\"'") !== false || strlen($host) > 253) {
        return false;
    }
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)
        && (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $user);
}

/** @return 'insert'|'rotate'|'foreign'|'cancel' */
function nvpanel_setup_decide(?array $existing, string $answer): string
{
    if ($existing === null) {
        return 'insert';
    }
    if (($existing['type'] ?? '') !== 'nvpanel') {
        return 'foreign';
    }
    return $answer === 'yes' ? 'rotate' : 'cancel';
}

function nvpanel_setup_token_ok(string $token): bool
{
    if (strlen($token) < 8 || strlen($token) > 200 || preg_match('/\s/', $token)) {
        return false;
    }
    return !preg_match('/^(example|changeme|token|password|secret|YOUR_TOKEN)$/i', $token);
}

function nvpanel_setup_ignored_env(array $env): array
{
    $ignored = [];
    foreach (['NV_PANEL_API_TOKEN', 'NVPANEL_TOKEN', 'GITHUB_TOKEN', 'GH_TOKEN'] as $key) {
        if (isset($env[$key]) && $env[$key] !== '') {
            $ignored[] = $key;
        }
    }
    return $ignored;
}

function nvpanel_balance_result(int $status, array $body): array
{
    $code = (string) ($body['code'] ?? '');
    if ($status === 401 || $code === 'unauthorized') {
        return ['ok' => false, 'auth' => false, 'balance' => null, 'reason' => 'unauthorized'];
    }
    if ($status === 200 && !empty($body['ok'])) {
        $bal = array_key_exists('balance_toman', $body) ? $body['balance_toman'] : null;
        if ($bal === null || $bal === '') {
            return ['ok' => true, 'auth' => true, 'balance' => null, 'reason' => 'unknown'];
        }
        return ['ok' => true, 'auth' => true, 'balance' => (int) $bal, 'reason' => 'known'];
    }
    return ['ok' => false, 'auth' => false, 'balance' => null, 'reason' => 'failed'];
}

function nvpanel_setup_public_error(string $message, string $secret): string
{
    if ($secret !== '' && str_contains($message, $secret)) {
        $message = str_replace($secret, '[hidden]', $message);
    }
    return $message;
}

function nvpanel_setup_parse_config(string $file): array
{
    $code = file_get_contents($file);
    if ($code === false) {
        return [];
    }
    $tokens = token_get_all($code);
    $wanted = ['dbhost' => true, 'dbname' => true, 'usernamedb' => true, 'passworddb' => true];
    $out = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
            continue;
        }
        $name = ltrim($tokens[$i][1], '$');
        if (!isset($wanted[$name])) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || $tokens[$j] !== '=') {
            continue;
        }
        $j++;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return [];
        }
        $out[$name] = stripcslashes(substr($tokens[$j][1], 1, -1));
    }
    foreach (array_keys($wanted) as $key) {
        if (!isset($out[$key]) || $out[$key] === '') {
            return [];
        }
    }
    return $out;
}

function nvpanel_setup_instance_dirs(string $instancesDir): array
{
    $dirs = [];
    foreach (glob($instancesDir . '/*.env') ?: [] as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $line) {
            if (!preg_match('/^INSTALL_DIR="?([^"\s]+)"?$/', $line, $m)) {
                continue;
            }
            if (nvpanel_setup_dir_ok($m[1])) {
                $dirs[] = $m[1];
            }
        }
    }
    return array_values(array_unique($dirs));
}

function nvpanel_tty_restore(): void
{
    if (DIRECTORY_SEPARATOR === '/' && is_readable('/dev/tty')) {
        @exec('stty echo < /dev/tty >/dev/null 2>&1');
    }
}

function nvpanel_read_hidden(): ?string
{
    $fh = fopen('/dev/tty', 'rb');
    if ($fh === false) {
        return null;
    }
    @exec('stty -echo < /dev/tty >/dev/null 2>&1');
    try {
        $line = fgets($fh);
        if ($line === false) {
            return null;
        }
        return rtrim($line, "\r\n");
    } finally {
        nvpanel_tty_restore();
        fclose($fh);
    }
}

function nvpanel_setup_check_balance(array $panel): array
{
    $res = nvpanel_request($panel, 'GET', '/balance?cached=1', null, 20);
    if ((int) $res['status'] >= 300 && (int) $res['status'] < 400) {
        return nvpanel_balance_result(0, ['code' => 'redirect']);
    }
    return nvpanel_balance_result((int) $res['status'], $res['json']);
}

function nvpanel_setup_main(): int
{
    $gate = nvpanel_setup_gate(
        function_exists('posix_geteuid') ? (int) posix_geteuid() : 1,
        function_exists('posix_isatty') && posix_isatty(STDIN) && posix_isatty(STDOUT),
        PHP_SAPI,
        isset($_SERVER['REQUEST_METHOD'])
    );
    if ($gate !== null) {
        fwrite(STDERR, "nvpanel-setup needs root on a real terminal.\n");
        return 1;
    }
    nvpanel_setup_ignored_env(getenv() ?: []);
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function (): void {
            nvpanel_tty_restore();
            exit(130);
        });
        pcntl_signal(SIGTERM, static function (): void {
            nvpanel_tty_restore();
            exit(143);
        });
    }
    register_shutdown_function('nvpanel_tty_restore');
    fwrite(STDOUT, "NV Panel setup. The token is not shown and is not saved until the cached balance read is authenticated.\n");
    $dirs = nvpanel_setup_instance_dirs('/etc/virabot/instances');
    if ($dirs === []) {
        fwrite(STDERR, "No registered install was found.\n");
        return 1;
    }
    foreach ($dirs as $i => $dir) {
        fwrite(STDOUT, ($i + 1) . ") " . $dir . "\n");
    }
    $choice = rtrim((string) fgets(STDIN), "\r\n");
    if (!ctype_digit($choice) || (int) $choice < 1 || (int) $choice > count($dirs)) {
        fwrite(STDERR, "Invalid instance.\n");
        return 1;
    }
    $install = $dirs[(int) $choice - 1];
    $cfg = nvpanel_setup_parse_config($install . '/config.php');
    if ($cfg === [] || !nvpanel_setup_db_ok($cfg)) {
        fwrite(STDERR, "Database settings could not be read.\n");
        return 1;
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . $cfg['dbhost'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
            $cfg['usernamedb'],
            $cfg['passworddb'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        fwrite(STDERR, nvpanel_setup_public_error('database connection failed', $cfg['passworddb']) . "\n");
        return 1;
    }
    unset($cfg['passworddb']);
    fwrite(STDOUT, "Panel name: ");
    $name = rtrim((string) fgets(STDIN), "\r\n");
    if (!nvpanel_setup_name_ok($name)) {
        fwrite(STDERR, "Invalid panel name.\n");
        return 1;
    }
    $st = $pdo->prepare('SELECT type, status FROM marzban_panel WHERE name_panel = ? LIMIT 1');
    $st->execute([$name]);
    $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $answer = '';
    if ($existing && ($existing['type'] ?? '') === 'nvpanel') {
        fwrite(STDOUT, "This NV Panel already exists. Type yes to replace only its token and address: ");
        $answer = rtrim((string) fgets(STDIN), "\r\n");
    }
    $decision = nvpanel_setup_decide($existing, $answer);
    if ($decision === 'foreign') {
        fwrite(STDERR, "That name belongs to another panel and was not changed.\n");
        return 1;
    }
    if ($decision === 'cancel') {
        fwrite(STDERR, "Cancelled. Nothing was written.\n");
        return 1;
    }
    $rotate = $decision === 'rotate';
    fwrite(STDOUT, 'Base URL [' . NV_SETUP_DEFAULT_URL . ']: ');
    $url = rtrim((string) fgets(STDIN), "\r\n");
    if ($url === '') {
        $url = NV_SETUP_DEFAULT_URL;
    }
    if (!nvpanel_base_ok($url)) {
        fwrite(STDERR, "The address must be HTTPS, without a path, user, or IP address.\n");
        return 1;
    }
    $origin = nvpanel_origin($url);
    fwrite(STDOUT, "Consumer token (hidden): ");
    $token = nvpanel_read_hidden();
    fwrite(STDOUT, "\n");
    if ($token === null) {
        fwrite(STDERR, "No token was read. Nothing was written.\n");
        return 1;
    }
    if (!nvpanel_setup_token_ok($token)) {
        fwrite(STDERR, "The token was rejected. Nothing was written.\n");
        return 1;
    }
    $probe = ['type' => 'nvpanel', 'url_panel' => $origin, 'password_panel' => $token, 'status' => 'disable'];
    $checked = nvpanel_setup_check_balance($probe);
    if (empty($checked['auth'])) {
        fwrite(STDERR, "The cached balance read was not authenticated. Nothing was written.\n");
        return 1;
    }
    if ($checked['reason'] === 'unknown') {
        fwrite(STDOUT, "Authenticated. Cached balance is unknown, not zero.\n");
    } else {
        fwrite(STDOUT, "Authenticated. Cached balance was read.\n");
    }
    $pdo->beginTransaction();
    try {
        if ($rotate) {
            $upd = $pdo->prepare("UPDATE marzban_panel SET url_panel = ?, linksubx = ?, password_panel = ? WHERE name_panel = ? AND type = 'nvpanel'");
            $upd->execute([$origin, $origin, $token, $name]);
        } else {
            $code = bin2hex(random_bytes(2));
            $price = '{"f":"4000","n":"4000","n2":"4000"}';
            $span = '{"f":"1","n":"1","n2":"1"}';
            $spanMax = '{"f":"1000","n":"1000","n2":"1000"}';
            $custom = '{"f":"0","n":"0","n2":"0"}';
            $ins = $pdo->prepare("INSERT INTO marzban_panel (code_panel,name_panel,sublink,config,MethodUsername,TestAccount,status,limit_panel,namecustom,Methodextend,type,conecton,inboundid,agent,inbound_deactive,inboundstatus,url_panel,username_panel,password_panel,time_usertest,val_usertest,linksubx,priceextravolume,priceextratime,pricecustomvolume,pricecustomtime,mainvolume,maxvolume,maintime,maxtime,status_extend,subvip,changeloc,customvolume,on_hold_test,version_panel) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $code, $name, 'onsublink', 'offconfig', 'numeric', 'OFFTestAccount', 'disable', '0', 'none',
                'none', 'nvpanel', 'offconecton', '0', 'all', '0', 'offinbounddisable', $origin, '',
                $token, '1', '100', $origin, $price, $price, $price, $price, $span, $spanMax, $span, $spanMax,
                'off_extend', 'offsubvip', 'offchangeloc', $custom, '1', '0',
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, nvpanel_setup_public_error('The panel was not saved.', $token) . "\n");
        return 1;
    }
    fwrite(STDOUT, $rotate
        ? "Token replaced. Product rows were not changed. Status was left as it was.\n"
        : "Panel saved disabled. It cannot sell, renew, or be reconciled until you enable it later.\n");
    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(nvpanel_setup_main());
}
