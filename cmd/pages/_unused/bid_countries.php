<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Moscow');

/**
 * DB
 */
define('DB_HOST', 'localhost');
define('DB_USER', 'pma');
define('DB_PASS', 'REPLACE_WITH_DB_PASSWORD');
define('DB_NAME', 'tracker');

function db(): mysqli {
    static $m = null;
    if ($m instanceof mysqli) return $m;
    $m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($m->connect_error) {
        die('DB error: ' . $m->connect_error);
    }
    $m->set_charset('utf8mb4');
    return $m;
}

/**
 * CONFIG
 * Кампания Kadam: 851694 (hotpornmoms.ru)
 * MyBid: токен, берём ВЕСЬ аккаунт (без деления по сайтам)
 */
$KADAM = [
    'app_id'     => 141381,
    'client_id'  => 141381,
    'bearer'     => '532568fbfb2630a8af12c1d00889c3bf',
    'campaignId' => 870724,
    'timezone'   => 3,
];

$MYBID = [
    'base_url' => 'https://publishers.mybid.io/backend/api/public/stats',
    'token'    => 'a65d532ac3dc2c5528aa2dc77e3422f4',
];

const MIN_BID_KADAM = 0.00011;
const MAX_BID_KADAM = 0.00500;

/* ---------- AJAX: апдейт лимита / BID / статуса вверху ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax']) && $_POST['ajax'] === '1'
) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $db = db();

        $cc = strtoupper(trim((string)($_POST['cc'] ?? '')));
        if ($cc === '') {
            throw new RuntimeException('Empty CC');
        }

        $targetClicks = isset($_POST['target_clicks']) ? (int)$_POST['target_clicks'] : 0;
        if ($targetClicks < 0) $targetClicks = 0;

        $baseBidRaw = (string)($_POST['base_bid'] ?? '0');
        $baseBid    = (float)str_replace(',', '.', $baseBidRaw);
        if ($baseBid < 0) $baseBid = 0.0;

        // В Кадам идёт только Base BID, но всё равно зажмём в диапазон
        if ($baseBid > 0 && $baseBid < MIN_BID_KADAM) $baseBid = MIN_BID_KADAM;
        if ($baseBid > MAX_BID_KADAM) $baseBid = MAX_BID_KADAM;

        $status = strtoupper(trim((string)($_POST['status'] ?? 'OFF')));
        if (!in_array($status, ['ON','OFF','LIMIT'], true)) {
            $status = 'OFF';
        }

        $label = $cc;
        $resMap = $db->query(
            "SELECT title FROM kadam_geolist WHERE cc='" . $db->real_escape_string($cc) . "' LIMIT 1"
        );
        if ($resMap && $row = $resMap->fetch_assoc()) {
            $t = trim((string)$row['title']);
            if ($t !== '') $label = $t;
        }

        $sql = sprintf(
            "INSERT INTO mybid_kadam_geo_plan_851694
               (cc,label,target_clicks,base_bid,min_bid,max_bid,current_bid,status,updated_at)
             VALUES ('%s','%s',%d,%.6f,0,0,%.6f,'%s',NOW())
             ON DUPLICATE KEY UPDATE
               label         = VALUES(label),
               target_clicks = VALUES(target_clicks),
               base_bid      = VALUES(base_bid),
               current_bid   = VALUES(current_bid),
               status        = VALUES(status),
               updated_at    = NOW()",
            $db->real_escape_string($cc),
            $db->real_escape_string($label),
            $targetClicks,
            $baseBid,
            $baseBid,
            $db->real_escape_string($status)
        );
        if (!$db->query($sql)) {
            throw new RuntimeException('DB error: ' . $db->error);
        }

        echo json_encode([
            'ok'            => 1,
            'cc'            => $cc,
            'target_clicks' => $targetClicks,
            'base_bid'      => $baseBid,
            'status'        => $status,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode([
            'ok'    => 0,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- MyBid HTTP ---------- */

function mybid_http_get_json(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("MyBid cURL error: $err");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("MyBid HTTP $code, body: " . $body);
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("MyBid JSON decode error: " . json_last_error_msg() . " body: " . $body);
    }
    return $data;
}

/**
 * Стата MyBid по странам за период по ВСЕМУ аккаунту.
 */
function mybid_get_country_stats(string $token, string $baseUrl, string $date1, string $date2, int $limit = 1000): array {
    $params = [
        'token'   => $token,
        'date1'   => $date1,
        'date2'   => $date2,
        'fields'  => 'country,impressions,clicks,money',
        'orderBy' => '-money',
        'limit'   => $limit,
        'offset'  => 0,
    ];
    $url  = rtrim($baseUrl, '/') . '?' . http_build_query($params);
    $resp = mybid_http_get_json($url);

    $rows = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : $resp;

    $agg = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $cc = isset($r['country']) && $r['country'] !== '' ? strtoupper((string)$r['country']) : 'ZZ';

        $impr   = isset($r['impressions']) ? (float)$r['impressions'] : 0.0;
        $clicks = isset($r['clicks'])      ? (float)$r['clicks']      : 0.0;
        $money  = isset($r['money'])       ? (float)$r['money']       : 0.0;

        if (!isset($agg[$cc])) {
            $agg[$cc] = [
                'country'     => $cc,
                'impressions' => 0.0,
                'clicks'      => 0.0,
                'money'       => 0.0,
            ];
        }
        $agg[$cc]['impressions'] += $impr;
        $agg[$cc]['clicks']      += $clicks;
        $agg[$cc]['money']       += $money;
    }

    foreach ($agg as &$row) {
        $impr = $row['impressions'];
        $clk  = $row['clicks'];
        $mon  = $row['money'];

        $row['ctr'] = ($impr > 0) ? ($clk / $impr * 100.0)   : 0.0;
        $row['cpc'] = ($clk  > 0) ? ($mon / $clk)            : 0.0;
        $row['epm'] = ($impr > 0) ? ($mon / $impr * 1000.0)  : 0.0;
        $row['epc'] = ($clk  > 0) ? ($mon / $clk)            : 0.0;
    }
    unset($row);

    usort($agg, function($a, $b){
        return $b['epm'] <=> $a['epm'];
    });

    return $agg;
}

