<?php
declare(strict_types=1);

/** @var string $DB_NAME */

if (!function_exists('db_connect')) {
    require_once __DIR__ . '/../config_admin.php';
}

$conn = db_connect($DB_NAME);

// fallback, если вдруг h() не определена в config.php
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// --- CSRF token ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

// --- WIPE watch stats handler (DELETE ALL user_video_stats) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'wipe_watch_stats') {
    $posted = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $posted)) {
        http_response_code(403);
        echo 'Bad CSRF token';
        exit;
    }

    // Удаляем всю статистику просмотров/досмотров
    $conn->query("TRUNCATE TABLE user_video_stats");

    // PRG: redirect после POST
    $mu = isset($_POST['min_users']) ? max(1, (int)$_POST['min_users']) : 5;
    header('Location: menu.php?page=watch_stats&min_users=' . $mu);
    exit;
}

// ------------------ pagination / filters ------------------

// Пагинация
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// Фильтр по минимальному числу уник. юзеров (чтобы отсечь шум)
$minUsers = isset($_GET['min_users']) ? max(1, (int)$_GET['min_users']) : 5;

// Считаем количество уникальных видео, попадающих под условие
$sqlTotal = "
    SELECT COUNT(*) AS c
    FROM (
        SELECT uvs.video_id
        FROM user_video_stats uvs
        GROUP BY uvs.video_id
        HAVING COUNT(*) >= {$minUsers}
    ) t
";
$total = 0;
$resTotal = $conn->query($sqlTotal);
if ($resTotal && $rowT = $resTotal->fetch_assoc()) {
    $total = (int)$rowT['c'];
    $resTotal->free();
}
$pages = $total > 0 ? (int)ceil($total / $perPage) : 1;

// Основной запрос: агрегация по видео
// Сортировка как в ленте (холодный режим / global_score):
// global_score = 0.3 * avg_watch_pct + 0.7 * like_pct
// like_pct = likes_cnt / views_cnt * 100
$sql = "
    SELECT
        v.id,
        v.external_id,
        COALESCE(u.username, v.username) AS username,
        v.likes_cnt,
        v.views_cnt,

        COUNT(uvs.user_id)          AS users_cnt,
        SUM(uvs.views)              AS views_total,
        AVG(uvs.max_watch_pct)      AS avg_watch_pct,
        SUM(CASE WHEN uvs.liked = 1 THEN 1 ELSE 0 END) AS liked_users,

        -- лайки как % от просмотров
        CASE
            WHEN v.views_cnt > 0 THEN (100.0 * v.likes_cnt / v.views_cnt)
            ELSE 0
        END AS like_pct,

        -- глобальный скор: 30% досмотр + 70% лайки (как в ленте)
        CASE
            WHEN v.views_cnt > 0 THEN
                0.3 * AVG(uvs.max_watch_pct)
              + 0.7 * (100.0 * v.likes_cnt / v.views_cnt)
            ELSE
                0.3 * AVG(uvs.max_watch_pct)
        END AS global_score

    FROM user_video_stats uvs
    JOIN videos v ON v.id = uvs.video_id
    LEFT JOIN users u ON u.id = v.user_id
    GROUP BY uvs.video_id
    HAVING COUNT(uvs.user_id) >= {$minUsers}
    ORDER BY global_score DESC, users_cnt DESC
    LIMIT {$offset}, {$perPage}
";
$res = $conn->query($sql);
?>

