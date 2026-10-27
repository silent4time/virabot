<?php
/* =====================================================================
 * Virabot 5.7.0 — Panel account sync (central ownership registry)
 *
 * panel_accounts records, for every username on a panel, who owns it
 * (a bot install or a website) and which user it belongs to. One install
 * can be the HUB (exposes sync_api.php); other bots / websites are CLIENTS
 * that call the hub with a token. Without a hub everything is local.
 *
 * Same file lives in patch/panel_sync.php and base/panel_sync.php.
 * Loaded from function.php when present. No output on load.
 * ===================================================================== */
if (!function_exists('ps_migrate')) {

define('PS_RESERVE_TTL', 900);
define('PS_RATE_LIMIT_PER_MIN', 600);
define('PS_HTTP_TIMEOUT', 5);
define('PS_SYNC_PAGE', 200);
define('PS_SYNC_MAX_PAGES', 250);
define('PS_REMOVED_STATUSES', "'removebyuser','removebyadmin','removedbyadmin','removeTime','removevolume','removedbypanel','Unsuccessful','unpaid'");

function ps_t($key, $default)
{
    global $textbotlang;
    $v = $textbotlang['psync'][$key] ?? null;
    return (is_string($v) && $v !== '') ? $v : $default;
}

function ps_log($msg)
{
    error_log('[panel_sync] ' . $msg);
}

function ps_migrate($pdo = null)
{
    if ($pdo === null) {
        global $pdo;
    }
    if (!$pdo) {
        return false;
    }
    $cs = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $sql = [
        "CREATE TABLE IF NOT EXISTS panel_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            panel_key VARCHAR(191) $cs NOT NULL,
            panel_url VARCHAR(500) $cs NULL,
            username VARCHAR(191) $cs NOT NULL,
            owner_type VARCHAR(10) $cs NULL,
            owner_id VARCHAR(100) $cs NULL,
            owner_user_id VARCHAR(100) $cs NULL,
            source VARCHAR(30) $cs NULL,
            status VARCHAR(30) $cs NULL,
            miss_count INT NOT NULL DEFAULT 0,
            last_seen INT NULL,
            created_at INT NULL,
            updated_at INT NULL,
            UNIQUE KEY uq_panel_user (panel_key, username),
            KEY idx_owner (owner_type, owner_id)
        ) ENGINE=InnoDB DEFAULT $cs",
        "CREATE TABLE IF NOT EXISTS panel_account_keys (
            key_hash CHAR(64) NOT NULL PRIMARY KEY,
            panel_key VARCHAR(191) $cs NOT NULL,
            username VARCHAR(191) $cs NOT NULL,
            updated_at INT NULL,
            KEY idx_pk_user (panel_key, username)
        ) ENGINE=InnoDB DEFAULT $cs",
        "CREATE TABLE IF NOT EXISTS sync_clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) $cs NOT NULL,
            owner_type VARCHAR(10) $cs NOT NULL DEFAULT 'bot',
            token_hash CHAR(64) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            rl_window INT NOT NULL DEFAULT 0,
            rl_count INT NOT NULL DEFAULT 0,
            last_used INT NULL,
            created_at INT NULL,
            UNIQUE KEY uq_token (token_hash),
            UNIQUE KEY uq_name (name)
        ) ENGINE=InnoDB DEFAULT $cs",
        "CREATE TABLE IF NOT EXISTS sync_settings (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v TEXT $cs NULL
        ) ENGINE=InnoDB DEFAULT $cs",
    ];
    $ok = true;
    foreach ($sql as $q) {
        try {
            $pdo->exec($q);
        } catch (Throwable $e) {
            $ok = false;
            ps_log('migrate: ' . $e->getMessage());
        }
    }
    try {
        $c = $pdo->query("SHOW COLUMNS FROM panel_accounts LIKE 'miss_count'");
        if ($c && $c->rowCount() == 0) {
            $pdo->exec("ALTER TABLE panel_accounts ADD miss_count INT NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {
        ps_log('migrate cols: ' . $e->getMessage());
    }
    if (ps_setting('instance_id') === null) {
        ps_setting_set('instance_id', 'bot-' . bin2hex(random_bytes(4)));
    }
    return $ok;
}

function ps_setting($k, $default = null)
{
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT v FROM sync_settings WHERE k = ?");
        $st->execute([$k]);
        $v = $st->fetchColumn();
    } catch (Throwable $e) {
        return $default;
    }
    return ($v === false || $v === null) ? $default : $v;
}

function ps_setting_set($k, $v)
{
    global $pdo;
    try {
        $st = $pdo->prepare("INSERT INTO sync_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
        $st->execute([$k, $v]);
        return true;
    } catch (Throwable $e) {
        ps_log('setting_set: ' . $e->getMessage());
        return false;
    }
}

/** local | hub | client (client without url/token falls back to local) */
function ps_mode()
{
    $m = ps_setting('mode', 'local');
    if ($m === 'client' && (trim((string) ps_setting('hub_url', '')) === '' || trim((string) ps_setting('hub_token', '')) === '')) {
        return 'local';
    }
    return in_array($m, ['local', 'hub', 'client'], true) ? $m : 'local';
}

function ps_enabled()
{
    return ps_setting('enabled', 'on') !== 'off';
}

function ps_self_ctx()
{
    return ['owner_type' => 'bot', 'owner_id' => (string) ps_setting('instance_id', 'local'), 'admin' => true];
}

function ps_panel_key($url)
{
    $url = trim((string) $url);
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return strtolower(rtrim($url, '/'));
    }
    $scheme = strtolower($p['scheme'] ?? 'https');
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = rtrim($p['path'] ?? '', '/');
    return $scheme . '://' . strtolower($p['host']) . $port . $path;
}

function ps_panel_by_name($name)
{
    $p = select("marzban_panel", "*", "name_panel", $name, "select");
    return is_array($p) ? $p : null;
}

function ps_hash_key($kind, $value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if ($kind === 'uuid') {
        $value = strtolower($value);
    }
    return hash('sha256', $kind . ':' . $value);
}

/** last meaningful path segment of a subscription URL */
function ps_sub_token_from_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null || $path === false) {
        $path = $url;
    }
    $segs = array_values(array_filter(explode('/', $path), 'strlen'));
    if (!$segs) {
        return null;
    }
    $last = end($segs);
    if (count($segs) >= 2 && in_array(strtolower($last), ['info', 'usage', 'v2ray', 'clash', 'clash-meta', 'sing-box', 'outline', 'v2ray-json', 'links'], true)) {
        $last = $segs[count($segs) - 2];
    }
    return strlen($last) >= 6 ? $last : null;
}

/** candidate key hashes from pasted text (sub link / vless / vmess / trojan / ss / bare uuid) */
function ps_keys_from_text($text)
{
    $keys = [];
    $text = trim((string) $text);
    if ($text === '') {
        return $keys;
    }
    if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $m)) {
        foreach ($m[0] as $u) {
            $tok = ps_sub_token_from_url($u);
            if ($tok) {
                $keys[] = ps_hash_key('sub', $tok);
            }
        }
    }
    if (preg_match_all('#\b(?:vless|trojan|tuic|hysteria2|hy2)://([^@\s]+)@#i', $text, $m)) {
        foreach ($m[1] as $id) {
            $keys[] = ps_hash_key('uuid', rawurldecode($id));
        }
    }
    if (preg_match_all('#\bvmess://([A-Za-z0-9+/=_-]+)#', $text, $m)) {
        foreach ($m[1] as $b) {
            $j = json_decode((string) base64_decode(strtr($b, '-_', '+/')), true);
            if (is_array($j) && !empty($j['id'])) {
                $keys[] = ps_hash_key('uuid', $j['id']);
            }
        }
    }
    if (preg_match_all('#\bss://([A-Za-z0-9+/=_-]+)@#', $text, $m)) {
        foreach ($m[1] as $b) {
            $dec = (string) base64_decode(strtr($b, '-_', '+/'));
            if ($dec !== '' && strpos($dec, ':') !== false) {
                $keys[] = ps_hash_key('uuid', substr($dec, strpos($dec, ':') + 1));
            }
        }
    }
    if (preg_match_all('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', $text, $m)) {
        foreach ($m[0] as $id) {
            $keys[] = ps_hash_key('uuid', $id);
        }
    }
    return array_values(array_unique(array_filter($keys)));
}