/* ---------- Kadam HTTP ---------- */

function kadam_get(string $endpoint, array $params, string $bearer, int $timeout = 30): array {
    $url = 'https://api.kadam.net/' . $endpoint . '?' . http_build_query($params, '', '&');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearer],
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'http' => $http,
        'err'  => $err ?: null,
        'raw'  => $raw,
        'json' => json_decode($raw, true),
    ];
}

function kadam_post_update_campaign(array $data, array $cfgKadam, int $timeout = 30): array {
    $url = 'https://api.kadam.net/ads.campaigns.update';

    $fields = [
        'app_id' => $cfgKadam['app_id'],
        'data'   => json_encode($data, JSON_UNESCAPED_UNICODE),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfgKadam['bearer'],
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8'
        ],
        CURLOPT_POSTFIELDS     => http_build_query($fields, '', '&'),
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'http' => $http,
        'err'  => $err ?: null,
        'raw'  => $raw,
        'json' => json_decode($raw, true),
    ];
}

/* ---------- helpers ---------- */

function fmt(float $v, int $dec = 2): string {
    return number_format($v, $dec, '.', ' ');
}

function get_epm_range(array $rows): array {
    $min = null;
    $max = null;
    foreach ($rows as $r) {
        $e = $r['epm'] ?? 0.0;
        if ($min === null || $e < $min) $min = $e;
        if ($max === null || $e > $max) $max = $e;
    }
    if ($min === null) $min = 0.0;
    if ($max === null) $max = 0.0;
    return [$min, $max];
}

function epm_to_hsl_bg(float $epm, float $minEpm, float $maxEpm): string {
    if ($maxEpm <= $minEpm) {
        $t = 0.5;
    } else {
        $t = ($epm - $minEpm) / ($maxEpm - $minEpm);
        if ($t < 0) $t = 0;
        if ($t > 1) $t = 1;
    }
    $hue = (int)round(0 + 120 * $t);
    return "hsl({$hue},70%,85%)";
}

/**
 * Градиент по ROI (красный → белый → зелёный)
 */
function roi_css($roi): string {
    if ($roi === null) return '';
    $roi = max(-100, min(100, (float)$roi));

    if ($roi < 0) {
        $t = ($roi + 100) / 100.0;
        $r = 255;
        $g = 77 + (255 - 77) * $t;
        $b = 77 + (255 - 77) * $t;
    } else {
        $t = $roi / 100.0;
        $r = 255 - (255 - 77) * $t;
        $g = 255;
        $b = 255 - (255 - 77) * $t;
    }
    return sprintf('background: rgb(%d,%d,%d);', (int)$r, (int)$g, (int)$b);
}

/* ---------- даты ---------- */

$today   = date('Y-m-d');
$monthStartDefault = date('Y-m-01');

$date1 = isset($_GET['date1']) ? preg_replace('~[^0-9\-]~', '', $_GET['date1']) : $monthStartDefault;
$date2 = isset($_GET['date2']) ? preg_replace('~[^0-9\-]~', '', $_GET['date2']) : $today;

if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date1)) $date1 = $monthStartDefault;
if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date2)) $date2 = $today;

// лимит для низа — топ-40 по EPM
$limitBottom = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
if ($limitBottom <= 0)   $limitBottom = 40;
if ($limitBottom > 1000) $limitBottom = 1000;

/* ---------- geo map Kadam ---------- */

$geoMapByRegion = [];
$geoMapByCc     = [];

$resMap = db()->query("SELECT kadam_id, cc, title FROM kadam_geolist");
if ($resMap) {
    while ($r = $resMap->fetch_assoc()) {
        $rid   = (int)$r['kadam_id'];
        $cc    = strtoupper(trim((string)$r['cc']));
        $title = (string)$r['title'];
        if ($rid > 0 && $cc !== '') {
            $label = $title !== '' ? $title : $cc;
            $geoMapByRegion[$rid] = [
                'cc'    => $cc,
                'title' => $label,
            ];
            $geoMapByCc[$cc] = [
                'kadam_id' => $rid,
                'title'    => $label,
            ];
        }
    }
}

/* ---------- таблица плана для hotpornmoms (кампания 851694) ---------- */

