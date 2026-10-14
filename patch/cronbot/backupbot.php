<?php
/**
 * SilentSell — database backup cron
 * Schedule: PaySetting backup_interval_hours + backup_start_hour
 * Manual/force: ?force=1 or BACKUP_FORCE=1
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../function.php';
if (function_exists('applyBotTimezone')) { applyBotTimezone(); }
$textbotlang = languagechange();
require_once __DIR__ . '/../botapi.php';

$forceBackup = false;
if ((isset($_GET['force']) && $_GET['force'] == '1') || (defined('BACKUP_FORCE') && BACKUP_FORCE)) {
    $forceBackup = true;
}

function mv_backup_setting($name, $default)
{
    try {
        $row = select("PaySetting", "ValuePay", "NamePay", $name, "select");
        if (is_array($row) && isset($row['ValuePay']) && $row['ValuePay'] !== '' && $row['ValuePay'] !== null) {
            return $row['ValuePay'];
        }
    } catch (Throwable $e) {
    }
    return $default;
}

$intervalH = max(1, intval(mv_backup_setting('backup_interval_hours', '5')));
$startH = intval(mv_backup_setting('backup_start_hour', '0'));
if ($startH < 0 || $startH > 23) {
    $startH = 0;
}

$lastRunFile = __DIR__ . '/.last_backup_ts';
$lockFile = __DIR__ . '/.backup_run.lock';
$lockFh = @fopen($lockFile, 'c+');
if ($lockFh) {
    if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
        // نمونهٔ دیگری در حال بکاپ است
        exit;
    }
}

if (!$forceBackup) {
    if (function_exists('applyBotTimezone')) {
        applyBotTimezone();
    }
    $nowH = intval(date('G'));
    $nowM = intval(date('i'));
    // فقط در ساعات مجاز: start, start+interval, ...
    $diff = ($nowH - $startH + 24) % 24;
    if (($diff % $intervalH) !== 0) {
        exit;
    }
    // اگر کرون اشتباهی هر چند دقیقه زده شد، فقط دقایق ۰–۱۰ همان ساعت
    if ($nowM > 12) {
        exit;
    }
    // قفل زمانی: تا قبل از اتمام بازه، بکاپ تکراری نگیر
    $minGap = max(3600, ($intervalH * 3600) - 600); // بازه منهای ۱۰ دقیقه تلورانس
    if (is_file($lastRunFile)) {
        $lastTs = intval(@file_get_contents($lastRunFile));
        if ($lastTs > 0 && (time() - $lastTs) < $minGap) {
            exit;
        }
    }
}

$reportbackup = 0;
try {
    $row = select("topicid", "idreport", "report", "backupfile", "select");
    if (is_array($row) && isset($row['idreport'])) {
        $reportbackup = intval($row['idreport']);
    }
} catch (Throwable $e) {
    $reportbackup = 0;
}

$setting = select("setting", "*");
$channel = is_array($setting) ? ($setting['Channel_Report'] ?? '') : '';

function mv_backup_notify($text, $channel, $thread)
{
    if ($channel === '' || $channel === null) {
        error_log('backupbot: ' . $text);
        return;
    }
    $payload = [
        'chat_id' => $channel,
        'text' => $text,
    ];
    if ($thread > 0) {
        $payload['message_thread_id'] = $thread;
    }
    telegram('sendmessage', $payload);
}

function mv_pdo_dump($pdo, $dbname, $outfile)
{
    $fh = fopen($outfile, 'w');
    if (!$fh) {
        return 'Cannot write dump file: ' . $outfile;
    }
    fwrite($fh, "-- SilentSell PDO backup\n-- DB: {$dbname}\n-- Date: " . date('c') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n");
        $rows = $pdo->query("SELECT * FROM `{$table}`", PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = array_map(function ($c) {
                return '`' . str_replace('`', '``', $c) . '`';
            }, array_keys($row));
            $vals = array_map(function ($v) use ($pdo) {
                if ($v === null) {
                    return 'NULL';
                }
                return $pdo->quote($v);
            }, array_values($row));
            fwrite($fh, 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return null;
}

$destination = __DIR__;
$backup_file_name = $destination . '/backup_' . date('Y-m-d_His') . '.sql';
$dbhost = empty($dbhost) ? 'localhost' : $dbhost;

// Prefer defaults-extra-file so password is not visible in process list / shell breakage
$cnf = $destination . '/.my.cnf.bak.' . getmypid();
$cnfBody = "[client]\nhost={$dbhost}\nuser={$usernamedb}\npassword=\"{$passworddb}\"\n";
@file_put_contents($cnf, $cnfBody);
@chmod($cnf, 0600);

$mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
$ok = false;
$errDetail = '';

if ($mysqldump !== '') {
    $cmd = escapeshellcmd($mysqldump)
        . ' --defaults-extra-file=' . escapeshellarg($cnf)
        . ' --no-tablespaces --single-transaction --routines --triggers '
        . escapeshellarg($dbname)
        . ' > ' . escapeshellarg($backup_file_name)
        . ' 2> ' . escapeshellarg($backup_file_name . '.err');
    $return_var = 0;
    exec($cmd, $output, $return_var);
    @unlink($cnf);
    if ($return_var === 0 && is_file($backup_file_name) && filesize($backup_file_name) > 50) {
        $ok = true;
    } else {
        $errDetail = is_file($backup_file_name . '.err') ? trim(file_get_contents($backup_file_name . '.err')) : "mysqldump exit={$return_var}";
        @unlink($backup_file_name . '.err');
        // retry without single-transaction
        $cmd2 = escapeshellcmd($mysqldump)
            . ' -h ' . escapeshellarg($dbhost)
            . ' -u ' . escapeshellarg($usernamedb)
            . ' -p' . escapeshellarg($passworddb)
            . ' --no-tablespaces '
            . escapeshellarg($dbname)
            . ' > ' . escapeshellarg($backup_file_name)
            . ' 2> ' . escapeshellarg($backup_file_name . '.err');
        exec($cmd2, $output, $return_var);
        if ($return_var === 0 && is_file($backup_file_name) && filesize($backup_file_name) > 50) {
            $ok = true;
            $errDetail = '';
        } else {
            $errDetail = is_file($backup_file_name . '.err') ? trim(file_get_contents($backup_file_name . '.err')) : $errDetail;
            @unlink($backup_file_name . '.err');
        }
    }
} else {
    $errDetail = 'mysqldump not found in PATH';
}
@unlink($cnf);

if (!$ok) {
    // Fallback: pure PHP dump
    $pdoErr = mv_pdo_dump($pdo, $dbname, $backup_file_name);
    if ($pdoErr === null && is_file($backup_file_name) && filesize($backup_file_name) > 50) {
        $ok = true;
        $errDetail = '';
    } else {
        $errDetail = trim($errDetail . ' | PDO: ' . ($pdoErr ?? 'empty file'));
    }
}

if (!$ok) {
    $msg = $textbotlang['keyboard']['backupError'] ?? '❌ خطا در بکاپ گیری';
    $msg .= "\n\n<code>" . htmlspecialchars(mb_substr($errDetail, 0, 500)) . '</code>';
    mv_backup_notify($msg, $channel, $reportbackup);
    error_log('backupbot failed: ' . $errDetail);
    @unlink($backup_file_name);
    exit(1);
}

$caption = 'backup ' . (function_exists('bot_date') ? bot_date('Y/m/d  H:i') : date('Y-m-d H:i'));

/**
 * ارسال امن فایل — فقط یک‌بار به تلگرام و یک‌بار به بله
 * بدون آینهٔ خودکار (جلوگیری از CURLFile دوباره‌مصرف و تکراری)
 */
