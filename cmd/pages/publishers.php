<?php
declare(strict_types=1);

/** @var string $DB_NAME */

$conn = db_connect($DB_NAME);

// ---------- CONFIG: tracker/Kadam ----------
$TRACKER_CAMP_ID   = 16;        // локальный camp_id в tracker/fapaio
$KADAM_CAMPAIGN_ID = 862575;    // ID кампании в Kadam
$KADAM_APP_ID      = 141381;    // app_id
$KADAM_BEARER      = '532568fbfb2630a8af12c1d00889c3bf'; // bearer, как в auto_geo_pub.php
$BL_SOURCE_ID      = 'fapaio';  // что писать в black_list_ts.source_id

// ---------- ensure black_list_ts exists ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS black_list_ts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        pub_name   VARCHAR(255) NOT NULL,
        source_id  VARCHAR(255) NOT NULL DEFAULT '',
        camp_id    INT NOT NULL,
        status_api TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_camp_pub (camp_id, pub_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- helpers ----------
function req_param(string $key, $default = null) {
    return $_REQUEST[$key] ?? $default;
}

function kadam_post_form(string $url, array $fields, string $bearer, int $timeout = 30, int $retries = 2): array {
    $payload = http_build_query($fields, '', '&');

    for ($i = 0; $i <= $retries; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $bearer,
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // на 429/503 пробуем ретраи
        if (in_array($http, [429, 503], true) && $i < $retries) {
            usleep((int)(pow(2, $i) * 250000));
            continue;
        }

        return [
            'http'  => $http,
            'error' => $err ?: null,
            'raw'   => $raw,
            'json'  => json_decode((string)$raw, true),
        ];
    }

    return [
        'http'  => 0,
        'error' => 'retry_exhausted',
        'raw'   => null,
        'json'  => null,
    ];
}

// ---------- фильтры / критерии ----------
$today   = (new DateTime('today'))->format('Y-m-d');
$weekAgo = (new DateTime('-7 days'))->format('Y-m-d');

// Период можно менять через URL/форму
$from = (string)req_param('from', $weekAgo);
$to   = (string)req_param('to',   $today);

// ПОРОГИ ПО КЛИКАМ ДЛЯ КАЖДОГО ИВЕНТА
$minClicksJs = 20;
$minClicksL1 = 50;
$minClicksL2 = 100;
$minClicksL3 = 200;
$minClicksL4 = 300;

// МИНИМАЛЬНЫЕ % (ниже → кандидат в BL)
$minJsPct  = 4.0;
$minL1Pct  = 7.0;
$minL2Pct  = 3.0;
$minL3Pct  = 1.0;
$minL4Pct  = 0.3;

// нормализация дат
try {
    $fromDt = new DateTime($from . ' 00:00:00');
    $toDt   = new DateTime($to   . ' 23:59:59');
} catch (Exception $e) {
    $fromDt = new DateTime($weekAgo . ' 00:00:00');
    $toDt   = new DateTime($today   . ' 23:59:59');
}

$fromSql = $conn->real_escape_string($fromDt->format('Y-m-d H:i:s'));
$toSql   = $conn->real_escape_string($toDt->format('Y-m-d H:i:s'));

// ---------- общая сводка по выбранному периоду ----------
$summary = [
    'clicks' => 0,
    'cost'   => 0.0,
    'js_cnt' => 0,
    'l4_cnt' => 0,
    'l1_cnt' => 0,
    'l2_cnt' => 0,
    'l3_cnt' => 0,
];

$sqlSummary = "
    SELECT
        COUNT(*)                         AS clicks,
        COALESCE(SUM(cost),0)            AS cost,
        SUM(js    > 0)                   AS js_cnt,
        SUM(lead4 > 0)                   AS l4_cnt,
        SUM(lead1 > 0)                   AS l1_cnt,
        SUM(lead2 > 0)                   AS l2_cnt,
        SUM(lead3 > 0)                   AS l3_cnt
    FROM clicks
    WHERE ts BETWEEN '{$fromSql}' AND '{$toSql}'
";
if ($resSum = $conn->query($sqlSummary)) {
    if ($row = $resSum->fetch_assoc()) {
        $summary['clicks'] = (int)($row['clicks'] ?? 0);
        $summary['cost']   = (float)($row['cost'] ?? 0);
        $summary['js_cnt'] = (int)($row['js_cnt'] ?? 0);
        $summary['l4_cnt'] = (int)($row['l4_cnt'] ?? 0);
        $summary['l1_cnt'] = (int)($row['l1_cnt'] ?? 0);
        $summary['l2_cnt'] = (int)($row['l2_cnt'] ?? 0);
        $summary['l3_cnt'] = (int)($row['l3_cnt'] ?? 0);
    }
    $resSum->free();
}
$summary['cpa_pwa'] = ($summary['l4_cnt'] > 0) ? ($summary['cost'] / $summary['l4_cnt']) : null;

// ---------- сводка за последний час ----------
$hourSummary = [
    'clicks' => 0,
    'cost'   => 0.0,
    'js_cnt' => 0,
    'l4_cnt' => 0,
    'l1_cnt' => 0,
    'l2_cnt' => 0,
    'l3_cnt' => 0,
];

$sqlHourSummary = "
    SELECT
        COUNT(*)                         AS clicks,
        COALESCE(SUM(cost),0)            AS cost,
        SUM(js    > 0)                   AS js_cnt,
        SUM(lead4 > 0)                   AS l4_cnt,
        SUM(lead1 > 0)                   AS l1_cnt,
        SUM(lead2 > 0)                   AS l2_cnt,
        SUM(lead3 > 0)                   AS l3_cnt
    FROM clicks
    WHERE ts BETWEEN DATE_SUB(NOW(), INTERVAL 1 HOUR) AND NOW()
";
if ($resH = $conn->query($sqlHourSummary)) {
    if ($rowH = $resH->fetch_assoc()) {
        $hourSummary['clicks'] = (int)($rowH['clicks'] ?? 0);
        $hourSummary['cost']   = (float)($rowH['cost'] ?? 0);
        $hourSummary['js_cnt'] = (int)($rowH['js_cnt'] ?? 0);
        $hourSummary['l4_cnt'] = (int)($rowH['l4_cnt'] ?? 0);
        $hourSummary['l1_cnt'] = (int)($rowH['l1_cnt'] ?? 0);
        $hourSummary['l2_cnt'] = (int)($rowH['l2_cnt'] ?? 0);
        $hourSummary['l3_cnt'] = (int)($rowH['l3_cnt'] ?? 0);
    }
    $resH->free();
}
$hourSummary['cpa_pwa'] = ($hourSummary['l4_cnt'] > 0) ? ($hourSummary['cost'] / $hourSummary['l4_cnt']) : null;

// ---------- снимок статистики по паблишерам ----------
$sql = "
    SELECT 
        COALESCE(NULLIF(c1,''),'[empty]') AS publisher,
        COUNT(*)                         AS clicks,
        COALESCE(SUM(cost),0)            AS cost,
        SUM(js    > 0)                   AS js_cnt,
        SUM(lead4 > 0)                   AS l4_cnt,
        SUM(lead1 > 0)                   AS l1_cnt,
        SUM(lead2 > 0)                   AS l2_cnt,
        SUM(lead3 > 0)                   AS l3_cnt
    FROM clicks
    WHERE ts BETWEEN '{$fromSql}' AND '{$toSql}'
    GROUP BY publisher
    ORDER BY clicks DESC
";

$res = $conn->query($sql);

$rows = [];
if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
        $clicks = (int)$r['clicks'];
        if ($clicks <= 0) continue;

        $js_cnt = (int)$r['js_cnt'];
        $l4_cnt = (int)$r['l4_cnt'];
        $l1_cnt = (int)$r['l1_cnt'];
        $l2_cnt = (int)$r['l2_cnt'];
        $l3_cnt = (int)$r['l3_cnt'];
        $cost   = (float)$r['cost'];

        $js_pct = $clicks > 0 ? $js_cnt / $clicks * 100.0 : 0.0;
        $l4_pct = $clicks > 0 ? $l4_cnt / $clicks * 100.0 : 0.0; // PWA (lead4%)
        $l1_pct = $clicks > 0 ? $l1_cnt / $clicks * 100.0 : 0.0;
        $l2_pct = $clicks > 0 ? $l2_cnt / $clicks * 100.0 : 0.0;
        $l3_pct = $clicks > 0 ? $l3_cnt / $clicks * 100.0 : 0.0;

        // CPA PWA = spend/lead4
        $cpa_pwa = ($l4_cnt > 0) ? ($cost / $l4_cnt) : null;

        // критерии "плохого" паблишера под BL:
        $js_bad = ($clicks >= $minClicksJs && $js_pct < $minJsPct);
        $l1_bad = ($clicks >= $minClicksL1 && $l1_pct < $minL1Pct);
        $l2_bad = ($clicks >= $minClicksL2 && $l2_pct < $minL2Pct);
        $l3_bad = ($clicks >= $minClicksL3 && $l3_pct < $minL3Pct);
        $l4_bad = ($clicks >= $minClicksL4 && $l4_pct < $minL4Pct);

        $isBad = ($js_bad || $l1_bad || $l2_bad || $l3_bad || $l4_bad);

        $rows[] = [
            'publisher' => $r['publisher'],
            'clicks'    => $clicks,
            'cost'      => $cost,

            'js_cnt'    => $js_cnt,
            'l4_cnt'    => $l4_cnt,
            'l1_cnt'    => $l1_cnt,
            'l2_cnt'    => $l2_cnt,
            'l3_cnt'    => $l3_cnt,

            'js_pct'    => $js_pct,
            'l4_pct'    => $l4_pct,
            'l1_pct'    => $l1_pct,
            'l2_pct'    => $l2_pct,
            'l3_pct'    => $l3_pct,

            'cpa_pwa'   => $cpa_pwa,

            'js_bad'    => $js_bad,
            'l4_bad'    => $l4_bad,
            'l1_bad'    => $l1_bad,
            'l2_bad'    => $l2_bad,
            'l3_bad'    => $l3_bad,

            'is_bad'    => $isBad,
            'in_bl'     => false,
        ];
    }
}
if ($res) $res->free();

