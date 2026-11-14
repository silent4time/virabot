<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/patch/nvpanel.php';

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

$sent = [];
$steps = [];
function sendmessage($chat, $msg, $kb = null, $mode = null)
{
    global $sent;
    $sent[] = ['text' => (string) $msg, 'kb' => $kb];
}
function step($s, $id)
{
    global $steps;
    $steps[] = $s;
}
function last(): array
{
    global $sent;
    return $sent ? $sent[count($sent) - 1] : ['text' => '', 'kb' => null];
}
function cbdata(?string $kb): array
{
    $d = json_decode((string) $kb, true);
    $out = [];
    foreach ((array) ($d['inline_keyboard'] ?? []) as $row) {
        foreach ($row as $b) {
            $out[] = (string) ($b['callback_data'] ?? '');
        }
    }
    return $out;
}

// Real v1.3.6 response shape (captured live, trimmed).
$ok = ['ok' => true, 'available' => true, 'code' => 'ok', 'empty' => false, 'products' => [
    ['id' => '11', 'name' => '1 ماهه آی پی غیر ثابت', 'duration' => ['value' => 1, 'unit' => 'ماه', 'raw' => '1 ماهه', 'source' => 'title'],
        'wholesale' => ['value' => 200000, 'unit' => 'تومان', 'raw' => '200,000', 'source' => 'span.fs-2x'], 'retail' => null],
    ['id' => '19', 'name' => '1 ماهه mr', 'duration' => ['value' => 1, 'unit' => 'ماه', 'raw' => '1 ماهه', 'source' => 'title'],
        'wholesale' => ['value' => 180000, 'unit' => 'تومان', 'raw' => '180,000', 'source' => 'span.fs-2x'], 'retail' => null],
    ['id' => 'bad id!', 'name' => 'x', 'wholesale' => ['value' => 1]],
], 'cache' => ['stale' => false]];

$calls = [];
$reply = ['status' => 200, 'body' => json_encode($ok, JSON_UNESCAPED_UNICODE), 'error' => ''];
$GLOBALS['nvpanel_transport'] = function ($method, $url, $headers, $body, $timeout, $hosts) {
    global $calls, $reply;
    $calls[] = ['method' => $method, 'url' => $url, 'auth' => $headers['Authorization'] ?? '', 'body' => $body];
    return $reply;
};

$panel = ['name_panel' => 'NV Panel', 'type' => 'nvpanel', 'status' => 'disable', 'code_panel' => 'a766',
    'url_panel' => 'https://gw.test.invalid', 'password_panel' => 'tok_abcdefgh123'];

// --- nvpanel_catalog
$c = nvpanel_catalog($panel);
check($c['ok'] && count($c['products']) === 2, 'catalog parses 2 valid products, drops bad id');
check($calls[0]['method'] === 'GET' && $calls[0]['url'] === 'https://gw.test.invalid/products?category=4', 'GET /products?category=4 on panel origin');
check($calls[0]['body'] === '', 'no request body');
check($c['products'][1]['id'] === '19' && $c['products'][1]['wholesale'] === 180000 && $c['products'][1]['duration'] === '1 ماهه', 'id/wholesale/duration');
check(nvpanel_catalog(['type' => 'nvpanel', 'url_panel' => '', 'password_panel' => ''] + $panel)['code'] === 'not_configured', 'not configured: no call');
$n = count($calls);
check(count($calls) === $n, 'not configured made no HTTP call');

