<?php
// pages/clicks_stats.php

/** @var string $DB_NAME */

$conn = db_connect($DB_NAME);

// дефолт: сегодня
$today      = date('Y-m-d');
$defaultFrom = $today . ' 00:00:00';
$defaultTo   = $today . ' 23:59:59';

$dateFrom   = trim($_GET['from'] ?? $defaultFrom);
$dateTo     = trim($_GET['to'] ?? $defaultTo);
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$sourceId   = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$externalId = isset($_GET['external_id']) ? (int)$_GET['external_id'] : 0;
$country    = isset($_GET['country']) ? strtoupper(trim((string)$_GET['country'])) : '';

// WHERE
$where = [];
if ($dateFrom !== '') {
    $where[] = "c.ts >= '" . $conn->real_escape_string($dateFrom) . "'";
}
if ($dateTo !== '') {
    $where[] = "c.ts <= '" . $conn->real_escape_string($dateTo) . "'";
}
if ($campaignId > 0) {
    $where[] = "c.campaign_id = " . $campaignId;
}
if ($sourceId > 0) {
    $where[] = "c.source_id = " . $sourceId;
}
if ($externalId > 0) {
    $where[] = "c.external_id = " . $externalId;
}
if ($country !== '') {
    $where[] = "c.country = '" . $conn->real_escape_string($country) . "'";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// сводка
$sqlSummary = "
    SELECT 
        COUNT(*) AS clicks,
        COALESCE(SUM(c.cost), 0) AS cost,
        COALESCE(SUM(c.revenue), 0) AS revenue
    FROM clicks c
    {$whereSql}
";
$summary = ['clicks' => 0, 'cost' => 0.0, 'revenue' => 0.0];
$resSum = $conn->query($sqlSummary);
if ($resSum) {
    $summary = $resSum->fetch_assoc() ?: $summary;
    $resSum->free();
}
$clicks  = (int)$summary['clicks'];
$cost    = (float)$summary['cost'];
$revenue = (float)$summary['revenue'];
$profit  = $revenue - $cost;
$roi     = ($cost > 0) ? ($profit / $cost * 100.0) : 0.0;

// топ по external_id
$sqlByExt = "
    SELECT 
        c.external_id,
        COUNT(*) AS clicks,
        COALESCE(SUM(c.cost), 0) AS cost,
        COALESCE(SUM(c.revenue), 0) AS revenue,
        (COALESCE(SUM(c.revenue), 0) - COALESCE(SUM(c.cost), 0)) AS profit
    FROM clicks c
    {$whereSql}
    GROUP BY c.external_id
    ORDER BY clicks DESC
    LIMIT 100
";
$rowsByExt = [];
$resExt = $conn->query($sqlByExt);
if ($resExt) {
    while ($row = $resExt->fetch_assoc()) {
        $rowsByExt[] = $row;
    }
    $resExt->free();
}

// топ по campaign_id/source_id
$sqlByCamp = "
    SELECT 
        c.campaign_id,
        c.source_id,
        COUNT(*) AS clicks,
        COALESCE(SUM(c.cost), 0) AS cost,
        COALESCE(SUM(c.revenue), 0) AS revenue,
        (COALESCE(SUM(c.revenue), 0) - COALESCE(SUM(c.cost), 0)) AS profit
    FROM clicks c
    {$whereSql}
    GROUP BY c.campaign_id, c.source_id
    ORDER BY clicks DESC
    LIMIT 100
";
$rowsByCamp = [];
$resCamp = $conn->query($sqlByCamp);
if ($resCamp) {
    while ($row = $resCamp->fetch_assoc()) {
        $rowsByCamp[] = $row;
    }
    $resCamp->free();
}

// Воронка по campaigns из Kadam 973225 и legacy tracker 1291 (последние 24 часа)
$sqlFunnel = "
    SELECT COUNT(*) AS clicks,
           SUM(js = 1) AS js_ok,
           SUM(lead1 > 0) AS lead1, SUM(lead2 > 0) AS lead2,
           SUM(lead3 > 0) AS lead3, SUM(lead4 > 0) AS lead4, SUM(lead5 > 0) AS lead5,
           COALESCE(SUM(cost), 0) AS cost
    FROM clicks
    WHERE campaign_id IN ('973225','1291') AND ts >= NOW() - INTERVAL 1 DAY
";
$rowFunnel = $conn->query($sqlFunnel)->fetch_assoc() ?: [];
?>

<h2 class="mb-4">Clicks stats</h2>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">Фильтры</h5>
        <form method="get" class="row g-3" action="menu.php">
            <input type="hidden" name="page" value="clicks">

            <div class="col-md-3">
                <label class="form-label">Дата с</label>
                <input type="text" name="from" class="form-control"
                       value="<?php echo h($dateFrom); ?>"
                       placeholder="YYYY-MM-DD HH:MM:SS">
            </div>
            <div class="col-md-3">
                <label class="form-label">Дата по</label>
                <input type="text" name="to" class="form-control"
                       value="<?php echo h($dateTo); ?>"
                       placeholder="YYYY-MM-DD HH:MM:SS">
            </div>

            <div class="col-md-2">
                <label class="form-label">Campaign ID</label>
                <input type="number" name="campaign_id" class="form-control"
                       value="<?php echo $campaignId > 0 ? h((string)$campaignId) : ''; ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">Source ID</label>
                <input type="number" name="source_id" class="form-control"
                       value="<?php echo $sourceId > 0 ? h((string)$sourceId) : ''; ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">Ext.ID (video)</label>
                <input type="number" name="external_id" class="form-control"
                       value="<?php echo $externalId > 0 ? h((string)$externalId) : ''; ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">Country</label>
                <input type="text" name="country" class="form-control"
                       maxlength="2"
                       value="<?php echo h($country); ?>"
                       placeholder="US / RU / DE">
            </div>

            <div class="col-md-2 align-self-end">
                <button type="submit" class="btn btn-primary w-100">Фильтровать</button>
            </div>

            <div class="col-md-2 align-self-end">
                <a href="menu.php?page=clicks" class="btn btn-outline-secondary w-100">Сброс</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-bg-light mb-3">
            <div class="card-body">
                <h6 class="card-title mb-1">Клики</h6>
                <div class="display-6"><?php echo $clicks; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-light mb-3">
            <div class="card-body">
                <h6 class="card-title mb-1">Cost</h6>
                <div class="display-6"><?php echo number_format($cost, 4, '.', ' '); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-light mb-3">
            <div class="card-body">
                <h6 class="card-title mb-1">Revenue</h6>
                <div class="display-6"><?php echo number_format($revenue, 4, '.', ' '); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card <?php echo $profit >= 0 ? 'text-bg-success' : 'text-bg-danger'; ?> mb-3">
            <div class="card-body">
                <h6 class="card-title mb-1">Profit / ROI%</h6>
                <div class="display-6">
                    <?php echo number_format($profit, 4, '.', ' '); ?>
                </div>
                <div><?php echo number_format($roi, 1, '.', ' '); ?> %</div>
            </div>
        </div>
    </div>
</div>

<!-- Топ по external_id -->
<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">Топ по external_id (video)</h5>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>Ext.ID</th>
                    <th>Clicks</th>
                    <th>Cost</th>
                    <th>Revenue</th>
                    <th>Profit</th>
                    <th>ROI %</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rowsByExt): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">Нет данных</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rowsByExt as $r): 
                        $c  = (int)$r['clicks'];
                        $cs = (float)$r['cost'];
                        $rv = (float)$r['revenue'];
                        $pf = (float)$r['profit'];
                        $ri = $cs > 0 ? ($pf / $cs * 100.0) : 0.0;
                    ?>
                        <tr>
                            <td><?php echo (int)$r['external_id']; ?></td>
                            <td><?php echo $c; ?></td>
                            <td><?php echo number_format($cs, 4, '.', ' '); ?></td>
                            <td><?php echo number_format($rv, 4, '.', ' '); ?></td>
                            <td><?php echo number_format($pf, 4, '.', ' '); ?></td>
                            <td><?php echo number_format($ri, 1, '.', ' '); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Топ по campaign/source -->
