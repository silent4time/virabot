<?php
/**
 * EylanPanel API — SilentSell
 * Auth: X-API-KEY = marzban_panel.password_panel
 * Protocol mode = marzban_panel.inboundid: openvpn|l2tp|cisco|wireguard|multi
 * Docs: https://eylandoo.github.io/
 */
if (!function_exists('eylan_request')) {

function eylan_request($method, $endpoint, $panel, $body = null)
{
    if (!is_array($panel) || empty($panel['url_panel'])) {
        return ['success' => false, 'error' => 'Invalid panel', 'http_code' => 0];
    }
    $url = rtrim($panel['url_panel'], '/') . $endpoint;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-KEY: ' . ($panel['password_panel'] ?? ''),
        ],
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        return ['success' => false, 'error' => $error, 'http_code' => $httpCode];
    }
    $raw = (string) $response;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        // پاسخ متنی کانفیگ (WireGuard/OpenVPN) — دور نریز
        if ($httpCode >= 200 && $httpCode < 300 && strlen($raw) > 40) {
            if (strpos($raw, '[Interface]') !== false || stripos($raw, 'PrivateKey') !== false
                || stripos($raw, 'client') !== false || stripos($raw, 'remote ') !== false) {
                return [
                    'success' => true,
                    'http_code' => $httpCode,
                    'config' => $raw,
                    'content' => $raw,
                    '_raw_config' => true,
                ];
            }
        }
        return ['success' => false, 'error' => 'Invalid JSON', 'raw' => substr($raw, 0, 400), 'http_code' => $httpCode];
    }
    $decoded['http_code'] = $httpCode;
    return $decoded;
}

function eylan_get_panel($namepanel)
{
    $p = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    return (is_array($p) && !empty($p['name_panel'])) ? $p : null;
}

function eylan_normalize_protocol($raw)
{
    $p = mb_strtolower(trim((string) $raw), 'UTF-8');
    $p = str_replace([' ', '-', '_', 'ـ'], '', $p);
    // فارسی رایج
    $p = str_replace(['وایرگارد', 'وایربگارد', 'وایر'], 'wireguard', $p);
    $p = str_replace(['اوپن‌وی‌پی‌ان', 'اوپنویپیان', 'اوپن'], 'openvpn', $p);
    $p = str_replace(['سیسکو'], 'cisco', $p);
    $p = str_replace(['مولتی', 'ملتی'], 'multi', $p);
    $map = [
        'openvpn' => 'openvpn', 'ovpn' => 'openvpn',
        'l2tp' => 'l2tp', 'ipsec' => 'l2tp', 'l2tpipsec' => 'l2tp',
        'cisco' => 'cisco', 'anyconnect' => 'cisco', 'ocserv' => 'cisco',
        'wireguard' => 'wireguard', 'wg' => 'wireguard', 'wg1' => 'wireguard', 'wg2' => 'wireguard',
        'multi' => 'multi', 'molti' => 'multi', 'all' => 'multi',
    ];
    if (isset($map[$p])) {
        return $map[$p];
    }
    // شامل بودن
    if (str_contains($p, 'wireguard') || $p === 'wg') return 'wireguard';
    if (str_contains($p, 'openvpn') || $p === 'ovpn') return 'openvpn';
    if (str_contains($p, 'l2tp')) return 'l2tp';
    if (str_contains($p, 'cisco') || str_contains($p, 'anyconnect')) return 'cisco';
    if (str_contains($p, 'multi') || str_contains($p, 'molti')) return 'multi';
    return 'multi';
}

function eylan_resolve_protocol($panel, $extra = [])
{
    // 1) صریح از extra
    if (!empty($extra['protocol'])) {
        $p = eylan_normalize_protocol($extra['protocol']);
        if ($p !== 'multi' || in_array(strtolower(trim((string)$extra['protocol'])), ['multi', 'molti', 'all'], true)) {
            return $p;
        }
    }
    // 2) inboundid پنل (باید openvpn|wireguard|l2tp|cisco|multi باشد — نه عدد)
    if (is_array($panel) && isset($panel['inboundid']) && $panel['inboundid'] !== '' && $panel['inboundid'] !== null) {
        $raw = trim((string) $panel['inboundid']);
        // اگر فقط عدد بود (سبک مرزبان) نادیده بگیر
        if (!preg_match('/^[0-9]+$/', $raw)) {
            $p = eylan_normalize_protocol($raw);
            if ($p !== 'multi' || in_array(strtolower(str_replace([' ', '-', '_'], '', $raw)), ['multi', 'molti', 'all'], true)) {
                return $p;
            }
        }
    }
    // 3) حدس از نام پنل
    if (is_array($panel) && !empty($panel['name_panel'])) {
        $n = mb_strtolower((string) $panel['name_panel'], 'UTF-8');
        if (str_contains($n, 'wireguard') || str_contains($n, 'وایرگارد') || str_contains($n, 'وایر')) {
            return 'wireguard';
        }
        if (str_contains($n, 'openvpn') || str_contains($n, 'اوپن')) {
            return 'openvpn';
        }
        if (str_contains($n, 'l2tp')) {
            return 'l2tp';
        }
        if (str_contains($n, 'cisco') || str_contains($n, 'سیسکو') || str_contains($n, 'anyconnect')) {
            return 'cisco';
        }
        if (str_contains($n, 'multi') || str_contains($n, 'molti') || str_contains($n, 'مولتی')) {
            return 'multi';
        }
    }
    return 'multi';
}

function eylan_protocol_flags($protocol)
{
    $protocol = eylan_normalize_protocol($protocol);
    // API v4: همه پرچم‌ها صریح false مگر پروتکل انتخابی
    $f = [
        'enable_openvpn' => false,
        'enable_l2tp' => false,
        'enable_cisco' => false,
        'enable_wg1' => false,
        'enable_singbox' => false,
    ];
    if ($protocol === 'multi') {
        return [
            'enable_openvpn' => true,
            'enable_l2tp' => true,
            'enable_cisco' => true,
            'enable_wg1' => true,
            'enable_singbox' => false,
        ];
    }
    if ($protocol === 'openvpn') {
        $f['enable_openvpn'] = true;
    } elseif ($protocol === 'l2tp') {
        $f['enable_l2tp'] = true;
    } elseif ($protocol === 'cisco') {
        $f['enable_cisco'] = true;
    } elseif ($protocol === 'wireguard') {
        $f['enable_wg1'] = true;
    }
    return $f;
}

