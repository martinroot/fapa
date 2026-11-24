<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

require_once __DIR__ . '/../cmd/config.php';

define('TG_NOTIFY_BOT', getenv('TG_NOTIFY_BOT') ?: '');
define('TG_NOTIFY_CHAT', '-1003717235096');

// Пересборка демо-заготовок дёргает этот же эндпоинт десятки раз подряд.
// Свой заголовок с ключом помечает такой прогон служебным, чтобы он не
// засыпал рабочий чат уведомлениями о каждой генерации.
define('DEMO_BUILD_KEY', getenv('DEMO_BUILD_KEY') ?: '');
define('IS_DEMO_BUILD', (($_SERVER['HTTP_X_DEMO_BUILD'] ?? '') === DEMO_BUILD_KEY));

if (!function_exists("tg_gen_notify")) {
function tg_gen_notify(array $d): void {
    if (IS_DEMO_BUILD) return;
    $s = $d['status'] ?? 'ok';
    $icon = $s === 'ok' ? '✅' : ($s === 'rejected' ? '🚫' : ($s === 'demo' ? '🎭' : '❌'));
    $lines  = [$icon . ' <b>' . htmlspecialchars($d['template_id'] ?? '—') . '</b>  [' . htmlspecialchars($d['source'] ?? 'web') . ']'];
    if (!empty($d['model']))   $lines[] = '🤖 ' . htmlspecialchars($d['model']);
    if (!empty($d['prompt']))  $lines[] = '📝 ' . htmlspecialchars(mb_substr($d['prompt'], 0, 200));
    if (($d['age'] ?? 0) > 0) $lines[] = '👶 Age: ' . (int)$d['age'];
    if (!empty($d['ip']))      $lines[] = '👤 ' . htmlspecialchars($d['ip']);
    if (!empty($d['uiq']))     $lines[] = '🔑 uiq=' . htmlspecialchars(substr($d['uiq'], 0, 16));
    if (!empty($d['elapsed'])) $lines[] = '⏱ ' . htmlspecialchars($d['elapsed']);
    if (!empty($d['error']))   $lines[] = '💬 ' . htmlspecialchars($d['error']);
    $text = implode("
", $lines);
    $ch = curl_init('https://api.telegram.org/bot' . TG_NOTIFY_BOT . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5,
        CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>TG_NOTIFY_CHAT,'text'=>$text,'parse_mode'=>'HTML']),
    ]);
    curl_exec($ch); curl_close($ch);
}
}

if (!function_exists("tg_notify_error")) {
function tg_notify_error(string $msg): void {
    tg_gen_notify(['status'=>'error','error'=>$msg,'template_id'=>'?','source'=>'web']);
}
}

// ── Карточки портала — единственный источник промптов ────────────────────
// Промпт и модель для любого шаблона берём из showcase.json, а не из того,
// что прислал браузер, и не из зашитых констант: иначе правка шаблона в
// портале до сайта не доезжает.
define('UNDRESS_TPL_ID', 'undress-demo-001');  // карточка дефолтного undress

