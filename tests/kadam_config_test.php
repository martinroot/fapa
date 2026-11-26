<?php
declare(strict_types=1);
require_once __DIR__ . '/../cmd/kadam_config.php';

if (FAPA_KADAM_CAMPAIGN_ID !== 973225) {
    fwrite(STDERR, "FAIL: campaign id\n");
    exit(1);
}
[$b, $app, $cli] = fapa_kadam_token();
if ($b === '' || $app <= 0 || $cli <= 0) {
    fwrite(STDERR, "FAIL: empty token/ids\n");
    exit(1);
}
echo "PASS: campaign=" . FAPA_KADAM_CAMPAIGN_ID . " app=$app token_len=" . strlen($b) . "\n";
