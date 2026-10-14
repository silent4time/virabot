<?php
/**
 * SilentSell — bug fixes for upstream files (panels.php, mikrotik.php,
 * IBSng library, config.php). Idempotent: every fix is applied only when its
 * exact anchor is present; files are backed up and linted, and restored if
 * the lint fails.
 */

$root = __DIR__;
$report = [];

function mvf_apply($file, array $fixes, array &$report)
{
    if (!is_file($file)) {
        $report[] = 'SKIP ' . basename($file) . ' (not found)';
        return;
    }
    $src = file_get_contents($file);
    $orig = $src;
    foreach ($fixes as $name => $fix) {
        list($from, $to) = $fix;
        if (strpos($src, $to) !== false) {
            $report[] = "SKIP {$name} (already applied)";
            continue;
        }
        if (strpos($src, $from) === false) {
            $report[] = "MISS {$name} (anchor not found)";
            continue;
        }
        $src = str_replace($from, $to, $src);
        $report[] = "OK   {$name}";
    }
    if ($src === $orig) {
        return;
    }
    $bak = $file . '.bak_fixes_' . date('YmdHis');
    copy($file, $bak);
    file_put_contents($file, $src);
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        copy($bak, $file);
        $report[] = 'FAIL ' . basename($file) . ' lint error, restored: ' . implode(' ', $out);
    }
}

// ---------------------------------------------------------------- panels.php
mvf_apply($root . '/panels.php', [
    'Change_status: return result instead of null' => [
        <<<'PHP'
        $DataUserOut = $this->DataUser($name_panel, $username);
        $Get_Data_Panel = select("marzban_panel", "*", "name_panel", $name_panel, "select");
        if ($DataUserOut['status'] == "Unsuccessful") {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $DataUserOut['detail']
            );
            return;
        }
        if (!in_array($DataUserOut['status'], ["active", "disabled"])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => "status invalid"
            );
            return;
        }
PHP,
        <<<'PHP'
        $DataUserOut = $this->DataUser($name_panel, $username);
        $Get_Data_Panel = select("marzban_panel", "*", "name_panel", $name_panel, "select");
        $Output = array(
            'status' => 'Unsuccessful',
            'msg' => 'panel type not supported'
        );
        if ($DataUserOut['status'] == "Unsuccessful") {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $DataUserOut['msg'] ?? ''
            );
            return $Output;
        }
        if (!in_array($DataUserOut['status'], ["active", "disabled"])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => "status invalid"
            );
            return $Output;
        }
PHP,
    ],
    'extra_time hiddify: days instead of timestamp/GB' => [
        '            $new_limit = ($old_limit_time / pow(1024, 3)) + $limit_time_new;',
        '            $new_limit = (int) ceil(max(0, $old_limit_time - time()) / 86400) + $limit_time_new;',
    ],
    'extra_time marzneshin: ISO expire_date' => [
        <<<'PHP'
            $data = array(
                'expire_date' => $new_limit,
                'expire_strategy' => "fixed_date",
PHP,
        <<<'PHP'
            $data = array(
                'expire_date' => $new_limit == 0 ? null : date('c', $new_limit),
                'expire_strategy' => $new_limit == 0 ? "never" : "fixed_date",
PHP,
    ],
    'extend marzneshin: no 1970 date for unlimited time' => [
        <<<'PHP'
            $expire_strotegy = $time_new == 0 ? "never" : "fixed_date";
            $time_new = date('c', $time_new);
PHP,
        <<<'PHP'
            $expire_strotegy = $time_new == 0 ? "never" : "fixed_date";
            $time_new = $time_new == 0 ? null : date('c', $time_new);
PHP,
    ],
    'createUser ibsng: use product group' => [
        <<<'PHP'
                $name_group = $Get_Data_Panel['inbounds'];
            } elseif ($code_product == "usertest") {
                $name_group = "usertest";
            }
            $data_Output = addUserIBsng(
PHP,
        <<<'PHP'
                $name_group = $Get_Data_Product['inbounds'];
            } elseif ($code_product == "usertest") {
                $name_group = "usertest";
            }
            $data_Output = addUserIBsng(
PHP,
    ],
    'createUser s_ui: use product inbounds' => [
        <<<'PHP'
            if ($Get_Data_Product['inbounds'] != null) {
                $Get_Data_Panel['inbounds'] = $Get_Data_Product['inbounds'];
            }
            $data_Output = addClientS_ui(
PHP,
        <<<'PHP'
            if ($Get_Data_Product['inbounds'] != null) {
                $Get_Data_Panel['proxies'] = $Get_Data_Product['inbounds'];
            }
            $data_Output = addClientS_ui(
PHP,
    ],
    'DataUser alireza: stop when client not found' => [
        <<<'PHP'
            if (!is_array($UsernameData2)) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => "user not found"
                );
            }
PHP,
        <<<'PHP'
            if (!is_array($UsernameData2) || count($UsernameData2) < 2) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => "user not found"
                );
            }
PHP,
    ],
], $report);

// -------------------------------------------------------------- mikrotik.php
mvf_apply($root . '/mikrotik.php', [
    'deleteUser_mikrotik: send the user id' => [
        <<<'PHP'
    $data = array(
        '.id' => $id
        );
    curl_setopt_array($curl, array(
      CURLOPT_URL => $panel['url_panel'].'/rest/user-manager/user/remove',
PHP,
        <<<'PHP'
    $data = array(
        '.id' => $username
        );
    curl_setopt_array($curl, array(
      CURLOPT_URL => $panel['url_panel'].'/rest/user-manager/user/remove',
PHP,
    ],
], $report);

// ------------------------------------------------------------- IBSng library
$ibsDir = $root . '/ibsng';
if (is_dir($ibsDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ibsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $body = file_get_contents($f->getPathname());
        if (strpos($body, 'namespace radiusApi') === false || strpos($body, "throw new Exception('IBSng needs") === false) {
            continue;
        }
        mvf_apply($f->getPathname(), [
            'IBSng: global Exception class' => [
                "throw new Exception('IBSng needs correct login information');",
                "throw new \\Exception('IBSng needs correct login information');",
            ],
        ], $report);
    }
}

// ---------------------------------------------------------------- config.php
$cfg = $root . '/config.php';
if (is_file($cfg)) {
    $c = file_get_contents($cfg);
    if (preg_match('/\n\?>\s*\n(?=[\s\S]*\$LICENSE_ENABLED)/', $c)) {
        $bak = $cfg . '.bak_fixes_' . date('YmdHis');
        copy($cfg, $bak);
        $c = preg_replace('/\n\?>\s*\n(?=[\s\S]*\$LICENSE_ENABLED)/', "\n\n", $c, 1);
        file_put_contents($cfg, $c);
        $out = [];
        $code = 0;
        exec('php -l ' . escapeshellarg($cfg) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            copy($bak, $cfg);
            $report[] = 'FAIL config.php lint error, restored';
        } else {
            $report[] = 'OK   config.php: license settings moved inside PHP';
        }
    } else {
        $report[] = 'SKIP config.php (nothing to fix)';
    }
}

echo implode("\n", $report) . "\n";
