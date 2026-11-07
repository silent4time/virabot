<?php
/**
 * Poll nvpanel operations that already have an op id.
 * Does not POST a new purchase and does not refund.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/function.php';

if (!isset($pdo) || !$pdo instanceof PDO || !function_exists('nvpanel_request')) {
    echo "skip\n";
    exit(0);
}
nvpanel_ensure($pdo);
$rows = $pdo->query("SELECT * FROM nvpanel_ops WHERE state IN ('pending','needs_review') AND op_id IS NOT NULL AND delivered = 0")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $panel = $pdo->prepare('SELECT * FROM marzban_panel WHERE code_panel = ? AND type = ? LIMIT 1');
    $panel->execute([$row['panel_code'], 'nvpanel']);
    $p = $panel->fetch(PDO::FETCH_ASSOC);
    if (!$p || !nvpanel_configured($p)) {
        continue;
    }
    $res = nvpanel_request($p, 'GET', '/op/' . (int) $row['op_id'], null, 20);
    if ((int) $res['status'] === 0) {
        continue;
    }
    $class = nvpanel_classify((int) $res['status'], $res['json']);
    $username = (string) ($res['json']['username'] ?? $row['username'] ?? '');
    $pass = array_key_exists('password', $res['json']) ? (string) $res['json']['password'] : (string) ($row['secret_pass'] ?? '');
    $pdo->prepare('UPDATE nvpanel_ops SET state=?, refund_ok=?, username=?, secret_pass=?, updated_at=? WHERE idem_key=?')
        ->execute([$class['state'], $class['refund'] ? 1 : 0, $username, $pass, time(), $row['idem_key']]);
    if ($class['deliver'] && $username !== '' && function_exists('sendmessage')) {
        $owner = $pdo->prepare('SELECT id_user FROM invoice WHERE id_invoice = ? LIMIT 1');
        $owner->execute([$row['invoice_id']]);
        $uid = $owner->fetchColumn();
        if ($uid) {
            sendmessage($uid, nvpanel_message($username, $pass, 'فعال', $res['json']['expire_at'] ?? null), null, 'HTML');
            $pdo->prepare('UPDATE invoice SET username=?, Status=? WHERE id_invoice=?')->execute([$username, 'active', $row['invoice_id']]);
            $pdo->prepare('UPDATE nvpanel_ops SET delivered=1 WHERE idem_key=?')->execute([$row['idem_key']]);
        }
    }
}
$panels = $pdo->query("SELECT * FROM marzban_panel WHERE type = 'nvpanel'")->fetchAll(PDO::FETCH_ASSOC);
$stateFile = dirname(__DIR__) . '/storage/nvpanel-balance.json';
$saved = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
if (!is_array($saved)) {
    $saved = [];
}
foreach ($panels as $p) {
    if (!nvpanel_configured($p)) {
        continue;
    }
    $res = nvpanel_request($p, 'GET', '/balance?cached=1', null, 15);
    $bal = $res['json']['balance_toman'] ?? null;
    $id = (string) ($p['code_panel'] ?? '');
    $alert = nvpanel_balance_alert(is_array($saved[$id] ?? null) ? $saved[$id] : [], $bal, time());
    $saved[$id] = $alert['state'];
    if ($alert['kind'] && function_exists('sendmessage')) {
        $text = $alert['kind'] === 'low_balance'
            ? 'موجودی کیف پول نماینده nvpanel کم است. این هشدار جدا از هشدار خود درگاه است.'
            : 'موجودی کیف پول نماینده nvpanel به بالای آستانه برگشته است.';
        $admins = $pdo->query("SELECT id_admin FROM admin WHERE rule = 'administrator'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            sendmessage($adminId, $text, null, 'HTML');
        }
    }
}
if (!is_dir(dirname($stateFile))) {
    @mkdir(dirname($stateFile), 0750, true);
}
file_put_contents($stateFile, json_encode($saved));
echo "ok\n";