function eylan_http_ok($result)
{
    if (!is_array($result)) {
        return false;
    }
    $code = (int) ($result['http_code'] ?? 0);
    if (!empty($result['error']) && ($code === 0 || $code >= 400)) {
        return false;
    }
    if (array_key_exists('success', $result) && $result['success'] === false) {
        return false;
    }
    if ($code > 0 && ($code < 200 || $code >= 300)) {
        return false;
    }
    return true;
}

function eylan_create_user($namepanel, $username, $data_limit_gb, $expire_timestamp, $extra = [])
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    $protocol = eylan_resolve_protocol($panel, $extra);
    $flags = eylan_protocol_flags($protocol);

    $days = isset($extra['days']) ? (int) $extra['days'] : 0;
    if ($days <= 0 && $expire_timestamp > time()) {
        $days = (int) max(1, ceil(($expire_timestamp - time()) / 86400));
    }

    // فقط فیلدهای مجاز API — نه merge کور $extra (جلوگیری از لو رفتن فیلدهای اشتباه)
    $body = array_merge([
        'username' => $username,
        'data_limit' => round(floatval($data_limit_gb), 2),
        'data_limit_unit' => 'GB',
        'max_clients' => array_key_exists('max_clients', $extra) ? max(0, (int) $extra['max_clients']) : 1,
        'notes' => $extra['note'] ?? ($extra['notes'] ?? ('bot ' . date('Y-m-d H:i'))),
        'activation_type' => 'fixed_date',
    ], $flags);

    if ($days > 0) {
        $body['expiry_days'] = $days;
    } elseif ($expire_timestamp > 0) {
        $body['expiry_date_str'] = date('Y-m-d', $expire_timestamp);
    }

    // همیشه برای L2TP/Cisco رمز بساز تا در پیام کاربر داشته باشیم
    if ($flags['enable_l2tp']) {
        $body['l2tp_password'] = $extra['l2tp_password'] ?? ('L2' . bin2hex(random_bytes(4)));
    }
    if ($flags['enable_cisco']) {
        $body['cisco_password'] = $extra['cisco_password'] ?? ('C' . bin2hex(random_bytes(4)));
    }
    if ($flags['enable_wg1']) {
        // API v4: wg1 با enable_wg1، بقیه در wg_instances (مثلاً wg2)
        $body['enable_wg1'] = true;
        $body['wg_instances'] = $extra['wg_instances'] ?? ['wg2'];
        $body['allowed_wg_instances'] = $body['wg_instances'];
    } else {
        $body['enable_wg1'] = false;
        $body['wg_instances'] = [];
        $body['allowed_wg_instances'] = [];
    }
    $body['enable_singbox'] = false;

    // JSON boolean واقعی (نه 0/1) برای API ایلان
    foreach ($flags as $fk => $fv) {
        $body[$fk] = (bool) $fv;
    }

    error_log("eylan_create_user protocol={$protocol} user={$username} flags=" . json_encode($flags));

    $result = eylan_request('POST', '/api/v1/users', $panel, $body);
    $result['_protocol'] = $protocol;
    $result['_flags'] = $flags;

    if (!eylan_http_ok($result) && $days > 0) {
        $body2 = $body;
        unset($body2['expiry_days']);
        $body2['expire'] = time() + ($days * 86400);
        $result2 = eylan_request('POST', '/api/v1/users', $panel, $body2);
        if (eylan_http_ok($result2)) {
            $result = $result2;
        }
    }

    // بعد از ساخت: قفل اجباری پروتکل (API گاهی همه را روشن می‌کند)
    if (eylan_http_ok($result) || !empty($result['username']) || (isset($result['http_code']) && $result['http_code'] >= 200 && $result['http_code'] < 300)) {
        $put = [
            'enable_openvpn' => false,
            'enable_l2tp' => false,
            'enable_cisco' => false,
            'enable_wg1' => false,
            'enable_singbox' => false,
            'wg_instances' => [],
            'allowed_wg_instances' => [],
        ];
        foreach ($flags as $fk => $fv) {
            $put[$fk] = (bool) $fv;
        }
        if (!empty($flags['enable_wg1'])) {
            $put['enable_wg1'] = true;
            $put['wg_instances'] = $extra['wg_instances'] ?? ['wg2'];
            $put['allowed_wg_instances'] = $put['wg_instances'];
        }
        // دو بار PUT با فاصله کوتاه — بعضی نسخه‌های پنل POST را بعداً override می‌کنند
        $putResult = eylan_request('PUT', '/api/v1/users/' . rawurlencode($username), $panel, $put);
        usleep(400000);
        $putResult2 = eylan_request('PUT', '/api/v1/users/' . rawurlencode($username), $panel, $put);
        error_log("eylan_create_user LOCK protocol={$protocol} put=" . json_encode($put) . " http1=" . ($putResult['http_code'] ?? '?') . " http2=" . ($putResult2['http_code'] ?? '?'));
        $result['_put'] = $putResult2;
        $result['_lock'] = $put;
    }

    $result['_protocol'] = $protocol;
    $result['_flags'] = $flags;
    $result['_body'] = $body;
    return $result;
}

function eylan_get_user($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    return eylan_request('GET', '/api/v1/users/' . rawurlencode($username), $panel);
}

function eylan_get_sub($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    return eylan_request('GET', '/api/v1/users/' . rawurlencode($username) . '/sub', $panel);
}

function eylan_get_ovpn_links($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    return eylan_request('GET', '/api/v1/users/' . rawurlencode($username) . '/all_ovpn_links', $panel);
}

function eylan_get_wg_files($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    return eylan_request('GET', '/api/v1/users/' . rawurlencode($username) . '/wg1_files', $panel);
}

function eylan_toggle_user($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    return eylan_request('POST', '/api/v1/users/' . rawurlencode($username) . '/toggle', $panel);
}

function eylan_reset_traffic($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    return eylan_request('POST', '/api/v1/users/' . rawurlencode($username) . '/reset_traffic', $panel);
}

function eylan_revoke($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    return eylan_request('POST', '/api/v1/users/' . rawurlencode($username) . '/revoke', $panel);
}

function eylan_delete_user($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    $r = eylan_request('DELETE', '/api/v1/users/' . rawurlencode($username), $panel);
    if (eylan_http_ok($r)) {
        return $r;
    }
    // toggle flips the state, so only use it to disable an account that is still active
    $user = eylan_get_user($namepanel, $username);
    if (is_array($user) && !empty($user['is_active'])) {
        return eylan_toggle_user($namepanel, $username);
    }
    return $r;
}