/* =============================================================== CORE ===
 * Operate on the LOCAL table with a requester ctx
 * ['owner_type'=>'bot|site','owner_id'=>string,'admin'=>bool].
 * Return [httpCode, array]. Used by the hub API and by local/hub mode.
 * ===================================================================== */
function ps_row($panelKey, $username)
{
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM panel_accounts WHERE panel_key = ? AND username = ?");
    $st->execute([$panelKey, $username]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function ps_row_by_key($keyHash)
{
    global $pdo;
    $st = $pdo->prepare("SELECT a.* FROM panel_account_keys k JOIN panel_accounts a ON a.panel_key = k.panel_key AND a.username = k.username WHERE k.key_hash = ?");
    $st->execute([$keyHash]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function ps_is_owner($row, $ctx)
{
    return $row && (string) $row['owner_type'] === (string) $ctx['owner_type'] && (string) $row['owner_id'] === (string) $ctx['owner_id'];
}

function ps_is_unowned($row)
{
    return $row && ($row['owner_id'] === null || (string) $row['owner_id'] === '');
}

function ps_public_row($row, $ctx)
{
    if (!$row) {
        return null;
    }
    $mine = ps_is_owner($row, $ctx);
    return [
        'panel_key' => $row['panel_key'],
        'username' => $row['username'],
        'status' => $row['status'],
        'owner_type' => $row['owner_type'],
        'owned' => !ps_is_unowned($row),
        'owned_by_you' => $mine,
        'owner_user_id' => ($mine || !empty($ctx['admin'])) ? $row['owner_user_id'] : null,
        'owner_id' => !empty($ctx['admin']) ? $row['owner_id'] : null,
        'last_seen' => $row['last_seen'] !== null ? (int) $row['last_seen'] : null,
    ];
}

function ps_valid_username($u)
{
    return is_string($u) && preg_match('/^[A-Za-z0-9_.@\-]{1,191}$/', $u);
}

function ps_norm_input(&$p)
{
    $p['panel_url'] = isset($p['panel_url']) ? (string) $p['panel_url'] : null;
    $p['panel_key'] = ps_panel_key($p['panel_url'] ?? ($p['panel_key'] ?? ''));
    $p['username'] = trim((string) ($p['username'] ?? ''));
    $p['owner_user_id'] = (isset($p['owner_user_id']) && $p['owner_user_id'] !== '' && $p['owner_user_id'] !== null) ? substr((string) $p['owner_user_id'], 0, 100) : null;
    if ($p['panel_key'] === '' || strlen($p['panel_key']) > 191) {
        return 'invalid panel_url';
    }
    if ($p['username'] !== '' && !ps_valid_username($p['username'])) {
        return 'invalid username';
    }
    return null;
}

function ps_core_reserve($ctx, $p)
{
    global $pdo;
    if ($e = ps_norm_input($p)) return [400, ['ok' => false, 'error' => $e]];
    if ($p['username'] === '') return [400, ['ok' => false, 'error' => 'username required']];
    $now = time();
    $row = ps_row($p['panel_key'], $p['username']);
    if (!$row) {
        try {
            $st = $pdo->prepare("INSERT INTO panel_accounts (panel_key, panel_url, username, owner_type, owner_id, owner_user_id, source, status, created_at, updated_at) VALUES (?,?,?,?,?,?,'reserve','reserved',?,?)");
            $st->execute([$p['panel_key'], $p['panel_url'], $p['username'], $ctx['owner_type'], $ctx['owner_id'], $p['owner_user_id'], $now, $now]);
            return [200, ['ok' => true, 'reserved' => true]];
        } catch (Throwable $e) {
            $row = ps_row($p['panel_key'], $p['username']);
            if (!$row) return [500, ['ok' => false, 'error' => 'db error']];
        }
    }
    if (ps_is_owner($row, $ctx) && $row['status'] === 'reserved') {
        $pdo->prepare("UPDATE panel_accounts SET updated_at = ? WHERE id = ?")->execute([$now, $row['id']]);
        return [200, ['ok' => true, 'reserved' => true]];
    }
    if ($row['status'] === 'deleted' || ($row['status'] === 'reserved' && (int) $row['updated_at'] < $now - PS_RESERVE_TTL)) {
        $st = $pdo->prepare("UPDATE panel_accounts SET owner_type = ?, owner_id = ?, owner_user_id = ?, source = 'reserve', status = 'reserved', miss_count = 0, updated_at = ? WHERE id = ?");
        $st->execute([$ctx['owner_type'], $ctx['owner_id'], $p['owner_user_id'], $now, $row['id']]);
        $pdo->prepare("DELETE FROM panel_account_keys WHERE panel_key = ? AND username = ?")->execute([$p['panel_key'], $p['username']]);
        return [200, ['ok' => true, 'reserved' => true]];
    }
    return [409, ['ok' => false, 'error' => 'username taken', 'account' => ps_public_row($row, $ctx)]];
}

function ps_core_release($ctx, $p)
{
    global $pdo;
    if ($e = ps_norm_input($p)) return [400, ['ok' => false, 'error' => $e]];
    $row = ps_row($p['panel_key'], $p['username']);
    if ($row && $row['status'] === 'reserved' && ps_is_owner($row, $ctx)) {
        $pdo->prepare("DELETE FROM panel_accounts WHERE id = ?")->execute([$row['id']]);
        return [200, ['ok' => true, 'released' => true]];
    }
    return [200, ['ok' => true, 'released' => false]];
}

function ps_add_keys($panelKey, $username, $keys)
{
    global $pdo;
    if (!$keys || !is_array($keys)) return;
    $st = $pdo->prepare("INSERT INTO panel_account_keys (key_hash, panel_key, username, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE panel_key = VALUES(panel_key), username = VALUES(username), updated_at = VALUES(updated_at)");
    $now = time();
    foreach (array_slice($keys, 0, 20) as $k) {
        if (is_string($k) && preg_match('/^[0-9a-f]{64}$/', $k)) {
            $st->execute([$k, $panelKey, $username, $now]);
        }
    }
}

/** type: buy | renew | delete */
function ps_core_event($ctx, $p)
{
    global $pdo;
    if ($e = ps_norm_input($p)) return [400, ['ok' => false, 'error' => $e]];
    if ($p['username'] === '') return [400, ['ok' => false, 'error' => 'username required']];
    $type = (string) ($p['type'] ?? '');
    if (!in_array($type, ['buy', 'renew', 'delete'], true)) return [400, ['ok' => false, 'error' => 'invalid type']];
    $now = time();
    $row = ps_row($p['panel_key'], $p['username']);
    $keys = isset($p['keys']) && is_array($p['keys']) ? $p['keys'] : [];
    if ($type === 'buy' || ($type === 'renew' && !$row)) {
        if (!$row) {
            $st = $pdo->prepare("INSERT INTO panel_accounts (panel_key, panel_url, username, owner_type, owner_id, owner_user_id, source, status, last_seen, created_at, updated_at) VALUES (?,?,?,?,?,?,?,'active',?,?,?)");
            $st->execute([$p['panel_key'], $p['panel_url'], $p['username'], $ctx['owner_type'], $ctx['owner_id'], $p['owner_user_id'], $type, $now, $now, $now]);
        } elseif (ps_is_owner($row, $ctx) || ps_is_unowned($row) || $row['status'] === 'deleted' || ($row['status'] === 'reserved' && (int) $row['updated_at'] < $now - PS_RESERVE_TTL)) {
            $st = $pdo->prepare("UPDATE panel_accounts SET panel_url = COALESCE(?, panel_url), owner_type = ?, owner_id = ?, owner_user_id = COALESCE(?, owner_user_id), source = 'buy', status = 'active', miss_count = 0, last_seen = ?, updated_at = ? WHERE id = ?");
            $st->execute([$p['panel_url'], $ctx['owner_type'], $ctx['owner_id'], $p['owner_user_id'], $now, $now, $row['id']]);
        } else {
            return [409, ['ok' => false, 'error' => 'owned by another', 'account' => ps_public_row($row, $ctx)]];
        }
        ps_add_keys($p['panel_key'], $p['username'], $keys);
        return [200, ['ok' => true]];
    }
    if (!$row) {
        return [200, ['ok' => true, 'known' => false]];
    }
    if (!ps_is_owner($row, $ctx) && !ps_is_unowned($row) && empty($ctx['admin'])) {
        return [409, ['ok' => false, 'error' => 'owned by another', 'account' => ps_public_row($row, $ctx)]];
    }
    if ($type === 'renew') {
        $pdo->prepare("UPDATE panel_accounts SET status = 'active', miss_count = 0, last_seen = ?, updated_at = ?, owner_type = IF(owner_id IS NULL OR owner_id = '', ?, owner_type), owner_id = IF(owner_id IS NULL OR owner_id = '', ?, owner_id), owner_user_id = COALESCE(?, owner_user_id) WHERE id = ?")
            ->execute([$now, $now, $ctx['owner_type'], $ctx['owner_id'], $p['owner_user_id'], $row['id']]);
        ps_add_keys($p['panel_key'], $p['username'], $keys);
        return [200, ['ok' => true]];
    }
    $pdo->prepare("UPDATE panel_accounts SET status = 'deleted', updated_at = ? WHERE id = ?")->execute([$now, $row['id']]);
    $pdo->prepare("DELETE FROM panel_account_keys WHERE panel_key = ? AND username = ?")->execute([$p['panel_key'], $p['username']]);
    return [200, ['ok' => true]];
}

/** attach an existing account to the requester (by key_hash; by username only for admin/current owner) */
function ps_core_claim($ctx, $p)
{
    global $pdo;
    $row = null;
    if (!empty($p['key_hash'])) {
        if (!is_string($p['key_hash']) || !preg_match('/^[0-9a-f]{64}$/', $p['key_hash'])) return [400, ['ok' => false, 'error' => 'invalid key_hash']];
        $row = ps_row_by_key($p['key_hash']);
        if (!$row) return [404, ['ok' => false, 'error' => 'not found']];
        ps_norm_input($p);
    } else {
        if ($e = ps_norm_input($p)) return [400, ['ok' => false, 'error' => $e]];
        if ($p['username'] === '') return [400, ['ok' => false, 'error' => 'username or key_hash required']];
        $row = ps_row($p['panel_key'], $p['username']);
        if (!$row) return [404, ['ok' => false, 'error' => 'not found']];
        if (empty($ctx['admin']) && !ps_is_owner($row, $ctx)) return [403, ['ok' => false, 'error' => 'claim by username needs key_hash']];
    }
    if ($row['status'] === 'deleted') return [410, ['ok' => false, 'error' => 'account deleted', 'account' => ps_public_row($row, $ctx)]];
    $force = !empty($p['force']) && !empty($ctx['admin']);
    if (!ps_is_unowned($row) && !ps_is_owner($row, $ctx) && !$force) {
        return [409, ['ok' => false, 'error' => 'owned by another', 'account' => ps_public_row($row, $ctx)]];
    }
    $pdo->prepare("UPDATE panel_accounts SET owner_type = ?, owner_id = ?, owner_user_id = ?, source = 'claim', updated_at = ? WHERE id = ?")
        ->execute([$ctx['owner_type'], $ctx['owner_id'], $p['owner_user_id'], time(), $row['id']]);
    $row = ps_row($row['panel_key'], $row['username']);
    return [200, ['ok' => true, 'account' => ps_public_row($row, $ctx)]];
}

/** bulk upsert from a panel crawl; never changes ownership */
function ps_core_upsert($ctx, $p)
{
    global $pdo;
    if ($e = ps_norm_input($p)) return [400, ['ok' => false, 'error' => $e]];
    $items = isset($p['items']) && is_array($p['items']) ? $p['items'] : [];
    if (count($items) > 500) return [400, ['ok' => false, 'error' => 'max 500 items']];
    $now = time();
    $ins = $pdo->prepare("INSERT INTO panel_accounts (panel_key, panel_url, username, source, status, miss_count, last_seen, created_at, updated_at)
        VALUES (?,?,?,'panel_sync',?,0,?,?,?)
        ON DUPLICATE KEY UPDATE status = IF(status = 'reserved', 'active', VALUES(status)), panel_url = VALUES(panel_url), miss_count = 0, last_seen = VALUES(last_seen), updated_at = VALUES(updated_at)");
    $n = 0;
    foreach ($items as $it) {
        $u = trim((string) ($it['username'] ?? ''));
        if (!ps_valid_username($u)) continue;
        $status = substr(preg_replace('/[^a-z_]/', '', strtolower((string) ($it['status'] ?? 'active'))), 0, 30);
        $ins->execute([$p['panel_key'], $p['panel_url'], $u, $status !== '' ? $status : 'active', $now, $now, $now]);
        ps_add_keys($p['panel_key'], $u, isset($it['keys']) && is_array($it['keys']) ? $it['keys'] : []);
        $n++;
    }
    $missing = isset($p['missing']) && is_array($p['missing']) ? array_slice($p['missing'], 0, 5000) : [];
    $m = 0;
    if ($missing) {
        // second consecutive miss => deleted
        $st = $pdo->prepare("UPDATE panel_accounts SET status = IF(miss_count >= 1, 'deleted', status), miss_count = miss_count + 1, updated_at = ? WHERE panel_key = ? AND username = ? AND status NOT IN ('deleted','reserved')");
        foreach ($missing as $u) {
            $st->execute([$now, $p['panel_key'], (string) $u]);
            $m += $st->rowCount();
        }
    }
    return [200, ['ok' => true, 'upserted' => $n, 'missing_marked' => $m]];
}

function ps_core_lookup($ctx, $p)
{
    if (!empty($p['key_hash'])) {
        if (!is_string($p['key_hash']) || !preg_match('/^[0-9a-f]{64}$/', $p['key_hash'])) return [400, ['ok' => false, 'error' => 'invalid key_hash']];
        $row = ps_row_by_key($p['key_hash']);
    } else {
        if ($e = ps_norm_input($p)) return [400, ['ok' => false, 'error' => $e]];
        if ($p['username'] === '') return [400, ['ok' => false, 'error' => 'username or key_hash required']];
        $row = ps_row($p['panel_key'], $p['username']);
    }
    if (!$row) return [404, ['ok' => false, 'error' => 'not found']];
    return [200, ['ok' => true, 'account' => ps_public_row($row, $ctx)]];
}

function ps_core_list($ctx, $p)
{
    global $pdo;
    $limit = max(1, min(500, (int) ($p['limit'] ?? 100)));
    $after = max(0, (int) ($p['after_id'] ?? 0));
    $where = ["id > ?"];
    $args = [$after];
    if (!empty($p['panel_url'])) {
        $where[] = "panel_key = ?";
        $args[] = ps_panel_key($p['panel_url']);
    }
    if (!empty($p['status']) && is_string($p['status'])) {
        $where[] = "status = ?";
        $args[] = $p['status'];
    }
    if (!empty($p['only_mine']) || empty($ctx['admin'])) {
        $where[] = !empty($p['include_unowned'])
            ? "((owner_type = ? AND owner_id = ?) OR owner_id IS NULL OR owner_id = '')"
            : "(owner_type = ? AND owner_id = ?)";
        $args[] = $ctx['owner_type'];
        $args[] = $ctx['owner_id'];
    }
    if (!empty($p['owner_user_id'])) {
        $where[] = "owner_user_id = ?";
        $args[] = (string) $p['owner_user_id'];
    }
    $st = $pdo->prepare("SELECT * FROM panel_accounts WHERE " . implode(' AND ', $where) . " ORDER BY id ASC LIMIT " . (int) $limit);
    $st->execute($args);
    $out = [];
    $last = $after;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[] = ps_public_row($r, $ctx) + ['id' => (int) $r['id']];
        $last = (int) $r['id'];
    }
    return [200, ['ok' => true, 'items' => $out, 'next_after_id' => count($out) === $limit ? $last : null]];
}

function ps_dispatch($ctx, $action, $p)
{
    if (!is_array($p)) $p = [];
    switch ($action) {
        case 'ping':
            return [200, ['ok' => true, 'hub' => (string) ps_setting('instance_id', ''), 'you' => ['owner_type' => $ctx['owner_type'], 'owner_id' => $ctx['owner_id']], 'time' => time()]];
        case 'reserve':
            return ps_core_reserve($ctx, $p);
        case 'release':
            return ps_core_release($ctx, $p);
        case 'event':
            return ps_core_event($ctx, $p);
        case 'claim':
            return ps_core_claim($ctx, $p);
        case 'upsert':
            return ps_core_upsert($ctx, $p);
        case 'lookup':
            return ps_core_lookup($ctx, $p);
        case 'list':
            return ps_core_list($ctx, $p);
    }
    return [400, ['ok' => false, 'error' => 'unknown action']];
}

/* ======================================================= CLIENT/FRONT ===
 * ps_call(): hub (client mode) or local. httpCode 0 = hub unreachable.
 * ===================================================================== */
function ps_http_hub($action, $p)
{
    $url = rtrim((string) ps_setting('hub_url', ''), '/');
    $token = (string) ps_setting('hub_token', '');
    if ($url === '' || $token === '') {
        return [0, ['ok' => false, 'error' => 'hub not configured']];
    }
    if (stripos($url, 'https://') !== 0) {
        return [0, ['ok' => false, 'error' => 'hub_url must be https']];
    }
    if (!preg_match('#/sync_api\.php$#', $url)) {
        $url .= '/sync_api.php';
    }
    $ch = curl_init($url . '?action=' . rawurlencode($action));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => PS_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POSTFIELDS => json_encode($p, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code === 0) {
        ps_log("hub unreachable ($action): $err");
        return [0, ['ok' => false, 'error' => 'hub unreachable']];
    }
    $j = json_decode((string) $body, true);
    if (!is_array($j)) {
        ps_log("hub bad response ($action) http=$code");
        return [0, ['ok' => false, 'error' => 'bad hub response']];
    }
    return [$code, $j];
}

function ps_call($action, $p)
{
    if (ps_mode() === 'client') {
        return ps_http_hub($action, $p);
    }
    try {
        return ps_dispatch(ps_self_ctx(), $action, $p);
    } catch (Throwable $e) {
        ps_log("local $action: " . $e->getMessage());
        return [0, ['ok' => false, 'error' => 'local error']];
    }
}

/* ================================================================ HOOKS ===
 * A definite 409 on reserve blocks creation; anything else (hub down,
 * tables missing) fails OPEN so selling never stops. Errors are logged.
 * ===================================================================== */
function ps_reserve($panelName, $username, $ownerUserId = null)
{
    if (!ps_enabled()) return true;
    $panel = ps_panel_by_name($panelName);
    if (!$panel || empty($panel['url_panel']) || (string) $username === '') return true;
    list($code, $res) = ps_call('reserve', ['panel_url' => $panel['url_panel'], 'username' => (string) $username, 'owner_user_id' => $ownerUserId]);
    if ($code === 409) {
        ps_log("reserve conflict {$panelName}/{$username}");
        return false;
    }
    return true;
}

function ps_report($type, $panelName, $username, $ownerUserId = null, $keys = [])
{
    if (!ps_enabled()) return;
    $panel = ps_panel_by_name($panelName);
    if (!$panel || empty($panel['url_panel']) || (string) $username === '') return;
    $p = ['panel_url' => $panel['url_panel'], 'username' => (string) $username, 'owner_user_id' => $ownerUserId];
    if ($type === 'release') {
        ps_call('release', $p);
        return;
    }
    $p['type'] = $type;
    $p['keys'] = $keys;
    list($code, $res) = ps_call('event', $p);
    if ($code !== 200) {
        ps_log("event $type {$panelName}/{$username} -> $code " . ($res['error'] ?? ''));
    }
}

/** key hashes from a createUser()/DataUser() result */
function ps_keys_from_output($out)
{
    $keys = [];
    if (!is_array($out)) return $keys;
    if (!empty($out['subscription_url']) && is_string($out['subscription_url'])) {
        $t = ps_sub_token_from_url($out['subscription_url']);
        if ($t) $keys[] = ps_hash_key('sub', $t);
    }
    if (!empty($out['uuid']) && is_string($out['uuid'])) {
        $keys[] = ps_hash_key('uuid', $out['uuid']);
    }
    foreach (['configs', 'links'] as $f) {
        if (!empty($out[$f]) && is_array($out[$f])) {
            $keys = array_merge($keys, ps_keys_from_text(implode("\n", array_filter($out[$f], 'is_string'))));
        }
    }
    return array_values(array_unique(array_filter($keys)));
}

/* ============================================================ PANEL CRAWL */
function ps_keys_marzban_user($u)
{
    $keys = [];
    if (!empty($u['subscription_url']) && is_string($u['subscription_url'])) {
        $t = ps_sub_token_from_url($u['subscription_url']);
        if ($t) $keys[] = ps_hash_key('sub', $t);
    }
    if (!empty($u['proxies']) && is_array($u['proxies'])) {
        foreach ($u['proxies'] as $cfg) {
            if (!is_array($cfg)) continue;
            foreach (['id', 'password'] as $f) {
                if (!empty($cfg[$f]) && is_string($cfg[$f])) $keys[] = ps_hash_key('uuid', $cfg[$f]);
            }
        }
    }
    if (!empty($u['key']) && is_string($u['key'])) { // marzneshin
        $keys[] = ps_hash_key('uuid', $u['key']);
        if (preg_match('/^[0-9a-f]{32}$/i', $u['key'])) {
            $k = strtolower($u['key']);
            $keys[] = ps_hash_key('uuid', substr($k, 0, 8) . '-' . substr($k, 8, 4) . '-' . substr($k, 12, 4) . '-' . substr($k, 16, 4) . '-' . substr($k, 20));
        }
    }
    if (!empty($u['links']) && is_array($u['links'])) {
        $keys = array_merge($keys, ps_keys_from_text(implode("\n", array_filter($u['links'], 'is_string'))));
    }
    return array_values(array_unique(array_filter($keys)));
}

function ps_http_get_json($url, $bearer, $timeout = 25)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        // panel APIs are commonly self-signed; matches how the existing panel code calls them
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $bearer],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    return [$code, is_array($j) ? $j : null];
}

function ps_ts($v)
{
    if ($v === null || $v === '' || $v === false) return 0;
    if (is_numeric($v)) return (int) $v;
    $t = strtotime((string) $v);
    return $t ? (int) $t : 0;
}

/**
 * ['ok'=>bool,'complete'=>bool,'users'=>[username=>['status','keys','data_limit','expire','created']],'error'?,'unsupported'?]
 * complete=true only if every page was read (required before marking deletions)
 */
function ps_crawl_panel($panel)
{
    $type = (string) ($panel['type'] ?? '');
    $users = [];
    $complete = false;
    $fail = function ($err) use (&$users) {
        return ['ok' => count($users) > 0, 'complete' => false, 'users' => $users, 'error' => $err];
    };
    if ($type === 'marzban' || $type === 'rebecca') {
        if ($type === 'marzban') {
            if (!function_exists('token_panel')) return $fail('token_panel missing');
            $tok = token_panel($panel['code_panel']);
            $bearer = is_array($tok) ? (string) ($tok['access_token'] ?? '') : '';
        } else {
            $bearer = (string) $panel['password_panel'];
        }
        if ($bearer === '') return $fail('no token');
        $base = rtrim($panel['url_panel'], '/') . '/api/users';
        $total = null;
        for ($pg = 0; $pg < PS_SYNC_MAX_PAGES; $pg++) {
            list($code, $j) = ps_http_get_json($base . '?offset=' . ($pg * PS_SYNC_PAGE) . '&limit=' . PS_SYNC_PAGE, $bearer);
            if ($code !== 200 || !is_array($j) || !isset($j['users']) || !is_array($j['users'])) return $fail("http $code");
            if (isset($j['total'])) $total = (int) $j['total'];
            foreach ($j['users'] as $u) {
                if (!is_array($u) || empty($u['username'])) continue;
                $s = strtolower((string) ($u['status'] ?? 'active'));
                $users[(string) $u['username']] = [
                    'status' => in_array($s, ['active', 'disabled', 'limited', 'expired', 'on_hold'], true) ? $s : 'active',
                    'keys' => ps_keys_marzban_user($u),
                    'data_limit' => (int) ($u['data_limit'] ?? 0),
                    'expire' => ps_ts($u['expire'] ?? 0),
                    'created' => ps_ts($u['created_at'] ?? 0),
                ];
            }
            if (count($j['users']) < PS_SYNC_PAGE || ($total !== null && count($users) >= $total)) {
                $complete = ($total === null) || count($users) >= $total;
                break;
            }
        }
        return ['ok' => true, 'complete' => $complete, 'users' => $users];
    }
    if ($type === 'marzneshin') {
        if (!function_exists('token_panelm')) return $fail('token_panelm missing');
        $tok = token_panelm($panel['code_panel']);
        $bearer = is_array($tok) ? (string) ($tok['access_token'] ?? '') : '';
        if ($bearer === '') return $fail('no token');
        $base = rtrim($panel['url_panel'], '/') . '/api/users';
        $total = null;
        for ($pg = 1; $pg <= PS_SYNC_MAX_PAGES; $pg++) {
            list($code, $j) = ps_http_get_json($base . '?page=' . $pg . '&size=' . PS_SYNC_PAGE, $bearer);
            if ($code !== 200 || !is_array($j) || !isset($j['items']) || !is_array($j['items'])) return $fail("http $code");
            if (isset($j['total'])) $total = (int) $j['total'];
            foreach ($j['items'] as $u) {
                if (!is_array($u) || empty($u['username'])) continue;
                $st = 'active';
                if (isset($u['enabled']) && !$u['enabled']) $st = 'disabled';
                elseif (!empty($u['expired'])) $st = 'expired';
                elseif (!empty($u['data_limit_reached'])) $st = 'limited';
                $users[(string) $u['username']] = [
                    'status' => $st,
                    'keys' => ps_keys_marzban_user($u),
                    'data_limit' => (int) ($u['data_limit'] ?? 0),
                    'expire' => ps_ts($u['expire_date'] ?? 0),
                    'created' => ps_ts($u['created_at'] ?? 0),
                ];
            }
            if (count($j['items']) < PS_SYNC_PAGE || ($total !== null && count($users) >= $total)) {
                $complete = ($total === null) || count($users) >= $total;
                break;
            }
        }
        return ['ok' => true, 'complete' => $complete, 'users' => $users];
    }
    if ($type === 'connectix') {
        if (!function_exists('connectix_list_clients') || !function_exists('connectix_http_ok')) return $fail('Connectix.php missing');
        $total = 0;
        for ($pg = 1; $pg <= PS_SYNC_MAX_PAGES; $pg++) {
            $res = connectix_list_clients($panel['name_panel'], ['page' => $pg, 'recordPerPage' => PS_SYNC_PAGE]);
            if (!connectix_http_ok($res)) return $fail((string) ($res['error'] ?? 'connectix error'));
            $total = (int) ($res['total_clients'] ?? 0);
            foreach ($res['clients'] as $c) {
                if (!is_array($c) || empty($c['username'])) continue;
                $keys = [];
                if (!empty($c['id']) && is_string($c['id'])) $keys[] = ps_hash_key('uuid', $c['id']);
                if (function_exists('connectix_client_uuid')) {
                    $id = connectix_client_uuid($c);
                    if (is_string($id) && $id !== '') $keys[] = ps_hash_key('uuid', $id);
                }
                if (function_exists('connectix_pick_subscription')) {
                    $sub = connectix_pick_subscription($c);
                    if (is_string($sub) && $sub !== '') {
                        $t = ps_sub_token_from_url($sub);
                        if ($t) $keys[] = ps_hash_key('sub', $t);
                    }
                }
                $disabled = (isset($c['is_active']) && !$c['is_active']) || (isset($c['enabled']) && !$c['enabled']);
                $users[(string) $c['username']] = [
                    'status' => $disabled ? 'disabled' : 'active',
                    'keys' => array_values(array_unique(array_filter($keys))),
                    'data_limit' => 0,
                    'expire' => 0,
                    'created' => ps_ts($c['created_at'] ?? 0),
                ];
            }
            if (empty($res['next_page'])) {
                $complete = ($total === 0 && count($users) === 0) || count($users) >= $total;
                break;
            }
        }
        return ['ok' => true, 'complete' => $complete, 'users' => $users];
    }
    return ['ok' => false, 'complete' => false, 'users' => [], 'error' => 'unsupported', 'unsupported' => true];
}

function ps_new_invoice_id()
{
    return bin2hex(random_bytes(5));
}

/** Sync one local panel: registry upsert (local + hub) and invoice import/status. */
function ps_sync_panel($panel)
{
    global $pdo;
    $crawl = ps_crawl_panel($panel);
    $stat = ['panel' => $panel['name_panel'], 'type' => $panel['type'], 'seen' => count($crawl['users']), 'imported' => 0, 'missing' => 0, 'removed_unclaimed' => 0, 'complete' => $crawl['complete'], 'error' => $crawl['error'] ?? null];
    if (!$crawl['ok']) return $stat;
    $panelUrl = $panel['url_panel'];
    $pk = ps_panel_key($panelUrl);
    $self = ps_self_ctx();
    $client = ps_mode() === 'client';

    $items = [];
    foreach ($crawl['users'] as $u => $d) {
        $items[] = ['username' => (string) $u, 'status' => $d['status'], 'keys' => $d['keys']];
    }
    $missing = [];
    if ($crawl['complete']) {
        $st = $pdo->prepare("SELECT username FROM panel_accounts WHERE panel_key = ? AND status NOT IN ('deleted','reserved')");
        $st->execute([$pk]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($crawl['users'][$r['username']])) $missing[] = $r['username'];
        }
    }
    $chunks = array_chunk($items, 500);
    if (!$chunks) $chunks = [[]];
    foreach ($chunks as $i => $chunk) {
        $p = ['panel_url' => $panelUrl, 'items' => $chunk];
        if ($i === 0 && $missing) $p['missing'] = $missing;
        if (!$chunk && empty($p['missing'])) continue;
        ps_dispatch($self, 'upsert', $p);   // local mirror, every mode
        if ($client) ps_http_hub('upsert', $p);
    }
    $stat['missing'] = count($missing);

    if (ps_setting('import_invoice', 'on') !== 'on') return $stat;
    $have = [];
    $st = $pdo->prepare("SELECT username FROM invoice WHERE Service_location = ? AND Status NOT IN (" . PS_REMOVED_STATUSES . ")");
    $st->execute([$panel['name_panel']]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $have[strtolower((string) $r['username'])] = true;
    }
    // unpaid rows hold a username that is about to be created; don't import over them
    $st = $pdo->prepare("SELECT username FROM invoice WHERE Service_location = ? AND Status = 'unpaid'");
    $st->execute([$panel['name_panel']]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $have[strtolower((string) $r['username'])] = true;
    }
    $ins = $pdo->prepare("INSERT IGNORE INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, note, notifctions) VALUES (NULL,?,?,?,?,?,'0',?,?,'unclaimed','panel_sync',?)");
    $notif = json_encode(['volume' => false, 'time' => false]);
    $label = ps_t('imported_product', 'panel_sync');
    $now = time();
    foreach ($crawl['users'] as $u => $d) {
        $u = (string) $u;
        if (isset($have[strtolower($u)]) || !ps_valid_username($u)) continue;
        $vol = $d['data_limit'] > 0 ? (string) round($d['data_limit'] / 1073741824, 2) : '0';
        $days = $d['expire'] > $now ? (string) ceil(($d['expire'] - $now) / 86400) : '0';
        $ins->execute([ps_new_invoice_id(), $u, (string) ($d['created'] ?: $now), $panel['name_panel'], $label, $vol, $days, $notif]);
        $stat['imported'] += $ins->rowCount();
    }
    if ($crawl['complete']) {
        $st = $pdo->prepare("SELECT id_invoice, username FROM invoice WHERE Service_location = ? AND Status = 'unclaimed'");
        $st->execute([$panel['name_panel']]);
        $del = $pdo->prepare("UPDATE invoice SET Status = 'removedbypanel' WHERE id_invoice = ? AND Status = 'unclaimed'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($crawl['users'][$r['username']])) {
                $del->execute([$r['id_invoice']]);
                $stat['removed_unclaimed'] += $del->rowCount();
            }
        }
        if ($missing && ps_setting('mark_deleted_owned', 'off') === 'on') {
            $gone = $pdo->prepare("SELECT username FROM panel_accounts WHERE panel_key = ? AND status = 'deleted'");
            $gone->execute([$pk]);
            $goneSet = [];
            while ($r = $gone->fetch(PDO::FETCH_ASSOC)) $goneSet[$r['username']] = true;
            $up = $pdo->prepare("UPDATE invoice SET Status = 'removedbypanel' WHERE Service_location = ? AND username = ? AND Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold','disabledn')");
            foreach ($missing as $u) {
                if (isset($goneSet[$u])) $up->execute([$panel['name_panel'], $u]);
            }
        }
    }
    return $stat;
}

