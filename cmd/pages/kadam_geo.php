<?php
declare(strict_types=1);

// Если вызываем файл напрямую (минуя menu.php) — подхватим config.php
if (!function_exists('db_connect')) {
    require_once __DIR__ . '/../config_admin.php';
}

global $DB_NAME;
/** @var string $DB_NAME */

$conn = db_connect($DB_NAME);

// ------------------ helpers ------------------
function req_param(string $key, $default = null) {
    return $_REQUEST[$key] ?? $default;
}

// ------------------ CONFIG Kadam ------------------
$KADAM = [
    'app_id'     => 141381,
    'client_id'  => 141381,
    'bearer'     => '532568fbfb2630a8af12c1d00889c3bf',
    'campaignId' => 862575,   // VPN-кампания
];

const KG_MIN_BID = 0.00011;
const KG_MAX_BID = 0.00500;

// --- пороги "плохих GEO" ---
$minClicksJs = 10;
$minClicksL1 = 200;
$minClicksL2 = 300;
$minClicksL3 = 400;
$minClicksL4 = 1000;

$minJsPct  = 30.0;
$minL1Pct  = 7.0;
$minL2Pct  = 3.0;
$minL3Pct  = 1.0;
$minL4Pct  = 0.3;

// ------------------ Kadam HTTP helper ------------------
function kadam_post_update_campaign(array $data, array $cfg, int $timeout = 30): array {
    $url = 'https://api.kadam.net/ads.campaigns.update';

    $fields = [
        'app_id' => $cfg['app_id'],
        'data'   => json_encode($data, JSON_UNESCAPED_UNICODE),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['bearer'],
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
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
        'json' => json_decode((string)$raw, true),
    ];
}

// ------------------ dates (для BL-статистики) ------------------
$today   = (new DateTime('today'))->format('Y-m-d');
$weekAgo = (new DateTime('-7 days'))->format('Y-m-d');

$from = (string)req_param('from', $weekAgo);
$to   = (string)req_param('to',   $today);

try {
    $fromDt = new DateTime($from . ' 00:00:00');
    $toDt   = new DateTime($to   . ' 23:59:59');
} catch (Throwable $e) {
    $fromDt = new DateTime($weekAgo . ' 00:00:00');
    $toDt   = new DateTime($today   . ' 23:59:59');
}

$fromSql = $conn->real_escape_string($fromDt->format('Y-m-d H:i:s'));
$toSql   = $conn->real_escape_string($toDt->format('Y-m-d H:i:s'));

// ------------------ TABLE plan ------------------
$tableName = 'kadam_geo_plan_' . (int)$KADAM['campaignId'];

