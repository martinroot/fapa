<?php
declare(strict_types=1);

/** @var string $DB_NAME */

$conn = db_connect($DB_NAME);

function req_param(string $key, $default = null) {
    return $_REQUEST[$key] ?? $default;
}

// ---------- фильтры / критерии ----------
$today   = (new DateTime('today'))->format('Y-m-d');
$weekAgo = (new DateTime('-7 days'))->format('Y-m-d');

$from = (string)req_param('from', $weekAgo);
$to   = (string)req_param('to',   $today);

// ПОРОГИ ПО КЛИКАМ И МИНИМАЛЬНЫЕ ОЖИДАЕМЫЕ ПРОЦЕНТЫ (как в publishers.php)
//
// JS:    >=10 кликов,    ожидание JS%   >= 40%  (наличие JS)
// Lead1: >=50 кликов,    ожидание L1%   >= 5%   (1 касание)
// Lead2: >=100 кликов,   ожидание L2%   >= 2%   (5 свайпов)
// Lead3: >=200 кликов,   ожидание L3%   >= 1.5% (15–20 свайпов)
// Lead4: >=500 кликов,   ожидание L4%   >= 1%   (кнопка установки VPN)
//
// Если доля события НИЖЕ этих значений — GEO считается «плохим» по этому критерию.
$minClicksJs = 10;
$minClicksL1 = 200;
$minClicksL2 = 300;
$minClicksL3 = 400;
$minClicksL4 = 1000;

// МИНИМАЛЬНЫЕ % (ниже → кандидат в BL)
$minJsPct  = 30.0;
$minL1Pct  = 7.0;
$minL2Pct  = 3.0;
$minL3Pct  = 1.0;
$minL4Pct  = 0.3;

// нормализуем даты
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

// ---------- сводка за последний час (по MySQL NOW) ----------
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

// ---------- снимок статистики по странам ----------
$sql = "
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
        $l4_pct = $clicks > 0 ? $l4_cnt / $clicks * 100.0 : 0.0;
        $l1_pct = $clicks > 0 ? $l1_cnt / $clicks * 100.0 : 0.0;
        $l2_pct = $clicks > 0 ? $l2_cnt / $clicks * 100.0 : 0.0;
        $l3_pct = $clicks > 0 ? $l3_cnt / $clicks * 100.0 : 0.0;

        // критерии "плохой страны" — если кликов достаточно, но % события ниже порога
        $js_bad = ($clicks >= $minClicksJs && $js_pct  < $minJsPct);
        $l1_bad = ($clicks >= $minClicksL1 && $l1_pct  < $minL1Pct);
        $l2_bad = ($clicks >= $minClicksL2 && $l2_pct  < $minL2Pct);
        $l3_bad = ($clicks >= $minClicksL3 && $l3_pct  < $minL3Pct);
        $l4_bad = ($clicks >= $minClicksL4 && $l4_pct  < $minL4Pct);

        $isBad = ($js_bad || $l1_bad || $l2_bad || $l3_bad || $l4_bad);

        $rows[] = [
            'cc'      => $r['cc'],
            'clicks'  => $clicks,
            'cost'    => $cost,

            'js_cnt'  => $js_cnt,
            'l4_cnt'  => $l4_cnt,
            'l1_cnt'  => $l1_cnt,
            'l2_cnt'  => $l2_cnt,
            'l3_cnt'  => $l3_cnt,

            'js_pct'  => $js_pct,
            'l4_pct'  => $l4_pct,
            'l1_pct'  => $l1_pct,
            'l2_pct'  => $l2_pct,
            'l3_pct'  => $l3_pct,

            'js_bad'  => $js_bad,
            'l4_bad'  => $l4_bad,
            'l1_bad'  => $l1_bad,
            'l2_bad'  => $l2_bad,
            'l3_bad'  => $l3_bad,

            'is_bad'  => $isBad,
        ];
    }
}
if ($res) {
    $res->free();
}

$totalCountries = count($rows);
$badCount = 0;
foreach ($rows as $r) {
    if ($r['is_bad']) $badCount++;
}
?>

<h2 class="mb-4">Countries (GEO)</h2>

