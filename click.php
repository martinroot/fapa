<?php
// Включаем отображение всех ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// click.php — вставка клика из GET-параметров, редирект на index.php с прокидыванием CLICK_ID

function log_click_error($error, $sql) {
    $logfile = __DIR__ . '/click_log.txt';
    $dt = date('Y-m-d H:i:s');
    $msg = "[$dt] $error\nSQL: $sql\n\n";
    file_put_contents($logfile, $msg, FILE_APPEND | LOCK_EX);
}

$host     = "localhost";
$user     = "pma";
$password = "REPLACE_WITH_DB_PASSWORD";
$database = "fapaio";

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    log_click_error("DB CONNECT ERROR: " . $conn->connect_error, "");
    http_response_code(500);
    exit;
}
$conn->set_charset('utf8mb4');

// Получаем параметры из $_GET
$ts = date('Y-m-d H:i:s');
$uiq = isset($_GET['uiq']) ? $conn->real_escape_string($_GET['uiq']) : bin2hex(random_bytes(16));
$ip  = isset($_SERVER['REMOTE_ADDR']) ? $conn->real_escape_string($_SERVER['REMOTE_ADDR']) : '';

$country_code = '';
if (isset($_GET['c3']) && preg_match('/^[A-Z]{2,6}$/i', $_GET['c3'])) {
    $country_code = $conn->real_escape_string($_GET['c3']);
} else {
    // Определяем страну по IP, если c3 не передан
    $ip_lookup = $ip;
    $sql = "SELECT country_code FROM ip2location_db1 WHERE INET_ATON('$ip_lookup') BETWEEN ip_from AND ip_to LIMIT 1";
    if ($res = @$conn->query($sql)) {
        if ($row = $res->fetch_assoc()) {
            $country_code = $row['country_code'] ?? '';
        }
        $res->free();
    }
}

$page = 'click';
$ref = isset($_SERVER['HTTP_REFERER']) ? $conn->real_escape_string(substr($_SERVER['HTTP_REFERER'], 0, 512)) : '';
$ua  = isset($_SERVER['HTTP_USER_AGENT']) ? $conn->real_escape_string($_SERVER['HTTP_USER_AGENT']) : '';

// Входной click id из сетки (если есть)
$cid = isset($_GET['cid']) ? $conn->real_escape_string($_GET['cid'])
     : (isset($_GET['click_id']) ? $conn->real_escape_string($_GET['click_id']) : '');

$browser     = isset($_GET['browser']) ? $conn->real_escape_string($_GET['browser']) : $ua;
$device      = isset($_GET['device']) ? $conn->real_escape_string($_GET['device']) : '';
$isp         = isset($_GET['isp']) ? $conn->real_escape_string($_GET['isp']) : '';
$platform    = isset($_GET['platform']) ? $conn->real_escape_string($_GET['platform']) : '';
$language    = isset($_GET['language']) ? $conn->real_escape_string($_GET['language']) : '';

$campaign_id = (isset($_GET['cmpid']) && is_numeric($_GET['cmpid'])) ? intval($_GET['cmpid']) : 0;
$source_id   = (isset($_GET['source_id']) && is_numeric($_GET['source_id'])) ? intval($_GET['source_id']) : 0;

$c1 = isset($_GET['c1']) ? $conn->real_escape_string(substr((string)$_GET['c1'], 0, 255)) : '';
$c2 = isset($_GET['c2']) ? $conn->real_escape_string(substr((string)$_GET['c2'], 0, 255)) : '';
$c3 = $country_code;
$c4 = isset($_GET['c4']) ? $conn->real_escape_string(substr((string)$_GET['c4'], 0, 255)) : '';
$c5 = isset($_GET['c5']) ? $conn->real_escape_string(substr((string)$_GET['c5'], 0, 255)) : '';

$cost = (is_numeric($c4)) ? floatval($c4) : 0;

$external_id = isset($_GET['external_id']) ? $conn->real_escape_string(substr((string)$_GET['external_id'], 0, 255)) : null;
$external_postback = (isset($_GET['external_postback']) && is_numeric($_GET['external_postback'])) ? intval($_GET['external_postback']) : 0;

// Вставка в таблицу clicks (без проверки уникальности)
$insertClickSql = "INSERT INTO `fapaio`.`clicks`
    (`ts`, `uiq`, `ip`, `country`, `page`, `referer`, `ua`, `click_id`, `browser`, `device`, `isp`, `platform`, `language`, `campaign_id`, `source_id`, `cost`, `c1`, `c2`, `c3`, `c4`, `c5`, `external_id`, `external_postback`, `revenue`, `js`,`lead1`,`lead2`,`lead3`,`lead4`)
    VALUES
    ('$ts', '$uiq', '$ip', '$country_code', '$page', '$ref', '$ua', '$cid', '$browser', '$device', '$isp', '$platform', '$language', '$campaign_id', '$source_id', '$cost', '$c1', '$c2', '$c3', '$c4', '$c5', " .
    ($external_id !== null ? "'$external_id'" : "NULL") . ", $external_postback, '0','0','0','0','0','0')";

$conn->query($insertClickSql);
if ($conn->error) {
    log_click_error("MySQL INSERT ERROR: " . $conn->error, $insertClickSql);
    http_response_code(500);
    exit;
}

$inserted_cid = (int)$conn->insert_id;
$conn->close();

/**
 * Редирект на index.php
 * Прокидываем CLICK_ID, чтобы ui.js подставил в subid_7
 * + дублируем cid/click_id для совместимости с твоими другими скриптами
 */
$params = [
    'cid'      => $inserted_cid,
    'click_id' => $inserted_cid,
    'CLICK_ID' => $inserted_cid,
];

// CMPID (если был)
if ($campaign_id > 0) {
    $params['cmpid'] = $campaign_id;
    $params['CMPID'] = $campaign_id;
}

// ZONE (если был) — пробуем zone/subid_2/c2
$zone = '';
if (isset($_GET['zone'])) $zone = (string)$_GET['zone'];
elseif (isset($_GET['ZONE'])) $zone = (string)$_GET['ZONE'];
elseif (isset($_GET['subid_2'])) $zone = (string)$_GET['subid_2'];
elseif (!empty($c2)) $zone = (string)$c2;

$zone = substr($zone, 0, 255);
if ($zone !== '') {
    $params['zone'] = $zone;
    $params['ZONE'] = $zone;
}

$redirectUrl = 'https://fapa.cam/index.php?' . http_build_query($params);

// ВАЖНО: лучше 302, чтобы браузер не кешировал редирект во время тестов
header('Location: ' . $redirectUrl, true, 302);
exit;