<h2 class="mb-4">Watch stats (per video)</h2>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-3" method="get" action="menu.php">
            <input type="hidden" name="page" value="watch_stats">
            <div class="col-md-3">
                <label class="form-label">Min unique users per video</label>
                <input type="number"
                       name="min_users"
                       class="form-control"
                       min="1"
                       value="<?php echo (int)$minUsers; ?>">
            </div>
            <div class="col-md-3 align-self-end">
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>
        <p class="mt-3 mb-0 text-muted">
            Всего видео с &ge; <?php echo (int)$minUsers; ?> уникальными зрителями:
            <b><?php echo (int)$total; ?></b>.
            Страница <?php echo (int)$page; ?> из <?php echo (int)$pages; ?>.
        </p>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped table-sm align-middle">
        <thead>
        <tr>
            <th scope="col">#ID</th>
            <th scope="col">External</th>
            <th scope="col">User</th>
            <th scope="col">Preview</th>
            <th scope="col">Avg watch, %</th>
            <th scope="col">Uniq users</th>
            <th scope="col">Total views</th>
            <th scope="col">Liked users</th>
            <th scope="col">Likes cnt</th>
            <th scope="col">Views cnt</th>
            <th scope="col">Open</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($res && $res->num_rows > 0): ?>
            <?php while ($row = $res->fetch_assoc()): ?>
                <?php
                $id          = (int)$row['id'];
                $external_id = (int)$row['external_id'];
                $username    = $row['username'] ?: ('user_' . $external_id);

                $avgWatchPct = (float)$row['avg_watch_pct'];
                $usersCnt    = (int)$row['users_cnt'];
                $viewsTotal  = (int)$row['views_total'];
                $likedUsers  = (int)$row['liked_users'];

                $likesCnt    = (int)$row['likes_cnt'];
                $viewsCnt    = (int)$row['views_cnt'];

                $previewUrl  = "/uvid/{$external_id}/poster.jpg";
                ?>
                <tr>
                    <td><?php echo $id; ?></td>
                    <td><?php echo $external_id; ?></td>
                    <td><?php echo h($username); ?></td>

                    <td>
                        <a href="/v.php?id=<?php echo $id; ?>" target="_blank" rel="noopener">
                            <img src="<?php echo h($previewUrl); ?>"
                                 alt="poster"
                                 style="width:80px; height:45px; object-fit:cover; border-radius:4px;">
                        </a>
                    </td>

                    <td>
                        <span class="<?php
                            if ($avgWatchPct >= 80) echo 'text-success fw-bold';
                            elseif ($avgWatchPct >= 50) echo 'text-primary';
                            elseif ($avgWatchPct >= 30) echo 'text-warning';
                            else echo 'text-danger';
                        ?>">
                            <?php echo number_format($avgWatchPct, 1, '.', ''); ?>
                        </span>
                    </td>
                    <td><?php echo $usersCnt; ?></td>
                    <td><?php echo $viewsTotal; ?></td>
                    <td><?php echo $likedUsers; ?></td>
                    <td><?php echo $likesCnt; ?></td>
                    <td><?php echo $viewsCnt; ?></td>
                    <td>
                        <a href="/v.php?id=<?php echo $id; ?>"
                           target="_blank" rel="noopener"
                           class="btn btn-sm btn-outline-secondary">
                            Open
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
        <tr>
            <td colspan="11" class="text-center text-muted">Пока нет данных по досматриваемости.</td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($res) { $res->free(); } ?>

<?php if ($pages > 1): ?>
<nav aria-label="Watch stats pagination">
    <ul class="pagination mt-3">
        <?php
        $baseUrl = 'menu.php?page=watch_stats&min_users=' . (int)$minUsers;
        $prev = max(1, $page - 1);
        $next = min($pages, $page + 1);
        ?>
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo $page <= 1 ? '#' : "{$baseUrl}&p={$prev}"; ?>">«</a>
        </li>

        <?php
        $from = max(1, $page - 3);
        $to   = min($pages, $page + 3);
        for ($i = $from; $i <= $to; $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="<?php echo "{$baseUrl}&p={$i}"; ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>

        <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo $page >= $pages ? '#' : "{$baseUrl}&p={$next}"; ?>">»</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<hr class="my-4">

<div class="card border-danger">
    <div class="card-body">
        <h5 class="card-title text-danger mb-2">Danger zone</h5>
        <p class="text-muted mb-3">
            Удалит всю статистику просмотров/досмотров из <code>user_video_stats</code>. Действие необратимо.
        </p>

        <form method="post" action="menu.php?page=watch_stats"
              onsubmit="return confirm('ТОЧНО удалить всю статистику просмотров (user_video_stats)? Это необратимо!');">
            <input type="hidden" name="action" value="wipe_watch_stats">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="min_users" value="<?php echo (int)$minUsers; ?>">

            <button type="submit" class="btn btn-danger">
                Delete ALL watch stats
            </button>
        </form>
    </div>
</div>