$reply = ['status' => 0, 'body' => '', 'error' => 'timeout'];
$c = nvpanel_catalog($panel);
check(!$c['ok'] && $c['code'] === 'timeout' && str_contains($c['msg'], 'پاسخ نداد'), 'timeout handled');
$reply = ['status' => 401, 'body' => '{"ok":false,"code":"unauthorized"}', 'error' => ''];
check(nvpanel_catalog($panel)['code'] === 'unauthorized', '401');
$reply = ['status' => 404, 'body' => '{"detail":"Not Found"}', 'error' => ''];
check(nvpanel_catalog($panel)['code'] === 'unsupported', 'old gateway 404');
$reply = ['status' => 200, 'body' => '{"ok":true,"available":false,"code":"catalog_session_required","products":[]}', 'error' => ''];
$c = nvpanel_catalog($panel);
check(!$c['ok'] && str_contains($c['msg'], 'نشست'), 'session required');
$reply = ['status' => 200, 'body' => '{"ok":true,"available":false,"code":"catalog_unparseable","products":[]}', 'error' => ''];
check(nvpanel_catalog($panel)['code'] === 'catalog_unparseable', 'unparseable is an error, not empty');
$reply = ['status' => 200, 'body' => '{"ok":true,"available":true,"code":"catalog_empty","empty":true,"products":[]}', 'error' => ''];
$c = nvpanel_catalog($panel);
check($c['ok'] && $c['products'] === [], 'empty catalog ok');
$reply = ['status' => 200, 'body' => 'not json', 'error' => ''];
check(!nvpanel_catalog($panel)['ok'], 'garbage body');
$st = $ok;
$st['cache'] = ['stale' => true];
$reply = ['status' => 200, 'body' => json_encode($st), 'error' => ''];
check(nvpanel_catalog($panel)['stale'] === true, 'stale flag');

// --- price warning
check(nvpanel_price_warning('400000', 180000) === '', 'no warning above wholesale');
check(nvpanel_price_warning('180000', 180000) === '', 'no warning equal');
check(str_contains(nvpanel_price_warning('150000', 180000), '30,000'), 'warning shows loss');
check(nvpanel_price_warning('150000', null) === '', 'unknown wholesale: no warning');

// --- admin screens
$reply = ['status' => 200, 'body' => json_encode($ok, JSON_UNESCAPED_UNICODE), 'error' => ''];
$textbotlang = ['Admin' => ['backAdminBtn' => '🏠 بازگشت به منوی مدیریت', 'backMenuBtn' => '▶️ بازگشت به منوی قبل'], 'users' => ['backbtn' => 'x'], 'keyboard' => []];
$option_nvpanel = '{"keyboard":[[{"text":"📋 محصولات درگاه nvpanel"}]]}';
$keyboardadmin = '{"keyboard":[[{"text":"admin"}]]}';
$from_id = 1;
$adminrulecheck = ['rule' => 'administrator'];
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE marzban_panel (name_panel TEXT, type TEXT, status TEXT, code_panel TEXT, url_panel TEXT, password_panel TEXT)');
$pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY, code_product TEXT, name_product TEXT, price_product TEXT, Location TEXT, nv_product_id TEXT)');
$pdo->exec("INSERT INTO marzban_panel VALUES ('NV Panel','nvpanel','disable','a766','https://gw.test.invalid','tok_abcdefgh123'),('Marz','marzban','active','m1','https://m.invalid','x')");
$pdo->exec("INSERT INTO product VALUES (74,'c716','OpenVpn','400000','NV Panel','19'),(75,'c800','Cheap','150000','NV Panel',NULL),(80,'m100','Other','100','Marz',NULL)");

function run(string $msg, string $data = '', string $step = 'home'): bool
{
    global $text, $datain, $user, $sent, $steps;
    $text = $msg;
    $datain = $data;
    $user = ['step' => $step, 'Processing_value' => 'NV Panel'];
    $sent = [];
    $steps = [];
    return nvpanel_admin_handle();
}
function nvid(int $id): ?string
{
    global $pdo;
    $v = $pdo->query('SELECT nv_product_id FROM product WHERE id = ' . $id)->fetchColumn();
    return $v === null || $v === false ? null : (string) $v;
}