<div class="card mb-3">
    <div class="card-body">
        <!-- Фильтр только по датам -->
        <form class="row g-2 align-items-end" method="get" action="menu.php">
            <input type="hidden" name="page" value="countries">

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

        <!-- Объяснение критериев -->
        <p class="mb-1"><strong>Подсветка проблемных GEO (красные строки):</strong></p>
        <ul class="small mb-3">
            <li><strong>JS</strong> — наличие JS (страница реально загрузилась с кодом сайта).  
                Если у GEO ≥<?php echo $minClicksJs; ?> кликов и доля JS-событий &lt; <?php echo $minJsPct; ?>% → GEO считается слабым по JS.</li>
            <li><strong>Lead1</strong> — первое касание (tap по странице).  
                ≥<?php echo $minClicksL1; ?> кликов и Lead1% &lt; <?php echo $minL1Pct; ?>% → слабое вовлечение.</li>
            <li><strong>Lead2</strong> — 5 свайпов.  
                ≥<?php echo $minClicksL2; ?> кликов и Lead2% &lt; <?php echo $minL2Pct; ?>% → мало даунфаннела.</li>
            <li><strong>Lead3</strong> — 15–20 свайпов.  
                ≥<?php echo $minClicksL3; ?> кликов и Lead3% &lt; <?php echo $minL3Pct; ?>% → плохо докручивают ленту.</li>
            <li><strong>Lead4</strong> — нажатие кнопки установки VPN и переход в стор.  
                ≥<?php echo $minClicksL4; ?> кликов и Lead4% &lt; <?php echo $minL4Pct; ?>% → слабая монетизация VPN.</li>
            <li>Если сработал хотя бы один из критериев, GEO помечается как проблемное (строка <span class="text-danger">красная</span>).</li>
            <li>Зелёные строки — есть нормальная конвертация хотя бы по одному из lead-ивентов.</li>
        </ul>

        <!-- Сводки: период / последний час -->
        <div class="row mb-3">
            <!-- Сводка за выбранный период -->
            <div class="col-12 col-md-6">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Период</th>
                        <th class="text-end">Clicks</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">
                            JS<br><small>наличие JS</small>
                        </th>
                        <th class="text-end">
                            Lead1<br><small>1 касание</small>
                        </th>
                        <th class="text-end">
                            Lead2<br><small>5 свайпов</small>
                        </th>
                        <th class="text-end">
                            Lead3<br><small>15–20 свайпов</small>
                        </th>
                        <th class="text-end">
                            Lead4<br><small>кнопка VPN</small>
                        </th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><?php echo h($from . ' … ' . $to); ?></td>
                        <td class="text-end"><?php echo number_format($summary['clicks']); ?></td>
                        <td class="text-end"><?php echo number_format($summary['cost'], 2); ?></td>
                        <td class="text-end"><?php echo number_format($summary['js_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($summary['l1_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($summary['l2_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($summary['l3_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($summary['l4_cnt']); ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <!-- Сводка за последний час -->
            <div class="col-12 col-md-6">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Последний час (MySQL NOW)</th>
                        <th class="text-end">Clicks</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">
                            JS<br><small>наличие JS</small>
                        </th>
                        <th class="text-end">
                            Lead1<br><small>1 касание</small>
                        </th>
                        <th class="text-end">
                            Lead2<br><small>5 свайпов</small>
                        </th>
                        <th class="text-end">
                            Lead3<br><small>15–20 свайпов</small>
                        </th>
                        <th class="text-end">
                            Lead4<br><small>кнопка VPN</small>
                        </th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>NOW() - 1h … NOW()</td>
                        <td class="text-end"><?php echo number_format($hourSummary['clicks']); ?></td>
                        <td class="text-end"><?php echo number_format($hourSummary['cost'], 2); ?></td>
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
            Всего GEO: <b><?php echo $totalCountries; ?></b>,
            проблемных по любому критерию: <b><?php echo $badCount; ?></b>.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-sm table-striped table-hover align-middle">
            <thead class="table-light">
            <tr>
                <th>Country</th>
                <th class="text-end">Clicks</th>
                <th class="text-end">Cost</th>

                <th class="text-end">
                    JS<br><small>наличие JS</small>
                </th>
                <th class="text-end">JS %</th>

                <th class="text-end">
                    Lead1<br><small>1 касание</small>
                </th>
                <th class="text-end">L1 %</th>

                <th class="text-end">
                    Lead2<br><small>5 свайпов</small>
                </th>
                <th class="text-end">L2 %</th>

                <th class="text-end">
                    Lead3<br><small>15–20 свайпов</small>
                </th>
                <th class="text-end">L3 %</th>

                <th class="text-end">
                    Lead4<br><small>кнопка VPN</small>
                </th>
                <th class="text-end">L4 %</th>

                <th class="text-center">Rule</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="13" class="text-center text-muted">Нет данных за выбранный период.</td>
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
                        <td><?php echo h($r['cc']); ?></td>
                        <td class="text-end"><?php echo number_format($r['clicks']); ?></td>
                        <td class="text-end"><?php echo number_format($r['cost'], 2); ?></td>

                        <td class="text-end"><?php echo number_format($r['js_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($r['js_pct'], 1); ?>%</td>

                        <td class="text-end"><?php echo number_format($r['l1_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($r['l1_pct'], 2); ?>%</td>

                        <td class="text-end"><?php echo number_format($r['l2_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($r['l2_pct'], 2); ?>%</td>

                        <td class="text-end"><?php echo number_format($r['l3_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($r['l3_pct'], 2); ?>%</td>

                        <td class="text-end"><?php echo number_format($r['l4_cnt']); ?></td>
                        <td class="text-end"><?php echo number_format($r['l4_pct'], 2); ?>%</td>

                        <td class="text-center">
                            <?php if ($r['is_bad']): ?>
                                <span class="badge bg-danger"><?php echo h($rulesStr); ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">ok</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$conn->close();
