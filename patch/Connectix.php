<?php
/**
 * Connectix seller API — SilentSell
 *
 * Live base (official spelling):
 *   https://seller-api.connectix.vip/externel/v1
 *
 * Auth: Authorization: Bearer <API_TOKEN>   (password_panel)
 *
 * Public seller endpoints (Swagger /profile/api-token):
 *   GET  /clients?page=&recordPerPage=&username=&name=
 *   GET  /clients/show?id=<client uuid>
 *   POST /clients/store
 *   GET  /clients/get-enabled-plans
 *   POST /clients/add-plan
 *
 * Panel row:
 *   type           = connectix
 *   url_panel      = https://seller-api.connectix.vip/externel/v1
 *   password_panel = API token
 *
 * Product after sync:
 *   note     = plan UUID
 *   category = کانکتیکس
 *   Location = name_panel
 */

if (!function_exists('connectix_request')) {

function connectix_default_base()
{
    return 'https://seller-api.connectix.vip/externel/v1';
}

/**
 * Normalize any URL the admin typed into the live /externel/v1 base.
 * Fixes the old bot bug that appended /external/v1 onto /externel/v1.
 */
function connectix_normalize_base($url)
{
    $base = trim((string) $url);
    $base = rtrim($base, '/');
    if ($base === '' || strcasecmp($base, 'null') === 0) {
        return connectix_default_base();
    }
    $base = preg_replace('#^http://#i', 'https://', $base);

    // strip accidental doubled suffixes
    $base = preg_replace('#/(externel|external)/v\d+(?:/(externel|external)/v\d+)+$#i', '/externel/v1', $base);

    if (preg_match('#/(externel|external)/v\d+$#i', $base)) {
        return preg_replace('#/(externel|external)/v\d+$#i', '/externel/v1', $base);
    }

    $host = strtolower((string) parse_url($base, PHP_URL_HOST));
    if ($host === 'seller-api.connectix.vip' || $host === 'api.connectix.vip' || $host === 'seller.connectix.vip') {
        return connectix_default_base();
    }

    return $base . '/externel/v1';
}

function connectix_get_panel($namepanel)
{
    if (is_array($namepanel)) {
        if (($namepanel['type'] ?? '') !== 'connectix') {
            return null;
        }
        return $namepanel;
    }
    $panel = select('marzban_panel', '*', 'name_panel', $namepanel, 'select');
    if (!is_array($panel) || ($panel['type'] ?? '') !== 'connectix') {
        return null;
    }
    return $panel;
}

function connectix_request($method, $endpoint, $panel, $body = null, $multipart = false)
{
    $base = connectix_normalize_base($panel['url_panel'] ?? '');
    $endpoint = '/' . ltrim((string) $endpoint, '/');
    $url = $base . $endpoint;
    $token = trim((string) ($panel['password_panel'] ?? ''));
    if ($token === '' || strcasecmp($token, 'null') === 0) {
        return ['http_code' => 0, 'error' => 'missing API token', 'body' => null, 'url' => $url];
    }

    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($body !== null) {
        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
    }
    $out = [
        'http_code' => $code,
        'body' => $decoded,
        'raw' => is_string($raw) ? mb_substr($raw, 0, 4000) : '',
        'url' => $url,
    ];
    if ($cerr) {
        $out['error'] = $cerr;
    }
    if (is_array($decoded)) {
        if (isset($decoded['message'])) {
            $out['message'] = $decoded['message'];
        }
        foreach ($decoded as $k => $v) {
            if (!isset($out[$k])) {
                $out[$k] = $v;
            }
        }
    }
    return $out;
}

function connectix_http_ok($result)
{
    $c = (int) ($result['http_code'] ?? 0);
    return $c >= 200 && $c < 300 && empty($result['error']);
}

function connectix_pick($arr, $keys, $default = '')
{
    if (!is_array($arr)) {
        return $default;
    }
    foreach ((array) $keys as $k) {
        if (strpos($k, '.') !== false) {
            $cur = $arr;
            $ok = true;
            foreach (explode('.', $k) as $part) {
                if (!is_array($cur) || !isset($cur[$part])) {
                    $ok = false;
                    break;
                }
                $cur = $cur[$part];
            }
            if ($ok && $cur !== '' && $cur !== null && !is_array($cur)) {
                return $cur;
            }
            continue;
        }
        if (isset($arr[$k]) && $arr[$k] !== '' && $arr[$k] !== null && !is_array($arr[$k])) {
            return $arr[$k];
        }
    }
    return $default;
}

function connectix_plan_group($plan)
{
    $group = trim((string) connectix_pick($plan, [
        'group_name',
        'group',
        'group_title',
        'plan_group',
        'groupName',
        'data.group',
        'plan.group',
        'attributes.group',
        'group.title',
        'group.name',
    ], ''));
    if ($group !== '' && strcasecmp($group, 'default') === 0) {
        return 'Super special';
    }
    if ($group !== '') {
        return $group;
    }
    $title = trim((string) connectix_pick($plan, ['title', 'name', 'full_title', 'full_period_text', 'label'], ''));
    return connectix_group_label_from_text($title);
}

function connectix_group_label_from_text($text)
{
    $t = (string) $text;
    if (preg_match('/iran\s*access/iu', $t)) {
        return 'Iran Access';
    }
    if (preg_match('/economic/iu', $t)) {
        return 'Economic';
    }
    if (preg_match('/super\s*special/iu', $t)) {
        return 'Super special';
    }
    if (preg_match('/\bdefault\b|\bspecial\b/iu', $t)) {
        return 'Super special';
    }
    if (preg_match('/^\s*\(\s*\d+\s*x\s*\)/iu', $t) && strpos($t, '+') === false) {
        return 'Super special';
    }
    $g = trim($t);
    if ($g === '' || strcasecmp($g, 'default') === 0) {
        return 'Super special';
    }
    return $g;
}

/** Shop name from GROUP NAME (default → Super special) + traffic GB */
function connectix_plan_title($plan)
{
    $group = connectix_plan_group($plan);
    $traffic = connectix_pick($plan, ['traffic_amount', 'traffic', 'volume', 'traffic_gb'], '');
    $vol = '';
    if (is_numeric($traffic)) {
        $vol = (string) (0 + $traffic);
    } elseif (is_string($traffic) && preg_match('/(\d+(?:\.\d+)?)/', $traffic, $vm)) {
        $vol = $vm[1];
    }
    if ($group !== '') {
        return $vol !== '' ? ($group . ' ' . $vol . 'G') : $group;
    }
    $title = trim((string) connectix_pick($plan, [
        'title', 'name', 'plan_title', 'planTitle', 'full_title',
        'display_name', 'label', 'data.title', 'plan.title', 'attributes.title',
    ], ''));
    if ($title !== '') {
        return $title;
    }
    return $vol !== '' ? ($vol . 'G') : 'Connectix Plan';
}

function connectix_is_free_test_plan($title, $plan = [])
{
    $blob = mb_strtolower((string) $title);
    if (is_array($plan)) {
        foreach (['title', 'name', 'plan_title', 'full_period_text', 'label'] as $k) {
            if (!empty($plan[$k])) {
                $blob .= ' ' . mb_strtolower((string) $plan[$k]);
            }
        }
    }
    if (preg_match('/\bfree\b|آزمایش|تست رایگان/u', $blob)) {
        return true;
    }
    $days = 0;
    if (is_array($plan)) {
        $days = intval($plan['full_period_days'] ?? $plan['period'] ?? $plan['days'] ?? $plan['Service_time'] ?? 0);
    }
    return $days === 1;
}

/** plan_id used by دکمه اکانت تست */
function connectix_test_plan_id($namepanel)
{
    $panel = connectix_get_panel($namepanel);
    if (!$panel) {
        return '';
    }
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT note FROM product WHERE Location = :loc AND one_buy_status = 'test' AND note IS NOT NULL AND note != '' ORDER BY name_product ASC LIMIT 1");
        $st->execute([':loc' => $panel['name_panel']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['note'])) {
            return (string) $row['note'];
        }
    } catch (Throwable $e) {
    }
    $stored = trim((string) ($panel['inboundid'] ?? ''));
    if ($stored !== '' && strcasecmp($stored, 'null') !== 0 && !preg_match('/^[0-9]+$/', $stored) && strlen($stored) >= 8) {
        return $stored;
    }
    return '';
}

