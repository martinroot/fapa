<?php
declare(strict_types=1);

/** @var string $DB_NAME */
if (!function_exists('db_connect')) {
    require_once __DIR__ . '/../config_admin.php';
}

$conn = db_connect($DB_NAME);

// ------------------ helpers ------------------
function req_int(string $key, int $default = 0): int { return isset($_GET[$key]) ? (int)$_GET[$key] : $default; }
function req_str(string $key, string $default = ''): string { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default; }
function flag_emoji(string $cc): string {
    $cc = strtoupper(trim($cc));
    if (!preg_match('~^[A-Z]{2}$~', $cc)) return '';
    $o1 = 0x1F1E6 + (ord($cc[0]) - 65);
    $o2 = 0x1F1E6 + (ord($cc[1]) - 65);
    return mb_convert_encoding('&#' . $o1 . ';&#' . $o2 . ';', 'UTF-8', 'HTML-ENTITIES');
}

$limit = 200;
$minViews = max(1, req_int('min_views', 1));
$gapMin = req_int('gap', 30);
if ($gapMin < 5) $gapMin = 5;
if ($gapMin > 240) $gapMin = 240;

// --- Detect optional columns ---
$cols = [];
if ($r = $conn->query("SHOW COLUMNS FROM user_video_stats")) {
    while ($c = $r->fetch_assoc()) $cols[$c['Field']] = true;
    $r->free();
}
$hasFirstViewAt = isset($cols['first_view_at']);

// --- detect country col ---
$uvsCountryCol = '';
if (isset($cols['country_code'])) $uvsCountryCol = 'country_code';
elseif (isset($cols['country']))  $uvsCountryCol = 'country';
elseif (isset($cols['geo']))      $uvsCountryCol = 'geo';

// ------------------ PURGE: delete "1 view, 0%" users ------------------
$purgeMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_1view_0pct') {

    // критерий "как в примере": 1 просмотр, 1 видео, 1 запись, 0% watch, 0 лайков
    $having = "
        SUM(views) = 1
        AND COUNT(*) = 1
        AND COUNT(DISTINCT video_id) = 1
        AND MAX(max_watch_pct) = 0
        AND SUM(CASE WHEN liked = 1 THEN 1 ELSE 0 END) = 0
    ";

    $cntUsers = 0;
    $cntRows  = 0;

    $sqlCntUsers = "
        SELECT COUNT(*) AS cnt
        FROM (
            SELECT user_id
            FROM user_video_stats
            GROUP BY user_id
            HAVING {$having}
        ) t
    ";
    if ($rc = $conn->query($sqlCntUsers)) {
        $cntUsers = (int)($rc->fetch_assoc()['cnt'] ?? 0);
        $rc->free();
    }

    $sqlCntRows = "
        SELECT COALESCE(SUM(rows_cnt), 0) AS cnt
        FROM (
            SELECT user_id, COUNT(*) AS rows_cnt
            FROM user_video_stats
            GROUP BY user_id
            HAVING {$having}
        ) t
    ";
    if ($rr = $conn->query($sqlCntRows)) {
        $cntRows = (int)($rr->fetch_assoc()['cnt'] ?? 0);
        $rr->free();
    }

    $delSql = "
        DELETE uvs
        FROM user_video_stats uvs
        INNER JOIN (
            SELECT user_id
            FROM user_video_stats
            GROUP BY user_id
            HAVING {$having}
        ) z ON z.user_id = uvs.user_id
    ";

    if ($conn->query($delSql)) {
        $deletedRows = (int)$conn->affected_rows;
        $purgeMsg = "✅ Очищено: пользователей <b>{$cntUsers}</b>, строк удалено <b>{$deletedRows}</b> (ожидалось строк: {$cntRows}).";
    } else {
        $purgeMsg = "❌ Ошибка очистки: " . h((string)$conn->error);
    }
}

// --- Online (5 min) ---
$onlineUsers = 0; $onlineViews = 0;
$onlineSql = "
    SELECT COUNT(*) AS online_users, COALESCE(SUM(t.views_total), 0) AS online_views
    FROM (
        SELECT user_id, SUM(views) AS views_total, MAX(last_view_at) AS last_view_at
        FROM user_video_stats
        GROUP BY user_id
    ) t
    WHERE t.last_view_at >= (NOW() - INTERVAL 5 MINUTE)
