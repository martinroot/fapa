<?php
// pages/videos_list.php

/** @var string $DB_NAME */

$conn = db_connect($DB_NAME);
$message = '';

// --- Обработка POST-действий ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $videoId = isset($_POST['video_id']) ? (int)$_POST['video_id'] : 0;

    if ($videoId <= 0) {
        $message = "Некорректный ID видео.";
    } else {
        if ($action === 'boost') {
            // --- Стартовый импульс ---
            $conn->begin_transaction();
            try {
                // 1) +3 просмотра в video_views (3 разных "сервисных" uiq)
                $stmtView = $conn->prepare("
                    INSERT INTO video_views (video_id, uiq, ip, country)
                    VALUES (?, ?, ?, ?)
                ");
                if ($stmtView) {
                    $ip      = '127.0.0.1';
                    $country = 'XX';
                    for ($i = 1; $i <= 3; $i++) {
                        $uiq = 'boost_' . $videoId . '_v' . $i;
                        $stmtView->bind_param('isss', $videoId, $uiq, $ip, $country);
                        $stmtView->execute();
                    }
                    $stmtView->close();
                }

                // 2) +1 лайк (без дублей по нашему сервисному uiq)
                $likesAdded = 0;
                $stmtLike = $conn->prepare("
                    INSERT IGNORE INTO video_likes (video_id, uiq, ip)
                    VALUES (?, ?, ?)
                ");
                if ($stmtLike) {
                    $likeUiq = 'boost_' . $videoId . '_like';
                    $ipLike  = '127.0.0.1';
                    $stmtLike->bind_param('iss', $videoId, $likeUiq, $ipLike);
                    $stmtLike->execute();
                    $likesAdded = $stmtLike->affected_rows; // 1 если добавили, 0 если уже был
                    $stmtLike->close();
                }

                // 3) Обновляем counters в videos
                $viewsToAdd = 3;
                $sqlUpd = "
                    UPDATE videos
                    SET 
                        views_cnt = views_cnt + {$viewsToAdd},
                        likes_cnt = likes_cnt + {$likesAdded}
                    WHERE id = {$videoId}
                    LIMIT 1
                ";
                $conn->query($sqlUpd);

                // 4) user_video_stats — 3 "сидовых" пользователя с 100% досмотром
                $stmtStats = $conn->prepare("
                    INSERT INTO user_video_stats (user_id, video_id, views, max_watch_pct, liked, last_view_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        views         = views + VALUES(views),
                        max_watch_pct = GREATEST(max_watch_pct, VALUES(max_watch_pct)),
                        liked         = GREATEST(liked, VALUES(liked)),
                        last_view_at  = NOW()
                ");
                if ($stmtStats) {
                    $pct   = 100;
                    $liked = 1;
                    for ($i = 1; $i <= 3; $i++) {
                        $userId = 'seed_' . $videoId . '_' . $i;
                        $views  = 1;
                        $stmtStats->bind_param('siiii', $userId, $videoId, $views, $pct, $liked);
                        $stmtStats->execute();
                    }
                    $stmtStats->close();
                }

                $conn->commit();
                $message = "Стартовый импульс для видео ID={$videoId}: +3 просмотра и +1 лайк (100% досмотренности).";
            } catch (Throwable $e) {
                $conn->rollback();
                $message = "Ошибка при старте импульса для видео ID={$videoId}: " . $e->getMessage();
            }
        } elseif ($action === 'delete') {
            // --- Полное удаление видео и всех его следов ---
            $conn->begin_transaction();
            try {
                // Чистим зависимые таблицы
                $tables = [
                    'video_views',
                    'video_likes',
                    'user_video_stats',
                    'video_tags'
                ];

                foreach ($tables as $tbl) {
                    $sqlDel = "DELETE FROM {$tbl} WHERE video_id = ?";
                    $stmtDel = $conn->prepare($sqlDel);
                    if ($stmtDel) {
                        $stmtDel->bind_param('i', $videoId);
                        $stmtDel->execute();
                        $stmtDel->close();
                    }
                }

                // Удаляем саму запись из videos
                $stmtVid = $conn->prepare("DELETE FROM videos WHERE id = ? LIMIT 1");
                if ($stmtVid) {
                    $stmtVid->bind_param('i', $videoId);
                    $stmtVid->execute();
                    $stmtVid->close();
                }

                $conn->commit();
                $message = "Видео ID={$videoId} и все лайки/просмотры/теги по нему удалены.";
            } catch (Throwable $e) {
                $conn->rollback();
                $message = "Ошибка при удалении видео ID={$videoId}: " . $e->getMessage();
            }
        }
    }
}

// --- параметры фильтра ---
$externalId   = isset($_GET['external_id']) ? (int)$_GET['external_id'] : 0;
$userFilter   = isset($_GET['user']) ? trim((string)$_GET['user']) : '';
$tagId        = isset($_GET['tag_id']) ? (int)$_GET['tag_id'] : 0;
$missingOnly  = isset($_GET['missing']) && $_GET['missing'] === '1';

// --- Сканер: сколько видео без постеров (по БД) ---
$missingPostersCount = 0;
$sqlMissingCnt = "
    SELECT COUNT(*) AS c
    FROM videos v
    WHERE v.poster_path IS NULL OR v.poster_path = ''
";
$resMissingCnt = $conn->query($sqlMissingCnt);
if ($resMissingCnt && $rowM = $resMissingCnt->fetch_assoc()) {
    $missingPostersCount = (int)$rowM['c'];
    $resMissingCnt->free();
}

// список юзеров для селекта
$users = [];
$resUsers = $conn->query("SELECT username FROM users ORDER BY username ASC");
if ($resUsers) {
    while ($row = $resUsers->fetch_assoc()) {
        if ($row['username'] !== '') {
            $users[] = $row['username'];
        }
    }
    $resUsers->free();
}

// список тегов для селекта
$tags = [];
$resTags = $conn->query("SELECT id, name FROM tags ORDER BY name ASC");
if ($resTags) {
    while ($row = $resTags->fetch_assoc()) {
        $tags[] = $row;
    }
    $resTags->free();
}

// строим WHERE
$where = [];
if ($externalId > 0) {
    $where[] = "v.external_id = " . $externalId;
}
if ($userFilter !== '') {
    $where[] = "v.username = '" . $conn->real_escape_string($userFilter) . "'";
}
if ($tagId > 0) {
    $where[] = "vt.tag_id = " . $tagId;
}
if ($missingOnly) {
    $where[] = "(v.poster_path IS NULL OR v.poster_path = '')";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// основной запрос
$sqlVideos = "
    SELECT 
        v.id,
        v.external_id,
        v.username,
        v.page,
        v.folder_id,
        v.likes_cnt,
        v.views_cnt,
        v.poster_path,
        GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS tags
    FROM videos v
    LEFT JOIN video_tags vt ON vt.video_id = v.id
    LEFT JOIN tags t        ON t.id = vt.tag_id
    {$whereSql}
    GROUP BY v.id
    ORDER BY v.id DESC
    LIMIT 200
";

$videos = [];
$resVideos = $conn->query($sqlVideos);
if ($resVideos) {
    while ($row = $resVideos->fetch_assoc()) {
        $videos[] = $row;
    }
    $resVideos->free();
}
?>

<h2 class="mb-4">Videos list</h2>

<?php if ($message): ?>
    <div class="alert alert-info"><?php echo h($message); ?></div>
<?php endif; ?>

<!-- Сканер пустых видео (без постеров) -->
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h5 class="card-title mb-1">Сканер пустых видео</h5>
            <p class="mb-0 text-muted">
                Видео без постера (poster_path NULL или пустой): 
                <b><?php echo (int)$missingPostersCount; ?></b>
            </p>
        </div>
        <div class="mt-2 mt-md-0">
            <a href="menu.php?page=videos&missing=1"
               class="btn btn-sm btn-outline-danger <?php echo $missingOnly ? 'active' : ''; ?>">
                Показать только без постеров
            </a>
            <?php if ($missingOnly): ?>
                <a href="menu.php?page=videos" class="btn btn-sm btn-outline-secondary ms-2">
                    Сброс фильтра
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">Фильтры</h5>
        <form method="get" class="row g-3" action="menu.php">
            <input type="hidden" name="page" value="videos">
            <?php if ($missingOnly): ?>
                <input type="hidden" name="missing" value="1">
            <?php endif; ?>

            <div class="col-md-2">
                <label class="form-label">External ID</label>
                <input type="number" name="external_id" class="form-control"
                       value="<?php echo $externalId > 0 ? h((string)$externalId) : ''; ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">User</label>
                <select name="user" class="form-select">
                    <option value="">— все —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo h($u); ?>"
                            <?php echo ($u === $userFilter) ? 'selected' : ''; ?>>
                            <?php echo h($u); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Tag</label>
                <select name="tag_id" class="form-select">
                    <option value="0">— все —</option>
                    <?php foreach ($tags as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>"
                            <?php echo ($tagId === (int)$t['id']) ? 'selected' : ''; ?>>
                            <?php echo h($t['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 align-self-end">
                <button type="submit" class="btn btn-primary w-100">Фильтровать</button>
            </div>

            <div class="col-md-2 align-self-end">
                <a href="menu.php?page=videos" class="btn btn-outline-secondary w-100">Сброс</a>
            </div>
        </form>
        <?php if ($missingOnly): ?>
            <p class="mt-2 mb-0 text-danger small">
                Активен фильтр: показываются только видео без постеров.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">
            Найдено видео: <?php echo count($videos); ?> (лимит 200)
        </h5>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Ext.ID</th>
                    <th>User</th>
                    <th>Folder</th>
                    <th>Likes</th>
                    <th>Views</th>
                    <th>Tags</th>
                    <th>Page</th>
                    <th>Boost</th>
                    <th>Delete</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$videos): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted">Нет данных</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($videos as $v): ?>
                        <tr>
                            <td><?php echo (int)$v['id']; ?></td>
                            <td><?php echo (int)$v['external_id']; ?></td>
                            <td><?php echo h($v['username']); ?></td>
                            <td><?php echo (int)$v['folder_id']; ?></td>
                            <td><?php echo (int)$v['likes_cnt']; ?></td>
                            <td><?php echo (int)$v['views_cnt']; ?></td>
                            <td><?php echo h($v['tags']); ?></td>
                            <td style="max-width:260px;">
                                <?php if ($v['page']): ?>
                                    <a href="https://fapa.cam/v.php?id=<?php echo (int)$v['id']; ?>" target="_blank">
                                        https://fapa.cam/v.php?id=<?php echo (int)$v['id']; ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="menu.php?page=videos" class="d-inline">
                                    <input type="hidden" name="action" value="boost">
                                    <input type="hidden" name="video_id" value="<?php echo (int)$v['id']; ?>">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-success"
                                            onclick="return confirm('Дать стартовый импульс этому видео? +1 лайк и +3 просмотра (100% досмотренности для CTR-алгоритма).');">
                                        Boost
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" action="menu.php?page=videos" class="d-inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="video_id" value="<?php echo (int)$v['id']; ?>">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Точно удалить это видео и все его лайки/просмотры/теги из базы?');">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