<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">Топ по campaign_id / source_id</h5>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>Campaign ID</th>
                    <th>Source ID</th>
                    <th>Clicks</th>
                    <th>Cost</th>
                    <th>Revenue</th>
                    <th>Profit</th>
                    <th>ROI %</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rowsByCamp): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">Нет данных</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rowsByCamp as $r): 
                        $c  = (int)$r['clicks'];
                        $cs = (float)$r['cost'];
                        $rv = (float)$r['revenue'];
                        $pf = (float)$r['profit'];
                        $ri = $cs > 0 ? ($pf / $cs * 100.0) : 0.0;
                    ?>
                        <tr>
                            <td><?php echo (int)$r['campaign_id']; ?></td>
                            <td><?php echo (int)$r['source_id']; ?></td>
                            <td><?php echo $c; ?></td>
                            <td><?php echo number_format($cs, 4, '.', ' '); ?></td>
                            <td><?php echo number_format($rv, 4, '.', ' '); ?></td>
                            <td><?php echo number_format($pf, 4, '.', ' '); ?></td>
                            <td><?php echo number_format($ri, 1, '.', ' '); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Воронка Kadam 973225 / tracker 1291 -->
<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">Воронка Kadam 973225 / tracker 1291 (24ч)</h5>
        <div class="d-flex flex-wrap gap-3">
            <?php foreach ([
                'Клики'  => 'clicks', 'JS'    => 'js_ok',
                'Lead1'  => 'lead1',  'Lead2' => 'lead2',
                'Lead3'  => 'lead3',  'Lead4' => 'lead4',
                'Lead5'  => 'lead5',  'Cost'  => 'cost',
            ] as $label => $key): ?>
                <div class="border rounded px-3 py-2">
                    <div class="small text-muted"><?php echo h($label); ?></div>
                    <div class="fw-bold">
                        <?php echo $key === 'cost'
                            ? number_format((float)($rowFunnel[$key] ?? 0), 4, '.', ' ')
                            : (int)($rowFunnel[$key] ?? 0); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
