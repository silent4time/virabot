<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/patch/nvpanel_setup.php';

$fail = 0;
$pass = 0;
function check(bool $ok, string $name): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
        echo "ok  $name\n";
        return;
    }
    $fail++;
    echo "FAIL $name\n";
}

check(nvpanel_setup_gate(1000, true, 'cli', false) === 'root', 'non-root rejected');
check(nvpanel_setup_gate(0, false, 'cli', false) === 'tty', 'non-tty rejected');
check(nvpanel_setup_gate(0, true, 'fpm-fcgi', true) === 'web', 'web invocation rejected');
check(nvpanel_setup_gate(0, true, 'cli', false) === null, 'root tty allowed');
check(!nvpanel_setup_db_ok(['dbhost' => 'localhost;evil', 'dbname' => 'db', 'usernamedb' => 'u']), 'dsn host injection rejected');
check(nvpanel_setup_db_ok(['dbhost' => '127.0.0.1', 'dbname' => 'virabot', 'usernamedb' => 'vb']), 'plain database host accepted');
check(nvpanel_setup_decide(null, '') === 'insert', 'new panel name inserts');
check(nvpanel_setup_decide(['type' => 'marzban'], 'yes') === 'foreign', 'other panel is not overwritten');
check(nvpanel_setup_decide(['type' => 'nvpanel'], 'no') === 'cancel', 'token rotation needs yes');
check(nvpanel_setup_decide(['type' => 'nvpanel'], 'yes') === 'rotate', 'confirmed rotation stays on the same panel');
check(!nvpanel_setup_dir_ok('/tmp/../etc') && !nvpanel_setup_dir_ok('/home/virabot;id') && nvpanel_setup_dir_ok('/home/virabot'), 'instance path injection rejected');
check(!nvpanel_setup_token_ok('changeme') && !nvpanel_setup_token_ok("abc def ghi") && nvpanel_setup_token_ok(str_repeat('a', 32)), 'placeholder and spaced token rejected');
$secret = 'unit-secret-value';
check(!str_contains(nvpanel_setup_public_error('database connection failed ' . $secret, $secret), $secret), 'error text hides the secret');
check(nvpanel_setup_ignored_env(['NV_PANEL_API_TOKEN' => $secret, 'PATH' => '/usr/bin']) === ['NV_PANEL_API_TOKEN'], 'env token is ignored not used');
$null = nvpanel_balance_result(200, ['ok' => true, 'balance_toman' => null]);
check($null['auth'] === true && $null['balance'] === null && $null['reason'] === 'unknown', 'null cached balance is unknown');
$unauth = nvpanel_balance_result(401, ['ok' => false, 'code' => 'unauthorized']);
check($unauth['auth'] === false && $unauth['reason'] === 'unauthorized', '401 is auth failure');
check(!nvpanel_may_map_product(['Location' => 'marzban-a'], ['type' => 'marzban', 'name_panel' => 'marzban-a']), 'other panel product blocked');
check(!nvpanel_may_map_product(['Location' => '/all'], ['type' => 'nvpanel', 'name_panel' => 'nv']), 'all-location product blocked');
check(nvpanel_may_map_product(['Location' => 'nv-one'], ['type' => 'nvpanel', 'name_panel' => 'nv-one']), 'same nvpanel product allowed');
check(!nvpanel_sales_open(['type' => 'nvpanel', 'status' => 'disable', 'url_panel' => 'https://nvpanel.silentping.site', 'password_panel' => str_repeat('b', 16)]), 'disabled panel cannot sell');
$src = file_get_contents(dirname(__DIR__) . '/patch/nvpanel_setup.php');
check(!str_contains($src, "'/create'") && !str_contains($src, "'/renew'"), 'setup source has no financial routes');
check(!preg_match('/getenv\(\s*[\'\"]NV_PANEL/', $src), 'setup does not read a token from the environment');
check(!str_contains($src, '$argv') && !str_contains($src, 'Processing_value'), 'setup ignores argv and bot temporary state');
$side = sys_get_temp_dir() . '/nvpanel-setup-side-' . bin2hex(random_bytes(4));
$cfgFile = sys_get_temp_dir() . '/nvpanel-setup-cfg-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($cfgFile, "<?php\n\$dbhost='localhost';\n\$dbname='db';\n\$usernamedb='u';\n\$passworddb='p';\nfile_put_contents(" . var_export($side, true) . ", 'ran');\n");
$parsed = nvpanel_setup_parse_config($cfgFile);
check($parsed === ['dbhost' => 'localhost', 'dbname' => 'db', 'usernamedb' => 'u', 'passworddb' => 'p'] && !is_file($side), 'config is parsed and not executed');
@unlink($cfgFile);
$seen = [];
$GLOBALS['nvpanel_transport'] = static function (string $method, string $url) use (&$seen): array {
    $seen[] = $method . ' ' . $url;
    if (str_contains($url, '/balance?cached=1')) {
        return ['status' => 200, 'body' => '{"ok":true,"balance_toman":null}', 'error' => ''];
    }
    return ['status' => 500, 'body' => '', 'error' => 'unexpected'];
};
$probe = ['type' => 'nvpanel', 'url_panel' => 'https://nvpanel.silentping.site', 'password_panel' => str_repeat('c', 16), 'status' => 'disable'];
$live = nvpanel_setup_check_balance($probe);
check(!empty($live['auth']) && $live['balance'] === null && $seen === ['GET https://nvpanel.silentping.site/balance?cached=1'], 'only cached balance is requested');
$GLOBALS['nvpanel_transport'] = static function (): array {
    return ['status' => 302, 'body' => '', 'error' => ''];
};
$redir = nvpanel_setup_check_balance($probe);
check(empty($redir['auth']), 'redirect is not followed with the token');
$GLOBALS['nvpanel_transport'] = static function (): array {
    return ['status' => 401, 'body' => '{"ok":false,"code":"unauthorized"}', 'error' => ''];
};
$denied = nvpanel_setup_check_balance($probe);
check(empty($denied['auth']) && $denied['reason'] === 'unauthorized', 'probe 401 does not count as success');
unset($GLOBALS['nvpanel_transport']);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