/* ================================================================ CLAIM */
function ps_local_panel_for_key($panelKey)
{
    global $pdo;
    $st = $pdo->query("SELECT * FROM marzban_panel");
    while ($p = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($p['url_panel']) && ps_panel_key($p['url_panel']) === $panelKey) {
            return $p;
        }
    }
    return null;
}

function ps_insert_owned_invoice($userId, $username, $panelName)
{
    global $pdo;
    $pdo->prepare("INSERT IGNORE INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, note, notifctions) VALUES (?,?,?,?,?,?,'0','0','0','active','panel_sync',?)")
        ->execute([(string) $userId, ps_new_invoice_id(), $username, (string) time(), $panelName, ps_t('imported_product', 'panel_sync'), json_encode(['volume' => false, 'time' => false])]);
}

/**
 * User pasted a sub link/config: find the account, check central ownership,
 * attach the local invoice row. codes: ok, already_yours, no_key, not_found,
 * owned_other, deleted, hub_down, no_local_panel
 */
function ps_claim_for_user($userId, $text)
{
    global $pdo;
    $keys = ps_keys_from_text($text);
    if (!$keys) return ['ok' => false, 'code' => 'no_key'];
    $found = null;
    $hubDown = false;
    foreach (array_slice($keys, 0, 10) as $kh) {
        list($code, $res) = ps_call('lookup', ['key_hash' => $kh]);
        if ($code === 0) {
            $hubDown = true;
            continue;
        }
        if ($code === 200 && !empty($res['account'])) {
            $found = ['key' => $kh, 'account' => $res['account']];
            break;
        }
    }
    if (!$found) return ['ok' => false, 'code' => $hubDown ? 'hub_down' : 'not_found'];
    $acc = $found['account'];
    if (($acc['status'] ?? '') === 'deleted') return ['ok' => false, 'code' => 'deleted'];
    $localPanel = ps_local_panel_for_key((string) $acc['panel_key']);
    if (!$localPanel) return ['ok' => false, 'code' => 'no_local_panel'];

    $inv = $pdo->prepare("SELECT * FROM invoice WHERE username = ? AND Service_location = ? AND Status NOT IN (" . PS_REMOVED_STATUSES . ") ORDER BY (Status = 'unclaimed') ASC LIMIT 1");
    $inv->execute([$acc['username'], $localPanel['name_panel']]);
    $row = $inv->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['id_user']) && (string) $row['id_user'] !== (string) $userId) {
        return ['ok' => false, 'code' => 'owned_other'];
    }
    $alreadyLocal = $row && (string) $row['id_user'] === (string) $userId && $row['Status'] !== 'unclaimed';
    if (!empty($acc['owned']) && empty($acc['owned_by_you'])) {
        return ['ok' => false, 'code' => 'owned_other'];
    }
    // owned_by_you but a *different* local user (another Telegram id) => refuse
    if (!empty($acc['owned_by_you']) && !empty($acc['owner_user_id']) && (string) $acc['owner_user_id'] !== (string) $userId && !$alreadyLocal) {
        return ['ok' => false, 'code' => 'owned_other'];
    }
    list($code, $res) = ps_call('claim', ['key_hash' => $found['key'], 'owner_user_id' => (string) $userId]);
    if ($code === 409) return ['ok' => false, 'code' => 'owned_other'];
    if ($code === 410) return ['ok' => false, 'code' => 'deleted'];
    if ($code === 0) return ['ok' => false, 'code' => 'hub_down'];
    if ($code !== 200) return ['ok' => false, 'code' => 'not_found'];
    if ($alreadyLocal) {
        return ['ok' => true, 'code' => 'already_yours', 'username' => $acc['username'], 'panel' => $localPanel['name_panel']];
    }
    if ($row) {
        $pdo->prepare("UPDATE invoice SET id_user = ?, Status = 'active' WHERE id_invoice = ? AND (id_user IS NULL OR id_user = '' OR id_user = ?)")
            ->execute([(string) $userId, $row['id_invoice'], (string) $userId]);
    } else {
        ps_insert_owned_invoice($userId, $acc['username'], $localPanel['name_panel']);
    }
    return ['ok' => true, 'code' => 'ok', 'username' => $acc['username'], 'panel' => $localPanel['name_panel']];
}