function eylan_status($namepanel)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['success' => false, 'message' => 'Panel not found'];
    }
    return eylan_request('GET', '/api/v1/status', $panel);
}


function eylan_collect_links($data, &$out, $depth = 0)
{
    if ($depth > 6 || $data === null) {
        return;
    }
    if (is_string($data)) {
        $s = trim($data);
        if ($s === '') {
            return;
        }
        if (preg_match('#^https?://#i', $s) || preg_match('#\.ovpn#i', $s) || preg_match('#\.conf#i', $s) || str_contains($s, 'wg')) {
            $out[] = $s;
        } elseif (str_contains($s, '[Interface]') || str_contains($s, 'client') && str_contains($s, 'remote ')) {
            $out[] = 'RAWCFG:' . $s;
        }
        return;
    }
    if (!is_array($data)) {
        return;
    }
    foreach (['url', 'link', 'download_url', 'file_url', 'config_url', 'sub_url', 'ovpn_url', 'wg_url'] as $k) {
        if (!empty($data[$k]) && is_string($data[$k])) {
            eylan_collect_links($data[$k], $out, $depth + 1);
        }
    }
    foreach (['config', 'conf', 'content', 'wg_config', 'ovpn'] as $k) {
        if (!empty($data[$k]) && is_string($data[$k]) && strlen($data[$k]) > 20) {
            $out[] = 'RAWCFG:' . $data[$k];
        }
    }
    foreach ($data as $v) {
        if (is_array($v) || is_string($v)) {
            eylan_collect_links($v, $out, $depth + 1);
        }
    }
}

function eylan_ovpn_fingerprint($body)
{
    if (!is_string($body) || $body === '') {
        return '';
    }
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $norm = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        // کامنت‌های ; را هم حذف
        if (str_starts_with($line, ';')) {
            continue;
        }
        $norm[] = strtolower($line);
    }
    return md5(implode("\n", $norm));
}

/**
 * لینک‌ها/متن‌های OVPN را جمع می‌کند و بر اساس محتوای فایل یکتا می‌کند
 * (قبلاً فقط URL یکتا می‌شد و از چند endpoint تکراری ۱۱ فایل به‌جای ۷ تا می‌آمد)
 */
function eylan_get_ovpn_all($namepanel, $username)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return [];
    }
    $collected = [];
    // منبع اصلی
    eylan_collect_links(eylan_get_ovpn_links($namepanel, $username), $collected);
    // فقط اگر خالی بود سراغ fallback
    if (count($collected) === 0) {
        foreach (['ovpn_links', 'openvpn_files'] as $ep) {
            eylan_collect_links(
                eylan_request('GET', '/api/v1/users/' . rawurlencode($username) . '/' . $ep, $panel),
                $collected
            );
        }
    }
    $collected = array_values(array_unique($collected));

    $out = [];
    $seenFp = [];
    foreach ($collected as $item) {
        if (!is_string($item) || $item === '') {
            continue;
        }
        $body = '';
        if (str_starts_with($item, 'RAWCFG:')) {
            $body = substr($item, 7);
        } elseif (preg_match('#^https?://#i', $item)) {
            $body = eylan_http_get_body($item, $panel);
            if ($body === '') {
                $r = eylan_http_get_raw($item, $panel);
                $cand = $r['body'] ?? '';
                if (is_string($cand) && (stripos($cand, 'remote ') !== false || stripos($cand, 'client') !== false || str_contains($cand, '-----BEGIN'))) {
                    $body = $cand;
                }
            }
        } else {
            continue;
        }
        if (!is_string($body) || strlen($body) < 30) {
            continue;
        }
        // باید شبیه ovpn باشد
        if (stripos($body, 'remote ') === false && stripos($body, 'client') === false && strpos($body, '-----BEGIN') === false) {
            continue;
        }
        $fp = eylan_ovpn_fingerprint($body);
        if ($fp === '' || isset($seenFp[$fp])) {
            continue;
        }
        $seenFp[$fp] = true;
        $out[] = 'RAWCFG:' . $body;
    }
    error_log('eylan_get_ovpn_all user=' . $username . ' links=' . count($collected) . ' unique=' . count($out));
    return $out;
}

function eylan_fetch_psk($panel)
{
    if (!is_array($panel)) {
        return '';
    }
    $r = eylan_request('GET', '/api/v1/server_settings', $panel);
    if (!is_array($r)) {
        return '';
    }
    foreach (['ipsec_psk', 'psk', 'l2tp_psk', 'ipsec_secret', 'secret'] as $k) {
        if (!empty($r[$k]) && is_string($r[$k])) {
            return $r[$k];
        }
        if (!empty($r['data'][$k]) && is_string($r['data'][$k])) {
            return $r['data'][$k];
        }
    }
    return '';
}


/**
 * آدرس نمایشی برای کاربر — هرگز mirzavalibot را نشان نده
 */

/**
 * آدرس عمومی پنل برای کاربر
 * لینک درست: http://panel.example.com:8050/...
 * هرگز mirzavalibot و هرگز بدون پورت 8050
 */