function connectix_list_test_products($namepanel = null)
{
    global $pdo;
    $loc = '';
    if ($namepanel) {
        $panel = connectix_get_panel($namepanel);
        if ($panel) {
            $loc = (string) $panel['name_panel'];
        } elseif (is_string($namepanel)) {
            $loc = $namepanel;
        }
    }
    try {
        $sql = "SELECT * FROM product WHERE (
            one_buy_status = 'test'
            OR CAST(Service_time AS UNSIGNED) = 1
            OR name_product LIKE '%free%'
            OR name_product LIKE '%Free%'
            OR category = 'تست'
        )";
        $params = [];
        if ($loc !== '') {
            $sql .= " AND Location = :loc";
            $params[':loc'] = $loc;
        } else {
            $sql .= " AND Location IN (SELECT name_panel FROM marzban_panel WHERE type = 'connectix')";
        }
        $sql .= " ORDER BY name_product ASC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('connectix_list_test_products: ' . $e->getMessage());
        return [];
    }
}

function connectix_gen_password($len = 8)
{
    $len = max(5, min(8, (int) $len));
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function connectix_parse_credentials($text_to_copy)
{
    $text = str_replace('`', '', (string) $text_to_copy);
    $user = '';
    $pass = '';
    if (preg_match('/username\s*:\s*([^\s\n]+)/iu', $text, $m)) {
        $user = trim($m[1]);
    }
    if (preg_match('/password\s*:\s*([^\s\n]+)/iu', $text, $m)) {
        $pass = trim($m[1]);
    }
    $sub = connectix_pick_subscription($text);
    return ['username' => $user, 'password' => $pass, 'raw' => $text_to_copy, 'subscription_url' => $sub];
}

function connectix_is_real_sub_url($url)
{
    $u = trim((string) $url);
    if (!preg_match('#^https?://#i', $u)) {
        return false;
    }
    if (preg_match('#seller-api\.connectix\.vip|/clients/|/externel/|/external/#i', $u)) {
        return false;
    }
    return true;
}

function connectix_pick_subscription($source)
{
    $found = [];
    $walk = function ($node) use (&$walk, &$found) {
        if (is_string($node)) {
            if (preg_match_all('#https?://[^\s<>"\']+#i', $node, $mm)) {
                foreach ($mm[0] as $u) {
                    $found[] = trim($u, " \t\n\r.,;");
                }
            }
            return;
        }
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $k => $v) {
            if (in_array($k, ['url', 'request_url', 'endpoint'], true) && is_string($v) && preg_match('#/clients/#', $v)) {
                continue;
            }
            $walk($v);
        }
    };
    $walk($source);
    $best = '';
    $bestScore = -1;
    foreach ($found as $u) {
        if (!connectix_is_real_sub_url($u)) {
            continue;
        }
        $score = 0;
        if (preg_match('#/subscription/|sub\.|irancdn|happ|clash#i', $u)) {
            $score += 10;
        }
        if (strpos($u, '#') !== false) {
            $score += 2;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $u;
        }
    }
    return $best;
}