";
if ($resOnline = $conn->query($onlineSql)) {
    if ($rowOn = $resOnline->fetch_assoc()) {
        $onlineUsers = (int)$rowOn['online_users'];
        $onlineViews = (int)$rowOn['online_views'];
    }
    $resOnline->free();
}
$viewsPerUser = $onlineUsers > 0 ? ($onlineViews / $onlineUsers) : 0.0;

// ------------------ MySQL version (for window functions) ------------------
$mysqlVer = '';
if ($rv = $conn->query("SELECT VERSION() AS v")) {
    $mysqlVer = (string)($rv->fetch_assoc()['v'] ?? '');
    $rv->free();
}
$useWindow = false;
if (preg_match('~^(\d+)\.(\d+)\.~', $mysqlVer, $m)) {
    $maj = (int)$m[1]; $min = (int)$m[2];
    $useWindow = ($maj > 8) || ($maj === 8 && $min >= 0);
}

// ------------------ SORTING (DESC) ------------------
$sortOptions = [
    'views_total'   => 'views_total',
    'videos_cnt'    => 'videos_cnt',
    'active_days'   => 'active_days',
    'returns_cnt'   => 'returns_cnt',
    'sessions_cnt'  => 'sessions_cnt',
    'avg_watch_pct' => 'avg_watch_pct',
    'liked_videos'  => 'liked_videos',
    'last_view_at'  => 'last_view_at',
    'first_seen_at' => 'first_seen_at',
];

$sort = req_str('sort', 'views_total');
if (!isset($sortOptions[$sort])) $sort = 'views_total';
$orderBy = $sortOptions[$sort] . ' DESC';

$firstSeenExpr = $hasFirstViewAt ? "MIN(uvs.first_view_at)" : "MIN(uvs.last_view_at)";

// --- country join/select ---
$countryJoin = "";
$countrySelect = "NULL AS country_code,";
if ($uvsCountryCol !== '') {
    $countryJoin = "
        LEFT JOIN (
            SELECT
                user_id,
                SUBSTRING_INDEX(
                    GROUP_CONCAT($uvsCountryCol ORDER BY last_view_at DESC SEPARATOR ','),
                    ',', 1
                ) AS country_code
            FROM user_video_stats
            WHERE $uvsCountryCol IS NOT NULL AND $uvsCountryCol <> ''
            GROUP BY user_id
        ) uc ON uc.user_id = uvs.user_id
    ";
    $countrySelect = "uc.country_code AS country_code,";
}

// --- sessions join/select ---
$sessionsJoin = "";
$sessionsSelect = "0 AS sessions_cnt,";

if ($useWindow) {
    // MySQL 8+: window functions
    $sessionsJoin = "
        LEFT JOIN (
            SELECT user_id,
                   SUM(CASE
                         WHEN prev_ts IS NULL THEN 1
                         WHEN TIMESTAMPDIFF(MINUTE, prev_ts, last_view_at) >= {$gapMin} THEN 1
                         ELSE 0
                       END) AS sessions_cnt
            FROM (
                SELECT user_id, last_view_at,
                       LAG(last_view_at) OVER (PARTITION BY user_id ORDER BY last_view_at) AS prev_ts
                FROM user_video_stats
            ) x
            GROUP BY user_id
        ) usess ON usess.user_id = uvs.user_id
    ";
    $sessionsSelect = "COALESCE(usess.sessions_cnt, 0) AS sessions_cnt,";
} else {
    // MySQL 5.7: user variables
    $sessionsJoin = "
        LEFT JOIN (
            SELECT user_id, SUM(is_new) AS sessions_cnt
            FROM (
                SELECT t.user_id, t.last_view_at,
                       IF(@prev_user = t.user_id AND TIMESTAMPDIFF(MINUTE, @prev_ts, t.last_view_at) < {$gapMin}, 0, 1) AS is_new,
                       @prev_user := t.user_id AS _pu,
                       @prev_ts   := t.last_view_at AS _pt
                FROM (
                    SELECT user_id, last_view_at
                    FROM user_video_stats
                    ORDER BY user_id, last_view_at
                ) t
                CROSS JOIN (SELECT @prev_user := '', @prev_ts := NULL) vars
            ) q
            GROUP BY user_id
        ) usess ON usess.user_id = uvs.user_id
    ";
    $sessionsSelect = "COALESCE(usess.sessions_cnt, 0) AS sessions_cnt,";
}