function eylan_get_wg_all_files($namepanel, $username, $user = null)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return [];
    }
    if (!is_array($user)) {
        $user = eylan_get_user($namepanel, $username);
    }
    $base = rtrim($panel['url_panel'] ?? '', '/');
    $rawOut = [];
    $seenEp = [];

    $addConf = function ($body, $src) use (&$rawOut, &$seenEp) {
        if (!is_string($body) || $body === '') {
            return;
        }
        if (strpos($body, '[Interface]') === false && strpos($body, '\\[Interface\\]') !== false) {
            $body = stripcslashes($body);
        }
        if (preg_match_all('/\[Interface\][\s\S]*?(?=(?:\[Interface\]|\z))/i', $body, $blocks)) {
            foreach ($blocks[0] as $block) {
                $block = trim($block);
                if (stripos($block, 'PrivateKey') === false) {
                    continue;
                }
                $ep = preg_match('/Endpoint\s*=\s*(\S+)/i', $block, $m) ? strtolower(rtrim($m[1], '\\')) : md5($block);
                if (isset($seenEp[$ep])) {
                    continue;
                }
                $seenEp[$ep] = true;
                $rawOut[] = 'RAWCFG:' . $block;
                error_log("eylan_wg add from=$src endpoint=$ep");
            }
        } elseif (strpos($body, '[Interface]') !== false && stripos($body, 'PrivateKey') !== false) {
            $ep = preg_match('/Endpoint\s*=\s*(\S+)/i', $body, $m) ? strtolower($m[1]) : md5($body);
            if (!isset($seenEp[$ep])) {
                $seenEp[$ep] = true;
                $rawOut[] = 'RAWCFG:' . $body;
                error_log("eylan_wg add from=$src endpoint=$ep");
            }
        }
    };

    // token
    $token = '';
    $su = is_array($user) ? ($user['sub_url'] ?? '') : '';
    if ($su === '') {
        $sub = eylan_get_sub($namepanel, $username);
        if (is_array($sub)) {
            $su = $sub['sub_url'] ?? $sub['url'] ?? '';
        }
    }
    if (preg_match('#/sub/([^/]+)/#', (string) $su, $m)) {
        $token = $m[1];
    }

    $urls = [];

    // منبع اصلی: /get_subpage_download_data/{token}/{username}
    // داخل JSON فیلد download_url مثل:
    // /download_wg_instance/wg2/{token}/{user}/main_tunnel/{endpoint_id}
    if ($token !== '') {
        $ddPath = '/get_subpage_download_data/' . rawurlencode($token) . '/' . rawurlencode($username);
        $raw = eylan_http_get_raw($base . $ddPath, $panel);
        $body = $raw['body'] ?? '';
        if (is_string($body) && $body !== '') {
            $j = json_decode($body, true);
            if (is_array($j)) {
                $stack = [$j];
                while ($stack) {
                    $node = array_pop($stack);
                    if (!is_array($node)) {
                        continue;
                    }
                    foreach ($node as $k => $v) {
                        if ($k === 'download_url' && is_string($v) && $v !== '') {
                            if (str_starts_with($v, 'http://') || str_starts_with($v, 'https://')) {
                                $urls[] = $v;
                            } else {
                                $urls[] = $base . (str_starts_with($v, '/') ? $v : '/' . $v);
                            }
                        } elseif (is_array($v)) {
                            $stack[] = $v;
                        } elseif (is_string($v) && (strpos($v, 'PrivateKey') !== false || strpos($v, '[Interface]') !== false)) {
                            $addConf($v, 'download_data.inline');
                        }
                    }
                }
            }
            error_log('eylan_wg download_data http=' . ($raw['code'] ?? 0) . ' urls=' . count($urls));
        }
    }

    // fallback: wg1_files API
    $r1 = eylan_request('GET', '/api/v1/users/' . rawurlencode($username) . '/wg1_files', $panel);
    if (is_array($r1)) {
        eylan_extract_wg_download_urls($r1, $base, $urls, $token);
    }

    // fallback: ساخت از wg_instances — مسیر درست download_wg_instance/{inst}/...
    if (is_array($user) && !empty($user['wg_instances']) && $token !== '') {
        foreach ($user['wg_instances'] as $instRow) {
            if (!is_array($instRow)) {
                continue;
            }
            $inst = $instRow['instance'] ?? 'wg1';
            foreach (($instRow['servers'] ?? []) as $srv) {
                if (!is_array($srv)) {
                    continue;
                }
                $eid = (string) ($srv['endpoint_id'] ?? '');
                $stype = $srv['server_type'] ?? 'main_tunnel';
                if ($eid === '') {
                    continue;
                }
                $urls[] = $base . '/download_wg_instance/' . rawurlencode($inst) . '/' . $token . '/' . rawurlencode($username) . '/' . $stype . '/' . $eid;
                // مسیرهای قدیمی هم امتحان
                $urls[] = $base . '/download_' . $inst . '/' . $token . '/' . rawurlencode($username) . '/' . $stype . '/' . $eid;
            }
        }
    }

    $seenU = [];
    foreach ($urls as $u) {
        if (!is_string($u) || !preg_match('#^https?://#i', $u)) {
            continue;
        }
        // فقط مسیرهای دانلود WG
        if (!preg_match('#/download_wg#i', $u)) {
            continue;
        }
        $k = preg_replace('#^https?://[^/]+#i', '', $u);
        if (isset($seenU[$k])) {
            continue;
        }
        $seenU[$k] = true;
        $conf = eylan_http_get_body($u, $panel);
        $addConf($conf, $u);
    }

    error_log('eylan_get_wg_all_files user=' . $username . ' confs=' . count($rawOut) . ' tried_urls=' . count($seenU));
    return $rawOut;
}


function eylan_extract_wg_download_urls($data, $base, &$collected, &$token, $depth = 0)
{
    if ($depth > 8 || $data === null) {
        return;
    }
    if (is_string($data)) {
        $s = trim($data);
        if (preg_match('#/download_wg\d+/#i', $s)) {
            if (!preg_match('#^https?://#i', $s)) {
                $s = $base . '/' . ltrim($s, '/');
            }
            $collected[] = $s;
            if ($token === '' && preg_match('#/download_wg\d+/([a-f0-9]+)/#i', $s, $m)) {
                $token = $m[1];
            }
        } elseif (preg_match('#^https?://[^\s]+#i', $s) && (str_contains($s, 'download_wg') || str_ends_with($s, '.conf'))) {
            $collected[] = $s;
        }
        return;
    }
    if (!is_array($data)) {
        return;
    }
    // فیلدهای رایج
    foreach (['url', 'download_url', 'file_url', 'link', 'href', 'path'] as $k) {
        if (!empty($data[$k])) {
            eylan_extract_wg_download_urls($data[$k], $base, $collected, $token, $depth + 1);
        }
    }
    foreach ($data as $k => $v) {
        if (is_array($v) || is_string($v)) {
            eylan_extract_wg_download_urls($v, $base, $collected, $token, $depth + 1);
        }
    }
}


function eylan_collect_wg_raw($data, &$out, $depth = 0)
{
    if ($depth > 8 || $data === null) {
        return;
    }
    if (is_string($data)) {
        $s = trim($data);
        if (strlen($s) > 40 && (strpos($s, '[Interface]') !== false || (stripos($s, 'PrivateKey') !== false && stripos($s, 'Endpoint') !== false))) {
            $out[] = 'RAWCFG:' . $s;
        }
        return;
    }
    if (!is_array($data)) {
        return;
    }
    foreach (['config', 'conf', 'content', 'wg_config', 'wireguard_config', 'client_config', 'file_content', 'text'] as $k) {
        if (!empty($data[$k]) && is_string($data[$k])) {
            eylan_collect_wg_raw($data[$k], $out, $depth + 1);
        }
    }
    foreach ($data as $v) {
        if (is_array($v) || is_string($v)) {
            eylan_collect_wg_raw($v, $out, $depth + 1);
        }
    }
}