/** admin bulk assign: usernames on a local panel → Telegram user id. Returns [done, failed[]] */
function ps_admin_bulk_assign($panelName, $usernames, $userId)
{
    global $pdo;
    $panel = ps_panel_by_name($panelName);
    if (!$panel) return [0, ['panel']];
    $done = 0;
    $failed = [];
    $client = ps_mode() === 'client';
    foreach ($usernames as $u) {
        $u = trim((string) $u);
        if (!ps_valid_username($u)) continue;
        $p = ['panel_url' => $panel['url_panel'], 'username' => $u, 'owner_user_id' => (string) $userId];
        list($code, $res) = ps_call('claim', $p + ['force' => !$client]);
        if ($code === 404 || ($client && $code === 403)) {
            // not in registry yet (cron not run) or hub refuses username-claims from clients: register it as ours
            list($code, $res) = ps_call('event', $p + ['type' => 'renew']);
        }
        if ($code !== 200) {
            $failed[] = $u . ' (' . ($res['error'] ?? $code) . ')';
            continue;
        }
        $st = $pdo->prepare("UPDATE invoice SET id_user = ?, Status = IF(Status = 'unclaimed', 'active', Status) WHERE username = ? AND Service_location = ? AND Status NOT IN (" . PS_REMOVED_STATUSES . ")");
        $st->execute([(string) $userId, $u, $panel['name_panel']]);
        if ($st->rowCount() == 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE username = ? AND Service_location = ? AND id_user = ? AND Status NOT IN (" . PS_REMOVED_STATUSES . ")");
            $chk->execute([$u, $panel['name_panel'], (string) $userId]);
            if ((int) $chk->fetchColumn() === 0) ps_insert_owned_invoice($userId, $u, $panel['name_panel']);
        }
        $done++;
    }
    return [$done, $failed];
}

