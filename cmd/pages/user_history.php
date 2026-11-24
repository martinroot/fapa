<?php
declare(strict_types=1);

/** @var string $DB_NAME */
if (!function_exists('db_connect')) {
    require_once __DIR__ . '/../config_admin.php';
}

$conn = db_connect($DB_NAME);

// ------------------ helpers ------------------
function req_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}
function normalize_media_url(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('~^https?://~i', $s)) return $s;
    if ($s[0] === '/') return $s;
    return '/' . $s;
}
function flag_emoji(string $cc): string {
    $cc = strtoupper(trim($cc));
    if (!preg_match('~^[A-Z]{2}$~', $cc)) return '';
    $o1 = 0x1F1E6 + (ord($cc[0]) - 65);
    $o2 = 0x1F1E6 + (ord($cc[1]) - 65);
    return mb_convert_encoding('&#' . $o1 . ';&#' . $o2 . ';', 'UTF-8', 'HTML-ENTITIES');
}

// ------------------ input ------------------
$userId = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';
if ($userId === '') {
    http_response_code(400);
    echo 'user_id required';
    exit;
}

// ------------------ schema detect ------------------
$uvsCols = [];
if ($r = $conn->query("SHOW COLUMNS FROM user_video_stats")) {
    while ($c = $r->fetch_assoc()) $uvsCols[$c['Field']] = true;
    $r->free();
}
$hasFirstViewAt = isset($uvsCols['first_view_at']);
$hasUvsCountry  = isset($uvsCols['country_code']) || isset($uvsCols['country']) || isset($uvsCols['geo']);

// videos table
$videosCols = [];
$hasVideosTable = false;
if ($r = $conn->query("SHOW TABLES LIKE 'videos'")) {
    $hasVideosTable = ($r->num_rows > 0);
    $r->free();
}
if ($hasVideosTable) {
    if ($r = $conn->query("SHOW COLUMNS FROM videos")) {
        while ($c = $r->fetch_assoc()) $videosCols[$c['Field']] = true;
        $r->free();
    }
}

// ------------------ detect user country ------------------
$userCountryCode = '';
$userCountrySrc  = '';

if ($hasUvsCountry) {
    // try get latest country from user_video_stats
    $ccCol = isset($uvsCols['country_code']) ? 'country_code' : (isset($uvsCols['country']) ? 'country' : 'geo');
    $sql = "SELECT {$ccCol} AS cc FROM user_video_stats WHERE user_id=? AND {$ccCol} IS NOT NULL AND {$ccCol}<>'' ORDER BY last_view_at DESC LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('s', $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row && !empty($row['cc'])) {
            $userCountryCode = strtoupper(trim((string)$row['cc']));
            $userCountrySrc  = 'user_video_stats';
        }
    }
}

// fallback: clicks / event_click (если есть)
if ($userCountryCode === '') {
    $candidates = ['clicks', 'event_click', 'click_log', 'click'];
    foreach ($candidates as $tbl) {
        $exists = false;
        if ($r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tbl) . "'")) {
            $exists = ($r->num_rows > 0);
            $r->free();
        }
        if (!$exists) continue;

        $cols = [];
        if ($r = $conn->query("SHOW COLUMNS FROM `{$tbl}`")) {
            while ($c = $r->fetch_assoc()) $cols[$c['Field']] = true;
            $r->free();
        }

        // find user column
        $userCol = '';
        foreach (['uiq','user_id','uid','subid','c1'] as $uc) {
            if (isset($cols[$uc])) { $userCol = $uc; break; }
        }
        if ($userCol === '') continue;

        // find country column
        $ccCol = '';
        foreach (['country_code','country','geo','cc'] as $cc) {
            if (isset($cols[$cc])) { $ccCol = $cc; break; }
        }
        if ($ccCol === '') continue;

        // find time column
        $tsCol = '';
        foreach (['created_at','ts','created','datetime','time','date'] as $tc) {
            if (isset($cols[$tc])) { $tsCol = $tc; break; }
        }
        if ($tsCol === '') {
            // без даты тоже ок, но тогда просто LIMIT 1
            $sql = "SELECT `{$ccCol}` AS cc FROM `{$tbl}` WHERE `{$userCol}`=? AND `{$ccCol}`<>'' LIMIT 1";
        } else {
            $sql = "SELECT `{$ccCol}` AS cc FROM `{$tbl}` WHERE `{$userCol}`=? AND `{$ccCol}`<>'' ORDER BY `{$tsCol}` DESC LIMIT 1";
        }

        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('s', $userId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row && !empty($row['cc'])) {
                $userCountryCode = strtoupper(trim((string)$row['cc']));
                $userCountrySrc  = $tbl;
                break;
            }
        }
    }
}

$countryFlag = $userCountryCode !== '' ? flag_emoji($userCountryCode) : '';

