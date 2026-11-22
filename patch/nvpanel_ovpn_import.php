<?php
/**
 * 5.8.6: root-only terminal import of the NV Panel OpenVPN templates.
 *
 *   sudo virabot nvpanel-ovpn-import
 *
 * Reads the encrypted .ovpn profiles from a folder on the server (ViraNet seed names:
 * new-1, new-2, old-1 .. old-8; or s1 .. s7), asks once for the shared key passphrase with echo
 * off, opens each client key in this process (never on a command line, in the environment or in
 * a log), checks it against the client certificate, and writes the templates with the key
 * unencrypted to /etc/virabot/ovpn (outside the web root): directory root:www-data 0750,
 * files root:www-data 0640 (www-data can only read). Nothing is written unless every file of a
 * group is valid. s7 = old-7 (UDP 71) + old-8 (TCP 70) folded into one profile, UDP first.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
    }
    exit;
}

require_once __DIR__ . '/nvpanel_setup.php';
require_once __DIR__ . '/nvpanel_ovpn.php';

const NV_OVPN_PER_REMOTE_TIMEOUT = 15;

/** @return array{0:string,1:string}|null [key PEM, cert block] from a profile. */
function nvpanel_ovpn_imp_blocks(string $tpl): ?array
{
    if (!preg_match('#<key>\s*(.*?)\s*</key>#s', $tpl, $k) || !preg_match('#<cert>\s*(.*?)\s*</cert>#s', $tpl, $c)) {
        return null;
    }
    return [trim($k[1]) . "\n", trim($c[1]) . "\n"];
}

function nvpanel_ovpn_imp_is_encrypted(string $keyPem): bool
{
    return (bool) preg_match('/ENCRYPTED PRIVATE KEY|Proc-Type:\s*4,ENCRYPTED/', $keyPem);
}

/** Unencrypted PKCS#8 PEM of an (optionally) encrypted key, or null. In-process first, then `openssl pkey` over pipes. */
function nvpanel_ovpn_imp_open_key(string $keyPem, string $pass): ?string
{
    $k = @openssl_pkey_get_private($keyPem, $pass);
    if ($k !== false) {
        $out = '';
        if (@openssl_pkey_export($k, $out, null) && str_contains($out, 'PRIVATE KEY') && !nvpanel_ovpn_imp_is_encrypted($out)) {
            return $out;
        }
    }
    while (openssl_error_string() !== false) {
    }
    // Fallback for key formats PHP's OpenSSL build will not open. Passphrase on fd 3, key on stdin.
    $proc = @proc_open(['openssl', 'pkey', '-passin', 'fd:3'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    fwrite($pipes[3], $pass . "\n");
    fclose($pipes[3]);
    fwrite($pipes[0], $keyPem);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----\n?/s', $out, $m)) {
        return null;
    }
    return rtrim($m[0]) . "\n";
}

/** Drop `auth-nocache` lines (never shipped with inline credentials). */
function nvpanel_ovpn_imp_strip_nocache(string $tpl, bool &$stripped = false): string
{
    $out = preg_replace('/^[ \t]*auth-nocache[ \t]*\r?\n?/m', '', $tpl, -1, $n);
    $stripped = $n > 0;
    return (string) $out;
}

/**
 * Template with the client key decrypted, ready for nvpanel_ovpn_build(), or an error code:
 * no_key | bad_passphrase | key_cert_mismatch | bad_template
 * @return array{ok:bool,tpl:string,err:string,nocache:bool}
 */
function nvpanel_ovpn_imp_prepare(string $raw, string $pass): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'tpl' => '', 'err' => $e, 'nocache' => false];
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    if ($raw === '' || strlen($raw) > NV_OVPN_MAX_TEMPLATE) {
        return $fail('bad_template');
    }
    $nocache = false;
    $raw = nvpanel_ovpn_imp_strip_nocache($raw, $nocache);
    $b = nvpanel_ovpn_imp_blocks($raw);
    if ($b === null) {
        return $fail('no_key');
    }
    [$keyPem, $certBlock] = $b;
    $plain = nvpanel_ovpn_imp_is_encrypted($keyPem) ? nvpanel_ovpn_imp_open_key($keyPem, $pass) : nvpanel_ovpn_imp_open_key($keyPem, '');
    if ($plain === null) {
        return $fail('bad_passphrase');
    }
    if (!preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certBlock, $leaf)
        || !@openssl_x509_check_private_key($leaf[0], $plain)) {
        return $fail('key_cert_mismatch');
    }
    $tpl = preg_replace_callback('#<key>.*?</key>#s', static fn () => "<key>\n" . rtrim($plain) . "\n</key>", $raw, 1);
    if (!is_string($tpl) || !nvpanel_ovpn_template_ok($tpl)) {
        return $fail('bad_template');
    }
    return ['ok' => true, 'tpl' => $tpl, 'err' => '', 'nocache' => $nocache];
}