db()->query("
    CREATE TABLE IF NOT EXISTS mybid_kadam_geo_plan_851694 (
        cc            VARCHAR(5) NOT NULL PRIMARY KEY,
        label         VARCHAR(64) NOT NULL,
        target_clicks INT NOT NULL DEFAULT 0,
        base_bid      DECIMAL(12,6) NOT NULL DEFAULT 0,
        min_bid       DECIMAL(12,6) NOT NULL DEFAULT 0,
        max_bid       DECIMAL(12,6) NOT NULL DEFAULT 0,
        current_bid   DECIMAL(12,6) NOT NULL DEFAULT 0,
        status        ENUM('ON','OFF','LIMIT') NOT NULL DEFAULT 'OFF',
        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

db()->query("ALTER TABLE mybid_kadam_geo_plan_851694 MODIFY COLUMN status ENUM('ON','OFF','LIMIT') NOT NULL DEFAULT 'OFF'");

/* ---------- POST: только нижняя таблица (план LTV) ---------- */

$saveMessageTop    = null; // AJAX вверху, сообщение не нужно
$saveMessagePlan   = null;
$kadamUpdateResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['ajax']))) {
    if (isset($_POST['save_plan'])) {
        $targets = $_POST['plan_target']    ?? [];
        $baseB   = $_POST['plan_base_bid']  ?? [];
        $minB    = $_POST['plan_min_bid']   ?? [];
        $maxB    = $_POST['plan_max_bid']   ?? [];
        $status  = $_POST['plan_status']    ?? [];

        $db = db();
        $values = [];

        foreach ($targets as $cc => $tStr) {
            $cc = strtoupper(trim((string)$cc));
            if ($cc === '') continue;

            $targetClicks = (int)$tStr;
            if ($targetClicks < 0) $targetClicks = 0;

            $bBase = isset($baseB[$cc]) ? (float)str_replace(',', '.', (string)$baseB[$cc]) : 0.0;
            $bMin  = isset($minB[$cc])  ? (float)str_replace(',', '.', (string)$minB[$cc])  : 0.0;
            $bMax  = isset($maxB[$cc])  ? (float)str_replace(',', '.', (string)$maxB[$cc])  : 0.0;

            if ($bBase < 0) $bBase = 0.0;
            if ($bMin  < 0) $bMin  = 0.0;
            if ($bMax  < 0) $bMax  = 0.0;

            if ($bBase > 0 && $bBase < MIN_BID_KADAM) $bBase = MIN_BID_KADAM;
            if ($bBase > MAX_BID_KADAM)               $bBase = MAX_BID_KADAM;

            if ($bMin > MAX_BID_KADAM) $bMin = MAX_BID_KADAM;
            if ($bMax > MAX_BID_KADAM) $bMax = MAX_BID_KADAM;

            $st = isset($status[$cc]) ? strtoupper(trim((string)$status[$cc])) : 'OFF';
            if (!in_array($st, ['ON','OFF','LIMIT'], true)) {
                $st = 'OFF';
            }

            $label = isset($geoMapByCc[$cc]) ? $geoMapByCc[$cc]['title'] : $cc;

            $values[] = sprintf(
                "('%s','%s',%d,%.6f,%.6f,%.6f,%.6f,'%s')",
                $db->real_escape_string($cc),
                $db->real_escape_string($label),
                $targetClicks,
                $bBase,
                $bMin,
                $bMax,
                $bBase,
                $db->real_escape_string($st)
            );
        }

        if ($values) {
            $sql = "
                INSERT INTO mybid_kadam_geo_plan_851694
                  (cc,label,target_clicks,base_bid,min_bid,max_bid,current_bid,status)
                VALUES ".implode(',', $values)."
                ON DUPLICATE KEY UPDATE
                  label         = VALUES(label),
                  target_clicks = VALUES(target_clicks),
                  base_bid      = VALUES(base_bid),
                  min_bid       = VALUES(min_bid),
                  max_bid       = VALUES(max_bid),
                  current_bid   = VALUES(current_bid),
                  status        = VALUES(status),
                  updated_at    = NOW()
            ";
            $db->query($sql);
        }

        $saveMessagePlan = 'План (объём/ставки/статусы) сохранён (нижняя таблица).';
    }
}

/* ---------- MyBid: сегодня (для ROI по странам) ---------- */

try {
    $mybid_today_rows = mybid_get_country_stats($MYBID['token'], $MYBID['base_url'], $today, $today, 1000);
} catch (Throwable $e) {
    $mybid_today_rows = [];
}
$mybid_today_by_cc = [];
foreach ($mybid_today_rows as $r) {
    $cc = strtoupper($r['country'] ?? 'ZZ');
    $mybid_today_by_cc[$cc] = $r;
}

/* ---------- MyBid: период (для низа) ---------- */

try {
    $mybid_period_rows_all = mybid_get_country_stats($MYBID['token'], $MYBID['base_url'], $date1, $date2, 1000);
} catch (Throwable $e) {
    $mybid_period_rows_all = [];
}
[$minEpmPeriod, $maxEpmPeriod] = get_epm_range($mybid_period_rows_all);
$mybid_bottom_rows = array_slice($mybid_period_rows_all, 0, $limitBottom);

$mybid_period_by_cc = [];
foreach ($mybid_period_rows_all as $r) {
    $cc = strtoupper($r['country'] ?? 'ZZ');
    $mybid_period_by_cc[$cc] = $r;
}

/* ---------- Kadam: сегодня по регионам ---------- */

$kTodayParams = [
    'app_id'    => $KADAM['app_id'],
    'client_id' => $KADAM['client_id'],
    'campaigns' => $KADAM['campaignId'],
    'group'     => 'region',
    'date_from' => $today,
    'date_to'   => $today,
    'timezone'  => $KADAM['timezone'],
    'limit'     => 2000,
];

$kTodayRes = kadam_get('ads.stats.campaign.get', $kTodayParams, $KADAM['bearer']);

$kadam_today_by_cc = [];

if ($kTodayRes['http'] === 200 && isset($kTodayRes['json']['response']['items']) && is_array($kTodayRes['json']['response']['items'])) {
    foreach ($kTodayRes['json']['response']['items'] as $it) {
        $regionId = (int)($it['region_id'] ?? 0);
        if ($regionId <= 0) continue;

        if (!isset($geoMapByRegion[$regionId])) continue;

        $cc    = $geoMapByRegion[$regionId]['cc'];

        $views  = (int)($it['views'] ?? 0);
        $clicks = (int)($it['clicks'] ?? 0);
        $spend  = (float)($it['moneyOut'] ?? 0.0);

        if (!isset($kadam_today_by_cc[$cc])) {
            $kadam_today_by_cc[$cc] = [
                'views'  => 0,
                'clicks' => 0,
                'spend'  => 0.0,
            ];
        }
        $kadam_today_by_cc[$cc]['views']  += $views;
        $kadam_today_by_cc[$cc]['clicks'] += $clicks;
        $kadam_today_by_cc[$cc]['spend']  += $spend;
    }
}

/* ---------- читаем план для hotpornmoms ---------- */

$planByCc = [];
$resPlan = db()->query("SELECT * FROM mybid_kadam_geo_plan_851694");
if ($resPlan) {
    while ($r = $resPlan->fetch_assoc()) {
        $cc = strtoupper($r['cc']);
        $planByCc[$cc] = $r;
    }
}

