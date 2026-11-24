<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../cmd/config.php';

try {
    $videoId = isset($_POST['video_id']) ? (int)$_POST['video_id'] : 0;
    $percent = isset($_POST['percent']) ? (float)$_POST['percent'] : 0.0;

    if ($videoId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'bad params']);
        exit;
    }

    // Нормализуем % (0–100)
    if ($percent < 0)   $percent = 0;
    if ($percent > 100) $percent = 100;

    $conn = db_connect();

    // тот же идентификатор юзера, что и в track_like / track_view
    $uiq = $_COOKIE['uiq'] ?? bin2hex(random_bytes(8));
    if (!isset($_COOKIE['uiq'])) {
        setcookie('uiq', $uiq, time() + 31536000, '/', '', false, true);
    }

    // 1) Обновляем максимум досмотра в user_video_stats
    $sqlUvs = "
        INSERT INTO user_video_stats (user_id, video_id, views, max_watch_pct, liked, last_view_at)
        VALUES (?, ?, 0, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE
          max_watch_pct = GREATEST(max_watch_pct, VALUES(max_watch_pct)),
          last_view_at  = VALUES(last_view_at)
    ";
    if ($stmt = $conn->prepare($sqlUvs)) {
        $pctInt = (int)round($percent);
        $stmt->bind_param('sii', $uiq, $videoId, $pctInt);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log('track_watch: user_video_stats prepare failed: ' . $conn->error);
    }

    // 2) Если досмотр >= 80% — добавляем небольшой вес тегам (+1)
    if ($percent >= 80) {
        $sqlUts = "
            INSERT INTO user_tag_score (user_id, tag_id, score, last_upd)
            SELECT ?, vt.tag_id, 1, NOW()
            FROM video_tags vt
            WHERE vt.video_id = ?
            ON DUPLICATE KEY UPDATE
              score   = score + 1,
              last_upd = VALUES(last_upd)
        ";
        if ($stmt = $conn->prepare($sqlUts)) {
            $stmt->bind_param('si', $uiq, $videoId);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log('track_watch: user_tag_score prepare failed: ' . $conn->error);
        }
    }

    echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