function eylan_http_get_raw($url, $panel = null, $extra_headers = [])
{
    if (!is_string($url) || $url === '') {
        return ['code' => 0, 'body' => ''];
    }
    $attempts = [];
    if (is_array($panel) && !empty($panel['password_panel'])) {
        $attempts[] = array_merge(['X-API-KEY: ' . $panel['password_panel'], 'Accept: application/json, text/plain, */*'], $extra_headers);
    }
    $attempts[] = array_merge(['Accept: application/json, text/plain, */*'], $extra_headers);

    foreach ($attempts as $hdrs) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_USERAGENT => 'silentsell/5.1',
        ]);
        $bin = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && is_string($bin)) {
            return ['code' => $code, 'body' => $bin];
        }
        // keep last non-empty error body for debug
        if (is_string($bin) && $bin !== '' && $code > 0) {
            $last = ['code' => $code, 'body' => $bin];
        }
    }
    return $last ?? ['code' => 0, 'body' => ''];
}

function eylan_http_get_body($url, $panel = null)
{
    $r = eylan_http_get_raw($url, $panel);
    $bin = $r['body'] ?? '';
    if (!is_string($bin) || $bin === '') {
        return '';
    }
    // برای دانلود conf سخت‌گیر؛ برای JSON خام از eylan_http_get_raw استفاده شود
    if (strpos($bin, '[Interface]') !== false || stripos($bin, 'PrivateKey') !== false
        || (stripos($bin, 'client') !== false && stripos($bin, 'remote') !== false)) {
        return $bin;
    }
    return '';
}



/**
 * از HTML صفحه ساب، لینک/متن کانفیگ‌های WG را دربیار
 * (Armenia :5182 و Turkey :5183 و هر Endpoint دیگر)
 */
function eylan_parse_wg_from_html($html, &$out, $panel = null, $username = '')
{
    // لینک‌های .conf
    if (preg_match_all('#https?://[^\s"\'<>]+?\.conf(?:\?[^\s"\'<>]*)?#i', $html, $m)) {
        foreach ($m[0] as $u) {
            $out[] = $u;
        }
    }
    // href نسبی
    if (preg_match_all('#href=["\']([^"\']+\.conf[^"\']*)["\']#i', $html, $m)) {
        foreach ($m[1] as $u) {
            if (!preg_match('#^https?://#i', $u) && is_array($panel)) {
                $u = rtrim($panel['url_panel'], '/') . '/' . ltrim($u, '/');
            }
            $out[] = $u;
        }
    }
    // API download paths در صفحه
    if (preg_match_all('#(/api/v1/users/[^"\'\s]+/(?:wg\d*_files|[^"\'\s]*config[^"\'\s]*))#i', $html, $m)) {
        foreach ($m[1] as $path) {
            if (is_array($panel)) {
                $body = eylan_http_get_body(rtrim($panel['url_panel'], '/') . $path, $panel);
                if ($body !== '' && (strpos($body, '[Interface]') !== false || stripos($body, 'PrivateKey') !== false)) {
                    $out[] = 'RAWCFG:' . $body;
                } else {
                    // maybe JSON
                    $j = json_decode($body, true);
                    if (is_array($j)) {
                        eylan_collect_links($j, $out);
                        eylan_collect_wg_raw($j, $out);
                    }
                }
            }
        }
    }
    // بلوک‌های conf داخل صفحه / اسکریپت
    if (preg_match_all('#\[Interface\][\s\S]{20,2000}?\[Peer\][\s\S]{20,1500}?(?=\\[Interface\\]|</|$)#i', $html, $m)) {
        foreach ($m[0] as $block) {
            $block = html_entity_decode(strip_tags($block));
            if (stripos($block, 'PrivateKey') !== false || stripos($block, 'Endpoint') !== false) {
                $out[] = 'RAWCFG:' . trim($block);
            }
        }
    }
    // data-config / data-conf attributes
    if (preg_match_all('#data-(?:config|conf|wg)=["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $raw) {
            $dec = html_entity_decode($raw);
            if (strpos($dec, '[Interface]') !== false) {
                $out[] = 'RAWCFG:' . $dec;
            } elseif (preg_match('#^https?://#i', $dec) || str_contains($dec, '.conf')) {
                $out[] = $dec;
            }
        }
    }
}


function eylan_public_base($panel = null)
{
    $host = 'panel.example.com';
    $port = '8050';
    if (is_array($panel) && !empty($panel['url_panel'])) {
        $p = @parse_url($panel['url_panel']);
        if (is_array($p) && !empty($p['host'])) {
            $host = $p['host'];
        }
        if (is_array($p) && !empty($p['port'])) {
            $port = (string) $p['port'];
        }
    }
    return 'http://' . $host . ':' . $port;
}

function eylan_public_host($panel = null)
{
    $base = eylan_public_base($panel);
    $p = @parse_url($base);
    return (is_array($p) && !empty($p['host'])) ? $p['host'] : 'panel.example.com';
}

function eylan_public_url($url, $panel = null)
{
    if (!is_string($url) || $url === '') {
        return $url;
    }
    $base = eylan_public_base($panel); // http://panel.example.com:8050

    // استخراج path+query
    $path = '';
    if (preg_match('#^https?://[^/]+(/.*)$#', $url, $m)) {
        $path = $m[1];
    } elseif (preg_match('#^https?://[^/]+$#', $url)) {
        $path = '/';
    } else {
        $path = '/' . ltrim($url, '/');
    }

    // اگر از قبل base درست بود
    if (str_starts_with($url, $base)) {
        return $url;
    }

    // هر دامنه mirzavalibot یا panel بدون پورت / با https اشتباه → base صحیح
    if (preg_match('#mirzavalibot#i', $url) || preg_match('#panel\.silentping\.ir#i', $url) || preg_match('#^https?://#i', $url)) {
        return $base . $path;
    }

    return $base . $path;
}

function eylan_vpn_server_host($panel = null)
{
    return eylan_public_host($panel);
}