// индексация по publisher
$rowsByPub = [];
foreach ($rows as $idx => $r) {
    $rowsByPub[$r['publisher']] = $idx;
}

$totalPublishers = count($rows);
$badCount = 0;
foreach ($rows as $r) {
    if ($r['is_bad']) $badCount++;
}

// ---------- Black List: амнистия + авто-добавление + полный update в Kadam ----------
$kadamUpdateResult = null;

// 1) текущий BL из базы для camp_id = TRACKER_CAMP_ID
$currentBL = [];
$sqlBlAll = "SELECT pub_name FROM black_list_ts WHERE camp_id = {$TRACKER_CAMP_ID}";
if ($resBlAll = $conn->query($sqlBlAll)) {
    while ($br = $resBlAll->fetch_assoc()) {
        $pubName = (string)$br['pub_name'];
        if ($pubName === '') continue;
        $currentBL[$pubName] = true;
    }
    $resBlAll->free();
}

// 2) АМНИСТИЯ
$amnestied = 0;
foreach (array_keys($currentBL) as $pubName) {
    $idx = $rowsByPub[$pubName] ?? null;
    $stillBad = false;
    if ($idx !== null) $stillBad = !empty($rows[$idx]['is_bad']);

    if ($idx !== null && !$stillBad) {
        $pubEsc = $conn->real_escape_string((string)$pubName);
        $sqlDel = "DELETE FROM black_list_ts WHERE camp_id = {$TRACKER_CAMP_ID} AND pub_name = '{$pubEsc}'";
        if ($conn->query($sqlDel)) {
            unset($currentBL[$pubName]);
            $amnestied++;
        }
    }
}

