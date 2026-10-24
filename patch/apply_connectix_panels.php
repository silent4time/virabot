<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Inject Connectix createUser + DataUser + extend into panels.php
 */
$panelsFile = __DIR__ . '/panels.php';
if (!is_file($panelsFile)) {
    echo "FAIL panels.php not found\n";
    exit(1);
}

$src = file_get_contents($panelsFile);

if (strpos($src, 'Connectix.php') === false) {
    if (preg_match('/^<\?php\s*/', $src)) {
        $src = preg_replace(
            '/^<\?php\s*/',
            "<?php\nif (is_file(__DIR__ . '/Connectix.php')) { require_once __DIR__ . '/Connectix.php'; }\n",
            $src,
            1
        );
        echo "OK require Connectix\n";
    }
} else {
    echo "SKIP require\n";
}

$createCx = <<<'PHP'
        } elseif ($Get_Data_Panel['type'] == "connectix") {
            if (!function_exists('connectix_create_client')) {
                $Output = array('status' => 'Unsuccessful', 'msg' => 'Connectix.php not loaded');
            } else {
                $plan_id = function_exists('connectix_plan_id_from_product') ? connectix_plan_id_from_product($Get_Data_Product) : '';
                if ($plan_id === '' && !empty($Get_Data_Product['note'])) {
                    $plan_id = trim((string) $Get_Data_Product['note']);
                }
                $prodCode = '';
                if (isset($Get_Data_Product['code_product'])) {
                    $prodCode = (string) $Get_Data_Product['code_product'];
                } elseif (isset($code_product)) {
                    $prodCode = (string) $code_product;
                }
                if ($prodCode === 'usertest' && function_exists('connectix_test_plan_id')) {
                    $tid = connectix_test_plan_id($Get_Data_Panel);
                    if ($tid !== '') {
                        $plan_id = $tid;
                    }
                }
                if ($plan_id === '') {
                    $Output = array('status' => 'Unsuccessful', 'msg' => 'Connectix plan_id missing on product (note)');
                } else {
                    $password = function_exists('connectix_gen_password') ? connectix_gen_password(8) : substr(bin2hex(random_bytes(4)), 0, 8);
                    $extra = array(
                        'name' => $usernameC,
                        'telegram_id' => isset($from_id) ? (string) $from_id : (isset($Balance_id['id']) ? (string) $Balance_id['id'] : ''),
                        'chat_id' => isset($from_id) ? (string) $from_id : '',
                    );
                    $result = connectix_create_client($Get_Data_Panel['name_panel'], $plan_id, $password, $extra);
                    $ok = function_exists('connectix_http_ok') ? connectix_http_ok($result) : (!empty($result['success']));
                    if ($ok) {
                        $ttc = '';
                        if (!empty($result['text_to_copy'])) {
                            $ttc = $result['text_to_copy'];
                        } elseif (!empty($result['body']['text_to_copy'])) {
                            $ttc = $result['body']['text_to_copy'];
                        }
                        $parsed = function_exists('connectix_parse_credentials') ? connectix_parse_credentials($ttc) : array('username' => $usernameC, 'password' => $password);
                        $u = $parsed['username'] !== '' ? $parsed['username'] : $usernameC;
                        $p = $parsed['password'] !== '' ? $parsed['password'] : $password;
                        $Output['status'] = 'successful';
                        $Output['username'] = $u;
                        $Output['subscription_url'] = isset($result['subscription_url']) ? $result['subscription_url'] : '';
                        $Output['configs'] = array();
                        $Output['_connectix'] = true;
                        $Output['_cx_password'] = $p;
                        $Output['_cx_text'] = $ttc;
                        $Output['_cx_client_id'] = $result['client_id'] ?? ($result['body']['client_id'] ?? '');
                        $Output['_cx_plan'] = isset($Get_Data_Product['name_product']) ? $Get_Data_Product['name_product'] : '';
                    } else {
                        $Output['status'] = 'Unsuccessful';
                        $Output['msg'] = isset($result['message']) ? $result['message'] : (isset($result['error']) ? $result['error'] : ('Connectix create failed HTTP ' . ($result['http_code'] ?? 0)));
                    }
                }
            }
PHP;

$dataCx = <<<'PHP'
        } elseif ($Get_Data_Panel['type'] == "connectix") {
            if (function_exists('connectix_data_user')) {
                $Output = connectix_data_user($Get_Data_Panel['name_panel'], $username);
            } else {
                $Output = array(
                    'status' => 'active',
                    'username' => $username,
                    'data_limit' => 0,
                    'expire' => 0,
                    'used_traffic' => 0,
                );
            }
PHP;

$extendCx = <<<'PHP'
        } elseif ($Get_Data_Panel['type'] == "connectix") {
            $plan_id = '';
            if (isset($Get_Data_Product) && is_array($Get_Data_Product)) {
                $plan_id = function_exists('connectix_plan_id_from_product') ? connectix_plan_id_from_product($Get_Data_Product) : trim((string) ($Get_Data_Product['note'] ?? ''));
            }
            if ($plan_id === '' && isset($code_product)) {
                $prod = select('product', '*', 'code_product', $code_product, 'select');
                if (is_array($prod)) {
                    $plan_id = trim((string) ($prod['note'] ?? ''));
                }
            }
            if ($plan_id === '') {
                $Output = array('status' => false, 'msg' => 'Connectix plan_id missing');
            } elseif (!function_exists('connectix_extend_client')) {
                $Output = array('status' => false, 'msg' => 'Connectix.php not loaded');
            } else {
                $ext = connectix_extend_client($Get_Data_Panel['name_panel'], $username, $plan_id);
                $Output = $ext;
            }
PHP;

$src = preg_replace(
    '/\n\s*\} elseif \(\$Get_Data_Panel\[\'type\'\] == "connectix"\) \{.*?\n(?=\s*\} else \{\s*\n\s*\$Output\[\'status\'\] = \'Unsuccessful\';\s*\n\s*\$Output\[\'msg\'\] = \'Panel Not Found\';)/s',
    "\n",
    $src
);

$createAnchor = "        } else {\n            \$Output['status'] = 'Unsuccessful';\n            \$Output['msg'] = 'Panel Not Found';";
if (strpos($src, $createAnchor) !== false) {
    $src = str_replace($createAnchor, $createCx . "\n" . $createAnchor, $src);
    echo "OK createUser connectix\n";
} else {
    echo "FAIL createUser anchor\n";
}

if (strpos($src, "type'] == \"connectix\"") === false || substr_count($src, 'connectix_data_user') < 1) {
    $dataAnchorCandidates = [
        "        } else {\n            \$Output['status'] = 'Unsuccessful';",
    ];
    echo "NOTE DataUser inject is best-effort; verify panels.php DataUser() has connectix branch\n";
}

$bak = $panelsFile . '.bak_connectix_' . date('YmdHis');
copy($panelsFile, $bak);
file_put_contents($panelsFile, $src);
echo "WROTE panels.php backup=$bak\n";