// ------------------ Summary ------------------
$firstSeenExpr = $hasFirstViewAt ? "MIN(first_view_at)" : "MIN(last_view_at)";
$summarySql = "
    SELECT
        SUM(views) AS views_total,
        COUNT(DISTINCT video_id) AS videos_cnt,
        AVG(max_watch_pct) AS avg_watch_pct,
        SUM(CASE WHEN liked = 1 THEN 1 ELSE 0 END) AS liked_videos,
        {$firstSeenExpr} AS first_seen_at,
        MAX(last_view_at) AS last_view_at,
        COUNT(DISTINCT DATE(last_view_at)) AS active_days,
        GREATEST(COUNT(DISTINCT DATE(last_view_at)) - 1, 0) AS returns_cnt
    FROM user_video_stats
    WHERE user_id = ?
";
$stmt = $conn->prepare($summarySql);
if (!$stmt) { die('Prepare failed: ' . $conn->error); }
$stmt->bind_param('s', $userId);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$viewsTotal  = (int)($summary['views_total'] ?? 0);
$videosCnt   = (int)($summary['videos_cnt'] ?? 0);
$avgWatchPct = (float)($summary['avg_watch_pct'] ?? 0);
$likedVideos = (int)($summary['liked_videos'] ?? 0);
$firstSeenAt = (string)($summary['first_seen_at'] ?? '');
$lastViewAt  = (string)($summary['last_view_at'] ?? '');
$activeDays  = (int)($summary['active_days'] ?? 0);
$returnsCnt  = (int)($summary['returns_cnt'] ?? 0);

// ------------------ Daily history ------------------
$dailySql = "
    SELECT
        DATE(last_view_at) AS day,
        SUM(views) AS views_total,
        COUNT(DISTINCT video_id) AS videos_cnt,
        AVG(max_watch_pct) AS avg_watch_pct,
        SUM(CASE WHEN liked = 1 THEN 1 ELSE 0 END) AS liked_videos,
        MAX(last_view_at) AS last_view_at
    FROM user_video_stats
    WHERE user_id = ?
    GROUP BY DATE(last_view_at)
    ORDER BY day DESC
    LIMIT 365
";
$stmt = $conn->prepare($dailySql);
if (!$stmt) { die('Prepare failed: ' . $conn->error); }
$stmt->bind_param('s', $userId);
$stmt->execute();
$dailyRes = $stmt->get_result();
$stmt->close();

// ------------------ Per-video history (latest actions) ------------------
$videoLimit = req_int('vlimit', 300);
if ($videoLimit < 50) $videoLimit = 50;
if ($videoLimit > 2000) $videoLimit = 2000;

$firstColSelect = $hasFirstViewAt ? ", uvs.first_view_at" : "";

// pick best thumbnail field from videos
$thumbField = '';
if ($hasVideosTable) {
    foreach (['thumb_url','thumb','poster_url','poster','cover_url','cover','preview_img','image','img'] as $f) {
        if (isset($videosCols[$f])) { $thumbField = $f; break; }
    }
}

$videoJoin = "";
$videoSelectExtra = "";
if ($hasVideosTable) {
    $videoJoin = "LEFT JOIN videos v ON v.id = uvs.video_id";
    $videoSelectExtra = "
        , v.external_id
        , COALESCE(v.username,'') AS v_username
    ";
    if ($thumbField !== '') {
        $videoSelectExtra .= ", v.`{$thumbField}` AS v_thumb";
    } else {
        $videoSelectExtra .= ", '' AS v_thumb";
    }
}

$videoSql = "
    SELECT
        uvs.video_id,
        uvs.views,
        uvs.max_watch_pct,
        uvs.liked,
        uvs.last_view_at
        {$firstColSelect}
        {$videoSelectExtra}
    FROM user_video_stats uvs
    {$videoJoin}
    WHERE uvs.user_id = ?
    ORDER BY uvs.last_view_at DESC
    LIMIT {$videoLimit}
";
$stmt = $conn->prepare($videoSql);
if (!$stmt) { die('Prepare failed: ' . $conn->error); }
$stmt->bind_param('s', $userId);
$stmt->execute();
$videoRes = $stmt->get_result();
$stmt->close();

$backLink = 'menu.php?page=user_watch';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h2 class="mb-1">User history</h2>
        <div class="text-muted">
            User: <code><?php echo h($userId); ?></code>
            <?php if ($userCountryCode !== ''): ?>
                &nbsp;•&nbsp; Country: <b><?php echo h($userCountryCode); ?></b> <?php echo $countryFlag; ?>
                <span class="small text-muted">(src: <?php echo h($userCountrySrc); ?>)</span>
            <?php endif; ?>
        </div>
    </div>
    <div>
        <a href="<?php echo h($backLink); ?>" class="btn btn-outline-secondary">← Back</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-bg-dark h-100">
            <div class="card-body text-center">
                <div class="text-uppercase small mb-1">Total views</div>
                <div class="display-6 fw-bold"><?php echo number_format($viewsTotal, 0, '.', ' '); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-primary h-100">
            <div class="card-body text-center">
                <div class="text-uppercase small mb-1">Unique videos</div>
                <div class="display-6 fw-bold"><?php echo number_format($videosCnt, 0, '.', ' '); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-success h-100">
            <div class="card-body text-center">
                <div class="text-uppercase small mb-1">Active days</div>
                <div class="display-6 fw-bold"><?php echo number_format($activeDays, 0, '.', ' '); ?></div>
                <div class="small opacity-75">returns: <?php echo number_format($returnsCnt, 0, '.', ' '); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-secondary h-100">
            <div class="card-body text-center">
                <div class="text-uppercase small mb-1">Avg watch, %</div>
                <div class="display-6 fw-bold"><?php echo number_format($avgWatchPct, 1, '.', ''); ?></div>
                <div class="small opacity-75">liked videos: <?php echo number_format($likedVideos, 0, '.', ' '); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="text-muted small">First seen</div>
                <div><code><?php echo h($firstSeenAt); ?></code></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">Last view at</div>
                <div><code><?php echo h($lastViewAt); ?></code></div>
            </div>
        </div>
    </div>