// 3) новые кандидаты
$newAdded = 0;
foreach ($rows as $r) {
    if (!$r['is_bad']) continue;
    $pub = (string)$r['publisher'];
    if ($pub === '' || $pub === '[empty]') continue;

    if (!isset($currentBL[$pub])) {
        $pubEsc = $conn->real_escape_string((string)$pub);
        $srcEsc = $conn->real_escape_string($BL_SOURCE_ID);
        $sqlIns = "
            INSERT INTO black_list_ts (pub_name, source_id, camp_id, status_api)
            VALUES ('{$pubEsc}', '{$srcEsc}', {$TRACKER_CAMP_ID}, 0)
        ";
        if ($conn->query($sqlIns)) {
            $currentBL[$pub] = true;
            $newAdded++;
        }
    }
}

// 4) итоговый список BL
$allBL = array_keys($currentBL);
sort($allBL);

// 5) помечаем строки как in_bl
foreach ($rows as $idx => $r) {
    $pub = (string)$r['publisher'];
    $rows[$idx]['in_bl'] = isset($currentBL[$pub]);
}

// 6) Kadam: полный update black_list
if ($KADAM_CAMPAIGN_ID && $KADAM_APP_ID && $KADAM_BEARER && $allBL) {
    $data = [
        'client_id'   => $KADAM_APP_ID,
        'campaign_id' => $KADAM_CAMPAIGN_ID,
        'black_list'  => $allBL,
    ];

    $resp = kadam_post_form(
        'https://api.kadam.net/ads.campaigns.update',
        [
            'app_id' => $KADAM_APP_ID,
            'data'   => json_encode($data, JSON_UNESCAPED_UNICODE),
        ],
        $KADAM_BEARER
    );

    $resp['sent_count'] = count($allBL);
    $resp['sent_list']  = $allBL;

    $kadamUpdateResult = $resp;
}
?>

