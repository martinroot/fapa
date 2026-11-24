<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../cmd/config.php';   // строго в глобальной области: db_connect() читает $DB_* как глобальные

if (!defined('HOTAPI_KEY')) {
    define('HOTAPI_KEY', getenv('HOTAPI_KEY') ?: '');
    define('HOTAPI_BASE', 'https://api.hotapi.ai/v1');
}

$AI_BASE    = 'http://127.0.0.1:8899';
$AI_USER    = 'admin';
$AI_PASS    = (getenv('GPU_PORTAL_PASS') ?: '');
$TOKEN_FILE = '/tmp/fapa_ai_token_local.json';
$LOCAL_DIR  = __DIR__ . '/../gen_results';
$LOCAL_URL  = 'https://hotbabestube.ru/gen_results';

$jobId = trim((string)($_GET['job_id'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9_:\-]{5,80}$/', $jobId)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad job_id']);
    exit;
}

// Get token (with refresh if expired/missing)
function ai_status_get_token(string $base, string $user, string $pass, string $tokenFile): string {
    if (file_exists($tokenFile)) {
        $cache = json_decode(file_get_contents($tokenFile), true);
        if (isset($cache['token'], $cache['expires_at']) && time() < $cache['expires_at']) {
            return $cache['token'];
        }
    }
    $ch = curl_init("$base/api/login");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['username' => $user, 'password' => $pass]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data  = json_decode($resp ?: '', true);
    $token = $data['token'] ?? '';
    if ($token) {
        @file_put_contents($tokenFile, json_encode(['token' => $token, 'expires_at' => time() + 82800]));
    }
    return $token;
}

/** Закрыть задание ошибкой и вернуть списанное — ровно один раз.
 *  Токен снимается при отправке задания, поэтому за упавшую генерацию человек
 *  остаётся и без картинки, и без попытки, и упирается в лимит ни с чем. */
function gen_refund_once(string $jobId): void
{
    $rc   = db_connect();
    $safe = $rc->real_escape_string($jobId);
    $row  = ($rr = $rc->query("SELECT site_user_id, template_id FROM user_generations
                               WHERE job_id = '$safe' LIMIT 1")) ? $rr->fetch_assoc() : null;
    $rc->query("UPDATE user_generations SET status = 'error' WHERE job_id = '$safe' AND status <> 'error'");
    if ($rc->affected_rows > 0 && $row) {
        $cost = (($row['template_id'] ?? '') === 'flux2-wan22-i2v-foley')
            ? (defined('GEN_TOKEN_COST_VIDEO') ? (int)GEN_TOKEN_COST_VIDEO : 1000)
            : (defined('GEN_TOKEN_COST')       ? (int)GEN_TOKEN_COST       : 300);
        $uid = (int)($row['site_user_id'] ?? 0);
        if ($uid > 0) {
            $rc->query("UPDATE site_users SET tokens = COALESCE(tokens,0) + $cost WHERE id = $uid");
        } elseif (!headers_sent()) {
            // счёт анонима живёт в куке — вернуть можно только в его же браузере
            $spent = max(0, (int)($_COOKIE['fapa_spent'] ?? 0));
            if ($spent > 0) setcookie('fapa_spent', (string)($spent - 1), time() + 31536000, '/', '', false, true);
        }
    }
    $rc->close();
}

/** Задание старше N минут? Свежие 404 не хороним: сервер мог не успеть его завести. */
function gen_older_than(string $jobId, int $minutes): bool
{
    $c    = db_connect();
    $safe = $c->real_escape_string($jobId);
    $r    = $c->query("SELECT created_at < DATE_SUB(NOW(), INTERVAL $minutes MINUTE) old
                       FROM user_generations WHERE job_id = '$safe' LIMIT 1");
    $old  = $r ? (int)($r->fetch_row()[0] ?? 0) : 0;
    $c->close();
    return $old === 1;
}

// HotAPI-задачи: job_id вида "hotapi:<task_id>" — статус берём у HotAPI
if (str_starts_with($jobId, 'hotapi:')) {
    $taskId = substr($jobId, 7);
    $HK = 'https://api.hotapi.ai/v1';
    $ch = curl_init("$HK/tasks/" . urlencode($taskId));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . (defined('HOTAPI_KEY') ? HOTAPI_KEY : '')]]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $t = json_decode((string)$resp, true);
    $st = (string)($t['status'] ?? 'unknown');
    if ($st === 'succeeded') {
        $url = '';
        foreach (($t['output']['assets'] ?? []) as $a) { if (!empty($a['url'])) { $url = (string)$a['url']; break; } }
        // зеркалим локально
        if ($url !== '') {
            $dir = __DIR__ . '/../gen_results/hotapi';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $ext = str_contains($url, '.png') ? 'png' : 'jpg';
            $name = 'hotapi-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
            $ch2 = curl_init($url);
            curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
            $bin = curl_exec($ch2); curl_close($ch2);
            if (is_string($bin) && $bin !== '') { file_put_contents("$dir/$name", $bin); @chmod("$dir/$name", 0664); $url = "https://core-vpn.click/gen_results/hotapi/$name"; }
        }
        $uconn = db_connect();
        $upd = $uconn->prepare("UPDATE user_generations SET status='done', result_url=? WHERE job_id=? AND status<>'done'");
        if ($upd) { $ru = $url; $upd->bind_param('ss', $ru, $jobId); $upd->execute(); $upd->close(); }
        $uconn->close();
        echo json_encode(['status' => 'done', 'result_url' => $url, 'result_type' => 'image']);
        exit;
    }
    if (in_array($st, ['failed', 'cancelled', 'expired'], true)) {
        gen_refund_once($jobId);
        echo json_encode(['status' => 'error', 'error' => 'hotapi_' . $st, 'result_url' => null]);
        exit;
    }
    echo json_encode(['status' => 'processing', 'progress' => 50]);
    exit;
}
$token = ai_status_get_token($AI_BASE, $AI_USER, $AI_PASS, $TOKEN_FILE);
if (!$token) {
    http_response_code(502);
    echo json_encode(['error' => 'no token']);
    exit;
}

$ch = curl_init("$AI_BASE/api/jobs/$jobId");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
    CURLOPT_TIMEOUT        => 15,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 404 && gen_older_than($jobId, 10)) {
    // задания на GPU уже нет, а ждать больше нечего: раньше страница в этом
    // месте опрашивала статус бесконечно и человек смотрел на полосу до упора
    gen_refund_once($jobId);
    echo json_encode(['status' => 'error', 'error' => 'job_lost', 'result_url' => null]);
    exit;
}
if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream error', 'code' => $httpCode]);
    exit;
}

