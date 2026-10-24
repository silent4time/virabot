<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Diagnostic: php eylan_diag.php
 * Shows eylan panels inboundid + optional API status
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/function.php';
if (is_file(__DIR__ . '/Eylan.php')) {
    require_once __DIR__ . '/Eylan.php';
}

echo "=== marzban_panel type=eylan ===\n";
$st = $pdo->query("SELECT name_panel, type, inboundid, url_panel, LEFT(password_panel,6) AS key6 FROM marzban_panel WHERE type='eylan'");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "NO eylan panels found!\n";
} else {
    foreach ($rows as $r) {
        $proto = function_exists('eylan_normalize_protocol') ? eylan_normalize_protocol($r['inboundid'] ?? '') : $r['inboundid'];
        echo "- {$r['name_panel']} | inboundid=[" . ($r['inboundid'] ?? '') . "] => protocol={$proto} | url={$r['url_panel']} | key={$r['key6']}...\n";
        if (function_exists('eylan_protocol_flags')) {
            echo "  flags: " . json_encode(eylan_protocol_flags($proto)) . "\n";
        }
    }
}

echo "\n=== products linked to eylan panels ===\n";
$names = array_column($rows ?: [], 'name_panel');
if ($names) {
    $in = implode(',', array_map(function ($n) use ($pdo) {
        return $pdo->quote($n);
    }, $names));
    $st = $pdo->query("SELECT name_product, Location, code_product FROM product WHERE Location IN ($in) LIMIT 50");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        echo "- product={$p['name_product']} location={$p['Location']} code={$p['code_product']}\n";
    }
}

echo "\n=== unpaid payments ===\n";
$c = $pdo->query("SELECT COUNT(*) FROM Payment_report WHERE payment_Status='Unpaid'")->fetchColumn();
echo "Unpaid count: $c\n";
echo "Done.\n";