function eylan_build_delivery($namepanel, $username, $protocol = null)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['subscription_url' => '', 'configs' => [], 'text' => '', 'user' => null, 'protocol' => 'multi', 'qr_payloads' => [], 'files' => []];
    }
    $protocol = $protocol !== null ? eylan_normalize_protocol($protocol) : eylan_resolve_protocol($panel, []);
    $user = eylan_get_user($namepanel, $username);
    $sub = eylan_get_sub($namepanel, $username);

    $sub_url = '';
    foreach (['sub_url', 'url', 'subscription_url'] as $k) {
        if (!empty($sub[$k]) && is_string($sub[$k])) {
            $sub_url = $sub[$k];
            break;
        }
    }
    if ($sub_url === '' && !empty($user['sub_url'])) {
        $sub_url = $user['sub_url'];
    }
    if ($sub_url !== '') {
        $sub_url = eylan_public_url($sub_url, $panel);
    }

    $server = eylan_vpn_server_host($panel);
    $files = []; // ['content'=>, 'name'=>, 'type'=>'ovpn'|'wg']
    $lines = ["✅ <b>سرویس آماده شد</b>", "یوزرنیم: <code>{$username}</code>", "نوع: <b>{$protocol}</b>"];

    $need_ovpn = in_array($protocol, ['openvpn', 'multi'], true);
    $need_l2tp = in_array($protocol, ['l2tp', 'multi'], true);
    $need_cisco = in_array($protocol, ['cisco', 'multi'], true);
    $need_wg = in_array($protocol, ['wireguard', 'multi'], true);

    if ($need_ovpn) {
        $lines[] = "\n📡 <b>OpenVPN</b>";
        if ($sub_url !== '') {
            $lines[] = "صفحه کاربری: <code>{$sub_url}</code>";
        }
        $n = 0;
        foreach (eylan_get_ovpn_all($namepanel, $username) as $item) {
            if (str_starts_with($item, 'RAWCFG:')) {
                $n++;
                $files[] = ['content' => substr($item, 7), 'name' => "{$username}_ovpn{$n}.ovpn", 'type' => 'ovpn'];
            } elseif (preg_match('#^https?://#i', $item)) {
                $n++;
                $files[] = ['content' => null, 'url' => $item, 'name' => "{$username}_ovpn{$n}.ovpn", 'type' => 'ovpn'];
            }
        }
        if ($n > 0) {
            $lines[] = "فایل‌های کانفیگ (.ovpn) جداگانه ارسال می‌شوند.";
        }
    }

    if ($need_l2tp) {
        $pass = $user['l2tp_password'] ?? $user['l2tp_pass'] ?? '';
        $psk = $user['ipsec_psk'] ?? $user['l2tp_psk'] ?? $user['psk'] ?? eylan_fetch_psk($panel);
        $lines[] = "\n🛡️ <b>L2TP / IPsec</b>";
        $lines[] = "یوزرنیم: <code>{$username}</code>";
        $lines[] = "سرور: <code>{$server}</code>";
        $lines[] = $pass !== '' ? "رمز عبور: <code>{$pass}</code>" : "رمز عبور: از پنل";
        $lines[] = "Shared Key (PSK): <code>" . ($psk !== '' ? $psk : '123456') . "</code>";
    }

    if ($need_cisco) {
        $pass = $user['cisco_password'] ?? $user['cisco_pass'] ?? '';
        $lines[] = "\n🌐 <b>Cisco AnyConnect</b>";
        $lines[] = "یوزرنیم: <code>{$username}</code>";
        $lines[] = "سرور: <code>{$server}</code>";
        $lines[] = $pass !== '' ? "رمز عبور: <code>{$pass}</code>" : "رمز عبور: از پنل";
    }

    if ($need_wg) {
        $lines[] = "\n🧩 <b>WireGuard</b>";
        $n = 0;
        foreach (eylan_get_wg_all_files($namepanel, $username, is_array($user) ? $user : null) as $item) {
            if (str_starts_with($item, 'RAWCFG:')) {
                $n++;
                $files[] = ['content' => substr($item, 7), 'name' => "{$username}_wg{$n}.conf", 'type' => 'wg'];
            } elseif (preg_match('#^https?://#i', $item)) {
                $n++;
                $files[] = ['content' => null, 'url' => $item, 'name' => "{$username}_wg{$n}.conf", 'type' => 'wg'];
            }
        }
        if (!empty($user['wg1_ip'])) {
            $lines[] = "IP: <code>{$user['wg1_ip']}</code>";
        }
        if ($n > 0) {
            $lines[] = "فایل‌ها و QR هر کانفیگ جداگانه ارسال می‌شوند.";
        }
        if ($sub_url !== '' && !$need_ovpn) {
            $lines[] = "صفحه کاربری: <code>{$sub_url}</code>";
        }
    }

    if ($sub_url !== '' && !$need_ovpn && !$need_wg) {
        $lines[] = "\nصفحه کاربری: <code>{$sub_url}</code>";
    }

    return [
        'subscription_url' => $sub_url,
        'configs' => [], // دیگر لینک اضافه در configs نریز
        'text' => implode("\n", $lines),
        'user' => $user,
        'protocol' => $protocol,
        'qr_payloads' => ($sub_url !== '') ? [$sub_url] : [],
        'files' => $files,
    ];
}

function eylan_to_datauser_output($namepanel, $username, $invoice = false)
{
    global $domainhosts;
    $user = eylan_get_user($namepanel, $username);
    if (!eylan_http_ok($user) && empty($user['username'])) {
        return ['status' => 'Unsuccessful', 'msg' => $user['message'] ?? $user['error'] ?? 'User not found'];
    }
    $delivery = eylan_build_delivery($namepanel, $username);
    $sub_url = $delivery['subscription_url'];
    if ($invoice != false && !empty($domainhosts) && is_array($invoice) && !empty($invoice['id_invoice'])) {
        $sub_url = "https://{$domainhosts}/sub/" . $invoice['id_invoice'];
    }
    $data_limit_gb = floatval($user['data_limit'] ?? 0);
    $data_limit_bytes = (int) round($data_limit_gb * 1024 * 1024 * 1024);
    $used = floatval($user['download_bytes'] ?? $user['used_traffic'] ?? $user['traffic_bytes'] ?? 0);
    if ($used > 0 && $used < 10000 && $data_limit_gb > 0) {
        $used = $used * 1024 * 1024 * 1024;
    }
    $expire = 0;
    if (!empty($user['expire'])) {
        $expire = is_numeric($user['expire']) ? (int) $user['expire'] : (int) strtotime($user['expire']);
    } elseif (!empty($user['expiry_date'])) {
        $expire = (int) strtotime($user['expiry_date']);
    }
    $status = 'active';
    if ((isset($user['is_active']) && !$user['is_active']) || !empty($user['is_disabled'])) {
        $status = 'disabled';
    }
    if ($expire > 0 && $expire < time()) {
        $status = 'expired';
    }
    return [
        'status' => $status,
        'username' => $user['username'] ?? $username,
        'data_limit' => $data_limit_bytes,
        'expire' => $expire ?: null,
        'online_at' => $user['online_at'] ?? null,
        'used_traffic' => (int) $used,
        'links' => $delivery['configs'],
        'subscription_url' => $sub_url,
        'sub_updated_at' => null,
        'sub_last_user_agent' => null,
        'uuid' => null,
        'data_limit_reset' => null,
        'eylan_text' => $delivery['text'],
        'eylan_protocol' => $delivery['protocol'],
    ];
}