function connectix_client_uuid($source)
{
    $ids = [];
    $walk = function ($node, $key = '') use (&$walk, &$ids) {
        if (is_string($node) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $node)) {
            $ids[] = ['key' => (string) $key, 'id' => $node];
            return;
        }
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $k => $v) {
            $walk($v, is_string($k) ? $k : $key);
        }
    };
    $walk($source);
    foreach ($ids as $row) {
        if (preg_match('/plan/i', $row['key'])) {
            continue;
        }
        if (preg_match('/id|uuid|client/i', $row['key'])) {
            return $row['id'];
        }
    }
    return $ids[0]['id'] ?? '';
}

function connectix_build_subscription($uuid, $username)
{
    $uuid = trim((string) $uuid);
    $username = trim((string) $username);
    if ($uuid === '' || !preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
        return '';
    }
    $url = 'https://sub.irancdn.org/subscription/' . $uuid;
    if ($username !== '') {
        $url .= '#' . rawurlencode($username);
    }
    return $url;
}

/** GET /clients/get-enabled-plans */
function connectix_get_plans($namepanel)
{
    $panel = connectix_get_panel($namepanel);
    if (!$panel) {
        return ['http_code' => 0, 'error' => 'panel not found', 'plans' => []];
    }
    $res = connectix_request('GET', '/clients/get-enabled-plans', $panel);
    $plans = [];
    $body = is_array($res['body'] ?? null) ? $res['body'] : $res;
    if (isset($body['plans']) && is_array($body['plans'])) {
        $plans = $body['plans'];
    } elseif (isset($body['data']) && is_array($body['data'])) {
        $plans = $body['data'];
    } elseif (isset($body[0]) && is_array($body[0])) {
        $plans = $body;
    }
    $res['plans'] = $plans;
    return $res;
}