/**
 * Fold profiles of one server that differ only in remote/proto into one profile with every
 * remote in order (port PHP copy of ViraNet backend/app/main.py merge_ovpn). Null if they differ.
 */
function nvpanel_ovpn_imp_merge(array $texts): ?string
{
    $parse = static function (string $text): ?array {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $proto = null;
        foreach ($lines as $l) {
            if (preg_match('/^\s*proto\s+(\S+)\s*$/', $l, $m)) {
                $proto = $m[1];
            }
        }
        $remotes = [];
        $rest = [];
        $first = -1;
        foreach ($lines as $l) {
            if (preg_match('/^\s*remote\s+(\S+)(?:\s+(\S+))?(?:\s+(\S+))?\s*$/', $l, $m)) {
                if ($first < 0) {
                    $first = count($rest);
                }
                $parts = array_filter(['remote', $m[1], ($m[2] ?? '') !== '' ? $m[2] : '1194', ($m[3] ?? '') !== '' ? $m[3] : (string) $proto], 'strlen');
                $remotes[] = implode(' ', $parts);
                continue;
            }
            if (preg_match('/^\s*proto\s+\S+\s*$/', $l) || preg_match('/^\s*(connect-timeout|server-poll-timeout)\s+\S+\s*$/', $l)) {
                continue;
            }
            $rest[] = rtrim($l);
        }
        return $remotes === [] ? null : [$remotes, $rest, max($first, 0)];
    };
    $texts = array_values(array_filter($texts, static fn ($t) => is_string($t) && trim($t) !== ''));
    if ($texts === []) {
        return null;
    }
    if (count($texts) === 1) {
        return $texts[0];
    }
    $parsed = array_map($parse, $texts);
    if (in_array(null, $parsed, true)) {
        return null;
    }
    $body = static fn (array $p): array => array_values(array_filter($p[1], static fn ($l) => trim($l) !== '' && !preg_match('/^\s*[#;]/', $l)));
    foreach ($parsed as $p) {
        if ($body($p) !== $body($parsed[0])) {
            return null;
        }
    }
    $remotes = [];
    foreach ($parsed as $p) {
        foreach ($p[0] as $r) {
            if (!in_array($r, $remotes, true)) {
                $remotes[] = $r;
            }
        }
    }
    if (count($remotes) === 1) {
        return $texts[0];
    }
    [, $rest, $first] = $parsed[0];
    return implode("\n", array_merge(array_slice($rest, 0, $first), $remotes, ['connect-timeout ' . NV_OVPN_PER_REMOTE_TIMEOUT], array_slice($rest, $first)));
}

/**
 * Which source file(s) make each template, from the files present in $dir.
 * @return array<string,array<string,string[]>> group => [template name => source paths]
 */
function nvpanel_ovpn_imp_plan(string $dir): array
{
    $has = static fn (string $n): bool => is_file($dir . '/' . $n . '.ovpn') && !is_link($dir . '/' . $n . '.ovpn');
    $p = static fn (string $n): string => $dir . '/' . $n . '.ovpn';
    $plan = [];
    if ($has('new-1') && $has('new-2')) {
        $plan['mr'] = ['new-1' => [$p('new-1')], 'new-2' => [$p('new-2')]];
    }
    $s = [];
    if (count(array_filter(range(1, 7), static fn ($i) => $has('s' . $i))) === 7) {
        foreach (range(1, 7) as $i) {
            $s['s' . $i] = [$p('s' . $i)];
        }
    } elseif (count(array_filter(range(1, 8), static fn ($i) => $has('old-' . $i))) === 8) {
        foreach (range(1, 6) as $i) {
            $s['s' . $i] = [$p('old-' . $i)];
        }
        $s['s7'] = [$p('old-7'), $p('old-8')]; // UDP 71 first, then TCP 70
    }
    if ($s !== []) {
        $plan['s1s7'] = $s;
    }
    return $plan;
}

/**
 * Build every template of the plan in memory. All-or-nothing per group.
 * @return array{ok:bool,files:array<string,string>,err:string,nocache:bool}
 */
function nvpanel_ovpn_imp_build(array $plan, string $pass): array
{
    $files = [];
    $nocache = false;
    foreach ($plan as $group => $names) {
        foreach ($names as $name => $sources) {
            $prepared = [];
            foreach ($sources as $src) {
                $raw = @file_get_contents($src, false, null, 0, NV_OVPN_MAX_TEMPLATE + 1);
                if (!is_string($raw)) {
                    return ['ok' => false, 'files' => [], 'err' => basename($src) . ': unreadable', 'nocache' => false];
                }
                $r = nvpanel_ovpn_imp_prepare($raw, $pass);
                if (!$r['ok']) {
                    return ['ok' => false, 'files' => [], 'err' => basename($src) . ': ' . $r['err'], 'nocache' => false];
                }
                $nocache = $nocache || $r['nocache'];
                $prepared[] = $r['tpl'];
            }
            $tpl = count($prepared) === 1 ? $prepared[0] : nvpanel_ovpn_imp_merge($prepared);
            if ($tpl === null || !nvpanel_ovpn_template_ok($tpl)) {
                return ['ok' => false, 'files' => [], 'err' => $name . ': profiles could not be merged', 'nocache' => false];
            }
            $files[$name] = rtrim($tpl) . "\n";
        }
    }
    return ['ok' => $files !== [], 'files' => $files, 'err' => $files === [] ? 'no complete group found' : '', 'nocache' => $nocache];
}