/**
 * ارسال مستقیم کانفیگ/رمز به کاربر تلگرام یا بله — مستقل از sendMessageService میرزا
 */

function eylan_lock($key)
{
    $dir = sys_get_temp_dir() . '/mv_eylan_lock';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $f = $dir . '/' . md5($key) . '.lock';
    if (is_file($f) && (time() - filemtime($f)) < 180) {
        error_log('eylan_lock SKIP ' . $key);
        return false;
    }
    @file_put_contents($f, (string) time());
    return true;
}


/**
 * یک پیام واحد موفقیت برای ایلان (به‌جای afterPay + پیام دوم)
 */
function eylan_format_success_message($username, $product_name, $location, $days, $volume, $protocol, $sub_url, $namepanel = null, $create_body = null, $time_unit = 'روز')
{
    $protocol = eylan_normalize_protocol($protocol);
    $sub_url = $sub_url ? eylan_public_url($sub_url) : '';
    $icons = [
        'openvpn' => '📡 OpenVPN',
        'wireguard' => '🧩 WireGuard',
        'l2tp' => '🛡️ L2TP / IPsec',
        'cisco' => '🌐 Cisco AnyConnect',
        'multi' => '🛰️ Multi',
    ];
    $proto_line = $icons[$protocol] ?? ('🛰️ ' . $protocol);

    $time_unit = in_array($time_unit, ['ساعت', 'روز', 'hour', 'hours', 'day', 'days'], true) ? $time_unit : 'روز';
    if (in_array($time_unit, ['hour', 'hours'], true)) {
        $time_unit = 'ساعت';
    }
    if (in_array($time_unit, ['day', 'days'], true)) {
        $time_unit = 'روز';
    }

    $days_label = (strval($days) === '0' || $days === 0 || $days === 'نامحدود') ? 'نامحدود' : $days;
    $vol_label = (strval($volume) === '0' || $volume === 0 || $volume === 'نامحدود') ? 'نامحدود' : $volume;
    $time_line = ($days_label === 'نامحدود') ? '⏳ مدت زمان: نامحدود' : "⏳ مدت زمان: {$days_label} {$time_unit}";

    $lines = [];
    $lines[] = "✅ <b>سرویس با موفقیت ایجاد شد</b>";
    $lines[] = "";
    $lines[] = $proto_line;
    $lines[] = "";
    $lines[] = "👤 نام کاربری سرویس : <code>{$username}</code>";
    $lines[] = "🌿 نام سرویس: {$product_name}";
    $lines[] = "🇺🇳 لوکیشن: {$location}";
    $lines[] = $time_line;
    $lines[] = "🗜 حجم سرویس: {$vol_label} گیگابایت";
    if (is_array($create_body) && array_key_exists('max_clients', $create_body)) {
        $mc = intval($create_body['max_clients']);
        $lines[] = "👥 کاربر همزمان: " . ($mc <= 0 ? 'نامحدود' : (string)$mc);
    }

    // برای L2TP/Cisco/Multi یوزرنیم فقط داخل بلوک مشخصات می‌آید (تکراری نباشد)
    if (!in_array($protocol, ['l2tp', 'cisco', 'multi'], true)) {
        $lines[] = "";
        $lines[] = "یوزرنیم: <code>{$username}</code>";
    }

    // L2TP / Cisco credentials
    if (in_array($protocol, ['l2tp', 'multi', 'cisco'], true) && $namepanel) {
        $panel = eylan_get_panel($namepanel);
        $user = eylan_get_user($namepanel, $username);
        if (!is_array($user)) {
            $user = [];
        }
        $server = eylan_vpn_server_host($panel);
        if (in_array($protocol, ['l2tp', 'multi'], true)) {
            $pass = $user['l2tp_password'] ?? $user['l2tp_pass'] ?? ($create_body['l2tp_password'] ?? '');
            $psk = $user['ipsec_psk'] ?? $user['l2tp_psk'] ?? $user['psk'] ?? eylan_fetch_psk($panel);
            $lines[] = "";
            $lines[] = "🛡️ <b>L2TP / IPsec</b>";
            $lines[] = "یوزرنیم: <code>{$username}</code>";
            $lines[] = "سرور: <code>{$server}</code>";
            $lines[] = $pass !== '' ? "رمز عبور: <code>{$pass}</code>" : "رمز عبور: از پنل";
            $lines[] = "Shared Key (PSK): <code>" . ($psk !== '' ? $psk : '123456') . "</code>";
        }
        if (in_array($protocol, ['cisco', 'multi'], true)) {
            $pass = $user['cisco_password'] ?? $user['cisco_pass'] ?? ($create_body['cisco_password'] ?? '');
            $lines[] = "";
            $lines[] = "🌐 <b>Cisco AnyConnect</b>";
            $lines[] = "یوزرنیم: <code>{$username}</code>";
            $lines[] = "سرور: <code>{$server}</code>";
            $lines[] = $pass !== '' ? "رمز عبور: <code>{$pass}</code>" : "رمز عبور: از پنل";
        }
    }

    if ($sub_url !== '') {
        $lines[] = "";
        $lines[] = "صفحه کاربری:";
        $lines[] = "<code>{$sub_url}</code>";
    }

    if (in_array($protocol, ['openvpn', 'multi'], true)) {
        $lines[] = "";
        $lines[] = "فایل‌های کانفیگ (.ovpn) جداگانه ارسال می‌شوند.";
    }
    if (in_array($protocol, ['wireguard', 'multi'], true)) {
        $lines[] = "";
        $lines[] = "فایل و QR وایرگارد جداگانه ارسال می‌شوند.";
    }

    $lines[] = "";
    $lines[] = "🧑‍🦯 شما میتوانید شیوه اتصال را با فشردن دکمه زیر و انتخاب سیستم عامل خود را دریافت کنید";

    return implode("\n", $lines);
}