function connectix_is_uuid($v)
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim((string) $v));
}

/**
 * GET /clients?page=&recordPerPage=(max 100)&username=&name=
 * Response: {total_clients, clients: {data: [Client], current_page, last_page, next_page_url, ...}}
 */
function connectix_list_clients($namepanel, $query = [])
{
    $panel = connectix_get_panel($namepanel);
    if (!$panel) {
        return ['http_code' => 0, 'error' => 'panel not found', 'clients' => []];
    }
    $q = [];
    foreach (['page', 'recordPerPage', 'username', 'name'] as $k) {
        if (isset($query[$k]) && $query[$k] !== '') {
            $q[$k] = $query[$k];
        }
    }
    $res = connectix_request('GET', '/clients' . ($q ? '?' . http_build_query($q) : ''), $panel);
    $body = is_array($res['body'] ?? null) ? $res['body'] : [];
    $page = (isset($body['clients']) && is_array($body['clients'])) ? $body['clients'] : [];
    $res['clients'] = (isset($page['data']) && is_array($page['data'])) ? $page['data'] : [];
    $res['total_clients'] = (int) ($body['total_clients'] ?? ($page['total'] ?? count($res['clients'])));
    $res['next_page'] = !empty($page['next_page_url']);
    return $res;
}

/** Client id by exact username (seller prefix included), or the id itself. */
function connectix_find_client_id($namepanel, $username)
{
    $username = trim((string) $username);
    if (connectix_is_uuid($username)) {
        return ['id' => $username, 'client' => null, 'error' => null];
    }
    $want = strtolower($username);
    $last = null;
    for ($page = 1; $page <= 5; $page++) {
        $list = connectix_list_clients($namepanel, ['username' => $username, 'recordPerPage' => 100, 'page' => $page]);
        $last = $list;
        if (!connectix_http_ok($list)) {
            break;
        }
        foreach ($list['clients'] as $c) {
            if (is_array($c) && strtolower((string) ($c['username'] ?? '')) === $want && !empty($c['id'])) {
                return ['id' => (string) $c['id'], 'client' => $c, 'error' => null];
            }
        }
        if (empty($list['next_page'])) {
            break;
        }
    }
    return ['id' => '', 'client' => null, 'error' => $last['message'] ?? $last['error'] ?? 'client not found'];
}

