<?php
require_once __DIR__ . '/inc/config.php';
require_auth();

function panel_backup_path(): string
{
    $dir = sys_get_temp_dir() . '/virabot-panel-backup';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir . '/backup_' . date('Y-m-d_His') . '.sql';
}

function panel_mysql_cnf(string $dbhost, string $dbuser, string $dbpass): string
{
    // Option-file values: wrap in double quotes and escape \\ and " so passwords
    // with #, ;, quotes or backslashes survive. Created 0600 before any write.
    $q = function (string $v): string {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $v) . '"';
    };
    $dir = dirname(panel_backup_path());
    $cnf = tempnam($dir, 'cnf_');
    $old = umask(0077);
    chmod($cnf, 0600);
    file_put_contents($cnf, "[client]\nhost=" . $q($dbhost) . "\nuser=" . $q($dbuser) . "\npassword=" . $q($dbpass) . "\n");
    umask($old);
    return $cnf;
}

function panel_sql_dump(PDO $pdo, string $dbname, string $dbhost, string $dbuser, string $dbpass, string $outfile): ?string
{
    $mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    if ($mysqldump !== '') {
        $cnf = panel_mysql_cnf($dbhost, $dbuser, $dbpass);
        $cmd = escapeshellcmd($mysqldump)
            . ' --defaults-extra-file=' . escapeshellarg($cnf)
            . ' --no-tablespaces --single-transaction --routines --triggers '
            . escapeshellarg($dbname)
            . ' > ' . escapeshellarg($outfile)
            . ' 2> ' . escapeshellarg($outfile . '.err');
        exec($cmd, $out, $code);
        @unlink($cnf);
        if ($code === 0 && is_file($outfile) && filesize($outfile) > 50) {
            @unlink($outfile . '.err');
            return null;
        }
        $err = is_file($outfile . '.err') ? trim(file_get_contents($outfile . '.err')) : "mysqldump exit={$code}";
        @unlink($outfile . '.err');
        @unlink($outfile);
        if ($err !== '') {
            // fall through to PDO dump
        }
    }
    $fh = fopen($outfile, 'w');
    if (!$fh) {
        return 'Cannot write dump file';
    }
    fwrite($fh, "-- Virabot SQL backup\n-- DB: {$dbname}\n-- Date: " . date('c') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
        $qt = str_replace('`', '``', $table);
        fwrite($fh, "DROP TABLE IF EXISTS `{$qt}`;\n" . $create[1] . ";\n\n");
        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`', PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row));
            $vals = array_map(function ($v) use ($pdo) {
                return $v === null ? 'NULL' : $pdo->quote($v);
            }, array_values($row));
            fwrite($fh, 'INSERT INTO `' . $qt . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return is_file($outfile) && filesize($outfile) > 50 ? null : 'Dump file is empty';
}

function panel_sql_restore(string $file, string $dbname, string $dbhost, string $dbuser, string $dbpass): ?string
{
    $mysql = trim((string) shell_exec('command -v mysql 2>/dev/null'));
    if ($mysql === '') {
        return 'mysql client is not installed';
    }
    $cnf = panel_mysql_cnf($dbhost, $dbuser, $dbpass);
    $errFile = $cnf . '.err';
    $cmd = escapeshellcmd($mysql)
        . ' --defaults-extra-file=' . escapeshellarg($cnf)
        . ' ' . escapeshellarg($dbname)
        . ' < ' . escapeshellarg($file)
        . ' 2> ' . escapeshellarg($errFile);
    exec($cmd, $out, $code);
    @unlink($cnf);
    $err = is_file($errFile) ? trim(file_get_contents($errFile)) : '';
    @unlink($errFile);
    return $code === 0 ? null : ($err !== '' ? $err : "mysql exit={$code}");
}

if (($_GET['action'] ?? '') === 'download') {
    csrf_check_get();
    global $pdo, $dbname, $dbhost, $usernamedb, $passworddb;
    $file = panel_backup_path();
    $err = panel_sql_dump($pdo, $dbname, $dbhost ?: 'localhost', $usernamedb, $passworddb, $file);
    if ($err !== null) {
        flash('error', 'بکاپ ساخته نشد: ' . $err);
        header('Location: settings.php?tab=backup');
        exit;
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    @unlink($file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    csrf_check_post();
    if (empty($_POST['confirm_restore'])) {
        flash('error', 'برای بارگذاری، تأیید جایگزینی دیتابیس لازم است.');
        header('Location: settings.php?tab=backup');
        exit;
    }
    $up = $_FILES['backup'] ?? null;
    if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', 'فایل بکاپ انتخاب نشده است.');
        header('Location: settings.php?tab=backup');
        exit;
    }
    if (($up['size'] ?? 0) > 80 * 1024 * 1024) {
        flash('error', 'حجم فایل بیشتر از ۸۰ مگابایت است.');
        header('Location: settings.php?tab=backup');
        exit;
    }
    $name = strtolower((string) $up['name']);
    if (!str_ends_with($name, '.sql')) {
        flash('error', 'فقط فایل SQL بکاپ ربات پذیرفته می‌شود.');
        header('Location: settings.php?tab=backup');
        exit;
    }
    $head = file_get_contents($up['tmp_name'], false, null, 0, 4000);
    if ($head === false || !preg_match('/CREATE TABLE|INSERT INTO|DROP TABLE/i', $head)) {
        flash('error', 'این فایل فرمت بکاپ SQL ربات نیست.');
        header('Location: settings.php?tab=backup');
        exit;
    }
    global $dbname, $dbhost, $usernamedb, $passworddb;
    $err = panel_sql_restore($up['tmp_name'], $dbname, $dbhost ?: 'localhost', $usernamedb, $passworddb);
    flash($err === null ? 'success' : 'error', $err === null ? 'بکاپ بارگذاری شد و دیتابیس جایگزین شد.' : 'بارگذاری ناموفق: ' . $err);
    header('Location: settings.php?tab=backup');
    exit;
}

header('Location: settings.php?tab=backup');
exit;