$GLOBALS['MV_SKIP_MIRROR'] = true;

function mv_send_backup_doc($chatId, $filePath, $caption, $platform, $threadId = 0)
{
    if ($chatId === '' || $chatId === null || $chatId === '0') {
        return ['ok' => false, 'description' => 'empty chat_id'];
    }
    if (!is_file($filePath)) {
        return ['ok' => false, 'description' => 'file missing'];
    }
    $payload = [
        'chat_id' => $chatId,
        'document' => new CURLFile($filePath, 'application/sql', basename($filePath)),
        'caption' => $caption,
    ];
    if ($platform === 'telegram' && intval($threadId) > 0) {
        $payload['message_thread_id'] = intval($threadId);
    }
    $force = ($platform === 'bale') ? 'bale' : null;
    $res = telegram('sendDocument', $payload, null, $force);
    // فقط اگر خطای thread بود بدون thread دوباره امتحان کن — نه روی timeout (تا فایل دوبل نشود)
    if (empty($res['ok']) && $platform === 'telegram' && isset($payload['message_thread_id'])) {
        $desc = strtolower((string) ($res['description'] ?? ''));
        if (strpos($desc, 'thread') !== false || strpos($desc, 'topic') !== false) {
            unset($payload['message_thread_id']);
            $payload['document'] = new CURLFile($filePath, 'application/sql', basename($filePath));
            $res = telegram('sendDocument', $payload, null, null);
        }
    }
    return is_array($res) ? $res : ['ok' => false, 'description' => 'invalid response'];
}

