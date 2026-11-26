<?php
declare(strict_types=1);
require_once '/var/www/fapa.cam/cmd/config_admin.php';
$conn = db_connect('fapaio');
$r = $conn->query("
    SELECT COUNT(*) AS clicks,
           SUM(js = 1) AS js_ok,
           SUM(lead1 > 0) AS lead1, SUM(lead2 > 0) AS lead2,
           SUM(lead3 > 0) AS lead3, SUM(lead4 > 0) AS lead4, SUM(lead5 > 0) AS lead5,
           COALESCE(SUM(cost), 0) AS cost
    FROM clicks
    WHERE campaign_id IN ('973225','1291') AND ts >= NOW() - INTERVAL 1 DAY
");
if (!$r) { fwrite(STDERR, "FAIL: " . $conn->error . "\n"); exit(1); }
$row = $r->fetch_assoc();
foreach (['clicks','js_ok','lead1','lead2','lead3','lead4','lead5','cost'] as $k) {
    if (!isset($row[$k]) || !is_numeric($row[$k])) {
        fwrite(STDERR, "FAIL: column $k\n"); exit(1);
    }
}
echo "PASS: clicks={$row['clicks']} js={$row['js_ok']} lead1={$row['lead1']} cost={$row['cost']}\n";
