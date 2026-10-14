<?php
/**
 * Inject/update eylan createUser + DataUser in panels.php (v1.8.12)
 * Safe for other panel types. Idempotent.
 */
$panels = __DIR__ . '/panels.php';
if (!is_file($panels)) {
    fwrite(STDERR, "ERROR: panels.php not found\n");
    exit(1);
}
$src = file_get_contents($panels);

if (strpos($src, 'Eylan.php') === false) {
    $line = "\nif (is_file(__DIR__ . '/Eylan.php')) { require_once __DIR__ . '/Eylan.php'; }\n";
    if (preg_match('/require(_once)?\s+[\'"][^\'"]*function\.php[\'"]\s*;/', $src, $m, PREG_OFFSET_CAPTURE)) {
        $end = $m[0][1] + strlen($m[0][0]);
        $src = substr($src, 0, $end) . $line . substr($src, $end);
    } else {
        $src = preg_replace('/^<\?php/', "<?php\n" . trim($line), $src, 1);
    }
    echo "OK require\n";
} else {
    echo "SKIP require\n";
}

$createEylan = <<<'PHP'
        } elseif ($Get_Data_Panel['type'] == "eylan") {
            if (!function_exists('eylan_create_user')) {
                return array('status' => 'Unsuccessful', 'msg' => 'Eylan.php not loaded');
            }
            $volume_gb = 0;
            if (isset($data_limit) && $data_limit > 0) {
                $volume_gb = floatval($data_limit) / (1024 * 1024 * 1024);
            }
            if ($volume_gb <= 0 && isset($Get_Data_Product['Volume_constraint'])) {
                $volume_gb = floatval($Get_Data_Product['Volume_constraint']);
            }
            $expire_ts = isset($expire) ? intval($expire) : 0;
            $days = 0;
            if ($expire_ts > time()) {
                $days = intval(ceil(($expire_ts - time()) / 86400));
            } elseif (isset($Get_Data_Product['Service_time'])) {
                $days = intval($Get_Data_Product['Service_time']);
                if ($days > 0) {
                    $expire_ts = time() + ($days * 86400);
                }
            }
            // inboundid پنل = حالت پروتکل (openvpn|l2tp|cisco|wireguard|multi)
            $protocol = isset($Get_Data_Panel['inboundid']) ? $Get_Data_Panel['inboundid'] : 'multi';
            $max_clients = 1;
            if (isset($data['max_clients'])) {
                $max_clients = max(0, intval($data['max_clients']));
            } elseif (isset($data['max_users'])) {
                $max_clients = max(0, intval($data['max_users']));
            } elseif (isset($Get_Data_Product['max_users'])) {
                $max_clients = max(0, intval($Get_Data_Product['max_users']));
            }
            $extra = array(
                'days' => $days,
                'note' => isset($note) ? $note : ('Created by bot ' . date('Y-m-d H:i')),
                'protocol' => $protocol,
                'max_clients' => $max_clients,
            );
            $result = eylan_create_user($Get_Data_Panel['name_panel'], $usernameC, $volume_gb, $expire_ts, $extra);
            $ok = function_exists('eylan_http_ok') ? eylan_http_ok($result) : (isset($result['http_code']) && $result['http_code'] >= 200 && $result['http_code'] < 300 && empty($result['error']));
            if ($ok) {
                $delivery = eylan_build_delivery($Get_Data_Panel['name_panel'], $usernameC, $protocol);
                $sub_url = isset($delivery['subscription_url']) ? $delivery['subscription_url'] : '';
                if ($sub_url !== '' && function_exists('eylan_public_url')) {
                    $sub_url = eylan_public_url($sub_url, $Get_Data_Panel);
                }
                $Output['status'] = 'successful';
                $Output['username'] = $usernameC;
                $Output['subscription_url'] = $sub_url;
                // configs خالی — ارسال فقط از eylan_send_to_user (جلوگیری از شلوغی afterPay)
                $Output['configs'] = array();
                if (!empty($result['_body'])) {
                    $Output['_body'] = $result['_body'];
                }
                if (!empty($result['_protocol'])) {
                    $Output['_protocol'] = $result['_protocol'];
                }
            } else {
                $Output['status'] = 'Unsuccessful';
                $Output['msg'] = isset($result['message']) ? $result['message'] : (isset($result['error']) ? $result['error'] : 'Eylan create failed');
            }
PHP;

$dataEylan = <<<'PHP'
        } elseif ($Get_Data_Panel['type'] == "eylan") {
            if (!function_exists('eylan_to_datauser_output')) {
                $Output = array('status' => 'Unsuccessful', 'msg' => 'Eylan.php not loaded');
            } else {
                $inv = isset($invoice) ? $invoice : false;
                $Output = eylan_to_datauser_output($Get_Data_Panel['name_panel'], $username, $inv);
            }
PHP;

// Remove any existing eylan createUser branch then re-insert cleanly
if (preg_match('/\s*\} elseif \(\$Get_Data_Panel\[.type.\] == .eylan.\) \{.*?(?=\n\s*\} else \{\s*\n\s*\$Output\[.status.\])/s', $src, $m, PREG_OFFSET_CAPTURE)) {
    // careful - might match too much; use simpler: from "} elseif ... eylan" until before final createUser else
    $src = preg_replace(
        '/\n\s*\} elseif \(\$Get_Data_Panel\[\'type\'\] == "eylan"\) \{.*?\n(?=\s*\} else \{\s*\n\s*\$Output\[\'status\'\] = \'Unsuccessful\';\s*\n\s*\$Output\[\'msg\'\] = \'Panel Not Found\';)/s',
        "\n",
        $src,
        1
    );
    echo "OK removed old createUser eylan\n";
}

$createAnchor = <<<'PHP'
        } else {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = 'Panel Not Found';
        }
        return $Output;
    }
    function DataUser($name_panel, $username)
