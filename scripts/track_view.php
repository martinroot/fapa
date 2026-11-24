<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../cmd/config.php';

try {
    $videoId = isset($_POST['video_id']) ? (int)$_POST['video_id'] : 0;
    if ($videoId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'bad params']);
        exit;
    }

    $conn = db_connect();

    // Такой же идентификатор юзера, как в track_like.php
    $uiq = $_COOKIE['uiq'] ?? bin2hex(random_bytes(8));
    if (!isset($_COOKIE['uiq'])) {
        setcookie('uiq', $uiq, time() + 31536000, '/', '', false, true);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    // 1) логируем просмотр (если у тебя другая структура video_views — просто поправь список полей)
    if ($stmt = $conn->prepare(
        "INSERT INTO video_views (video_id, uiq, ip) VALUES (?, ?, ?)"
    )) {
        $stmt->bind_param('iss', $videoId, $uiq, $ip);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log('track_view: video_views prepare failed: ' . $conn->error);
        // не фаталим, чтобы не мешать основному флоу
    }

    // 2) инкремент счётчика просмотров в videos
    $conn->query(
        "UPDATE videos SET views_cnt = views_cnt + 1 WHERE id = {$videoId}"
    );

    // 3) user_video_stats: увеличиваем views
    $sqlUvs = "
        INSERT INTO user_video_stats (user_id, video_id, views, max_watch_pct, liked, last_view_at)
        VALUES (?, ?, 1, 0, 0, NOW())
        ON DUPLICATE KEY UPDATE
          views       = views + 1,
          last_view_at = VALUES(last_view_at)
    ";
    if ($stmt = $conn->prepare($sqlUvs)) {
        $stmt->bind_param('si', $uiq, $videoId);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log('track_view: user_video_stats prepare failed: ' . $conn->error);
    }

    echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