function portal_items(): array {
    static $items = null;
    if ($items === null) {
        $f = __DIR__ . '/../gpu-portal/showcase.json';
        $items = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    return $items;
}

function portal_item(string $id): ?array {
    if ($id === '') return null;
    foreach (portal_items() as $it) {
        if ((string)($it['id'] ?? '') === $id) return $it;
    }
    return null;
}

// Карточка дефолтного undress: по id из константы, иначе первый img2img-шаблон
// с template_id=undress — чтобы флоу не осиротел, если карточку пересоздадут.
function portal_undress_item(): ?array {
    $it = portal_item(UNDRESS_TPL_ID);
    if ($it) return $it;
    foreach (portal_items() as $c) {
        if (($c['template_id'] ?? '') === 'undress' && ($c['type'] ?? '') === 'img2img') return $c;
    }
    return null;
}

// Порядок полей повторяет редактор портала (gpu-portal/index.html): для dual
// админ правит prompt, для остальных image_prompt_positive. Пустая строка не
// значение, поэтому ?: , а не ?? — иначе в HotAPI уходит пустой промпт.
function portal_prompt(array $it): array {
    $pos = trim((string)($it['image_prompt_positive'] ?? ''));
    $alt = trim((string)($it['prompt'] ?? ''));
    if (($it['type'] ?? '') === 'dual') { [$pos, $alt] = [$alt, $pos]; }
    return [
        'pos'   => $pos !== '' ? $pos : $alt,
        'neg'   => trim((string)($it['image_prompt_negative'] ?? '')),
        'model' => trim((string)($it['img_model'] ?? '')) ?: trim((string)($it['model'] ?? '')),
        'flow'  => trim((string)($it['flow'] ?? '')),
    ];
}


define('XAI_KEY_MOD', getenv('XAI_KEY_MOD') ?: '');

// ── HotAPI: внешняя uncensored-модель для генераций каталога ─────────────
define('HOTAPI_KEY', getenv('HOTAPI_KEY') ?: '');
define('HOTAPI_BASE',       'https://api.hotapi.ai/v1');
define('HOTAPI_MODEL',      'qwen-image-edit-spicy');   // $0.08/изображение
define('HOTAPI_DAILY_FREE', 5);                          // бесплатных генераций в сутки

/**
 * Полный цикл HotAPI: upload → task → poll → URL результата на CDN.
 * CDN-ссылка живёт 24ч, поэтому вызывающий сразу зеркалит файл к себе.
 * @return array{ok:bool, task_id:string, asset_url:string, error:string}
 */
function hotapi_generate(string $imgPath, string $prompt): array {
    $hdr = ['Authorization: Bearer ' . HOTAPI_KEY];
    // HotAPI принимает только JPEG/PNG/WebP ≤10MB. Всё прочее (HEIC, GIF, битые
    // файлы) конвертируем в JPEG через GD — иначе upload падает 400 и юзер
    // видит «Connection error».
    $mime = mime_content_type($imgPath) ?: '';
    $ok   = ['image/jpeg', 'image/png', 'image/webp'];
    if ($mime === '' || !in_array($mime, $ok, true) || filesize($imgPath) > 10 * 1024 * 1024) {
        $img = null;
        try {
            if (function_exists('imagecreatefromstring')) {
                $src = @imagecreatefromstring((string)file_get_contents($imgPath));
                if ($src !== false) {
                    $img = imagecreatetruecolor(imagesx($src), imagesy($src));
                    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
                    imagecopy($img, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
                    if ($mime === 'image/png' || $mime === 'image/webp') { /* фон уже белый */ }
                    imagejpeg($img, $imgPath, 92);
                    imagedestroy($src); imagedestroy($img);
                    clearstatcache(true, $imgPath);
                    $mime = 'image/jpeg';
                }
            }
        } catch (Throwable $e) { /* ниже отдадим ошибку */ }
    }
    if ($mime === '' || !in_array($mime, $ok, true) || filesize($imgPath) > 10 * 1024 * 1024) {
        return ['ok' => false, 'task_id' => '', 'asset_url' => '', 'error' => 'unsupported image format (need JPEG/PNG/WebP ≤10MB)'];
    }
    // 1. upload исходника (edit-модель принимает только public URL)
    $ch = curl_init(HOTAPI_BASE . '/uploads');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($imgPath, $mime ?: 'image/jpeg', 'photo.jpg')],
    ]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $up = json_decode((string)$resp, true);
    if (empty($up['url'])) {
        return ['ok' => false, 'task_id' => '', 'asset_url' => '', 'error' => "hotapi upload http=$http " . substr((string)$resp, 0, 200)];
    }
    // 2. задача на edit
    $ch = curl_init(HOTAPI_BASE . '/' . HOTAPI_MODEL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array_merge($hdr, ['Content-Type: application/json']),
        CURLOPT_POSTFIELDS => json_encode(['image' => $up['url'], 'prompt' => $prompt]),
    ]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $task = json_decode((string)$resp, true);
    $taskId = (string)($task['id'] ?? '');
    if ($taskId === '') {
        return ['ok' => false, 'task_id' => '', 'asset_url' => '', 'error' => "hotapi task http=$http " . substr((string)$resp, 0, 200)];
    }
    // 3. poll до терминального статуса. Короткий цикл по умолчанию (~90с):
    // если задача не успела, вернём task_id — клиент перейдёт в polling.
    for ($i = 0; $i < ($GLOBALS["hotapi_poll_max"] ?? 22); $i++) {
        sleep(4);
        $ch = curl_init(HOTAPI_BASE . '/tasks/' . urlencode($taskId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $hdr]);
        $resp = curl_exec($ch); curl_close($ch);
        $st = json_decode((string)$resp, true);
        $status = (string)($st['status'] ?? '');
        if ($status === 'succeeded') {
            $out = $st['output'] ?? [];
            $url = '';
            foreach (($out['assets'] ?? []) as $a) { if (!empty($a['url'])) { $url = (string)$a['url']; break; } }
            if ($url === '' && !empty($out['url']))       $url = (string)$out['url'];
            if ($url === '' && !empty($out['image_url'])) $url = (string)$out['image_url'];
            if ($url === '') return ['ok' => false, 'task_id' => $taskId, 'asset_url' => '', 'error' => 'hotapi succeeded without asset: ' . substr(json_encode($out), 0, 200)];
            return ['ok' => true, 'task_id' => $taskId, 'asset_url' => $url, 'error' => ''];
        }
        if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
            $em = (string)($st['error']['message'] ?? $status);
            return ['ok' => false, 'task_id' => $taskId, 'asset_url' => '', 'error' => "hotapi $status: $em"];
        }
    }
    return ['ok' => false, 'task_id' => $taskId, 'asset_url' => '', 'error' => 'hotapi poll timeout'];
}


function moderateAge(string $photoPath): int {
    $b64 = base64_encode((string)file_get_contents($photoPath));
    $payload = json_encode([
        'model'      => 'grok-4.20-0309-non-reasoning',
        'max_tokens' => 5,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64]],
                ['type' => 'text', 'text' => 'Minimum age of people in this photo? Reply with single integer only. 0 if no people visible.'],
            ],
        ]],
    ]);
    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . XAI_KEY_MOD, 'Content-Type: application/json'],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    $text = trim($data['choices'][0]['message']['content'] ?? '0');
    return (int)preg_replace('/\D/', '', $text);
}

$AI_BASE_LOCAL = 'http://127.0.0.1:8899';
$AI_BASE_OLD   = 'http://146.103.41.33:8899';
$AI_USER       = 'admin';
$AI_PASS       = (getenv('GPU_PORTAL_PASS') ?: '');
$TOKEN_FILE_LOCAL = '/var/www/core-vpn.click/tmp/fapa_ai_token_local.json';
$TOKEN_FILE_OLD   = '/tmp/fapa_ai_token.json';

function ai_get_token(string $base, string $user, string $pass, string $tokenFile): string
{
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
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data  = json_decode($resp ?: '', true);
    $token = $data['token'] ?? '';
    if ($token) {
        file_put_contents($tokenFile, json_encode(['token' => $token, 'expires_at' => time() + 82800]));
    }
    return $token;
}