/* ============================================================ BOT UI === */
function ps_answer_cb($t = '')
{
    global $callback_query_id;
    if (empty($callback_query_id)) return;
    $d = ['callback_query_id' => $callback_query_id];
    if ($t !== '') $d['text'] = $t;
    telegram('answerCallbackQuery', $d);
}

function virabot_psync_claim_button()
{
    if (!ps_enabled() || ps_setting('claim_enabled', 'on') !== 'on') return null;
    return [['text' => ps_t('btn_claim', '🔗 افزودن سرویس قبلی (لینک/کانفیگ)'), 'callback_data' => 'psync_claim']];
}

/** user side: true if handled */
function virabot_psync_user_handle()
{
    global $text, $datain, $user, $from_id;
    $txt = isset($text) ? (string) $text : '';
    $cb = isset($datain) ? (string) $datain : '';
    if ($cb === 'psync_claim' || $txt === '/claim') {
        if (!ps_enabled() || ps_setting('claim_enabled', 'on') !== 'on') return false;
        if ($cb !== '') ps_answer_cb();
        sendmessage($from_id, ps_t('ask_claim', "🔗 لینک ساب‌اسکریپشن یا یکی از کانفیگ‌های سرویسی را که قبلاً (خارج از این ربات) گرفته‌اید بفرستید تا به حساب شما وصل شود.\n\nبرای انصراف /start را بزنید."), null, 'HTML');
        step('psync_claim_wait', $from_id);
        return true;
    }
    if (($user['step'] ?? '') !== 'psync_claim_wait' || $txt === '' || $txt[0] === '/') return false;
    if (!ps_keys_from_text($txt)) {
        // not a link/config (e.g. a menu button): leave the claim step and let the normal router handle it
        step('home', $from_id);
        $user['step'] = 'home';
        return false;
    }
    $now = time();
    $k = 'rl_claim_' . $from_id;
    $rl = json_decode((string) ps_setting($k, ''), true);
    if (!is_array($rl) || $now - (int) ($rl['t'] ?? 0) > 600) $rl = ['t' => $now, 'n' => 0];
    $rl['n'] = (int) $rl['n'] + 1;
    ps_setting_set($k, json_encode($rl));
    if ($rl['n'] > 10) {
        sendmessage($from_id, ps_t('claim_rate', '⏳ تعداد تلاش‌ها زیاد بود؛ چند دقیقه دیگر دوباره امتحان کنید.'), null, 'HTML');
        return true;
    }
    $r = ps_claim_for_user($from_id, $txt);
    $msgs = [
        'ok' => ps_t('claim_ok', '✅ سرویس <code>%s</code> به حساب شما اضافه شد و از «سرویس‌های من» قابل مشاهده و تمدید است.'),
        'already_yours' => ps_t('claim_already', 'ℹ️ سرویس <code>%s</code> از قبل متعلق به شماست.'),
        'no_key' => ps_t('claim_no_key', '❌ لینک یا کانفیگ معتبری پیدا نشد. لینک ساب یا کانفیگ کامل (vless/vmess/trojan/ss) را بفرستید.'),
        'not_found' => ps_t('claim_not_found', '❌ این سرویس در پنل‌های متصل پیدا نشد. اگر تازه ساخته شده، چند دقیقه بعد دوباره امتحان کنید.'),
        'owned_other' => ps_t('claim_owned', '⛔️ این سرویس متعلق به حساب دیگری است و قابل انتقال نیست. در صورت نیاز با پشتیبانی تماس بگیرید.'),
        'deleted' => ps_t('claim_deleted', '❌ این سرویس روی پنل حذف شده است.'),
        'hub_down' => ps_t('claim_hub_down', '⚠️ ارتباط با سرور مرکزی برقرار نشد؛ کمی بعد دوباره تلاش کنید.'),
        'no_local_panel' => ps_t('claim_no_panel', '❌ این سرویس روی پنلی است که در این ربات تعریف نشده.'),
    ];
    $m = $msgs[$r['code']] ?? $msgs['not_found'];
    if (!empty($r['ok'])) {
        $m = sprintf($m, htmlspecialchars((string) $r['username'], ENT_QUOTES, 'UTF-8'));
        step('home', $from_id);
    }
    sendmessage($from_id, $m, null, 'HTML');
    return true;
}