$data = json_decode($resp ?: '', true);
if (!$data) {
    http_response_code(502);
    echo json_encode(['error' => 'bad response']);
    exit;
}

$status  = $data['status'] ?? 'unknown';
$outputs = $data['result']['outputs'] ?? [];
$preview = $data['result']['split_preview'] ?? null;

// ── Download file to local storage, return local URL ──
function download_local(string $remotePath, string $jobId, string $localDir, string $localUrl): ?string {
    global $AI_BASE;
    $filename  = basename($remotePath);
    $safeJob   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $jobId);
    $jobDir    = $localDir . '/' . $safeJob;
    if (!is_dir($jobDir)) {
        mkdir($jobDir, 0775, true);
    }
    $localPath = $jobDir . '/' . $filename;
    // Already downloaded
    if (file_exists($localPath) && filesize($localPath) > 0) {
        return $localUrl . '/' . $safeJob . '/' . $filename;
    }
    $remoteUrl = $AI_BASE . '/showcase/' . $filename;
    $ch = curl_init($remoteUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $content  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && $content && strlen($content) > 100) {
        file_put_contents($localPath, $content);
        return $localUrl . '/' . $safeJob . '/' . $filename;
    }
    return null;
}

$resultUrl   = null;
$resultType  = null;
$splitPreview = null;

if ($status === 'done') {
    // Find main result output
    foreach ($outputs as $out) {
        if (substr($out, -4) === '.mp4') {
            $resultUrl  = download_local($out, $jobId, $LOCAL_DIR, $LOCAL_URL);
            $resultType = 'video';
            break;
        }
    }
    if (!$resultUrl) {
        foreach ($outputs as $out) {
            if (preg_match('/\.(jpg|jpeg|png|webp)$/i', $out)) {
                $resultUrl  = download_local($out, $jobId, $LOCAL_DIR, $LOCAL_URL);
                $resultType = 'image';
                break;
            }
        }
    }
    // Split preview ignored intentionally

    // Update DB if we got local URLs
    if ($resultUrl) {
            $conn = db_connect();
        $genSec = isset($data['total_sec']) ? (float)$data['total_sec'] : null;
        $stmt = $conn->prepare("UPDATE user_generations SET status = 'done', result_url = ?, generation_sec = ?, completed_at = NOW() WHERE job_id = ?");
        $stmt->bind_param('sds', $resultUrl, $genSec, $jobId);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
    // Fallback: AI server may have cleaned up outputs
    if (!$resultUrl) {
            $conn2 = db_connect();
        $safe  = $conn2->real_escape_string($jobId);
        $res2  = $conn2->query("SELECT result_url FROM user_generations WHERE job_id = '$safe' AND status = 'done' LIMIT 1");
        $row2  = $res2 ? $res2->fetch_assoc() : null;
        if ($row2 && $row2['result_url']) {
            $resultUrl  = $row2['result_url'];
            $ext        = strtolower(pathinfo($resultUrl, PATHINFO_EXTENSION));
            $resultType = ($ext === 'mp4') ? 'video' : 'image';
        }
        $conn2->close();
    }
}

// упало на стороне GPU — закрываем и возвращаем списанное
if (in_array($status, ['error', 'failed', 'cancelled'], true)) {
    gen_refund_once($jobId);
}

echo json_encode([
    'status'        => $status,
    'result_url'    => $resultUrl,
    'result_type'   => $resultType,
    'split_preview' => null,
    'generation_sec'=> $data['generation_sec'] ?? null,
    'error'         => $data['error'] ?? null,
]);
