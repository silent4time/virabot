<?php
/**
 * Paste these two blocks into admin.php if they are not already present.
 * 1) Replace the Connectix token prompt so it shows the live /externel/v1 URL.
 * 2) Admin tools: list clients + add/renew plan.
 */

// ——— 1) inside add_link_panel when type === connectix ———
/*
        if (($userdata['type'] ?? '') === 'connectix') {
            sendmessage($from_id, "🔑 API Token کانکتیکس را بفرستید\n(از Account Settings → API Token)\n\nآدرس API را دقیقاً این بگذارید:\n<code>https://seller-api.connectix.vip/externel/v1</code>\n⚠️ املای رسمی externel است نه external.", $backadmin, 'HTML');
        }
*/

// ——— 2) admin commands (near the existing "🔄 همگام پلن کانکتیکس") ———

elseif ($text == "🔄 همگام پلن کانکتیکس" && $adminrulecheck['rule'] == "administrator") {
    $panels = select("marzban_panel", "*", "type", "connectix", "fetchAll");
    if (!is_array($panels) || count($panels) === 0) {
        sendmessage($from_id, "❌ پنل کانکتیکس تعریف نشده.", $keyboardadmin, "HTML");
    } else {
        $lines = [];
        foreach ($panels as $p) {
            if (!function_exists("connectix_sync_products")) {
                $lines[] = "Connectix.php missing";
                break;
            }
            $s = connectix_sync_products($p["name_panel"]);
            if (!empty($s["error"])) {
                $url = isset($s["url"]) ? "\n" . $s["url"] : "";
                $lines[] = $p["name_panel"] . ": ❌ " . $s["error"] . $url;
            } else {
                $lines[] = $p["name_panel"] . ": ✅ total={$s['total']} +{$s['added']} ~{$s['updated']}";
            }
        }
        sendmessage($from_id, "همگام‌سازی کانکتیکس:\n" . implode("\n", $lines), $keyboardadmin, "HTML");
    }
}

elseif ($text == "👥 کاربران کانکتیکس" && $adminrulecheck['rule'] == "administrator") {
    $panels = select("marzban_panel", "*", "type", "connectix", "fetchAll");
    if (!is_array($panels) || count($panels) === 0) {
        sendmessage($from_id, "❌ پنل کانکتیکس تعریف نشده.", $keyboardadmin, "HTML");
    } else {
        $lines = [];
        foreach ($panels as $p) {
            if (!function_exists('connectix_list_clients')) {
                $lines[] = "Connectix.php missing";
                break;
            }
            $r = connectix_list_clients($p['name_panel'], ['recordPerPage' => 20]);
            if (!function_exists('connectix_http_ok') || !connectix_http_ok($r)) {
                $lines[] = $p['name_panel'] . ': ❌ ' . ($r['message'] ?? $r['error'] ?? 'fail');
                continue;
            }
            $n = (int) ($r['total_clients'] ?? (is_array($r['clients'] ?? null) ? count($r['clients']) : 0));
            $lines[] = $p['name_panel'] . ": {$n} کاربر";
            $i = 0;
            foreach ($r['clients'] as $c) {
                if ($i >= 20) {
                    $lines[] = "…";
                    break;
                }
                $u = $c['username'] ?? $c['name'] ?? $c['id'] ?? '?';
                $lines[] = "• <code>" . htmlspecialchars((string) $u) . "</code>";
                $i++;
            }
        }
        sendmessage($from_id, "کاربران کانکتیکس:\n" . implode("\n", $lines), $keyboardadmin, "HTML");
    }
}

elseif (preg_match('/^cxadd\s+(\S+)\s+(\S+)$/i', (string) $text, $m) && $adminrulecheck['rule'] == "administrator") {
    $username = $m[1];
    $planId = $m[2];
    $panels = select("marzban_panel", "*", "type", "connectix", "fetchAll");
    if (!is_array($panels) || count($panels) === 0) {
        sendmessage($from_id, "❌ پنل کانکتیکس تعریف نشده.", $keyboardadmin, "HTML");
    } else {
        $p = $panels[0];
        $r = connectix_add_plan($p['name_panel'], $username, $planId);
        if (!empty($r['success'])) {
            sendmessage($from_id, "✅ پلن به کاربر <code>{$username}</code> اضافه شد.", $keyboardadmin, "HTML");
        } else {
            $msg = $r['message'] ?? $r['error'] ?? ('HTTP ' . ($r['http_code'] ?? 0));
            sendmessage($from_id, "❌ افزودن پلن شکست خورد:\n" . htmlspecialchars((string) $msg) . "\n<code>" . htmlspecialchars((string) ($r['raw'] ?? '')) . "</code>", $keyboardadmin, "HTML");
        }
    }
}

elseif (preg_match('/^cxshow\s+(\S+)$/i', (string) $text, $m) && $adminrulecheck['rule'] == "administrator") {
    $username = $m[1];
    $panels = select("marzban_panel", "*", "type", "connectix", "fetchAll");
    if (!is_array($panels) || count($panels) === 0) {
        sendmessage($from_id, "❌ پنل کانکتیکس تعریف نشده.", $keyboardadmin, "HTML");
    } else {
        $r = connectix_show_client($panels[0]['name_panel'], $username);
        $dump = json_encode($r['client'] ?? $r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $dump = mb_substr((string) $dump, 0, 3500);
        sendmessage($from_id, "کاربر <code>{$username}</code>:\n<pre>" . htmlspecialchars($dump) . "</pre>", $keyboardadmin, "HTML");
    }
}