function ps_admin_keyboard()
{
    $mode = ps_mode();
    $on = function ($k, $d) {
        return ps_setting($k, $d) === 'on' ? '✅' : '❌';
    };
    $rows = [
        [['text' => ps_t('btn_mode_local', 'محلی') . ($mode === 'local' ? ' ✅' : ''), 'callback_data' => 'psync_mode_local'],
         ['text' => ps_t('btn_mode_hub', 'هاب') . ($mode === 'hub' ? ' ✅' : ''), 'callback_data' => 'psync_mode_hub'],
         ['text' => ps_t('btn_mode_client', 'کلاینت') . ($mode === 'client' ? ' ✅' : ''), 'callback_data' => 'psync_mode_client']],
        [['text' => ps_t('btn_hub_url', '🌐 آدرس هاب'), 'callback_data' => 'psync_set_hub_url'], ['text' => ps_t('btn_hub_token', '🔑 توکن هاب'), 'callback_data' => 'psync_set_hub_token']],
        [['text' => ps_t('btn_new_client', '➕ توکن کلاینت جدید'), 'callback_data' => 'psync_new_client'], ['text' => ps_t('btn_list_clients', '📋 کلاینت‌ها'), 'callback_data' => 'psync_list_clients']],
        [['text' => ps_t('btn_toggle_import', '📥 ورود کاربران پنل') . ': ' . $on('import_invoice', 'on'), 'callback_data' => 'psync_toggle_import']],
        [['text' => ps_t('btn_toggle_claim', '🔗 دکمه افزودن سرویس قبلی') . ': ' . $on('claim_enabled', 'on'), 'callback_data' => 'psync_toggle_claim']],
        [['text' => ps_t('btn_toggle_markdel', '🗑 حذف سرویس مالک‌دارِ حذف‌شده از پنل') . ': ' . $on('mark_deleted_owned', 'off'), 'callback_data' => 'psync_toggle_markdel']],
        [['text' => ps_t('btn_toggle_enabled', '⚙️ ثبت رویداد خرید/تمدید/حذف') . ': ' . $on('enabled', 'on'), 'callback_data' => 'psync_toggle_enabled']],
        [['text' => ps_t('btn_ping', '📡 تست اتصال'), 'callback_data' => 'psync_ping'], ['text' => ps_t('btn_bulk', '👥 واگذاری گروهی'), 'callback_data' => 'psync_bulk']],
    ];
    return json_encode(['inline_keyboard' => $rows]);
}

