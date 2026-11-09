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

$textbotlang = [
    'Admin' => ['backAdminBtn' => '🏠 بازگشت به منوی مدیریت', 'backMenuBtn' => '▶️ بازگشت به منوی قبل', 'panelAdmin' => '👨‍💼 پنل مدیریت'],
    'users' => ['backbtn' => '🏠 بازگشت به منوی اصلی'],
    'keyboard' => ['panelFeatureStatus' => '⚙️ وضعیت قابلیت ها پنل', 'panelName' => '✍️ نام پنل', 'deletePanel' => '❌ حذف پنل', 'editPanelUrl' => '🔗 ویرایش آدرس پنل'],
];
$option_nvpanel = '{"keyboard":[[{"text":"شناسه محصول nvpanel"}],[{"text":"🏠 بازگشت به منوی مدیریت"},{"text":"▶️ بازگشت به منوی قبل"}]]}';
$keyboardadmin = '{"keyboard":[[{"text":"admin"}]]}';
$from_id = 1;
$adminrulecheck = ['rule' => 'administrator'];

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE marzban_panel (name_panel TEXT, type TEXT, status TEXT)');
$pdo->exec('CREATE TABLE product (code_product TEXT, name_product TEXT, Location TEXT, nv_product_id TEXT)');
$pdo->exec("INSERT INTO marzban_panel VALUES ('NV Panel','nvpanel','disable'),('Marz','marzban','active')");
$pdo->exec("INSERT INTO product VALUES ('c716','OpenVpn','NV Panel',NULL),('m100','Other','Marz',NULL),('dup','A','NV Panel',NULL),('dup','B','NV Panel',NULL)");

function run(string $msg, string $step): bool
{
    global $text, $user, $sent, $steps;
    $text = $msg;
    $user = ['step' => $step, 'Processing_value' => 'NV Panel'];
    $sent = [];
    $steps = [];
    return nvpanel_admin_handle();
}
function said(string $needle): bool
{
    global $sent;
    foreach ($sent as $m) {
        if (str_contains($m['text'], $needle)) {
            return true;
        }
    }
    return false;
}
function nvid(string $code): ?string
{
    global $pdo;
    $st = $pdo->prepare('SELECT nv_product_id FROM product WHERE code_product = ? LIMIT 1');
    $st->execute([$code]);
    $v = $st->fetchColumn();
    return $v === null || $v === false ? null : (string) $v;
}

// nav helper
foreach (['🏠 بازگشت به منوی مدیریت', '▶️ بازگشت به منوی قبل', 'بازگشت به منوی قبل', 'بازگشت به منوی اصلی', '🏠 بازگشت به منوی اصلی'] as $nav) {
    check(nvpanel_is_nav_text($nav), "nav text: $nav");
}
check(!nvpanel_is_nav_text('c716 19'), 'mapping line is not nav');
check(!nvpanel_is_nav_text('asdf qwer zxcv'), 'random text is not nav');
check(!nvpanel_is_nav_text(''), 'empty is not nav');
check(nvpanel_is_panel_action_text('✍️ نام پنل') && !nvpanel_is_panel_action_text('c716 19'), 'panel action labels recognized');

// opening prompt
check(run('شناسه محصول nvpanel', 'home') === true, 'id button handled');
check($steps === ['nvpanel_product_map'], 'id button sets mapping step');
check($sent[0]['kb'] === $option_nvpanel, 'prompt keeps nv panel keyboard with back buttons');
check(said('<code>c716</code>') && said('ثبت‌نشده') && said('<code>c716 19</code>'), 'prompt lists product codes and example');
check(!said('<code>m100</code>'), 'prompt does not list other panels products');

// back during mapping
foreach (['▶️ بازگشت به منوی قبل', '🏠 بازگشت به منوی مدیریت', 'بازگشت به منوی قبل'] as $nav) {
    $r = run($nav, 'nvpanel_product_map');
    check($r === true && $steps === ['home'] && !said('قالب نامعتبر'), "back cancels mapping: $nav");
}
run('▶️ بازگشت به منوی قبل', 'nvpanel_product_map');
check($sent[0]['kb'] === $option_nvpanel, 'back to previous restores nv panel menu');
run('🏠 بازگشت به منوی مدیریت', 'nvpanel_product_map');
check($sent[0]['kb'] === $keyboardadmin, 'admin home restores admin keyboard');

// another panel button falls through to admin.php
check(run('✍️ نام پنل', 'nvpanel_product_map') === false && $steps === ['home'] && $sent === [], 'panel button resets step and falls through');

// invalid / guarded mapping
check(run('garbage', 'nvpanel_product_map') === true && said('قالب نامعتبر'), 'invalid format still errors');
check(run('dup 19', 'nvpanel_product_map') === true && said('دقیقاً یک محصول') && nvid('dup') === null, 'duplicate code refused');
check(run('m100 19', 'nvpanel_product_map') === true && said('فقط محصولی') && nvid('m100') === null, 'other panel product untouched');
check(nvid('c716') === null, 'nothing written before a valid mapping');

// valid mapping
check(run('c716 19', 'nvpanel_product_map') === true && said('ذخیره شد') && $steps === ['home'], 'valid mapping saves and steps home');
check(nvid('c716') === '19' && nvid('m100') === null, 'only the matching nv panel row changed');
check($sent[0]['kb'] === $option_nvpanel, 'save restores nv panel keyboard');
check(run('c716 19', 'home') === false, 'mapping text outside the step is ignored');

// non-admin
$adminrulecheck = ['rule' => 'seller'];
check(run('شناسه محصول nvpanel', 'home') === false, 'non-administrator ignored');
$adminrulecheck = ['rule' => 'administrator'];

// source: first-open menu has an nvpanel branch before the Marzban fallback
$src = (string) file_get_contents(dirname(__DIR__) . '/patch/admin.php');
$start = strpos($src, '} elseif ($user[\'step\'] == "GetLocationEdit") {');
$seg = $start === false ? '' : substr($src, $start, 30000);
$nv = strpos($seg, '} elseif ($marzban_list_get[\'type\'] == "nvpanel") {');
$fallback = strpos($seg, '$optionMarzban, \'HTML\');' . "\n" . '    }' . "\n" . '    update("user", "Processing_value", $text');
check($nv !== false && $fallback !== false && $nv < $fallback && str_contains(substr($seg, $nv, 200), '$option_nvpanel'), 'GetLocationEdit sends nv panel menu first');

// product details show code_product
foreach (['patch/lang-fa.php', 'base/lang/fa.php', 'base/lang/en.php', 'base/lang/ru.php', 'base/lang/zh.php'] as $f) {
    $lang = (string) file_get_contents(dirname(__DIR__) . '/' . $f);
    $i = strpos($lang, "'msg_user_price_volume' => '");
    $j = $i === false ? false : strpos($lang, "',", $i + 30);
    $fmt = ($i === false || $j === false) ? '' : substr($lang, $i, $j - $i);
    check(substr_count($fmt, '%s') === 11 && str_contains($fmt, '<code>%s</code>'), "product details format has code slot: $f");
}
foreach (['patch/admin.php', 'base/admin.php'] as $f) {
    $a = (string) file_get_contents(dirname(__DIR__) . '/' . $f);
    check((bool) preg_match("/msg_user_price_volume'\\], \\\$info_product\\['name_product'\\], htmlspecialchars\\(\\(string\\) \\(\\\$info_product\\['code_product'\\]/", $a), "product details sprintf passes code_product: $f");
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