/** GET /clients/show?id=<uuid>; a username is resolved to its id first. 404 = not found or not yours. */
function connectix_show_client($namepanel, $usernameOrId)
{
    $panel = connectix_get_panel($namepanel);
    if (!$panel) {
        return ['http_code' => 0, 'error' => 'panel not found', 'client' => null];
    }
    $id = trim((string) $usernameOrId);
    if (!connectix_is_uuid($id)) {
        $found = connectix_find_client_id($panel, $id);
        if ($found['id'] === '') {
            return ['http_code' => 404, 'error' => $found['error'], 'client' => null];
        }
        $id = $found['id'];
    }
    $res = connectix_request('GET', '/clients/show?id=' . rawurlencode($id), $panel);
    $body = is_array($res['body'] ?? null) ? $res['body'] : [];
    $res['client'] = (connectix_http_ok($res) && isset($body['client']) && is_array($body['client'])) ? $body['client'] : null;
    return $res;
}

/**
 * POST /clients/store (multipart). Required: password (5-8), plan_id.
 * The response has only message, client_id and text_to_copy; username, password and
 * subscription_link are read back with clients/show.
 */
function connectix_create_client($namepanel, $plan_id, $password, $extra = [])
{
    $panel = connectix_get_panel($namepanel);
    if (!$panel) {
        return ['http_code' => 0, 'error' => 'panel not found', 'success' => false];
    }
    $form = [
        'password' => (string) $password,
        'plan_id' => (string) $plan_id,
    ];
    foreach (['telegram_id', 'name', 'phone', 'email', 'chat_id'] as $k) {
        if (isset($extra[$k]) && $extra[$k] !== '' && $extra[$k] !== null) {
            $form[$k] = (string) $extra[$k];
        }
    }
    foreach (['enable_plan_after_first_login', 'is_child_protection_enabled'] as $k) {
        if (isset($extra[$k])) {
            $form[$k] = $extra[$k] ? '1' : '0';
        }
    }

    $res = connectix_request('POST', '/clients/store', $panel, $form, true);
    $res['success'] = connectix_http_ok($res);
    $body = is_array($res['body'] ?? null) ? $res['body'] : [];
    $res['text_to_copy'] = (string) ($body['text_to_copy'] ?? '');
    $res['client_id'] = (string) ($body['client_id'] ?? '');
    $res['subscription_url'] = '';
    if ($res['success'] && $res['client_id'] !== '') {
        $show = connectix_show_client($panel, $res['client_id']);
        if (is_array($show['client'] ?? null)) {
            $c = $show['client'];
            $res['client'] = $c;
            $res['subscription_url'] = (string) ($c['subscription_link'] ?? '');
            if (!empty($c['username']) && !empty($c['password'])) {
                $res['text_to_copy'] = ($res['text_to_copy'] !== '' ? $res['text_to_copy'] : "username: {$c['username']}\npassword: {$c['password']}");
            }
        }
    }
    return $res;
}

/** subscription_link of a client (by store result, id or username). */
function connectix_fetch_subscription($namepanel, $username, $storeRes = [])
{
    if (!empty($storeRes['subscription_url'])) {
        return (string) $storeRes['subscription_url'];
    }
    $key = !empty($storeRes['client_id']) ? $storeRes['client_id'] : $username;
    $show = connectix_show_client($namepanel, $key);
    return (string) ($show['client']['subscription_link'] ?? '');
}

/**
 * POST /clients/add-plan  JSON {id, plan_id}
 * Every success buys a plan (queued if one is active). Sent exactly once: never retried,
 * because a lost response may still have been charged.
 */
function connectix_add_plan($namepanel, $username, $plan_id, $extra = [])
{
    $panel = connectix_get_panel($namepanel);
    if (!$panel) {
        return ['http_code' => 0, 'error' => 'panel not found', 'success' => false];
    }
    $found = connectix_find_client_id($panel, $username);
    $clientId = $found['id'];
    if ($clientId === '') {
        return ['http_code' => 404, 'error' => 'client not found: ' . $username, 'success' => false, 'client_id' => ''];
    }
    $res = connectix_request('POST', '/clients/add-plan', $panel, ['id' => $clientId, 'plan_id' => (string) $plan_id], false);
    $res['success'] = connectix_http_ok($res);
    $res['client_id'] = $clientId;
    $code = (int) ($res['http_code'] ?? 0);
    if ($code === 0) {
        $res['message'] = 'پاسخی از کانکتیکس نرسید؛ قبل از تلاش دوباره در پنل کانکتیکس بررسی کنید که پلن اضافه شده یا نه.';
    } elseif ($code === 403) {
        $res['message'] = 'کانکتیکس اجازهٔ خرید نداد (موجودی یا دسترسی فروشنده): ' . ($res['message'] ?? '');
    } elseif ($code === 422) {
        $res['message'] = 'شناسهٔ کاربر یا پلن نامعتبر است: ' . ($res['message'] ?? '');
    }
    return $res;
}