function ps_admin_status_text()
{
    global $pdo;
    $n = 0;
    $un = 0;
    try {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM panel_accounts WHERE status <> 'deleted'")->fetchColumn();
        $un = (int) $pdo->query("SELECT COUNT(*) FROM invoice WHERE Status = 'unclaimed'")->fetchColumn();
    } catch (Throwable $e) {
    }
    $tk = (string) ps_setting('hub_token', '');
    $last = json_decode((string) ps_setting('last_run', ''), true);
    $lastTxt = is_array($last) ? date('Y/m/d H:i', (int) ($last['t'] ?? 0)) : '-';
    return sprintf(ps_t('status_text', "🔄 <b>همگام‌سازی کاربران پنل</b>\n\nحالت: <b>%s</b>\nشناسه این نصب: <code>%s</code>\nآدرس هاب: <code>%s</code>\nتوکن هاب: <code>%s</code>\nآدرس API این نصب: <code>%s</code>\nحساب‌های جدول: %d\nسرویس‌های بدون مالک: %d\nآخرین اجرای کرون: %s\n\nمحلی = فقط جدول همین ربات · هاب = جدول مرکزی + API باز · کلاینت = اتصال به هاب"),
        ps_mode(),
        htmlspecialchars((string) ps_setting('instance_id', ''), ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) ps_setting('hub_url', '-'), ENT_QUOTES, 'UTF-8'),
        $tk !== '' ? '••••' . htmlspecialchars(substr($tk, -4), ENT_QUOTES, 'UTF-8') : '-',
        htmlspecialchars(ps_api_url(), ENT_QUOTES, 'UTF-8'),
        $n, $un, $lastTxt);
}

function ps_api_url()
{
    global $domainhosts;
    return 'https://' . $domainhosts . '/sync_api.php';
}