/* ---------- авто-добавление GEO с кликами в план как LIMIT ---------- */

$db = db();
foreach ($kadam_today_by_cc as $cc => $kt) {
    $ccUp = strtoupper($cc);
    if (isset($planByCc[$ccUp])) continue;

    $label = isset($geoMapByCc[$ccUp]) ? $geoMapByCc[$ccUp]['title'] : $ccUp;
    $sql = sprintf(
        "INSERT INTO mybid_kadam_geo_plan_851694
           (cc,label,target_clicks,base_bid,min_bid,max_bid,current_bid,status,updated_at)
         VALUES ('%s','%s',0,0,0,0,0,'LIMIT',NOW())
         ON DUPLICATE KEY UPDATE
           label=VALUES(label),
           status='LIMIT',
           updated_at=NOW()",
        $db->real_escape_string($ccUp),
        $db->real_escape_string($label)
    );
    $db->query($sql);

    $planByCc[$ccUp] = [
        'cc'            => $ccUp,
        'label'         => $label,
        'target_clicks' => 0,
        'base_bid'      => 0.0,
        'min_bid'       => 0.0,
        'max_bid'       => 0.0,
        'current_bid'   => 0.0,
        'status'        => 'LIMIT',
        'updated_at'    => $today . ' 00:00:00',
    ];
}

/* ---------- верхняя таблица: runtime статус и BID (Только base_bid → Kadam) ---------- */

$runtimeStatusByCc = [];
$countryTarget     = [];
$newCurrentBids    = [];

$totClicksToday = 0;
$totSpendToday  = 0.0;
$totRevToday    = 0.0;

foreach ($planByCc as $cc => $plan) {
    $ccUp = strtoupper($cc);

    $kt = $kadam_today_by_cc[$ccUp] ?? ['views'=>0,'clicks'=>0,'spend'=>0.0];
    $mb = $mybid_today_by_cc[$ccUp] ?? ['money'=>0.0];

    $viewsToday  = (int)$kt['views'];
    $clicksToday = (int)$kt['clicks'];
    $spendToday  = (float)$kt['spend'];
    $revToday    = (float)($mb['money'] ?? 0.0);

    $profitToday = $revToday - $spendToday;
    $roiToday    = ($spendToday > 0) ? ($profitToday / $spendToday * 100.0) : null;

    $totClicksToday += $clicksToday;
    $totSpendToday  += $spendToday;
    $totRevToday    += $revToday;

    $targetClicks = (int)$plan['target_clicks'];
    $planStatus   = (string)$plan['status'];

    $runtimeStatus = $planStatus;

    // авто-LIMIT по достижению цели кликов
    // В плюсе (ROI > 0) не уходим в LIMIT, остаёмся ON
    if (
        $planStatus === 'ON'
        && $targetClicks > 0
        && $clicksToday >= $targetClicks
        && !($roiToday !== null && $roiToday > 0)
    ) {
        $runtimeStatus = 'LIMIT';
    }

    $runtimeStatusByCc[$ccUp] = [
        'status'       => $runtimeStatus,
        'views_today'  => $viewsToday,
        'clicks_today' => $clicksToday,
        'spend_today'  => $spendToday,
        'rev_today'    => $revToday,
        'roi_today'    => $roiToday,
    ];

    // В Kadam идёт ТОЛЬКО base_bid из плана по GEO со статусом ON
    if ($runtimeStatus !== 'ON') {
        continue;
    }

    if (!isset($geoMapByCc[$ccUp])) continue;
    $kid = (int)$geoMapByCc[$ccUp]['kadam_id'];
    if ($kid <= 0) continue;

    $baseBid = (float)$plan['base_bid'];
    if ($baseBid <= 0) continue;

    $bid = $baseBid;
    if ($bid < MIN_BID_KADAM) $bid = MIN_BID_KADAM;
    if ($bid > MAX_BID_KADAM) $bid = MAX_BID_KADAM;

    $countryTarget[$kid] = [
        'bid'      => $bid,
        'leadCost' => 0.01,
    ];
    $newCurrentBids[$ccUp] = $bid;
}

/* ---------- апдейт current_bid и пуш в Kadam по всем ON GEO ---------- */

if ($countryTarget) {
    foreach ($newCurrentBids as $cc => $bidVal) {
        $sql = sprintf(
            "UPDATE mybid_kadam_geo_plan_851694
             SET current_bid=%.6f, updated_at=NOW()
             WHERE cc='%s'",
            $bidVal,
            $db->real_escape_string($cc)
        );
        $db->query($sql);
    }

    $payload = [
        'client_id'     => $KADAM['client_id'],
        'campaign_id'   => $KADAM['campaignId'],
        'countryTarget' => $countryTarget,
    ];
    $kadamUpdateResult = kadam_post_update_campaign($payload, $KADAM);
}

/* ---------- сортировка плана для верхней таблицы по spend+rev ---------- */

uksort($planByCc, function(string $a, string $b) use ($runtimeStatusByCc) {
    $ra = $runtimeStatusByCc[$a] ?? ['spend_today'=>0.0,'rev_today'=>0.0];
    $rb = $runtimeStatusByCc[$b] ?? ['spend_today'=>0.0,'rev_today'=>0.0];
    $va = ($ra['spend_today'] ?? 0.0) + ($ra['rev_today'] ?? 0.0);
    $vb = ($rb['spend_today'] ?? 0.0) + ($rb['rev_today'] ?? 0.0);
    if ($va == $vb) return 0;
    return ($va < $vb) ? 1 : -1;
});

/* ---------- TOTAL по MyBid период ---------- */