/** Atomic per file: temp in the same dir, root:www-data 0640, rename. */
function nvpanel_ovpn_imp_write(string $dir, array $files, ?int $gid): bool
{
    $old = umask(0077);
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return false;
        }
        if (is_link($dir)) {
            return false;
        }
        @chmod($dir, 0750);
        if ($gid !== null) {
            @chown($dir, 0);
            @chgrp($dir, $gid);
        }
        foreach ($files as $name => $body) {
            if (!preg_match('/^(new-[12]|s[1-7])$/', $name)) {
                return false;
            }
            $tmp = $dir . '/.' . $name . '.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $body) !== strlen($body)) {
                @unlink($tmp);
                return false;
            }
            if ($gid !== null) {
                @chown($tmp, 0);
                @chgrp($tmp, $gid);
            }
            @chmod($tmp, 0640);
            if (!@rename($tmp, $dir . '/' . $name . '.ovpn')) {
                @unlink($tmp);
                return false;
            }
        }
        return true;
    } finally {
        umask($old);
    }
}

function nvpanel_ovpn_import_main(): int
{
    $gate = nvpanel_setup_gate(
        function_exists('posix_geteuid') ? (int) posix_geteuid() : 1,
        function_exists('posix_isatty') && posix_isatty(STDIN) && posix_isatty(STDOUT),
        PHP_SAPI,
        isset($_SERVER['REQUEST_METHOD'])
    );
    if ($gate !== null) {
        fwrite(STDERR, "nvpanel-ovpn-import needs root on a real terminal.\n");
        return 1;
    }
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function (): void {
            nvpanel_tty_restore();
            exit(130);
        });
        pcntl_signal(SIGTERM, static function (): void {
            nvpanel_tty_restore();
            exit(143);
        });
    }
    register_shutdown_function('nvpanel_tty_restore');
    $gr = function_exists('posix_getgrnam') ? posix_getgrnam('www-data') : false;
    if (!is_array($gr)) {
        fwrite(STDERR, "Group www-data was not found. Nothing was written.\n");
        return 1;
    }
    fwrite(STDOUT, "NV Panel OpenVPN templates. The passphrase is not shown and is never saved.\n");
    fwrite(STDOUT, "Folder with the .ovpn files (new-1, new-2, old-1..old-8 or s1..s7): ");
    $src = rtrim((string) fgets(STDIN), "\r\n");
    if (!nvpanel_setup_dir_ok($src) || !is_dir($src)) {
        fwrite(STDERR, "Not a folder. Nothing was written.\n");
        return 1;
    }
    $plan = nvpanel_ovpn_imp_plan($src);
    if ($plan === []) {
        fwrite(STDERR, "No complete group: need new-1 and new-2, or old-1..old-8, or s1..s7. Nothing was written.\n");
        return 1;
    }
    foreach ($plan as $g => $names) {
        fwrite(STDOUT, 'Group ' . $g . ': ' . implode(', ', array_keys($names)) . "\n");
    }
    fwrite(STDOUT, "Key passphrase (hidden, empty if the keys are not encrypted): ");
    $pass = nvpanel_read_hidden();
    fwrite(STDOUT, "\n");
    if ($pass === null) {
        fwrite(STDERR, "No passphrase was read. Nothing was written.\n");
        return 1;
    }
    $built = nvpanel_ovpn_imp_build($plan, $pass);
    $pass = str_repeat("\0", strlen($pass));
    unset($pass);
    if (!$built['ok']) {
        $hint = str_contains($built['err'], 'bad_passphrase') ? ' (wrong passphrase?)' : '';
        fwrite(STDERR, 'Rejected: ' . $built['err'] . $hint . ". Nothing was written.\n");
        return 1;
    }
    if (!nvpanel_ovpn_imp_write(NV_OVPN_DIR, $built['files'], (int) $gr['gid'])) {
        fwrite(STDERR, "Could not write " . NV_OVPN_DIR . ".\n");
        return 1;
    }
    $built['files'] = [];
    if ($built['nocache']) {
        fwrite(STDOUT, "Note: auth-nocache lines were removed (they are not used with inline credentials).\n");
    }
    fwrite(STDOUT, 'Saved to ' . NV_OVPN_DIR . " (root:www-data, 0640). Keys inside are not encrypted, so keep this folder private.\n");
    fwrite(STDOUT, "Turn sending on in the bot: NV Panel settings > " . NV_OVPN_BTN . ".\n");
    fwrite(STDOUT, "You can now delete the source folder if you do not need it.\n");
    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(nvpanel_ovpn_import_main());
}
