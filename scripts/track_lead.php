<?php
declare(strict_types=1);

require_once __DIR__ . '/../cmd/config.php'; // db_connect(), h()

header('Content-Type: text/plain; charset=utf-8');

$conn = db_connect();

$cid  = isset($_POST['cid']) ? (int)$_POST['cid'] : 0;
$lead = $_POST['lead'] ?? '';

if ($cid <= 0) {
    http_response_code(400);
    echo "bad cid";
    exit;
}

if (!in_array($lead, ['1','2','3','4'], true)) {
    http_response_code(400);
    echo "bad lead";
    exit;
}

$column = 'lead' . $lead;

// на всякий — защита от инъекции (но колонка жёстко из списка)
$sql = "UPDATE clicks SET `$column` = 1 WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "stmt error";
    exit;
}

$stmt->bind_param('i', $cid);
$stmt->execute();

if ($stmt->error) {
    http_response_code(500);
    echo "db error";
    exit;
}

echo "ok";