$sql = "
    SELECT
        uvs.user_id,
        {$countrySelect}
        {$sessionsSelect}
        COUNT(*)                          AS rows_cnt,
        SUM(uvs.views)                    AS views_total,
        COUNT(DISTINCT uvs.video_id)      AS videos_cnt,
        AVG(uvs.max_watch_pct)            AS avg_watch_pct,
        SUM(CASE WHEN uvs.liked = 1 THEN 1 ELSE 0 END) AS liked_videos,
        MAX(uvs.last_view_at)             AS last_view_at,
        {$firstSeenExpr}                  AS first_seen_at,
        COUNT(DISTINCT DATE(uvs.last_view_at)) AS active_days,
        GREATEST(COUNT(DISTINCT DATE(uvs.last_view_at)) - 1, 0) AS returns_cnt
    FROM user_video_stats uvs
    {$countryJoin}
    {$sessionsJoin}
    GROUP BY uvs.user_id
    HAVING SUM(uvs.views) >= {$minViews}
    ORDER BY {$orderBy}
    LIMIT {$limit}
";

$res = $conn->query($sql);
?>

<h2 class="mb-4">Users watch stats</h2>

<?php if ($purgeMsg !== ''): ?>
    <div class="alert alert-warning"><?php echo $purgeMsg; ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card text-bg-primary h-100">
            <div class="card-body text-center">
                <div class="text-uppercase small mb-1">Online (последние 5 минут)</div>
                <div class="display-5 fw-bold"><?php echo number_format($onlineUsers, 0, '.', ' '); ?></div>
                <div class="small opacity-75">пользователей</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-bg-dark h-100">
            <div class="card-body text-center">
                <div class="text-uppercase small mb-1">Видео</div>
                <div class="display-5 fw-bold"><?php echo number_format($onlineViews, 0, '.', ' '); ?></div>
                <div class="small opacity-75">всего просмотрено этим онлайном</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-bg-success h-100">
            <div class="card-body text-center">
                <div class="text-uppercase small mb-1">Видео на юзера</div>
                <div class="display-5 fw-bold"><?php echo number_format($viewsPerUser, 1, '.', ' '); ?></div>
                <div class="small opacity-75">среднее по онлайн-аудитории</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-3" method="get" action="menu.php">
            <input type="hidden" name="page" value="user_watch">

            <div class="col-md-3">
                <label class="form-label">Min total views per user</label>
                <input type="number" name="min_views" class="form-control" min="1"
                       value="<?php echo (int)$minViews; ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Session gap (minutes)</label>
                <input type="number" name="gap" class="form-control" min="5" max="240"
                       value="<?php echo (int)$gapMin; ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Sort by (DESC)</label>
                <select name="sort" class="form-select">
                    <option value="views_total"   <?php echo $sort==='views_total'?'selected':''; ?>>Total views</option>
                    <option value="sessions_cnt"  <?php echo $sort==='sessions_cnt'?'selected':''; ?>>Sessions</option>
                    <option value="videos_cnt"    <?php echo $sort==='videos_cnt'?'selected':''; ?>>Unique videos</option>
                    <option value="active_days"   <?php echo $sort==='active_days'?'selected':''; ?>>Active days</option>
                    <option value="returns_cnt"   <?php echo $sort==='returns_cnt'?'selected':''; ?>>Returns</option>
                    <option value="avg_watch_pct" <?php echo $sort==='avg_watch_pct'?'selected':''; ?>>Avg watch %</option>
                    <option value="liked_videos"  <?php echo $sort==='liked_videos'?'selected':''; ?>>Liked videos</option>
                    <option value="last_view_at"  <?php echo $sort==='last_view_at'?'selected':''; ?>>Last view at</option>
                    <option value="first_seen_at" <?php echo $sort==='first_seen_at'?'selected':''; ?>>First seen</option>
                </select>
            </div>

            <div class="col-md-3 align-self-end">
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>
        <p class="mt-3 mb-0 text-muted">
            Session gap: <b><?php echo (int)$gapMin; ?> min</b>. MySQL: <code><?php echo h($mysqlVer); ?></code>
        </p>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
        <thead>
        <tr>
            <th>#</th>
            <th>User ID (uiq)</th>
            <th>Country</th>
            <th>Total views</th>
            <th>Sessions</th>
            <th>Unique videos</th>
            <th>Active days</th>
            <th>Returns</th>
            <th>Avg watch, %</th>
            <th>Liked videos</th>
            <th>First seen</th>
            <th>Last view at</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($res && $res->num_rows > 0): ?>
            <?php $i = 0; while ($row = $res->fetch_assoc()): $i++; ?>
                <?php
                $userId      = (string)$row['user_id'];
                $cc          = strtoupper(trim((string)($row['country_code'] ?? '')));
                $flag        = $cc !== '' ? flag_emoji($cc) : '';

                $viewsTotal  = (int)$row['views_total'];
                $sessionsCnt = (int)$row['sessions_cnt'];
                $videosCnt   = (int)$row['videos_cnt'];
                $activeDays  = (int)$row['active_days'];
                $returnsCnt  = (int)$row['returns_cnt'];
                $avgWatchPct = (float)$row['avg_watch_pct'];
                $likedVideos = (int)$row['liked_videos'];
                $firstSeenAt = (string)$row['first_seen_at'];
                $lastViewAt  = (string)$row['last_view_at'];

                $userLink = 'menu.php?page=user_history&user_id=' . rawurlencode($userId) . '&gap=' . (int)$gapMin;
                ?>
                <tr>
                    <td><?php echo $i; ?></td>
                    <td>
                        <a href="<?php echo h($userLink); ?>" class="text-decoration-none">
                            <code><?php echo h($userId); ?></code>
                        </a>
                    </td>
                    <td>
                        <?php if ($cc !== ''): ?><b><?php echo h($cc); ?></b> <?php echo $flag; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td><b><?php echo $viewsTotal; ?></b></td>
                    <td><b><?php echo $sessionsCnt; ?></b></td>
                    <td><?php echo $videosCnt; ?></td>
                    <td><?php echo $activeDays; ?></td>
                    <td><?php echo $returnsCnt; ?></td>
                    <td>
                        <span class="<?php
                            if ($avgWatchPct >= 80)      echo 'text-success fw-bold';
                            elseif ($avgWatchPct >= 50) echo 'text-primary';
                            elseif ($avgWatchPct >= 30) echo 'text-warning';
                            else                        echo 'text-danger';
                        ?>">
                            <?php echo number_format($avgWatchPct, 1, '.', ''); ?>
                        </span>
                    </td>
                    <td><?php echo $likedVideos; ?></td>
                    <td><?php echo h($firstSeenAt); ?></td>
                    <td><?php echo h($lastViewAt); ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="12" class="text-center text-muted">Нет данных.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- PURGE BUTTON -->
<div class="card mt-3 border-danger">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="fw-bold text-danger">Очистка мусорной статы</div>
            <div class="text-muted small">
                Удаляет всех юзеров, у которых ровно <b>1 просмотр</b> и <b>0% watch</b> (и 0 лайков).
            </div>
        </div>

        <form method="post" action="menu.php?page=user_watch&min_views=<?php echo (int)$minViews; ?>&gap=<?php echo (int)$gapMin; ?>&sort=<?php echo h($sort); ?>"
              onsubmit="return confirm('Точно удалить всех пользователей с 1 просмотром и 0% watch?');">
            <input type="hidden" name="action" value="purge_1view_0pct">
            <button type="submit" class="btn btn-danger">
                Очистить стату
            </button>
        </form>
    </div>
</div>

<?php
if ($res) { $res->free(); }
$conn->close();