/** سازگاری با کد قبلی */
function eylan_credentials_append($text, $namepanel, $username, $protocol, $create_body = null)
{
    // دیگر به afterPay چیزی اضافه نکن — پیام واحد از eylan_format_success_message می‌آید
    return $text;
}

function eylan_send_to_user($chat_id, $namepanel, $username, $protocol = null, $create_body = null)
{
    if (!eylan_lock("files|" . $username . "|" . $namepanel)) {
        return null;
    }
    usleep(1500000); // صبر بیشتر برای ساخت فایل‌های WG/OVPN
    $delivery = eylan_build_delivery($namepanel, $username, $protocol);

    // پیام متنی اینجا ارسال نمی‌شود — فقط فایل (پیام afterPay از function.php می‌آید)
    // بدون QR صفحه کاربری — فقط فایل کانفیگ (+ QR برای WireGuard)
    $cwd = defined('BOT_ROOT') ? BOT_ROOT : sys_get_temp_dir();

    $fi = 0;
    foreach ($delivery['files'] as $f) {
        $fi++;
        $content = $f['content'] ?? null;
        if ($content === null && !empty($f['url'])) {
            $ch = curl_init($f['url']);
            // برای دانلود از API ممکن است نیاز به همان دامنه داخلی باشد
            $dl = $f['url'];
            // اگر public بود و شکست خورد، از url_panel هم امتحان می‌کنیم
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $bin = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!($code >= 200 && $code < 300 && is_string($bin) && strlen($bin) > 30)) {
                // retry با دامنه API پنل
                $panel = eylan_get_panel($namepanel);
                if ($panel && !empty($panel['url_panel'])) {
                    $path = parse_url($f['url'], PHP_URL_PATH);
                    $q = parse_url($f['url'], PHP_URL_QUERY);
                    $internal = rtrim($panel['url_panel'], '/') . ($path ?: '') . ($q ? ('?' . $q) : '');
                    $ch = curl_init($internal);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 25,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTPHEADER => ['X-API-KEY: ' . ($panel['password_panel'] ?? '')],
                    ]);
                    $bin = curl_exec($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                }
            }
            if ($code >= 200 && $code < 300 && is_string($bin) && strlen($bin) > 30) {
                $content = $bin;
            }
        }
        if ($content === null || $content === '') {
            continue;
        }
        $fname = $f['name'] ?? ("config_{$fi}.txt");
        if (($f['type'] ?? '') === 'wg' && preg_match('/Endpoint\s*=\s*[^:\s]+:(\d+)/i', $content, $em)) {
            $fname = $username . '_wg_' . $em[1] . '.conf';
            $caption = 'WireGuard :' . $em[1];
        } else {
            $caption = (($f['type'] ?? '') === 'wg') ? "WireGuard #{$fi}" : "OpenVPN #{$fi}";
        }
        $path = rtrim($cwd, '/') . '/' . $fname;
        file_put_contents($path, $content);
        telegram('senddocument', [
            'chat_id' => $chat_id,
            'document' => new CURLFile($path, 'application/octet-stream', $fname),
            'caption' => $caption,
        ]);
        // QR فقط برای کانفیگ WG (محتوای conf)
        if (($f['type'] ?? '') === 'wg' && function_exists('createqrcode')) {
            try {
                $urlimage = rtrim($cwd, '/') . "/eylan_wgqr_{$chat_id}_{$fi}.png";
                $qr = createqrcode($content);
                file_put_contents($urlimage, $qr->getString());
                telegram('sendphoto', [
                    'chat_id' => $chat_id,
                    'photo' => new CURLFile($urlimage),
                    'caption' => "QR WireGuard #{$fi}",
                ]);
                @unlink($urlimage);
            } catch (Throwable $e) {
                error_log('eylan wg qr: ' . $e->getMessage());
            }
        }
        @unlink($path);
    }

    error_log('eylan_send_to_user done chat=' . $chat_id . ' files=' . count($delivery['files']));
    return $delivery;
}


/**
 * API helpers extras for Eylan Panel (only if not already defined)
 */
if (!function_exists('eylan_revoke_user')) {
    function eylan_revoke_user($namepanel, $username)
    {
        $panel = eylan_get_panel($namepanel);
        if (!$panel) {
            return ['error' => 'panel not found', 'http_code' => 0];
        }
        return eylan_request('POST', '/api/v1/users/' . rawurlencode($username) . '/revoke', $panel);
    }
}
if (!function_exists('eylan_list_users')) {
    function eylan_list_users($namepanel)
    {
        $panel = eylan_get_panel($namepanel);
        if (!$panel) {
            return ['error' => 'panel not found', 'http_code' => 0];
        }
        return eylan_request('GET', '/api/v1/users/list_all', $panel);
    }
}
if (!function_exists('eylan_hwid_devices')) {
    function eylan_hwid_devices($namepanel, $username)
    {
        $panel = eylan_get_panel($namepanel);
        if (!$panel) {
            return ['error' => 'panel not found', 'http_code' => 0];
        }
        return eylan_request('GET', '/api/v1/users/' . rawurlencode($username) . '/hwid_devices', $panel);
    }
}
if (!function_exists('eylan_hwid_reset')) {
    function eylan_hwid_reset($namepanel, $username)
    {
        $panel = eylan_get_panel($namepanel);
        if (!$panel) {
            return ['error' => 'panel not found', 'http_code' => 0];
        }
        return eylan_request('POST', '/api/v1/users/' . rawurlencode($username) . '/hwid_devices/reset', $panel);
    }
}

} // guard

function eylan_extend_user($namepanel, $username, $data_limit_gb, $days)
{
    $panel = eylan_get_panel($namepanel);
    if (!$panel) {
        return ['status' => false, 'msg' => 'panel not found'];
    }
    $body = [
        'data_limit' => round(floatval($data_limit_gb), 2),
        'data_limit_unit' => 'GB',
        'activation_type' => 'fixed_date',
    ];
    if ((int) $days > 0) {
        $body['expiry_days'] = (int) $days;
    }
    $result = eylan_request('PUT', '/api/v1/users/' . rawurlencode($username), $panel, $body);
    if (!eylan_http_ok($result)) {
        $msg = $result['message'] ?? $result['error'] ?? ('HTTP ' . ($result['http_code'] ?? 0));
        return ['status' => false, 'msg' => $msg, 'raw' => $result];
    }
    eylan_request('POST', '/api/v1/users/' . rawurlencode($username) . '/reset_traffic', $panel);
    return ['status' => true, 'msg' => 'ok', 'raw' => $result];
}