$totImprPeriod = 0.0;
$totClkPeriod  = 0.0;
$totMonPeriod  = 0.0;
foreach ($mybid_period_rows_all as $r) {
    $totImprPeriod += (float)($r['impressions'] ?? 0.0);
    $totClkPeriod  += (float)($r['clicks']      ?? 0.0);
    $totMonPeriod  += (float)($r['money']       ?? 0.0);
}
$totCtrPeriod = ($totImprPeriod > 0) ? ($totClkPeriod / $totImprPeriod * 100.0)  : 0.0;
$totCpcPeriod = ($totClkPeriod  > 0) ? ($totMonPeriod / $totClkPeriod)          : 0.0;
$totEpmPeriod = ($totImprPeriod > 0) ? ($totMonPeriod / $totImprPeriod * 1000.0): 0.0;
$totEpcPeriod = ($totClkPeriod  > 0) ? ($totMonPeriod / $totClkPeriod)          : 0.0;

/* ---------- HTML ---------- */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>hotpornmoms.ru — MyBid + Kadam GEO (851694)</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 20px;
        }
        h1 {
            font-size: 20px;
            margin-bottom: 4px;
        }
        h2 {
            font-size: 14px;
            margin-top: 20px;
            margin-bottom: 4px;
            color: #444;
        }
        .meta {
            font-size: 12px;
            color: #777;
            margin-bottom: 10px;
        }
        .filter-form {
            margin-bottom: 10px;
            font-size: 12px;
        }
        .filter-form input[type="date"],
        .filter-form input[type="number"] {
            padding: 4px 6px;
            font-size: 12px;
            border-radius: 4px;
            border: 1px solid #ccc;
            margin-right: 6px;
        }
        .filter-form button,
        .btn {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 4px;
            border: 1px solid #999;
            background: #f5f5f5;
            cursor: pointer;
        }
        .filter-form button:hover,
        .btn:hover {
            background: #e8e8e8;
        }
        .bulk-actions {
            margin: 8px 0 10px 0;
            font-size: 12px;
        }
        .bulk-actions .btn {
            margin-right: 6px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }
        th, td {
            padding: 6px 8px;
            text-align: right;
            font-size: 11px;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f0f3fa;
            font-weight: 600;
            color: #555;
        }
        th:first-child, td:first-child {
            text-align: left;
        }
        tr:last-child td {
            border-bottom: none;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 6px;
            background: #e4e7f2;
            font-size: 11px;
        }
        .nowrap {
            white-space: nowrap;
        }
        .total-row {
            font-weight: 600;
            background: #eef2ff;
        }
        .msg-ok {
            font-size: 12px;
            color: #0a0;
            margin-bottom: 6px;
        }
        .msg-error {
            font-size: 12px;
            color: #c00;
            margin-bottom: 6px;
        }
        .epm-cell {
            font-weight: 600;
        }
        .target-input {
            width: 70px;
            text-align: right;
        }
        .bid-input {
            width: 80px;
            text-align: right;
        }
        .status-select {
            width: 80px;
        }
        pre {
            background: #111;
            color: #eee;
            padding: 10px;
            font-size: 10px;
            overflow: auto;
            max-height: 260px;
        }
        .runtime-limit {
            font-weight: 600;
            color: #c00;
        }
        tr.top-row-updating {
            outline: 2px solid #f0ad4e;
        }
        tr.top-row-ok {
            outline: 2px solid #5cb85c;
            transition: outline 0.5s;
        }
        tr.top-row-error {
            outline: 2px solid #d9534f;
            transition: outline 0.5s;
        }
    </style>
</head>
<body>

