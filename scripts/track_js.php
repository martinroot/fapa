<?php
declare(strict_types=1);

require_once __DIR__ . '/../cmd/config.php'; // db_connect()

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$cid = isset($_POST['cid']) ? (int)$_POST['cid'] : 0;
if ($cid <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Bad cid']);
    exit;
}

$conn = db_connect();

$sql = "UPDATE `clicks` SET `js` = 1 WHERE `id` = ? AND `js` = 0 LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed']);
    exit;
}

$stmt->bind_param('i', $cid);
$stmt->execute();

if ($stmt->error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error']);
    $stmt->close();
    exit;
}

$stmt->close();

echo json_encode(['status' => 'ok']);