</div>

<h4 class="mb-2">Daily activity</h4>
<div class="table-responsive mb-4">
    <table class="table table-striped table-sm align-middle">
        <thead>
        <tr>
            <th>Day</th>
            <th>Total views</th>
            <th>Unique videos</th>
            <th>Avg watch, %</th>
            <th>Liked</th>
            <th>Last action time</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($dailyRes && $dailyRes->num_rows > 0): ?>
            <?php while ($d = $dailyRes->fetch_assoc()): ?>
                <tr>
                    <td><code><?php echo h((string)$d['day']); ?></code></td>
                    <td><b><?php echo (int)$d['views_total']; ?></b></td>
                    <td><?php echo (int)$d['videos_cnt']; ?></td>
                    <td><?php echo number_format((float)$d['avg_watch_pct'], 1, '.', ''); ?></td>
                    <td><?php echo (int)$d['liked_videos']; ?></td>
                    <td><?php echo h((string)$d['last_view_at']); ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="6" class="text-center text-muted">Нет дневной истории.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="d-flex align-items-center justify-content-between mb-2">
    <h4 class="mb-0">Per-video actions (latest)</h4>
    <form class="d-flex gap-2" method="get" action="menu.php">
        <input type="hidden" name="page" value="user_history">
        <input type="hidden" name="user_id" value="<?php echo h($userId); ?>">
        <input type="number" name="vlimit" class="form-control form-control-sm" style="width:110px"
               min="50" max="2000" value="<?php echo (int)$videoLimit; ?>">
        <button class="btn btn-sm btn-outline-primary">Limit</button>
    </form>
</div>

<div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
        <thead>
        <tr>
            <th>#</th>
            <th>Preview</th>
            <th>Video ID</th>
            <?php if ($hasVideosTable): ?>
                <th>External ID</th>
                <th>Video username</th>
            <?php endif; ?>
            <th>Views</th>
            <th>Max watch, %</th>
            <th>Liked</th>
            <?php if ($hasFirstViewAt): ?>
                <th>First view at</th>
            <?php endif; ?>
            <th>Last view at</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($videoRes && $videoRes->num_rows > 0): ?>
            <?php $i = 0; while ($v = $videoRes->fetch_assoc()): $i++; ?>
                <?php
                    $vid = (string)$v['video_id'];
                    $publicUrl = '/v.php?id=' . rawurlencode($vid);
                    $thumb = '';
                    if ($hasVideosTable && isset($v['v_thumb'])) {
                        $thumb = normalize_media_url((string)$v['v_thumb']);
                    }
                ?>
                <tr>
                    <td><?php echo $i; ?></td>

                    <td style="width:88px;">
                        <a href="<?php echo h($publicUrl); ?>" target="_blank" class="text-decoration-none">
                            <?php if ($thumb !== ''): ?>
                                <img src="<?php echo h($thumb); ?>"
                                     style="width:72px;height:72px;object-fit:cover;border-radius:10px;border:1px solid rgba(0,0,0,.15);"
                                     loading="lazy"
                                     alt="preview">
                            <?php else: ?>
                                <span class="btn btn-sm btn-outline-secondary">Open</span>
                            <?php endif; ?>
                        </a>
                    </td>

                    <td><code><?php echo h($vid); ?></code></td>

                    <?php if ($hasVideosTable): ?>
                        <td><code><?php echo h((string)($v['external_id'] ?? '')); ?></code></td>
                        <td><?php echo h((string)($v['v_username'] ?? '')); ?></td>
                    <?php endif; ?>

                    <td><b><?php echo (int)$v['views']; ?></b></td>
                    <td><?php echo number_format((float)$v['max_watch_pct'], 1, '.', ''); ?></td>
                    <td><?php echo ((int)$v['liked'] === 1) ? '<span class="text-danger fw-bold">1</span>' : '0'; ?></td>

                    <?php if ($hasFirstViewAt): ?>
                        <td><?php echo h((string)$v['first_view_at']); ?></td>
                    <?php endif; ?>

                    <td><?php echo h((string)$v['last_view_at']); ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="<?php echo $hasVideosTable ? ($hasFirstViewAt ? 10 : 9) : ($hasFirstViewAt ? 8 : 7); ?>"
                    class="text-center text-muted">
                    Нет истории по видео.
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
if ($dailyRes) { $dailyRes->free(); }
if ($videoRes) { $videoRes->free(); }
$conn->close();
