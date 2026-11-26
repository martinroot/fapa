<?php
declare(strict_types=1);
/**
 * READ-ONLY preflight for fapa.cam Kadam campaign 973225 (new API v1).
 * Prints HTTP statuses and whether the campaign is visible to the token.
 * Never writes to Kadam and never prints the bearer.
 */
require_once '/var/www/fapa.cam/cmd/kadam_config.php';

[$bearer, $app, $cli] = fapa_kadam_token();
$camp = FAPA_KADAM_CAMPAIGN_ID;
$base = 'https://partners.kadam.net/api/v1';

function kpost(string $path, array $body, string $bearer): array {
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $bearer,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)$raw];
}

[$code, $raw] = kpost('/campaigns', [
    'page' => 1, 'perPage' => 10,
    'filters' => [
        'dateFrom' => date('Y-m-d', strtotime('-1 day')),
        'dateTo' => date('Y-m-d'),
        'campaignId' => $camp,
    ],
], $bearer);
printf("campaigns campaign=%d HTTP=%d listed=%s\n", $camp, $code,
       strpos($raw, '"id":' . $camp) !== false ? 'yes' : 'no');
echo 'body: ' . substr($raw, 0, 500) . "\n";

[$code2, $raw2] = kpost('/stats/sites', [
    'page' => 1, 'perPage' => 10,
    'filters' => [
        'dateFrom' => date('Y-m-d', strtotime('-1 day')),
        'dateTo' => date('Y-m-d'),
        'campaignIds' => [$camp],
    ],
], $bearer);
printf("stats/sites HTTP=%d\n", $code2);
echo 'body: ' . substr($raw2, 0, 300) . "\n";
