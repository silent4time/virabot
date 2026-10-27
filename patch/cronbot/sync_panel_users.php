<?php
/* Virabot 5.7.0 — crawl panels, register every username in the central
 * ownership table (local + hub) and import missing users into invoice as
 * ownerless ('unclaimed'). Supported: marzban, rebecca, marzneshin, connectix.
 * Other panel types are skipped. Registered by activecron() every 10 min. */
ini_set('error_log', 'error_log');
ini_set('max_execution_time', '280');
set_time_limit(280);
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
if (is_file(__DIR__ . '/../Connectix.php')) {
    require_once __DIR__ . '/../Connectix.php';
}
if (!function_exists('ps_sync_panel') && is_file(__DIR__ . '/../panel_sync.php')) {
    require_once __DIR__ . '/../panel_sync.php';
}
if (!function_exists('ps_sync_panel')) {
    exit;
}
$textbotlang = languagechange();
ps_migrate($pdo);
if (!ps_enabled()) {
    exit;
}
// one run at a time
$lock = $pdo->query("SELECT GET_LOCK('virabot_panel_sync', 0)")->fetchColumn();
if ((int) $lock !== 1) {
    exit;
}
$started = time();
$report = [];
try {
    $panels = $pdo->query("SELECT * FROM marzban_panel WHERE type IN ('marzban','rebecca','marzneshin','connectix')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($panels as $panel) {
        if (time() - $started > 240) {
            break;
        }
        try {
            $report[] = ps_sync_panel($panel);
        } catch (Throwable $e) {
            error_log('[sync_panel_users] ' . $panel['name_panel'] . ': ' . $e->getMessage());
            $report[] = ['panel' => $panel['name_panel'], 'error' => 'exception'];
        }
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('virabot_panel_sync')");
}
ps_setting_set('last_run', json_encode(['t' => time(), 'panels' => $report], JSON_UNESCAPED_UNICODE));
echo json_encode(['ok' => true, 'panels' => $report], JSON_UNESCAPED_UNICODE);