<h1>hotpornmoms.ru — MyBid + Kadam GEO (кампания #<?=htmlspecialchars((string)$KADAM['campaignId'])?>)</h1>
<div class="meta">
    Верхняя таблица — <b>сегодня</b> (<?=htmlspecialchars($today)?>): расход Kadam по GEO + доход MyBid по GEO за сегодня, ROI, авто-LIMIT по таргету (но в плюсе GEO не режутся).<br>
    Управление вверху: <b>лимит кликов/день</b>, <b>Base BID</b>, <b>статус</b> — всё через AJAX, без перезагрузки, как в Tacoloco.<br>
    Нижняя таблица — <b>LTV MyBid</b> за период <b><?=htmlspecialchars($date1)?></b> → <b><?=htmlspecialchars($date2)?></b>, топ <?= (int)$limitBottom ?> GEO по EPM.<br>
    В Kadam уходит ТОЛЬКО <b>Base BID</b> из плана по GEO со статусом ON. Cron: <code>php /path/to/this_script.php &gt;/dev/null 2&gt;&amp;1</code>
</div>

<?php if ($saveMessagePlan): ?>
    <div class="msg-ok"><?=htmlspecialchars($saveMessagePlan)?></div>
<?php endif; ?>

<div class="bulk-actions">
    <button type="button" class="btn" id="btn-roi-gt-minus30">
        ROI &gt; -30% → +30% лимита и +0.00001 к Base BID
    </button>
</div>

<h2>Сегодня: Kadam расход / MyBid ревенью / ROI по странам + управление (AJAX)</h2>

<table id="top-table">
    <thead>
    <tr>
        <th>CC</th>
        <th>Label</th>
        <th class="nowrap">Views Kadam</th>
        <th class="nowrap">Clicks Kadam</th>
        <th class="nowrap">Spend Kadam</th>
        <th class="nowrap">CPC Kadam</th>
        <th class="nowrap">Revenue MyBid (сегодня)</th>
        <th class="nowrap">Profit</th>
        <th class="nowrap">ROI %</th>
        <th class="nowrap">Target кликов/день</th>
        <th class="nowrap">Base BID (план)</th>
        <th class="nowrap">Min BID (визуал)</th>
        <th class="nowrap">Max BID (визуал)</th>
        <th class="nowrap">Current BID (факт)</th>
        <th class="nowrap">Статус плана (ON/OFF/LIMIT)</th>
        <th class="nowrap">Статус сегодня (runtime)</th>
    </tr>
    </thead>
    <tbody>
    <?php
    foreach ($planByCc as $cc => $plan):
        $ccUp  = strtoupper($cc);
        $label = $plan['label'] ?? $ccUp;

        $rt = $runtimeStatusByCc[$ccUp] ?? [
            'status'       => $plan['status'],
            'views_today'  => 0,
            'clicks_today' => 0,
            'spend_today'  => 0.0,
            'rev_today'    => 0.0,
            'roi_today'    => null,
        ];

        $viewsToday  = (int)$rt['views_today'];
        $clicksToday = (int)$rt['clicks_today'];
        $spendToday  = (float)$rt['spend_today'];
        $revToday    = (float)$rt['rev_today'];
        $roiToday    = $rt['roi_today'];

        $profitToday = $revToday - $spendToday;
        $cpcToday    = ($clicksToday > 0) ? $spendToday / $clicksToday : 0.0;

        $targetClicks = (int)$plan['target_clicks'];
        $baseBid      = (float)$plan['base_bid'];
        $minBid       = (float)$plan['min_bid'];
        $maxBid       = (float)$plan['max_bid'];
        $curBid       = (float)$plan['current_bid'];
        $statusPlan   = (string)$plan['status'];
        $runtimeSt    = (string)$rt['status'];

        $roiStr  = ($roiToday === null) ? '-' : fmt($roiToday, 2);
        $rowBg   = roi_css($roiToday);

        $roiAttr = ($roiToday === null)
            ? ''
            : number_format($roiToday, 4, '.', '');
    ?>
    <tr data-cc="<?=htmlspecialchars($ccUp)?>"
        data-roi="<?=htmlspecialchars($roiAttr)?>"
        style="<?=$rowBg?>">
        <td><span class="badge"><?=htmlspecialchars($ccUp)?></span></td>
        <td><?=htmlspecialchars($label)?></td>
        <td><?=number_format($viewsToday, 0, '.', ' ')?></td>
        <td><?=number_format($clicksToday, 0, '.', ' ')?></td>
        <td><?=fmt($spendToday, 4)?></td>
        <td><?=fmt($cpcToday, 5)?></td>
        <td><?=fmt($revToday, 4)?></td>
        <td><?=fmt($profitToday, 4)?></td>
        <td><?=$roiStr?></td>
        <td>
            <input
                type="number"
                class="target-input top-target"
                data-cc="<?=htmlspecialchars($ccUp)?>"
                value="<?=htmlspecialchars((string)$targetClicks)?>"
                min="0"
                step="1"
            >
        </td>
        <td>
            <input
                type="number"
                class="bid-input top-bid"
                data-cc="<?=htmlspecialchars($ccUp)?>"
                value="<?=htmlspecialchars(number_format($baseBid, 5, '.', ''))?>"
                step="0.00001"
                min="0"
                max="<?=MAX_BID_KADAM?>"
            >
        </td>
        <td><?=fmt($minBid, 5)?></td>
        <td><?=fmt($maxBid, 5)?></td>
        <td><?=fmt($curBid, 5)?></td>
        <td>
            <select
                class="status-select top-status"
                data-cc="<?=htmlspecialchars($ccUp)?>"
            >
                <option value="ON"    <?=$statusPlan==='ON'?'selected':''?>>ON</option>
                <option value="OFF"   <?=$statusPlan==='OFF'?'selected':''?>>OFF</option>
                <option value="LIMIT" <?=$statusPlan==='LIMIT'?'selected':''?>>LIMIT</option>
            </select>
        </td>
        <td>
            <?php if ($runtimeSt === 'LIMIT'): ?>
                <span class="runtime-limit">LIMIT (auto)</span>
            <?php else: ?>
                <?=htmlspecialchars($runtimeSt)?>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="3">TOTAL</td>
        <td><?=number_format($totClicksToday, 0, '.', ' ')?></td>
        <td><?=fmt($totSpendToday, 4)?></td>
        <td><?=($totClicksToday>0?fmt($totSpendToday/$totClicksToday,5):'-')?></td>
        <td><?=fmt($totRevToday, 4)?></td>
        <td><?=fmt($totRevToday - $totSpendToday, 4)?></td>
        <td><?=($totSpendToday>0?fmt(($totRevToday-$totSpendToday)/$totSpendToday*100,2):'-')?></td>
        <td colspan="7"></td>
    </tr>
    </tbody>
</table>

<h2>MyBid — слепок LTV (EPM / EPC) по странам за период</h2>
<div class="meta">
    Внизу строится таблица «Объём / Страны / Ставки» по MyBid за период (топ GEO по EPM).<br>
    Кнопка заполняет Base BID как EPC/2 (зажатый в MIN/MAX), ставит примерный таргет кликов и включает ON — дальше руками правишь.
</div>

<form method="get" class="filter-form">
    Период MyBid:
    <input type="date" name="date1" value="<?=htmlspecialchars($date1)?>">
    →
    <input type="date" name="date2" value="<?=htmlspecialchars($date2)?>">
    Топ GEO:
    <input type="number" name="limit" min="1" max="1000" value="<?= (int)$limitBottom ?>" style="width:70px;">
    <button type="submit">Обновить</button>
</form>

<form method="post" id="planForm">
    <button type="button" id="btn-fill-bid-from-epc" class="btn">Заполнить Base BID из EPC/2 и включить ON</button>
    <button type="submit" name="save_plan" value="1" class="btn">Сохранить план (объём/ставки/статусы)</button>

    <table id="mybid-bottom-table">
        <thead>
        <tr>
            <th>#</th>
            <th>CC</th>
            <th class="nowrap">Impr (период)</th>
            <th class="nowrap">Clicks</th>
            <th class="nowrap">Revenue</th>
            <th class="nowrap">CTR, %</th>
            <th class="nowrap">EPM</th>
            <th class="nowrap">EPC</th>
            <th class="nowrap">Target кликов/день</th>
            <th class="nowrap">Base BID</th>
            <th class="nowrap">Min BID</th>
            <th class="nowrap">Max BID</th>
            <th class="nowrap">Статус (ON/OFF/LIMIT)</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $i = 0;
        foreach ($mybid_bottom_rows as $r):
            $i++;
            $cc    = strtoupper($r['country'] ?? 'ZZ');
            $impr  = (float)($r['impressions'] ?? 0.0);
            $click = (float)($r['clicks']      ?? 0.0);
            $mon   = (float)($r['money']       ?? 0.0);
            $ctr   = (float)($r['ctr']         ?? 0.0);
            $epm   = (float)($r['epm']         ?? 0.0);
            $epc   = (float)($r['epc']         ?? 0.0);

            $bgEpm = epm_to_hsl_bg($epm, $minEpmPeriod, $maxEpmPeriod);

            $plan = $planByCc[$cc] ?? null;
            $targetPlan = $plan ? (int)$plan['target_clicks'] : 0;
            $basePlan   = $plan ? (float)$plan['base_bid']    : 0.0;
            $minPlan    = $plan ? (float)$plan['min_bid']     : 0.0;
            $maxPlan    = $plan ? (float)$plan['max_bid']     : 0.0;
            $statusPlan = $plan ? (string)$plan['status']     : 'OFF';
        ?>
        <tr data-cc="<?=htmlspecialchars($cc)?>"
            data-epc="<?=htmlspecialchars(number_format($epc, 10, '.', ''))?>">
            <td><?= $i ?></td>
            <td><span class="badge"><?=htmlspecialchars($cc)?></span></td>
            <td><?=number_format($impr, 0, '.', ' ')?></td>
            <td><?=number_format($click, 0, '.', ' ')?></td>
            <td><?=fmt($mon, 4)?></td>
            <td><?=fmt($ctr, 2)?></td>
            <td class="epm-cell" style="background-color: <?=$bgEpm?>;">
                <?=fmt($epm, 2)?>
            </td>
            <td><?=fmt($epc, 5)?></td>
            <td>
                <input
                    type="number"
                    class="target-input"
                    name="plan_target[<?=htmlspecialchars($cc)?>]"
                    value="<?=htmlspecialchars((string)$targetPlan)?>"
                    min="0"
                    step="1"
                >
            </td>
            <td>
                <input
                    type="number"
                    class="bid-input"
                    name="plan_base_bid[<?=htmlspecialchars($cc)?>]"
                    value="<?=htmlspecialchars(number_format($basePlan, 5, '.', ''))?>"
                    step="0.00001"
                    min="0"
                    max="<?=MAX_BID_KADAM?>"
                >
            </td>
            <td>
                <input
                    type="number"
                    class="bid-input"
                    name="plan_min_bid[<?=htmlspecialchars($cc)?>]"
                    value="<?=htmlspecialchars(number_format($minPlan, 5, '.', ''))?>"
                    step="0.00001"
                    min="0"
                    max="<?=MAX_BID_KADAM?>"
                >
            </td>
            <td>
                <input
                    type="number"
                    class="bid-input"
                    name="plan_max_bid[<?=htmlspecialchars($cc)?>]"
                    value="<?=htmlspecialchars(number_format($maxPlan, 5, '.', ''))?>"
                    step="0.00001"
                    min="0"
                    max="<?=MAX_BID_KADAM?>"
                >
            </td>
            <td>
                <select name="plan_status[<?=htmlspecialchars($cc)?>]" class="status-select">
                    <option value="ON"    <?=$statusPlan==='ON'?'selected':''?>>ON</option>
                    <option value="OFF"   <?=$statusPlan==='OFF'?'selected':''?>>OFF</option>
                    <option value="LIMIT"<?=$statusPlan==='LIMIT'?'selected':''?>>LIMIT</option>
                </select>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="2">TOTAL</td>
            <td><?=number_format($totImprPeriod, 0, '.', ' ')?></td>
            <td><?=number_format($totClkPeriod,  0, '.', ' ')?></td>
            <td><?=fmt($totMonPeriod, 4)?></td>
            <td><?=fmt($totCtrPeriod, 2)?></td>
            <td><?=fmt($totEpmPeriod, 2)?></td>
            <td><?=fmt($totEpcPeriod, 5)?></td>
            <td colspan="5"></td>
        </tr>
        </tbody>
    </table>

    <button type="submit" name="save_plan" value="1" class="btn">Сохранить план (объём/ставки/статусы)</button>
</form>

<h2>Ответ Kadam (ads.campaigns.update)</h2>
<pre><?=htmlspecialchars(json_encode($kadamUpdateResult, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre>

<script>
const MIN_BID_KADAM = <?=json_encode(MIN_BID_KADAM)?>;
const MAX_BID_KADAM = <?=json_encode(MAX_BID_KADAM)?>;

/**
 * AJAX обновление верхней строки (лимит / BID / статус)
 */
(function(){
    function sendTopRowUpdate(tr) {
        const cc = tr.dataset.cc || '';
        if (!cc) return;

        const targetInput = tr.querySelector('.top-target');
        const bidInput    = tr.querySelector('.top-bid');
        const statusSel   = tr.querySelector('.top-status');

        const targetVal = targetInput ? targetInput.value : '0';
        const bidVal    = bidInput ? bidInput.value : '0';
        const statusVal = statusSel ? statusSel.value : 'OFF';

        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('cc', cc);
        fd.append('target_clicks', targetVal);
        fd.append('base_bid', bidVal);
        fd.append('status', statusVal);

        tr.classList.remove('top-row-ok', 'top-row-error');
        tr.classList.add('top-row-updating');

        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: fd
        })
        .then(r => r.json().catch(() => ({})))
        .then(js => {
            tr.classList.remove('top-row-updating');
            if (!js || !js.ok) {
                tr.classList.add('top-row-error');
                return;
            }
            // нормализуем значения из ответа
            if (targetInput && typeof js.target_clicks !== 'undefined') {
                targetInput.value = js.target_clicks;
            }
            if (bidInput && typeof js.base_bid !== 'undefined') {
                bidInput.value = (+js.base_bid).toFixed(5);
            }
            if (statusSel && typeof js.status !== 'undefined') {
                statusSel.value = js.status;
            }
            tr.classList.add('top-row-ok');
            setTimeout(() => {
                tr.classList.remove('top-row-ok', 'top-row-error');
            }, 800);
        })
        .catch(() => {
            tr.classList.remove('top-row-updating');
            tr.classList.add('top-row-error');
            setTimeout(() => {
                tr.classList.remove('top-row-ok', 'top-row-error');
            }, 800);
        });
    }

    // листенеры для ручных изменений
    document.querySelectorAll('#top-table tbody tr[data-cc]').forEach(function(tr){
        const targetInput = tr.querySelector('.top-target');
        const bidInput    = tr.querySelector('.top-bid');
        const statusSel   = tr.querySelector('.top-status');

        if (targetInput) {
            targetInput.addEventListener('change', function(){
                sendTopRowUpdate(tr);
            });
            targetInput.addEventListener('blur', function(){
                if (targetInput.dataset._dirty === '1') {
                    sendTopRowUpdate(tr);
                    targetInput.dataset._dirty = '0';
                }
            });
            targetInput.addEventListener('input', function(){
                targetInput.dataset._dirty = '1';
            });
        }

        if (bidInput) {
            bidInput.addEventListener('change', function(){
                sendTopRowUpdate(tr);
            });
            bidInput.addEventListener('blur', function(){
                if (bidInput.dataset._dirty === '1') {
                    sendTopRowUpdate(tr);
                    bidInput.dataset._dirty = '0';
                }
            });
            bidInput.addEventListener('input', function(){
                bidInput.dataset._dirty = '1';
            });
        }

        if (statusSel) {
            statusSel.addEventListener('change', function(){
                sendTopRowUpdate(tr);
            });
        }
    });

    // массовая кнопка: ROI > -30 → +30% таргета и +0.00001 к Base BID
    const btnRoi = document.getElementById('btn-roi-gt-minus30');
    if (btnRoi) {
        btnRoi.addEventListener('click', function(){
            document.querySelectorAll('#top-table tbody tr[data-cc]').forEach(function(tr){
                const roiStr = tr.getAttribute('data-roi') || '';
                if (!roiStr) return;
                let roi = parseFloat(roiStr.replace(',', '.'));
                if (isNaN(roi)) return;

                // условие: ROI > -30
                if (roi <= -30) return;

                const targetInput = tr.querySelector('.top-target');
                const bidInput    = tr.querySelector('.top-bid');

                // +30% к текущему лимиту (минимум +1, если лимит 0)
                if (targetInput) {
                    let t = parseInt(targetInput.value || '0', 10);
                    if (isNaN(t) || t < 0) t = 0;
                    let add = Math.round(t * 0.3);
                    if (add < 1) add = 1;
                    targetInput.value = String(t + add);
                }

                // +0.0001 к Base BID, зажатому в MIN/MAX
                if (bidInput) {
                    let b = parseFloat(bidInput.value || '0');
                    if (isNaN(b)) b = 0;
                    b += 0.00001;
                    if (b < MIN_BID_KADAM) b = MIN_BID_KADAM;
                    if (b > MAX_BID_KADAM) b = MAX_BID_KADAM;
                    bidInput.value = b.toFixed(5);
                }

                // сразу пушим апдейт
                sendTopRowUpdate(tr);
            });
        });
    }
})();

/**
 * Низ: по кнопке — base_bid = EPC/2, min/max вокруг, статус ON, таргет по 3000 / активные GEO
 */
(function(){
    const btn = document.getElementById('btn-fill-bid-from-epc');
    if (!btn) return;

    btn.addEventListener('click', function(){
        const rows = document.querySelectorAll('#mybid-bottom-table tbody tr[data-cc]');

        const active = [];
        rows.forEach(function(tr){
            const cc  = tr.dataset.cc || '';
            const epc = parseFloat(tr.dataset.epc || '0') || 0;
            if (!cc || epc <= 0) return;

            let bid = epc / 2.0;
            if (bid < MIN_BID_KADAM) bid = MIN_BID_KADAM;
            if (bid > MAX_BID_KADAM) bid = MAX_BID_KADAM;

            active.push({ tr, cc, epc, bid });
        });

        if (!active.length) return;

        const TOTAL_TARGET_CLICKS = 3000;
        const perTarget = Math.max(1, Math.round(TOTAL_TARGET_CLICKS / active.length));

        active.forEach(function(item){
            const cc  = item.cc;
            const bid = item.bid;

            const baseInp = item.tr.querySelector('input[name="plan_base_bid['+cc+']"]');
            const minInp  = item.tr.querySelector('input[name="plan_min_bid['+cc+']"]');
            const maxInp  = item.tr.querySelector('input[name="plan_max_bid['+cc+']"]');
            const stSel   = item.tr.querySelector('select[name="plan_status['+cc+']"]');
            const tgtInp  = item.tr.querySelector('input[name="plan_target['+cc+']"]');

            if (baseInp) baseInp.value = bid.toFixed(5);

            let minB = bid * 0.7;
            let maxB = bid * 1.5;
            if (minB < MIN_BID_KADAM) minB = MIN_BID_KADAM;
            if (maxB > MAX_BID_KADAM) maxB = MAX_BID_KADAM;

            if (minInp) minInp.value = minB.toFixed(5);
            if (maxInp) maxInp.value = maxB.toFixed(5);

            if (tgtInp) tgtInp.value = perTarget.toString();
            if (stSel) stSel.value = 'ON';
        });
    });
})();
</script>

</body>
</html>