$tgOk = false;
$baleOk = false;
$tgRes = ['ok' => false];
$baleRes = ['ok' => false];

if ($channel !== '' && $channel !== null && $channel !== '0') {
    $tgRes = mv_send_backup_doc($channel, $backup_file_name, $caption, 'telegram', $reportbackup);
    $tgOk = !empty($tgRes['ok']);
    // یک retry فقط اگر aborted — با تأخیر و همان فایل (ریسک دوبل کم با timeout بلند)
    if (!$tgOk) {
        $desc = strtolower((string) ($tgRes['description'] ?? ''));
        if (strpos($desc, 'aborted') !== false || strpos($desc, 'timed out') !== false || strpos($desc, 'timeout') !== false) {
            sleep(3);
            $tgRes = mv_send_backup_doc($channel, $backup_file_name, $caption, 'telegram', $reportbackup);
            $tgOk = !empty($tgRes['ok']);
        }
    }
}

$baleGroup = '';
if (!empty($ENABLE_BALE)) {
    if (!empty($BALE_REPORT_GROUP_ID) && $BALE_REPORT_GROUP_ID !== '0') {
        $baleGroup = (string) $BALE_REPORT_GROUP_ID;
    } else {
        try {
            $br = select("PaySetting", "ValuePay", "NamePay", "bale_report_group_id", "select");
            if (is_array($br) && !empty($br['ValuePay']) && $br['ValuePay'] !== '0') {
                $baleGroup = (string) $br['ValuePay'];
            }
        } catch (Throwable $e) {
        }
    }
}
if ($baleGroup !== '') {
    $baleRes = mv_send_backup_doc($baleGroup, $backup_file_name, $caption, 'bale', 0);
    $baleOk = !empty($baleRes['ok']);
    if (!$baleOk) {
        sleep(2);
        $baleRes = mv_send_backup_doc($baleGroup, $backup_file_name, $caption, 'bale', 0);
        $baleOk = !empty($baleRes['ok']);
    }
}

$GLOBALS['MV_SKIP_MIRROR'] = false;
@unlink($backup_file_name);

if ($tgOk || $baleOk) {
    if (isset($lastRunFile)) {
        @file_put_contents($lastRunFile, (string) time());
    }
    if (!$tgOk) {
        error_log('backupbot telegram fail: ' . json_encode($tgRes));
    }
    if ($baleGroup !== '' && !$baleOk) {
        error_log('backupbot bale fail: ' . json_encode($baleRes));
        // فقط لاگ — پیام خطای اضافه به گروه نفرست تا اسپم نشود
    }
    if ($lockFh) {
        @flock($lockFh, LOCK_UN);
        @fclose($lockFh);
    }
    exit(0);
}

// هر دو ناموفق
$errMsg = ($textbotlang['keyboard']['backupError'] ?? '❌ خطا در بکاپ گیری')
    . "\nTG: " . json_encode($tgRes)
    . "\nBale: " . json_encode($baleRes);
mv_backup_notify($errMsg, $channel, $reportbackup);
if ($lockFh) {
    @flock($lockFh, LOCK_UN);
    @fclose($lockFh);
}
exit(1);
