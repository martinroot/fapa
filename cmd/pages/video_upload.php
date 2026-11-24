<?php
// pages/video_upload.php
declare(strict_types=1);

/** @var string $DB_NAME */

require_once __DIR__ . '/../config_admin.php';

$BASE_DIR   = '/home/claude2/uvid';   // куда кладём папки с external_id
$FFMPEG_BIN = '/usr/bin/ffmpeg';         // путь к ffmpeg (проверь при необходимости)

$message  = '';
$logLines = [];

/**
 * Запуск ffmpeg с логированием
 */
function run_ffmpeg_cmd(string $cmd, array &$logLines): bool
{
    $logLines[] = '$ ' . $cmd;
    $output = [];
    $ret    = 0;
    exec($cmd, $output, $ret);
    foreach ($output as $line) {
        $logLines[] = $line;
    }
    $logLines[] = "exit code: {$ret}";
    return $ret === 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_mp4') {
    $externalId = isset($_POST['external_id']) ? (int)$_POST['external_id'] : 0;

    // Доп. данные
    $username = trim($_POST['username'] ?? '');
    $pageUrl  = trim($_POST['page_url'] ?? '');
    $stream   = trim($_POST['stream_url'] ?? '');
    $tagsRaw  = trim($_POST['tags'] ?? '');

    if ($externalId <= 0) {
        $message = 'Укажи корректный external_id (целое число).';
    } elseif (!isset($_FILES['mp4']) || ($_FILES['mp4']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $message = 'Загрузи mp4-файл.';
    } else {
        $fileTmp  = $_FILES['mp4']['tmp_name'];
        $fileName = $_FILES['mp4']['name'];

        if (!is_uploaded_file($fileTmp)) {
            $message = 'Файл не является загруженным через HTTP.';
        } else {
            // Папка /uvid/EXTERNAL_ID
            $dir = rtrim($BASE_DIR, '/') . '/' . $externalId;
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                    $message = 'Не удалось создать директорию: ' . $dir;
                }
            }

            if ($message === '') {
                $mp4Path    = $dir . '/' . $externalId . '.mp4';
                $m3u8Path   = $dir . '/index.m3u8';
                $segPattern = $dir . '/chunk_%05d.ts';
                $posterPath = ''.$dir . '/poster.jpg';
                $jsonPath   = $dir . '/' . $externalId . '.json';

                // Переносим mp4
                if (!move_uploaded_file($fileTmp, $mp4Path)) {
                    $message = 'Не удалось переместить загруженный файл в ' . $mp4Path;
                } else {
                    @chmod($mp4Path, 0664);

                    // === 1) HLS: перекодируем в нормальный H.264/AAC ===
                    $cmdHls = sprintf(
    '%s -y -loglevel error -i %s ' .
    '-c:v libx264 -preset veryfast -crf 23 ' .
    '-c:a aac -b:a 128k -ac 2 ' .
    '-start_number 0 ' .
    '-hls_segment_size 200000 ' .           // ≈ 200 KB на сегмент
    '-hls_time 6 ' .                        // максимум 6 c, но порежет раньше по размеру
    '-hls_playlist_type vod ' .
    '-hls_segment_filename %s %s 2>&1',
    escapeshellcmd($FFMPEG_BIN),
    escapeshellarg($mp4Path),
    escapeshellarg($segPattern),
    escapeshellarg($m3u8Path)
);
                    $okHls = run_ffmpeg_cmd($cmdHls, $logLines);


                    // === 2) Постер — первый кадр ===
                    $cmdPoster = sprintf(
                        '%s -y -loglevel error -i %s -ss 0 -vframes 1 -q:v 2 %s 2>&1',
                        escapeshellcmd($FFMPEG_BIN),
                        escapeshellarg($mp4Path),
                        escapeshellarg($posterPath)
                    );
                    $okPoster = run_ffmpeg_cmd($cmdPoster, $logLines);

                    if (!$okHls) {
                        $message = 'FFmpeg: не удалось собрать HLS (index.m3u8). См. лог ниже.';
                    } elseif (!$okPoster) {
                        $message = 'FFmpeg: не удалось сделать poster.jpg, но HLS собран.';
                    } else {
                        // === 3) Обновляем БД (videos, users, tags, video_tags) + JSON ===
                        try {
                            $conn = db_connect($DB_NAME);

                            // --- users: автор ---
                            $userId = null;
                            if ($username !== '') {
                                $stmtU = $conn->prepare("
                                    INSERT INTO users (username)
                                    VALUES (?)
                                    ON DUPLICATE KEY UPDATE username = VALUES(username)
                                ");
                                if ($stmtU) {
                                    $stmtU->bind_param('s', $username);
                                    $stmtU->execute();
                                    $insId = $stmtU->insert_id;
                                    $stmtU->close();

                                    if ($insId > 0) {
                                        $userId = $insId;
                                    } else {
                                        $stmtU2 = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                                        if ($stmtU2) {
                                            $stmtU2->bind_param('s', $username);
                                            $stmtU2->execute();
                                            $stmtU2->bind_result($uid);
                                            if ($stmtU2->fetch()) {
                                                $userId = (int)$uid;
                                            }
                                            $stmtU2->close();
                                        }
                                    }
                                } else {
                                    $logLines[] = 'DB users prepare error: ' . $conn->error;
                                }
                            }

                            $relPoster = "/home/claude2/uvid/{$externalId}/poster.jpg";
                            $relM3u8   = "/home/claude2/uvid/{$externalId}/index.m3u8";

                            // --- videos: вставка / обновление ---

                            
                            if ($userId !== null) {
                                $stmtV = $conn->prepare("
                                    INSERT INTO videos (external_id, folder_id, user_id, username, page, stream, poster_path, m3u8_path)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE
                                        folder_id   = VALUES(folder_id),
                                        user_id     = VALUES(user_id),
                                        username    = VALUES(username),
                                        page        = VALUES(page),
                                        stream      = VALUES(stream),
                                        poster_path = VALUES(poster_path),
                                        m3u8_path   = VALUES(m3u8_path),
                                        updated_at  = NOW()
                                ");
                                if ($stmtV) {
                                    $stmtV->bind_param(
                                        'iiisssss',
                                        $externalId,
                                        $externalId,
                                        $userId,
                                        $username,
                                        $pageUrl,
                                        $stream,
                                        $relPoster,
                                        $relM3u8
                                    );
                                    $stmtV->execute();
                                    $stmtV->close();
                                } else {
                                    $logLines[] = 'DB videos (user_id) prepare error: ' . $conn->error;
                                }
                            } else {
                                $stmtV = $conn->prepare("
                                    INSERT INTO videos (external_id, folder_id, username, page, stream, poster_path, m3u8_path)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE
                                        folder_id   = VALUES(folder_id),
                                        username    = VALUES(username),
                                        page        = VALUES(page),
                                        stream      = VALUES(stream),
                                        poster_path = VALUES(poster_path),
                                        m3u8_path   = VALUES(m3u8_path),
                                        updated_at  = NOW()
                                ");
                                if ($stmtV) {
                                    $stmtV->bind_param(
                                        'iisssss',
                                        $externalId,
                                        $externalId,
                                        $username,
                                        $pageUrl,
                                        $stream,
                                        $relPoster,
                                        $relM3u8
                                    );
                                    $stmtV->execute();
                                    $stmtV->close();
                                } else {
                                    $logLines[] = 'DB videos prepare error: ' . $conn->error;
                                }
                            }

                            // --- получаем video_id по external_id ---
                            $videoId = 0;
                            $stmtVid = $conn->prepare("SELECT id FROM videos WHERE external_id = ? LIMIT 1");
                            if ($stmtVid) {
                                $stmtVid->bind_param('i', $externalId);
                                $stmtVid->execute();
                                $stmtVid->bind_result($vidId);
                                if ($stmtVid->fetch()) {
                                    $videoId = (int)$vidId;
                                }
                                $stmtVid->close();
                            }

                            // --- Теги: парсим, апсертим tags, обновляем video_tags ---
                            $tagList = [];
                            if ($tagsRaw !== '') {
                                $parts = preg_split('/[,\n]+/u', $tagsRaw);
                                foreach ($parts as $t) {
                                    $t = trim($t);
                                    if ($t === '') {
                                        continue;
                                    }
                                    $tagList[] = $t;
                                }
                                $tagList = array_values(array_unique($tagList));
                            }

                            if ($videoId > 0 && $tagList) {
                                // чистим старые связи
                                $stmtDelVT = $conn->prepare("DELETE FROM video_tags WHERE video_id = ?");
                                if ($stmtDelVT) {
                                    $stmtDelVT->bind_param('i', $videoId);
                                    $stmtDelVT->execute();
                                    $stmtDelVT->close();
                                }

                                $stmtTagIns = $conn->prepare("
                                    INSERT INTO tags (name, slug)
                                    VALUES (?, ?)
                                    ON DUPLICATE KEY UPDATE slug = VALUES(slug)
                                ");

                                $stmtTagGet = $conn->prepare("SELECT id FROM tags WHERE name = ? LIMIT 1");

                                $stmtVT = $conn->prepare("
                                    INSERT IGNORE INTO video_tags (video_id, tag_id)
                                    VALUES (?, ?)
                                ");

                                foreach ($tagList as $tagName) {
                                    $slug = strtolower(preg_replace('/\s+/', '-', $tagName));

                                    // tags
                                    $tagId = 0;
                                    if ($stmtTagIns) {
                                        $stmtTagIns->bind_param('ss', $tagName, $slug);
                                        $stmtTagIns->execute();
                                        $tInsId = $stmtTagIns->insert_id;
                                        if ($tInsId > 0) {
                                            $tagId = $tInsId;
                                        }
                                    }
                                    if ($tagId === 0 && $stmtTagGet) {
                                        $stmtTagGet->bind_param('s', $tagName);
                                        $stmtTagGet->execute();
                                        $stmtTagGet->bind_result($tid);
                                        if ($stmtTagGet->fetch()) {
                                            $tagId = (int)$tid;
                                        }
                                        $stmtTagGet->free_result();
                                    }

                                    if ($tagId > 0 && $stmtVT) {
                                        $stmtVT->bind_param('ii', $videoId, $tagId);
                                        $stmtVT->execute();
                                    }
                                }

                                if ($stmtTagIns) $stmtTagIns->close();
                                if ($stmtTagGet) $stmtTagGet->close();
                                if ($stmtVT)     $stmtVT->close();
                            }

                            // --- JSON-описание (как в старом пайплайне) ---
                            $jsonData = [
                                'id'     => $externalId,
                                'user'   => $username,
                                'page'   => $pageUrl,
                                'stream' => $stream,
                                'tags'   => $tagList,
                            ];
                            file_put_contents(
                                $jsonPath,
                                json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                            );
                            @chmod($jsonPath, 0664);

                            $conn->close();
                        } catch (Throwable $e) {
                            $logLines[] = 'DB exception: ' . $e->getMessage();
                        }

                        if ($message === '') {
                            $message = "Видео {$externalId} успешно загружено: mp4 + HLS + poster + JSON + вставка в БД (users/videos/tags/video_tags).";
                        }
                    }
                }
            }
        }
    }
}
?>
<h2 class="mb-4">Video upload (mp4 → HLS + meta)</h2>

<?php if ($message): ?>
    <div class="alert alert-info"><?php echo h($message); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_mp4">

            <div class="mb-3">
                <label class="form-label">External ID / Folder ID</label>
                <input type="number" name="external_id" class="form-control" required
                       placeholder="например 1001">
                <div class="form-text">
                    Будет создана папка <code>/uvid/ID</code>:
                    <code>ID.mp4</code>, <code>index.m3u8</code>, <code>chunk_*.ts</code>, <code>poster.jpg</code>, <code>ID.json</code>.
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">MP4 файл</label>
                <input type="file" name="mp4" accept="video/mp4" class="form-control" required>
            </div>

            <hr>

            <div class="mb-3">
                <label class="form-label">Автор (username)</label>
                <input type="text" name="username" value="ai_porn" class="form-control" placeholder="например candyai">
                <div class="form-text">
                    Будет сохранён в таблицу <code>users</code>, связка идёт через <code>videos.user_id</code> и <code>videos.username</code>.
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Page URL (исходная страница)</label>
                <input type="text" name="page_url" class="form-control" placeholder="https://...">
            </div>

            <div class="mb-3">
                <label class="form-label">Stream URL (опционально, для истории)</label>
                <input type="text" name="stream_url" class="form-control" placeholder="https://.../index.m3u8">
            </div>

            <div class="mb-3">
                <label class="form-label">Теги (через запятую или с новой строки)</label>
                <textarea name="tags" class="form-control" rows="3"
                          placeholder="tag1, tag2, tag3">AI Porn</textarea>
                <div class="form-text">
                    Будут сохранены в <code>tags</code> и связаны через <code>video_tags</code>.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Загрузить и сконвертировать</button>
        </form>
    </div>
</div>

<?php if ($logLines): ?>
    <div class="card">
        <div class="card-header">FFmpeg / DB log</div>
        <div class="card-body">
            <pre class="schema-json mb-0" style="max-height: 400px;"><?php
                echo h(implode("\n", $logLines));
            ?></pre>
        </div>
    </div>
<?php endif; ?>