function connectix_extend_client($namepanel, $username, $plan_id, $extra = [])
{
    $panel = connectix_get_panel($namepanel);
    if (!$panel) {
        return ['status' => false, 'msg' => 'panel not found'];
    }
    $res = connectix_add_plan($panel, $username, $plan_id, $extra);
    if (connectix_http_ok($res)) {
        return ['status' => true, 'msg' => 'ok', 'raw' => $res];
    }
    $msg = $res['message'] ?? $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? 0));
    return ['status' => false, 'msg' => $msg, 'raw' => $res];
}

/** Bot DataUser shape. Connectix traffic is in GB (null = unlimited); dates are ISO. */
function connectix_data_user($namepanel, $username)
{
    $show = connectix_show_client($namepanel, $username);
    $client = is_array($show['client'] ?? null) ? $show['client'] : [];
    if ($client === []) {
        return [
            'status' => 'Unsuccessful',
            'msg' => $show['message'] ?? $show['error'] ?? 'client not found',
        ];
    }
    $gb = 1073741824;
    $plan = is_array($client['active_plan'] ?? null) ? $client['active_plan'] : [];
    $limit = (isset($plan['traffic_amount']) && is_numeric($plan['traffic_amount'])) ? (int) round($plan['traffic_amount'] * $gb) : 0;
    $used = (isset($plan['used_traffic']) && is_numeric($plan['used_traffic'])) ? (int) round($plan['used_traffic'] * $gb) : 0;
    $exp = $client['expire_date'] ?? ($plan['expire_date'] ?? null);
    $expire = $exp ? (int) strtotime((string) $exp) : 0;
    if (!empty($client['is_expired'])) {
        $status = 'expired';
    } elseif (isset($client['is_activated']) && !$client['is_activated']) {
        $status = 'on_hold';
    } elseif (isset($client['is_active']) && !$client['is_active']) {
        $status = 'disabled';
    } elseif ($limit > 0 && $used >= $limit) {
        $status = 'limited';
    } else {
        $status = 'active';
    }
    return [
        'status' => $status,
        'username' => (string) ($client['username'] ?? $username),
        'data_limit' => $limit,
        'expire' => $expire,
        'online_at' => null,
        'used_traffic' => $used,
        'links' => [],
        'subscription_url' => (string) ($client['subscription_link'] ?? ''),
        'sub_updated_at' => null,
        'sub_last_user_agent' => null,
        'uuid' => null,
        'data_limit_reset' => 'no_reset',
        '_connectix' => true,
        '_cx_client' => $client,
    ];
}