$conn->query("
    CREATE TABLE IF NOT EXISTS {$tableName} (
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
$conn->query("ALTER TABLE {$tableName} MODIFY COLUMN status ENUM('ON','OFF','LIMIT') NOT NULL DEFAULT 'OFF'");

// ------------------ GEO map из kadam_geolist (все GEO, кроме IN) ------------------
$geoMapByCc = [];
$sqlGeo = "SELECT kadam_id, cc, title FROM kadam_geolist";
if ($resGeo = $conn->query($sqlGeo)) {
    while ($r = $resGeo->fetch_assoc()) {
        $cc = strtoupper(trim((string)$r['cc']));
        if ($cc === '' || $cc === 'IN') continue;
        $title = (string)$r['title'];
        $label = $title !== '' ? $title : $cc;
        $geoMapByCc[$cc] = [
            'kadam_id' => (int)$r['kadam_id'],
            'title'    => $label,
        ];
    }
    $resGeo->free();
}

// авто-добавление всех GEO (кроме IN) в план
foreach ($geoMapByCc as $cc => $info) {
    $label = $info['title'];
    $sql = sprintf(
        "INSERT IGNORE INTO {$tableName}
           (cc,label,target_clicks,base_bid,status,updated_at)
         VALUES ('%s','%s',0,0,'OFF',NOW())",
        $conn->real_escape_string($cc),
        $conn->real_escape_string($label)
    );
    $conn->query($sql);
}

// ------------------ статистика по GEO (для BL) ------------------
$geoStatsByCc = [];
$sqlStats = "
    SELECT 
        COALESCE(NULLIF(UPPER(TRIM(country)),''),'[empty]') AS cc,
        COUNT(*)                         AS clicks,
        COALESCE(SUM(cost),0)            AS cost,
        SUM(js    > 0)                   AS js_cnt,
        SUM(lead4 > 0)                   AS l4_cnt,
        SUM(lead1 > 0)                   AS l1_cnt,
        SUM(lead2 > 0)                   AS l2_cnt,
        SUM(lead3 > 0)                   AS l3_cnt
    FROM clicks
    WHERE ts BETWEEN '{$fromSql}' AND '{$toSql}'
    GROUP BY cc
";
if ($resS = $conn->query($sqlStats)) {
    while ($r = $resS->fetch_assoc()) {
        $cc     = strtoupper(trim((string)$r['cc']));
        if ($cc === '' || $cc === 'IN') continue;

        $clicks = (int)$r['clicks'];
        if ($clicks <= 0) continue;

        $js_cnt = (int)$r['js_cnt'];
        $l1_cnt = (int)$r['l1_cnt'];
        $l2_cnt = (int)$r['l2_cnt'];
        $l3_cnt = (int)$r['l3_cnt'];
        $l4_cnt = (int)$r['l4_cnt'];
        $cost   = (float)$r['cost'];

        $js_pct = $clicks ? $js_cnt / $clicks * 100.0 : 0.0;
        $l1_pct = $clicks ? $l1_cnt / $clicks * 100.0 : 0.0;
        $l2_pct = $clicks ? $l2_cnt / $clicks * 100.0 : 0.0;
        $l3_pct = $clicks ? $l3_cnt / $clicks * 100.0 : 0.0;
        $l4_pct = $clicks ? $l4_cnt / $clicks * 100.0 : 0.0;

        $js_bad = ($clicks >= $minClicksJs && $js_pct < $minJsPct);
        $l1_bad = ($clicks >= $minClicksL1 && $l1_pct < $minL1Pct);
        $l2_bad = ($clicks >= $minClicksL2 && $l2_pct < $minL2Pct);
        $l3_bad = ($clicks >= $minClicksL3 && $l3_pct < $minL3Pct);
        $l4_bad = ($clicks >= $minClicksL4 && $l4_pct < $minL4Pct);

        $rules = [];
        if ($js_bad) $rules[] = 'JS';
        if ($l1_bad) $rules[] = 'L1';
        if ($l2_bad) $rules[] = 'L2';
        if ($l3_bad) $rules[] = 'L3';
        if ($l4_bad) $rules[] = 'L4';

        $geoStatsByCc[$cc] = [
            'clicks' => $clicks,
            'cost'   => $cost,
            'js_pct' => $js_pct,
            'l1_pct' => $l1_pct,
            'l2_pct' => $l2_pct,
            'l3_pct' => $l3_pct,
            'l4_pct' => $l4_pct,
            'is_bad' => (bool)$rules,
            'rules'  => $rules,
        ];
    }
    $resS->free();
}

// ------------------ AJAX: апдейт одной строки ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['ajax'] ?? '') === '1')) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cc = strtoupper(trim((string)($_POST['cc'] ?? '')));
        if ($cc === '') throw new RuntimeException('Empty CC');

        $targetClicks = isset($_POST['target_clicks']) ? (int)$_POST['target_clicks'] : 0;
        if ($targetClicks < 0) $targetClicks = 0;

        $baseBidRaw = (string)($_POST['base_bid'] ?? '0');
        $baseBid    = (float)str_replace(',', '.', $baseBidRaw);
        if ($baseBid < 0) $baseBid = 0.0;

        if ($baseBid > 0 && $baseBid < KG_MIN_BID) $baseBid = KG_MIN_BID;
        if ($baseBid > KG_MAX_BID)                 $baseBid = KG_MAX_BID;

        $status = strtoupper(trim((string)($_POST['status'] ?? 'OFF')));
        if (!in_array($status, ['ON','OFF','LIMIT'], true)) $status = 'OFF';

        $label = isset($geoMapByCc[$cc]) ? $geoMapByCc[$cc]['title'] : $cc;

        $sql = sprintf(
            "INSERT INTO {$tableName}
               (cc,label,target_clicks,base_bid,status,updated_at)
             VALUES ('%s','%s',%d,%.6f,'%s',NOW())
             ON DUPLICATE KEY UPDATE
               label         = VALUES(label),
               target_clicks = VALUES(target_clicks),
               base_bid      = VALUES(base_bid),
               status        = VALUES(status),
               updated_at    = NOW()",
            $conn->real_escape_string($cc),
            $conn->real_escape_string($label),
            $targetClicks,
            $baseBid,
            $conn->real_escape_string($status)
        );

        if (!$conn->query($sql)) throw new RuntimeException('DB error: ' . $conn->error);

        echo json_encode([
            'ok'            => 1,
            'cc'            => $cc,
            'target_clicks' => $targetClicks,
            'base_bid'      => $baseBid,
            'status'        => $status,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ------------------ POST actions ------------------
$saveMessage       = null;
$kadamUpdateResult = null;

// BL OFF: выключить все "плохие" GEO (за период) -> status=OFF
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['ajax'])
    && isset($_POST['bl_off_bad'])
) {
    $badCcs = [];
    foreach ($geoStatsByCc as $cc => $st) {
        if (!empty($st['is_bad'])) $badCcs[] = $cc;
    }
    $badCcs = array_values(array_unique(array_filter($badCcs, fn($x) => $x !== '' && $x !== 'IN')));

    if ($badCcs) {
        $escaped = array_map(fn($cc) => "'" . $conn->real_escape_string($cc) . "'", $badCcs);
        $sqlOff = "UPDATE {$tableName} SET status='OFF', updated_at=NOW() WHERE cc IN (" . implode(',', $escaped) . ")";
        $conn->query($sqlOff);
        $saveMessage = 'BL OFF: выключено GEO = ' . count($badCcs);
    } else {
        $saveMessage = 'BL OFF: плохих GEO не найдено за период.';
    }
}

// массовое сохранение (по кнопке)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['ajax'])
    && isset($_POST['save_plan'])
) {
    $targets = $_POST['plan_target']   ?? [];
    $baseB   = $_POST['plan_base_bid'] ?? [];
    $status  = $_POST['plan_status']   ?? [];

    $values = [];

    foreach ($targets as $cc => $tStr) {
        $cc = strtoupper(trim((string)$cc));
        if ($cc === '' || $cc === 'IN') continue;

        $targetClicks = (int)$tStr;
        if ($targetClicks < 0) $targetClicks = 0;

        $bBase = isset($baseB[$cc]) ? (float)str_replace(',', '.', (string)$baseB[$cc]) : 0.0;
        if ($bBase < 0) $bBase = 0.0;

        if ($bBase > 0 && $bBase < KG_MIN_BID) $bBase = KG_MIN_BID;
        if ($bBase > KG_MAX_BID)               $bBase = KG_MAX_BID;

        $st = isset($status[$cc]) ? strtoupper(trim((string)$status[$cc])) : 'OFF';
        if (!in_array($st, ['ON','OFF','LIMIT'], true)) $st = 'OFF';

        $label = isset($geoMapByCc[$cc]) ? $geoMapByCc[$cc]['title'] : $cc;

        $values[] = sprintf(
            "('%s','%s',%d,%.6f,'%s')",
            $conn->real_escape_string($cc),
            $conn->real_escape_string($label),
            $targetClicks,
            $bBase,
            $conn->real_escape_string($st)
        );
    }

    if ($values) {
        $sql = "
            INSERT INTO {$tableName}
              (cc,label,target_clicks,base_bid,status)
            VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
              label         = VALUES(label),
              target_clicks = VALUES(target_clicks),
              base_bid      = VALUES(base_bid),
              status        = VALUES(status),
              updated_at    = NOW()
        ";
        $conn->query($sql);
    }

    $saveMessage = $saveMessage ?: 'План по GEO сохранён.';
}

// ------------------ читаем план ------------------
$planByCc = [];
$sqlPlan = "SELECT * FROM {$tableName}";
if ($resPlan = $conn->query($sqlPlan)) {
    while ($r = $resPlan->fetch_assoc()) {
        $cc = strtoupper((string)$r['cc']);
        if ($cc === '' || $cc === 'IN') continue;
        $planByCc[$cc] = $r;
    }
    $resPlan->free();
}

// --------- сортировка: по кликам DESC (потом label ASC) ----------
$planRows = array_values($planByCc);
usort($planRows, function(array $a, array $b) use ($geoStatsByCc) {
    $cca = strtoupper((string)($a['cc'] ?? ''));
    $ccb = strtoupper((string)($b['cc'] ?? ''));

    $ca = (int)($geoStatsByCc[$cca]['clicks'] ?? 0);
    $cb = (int)($geoStatsByCc[$ccb]['clicks'] ?? 0);
    if ($ca !== $cb) return $cb <=> $ca; // DESC

    $la = (string)($a['label'] ?? $cca);
    $lb = (string)($b['label'] ?? $ccb);
    return strcasecmp($la, $lb);
});

// ------------------ push to Kadam (только ON + base_bid>0) ------------------
$countryTarget = [];
foreach ($planByCc as $cc => $plan) {
    $status = (string)($plan['status'] ?? 'OFF');
    if ($status !== 'ON') continue;

    $baseBid = (float)($plan['base_bid'] ?? 0.0);
    if ($baseBid <= 0) continue;

    if ($baseBid > 0 && $baseBid < KG_MIN_BID) $baseBid = KG_MIN_BID;
    if ($baseBid > KG_MAX_BID)                 $baseBid = KG_MAX_BID;

    if (!isset($geoMapByCc[$cc])) continue;

    $regionId = (int)$geoMapByCc[$cc]['kadam_id'];
    if ($regionId <= 0) continue;

    $countryTarget[$regionId] = [
        'bid'      => $baseBid,
        'leadCost' => 0.01,
    ];
}

if ($countryTarget) {
    $payload = [
        'client_id'     => $KADAM['client_id'],
        'campaign_id'   => $KADAM['campaignId'],
        'countryTarget' => $countryTarget,
    ];
    $kadamUpdateResult = kadam_post_update_campaign($payload, $KADAM);
}

// ------------------ HTML ------------------
$totalGeo = count($planByCc);
$badCount = 0;
foreach ($geoStatsByCc as $st) if (!empty($st['is_bad'])) $badCount++;
?>
<style>
    tr.kg-row-updating { outline: 2px solid #f0ad4e; }
    tr.kg-row-ok       { outline: 2px solid #28a745; transition: outline .4s; }
    tr.kg-row-error    { outline: 2px solid #dc3545; transition: outline .4s; }
    pre.kadam-json {
        background: #111;
        color: #eee;
        padding: 10px;
        font-size: 11px;
        border-radius: 6px;
        max-height: 260px;
        overflow: auto;
    }
    .kg-badge-rule { font-size: .70rem; }
</style>

<h2 class="mb-3">Kadam GEO bids — кампания #<?=h((string)$KADAM['campaignId'])?></h2>

<p class="text-muted" style="font-size: 0.85rem;">
    В Kadam уходит только <b>base_bid</b> по GEO со статусом <b>ON</b>.<br>
    BL(плохие) считаются по <code>clicks</code> (events js/lead1..lead4) за период: <b><?=h($from)?> … <?=h($to)?></b>.
</p>

<?php if ($saveMessage): ?>
    <div class="alert alert-success py-2"><?=h($saveMessage)?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get" action="menu.php">
            <input type="hidden" name="page" value="kadam_geo">
            <div class="col-12 col-md-3">
                <label class="form-label">Дата с</label>
                <input type="date" name="from" value="<?=h($from)?>" class="form-control">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Дата по</label>
                <input type="date" name="to" value="<?=h($to)?>" class="form-control">
            </div>
            <div class="col-12 col-md-2 mt-4">
                <button type="submit" class="btn btn-primary w-100">Применить</button>
            </div>
            <div class="col-12 col-md-4 mt-4 text-muted" style="font-size:.85rem;">
                Всего GEO: <b><?= (int)$totalGeo ?></b>, BL(плохих) за период: <b><?= (int)$badCount ?></b>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>План по GEO (лимит / Base BID / статус) + BL</span>
        <span class="text-muted" style="font-size: 0.8rem;">Сортировка: <b>по кликам</b></span>
    </div>

    <div class="card-body">
        <form method="post" id="kadamGeoForm">
            <div class="mb-2 d-flex flex-wrap align-items-center gap-2">
                <button type="submit" name="save_plan" value="1" class="btn btn-sm btn-primary">
                    Сохранить план (массово)
                </button>

                <button type="submit" name="bl_off_bad" value="1" class="btn btn-sm btn-danger"
                        title="Выключит (status=OFF) все GEO, которые BL(плохие) за выбранный период">
                    BL OFF (плохие GEO)
                </button>

                <button type="button" class="btn btn-sm btn-secondary" id="btn-make-global">
                    Make global: 300 кликов, BID 0.00011, ON
                </button>

                <button type="button" class="btn btn-sm btn-warning" id="btn-all-on-plus">
                    Всем ON +0.00001
                </button>

                <span class="text-muted" style="font-size: 0.8rem;">
                    (Make global / Всем ON) меняют только форму. Управление — через select/inputs (AJAX).
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle" id="kadam-geo-table">
                    <thead class="table-light">
                    <tr>
                        <th>CC</th>
                        <th>Label</th>
                        <th class="text-end">Target/день</th>
                        <th class="text-end">Base BID</th>
                        <th class="text-center">Статус</th>

                        <th class="text-end">Clicks</th>
                        <th class="text-end">JS%</th>
                        <th class="text-end">L1%</th>
                        <th class="text-end">L2%</th>
                        <th class="text-end">L3%</th>
                        <th class="text-end">L4%</th>
                        <th class="text-center">BL</th>

                        <th class="text-center">Updated</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$planRows): ?>
                        <tr>
                            <td colspan="13" class="text-center text-muted">
                                Нет данных — проверь <code>kadam_geolist</code>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($planRows as $plan):
                            $cc = strtoupper((string)($plan['cc'] ?? ''));
                            $label   = $plan['label'] ?? $cc;
                            $target  = (int)($plan['target_clicks'] ?? 0);
                            $baseBid = (float)($plan['base_bid'] ?? 0);
                            $status  = (string)($plan['status'] ?? 'OFF');
                            $updated = (string)($plan['updated_at'] ?? '');

                            $st = $geoStatsByCc[$cc] ?? null;
                            $isBad = $st ? (bool)$st['is_bad'] : false;
                            $rules = $st ? ($st['rules'] ?? []) : [];
                            $rulesStr = $rules ? implode(',', $rules) : '—';

                            $rowClass = '';
                            if ($isBad) $rowClass = 'table-danger';
                            elseif ($st && (($st['l1_pct'] ?? 0) > 0 || ($st['l2_pct'] ?? 0) > 0 || ($st['l3_pct'] ?? 0) > 0 || ($st['l4_pct'] ?? 0) > 0)) $rowClass = 'table-success';
                            ?>
                            <tr class="<?=h($rowClass)?>" data-cc="<?=h($cc)?>">
                                <td><span class="badge bg-secondary" style="font-size: .75rem;"><?=h($cc)?></span></td>
                                <td><?=h($label)?></td>

                                <td class="text-end">
                                    <input type="number"
                                           class="form-control form-control-sm d-inline-block kg-target"
                                           name="plan_target[<?=h($cc)?>]"
                                           value="<?=h((string)$target)?>"
                                           min="0" step="1"
                                           data-cc="<?=h($cc)?>"
                                           style="width: 90px;">
                                </td>

                                <td class="text-end">
                                    <input type="number"
                                           class="form-control form-control-sm d-inline-block kg-bid"
                                           name="plan_base_bid[<?=h($cc)?>]"
                                           value="<?=h(number_format($baseBid, 5, '.', ''))?>"
                                           step="0.00001" min="0" max="<?=KG_MAX_BID?>"
                                           data-cc="<?=h($cc)?>"
                                           style="width: 95px;">
                                </td>

                                <td class="text-center">
                                    <select class="form-select form-select-sm d-inline-block kg-status"
                                            name="plan_status[<?=h($cc)?>]"
                                            data-cc="<?=h($cc)?>"
                                            style="width: 90px;">
                                        <option value="ON"    <?=$status === 'ON' ? 'selected' : ''?>>ON</option>
                                        <option value="OFF"   <?=$status === 'OFF' ? 'selected' : ''?>>OFF</option>
                                        <option value="LIMIT" <?=$status === 'LIMIT' ? 'selected' : ''?>>LIMIT</option>
                                    </select>
                                </td>

                                <td class="text-end"><?= $st ? number_format((int)$st['clicks']) : '0' ?></td>
                                <td class="text-end"><?= $st ? number_format((float)$st['js_pct'], 1) . '%' : '—' ?></td>
                                <td class="text-end"><?= $st ? number_format((float)$st['l1_pct'], 2) . '%' : '—' ?></td>
                                <td class="text-end"><?= $st ? number_format((float)$st['l2_pct'], 2) . '%' : '—' ?></td>
                                <td class="text-end"><?= $st ? number_format((float)$st['l3_pct'], 2) . '%' : '—' ?></td>
                                <td class="text-end"><?= $st ? number_format((float)$st['l4_pct'], 2) . '%' : '—' ?></td>

                                <td class="text-center">
                                    <?php if ($isBad): ?>
                                        <span class="badge bg-danger kg-badge-rule" title="<?=h($rulesStr)?>"><?=h($rulesStr)?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary kg-badge-rule">ok</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center text-muted" style="font-size: .8rem;"><?=h($updated)?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                <button type="submit" name="save_plan" value="1" class="btn btn-sm btn-primary">
                    Сохранить план (массово)
                </button>
            </div>
        </form>
    </div>
</div>

<h3 class="mt-4 mb-2">Ответ Kadam (ads.campaigns.update)</h3>
<pre class="kadam-json"><?=h(json_encode($kadamUpdateResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre>

<script>
const KG_MIN_BID = <?=json_encode(KG_MIN_BID)?>;
const KG_MAX_BID = <?=json_encode(KG_MAX_BID)?>;

// AJAX URL — вызываем этот же файл напрямую
const KG_AJAX_URL = 'pages/kadam_geo.php';

(function(){
    function sendRowUpdate(tr) {
        const cc = tr.dataset.cc || '';
        if (!cc) return;

        const targetInput = tr.querySelector('.kg-target');
        const bidInput    = tr.querySelector('.kg-bid');
        const statusSel   = tr.querySelector('.kg-status');

        const targetVal = targetInput ? targetInput.value : '0';
        const bidVal    = bidInput ? bidInput.value : '0';
        const statusVal = statusSel ? statusSel.value : 'OFF';

        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('cc', cc);
        fd.append('target_clicks', targetVal);
        fd.append('base_bid', bidVal);
        fd.append('status', statusVal);

        tr.classList.remove('kg-row-ok', 'kg-row-error');
        tr.classList.add('kg-row-updating');

        fetch(KG_AJAX_URL, { method: 'POST', body: fd })
            .then(r => r.json().catch(() => ({})))
            .then(js => {
                tr.classList.remove('kg-row-updating');
                if (!js || !js.ok) {
                    tr.classList.add('kg-row-error');
                    setTimeout(() => tr.classList.remove('kg-row-error'), 1000);
                    return;
                }
                if (targetInput && typeof js.target_clicks !== 'undefined') targetInput.value = js.target_clicks;
                if (bidInput && typeof js.base_bid !== 'undefined') bidInput.value = (+js.base_bid).toFixed(5);
                if (statusSel && typeof js.status !== 'undefined') statusSel.value = js.status;

                tr.classList.add('kg-row-ok');
                setTimeout(() => tr.classList.remove('kg-row-ok'), 800);
            })
            .catch(() => {
                tr.classList.remove('kg-row-updating');
                tr.classList.add('kg-row-error');
                setTimeout(() => tr.classList.remove('kg-row-error'), 1000);
            });
    }

    // listeners на изменение строк
    document.querySelectorAll('#kadam-geo-table tbody tr[data-cc]').forEach(function(tr){
        const targetInput = tr.querySelector('.kg-target');
        const bidInput    = tr.querySelector('.kg-bid');
        const statusSel   = tr.querySelector('.kg-status');

        if (targetInput) targetInput.addEventListener('change', () => sendRowUpdate(tr));

        if (bidInput) {
            bidInput.addEventListener('change', function(){
                let v = parseFloat((bidInput.value || '0').toString().replace(',', '.'));
                if (isNaN(v)) v = 0;
                if (v > 0 && v < KG_MIN_BID) v = KG_MIN_BID;
                if (v > KG_MAX_BID)         v = KG_MAX_BID;
                bidInput.value = v.toFixed(5);
                sendRowUpdate(tr);
            });
        }

        if (statusSel) statusSel.addEventListener('change', () => sendRowUpdate(tr));
    });

    // Make global: 300, 0.00011, ON (только форма)
    const btnGlobal = document.getElementById('btn-make-global');
    if (btnGlobal) {
        btnGlobal.addEventListener('click', function(){
            document.querySelectorAll('#kadam-geo-table tbody tr[data-cc]').forEach(function(tr){
                const targetInput = tr.querySelector('.kg-target');
                const bidInput    = tr.querySelector('.kg-bid');
                const statusSel   = tr.querySelector('.kg-status');

                if (targetInput) targetInput.value = '300';

                if (bidInput) {
                    let v = 0.00011;
                    if (v < KG_MIN_BID) v = KG_MIN_BID;
                    if (v > KG_MAX_BID) v = KG_MAX_BID;
                    bidInput.value = v.toFixed(5);
                }

                if (statusSel) statusSel.value = 'ON';
            });
        });
    }

// Всем ON +0.00001 (ТОЛЬКО для тех, кто уже ON). Если bid=0 -> MIN. Только форма.
const btnAllOnPlus = document.getElementById('btn-all-on-plus');
if (btnAllOnPlus) {
  btnAllOnPlus.addEventListener('click', function () {
    document.querySelectorAll('#kadam-geo-table tbody tr[data-cc]').forEach(function (tr) {
      const bidInput  = tr.querySelector('.kg-bid');
      const statusSel = tr.querySelector('.kg-status');

      // ✅ НЕ меняем статус, работаем ТОЛЬКО если уже ON
      if (!statusSel || statusSel.value !== 'ON') return;

      if (!bidInput) return;

      let v = parseFloat((bidInput.value || '0').toString().replace(',', '.'));
      if (isNaN(v)) v = 0;

      // если bid пустой/0 — ставим MIN, иначе +0.00001
      if (v <= 0) v = KG_MIN_BID;
      else v = v + 0.00001;

      if (v > 0 && v < KG_MIN_BID) v = KG_MIN_BID;
      if (v > KG_MAX_BID)         v = KG_MAX_BID;

      bidInput.value = v.toFixed(5);
    });
  });
}

})();
</script>
<?php
$conn->close();