<h2 class="mb-4">Publishers (JS / lead1 / lead2 / lead3 / lead4)</h2>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get" action="menu.php">
      <input type="hidden" name="page" value="publishers">
      <div class="col-12 col-md-3">
        <label class="form-label">Дата с</label>
        <input type="date" name="from" value="<?php echo h($from); ?>" class="form-control">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Дата по</label>
        <input type="date" name="to" value="<?php echo h($to); ?>" class="form-control">
      </div>
      <div class="col-12 col-md-2 mt-4">
        <button type="submit" class="btn btn-primary w-100">Применить</button>
      </div>
    </form>

    <hr class="mt-3 mb-2">

    <p class="mb-1"><strong>Авто-black-list по паблишерам (за выбранный период):</strong></p>
    <ul class="small mb-3">
      <li><strong>JS</strong> — ≥<?php echo $minClicksJs; ?> кликов и JS% &lt; <?php echo $minJsPct; ?>% → BL.</li>
      <li><strong>Lead1</strong> — ≥<?php echo $minClicksL1; ?> кликов и Lead1% &lt; <?php echo $minL1Pct; ?>% → BL.</li>
      <li><strong>Lead2</strong> — ≥<?php echo $minClicksL2; ?> кликов и Lead2% &lt; <?php echo $minL2Pct; ?>% → BL.</li>
      <li><strong>Lead3</strong> — ≥<?php echo $minClicksL3; ?> кликов и Lead3% &lt; <?php echo $minL3Pct; ?>% → BL.</li>
      <li><strong>Lead4</strong> (PWA) — ≥<?php echo $minClicksL4; ?> кликов и Lead4% &lt; <?php echo $minL4Pct; ?>% → BL.</li>
      <li><strong>CPA PWA</strong> — считается как <code>Spend / Lead4</code> (если Lead4=0 → «—»).</li>
    </ul>

    <div class="row mb-3">
      <div class="col-12 col-md-6">
        <table class="table table-sm table-bordered mb-0">
          <thead class="table-light">
          <tr>
            <th>Период</th>
            <th class="text-end">Clicks</th>
            <th class="text-end">Cost</th>
            <th class="text-end">CPA PWA</th>
            <th class="text-end">JS</th>
            <th class="text-end">Lead1</th>
            <th class="text-end">Lead2</th>
            <th class="text-end">Lead3</th>
            <th class="text-end">Lead4</th>
          </tr>
          </thead>
          <tbody>
          <tr>
            <td><?php echo h($from . ' … ' . $to); ?></td>
            <td class="text-end"><?php echo number_format($summary['clicks']); ?></td>
            <td class="text-end"><?php echo number_format($summary['cost'], 2); ?></td>
            <td class="text-end"><?php echo ($summary['cpa_pwa'] === null) ? '—' : number_format(round((float)$summary['cpa_pwa'], 3), 3); ?></td>
            <td class="text-end"><?php echo number_format($summary['js_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($summary['l1_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($summary['l2_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($summary['l3_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($summary['l4_cnt']); ?></td>
          </tr>
          </tbody>
        </table>
      </div>

      <div class="col-12 col-md-6">
        <table class="table table-sm table-bordered mb-0">
          <thead class="table-light">
          <tr>
            <th>Последний час</th>
            <th class="text-end">Clicks</th>
            <th class="text-end">Cost</th>
            <th class="text-end">CPA PWA</th>
            <th class="text-end">JS</th>
            <th class="text-end">Lead1</th>
            <th class="text-end">Lead2</th>
            <th class="text-end">Lead3</th>
            <th class="text-end">Lead4</th>
          </tr>
          </thead>
          <tbody>
          <tr>
            <td>NOW()-1h … NOW()</td>
            <td class="text-end"><?php echo number_format($hourSummary['clicks']); ?></td>
            <td class="text-end"><?php echo number_format($hourSummary['cost'], 2); ?></td>
            <td class="text-end"><?php echo ($hourSummary['cpa_pwa'] === null) ? '—' : number_format(round((float)$hourSummary['cpa_pwa'], 3), 3); ?></td>
            <td class="text-end"><?php echo number_format($hourSummary['js_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($hourSummary['l1_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($hourSummary['l2_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($hourSummary['l3_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($hourSummary['l4_cnt']); ?></td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p class="mb-1">
      Всего паблишеров: <b><?php echo $totalPublishers; ?></b>,
      кандидатов в BL: <b><?php echo $badCount; ?></b>,
      новых добавлено: <b><?php echo $newAdded; ?></b>,
      амнистировано: <b><?php echo $amnestied; ?></b>.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-sm table-striped table-hover align-middle">
      <thead class="table-light">
      <tr>
        <th>Publisher (c1)</th>
        <th class="text-end">Clicks</th>
        <th class="text-end">Spend</th>

        <th class="text-end">JS</th>
        <th class="text-end">JS %</th>

        <th class="text-end">Lead1</th>
        <th class="text-end">L1 %</th>

        <th class="text-end">Lead2</th>
        <th class="text-end">L2 %</th>

        <th class="text-end">Lead3</th>
        <th class="text-end">L3 %</th>

        <th class="text-end">Lead4</th>
        <th class="text-end">PWA %<br><small>(Lead4)</small></th>

        <th class="text-end">CPA PWA<br><small>spend/lead4</small></th>

        <th class="text-center">Rule</th>
        <th class="text-center">BL</th>
      </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="17" class="text-center text-muted">Нет данных за выбранный период.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
          if ($r['is_bad']) {
              $rowClass = 'table-danger';
          } elseif ($r['l3_pct'] > 0 || $r['l2_pct'] > 0 || $r['l1_pct'] > 0 || $r['l4_pct'] > 0) {
              $rowClass = 'table-success';
          } else {
              $rowClass = '';
          }

          $rules = [];
          if ($r['js_bad']) $rules[] = 'JS';
          if ($r['l1_bad']) $rules[] = 'L1';
          if ($r['l2_bad']) $rules[] = 'L2';
          if ($r['l3_bad']) $rules[] = 'L3';
          if ($r['l4_bad']) $rules[] = 'L4';
          $rulesStr = $rules ? implode(', ', $rules) : '—';
          ?>
          <tr class="<?php echo $rowClass; ?>">
            <td><?php echo h($r['publisher']); ?></td>
            <td class="text-end"><?php echo number_format($r['clicks']); ?></td>
            <td class="text-end"><?php echo number_format((float)$r['cost'], 2); ?></td>

            <td class="text-end"><?php echo number_format($r['js_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($r['js_pct'], 1); ?>%</td>

            <td class="text-end"><?php echo number_format($r['l1_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($r['l1_pct'], 2); ?>%</td>

            <td class="text-end"><?php echo number_format($r['l2_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($r['l2_pct'], 2); ?>%</td>

            <td class="text-end"><?php echo number_format($r['l3_cnt']); ?></td>
            <td class="text-end"><?php echo number_format($r['l3_pct'], 2); ?>%</td>

            <td class="text-end"><?php echo number_format($r['l4_cnt']); ?></td>
            <td class="text-end"><?php echo number_format(round((float)$r['l4_pct'], 3), 3); ?>%</td>

            <td class="text-end">
              <?php echo ($r['cpa_pwa'] === null) ? '—' : number_format(round((float)$r['cpa_pwa'], 3), 3); ?>
            </td>

            <td class="text-center">
              <?php if ($r['is_bad']): ?>
                <span class="badge bg-danger"><?php echo h($rulesStr); ?></span>
              <?php else: ?>
                <span class="badge bg-secondary">ok</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($r['in_bl']): ?>
                <span class="badge bg-success">BL</span>
              <?php else: ?>
                <span class="badge bg-light text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($kadamUpdateResult !== null): ?>
  <div class="card mt-3">
    <div class="card-body">
      <h5 class="card-title mb-2">Kadam update response</h5>
      <pre class="small mb-0"><?php echo h(json_encode($kadamUpdateResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
    </div>
  </div>
<?php endif; ?>

<?php
$conn->close();