/** admin side: true if handled */
function virabot_psync_admin_handle()
{
    global $text, $datain, $user, $from_id, $message_id, $pdo, $textbotlang;
    $txt = isset($text) ? (string) $text : '';
    $cb = isset($datain) ? (string) $datain : '';
    $stepNow = (string) ($user['step'] ?? '');
    $menuBtn = ps_t('btn_menu', '🔄 همگام‌سازی کاربران پنل');
    $relevant = ($txt !== '' && $txt === $menuBtn) || (strpos($cb, 'psync_') === 0 && $cb !== 'psync_claim') || (strpos($stepNow, 'psync_') === 0 && $stepNow !== 'psync_claim_wait');
    if (!$relevant) return false;
    $vbRule = select("admin", "*", "id_admin", $from_id, "select");
    if (!is_array($vbRule) || ($vbRule['rule'] ?? '') !== 'administrator') return false;
    ps_migrate($pdo);

    if ($txt !== '' && $txt === $menuBtn) {
        sendmessage($from_id, ps_admin_status_text(), ps_admin_keyboard(), 'HTML');
        return true;
    }
    if ($cb !== '') {
        $refresh = function () use ($from_id, $message_id) {
            Editmessagetext($from_id, $message_id, ps_admin_status_text(), ps_admin_keyboard());
        };
        if (preg_match('/^psync_mode_(local|hub|client)$/', $cb, $m)) {
            if ($m[1] === 'client' && (ps_setting('hub_url', '') === '' || ps_setting('hub_token', '') === '')) {
                ps_answer_cb(ps_t('need_hub', 'اول آدرس و توکن هاب را تنظیم کنید.'));
                return true;
            }
            ps_setting_set('mode', $m[1]);
            ps_answer_cb('✅');
            $refresh();
            return true;
        }
        $toggles = ['psync_toggle_import' => ['import_invoice', 'on'], 'psync_toggle_claim' => ['claim_enabled', 'on'], 'psync_toggle_markdel' => ['mark_deleted_owned', 'off'], 'psync_toggle_enabled' => ['enabled', 'on']];
        if (isset($toggles[$cb])) {
            list($k, $def) = $toggles[$cb];
            ps_setting_set($k, ps_setting($k, $def) === 'on' ? 'off' : 'on');
            ps_answer_cb('✅');
            $refresh();
            return true;
        }
        $asks = [
            'psync_set_hub_url' => ['psync_hub_url', ps_t('ask_hub_url', 'آدرس هاب را بفرستید (فقط https)، مثلاً <code>https://hub.example.com</code>:')],
            'psync_set_hub_token' => ['psync_hub_token', ps_t('ask_hub_token', 'توکنی را که مدیر هاب برای این ربات ساخته بفرستید (پیام شما پاک می‌شود):')],
            'psync_bulk' => ['psync_bulk', ps_t('ask_bulk', "واگذاری گروهی سرویس‌ها به یک کاربر. با این قالب بفرستید:\n\n<code>آیدی_عددی_کاربر\nنام_پنل\nusername1\nusername2</code>")],
        ];
        if (isset($asks[$cb])) {
            sendmessage($from_id, $asks[$cb][1], null, 'HTML');
            step($asks[$cb][0], $from_id);
            ps_answer_cb();
            return true;
        }
        if ($cb === 'psync_new_client') {
            if (ps_mode() !== 'hub') {
                ps_answer_cb(ps_t('need_hub_mode', 'فقط در حالت «هاب» فعال است.'));
                return true;
            }
            sendmessage($from_id, ps_t('ask_client_name', "نوع و نام کلاینت را بفرستید، مثلاً:\n<code>bot shop2</code> یا <code>site mysite.ir</code>"), null, 'HTML');
            step('psync_new_client', $from_id);
            ps_answer_cb();
            return true;
        }
        if ($cb === 'psync_list_clients') {
            $rows = $pdo->query("SELECT id, name, owner_type, active, last_used FROM sync_clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $t = ps_t('clients_title', '📋 کلاینت‌های هاب:') . "\n";
            $kb = ['inline_keyboard' => []];
            foreach ($rows as $r) {
                $t .= "\n#{$r['id']} " . htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') . " ({$r['owner_type']}) " . ($r['active'] ? '✅' : '⛔️') . ($r['last_used'] ? ' · ' . date('Y/m/d H:i', (int) $r['last_used']) : '');
                $kb['inline_keyboard'][] = [['text' => ($r['active'] ? '⛔️ ' : '✅ ') . $r['name'], 'callback_data' => 'psync_cltog_' . (int) $r['id']]];
            }
            if (!$rows) $t .= "\n-";
            sendmessage($from_id, $t, json_encode($kb), 'HTML');
            ps_answer_cb();
            return true;
        }
        if (preg_match('/^psync_cltog_(\d+)$/', $cb, $m)) {
            $pdo->prepare("UPDATE sync_clients SET active = 1 - active WHERE id = ?")->execute([(int) $m[1]]);
            ps_answer_cb('✅');
            return true;
        }
        if ($cb === 'psync_ping') {
            list($code, $res) = ps_call('ping', []);
            ps_answer_cb($code === 200 ? ps_t('ping_ok', '✅ اتصال برقرار است') : ps_t('ping_fail', '❌ خطا: ') . ($res['error'] ?? $code));
            return true;
        }
        ps_answer_cb();
        return true;
    }
    if ($txt === '') return false;
    $back = [$textbotlang['Admin']['backAdminBtn'] ?? '', $textbotlang['Admin']['backMenuBtn'] ?? '', 'panel', '/panel', '/start', $textbotlang['Admin']['panelAdmin'] ?? ''];
    if (in_array($txt, $back, true)) {
        step('home', $from_id);
        return false;
    }
    if ($stepNow === 'psync_hub_url') {
        $u = trim($txt);
        if (!preg_match('#^https://[^\s/]+(/\S*)?$#i', $u)) {
            sendmessage($from_id, ps_t('bad_url', '❌ آدرس باید با https:// شروع شود.'), null, 'HTML');
            return true;
        }
        ps_setting_set('hub_url', rtrim($u, '/'));
        step('home', $from_id);
        sendmessage($from_id, ps_admin_status_text(), ps_admin_keyboard(), 'HTML');
        return true;
    }
    if ($stepNow === 'psync_hub_token') {
        deletemessage($from_id, $message_id);
        $tk = trim($txt);
        if (!preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $tk)) {
            sendmessage($from_id, ps_t('bad_token', '❌ توکن نامعتبر است.'), null, 'HTML');
            return true;
        }
        ps_setting_set('hub_token', $tk);
        step('home', $from_id);
        sendmessage($from_id, ps_admin_status_text(), ps_admin_keyboard(), 'HTML');
        return true;
    }
    if ($stepNow === 'psync_new_client') {
        if (!preg_match('/^(bot|site)\s+([A-Za-z0-9_.\-]{2,60})$/', trim($txt), $m)) {
            sendmessage($from_id, ps_t('bad_client', '❌ قالب: <code>bot name</code> یا <code>site name</code>'), null, 'HTML');
            return true;
        }
        $token = 'vbs_' . bin2hex(random_bytes(24));
        try {
            $pdo->prepare("INSERT INTO sync_clients (name, owner_type, token_hash, active, created_at) VALUES (?,?,?,1,?)")
                ->execute([$m[2], $m[1], hash('sha256', $token), time()]);
        } catch (Throwable $e) {
            sendmessage($from_id, ps_t('client_exists', '❌ این نام قبلاً ثبت شده است.'), null, 'HTML');
            return true;
        }
        step('home', $from_id);
        sendmessage($from_id, sprintf(ps_t('client_created', "✅ کلاینت <b>%s</b> ساخته شد.\n\nتوکن (فقط همین یک بار نمایش داده می‌شود):\n<code>%s</code>\n\nآدرس API:\n<code>%s</code>"),
            htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8'), $token, htmlspecialchars(ps_api_url(), ENT_QUOTES, 'UTF-8')), null, 'HTML');
        return true;
    }
    if ($stepNow === 'psync_bulk') {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $txt)), 'strlen'));
        if (count($lines) < 3 || !ctype_digit($lines[0])) {
            sendmessage($from_id, ps_t('bad_bulk', '❌ قالب نادرست است.'), null, 'HTML');
            return true;
        }
        if (!select("user", "*", "id", $lines[0], "select")) {
            sendmessage($from_id, ps_t('bulk_no_user', '❌ کاربری با این آیدی در ربات نیست.'), null, 'HTML');
            return true;
        }
        if (!ps_panel_by_name($lines[1])) {
            sendmessage($from_id, ps_t('bulk_no_panel', '❌ پنلی با این نام پیدا نشد.'), null, 'HTML');
            return true;
        }
        list($done, $failed) = ps_admin_bulk_assign($lines[1], array_slice($lines, 2, 500), $lines[0]);
        step('home', $from_id);
        $t = sprintf(ps_t('bulk_done', '✅ %d سرویس واگذار شد.'), $done);
        if ($failed) $t .= "\n" . ps_t('bulk_failed', 'ناموفق:') . "\n" . htmlspecialchars(implode("\n", array_slice($failed, 0, 50)), ENT_QUOTES, 'UTF-8');
        sendmessage($from_id, $t, null, 'HTML');
        return true;
    }
    return false;
}

} // !function_exists('ps_migrate')