function connectix_sync_products($namepanel)
{
    global $pdo;
    $panel = connectix_get_panel($namepanel);
    if (!$panel) {
        return ['added' => 0, 'updated' => 0, 'total' => 0, 'error' => 'panel not found'];
    }
    $name = $panel['name_panel'];
    $res = connectix_get_plans($panel);
    if (!connectix_http_ok($res)) {
        $detail = $res['message'] ?? $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? 0));
        $url = $res['url'] ?? connectix_normalize_base($panel['url_panel'] ?? '');
        return [
            'added' => 0,
            'updated' => 0,
            'total' => 0,
            'error' => $detail,
            'url' => $url,
        ];
    }
    $plans = $res['plans'] ?? [];
    $added = 0;
    $updated = 0;
    $skipped = 0;
    $category = 'کانکتیکس';
    $testPlanId = '';
    $testDays = 0;
    $testVol = 0;

    foreach ($plans as $plan) {
        if (!is_array($plan)) {
            continue;
        }
        $planId = (string) connectix_pick($plan, ['id', 'plan_id', 'uuid']);
        if ($planId === '') {
            continue;
        }
        $title = connectix_plan_title($plan);
        if (connectix_is_free_test_plan($title, $plan)) {
            if ($testPlanId === '') {
                $testPlanId = $planId;
                $testDays = intval(connectix_pick($plan, ['full_period_days', 'period', 'days'], 0));
                $trafficT = connectix_pick($plan, ['traffic_amount', 'traffic', 'volume'], '');
                if (is_numeric($trafficT)) {
                    $testVol = intval($trafficT);
                } elseif (is_string($trafficT) && preg_match('/(\d+)/', $trafficT, $vm)) {
                    $testVol = intval($vm[1]);
                }
            }
            $price = '0';
            $days = (string) intval(connectix_pick($plan, ['full_period_days', 'period', 'days'], 0));
            $traffic = connectix_pick($plan, ['traffic_amount', 'traffic', 'volume'], '');
            $vol = '0';
            if (is_numeric($traffic)) {
                $vol = (string) intval($traffic);
            } elseif (is_string($traffic) && preg_match('/(\d+)/', $traffic, $vm)) {
                $vol = $vm[1];
            }
            $devices = intval(connectix_pick($plan, ['count_of_devices', 'devices', 'max_clients'], 1));
            $code = substr(md5('cx|test|' . $name . '|' . $planId), 0, 10);
            try {
                $st = $pdo->prepare("SELECT id FROM product WHERE note = :note AND Location = :loc LIMIT 1");
                $st->execute([':note' => $planId, ':loc' => $name]);
                $ex = $st->fetch(PDO::FETCH_ASSOC);
                if ($ex) {
                    $st = $pdo->prepare("UPDATE product SET name_product=:n, price_product='0', Volume_constraint=:v, Service_time=:t, category='تست', one_buy_status='test', max_users=:m WHERE id=:id");
                    $st->execute([':n' => $title, ':v' => $vol, ':t' => $days, ':m' => (string) max(1, $devices), ':id' => $ex['id']]);
                } else {
                    $st = $pdo->prepare("INSERT INTO product (name_product,code_product,price_product,Volume_constraint,Service_time,Location,agent,data_limit_reset,note,category,hide_panel,one_buy_status,max_users) VALUES (:n,:code,'0',:v,:t,:loc,'f','no_reset',:note,'تست','{}','test',:m)");
                    $st->execute([':n' => $title, ':code' => $code, ':v' => $vol, ':t' => $days, ':loc' => $name, ':note' => $planId, ':m' => (string) max(1, $devices)]);
                }
            } catch (Throwable $e) {
                error_log('connectix_sync test product: ' . $e->getMessage());
            }
            $skipped++;
            continue;
        }
        $price = (string) connectix_pick($plan, ['sell_price', 'price', 'amount'], '0');
        $days = (string) intval(connectix_pick($plan, ['full_period_days', 'period', 'days'], 0));
        $traffic = connectix_pick($plan, ['traffic_amount', 'traffic', 'volume'], '');
        $vol = 0;
        if (is_numeric($traffic)) {
            $vol = (string) intval($traffic);
        } elseif (is_string($traffic) && preg_match('/(\d+)/', $traffic, $vm)) {
            $vol = $vm[1];
        }
        $devices = intval(connectix_pick($plan, ['count_of_devices', 'devices', 'max_clients'], 1));
        $code = substr(md5('cx|' . $name . '|' . $planId), 0, 10);

        $existing = null;
        try {
            $st = $pdo->prepare("SELECT id, code_product FROM product WHERE note = :note AND Location = :loc LIMIT 1");
            $st->execute([':note' => $planId, ':loc' => $name]);
            $existing = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
        }

        if ($existing) {
            try {
                $st = $pdo->prepare("UPDATE product SET name_product=:n, price_product=:p, Volume_constraint=:v, Service_time=:t, category=:c, max_users=:m WHERE id=:id");
                $st->execute([
                    ':n' => $title,
                    ':p' => $price,
                    ':v' => $vol,
                    ':t' => $days,
                    ':c' => $category,
                    ':m' => (string) $devices,
                    ':id' => $existing['id'],
                ]);
                $updated++;
            } catch (Throwable $e) {
                error_log('connectix_sync update: ' . $e->getMessage());
            }
        } else {
            try {
                $st = $pdo->prepare("INSERT INTO product (name_product,code_product,price_product,Volume_constraint,Service_time,Location,agent,data_limit_reset,note,category,hide_panel,one_buy_status,max_users) VALUES (:n,:code,:p,:v,:t,:loc,'f','no_reset',:note,:c,'{}','0',:m)");
                $st->execute([
                    ':n' => $title,
                    ':code' => $code,
                    ':p' => $price,
                    ':v' => $vol,
                    ':t' => $days,
                    ':loc' => $name,
                    ':note' => $planId,
                    ':c' => $category,
                    ':m' => (string) max(1, $devices),
                ]);
                $added++;
            } catch (Throwable $e) {
                error_log('connectix_sync insert: ' . $e->getMessage());
            }
        }
    }

    try {
        $pdo->exec("UPDATE setting SET statuscategory='oncategory' WHERE statuscategory='offcategory' OR statuscategory IS NULL OR statuscategory=''");
    } catch (Throwable $e) {
    }

    if ($testPlanId !== '') {
        try {
            $st = $pdo->prepare("UPDATE marzban_panel SET inboundid = :pid, TestAccount = 'ONTestAccount' WHERE name_panel = :n AND type = 'connectix'");
            $st->execute([':pid' => $testPlanId, ':n' => $name]);
            if ($testDays > 0) {
                $hours = max(1, $testDays * 24);
                $st = $pdo->prepare("UPDATE marzban_panel SET time_usertest = :h WHERE name_panel = :n AND type = 'connectix'");
                $st->execute([':h' => (string) $hours, ':n' => $name]);
            }
            if ($testVol > 0) {
                $st = $pdo->prepare("UPDATE marzban_panel SET val_usertest = :v WHERE name_panel = :n AND type = 'connectix'");
                $st->execute([':v' => (string) $testVol, ':n' => $name]);
            }
        } catch (Throwable $e) {
            error_log('connectix_sync test panel: ' . $e->getMessage());
        }
    }

    return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($plans), 'error' => null, 'test_plan' => $testPlanId];
}