PHP;

if (strpos($src, $createAnchor) !== false) {
    if (strpos($src, 'eylan_create_user') !== false) {
        $src = preg_replace(
            '/\n\s*\} elseif \(\$Get_Data_Panel\[\'type\'\] == "eylan"\) \{.*?\n(?=\s*\} else \{\s*\n\s*\$Output\[\'status\'\] = \'Unsuccessful\';\s*\n\s*\$Output\[\'msg\'\] = \'Panel Not Found\';)/s',
            "\n",
            $src,
            1
        );
        echo "OK force-stripped createUser eylan\n";
    }
    if (strpos($src, 'eylan_create_user') === false) {
        $src = str_replace($createAnchor, $createEylan . "\n" . $createAnchor, $src);
        echo "OK createUser\n";
    } else {
        echo "WARN createUser still present after strip\n";
    }
} else {
    echo "FAIL createUser anchor\n";
}

// DataUser
if (strpos($src, 'eylan_to_datauser_output') === false) {
    $dataAnchor = <<<'PHP'
        } else {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => 'Panel Not Found'
            );
        }
        return $Output;
    }
    function Revoke_sub($name_panel, $username)
PHP;
    if (strpos($src, $dataAnchor) !== false) {
        $src = str_replace($dataAnchor, $dataEylan . "\n" . $dataAnchor, $src);
        echo "OK DataUser\n";
    } else {
        echo "SKIP/FAIL DataUser\n";
    }
} else {
    echo "SKIP DataUser\n";
}

$bak = $panels . '.bak_eylan_' . date('YmdHis');
copy($panels, $bak);
file_put_contents($panels, $src);
echo "Backup: $bak\n";
passthru('php -l ' . escapeshellarg($panels), $code);

// Auto-fix inboundid for eylan panels (never leave numeric/empty)
try {
    if (is_file(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    }
    if (isset($pdo) && $pdo instanceof PDO) {
        $rows = $pdo->query("SELECT name_panel, inboundid FROM marzban_panel WHERE type='eylan'")->fetchAll(PDO::FETCH_ASSOC);
        $fixed = 0;
        foreach ($rows as $row) {
            $name = $row['name_panel'] ?? '';
            $cur = trim((string) ($row['inboundid'] ?? ''));
            $need = (preg_match('/^[0-9]+$/', $cur) || $cur === '' || $cur === '0');
            $nl = mb_strtolower($name, 'UTF-8');
            $proto = null;
            if (str_contains($nl, 'wireguard') || str_contains($nl, 'وایر')) $proto = 'wireguard';
            elseif (str_contains($nl, 'openvpn') || str_contains($nl, 'اوپن')) $proto = 'openvpn';
            elseif (str_contains($nl, 'l2tp')) $proto = 'l2tp';
            elseif (str_contains($nl, 'cisco') || str_contains($nl, 'سیسکو')) $proto = 'cisco';
            elseif (str_contains($nl, 'multi') || str_contains($nl, 'مولتی') || str_contains($nl, 'molti')) $proto = 'multi';
            if ($need && $proto) {
                $st = $pdo->prepare("UPDATE marzban_panel SET inboundid=? WHERE type='eylan' AND name_panel=?");
                $st->execute([$proto, $name]);
                echo "FIX inboundid {$name} => {$proto}\n";
                $fixed++;
            } elseif (!$need && in_array($cur, ['openvpn','wireguard','l2tp','cisco','multi'], true)) {
                // ok
            } elseif ($need && !$proto) {
                echo "WARN eylan panel '{$name}' inboundid='{$cur}' — set manually to openvpn|wireguard|l2tp|cisco|multi\n";
            }
        }
        if ($fixed === 0) {
            echo "OK inboundid check (no numeric fix needed)\n";
        }
    }
} catch (Throwable $e) {
    echo "SKIP inboundid fix: " . $e->getMessage() . "\n";
}

exit((int) $code);