$_t0 = microtime(true);
$templateId = trim((string)($_POST['template_id'] ?? 'undress'));
// Allow fixed templates + any showcase ID (gen_*, clone_*, or alphanumeric)
$fixedTemplates = ['undress', 'flux2-img2img', 'flux2-wan22-i2v-foley', 'dual'];
if (!in_array($templateId, $fixedTemplates, true) && !preg_match('/^[a-zA-Z0-9_\-]{3,64}$/', $templateId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown template']);
    exit;
}
// Map unknown showcase IDs that aren't video/dual to flux2-img2img for the legacy path
// (the actual template_id is forwarded to GPU portal)
if (!in_array($templateId, $fixedTemplates, true)) {
    // showcase template — will be sent as-is to /api/showcase/generate
}

// Видео-генерации отключены: самая дорогая операция и по GPU, и по времени.
// Вернуть = убрать этот блок.
$_videoBanned = ($templateId === 'flux2-wan22-i2v-foley');
if (!$_videoBanned) {
    $_scPath = __DIR__ . '/../gpu-portal/showcase.json';
    if (!in_array($templateId, ['undress', 'dual', 'flux2-img2img'], true) && is_file($_scPath)) {
        foreach ((json_decode((string)file_get_contents($_scPath), true) ?: []) as $_scIt) {
            if (($_scIt['id'] ?? '') === $templateId) {
                $_videoBanned = (($_scIt['type'] ?? '') === 'video') || !empty($_scIt['vid_model']);
                break;
            }
        }
    }
}
if ($_videoBanned) {
    http_response_code(403);
    echo json_encode(['error' => 'video_disabled', 'message' => 'Video generation is temporarily unavailable']);
    exit;
}

if (empty($_FILES['image']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Image required']);
    exit;
}

// client_fingerprint() и client_ip() живут в cmd/config.php — они нужны
// и генерации, и регистрации, иначе отпечатки считаются по-разному.
$uiq      = $_COOKIE['uiq'] ?? '';
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$clientIp = explode(',', $clientIp)[0];
$clientIp   = trim($clientIp);
$clientHint = substr(preg_replace('/[^a-zA-Z0-9x:,\-\.]/', '', (string)($_POST['fpc'] ?? '')), 0, 120);
$clientFp   = client_fingerprint($clientIp, $clientHint);

// Блок-лист проверяем раньше всего: заблокированный не должен тратить ни GPU,
// ни платный вызов модерации, ни даже заготовку из кэша.
try {
    $_bc = db_connect();
    // id аккаунта берём из куки: $siteUser поднимается из базы позже
    $_blockUid = isset($_COOKIE['fapa_uid']) ? (int)$_COOKIE['fapa_uid'] : 0;
    // сверяем оба варианта отпечатка: на регистрации подсказки клиента нет
    if (client_blocked($_bc, $clientIp, (string)$uiq, $clientFp, $_blockUid, client_fingerprint($clientIp, ''))) {
        $_bc->close();
        http_response_code(403);
        echo json_encode(['error' => 'blocked', 'message' => 'Generation is not available for this device.']);
        exit;
    }
    $_bc->close();
} catch (Throwable $_e) { /* недоступность блок-листа не ломает генерацию */ }

// Заготовки проверяем ДО модерации возраста: демо-фото наше собственное, а
// moderateAge — платный вызов Grok. Раньше он уходил на каждую кэш-выдачу,
// то есть мы платили за картинку, которая и так лежит готовой.
// Demo image detection — return cached result instead of real generation
define('DEMO_IMG_MD5', '060ced49e4d1c546e7724e86ade7f9d8');
define('DEMO_RESULT_URL', 'https://hotbabestube.ru/showcase/demo_result_new.jpg');
$_uploadedMd5 = md5_file($_FILES['image']['tmp_name']);
// Заготовка — только для базового шаблона на дефолтной странице.
// Если юзер выбрал шаблон из каталога, он уже «живой»: отдавать ему чужую
// картинку от Undress нельзя, идём в настоящую генерацию.
// Для каждого шаблона заранее прогнали демо-фото и сохранили результат.
// Значит мгновенную выдачу можно давать на любом шаблоне — юзер увидит
// картинку именно своего шаблона, а GPU не тратится.
$_demoUrl = DEMO_RESULT_URL;
$_demoAllowed = in_array($templateId, ['undress', 'flux2-img2img'], true);
$_cacheFile = __DIR__ . '/../gpu-portal/demo_cache.json';
if (!$_demoAllowed && is_file($_cacheFile)) {
    $_cacheMap = json_decode((string)file_get_contents($_cacheFile), true) ?: [];
    $_key = ($templateId === 'dual') ? (string)($_POST['showcase_id'] ?? '') : $templateId;
    if ($_key !== '' && !empty($_cacheMap[$_key])) {
        $_demoAllowed = true;
        $_demoUrl = 'https://hotbabestube.ru' . $_cacheMap[$_key];
    }
}
// Служебная пересборка заготовок шлёт то же демо-фото и должна получить
// НАСТОЯЩУЮ генерацию, иначе просто перезапишет кэш самим собой.
// Гостю заготовка полагается один раз: дальше пусть идёт настоящая генерация.
// Аккаунт опознаём по куке fapa_uid — $siteUser здесь ещё не поднят из базы.
$_isGuest    = empty($_COOKIE['fapa_uid']);
$_cachedUsed = max(0, (int)($_COOKIE['fapa_cached'] ?? 0));
$_cachedCap  = defined('ANON_FREE_CACHED') ? (int)ANON_FREE_CACHED : 1;
// Гость свой кэш-показ уже получил. Гнать то же самое демо-фото на GPU нельзя:
// это оплаченная генерация ради картинки, которая и так лежит в заготовках.
// Настоящая генерация ждёт его со СВОИМ фото — об этом и говорим странице.
if ($_isGuest && $_cachedUsed >= $_cachedCap && $_uploadedMd5 === DEMO_IMG_MD5 && !IS_DEMO_BUILD) {
    http_response_code(403);
    echo json_encode([
        'error'   => 'demo_spent',
        'message' => 'Upload your own photo — one free generation is waiting',
    ]);
    exit;
}

if ($_demoAllowed && $_uploadedMd5 === DEMO_IMG_MD5 && !IS_DEMO_BUILD) {
    if ($_isGuest) {
        // кука не httponly: клиентская демо-ветка на промо считает по ней же
        setcookie('fapa_cached', (string)($_cachedUsed + 1), time() + 31536000, '/', '', false, false);
    }
    tg_gen_notify(['status'=>'demo','template_id'=>$templateId,'ip'=>$clientIp,'uiq'=>$uiq,'age'=>0,'elapsed'=>'demo','source'=>'web','error'=>'Demo image — returning cached result']);
    sleep(5); // fake processing delay
    // часть заготовок — видео (шаблоны с vid_model), тип определяем по файлу,
    // иначе страница попытается показать mp4 картинкой
    $_demoExt  = strtolower(pathinfo(parse_url($_demoUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $_demoType = in_array($_demoExt, ['mp4', 'webm', 'mov'], true) ? 'video' : 'image';
    // cached: результат взят из заготовок, генерации не было — важно для учёта
    echo json_encode(['ok' => true, 'status' => 'done', 'cached' => true, 'result_url' => $_demoUrl, 'result_type' => $_demoType]);
    exit;
}


// Гостю живая генерация не полагается: отказываем ДО модерации, иначе на каждую
// попытку анонима уходит платный вызов Grok за фото, которое всё равно отклоним.
// Заготовка из кэша сюда не доходит — она отдаётся выше и остаётся бесплатной.
$_anonStart = defined('ANON_START_TOKENS') ? (int)ANON_START_TOKENS : 0;
if (empty($_COOKIE['fapa_uid']) && $_anonStart <= 0) {
    $_paidAnon = false;
    if ($uiq !== '') {
        try {
            $_pc = db_connect();
            $_pe = $_pc->real_escape_string($uiq);
            $_pr = $_pc->query("SELECT 1 FROM anon_paid_sessions WHERE uiq='$_pe' AND paid_until > NOW() LIMIT 1");
            $_paidAnon = (bool)($_pr && $_pr->num_rows);
            $_pc->close();
        } catch (Throwable $_e) { $_paidAnon = false; }
    }
    if (!$_paidAnon) {
        // Дневной лимит: 5 генераций в сутки на гостя (по uiq) — как у юзеров
        $_cnt = 0;
        if ($uiq !== '') {
            $_pc = db_connect();
            $_pe = $_pc->real_escape_string($uiq);
            $_pr = $_pc->query("SELECT COUNT(*) c FROM user_generations WHERE uiq='$_pe' AND site_user_id IS NULL AND created_at > NOW() - INTERVAL 1 DAY");
            $_cnt = (int)($_pr->fetch_assoc()['c'] ?? 0);
            $_pc->close();
        }
        if ($_cnt >= (defined('HOTAPI_DAILY_FREE') ? HOTAPI_DAILY_FREE : 5)) {
            http_response_code(429);
            echo json_encode([
                'error'   => 'daily_limit',
                'message' => 'Daily limit reached — 5 free generations per day. Come back tomorrow or sign up.',
                'used'    => $_cnt,
            ]);
            exit;
        }
        // лимит не исчерпан — гость идёт дальше, потолки по IP/устройству ниже
    }
}

try {
    $_detectedAge = moderateAge($_FILES['image']['tmp_name']);
} catch (Throwable $_e) {
    $_detectedAge = 0;
}
if ($_detectedAge > 0 && $_detectedAge < 16) {
    // Порог не ослабляем. Но отказов много и часть выглядит ложной (возраст 2
    // на взрослом фото), поэтому копим след для разбора: возраст, шаблон, md5.
    @file_put_contents('/var/log/fapa-age-reject.log', sprintf(
        "%s age=%d tpl=%s uiq=%s md5=%s\n",
        date('Y-m-d H:i:s'), $_detectedAge, $templateId, substr((string)$uiq, 0, 12), $_uploadedMd5
    ), FILE_APPEND);
    tg_gen_notify(['status'=>'rejected','template_id'=>$templateId,'ip'=>$clientIp,'uiq'=>$uiq,'age'=>$_detectedAge,'elapsed'=>round(microtime(true)-$_t0,1).'s','source'=>'web']);
    http_response_code(403);
    echo json_encode(['error' => 'age_moderation', 'age' => $_detectedAge, 'message' => 'Photo contains a person under 16']);
    exit;
}

$conn     = db_connect();
ensure_site_schema($conn);

// Look up tracker click_id for this uiq (fapaio.clicks table)
$clickId = '';
if ($uiq) {
    $uiqEsc = $conn->real_escape_string($uiq);
    $clRes  = $conn->query("SELECT click_id FROM clicks WHERE uiq='$uiqEsc' ORDER BY ts DESC LIMIT 1");
    if ($clRes && $clRow = $clRes->fetch_assoc()) {
        $clickId = (string)($clRow['click_id'] ?? '');
        $clRes->free();
    }
}
$siteUser = get_site_user($conn);

// ── HotAPI-режим: определяем заранее, до списания токенов ────────────────
// Каталог генераций (undress + карточки img2img) уходит во внешнюю модель
// HotAPI; локальный GPU остаётся для dual и фиксированных flux2-*.
$showcaseTemplate = null;
if (!in_array($templateId, ['undress', 'dual', 'flux2-img2img', 'flux2-wan22-i2v-foley'], true)) {
    $scFile = __DIR__ . '/../gpu-portal/showcase.json';
    if (file_exists($scFile)) {
        $scAll = json_decode((string)file_get_contents($scFile), true) ?: [];
        foreach ($scAll as $scItem) {
            if (($scItem['id'] ?? '') === $templateId) { $showcaseTemplate = $scItem; break; }
        }
    }
}
$useHot = ($templateId === 'undress') || ($showcaseTemplate && ($showcaseTemplate['type'] ?? '') === 'img2img');


// ── Токены — единый гейт и для анонима, и для зарегистрированного ────────
// Списываем сразу и атомарно (защита от гонки), при сбое возвращаем.
$__isVideoGen  = ($templateId === 'flux2-wan22-i2v-foley');
$__genCost     = $__isVideoGen
    ? (defined('GEN_TOKEN_COST_VIDEO') ? (int)GEN_TOKEN_COST_VIDEO : 1000)
    : (defined('GEN_TOKEN_COST') ? (int)GEN_TOKEN_COST : 300);
$__startTokens = defined('ANON_START_TOKENS') ? (int)ANON_START_TOKENS : 300;  // квота гостя, у аккаунтов баланс в базе
$__chargedUid  = 0;
$__anonPrevSpent = null;  // для отката куки анонима при сбое

/** След неудачной генерации: без него причина обрыва не восстанавливается. */
function gen_fail_log(string $stage, string $tpl, $http, string $uiq, $resp = ''): void
{
    @file_put_contents('/var/log/fapa-gen-errors.log', sprintf(
        "%s stage=%s tpl=%s http=%s uiq=%s resp=%s\n",
        date('Y-m-d H:i:s'), $stage, $tpl, (string)$http, substr($uiq, 0, 12), substr((string)$resp, 0, 400)
    ), FILE_APPEND);
}

function token_refund(mysqli $conn, int $uid, int $amount): void {
    global $__anonPrevSpent;
    if ($__anonPrevSpent !== null && !headers_sent()) {
        setcookie('fapa_spent', (string)$__anonPrevSpent, time() + 31536000, '/', '', false, true);
        $__anonPrevSpent = null;
    }
    if ($uid <= 0 || $amount <= 0) return;
    if ($st = $conn->prepare('UPDATE site_users SET tokens = COALESCE(tokens,0) + ? WHERE id = ?')) {
        $st->bind_param('ii', $amount, $uid);
        $st->execute();
        $st->close();
    }
}


if (!$siteUser) {
    // Check if this anon uiq has paid via crypto
    $anonPaid = false;
    if ($uiq) {
        $conn->query("CREATE TABLE IF NOT EXISTS anon_paid_sessions (uiq VARCHAR(64) NOT NULL PRIMARY KEY, paid_until DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $uiqEsc = $conn->real_escape_string($uiq);
        $apr = $conn->query("SELECT paid_until FROM anon_paid_sessions WHERE uiq='$uiqEsc' AND paid_until > NOW() LIMIT 1");
        $anonPaid = ($apr && $apr->num_rows > 0);
    }
    if (!$anonPaid) {
        // Баланс анонима: 600 стартовых токенов = 2 генерации, счёт в куке.
        // Демо-генерацию списывает api/tokens_spend.php — она часть тех же 600.
        // Раньше здесь считались генерации по uiq ИЛИ IP ИЛИ cid: инкогнито
        // сбрасывает куки, но не IP, и юзера выбрасывало на регистрацию сразу.
        // Потолки за сутки: по IP и по отпечатку устройства.
        // Ловят цикл «две генерации — чистка кук — ещё две».
        $capIp = defined('FREE_GEN_PER_IP_DAY') ? (int)FREE_GEN_PER_IP_DAY : 0;
        $capFp = defined('FREE_GEN_PER_FP_DAY') ? (int)FREE_GEN_PER_FP_DAY : 0;
        foreach ([['ip', $clientIp, $capIp], ['fp', $clientFp, $capFp]] as [$col, $val, $cap]) {
            if ($cap <= 0 || $val === '') continue;
            $q = $conn->prepare("SELECT COUNT(*) c FROM user_generations
                                 WHERE $col = ? AND site_user_id IS NULL
                                   AND created_at > NOW() - INTERVAL 1 DAY");
            $q->bind_param('s', $val);
            $q->execute();
            $used = (int)($q->get_result()->fetch_assoc()['c'] ?? 0);
            $q->close();
            if ($used >= $cap) {
                $conn->close();
                http_response_code(403);
                echo json_encode([
                    'error'   => 'limit',
                    'message' => 'Free limit reached for today — sign up to continue',
                    'reason'  => $col === 'ip' ? 'ip_daily' : 'device_daily',
                ]);
                exit;
            }
        }

        if (!$useHot) {
            // Токеновая модель — только для локальных путей (dual/flux2-*):
            // каталог HotAPI бесплатен в пределах дневного лимита.
            $spent   = max(0, (int)($_COOKIE['fapa_spent'] ?? 0));
            $balance = max(0, $__startTokens - $__genCost * $spent);
            if ($balance < $__genCost) {
                $conn->close();
                http_response_code(403);
                echo json_encode([
                    'error'   => 'limit',
                    'message' => 'Sign up to get more tokens',
                    'cost'    => $__genCost,
                    'tokens'  => $balance,
                ]);
                exit;
            }
            $__anonPrevSpent = $spent;
            setcookie('fapa_spent', (string)($spent + 1), time() + 31536000, '/', '', false, true);
        }
    }
}

// зарегистрированный: списываем с баланса в базе
if ($siteUser) {
    $paid_until = $siteUser['paid_until'] ?? '';
    // Безлимит только за настоящую покупку. Бесплатный триал (is_trial=1)
    // раньше проходил здесь же и давал генерации даром — это и была дыра.
    $isPaid = $paid_until && strtotime($paid_until) > time() && empty($siteUser['is_trial']);
    if (!$isPaid) {
        $siteUser['tokens'] = daily_refill_tokens($conn, (int)$siteUser['id']); // 5 генераций в сутки
        $uid = (int)$siteUser['id'];
        $chSt = $conn->prepare('UPDATE site_users SET tokens = tokens - ? WHERE id = ? AND tokens >= ?');
        $chSt->bind_param('iii', $__genCost, $uid, $__genCost);
        $chSt->execute();
        $charged = ($chSt->affected_rows === 1);
        $chSt->close();
        if (!$charged) {
            $conn->close();
            http_response_code(403);
            echo json_encode([
                'error'   => 'paywall',
                'message' => 'Not enough tokens',
                'cost'    => $__genCost,
                'tokens'  => (int)($siteUser['tokens'] ?? 0),
            ]);
            exit;
        }
        $__chargedUid = $uid;
    }
}

// ── Квота: 5 генераций в сутки на пользователя каталога ─────────────────
// Считается по user_generations за 24ч: у аккаунта — по site_user_id,
// у гостя — по uiq. Отдельно от токенов: это дневной потолок каталога.
$hotUid = $siteUser ? (int)$siteUser['id'] : 0;
if ($hotUid > 0) {
    $qSt = $conn->prepare('SELECT COUNT(*) c FROM user_generations WHERE site_user_id = ? AND created_at > NOW() - INTERVAL 1 DAY');
    $qSt->bind_param('i', $hotUid);
} elseif ($uiq !== '') {
    $qSt = $conn->prepare("SELECT COUNT(*) c FROM user_generations WHERE uiq = ? AND site_user_id IS NULL AND created_at > NOW() - INTERVAL 1 DAY");
    $qSt->bind_param('s', $uiq);
} else {
    $qSt = null;
}
$hotUsed = 0;
if ($qSt) { $qSt->execute(); $hotUsed = (int)($qSt->get_result()->fetch_assoc()['c'] ?? 0); $qSt->close(); }
if ($hotUsed >= HOTAPI_DAILY_FREE) {
    $conn->close();
    http_response_code(429);
    echo json_encode([
        'error'   => 'daily_limit',
        'message' => 'Daily limit reached — 5 free generations per day. Come back tomorrow or upgrade.',
        'used'    => $hotUsed,
        'limit'   => HOTAPI_DAILY_FREE,
    ]);
    exit;
}

/** Зеркалим результат HotAPI к себе: CDN-ссылка живёт 24ч, а генерация — надолго. */
function hotapi_mirror(string $assetUrl, string $templateId): string {
    $dir  = __DIR__ . '/../gen_results/hotapi';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ext  = (str_contains($assetUrl, '.png')) ? 'png' : 'jpg';
    $name = 'hotapi-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $ch = curl_init($assetUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
    $bin = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http !== 200 || !is_string($bin) || $bin === '') return '';
    $fp = fopen("$dir/$name", 'wb');
    fwrite($fp, $bin); fclose($fp);
    @chmod("$dir/$name", 0664);
    return $name;
}


// ── HotAPI: исполнение для каталога (undress + img2img-карточки) ─────────
// Токены уже списаны, квота проверена. Промпт берём из карточки портала —
// то, что прислал браузер, не доверяем. Результат сразу зеркалим к себе:
// CDN HotAPI отдаёт файлы только 24ч.
if ($useHot) {
    $_hotP = ($_uItem ?? null) ? $_uItem : $showcaseTemplate;  // карточка шаблона
    if ($templateId === 'undress') {
        $_uItem  = portal_undress_item();
        $_hotP   = $_uItem;
        $_uPrompt = 'undress, nude';
        $_uModel  = HOTAPI_MODEL;
        if ($_uItem) {
            $_uP = portal_prompt($_uItem);
            if ($_uP['pos'] !== '')   $_uPrompt = $_uP['pos'];
        }
        $_hotPrompt = $_uPrompt;
    } else {
        $_tp = portal_prompt($showcaseTemplate);
        $_hotPrompt = $_tp['pos'] !== '' ? $_tp['pos'] : (string)($_POST['image_prompt_positive'] ?? '');
        if ($_hotPrompt === '') $_hotPrompt = 'enhance this photo';
    }

    // Короткий poll: укладываемся в ~60с, чтобы не поймать таймаут CF/браузера.
    // Не успело — отдаём job_id=hotapi:task_id, результат добирает polling.
    $GLOBALS['hotapi_poll_max'] = 0; // POST не ждёт генерацию: сразу queued, результат добирает polling.
    // Длинные запросы рвутся на слабых VPN-соединениях → у юзера «Connection error».
    $hot = hotapi_generate($_FILES['image']['tmp_name'], $_hotPrompt);
    if ($hot['ok']) { $GLOBALS['hot_done'] = $hot; }
    if (!$hot['ok'] && $hot['task_id'] !== '' && ($hot['error'] === 'hotapi poll timeout' || !empty($GLOBALS['hotapi_async']))) {
        // задача ещё в работе — отдаём «queued», юзер ждёт по /api/ai_status.php
        $jobId = 'hotapi:' . $hot['task_id'];
        $siteUserId = $siteUser ? (int)$siteUser['id'] : null;
        $stmt = $conn->prepare('INSERT INTO user_generations (site_user_id, uiq, ip, fp, cid, job_id, template_id, source_image) VALUES (?,?,?,?,?,?,?,?)');
        $srcRel = 'https://core-vpn.click/' . ($_FILES['image']['tmp_name'] ? '' : '');
        $stmt->bind_param('isssssss', $siteUserId, $uiq, $clientIp, $clientFp, $clickId, $jobId, $templateId, $srcRel);
        $stmt->execute(); $stmt->close();
        $conn->close();
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'queued']);
        exit;
    }
    if (!$hot['ok']) {
        tg_gen_notify(['status'=>'error','template_id'=>$templateId,'model'=>HOTAPI_MODEL,'ip'=>$clientIp,'uiq'=>$uiq,'age'=>$_detectedAge,'elapsed'=>round(microtime(true)-$_t0,1).'s','error'=>$hot['error'],'source'=>'web']);
        gen_fail_log('hotapi_failed', $templateId, '-', (string)$uiq, $hot['error']);
        $conn->close();
        token_refund($conn, $__chargedUid, $__genCost);
        http_response_code(502);
        echo json_encode(['error' => 'Generation failed', 'detail' => $hot['error']]);
        exit;
    }
    $mirrored = hotapi_mirror($hot['asset_url'], $templateId);
    $resultUrl = $mirrored !== ''
        ? 'https://core-vpn.click/gen_results/hotapi/' . $mirrored
        : $hot['asset_url'];   // зеркало не удалось — отдаём CDN на 24ч

    $jobId = 'hotapi-' . ($hot['task_id'] ?: ('t' . time()));
    $siteUserId = $siteUser ? (int)$siteUser['id'] : null;
    $stmt = $conn->prepare('INSERT INTO user_generations (site_user_id, uiq, ip, fp, cid, job_id, template_id, status, result_url, completed_at) VALUES (?,?,?,?,?,?,?,"done",?,NOW())');
    $stmt->bind_param('isssssss', $siteUserId, $uiq, $clientIp, $clientFp, $clickId, $jobId, $templateId, $resultUrl);
    $stmt->execute(); $stmt->close();
    $conn->close();

    tg_gen_notify(['status'=>'ok','template_id'=>$templateId,'model'=>HOTAPI_MODEL,'ip'=>$clientIp,'uiq'=>$uiq,'age'=>$_detectedAge,'elapsed'=>round(microtime(true)-$_t0,1).'s','source'=>'web']);
    echo json_encode(['ok' => true, 'status' => 'done', 'result_url' => $resultUrl, 'result_type' => 'image']);
    exit;
}

// ── undress: synchronous call to local GPU portal /api/generate ──────────
if ($templateId === 'undress') {
    $token = ai_get_token($AI_BASE_LOCAL, $AI_USER, $AI_PASS, $TOKEN_FILE_LOCAL);
    if (!$token) {
        gen_fail_log('no_token_undress', $templateId, '-', (string)$uiq);
        token_refund($conn, $__chargedUid, $__genCost);
        $conn->close();
        http_response_code(502);
        echo json_encode(['error' => 'GPU portal unavailable']);
        exit;
    }

    $imgFile = new CURLFile(
        $_FILES['image']['tmp_name'],
        $_FILES['image']['type'] ?: 'image/jpeg',
        'photo.jpg'
    );

    // Дефолтный undress тянет промпт и модель из своей карточки в портале —
    // той самой, чью пару «до/после» страница показывает в шапке.
    $_uItem   = portal_undress_item();
    $_uPrompt = 'undress, nude';            // фолбэк, если карточку удалили
    $_uModel  = 'qwen-image-edit-spicy';
    if ($_uItem) {
        $_uP = portal_prompt($_uItem);
        if ($_uP['pos'] !== '')   $_uPrompt = $_uP['pos'];
        if ($_uP['model'] !== '') $_uModel  = $_uP['model'];
    }

    $ch = curl_init("$AI_BASE_LOCAL/api/generate");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['model' => $_uModel, 'prompt' => $_uPrompt, 'image' => $imgFile],
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "X-Client-IP: $clientIp", "X-Client-UIQ: $uiq", "X-Template-ID: undress", "X-Click-ID: $clickId", "X-Age-Detected: $_detectedAge"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp ?: '', true);

    if (!$data || empty($data['url'])) {
        if ($httpCode === 401) @unlink($TOKEN_FILE_LOCAL);
        tg_gen_notify(['status'=>'error','template_id'=>'undress','model'=>$_uModel,'ip'=>$clientIp,'uiq'=>$uiq,'age'=>$_detectedAge,'elapsed'=>round(microtime(true)-$_t0,1).'s','error'=>"http={$httpCode} ".substr($resp??'',0,150),'source'=>'web']);
        gen_fail_log('undress_sync_failed', $templateId, $httpCode, (string)$uiq, $resp);
        $conn->close();
        token_refund($conn, $__chargedUid, $__genCost);
        http_response_code(502);
        echo json_encode(['error' => 'Generation failed', 'detail' => $resp]);
        exit;
    }

    $relUrl    = $data['url'];
    $resultUrl = 'https://hotbabestube.ru' . $relUrl;

    $jobId = 'sync-' . time() . '-' . bin2hex(random_bytes(4));
    $siteUserId = $siteUser ? (int)$siteUser['id'] : null;
    $stmt = $conn->prepare('INSERT INTO user_generations (site_user_id, uiq, ip, fp, cid, job_id, template_id, status, result_url, completed_at) VALUES (?,?,?,?,?,?,?,"done",?,NOW())');
    $stmt->bind_param('isssssss', $siteUserId, $uiq, $clientIp, $clientFp, $clickId, $jobId, $templateId, $resultUrl);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    tg_gen_notify(['status'=>'ok','template_id'=>'undress','model'=>$_uModel,'ip'=>$clientIp,'uiq'=>$uiq,'age'=>$_detectedAge,'elapsed'=>round(microtime(true)-$_t0,1).'s','source'=>'web']);
    echo json_encode(['ok' => true, 'status' => 'done', 'result_url' => $resultUrl, 'result_type' => 'image']);
    exit;
}

// ── Legacy: old worker via 146.103.41.33 ─────────────────────────────────
$token = ai_get_token($AI_BASE_LOCAL, $AI_USER, $AI_PASS, $TOKEN_FILE_LOCAL);
if (!$token) {
    gen_fail_log('no_token_main', $templateId, '-', (string)$uiq);
    $conn->close();
    http_response_code(502);
    echo json_encode(['error' => 'AI server unavailable']);
    exit;
}

// ── Showcase templates using local models (qwen-image-edit-spicy etc.) → /api/generate sync ──
$localModels = ['qwen-image-edit-spicy', 'qwen-image-edit'];
$showcaseTemplate = null;
if (!in_array($templateId, ['undress', 'dual', 'flux2-img2img', 'flux2-wan22-i2v-foley'], true)) {
    $scFile = __DIR__ . '/../gpu-portal/showcase.json';
    if (file_exists($scFile)) {
        $scAll = json_decode(file_get_contents($scFile), true) ?? [];
        foreach ($scAll as $scItem) {
            if (($scItem['id'] ?? '') === $templateId) { $showcaseTemplate = $scItem; break; }
        }
    }
}
if (false) { // HotAPI покрывает все showcase img2img — локальный путь отключён
    $_scP = portal_prompt($showcaseTemplate);
    $token = ai_get_token($AI_BASE_LOCAL, $AI_USER, $AI_PASS, $TOKEN_FILE_LOCAL);
    if (!$token) { gen_fail_log('no_token_showcase', $templateId, '-', (string)$uiq); token_refund($conn, $__chargedUid, $__genCost); $conn->close(); http_response_code(502); echo json_encode(['error' => 'GPU portal unavailable']); exit; }
    $imgFile = new CURLFile($_FILES['image']['tmp_name'], $_FILES['image']['type'] ?: 'image/jpeg', 'photo.jpg');
    $ch = curl_init("$AI_BASE_LOCAL/api/generate");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['model' => $_scP['model'] ?: (string)$showcaseTemplate['model'], 'prompt' => $_scP['pos'], 'image' => $imgFile],
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", "X-Client-IP: $clientIp", "X-Client-UIQ: $uiq", "X-Template-ID: $templateId", "X-Click-ID: $clickId", "X-Age-Detected: $_detectedAge"],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($resp ?: '', true);
    if (!$data || empty($data['url'])) {
        if ($httpCode === 401) @unlink($TOKEN_FILE_LOCAL);
        tg_gen_notify(['status'=>'error','template_id'=>$templateId,'model'=>$_scP['model'] ?: $showcaseTemplate['model'],'ip'=>$clientIp,'uiq'=>$uiq,'age'=>$_detectedAge,'elapsed'=>round(microtime(true)-$_t0,1).'s','error'=>"http={$httpCode} ".substr($resp??'',0,150),'source'=>'web']);
        token_refund($conn, $__chargedUid, $__genCost);
        gen_fail_log('showcase_sync_failed', $templateId, $httpCode, (string)$uiq, $resp);
        $conn->close(); http_response_code(502); echo json_encode(['error' => 'Generation failed', 'detail' => $resp]); exit;
    }
    $resultUrl = 'https://hotbabestube.ru' . $data['url'];
    $jobId = 'sync-' . time() . '-' . bin2hex(random_bytes(4));
    $siteUserId = $siteUser ? (int)$siteUser['id'] : null;
    $stmt = $conn->prepare('INSERT INTO user_generations (site_user_id, uiq, ip, fp, cid, job_id, template_id, status, result_url, completed_at) VALUES (?,?,?,?,?,?,?,"done",?,NOW())');
    $stmt->bind_param('isssssss', $siteUserId, $uiq, $clientIp, $clientFp, $clickId, $jobId, $templateId, $resultUrl);
    $stmt->execute(); $stmt->close(); $conn->close();
    tg_gen_notify(['status'=>'ok','template_id'=>$templateId,'model'=>$_scP['model'] ?: $showcaseTemplate['model'],'ip'=>$clientIp,'uiq'=>$uiq,'age'=>$_detectedAge,'elapsed'=>round(microtime(true)-$_t0,1).'s','source'=>'web']);
    echo json_encode(['ok' => true, 'status' => 'done', 'result_url' => $resultUrl, 'result_type' => 'image']);
    exit;
}

$endpoint = ($templateId === 'dual') ? "$AI_BASE_LOCAL/api/dual/generate" : "$AI_BASE_LOCAL/api/showcase/generate";
$postFields = ['image' => new CURLFile($_FILES['image']['tmp_name'], $_FILES['image']['type'], 'image.jpg')];

if ($templateId !== 'dual') {
    $postFields['template_id']           = $templateId;
    // Шаблон каталога: промпт, модель и flow — из карточки портала. То, что
    // прислал браузер, идёт в дело только для фиксированных шаблонов
    // (flux2-*), которых в каталоге нет.
    $_pos = ''; $_neg = ''; $_tplModel = ''; $_tplFlow = '';
    if ($showcaseTemplate) {
        $_tp       = portal_prompt($showcaseTemplate);
        $_pos      = $_tp['pos'];
        $_neg      = $_tp['neg'];
        $_tplModel = $_tp['model'];
        $_tplFlow  = $_tp['flow'];
    }
    if ($_pos === '') $_pos = (string)($_POST['image_prompt_positive'] ?? '');
    if ($_neg === '') $_neg = (string)($_POST['image_prompt_negative'] ?? '');
    if ($_neg === '') $_neg = 'different person, blurry, low quality';
    $postFields['image_prompt_positive'] = $_pos;
    $postFields['image_prompt_negative'] = $_neg;
    if ($showcaseTemplate) {
        // preset_id — по нему портал сшивает генерацию с шаблоном в статистике
        $postFields['preset_id'] = (string)($showcaseTemplate['id'] ?? '');
        if ($_tplModel !== '') $postFields['img_model'] = $_tplModel;
        if ($_tplFlow  !== '') $postFields['flow']      = $_tplFlow;
    }
    if ($templateId === 'flux2-wan22-i2v-foley') {
        $postFields['video_prompt_positive'] = (string)($_POST['video_prompt_positive'] ?? 'smooth camera movement, cinematic');
        $postFields['video_prompt_negative'] = (string)($_POST['video_prompt_negative'] ?? 'static, blurry, face morphing');
    }
} else {
    // dual: image1 = template's photo1, image2 = user's uploaded photo (same as portal)
    $showcaseId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['showcase_id'] ?? ''));
    $showcaseFile = __DIR__ . '/../gpu-portal/showcase.json';
    $tplImage1Path = null;
    if ($showcaseId && file_exists($showcaseFile)) {
        $showcase = json_decode(file_get_contents($showcaseFile), true) ?? [];
        foreach ($showcase as $item) {
            if (($item['id'] ?? '') === $showcaseId) {
                // image1 from template (kept as-is)
                $img1rel = $item['image1'] ?? $item['source_image'] ?? '';
                if ($img1rel && str_starts_with($img1rel, '/showcase/')) {
                    $tplImage1Path = __DIR__ . '/../gpu-portal/showcase/' . basename($img1rel);
                } elseif ($img1rel && preg_match('#(?:/gpu)?/showcase/([^?]+)$#', $img1rel, $m)) {
                    $tplImage1Path = __DIR__ . '/../gpu-portal/showcase/' . $m[1];
                } elseif ($img1rel && filter_var($img1rel, FILTER_VALIDATE_URL)) {
                    $tplImage1Path = __DIR__ . '/../gpu-portal/showcase/' . basename(parse_url($img1rel, PHP_URL_PATH));
                }
                // страница шлёт промпт, отрендеренный в HTML: у открытой
                // вкладки он мог устареть — карточка портала главнее
                $_dualP = portal_prompt($item);
                $postFields['prompt'] = $_dualP['pos'] !== ''
                    ? $_dualP['pos']
                    : (string)($_POST['prompt'] ?? '');
                $postFields['preset_id'] = $showcaseId;
                break;
            }
        }
    }
    if (!isset($postFields['prompt'])) {
        $postFields['prompt'] = (string)($_POST['prompt'] ?? '');
    }
    // image1 = template photo1, image2 = user's photo (replaces template photo2)
    $userPhoto = $postFields['image'];
    unset($postFields['image']);
    if ($tplImage1Path && file_exists($tplImage1Path)) {
        $mime = mime_content_type($tplImage1Path) ?: 'image/jpeg';
        $postFields['image1'] = new CURLFile($tplImage1Path, $mime, 'photo1.jpg');
    }
    $postFields['image2'] = $userPhoto;
}

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer $token",
        "X-Client-IP: $clientIp",
        "X-Client-UIQ: $uiq",
        "X-Template-ID: $templateId",
        "X-Click-ID: $clickId",
        "X-Age-Detected: $_detectedAge",
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data  = json_decode($resp ?: '', true);
$jobId = $data['job_id'] ?? '';

if (!$jobId) {
    if ($httpCode === 401) @unlink($TOKEN_FILE_OLD);
    // Debug log
    gen_fail_log('no_job_id', $templateId, $httpCode, (string)$uiq, $resp);
    $hasImg2 = !empty($_FILES['image2']['tmp_name']) ? 'yes' : 'NO';
    tg_notify_error("tpl={$templateId} http={$httpCode} img2={$hasImg2} resp=" . substr($resp ?? '', 0, 250));
    $conn->close();
    token_refund($conn, $__chargedUid, $__genCost);
    http_response_code(502);
    echo json_encode(['error' => 'AI server error', 'detail' => $resp]);
    exit;
}

$LOCAL_DIR = __DIR__ . '/../gen_results';
$LOCAL_URL = 'https://hotbabestube.ru/gen_results';
$safeJob   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $jobId);
$jobDir    = $LOCAL_DIR . '/' . $safeJob;
if (!is_dir($jobDir)) mkdir($jobDir, 0775, true);

$srcFile = $_FILES['image']['tmp_name'] ?? '';
if (!$srcFile && !empty($_FILES['image1']['tmp_name'])) $srcFile = $_FILES['image1']['tmp_name'];
$sourceImage = null;
if ($srcFile) {
    $mime = $_FILES['image']['type'] ?? ($_FILES['image1']['type'] ?? '');
    $ext  = str_contains($mime, 'png') ? 'png' : (str_contains($mime, 'webp') ? 'webp' : 'jpg');
    $destPath = $jobDir . '/source.' . $ext;
    move_uploaded_file($srcFile, $destPath);
    $sourceImage = $LOCAL_URL . '/' . $safeJob . '/source.' . $ext;
}

$siteUserId = $siteUser ? (int)$siteUser['id'] : null;
$stmt = $conn->prepare('INSERT INTO user_generations (site_user_id, uiq, ip, fp, cid, job_id, template_id, source_image) VALUES (?,?,?,?,?,?,?,?)');
$stmt->bind_param('isssssss', $siteUserId, $uiq, $clientIp, $clientFp, $clickId, $jobId, $templateId, $sourceImage);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'queued']);