check(run(NV_CATALOG_BTN), 'catalog button handled');
$m = last();
check(str_contains($m['text'], '180,000 تومان') && str_contains($m['text'], '200,000 تومان'), 'screen lists wholesale prices');
check(str_contains($m['text'], 'شناسه <code>19</code>') && str_contains($m['text'], '1 ماهه mr'), 'screen lists id and title');
check(str_contains($m['text'], 'وصل به <code>c716</code>'), 'screen shows current mapping');
check(in_array('nvcat_p_19', cbdata($m['kb']), true) && in_array('nvcat_p_11', cbdata($m['kb']), true), 'pick buttons');
check(!str_contains($m['text'], 'tok_abcdefgh123'), 'token not shown');
foreach (cbdata($m['kb']) as $cb) {
    check(strlen($cb) <= 64, "callback <= 64 bytes: $cb");
}

check(run('', 'nvcat_p_19'), 'pick product');
$m = last();
$cbs = cbdata($m['kb']);
check(in_array('nvcat_m_19_74', $cbs, true) && in_array('nvcat_m_19_75', $cbs, true), 'bot products of this panel offered');
check(!in_array('nvcat_m_19_80', $cbs, true), 'other panel product not offered');
check(str_contains($m['text'], 'زیان'), 'pick screen warns for cheap product');

check(run('', 'nvcat_m_19_75'), 'map');
check(nvid(75) === '19', 'mapped c800 -> 19');
check(str_contains(last()['text'], 'زیان') && str_contains(last()['text'], '30,000'), 'map warns price < wholesale');
check(run('', 'nvcat_m_11_74') && nvid(74) === '11' && str_contains(last()['text'], 'شناسهٔ قبلی'), 'remap shows old id, no warning');
check(!str_contains(last()['text'], 'زیان'), 'no warning when price >= wholesale');
run('', 'nvcat_m_19_74');
check(nvid(74) === '19', 'restore');

check(run('', 'nvcat_m_19_80') && nvid(80) === null, 'cannot map other panel product via callback');
check(run('', 'nvcat_m_99_74') && nvid(74) === '19' && str_contains(last()['text'], 'در فهرست درگاه نیست'), 'unknown gateway id refused');
check(!run('', 'nvcat_m_19_74; DROP'), 'malformed callback ignored');

$reply = ['status' => 0, 'body' => '', 'error' => 'timeout'];
check(run('', 'nvcat_m_11_74') && nvid(74) === '19', 'gateway down: nothing saved');
check(str_contains(last()['text'], 'چیزی ذخیره نشد'), 'gateway down message');
check(run(NV_CATALOG_BTN) && str_contains(last()['text'], 'نگاشت دستی'), 'error screen points to manual mapping');
check(in_array('nvcat_r', cbdata(last()['kb']), true), 'retry button');

// manual mapping still works, with and without gateway
check(run('c800 11', '', 'nvpanel_product_map') && nvid(75) === '11', 'manual mapping works while gateway down');
check(str_contains(last()['text'], 'ذخیره شد'), 'manual saved message');
$reply = ['status' => 200, 'body' => json_encode($ok, JSON_UNESCAPED_UNICODE), 'error' => ''];
check(run('c800 19', '', 'nvpanel_product_map') && nvid(75) === '19' && str_contains(last()['text'], 'زیان'), 'manual mapping warns with catalog');
check(run('c716 77', '', 'nvpanel_product_map') && nvid(74) === '77' && str_contains(last()['text'], 'در فهرست فعلی درگاه نیست'), 'manual mapping notes unknown id');

// non-admin
$adminrulecheck = ['rule' => 'support'];
check(!run(NV_CATALOG_BTN) && !run('', 'nvcat_r'), 'non-administrator ignored');

// no money paths touched
$paths = array_unique(array_map(fn ($c) => $c['method'] . ' ' . parse_url($c['url'], PHP_URL_PATH), $calls));
check($paths === ['GET /products'], 'only GET /products was called: ' . implode(',', $paths));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
