<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../cmd/config.php';

try {
    $videoId = isset($_POST['video_id']) ? (int)$_POST['video_id'] : 0;
    $action  = $_POST['action'] ?? '';

    if ($videoId <= 0 || !in_array($action, ['like', 'unlike'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'bad params']);
        exit;
    }

    $conn = db_connect();

    // UIQ — идентификатор юзера (по куке)
    $uiq = $_COOKIE['uiq'] ?? bin2hex(random_bytes(8));
    if (!isset($_COOKIE['uiq'])) {
        // кука на год
        setcookie('uiq', $uiq, time() + 31536000, '/', '', false, true);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    if ($action === 'like') {
        // 1) фиксируем лайк в video_likes (UNIQUE(video_id, uiq))
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO video_likes (video_id, uiq, ip) VALUES (?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('prepare failed: video_likes');
        }
        $stmt->bind_param('iss', $videoId, $uiq, $ip);
        $stmt->execute();
        $inserted = $stmt->affected_rows;
        $stmt->close();

        // 2) если реально новый лайк — увеличиваем счётчик
        if ($inserted > 0) {
            $conn->query(
                "UPDATE videos SET likes_cnt = likes_cnt + 1 WHERE id = {$videoId}"
            );
        }

        // 3) user_video_stats: помечаем liked = 1
        $sqlUvs = "
            INSERT INTO user_video_stats (user_id, video_id, views, max_watch_pct, liked, last_view_at)
            VALUES (?, ?, 0, 0, 1, NOW())
            ON DUPLICATE KEY UPDATE
              liked        = VALUES(liked),
              last_view_at = VALUES(last_view_at)
        ";
        if ($stmt = $conn->prepare($sqlUvs)) {
            $stmt->bind_param('si', $uiq, $videoId);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log('track_like: user_video_stats prepare failed: ' . $conn->error);
        }

        // 4) user_tag_score: бустим теги лайкнутого видео (+3)
        $sqlUts = "
            INSERT INTO user_tag_score (user_id, tag_id, score, last_upd)
            SELECT ?, vt.tag_id, 3, NOW()
            FROM video_tags vt
            WHERE vt.video_id = ?
            ON DUPLICATE KEY UPDATE
              score   = score + 3,
              last_upd = VALUES(last_upd)
        ";
        if ($stmt = $conn->prepare($sqlUts)) {
            $stmt->bind_param('si', $uiq, $videoId);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log('track_like: user_tag_score prepare failed: ' . $conn->error);
        }

    } else { // unlike
        // снимаем лайк в video_likes
        $stmt = $conn->prepare(
            "DELETE FROM video_likes WHERE video_id = ? AND uiq = ?"
        );
        if (!$stmt) {
            throw new RuntimeException('prepare failed: video_likes delete');
        }
        $stmt->bind_param('is', $videoId, $uiq);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($deleted > 0) {
            $conn->query(
                "UPDATE videos SET likes_cnt = GREATEST(0, likes_cnt - 1) WHERE id = {$videoId}"
            );
        }

        // user_video_stats: liked = 0
        $sqlUvs = "
            INSERT INTO user_video_stats (user_id, video_id, views, max_watch_pct, liked, last_view_at)
            VALUES (?, ?, 0, 0, 0, NOW())
            ON DUPLICATE KEY UPDATE
              liked        = VALUES(liked),
              last_view_at = VALUES(last_view_at)
        ";
        if ($stmt = $conn->prepare($sqlUvs)) {
            $stmt->bind_param('si', $uiq, $videoId);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log('track_like: user_video_stats (unlike) prepare failed: ' . $conn->error);
        }
        // веса тегов пока не уменьшаем при unlike — это можно добавить потом.
    }

    // читаем актуальное значение
    $likes = 0;
    $res = $conn->query(
        "SELECT likes_cnt FROM videos WHERE id = {$videoId} LIMIT 1"
    );
    if ($res && $row = $res->fetch_assoc()) {
        $likes = (int)$row['likes_cnt'];
        $res->free();
    }

    echo json_encode([
        'status' => 'ok',
        'likes'  => $likes,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