function connectix_format_message($planTitle, $username, $password, $text_to_copy = '', $subscription_url = '')
{
    $msg = "✅ سرویس کانکتیکس فعال شد\n\n";
    if ($planTitle !== '') {
        $msg .= "📦 پلن: <b>" . htmlspecialchars($planTitle) . "</b>\n";
    }
    $msg .= "👤 نام کاربری: <code>" . htmlspecialchars($username) . "</code>\n";
    $msg .= "🔑 رمز عبور: <code>" . htmlspecialchars($password) . "</code>\n";
    if ($subscription_url === '') {
        $subscription_url = connectix_pick_subscription($text_to_copy);
    }
    if ($subscription_url !== '') {
        $msg .= "🔗 لینک اتصال:\n<code>" . htmlspecialchars($subscription_url) . "</code>\n";
    }
    if ($text_to_copy !== '' && preg_match('/plan\s*:\s*(.+)/i', str_replace('`', '', $text_to_copy), $pm)) {
        $msg .= "📄 مشخصات پلن: <code>" . htmlspecialchars(trim($pm[1])) . "</code>\n";
    }
    $msg .= "\n\nبا لینک وارد اپ شوید یا با نام کاربری و رمز لاگین کنید.";
    return $msg;
}

function connectix_plan_id_from_product($product)
{
    if (!is_array($product)) {
        return '';
    }
    foreach (['note', 'inbounds'] as $k) {
        if (!empty($product[$k])) {
            return trim((string) $product[$k]);
        }
    }
    return '';
}

} // guard
