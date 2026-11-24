<?php
// pages/video_import.php
/** @var string $DB_NAME */

$importReport = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_videos') {
    $baseDir = '/home/claude2/uvid';

    $conn = db_connect($DB_NAME);
    ensure_fapaio_schema($conn);

    $processed       = 0; // реально новых / переимпортированных папок
    $inserted        = 0;
    $updated         = 0;
    $tagCount        = 0;
    $skippedExisting = 0; // папки, которые уже есть в базе (external_id)

    // --- заранее забираем все уже известные external_id, чтобы не трогать их повторно ---
    $existingIds = [];
    $resExist = $conn->query("SELECT external_id FROM videos");
    if ($resExist) {
        while ($row = $resExist->fetch_assoc()) {
            $eid = (int)$row['external_id'];
            if ($eid > 0) {
                $existingIds[$eid] = true;
            }
        }
        $resExist->free();
    }

    if (is_dir($baseDir)) {
        $dirs = scandir($baseDir);
        foreach ($dirs as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!ctype_digit($entry)) {
                continue; // ждем ID папки
            }

            $folderId  = (int)$entry;
            $folderDir = $baseDir . '/' . $entry;
            if (!is_dir($folderDir)) {
                continue;
            }

            // если такой external_id уже есть в базе — считаем, что видео уже импортировано, аккуратно скипаем
            if (isset($existingIds[$folderId])) {
                $skippedExisting++;
                continue;
            }

            $jsonPath = $folderDir . '/' . $entry . '.json';
            if (!file_exists($jsonPath)) {
                continue;
            }

            $jsonStr = @file_get_contents($jsonPath);
            if ($jsonStr === false || $jsonStr === '') {
                continue;
            }

            $data = json_decode($jsonStr, true);
            if (!is_array($data)) {
                continue;
            }

            $processed++;

            $externalId = (int)($data['id'] ?? 0);
            if ($externalId <= 0) {
                // на всякий случай: если в JSON нет ID — используем имя папки
                $externalId = $folderId;
            }

            $username = isset($data['user']) ? trim((string)$data['user']) : null;
            $pageUrl  = isset($data['page']) ? trim((string)$data['page']) : null;
            $stream   = isset($data['stream']) ? trim((string)$data['stream']) : null;

            // m3u8 из stream/streams
            $m3u8 = null;
            if ($stream && str_contains($stream, 'index.m3u8')) {
                $m3u8 = $stream;
            } elseif (!empty($data['streams']) && is_array($data['streams'])) {
                foreach ($data['streams'] as $url) {
                    $url = (string)$url;
                    if (str_contains($url, 'index.m3u8')) {
                        $m3u8 = $url;
                        break;
                    }
                }
            }

            // локальные файлы в папке
            $localM3u8   = is_file($folderDir . '/index.m3u8') ? $folderDir . '/index.m3u8' : null;
            $posterLocal = null;
            foreach (glob($folderDir . '/poster*.*') as $p) {
                $posterLocal = $p;
                break;
            }

            // === users: INSERT IGNORE + получение user_id ===
            $userId = null;
            if ($username) {
                // вставка, если нет
                $stmtUser = $conn->prepare("INSERT IGNORE INTO users (username) VALUES (?)");
                if ($stmtUser) {
                    $stmtUser->bind_param('s', $username);
                    $stmtUser->execute();
                    $stmtUser->close();
                }

                // получаем id
                $stmtGetUser = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                if ($stmtGetUser) {
                    $stmtGetUser->bind_param('s', $username);
                    $stmtGetUser->execute();
                    $stmtGetUser->bind_result($uid);
                    if ($stmtGetUser->fetch()) {
                        $userId = (int)$uid;
                    }
                    $stmtGetUser->close();
                }
            }

            // === videos: UPSERT по external_id, пишем user_id + username ===
            $stmtVideo = $conn->prepare("
                INSERT INTO videos (external_id, user_id, username, page, stream, folder_id, poster_path, m3u8_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    user_id    = VALUES(user_id),
                    username   = VALUES(username),
                    page       = VALUES(page),
                    stream     = VALUES(stream),
                    folder_id  = VALUES(folder_id),
                    poster_path= VALUES(poster_path),
                    m3u8_path  = VALUES(m3u8_path),
                    updated_at = CURRENT_TIMESTAMP
            ");
            if (!$stmtVideo) {
                continue;
            }

            $posterPathSave = $posterLocal ?: null;
            $m3u8PathSave   = $localM3u8 ?: $m3u8;

            // типы: external_id(int), user_id(?int), username(?string), page(?string),
            //       stream(?string), folder_id(int), poster_path(?string), m3u8_path(?string)
            $stmtVideo->bind_param(
                'iisssiss',
                $externalId,
                $userId,
                $username,
                $pageUrl,
                $stream,
                $folderId,
                $posterPathSave,
                $m3u8PathSave
            );
            $stmtVideo->execute();

            if ($stmtVideo->affected_rows > 0) {
                if ($stmtVideo->insert_id > 0) {
                    $inserted++;
                } else {
                    $updated++;
                }
            }
            $stmtVideo->close();

            // помечаем этот external_id как уже импортированный,
            // чтобы при следующем заходе по dirs он не попал в "новые"
            $existingIds[$externalId] = true;

            // получаем id видео
            $videoId = null;
            $resVid = $conn->query("SELECT id FROM videos WHERE external_id = " . (int)$externalId . " LIMIT 1");
            if ($resVid && $resVid->num_rows > 0) {
                $rowVid  = $resVid->fetch_assoc();
                $videoId = (int)$rowVid['id'];
                $resVid->free();
            }
            if (!$videoId) {
                continue;
            }

            // === теги ===
            if (!empty($data['tags']) && is_array($data['tags'])) {
                foreach ($data['tags'] as $tagRaw) {
                    $tagName = trim((string)$tagRaw);
                    if ($tagName === '') continue;

                    $slug = mb_strtolower(preg_replace('~[^a-z0-9]+~iu', '-', $tagName), 'UTF-8');
                    $slug = trim($slug, '-');

                    // tags: INSERT IGNORE
                    $stmtTag = $conn->prepare("INSERT IGNORE INTO tags (name, slug) VALUES (?, ?)");
                    if ($stmtTag) {
                        $stmtTag->bind_param('ss', $tagName, $slug);
                        $stmtTag->execute();
                        $stmtTag->close();
                    }

                    // id тега
                    $safeName = $conn->real_escape_string($tagName);
                    $resTag = $conn->query("SELECT id FROM tags WHERE name = '{$safeName}' LIMIT 1");
                    if ($resTag && $resTag->num_rows > 0) {
                        $rowTag = $resTag->fetch_assoc();
                        $tagId  = (int)$rowTag['id'];
                        $resTag->free();

                        if ($tagId) {
                            $stmtVT = $conn->prepare("
                                INSERT IGNORE INTO video_tags (video_id, tag_id)
                                VALUES (?, ?)
                            ");
                            if ($stmtVT) {
                                $stmtVT->bind_param('ii', $videoId, $tagId);
                                $stmtVT->execute();
                                $stmtVT->close();
                                $tagCount++;
                            }
                        }
                    }
                }
            }
        }

        $importReport = "Импорт видео завершен. "
            . "Обработано новых папок: {$processed}, "
            . "новых записей: {$inserted}, "
            . "обновлено: {$updated}, "
            . "привязок тегов: {$tagCount}, "
            . "пропущено (уже в базе): {$skippedExisting}.";
    } else {
        $importReport = "Директория {$baseDir} не найдена.";
    }

    $conn->close();
}
?>

<h2 class="mb-4">Video import</h2>

<?php if ($importReport): ?>
    <div class="alert alert-success"><?php echo h($importReport); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">Импорт из /home/claude2/uvid</h5>
        <p class="card-text">
            Ожидается структура: <code>/home/claude2/uvid/ID/ID.json</code> +
            локальные файлы <code>index.m3u8</code>, <code>poster*</code> (если есть).
        </p>
        <ul>
            <li>БД: <b><?php echo h($DB_NAME); ?></b></li>
            <li>Создаются/обновляются таблицы:
                <code>users</code>, <code>videos</code>, <code>tags</code>,
                <code>video_tags</code>, <code>video_views</code>, <code>video_likes</code>
            </li>
            <li><code>videos.id</code> — AUTO_INCREMENT с 13800</li>
            <li><code>videos.external_id</code> = ID из JSON/папки</li>
            <li><code>videos.user_id</code> → <code>users.id</code>, связь 1:N (юзер → видео)</li>
            <li>Теги из <code>tags[]</code> в JSON → <code>tags</code> + <code>video_tags</code></li>
            <li>Повторные запуски обрабатывают только новые папки, уже импортированные аккуратно скипаются.</li>
        </ul>
        <form method="post">
            <input type="hidden" name="action" value="import_videos">
            <button class="btn btn-warning" type="submit">Запустить импорт</button>
        </form>
    </div>
</div>
