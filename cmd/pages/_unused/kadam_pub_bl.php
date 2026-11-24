<?php

// --- ИНИЦИАЛИЗАЦИЯ ПАР GEO-КАМПАНИЯ (создание, если не существует) ---
/*
 * Этот блок должен быть всегда самым первым в module34.php!
 * Создаёт пары (camp_id, geo) в geo_bid_state для всех geo из kadam_geolist, если их нет.
 */
if (isset($conn) && isset($camp_id)) {
    // Получаем список GEO
    $geo_list_init = [];
    $query_geo_init = 'SELECT `cc` FROM `kadam_geolist`';
    $result_geo_init = $conn->query($query_geo_init);
    if ($result_geo_init && $result_geo_init->num_rows > 0) {
        while($row = $result_geo_init->fetch_assoc()) {
            $geo_list_init[] = $row['cc'];
        }
    }
    // Создаём пары, если не существуют
    foreach ($geo_list_init as $cc) {
        $check_sql = 'SELECT 1 FROM geo_bid_state WHERE camp_id='.$camp_id.' AND geo="'.$conn->real_escape_string($cc).'" LIMIT 1';
        $check_res = $conn->query($check_sql);
        //===============
        $query_offer = 'SELECT `offer_id` FROM `split_offer` WHERE `camp_id` = '.$camp_id.' ORDER BY `id` DESC LIMIT 1';
        $result_offer = $conn->query($query_offer);
        $offer_id = 0;
        if ($result_offer && $result_offer->num_rows > 0) {
            $row = $result_offer->fetch_assoc();
            $offer_id = (int)$row['offer_id'];
        }
        if (!$offer_id) {
            die('Не найден offer_id для camp_id='.$camp_id);
        }

        //===============
        if (!$check_res || $check_res->num_rows === 0) {
            $query_ffff='INSERT INTO geo_bid_state (id,camp_id, offer_id, geo, bid, last_update, mode) VALUES (NULL, '.$camp_id.', '.$offer_id.',"'.$conn->real_escape_string($cc).'", "0.0001", NOW(), "test")';
            //echo '<textarea>'.$query_ffff.'</textarea>'; // Для отладки запроса
            $conn->query($query_ffff);

        }
    }
}
// --- КОНЕЦ БЛОКА ИНИЦИАЛИЗАЦИИ ---

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo '$camp_id='.$camp_id.'<br>';
echo '$source_camp='.$source_camp.'<br>';

// --- Обработка сброса кликов ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_clicks') {
    $sql = "DELETE FROM `click` WHERE `camp_id`=$camp_id";
    if ($conn->query($sql)) {
        echo '<div style="background:#ffeaea;color:#c9302c;padding:8px 12px;margin-bottom:10px;border:1px solid #e2b2b2;">Клики для camp_id=1123 успешно удалены</div>';
    } else {
        echo '<div style="background:#ffeaea;color:#c9302c;padding:8px 12px;margin-bottom:10px;border:1px solid #e2b2b2;">Ошибка удаления: ' . htmlspecialchars($conn->error) . '</div>';
    }
}

// --- Обработка сохранения параметров автоматизации ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_geo_bid_state_setup') {
    //$camp_id = isset($_POST['camp_id']) ? (int)$_POST['camp_id'] : 0;
    $fields = [
        'min_clicks' => (int)($_POST['min_clicks'] ?? 0),
        'roi_limit' => (float)($_POST['roi_limit'] ?? 0),
        'status' => $_POST['status'] ?? '',
        'comment' => $_POST['comment'] ?? '',
        'whitelist_geo' => $_POST['whitelist_geo'] ?? '',
        'blacklist_geo' => $_POST['blacklist_geo'] ?? '',
        'step' => (float)($_POST['step'] ?? 0),
        'max_bid' => (float)($_POST['max_bid'] ?? 0),
        'min_bid' => (float)($_POST['min_bid'] ?? 0),
    ];
    $set = [];
    foreach ($fields as $k => $v) {
        $set[] = "`$k` = '" . $conn->real_escape_string($v) . "'";
    }
    $sql = "UPDATE geo_bid_state_setup SET " . implode(', ', $set) . " WHERE camp_id = $camp_id LIMIT 1";
    if ($conn->query($sql)) {
        echo '<div style="background:#eaffea;color:#197e1f;padding:8px 12px;margin-bottom:10px;border:1px solid #b2e2b2;">Параметры успешно обновлены</div>';
    } else {
        echo '<div style="background:#ffeaea;color:#c9302c;padding:8px 12px;margin-bottom:10px;border:1px solid #e2b2b2;">Ошибка обновления: ' . htmlspecialchars($conn->error) . '</div>';
    }
}

/**
 * Модуль 34: Тестирование офферов внутри РК (Kadam)
 * - Для каждого GEO из kadam_geolist тестируем оффер (offer_id из split_offer, camp_id=1264)
 * - Начинаем с bid=0.0001, каждые 10 минут +0.00001
 * - Если по geo появляется плюс (прибыль > 0), переводим в CPC = EPC/2, но не выше 0.001
 * - Таблица: GEO, kadam_id, bid, CR, EPC, прибыль, статус, подтверждение API
 */

// Получаем актуальный offer_id для кампании 1264


// --- fill_whitelist_geo: серверное заполнение whitelist_geo через обычный POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fill_whitelist_geo') {
    require_once __DIR__.'/../include/config.php';
    $camp_id = isset($_POST['camp_id']) ? (int)$_POST['camp_id'] : 0;
    $geo = [];
    if ($camp_id > 0) {
        $sql = 'SELECT geo FROM geo_bid_state WHERE camp_id='.$camp_id.' AND mode="cpc"';
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while($row = $res->fetch_assoc()) {
                $geo[] = $row['geo'];
            }
        }
    }
    $whitelist_geo_str = implode(',', $geo);
}

// --- Глобальная загрузка параметров geo_bid_state_setup для camp_id ---
$global_geo_setup = [];
$query_global_setup = 'SELECT * FROM geo_bid_state_setup WHERE camp_id = '.$camp_id.' LIMIT 1';
$result_global_setup = $conn->query($query_global_setup);
if ($result_global_setup && $result_global_setup->num_rows > 0) {
    $row = $result_global_setup->fetch_assoc();
$global_geo_setup = [
    'min_clicks'     => (int)$row['min_clicks'],
    'roi_limit'      => (float)$row['roi_limit'],
    'status'         => $row['status'],
    'comment'        => $row['comment'],
    'whitelist_geo'  => isset($whitelist_geo_str) ? $whitelist_geo_str : $row['whitelist_geo'],
    'blacklist_geo'  => $row['blacklist_geo'],
    'step'           => (float)$row['step'],
    'max_bid'        => (float)$row['max_bid'],
    'min_bid'        => (float)$row['min_bid'],
    'min_click_bl'   => isset($row['min_click_bl']) ? (int)$row['min_click_bl'] : 50,
    'roi_limit_bl'   => isset($row['roi_limit_bl']) ? (float)$row['roi_limit_bl'] : -10,
];
} else {
    // Если нет записи — делаем разовый insert дефолтных значений
    $def_sql = "INSERT INTO geo_bid_state_setup (camp_id, min_clicks, roi_limit, status, manual_skip, comment, whitelist_geo, blacklist_geo, step, max_bid, min_bid, min_click_bl, 	roi_limit_bl)
        VALUES ($camp_id, 200, 0, 'active', '', 'Автоматически создано', '', '', 0.00001, 0.001, 0.0001, 200, -40)";
        //cho '<textarea>'.$def_sql.'</textarea>'; // Для отладки запроса
    $conn->query($def_sql);
    // Перечитываем только что вставленную строку
    $result_global_setup = $conn->query($query_global_setup);
    if ($result_global_setup && $result_global_setup->num_rows > 0) {
        $row = $result_global_setup->fetch_assoc();
        $global_geo_setup = [
            'min_clicks'     => (int)$row['min_clicks'],
            'roi_limit'      => (float)$row['roi_limit'],
            'status'         => $row['status'],
            'manual_skip'    => $row['manual_skip'],
            'comment'        => $row['comment'],
            'whitelist_geo'  => isset($whitelist_geo_str) ? $whitelist_geo_str : $row['whitelist_geo'],
            'blacklist_geo'  => $row['blacklist_geo'],
            'step'           => (float)$row['step'],
            'max_bid'        => (float)$row['max_bid'],
            'min_bid'        => (float)$row['min_bid'],
        ];
    }
}
/* --- конец глобальной загрузки --- */
// --- Обработка сброса ставок на min_bid ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_min_bid') {
    

    $res = $conn->query('SELECT min_bid FROM geo_bid_state_setup WHERE camp_id='.$camp_id.' LIMIT 1');
    if ($res && $row = $res->fetch_assoc()) {
        $min_bid = (float)$row['min_bid'];
    }
    if ($min_bid > 0) {
        $sql = 'UPDATE geo_bid_state SET bid='.$min_bid.' WHERE camp_id='.$camp_id;
        if ($conn->query($sql)) {
            echo '<div style="background:#eaffea;color:#197e1f;padding:8px 12px;margin-bottom:10px;border:1px solid #b2e2b2;">Всем geo выставлен bid = '.number_format($min_bid, 5, '.', '').'</div>';
        } else {
            echo '<div style="background:#ffeaea;color:#c9302c;padding:8px 12px;margin-bottom:10px;border:1px solid #e2b2b2;">Ошибка сброса bid: ' . htmlspecialchars($conn->error) . '</div>';
        }
    } else {
        echo '<div style="background:#ffeaea;color:#c9302c;padding:8px 12px;margin-bottom:10px;border:1px solid #e2b2b2;">min_bid не найден для camp_id=1123</div>';
    }
}


$query_offer = 'SELECT `offer_id` FROM `split_offer` WHERE `camp_id` = '.$camp_id.' ORDER BY `id` DESC LIMIT 1';
$result_offer = $conn->query($query_offer);
$offer_id = 0;
if ($result_offer && $result_offer->num_rows > 0) {
    $row = $result_offer->fetch_assoc();
    $offer_id = (int)$row['offer_id'];
}
if (!$offer_id) {
    die('Не найден offer_id для camp_id='.$camp_id);
}

// Получаем список GEO для теста
$geo_list = [];
$query_geo = 'SELECT `cc`, `kadam_id` FROM `kadam_geolist`';
//echo '<textarea>'.$query_geo .'</textarea>'; // Для отладки запроса
$result_geo = $conn->query($query_geo);
if ($result_geo && $result_geo->num_rows > 0) {
    while($row = $result_geo->fetch_assoc()) {
        $geo_list[] = [
            'cc' => $row['cc'],
            'kadam_id' => $row['kadam_id']
        ];
    }
}


/* --- Инициализация geo_bid_state: для всех geo из kadam_geolist должна быть запись (camp_id, geo) --- */
foreach ($geo_list as $geo) {
    $cc = $geo['cc'];
    $check_sql = 'SELECT 1 FROM geo_bid_state WHERE camp_id='.$camp_id.' AND geo="'.$conn->real_escape_string($cc).'" LIMIT 1';
    $check_res = $conn->query($check_sql);
    if (!$check_res || $check_res->num_rows === 0) {
        $conn->query('INSERT INTO geo_bid_state (camp_id, geo, bid, last_update, mode) VALUES ('.$camp_id.', "'.$conn->real_escape_string($cc).'", '.$global_geo_setup['min_bid'].', NOW(), "test")');
    }
}
/* --- конец инициализации geo_bid_state --- */

// Основной цикл по GEO
$countryBids = [];
$now = time();
$step = $global_geo_setup['step'];
$min_bid = $global_geo_setup['min_bid'];
$max_bid = $global_geo_setup['max_bid'];
$interval = 600; // 10 минут

// Получаем source_camp (ID кампании Kadam) из GET/POST или переменной
//$source_camp = isset($_GET['source_camp']) ? (int)$_GET['source_camp'] : (isset($source_camp) ? (int)$source_camp : 0);

// Подключаем Kadam SDK
require_once __DIR__.'/source/vendor/autoload.php';
$kadamApi = new \kadam\KadamApi(141381, '7be1c295ac0bc2630bcb32edbb27dbdd5df18464f308cb90f50cfa14ddb5c394');

echo '<style>
    table { border-collapse: collapse; min-width:1000px; margin:12px 0; font-size:14px;}
    th, td { border: 1px solid #d2d2d2; padding: 4px 9px; text-align: center;}
    th { background: #f2f2f2; }
    .roi_pos { background: #eaffea; color: #197e1f; font-weight:bold; }
    .roi_neg { background: #ffeaea; color: #c9302c; font-weight:bold; }
    .status_test { background: #eaf4ff; color: #1970c1; font-weight:bold;}
    .status_plus { background: #eaffea; color: #197e1f; font-weight:bold;}
    .status_skip { background: #fffbe6; color: #c9302c; font-weight:bold;}
    .status_limit { background: #fffbe6; color: #8f6c00; font-weight:bold;}
    .status_wait { background: #f7f7f7; color: #aaaaaa; font-style:italic;}
</style>';

// Визуализация параметров автоматизации и ручного skip
echo '<h3>Тестирование офферов внутри РК (Kadam)</h3>';

// Форма/таблица для настройки критериев отключения и ручного skip
echo '<div style="margin-bottom:18px;padding:10px 14px;border:1px solid #d2d2d2;background:#f9f9f9;">';
echo '<b>Параметры автоматизации для кампании camp_id='.$camp_id.':</b><br>';
echo '<form method="POST" action="'.htmlspecialchars($_SERVER['REQUEST_URI']).'" style="margin:0;padding:0;">';
echo '<input type="hidden" name="camp_id" value="'.$camp_id.'">';
echo '<table style="margin-top:6px;margin-bottom:6px;"><tr>
<th>GEO</th>
<th>min_clicks</th>
<th>roi_limit</th>
<th>min_click_bl</th>
<th>roi_limit_bl</th>
<th>status</th>
<th>comment</th>
<th>whitelist_geo</th>
<th>whitelist</th>
<th>blacklist_geo</th>
<th>step</th>
<th>max_bid</th>
<th>min_bid</th>
<th></th>
</tr>';
echo '<tr>';
echo '<td>ALL</td>';
echo '<td><input type="number" name="min_clicks" value="'.htmlspecialchars($global_geo_setup['min_clicks']).'" style="width:70px"></td>';
echo '<td><input type="text" name="roi_limit" value="'.htmlspecialchars($global_geo_setup['roi_limit']).'" style="width:70px"></td>';
echo '<td><input type="number" name="min_click_bl" value="'.htmlspecialchars($global_geo_setup['min_click_bl'] ?? 50).'" style="width:70px"></td>';
echo '<td><input type="text" name="roi_limit_bl" value="'.htmlspecialchars($global_geo_setup['roi_limit_bl'] ?? -10).'" style="width:70px"></td>';
echo '<td><input type="text" name="status" value="'.htmlspecialchars($global_geo_setup['status']).'" style="width:90px"></td>';
echo '<td><input type="text" name="comment" value="'.htmlspecialchars($global_geo_setup['comment']).'" style="width:120px"></td>';
echo '<td><input type="text" name="whitelist_geo" id="whitelist_geo" value="'.htmlspecialchars($global_geo_setup['whitelist_geo']).'" style="width:120px"></td>';
echo '<td>
    <button type="submit" name="action" value="fill_whitelist_geo" style="">Собрать WhiteList гео (CPC)</button>
</td>';
echo '<td><input type="text" name="blacklist_geo" value="'.htmlspecialchars($global_geo_setup['blacklist_geo']).'" style="width:120px"></td>';
echo '<td><input type="text" name="step" value="'.number_format($global_geo_setup['step'], 5, '.', '').'" style="width:70px"></td>';
echo '<td><input type="text" name="max_bid" value="'.htmlspecialchars($global_geo_setup['max_bid']).'" style="width:70px"></td>';
echo '<td><input type="text" name="min_bid" value="'.htmlspecialchars($global_geo_setup['min_bid']).'" style="width:70px"></td>';
echo '<td><button type="submit" name="action" value="update_geo_bid_state_setup" style="padding:2px 10px;">Сохранить</button></td>';
echo '</tr>';
echo '</table></form>';

/* JS удалён, теперь только POST-форма для сбора whitelist_geo */

echo '<form method="POST" action="'.htmlspecialchars($_SERVER['REQUEST_URI']).'" style="margin:10px 0 0 0;padding:0;display:inline;">';
echo '<input type="hidden" name="action" value="reset_clicks">';
echo '<button type="submit" style="padding:4px 18px;background:#ffeaea;color:#c9302c;border:1px solid #e2b2b2;font-weight:bold;">Сбросить все клики и перетест</button>';
echo '</form> ';
echo '<form method="POST" action="'.htmlspecialchars($_SERVER['REQUEST_URI']).'" style="margin:10px 0 0 10px;padding:0;display:inline;">';
echo '<input type="hidden" name="action" value="reset_min_bid">';
echo '<button type="submit" style="padding:4px 18px;background:#eaffea;color:#197e1f;border:1px solid #b2e2b2;font-weight:bold;">Сбросить всем min_bid</button>';
echo '</form>';



echo '<table>
<tr>
    <th>GEO</th>
    <th>Kadam ID</th>
    <th>Bid</th>
    <th>Клики</th>
    <th>CR (%)</th>
    <th>EPC</th>
    <th>Профит</th>
    <th>ROI (%)</th>
    <th>Статус</th>
    <th>API</th>
</tr>';


// Групповой запрос по всем geo для сбора статистики
$date_from = date('Y-m-d', strtotime('-100 day')).' 00:00:00';
$date_to = date('Y-m-d').' 23:59:59';
$geo_stats = [];
$query_stats = 'SELECT geo, COUNT(*) as clicks, SUM(CASE WHEN lead=1 THEN 1 ELSE 0 END) as leads, SUM(cpc) as cost, SUM(rev) as rev FROM `click` WHERE `camp_id`='.$camp_id.' AND geo!="" AND click_time >= "'.$date_from.'" AND click_time <= "'.$date_to.'" GROUP BY geo';
//echo '<textarea>'.$query_stats.'</textarea>'; // Для отладки запроса
$result_stats = $conn->query($query_stats);
if ($result_stats && $result_stats->num_rows > 0) {
    while($row = $result_stats->fetch_assoc()) {
        $geo_stats[$row['geo']] = [
            'clicks' => (int)$row['clicks'],
            'leads' => (int)$row['leads'],
            'cost' => (float)$row['cost'],
            'rev' => (float)$row['rev']
        ];
    }
}

// Визуализация глобальных параметров автоматизации и ручного skip

usort($geo_list, function($a, $b) use ($geo_stats) {
    $a_clicks = isset($geo_stats[$a['cc']]) ? $geo_stats[$a['cc']]['clicks'] : 0;
    $b_clicks = isset($geo_stats[$b['cc']]) ? $geo_stats[$b['cc']]['clicks'] : 0;
    return $b_clicks <=> $a_clicks;
});

foreach ($geo_list as $geo) {
    $cc = $geo['cc'];
    $kadam_id = $geo['kadam_id'];

    // Whitelist/Blacklist: если geo не в whitelist (если он задан) или geo в blacklist — сразу SKEEP
    $whitelist = array_filter(array_map('trim', explode(',', $global_geo_setup['whitelist_geo'])));
    $blacklist = array_filter(array_map('trim', explode(',', $global_geo_setup['blacklist_geo'])));
    if (!empty($blacklist) && in_array($cc, $blacklist, true)) {
        $conn->query('UPDATE geo_bid_state SET mode="SKEEP" WHERE camp_id='.$camp_id.' AND geo="'.$conn->real_escape_string($cc).'"');
        $mode = 'SKEEP';
        // В таблицу попадёт как SKEEP, но в API не уйдёт
        continue;
    }
    if (!empty($whitelist) && !in_array($cc, $whitelist, true)) {
        $conn->query('UPDATE geo_bid_state SET mode="SKEEP" WHERE camp_id='.$camp_id.' AND geo="'.$conn->real_escape_string($cc).'"');
        $mode = 'SKEEP';
        continue;
    }

    // manual_skip больше не используется

    // Получаем текущую ставку и время последнего изменения (пример: из таблицы geo_bid_state)
    $query_state = 'SELECT `bid`, `last_update`, `mode` FROM `geo_bid_state` WHERE `camp_id`='.$camp_id.' AND `geo`="'.$conn->real_escape_string($cc).'"';
    $result_state = $conn->query($query_state);
    $bid = $min_bid;
    $last_update = 0;
    $mode = 'test'; // test/cpc/skip

    if ($result_state && $result_state->num_rows > 0) {
        $row = $result_state->fetch_assoc();
        $bid = (float)$row['bid'];
        $last_update = strtotime($row['last_update']);
        $mode = $row['mode'];
    }

    // Плавное повышение ставки раз в 10 минут
    if ($mode === 'test' && ($now - $last_update >= $interval)) {
        $bid = min($bid + $step, $max_bid);
        $conn->query('UPDATE geo_bid_state SET bid='.$bid.', last_update=NOW(), mode="test" WHERE camp_id='.$camp_id.' AND geo="'.$conn->real_escape_string($cc).'"');
    }

    // Получаем статистику по этому GEO из массива
    $clicks = $leads = $cost = $rev = 0;
    if (isset($geo_stats[$cc])) {
        $clicks = $geo_stats[$cc]['clicks'];
        $leads = $geo_stats[$cc]['leads'];
        $cost = $geo_stats[$cc]['cost'];
        $rev = $geo_stats[$cc]['rev'];
    }
    $cr = $clicks > 0 ? round($leads / $clicks * 100, 2) : 0;
    $epc = $clicks > 0 ? round($rev / $clicks, 5) : 0;
    $profit = $rev - $cost;
    $roi = ($cost > 0) ? round(($profit / $cost) * 100, 1) : 0;

    // SKEEP по условиям: если кликов > min_clicks и ROI < roi_limit — skip и обновить статус
    if ($clicks > $global_geo_setup['min_clicks'] && $roi < $global_geo_setup['roi_limit']) {
        $conn->query('UPDATE geo_bid_state SET mode="SKEEP" WHERE camp_id='.$camp_id.' AND geo="'.$conn->real_escape_string($cc).'"');
        $mode = 'SKEEP';
    } else {
        $conn->query('UPDATE geo_bid_state SET mode="test" WHERE camp_id='.$camp_id.' AND geo="'.$conn->real_escape_string($cc).'"');
        $mode = 'test';
    }
    // Далее — проверка на CPC

    // Если ROI превышает порог из параметров — переводим в режим CPC (CPC = EPC / 2)
    $status = 'Тест';
    if ($roi > $global_geo_setup['roi_limit'] && $mode !== 'cpc') {
        $bid = min(round($epc / 2, 5), $max_bid);
        $mode = 'cpc';
        $conn->query('UPDATE geo_bid_state SET bid='.$bid.', last_update=NOW(), mode="cpc" WHERE camp_id='.$camp_id.' AND geo="'.$conn->real_escape_string($cc).'"');
        $status = 'CPC (ROI>'.$global_geo_setup['roi_limit'].')';
    }
    if ($bid > $max_bid) $bid = $max_bid;
    if ($bid < $min_bid) $bid = $min_bid;

    // Формируем массив для API
    // В API Kadam отправляем только если не SKEEP
    if ($kadam_id > 0 && $mode !== 'SKEEP') {
        $countryBids[$kadam_id] = ['bid' => $bid, 'leadCost' => 0.01];
    }
}

// Отправляем ставки по API Kadam для всех geo одним запросом
$api_result = '';
if ($source_camp > 0 && !empty($countryBids)) {
    try {
        $response = $kadamApi->updateCampaign($source_camp, [
            'countryTarget' => $countryBids
        ]);
        $api_result = is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE) : (string)$response;
    } catch (Exception $e) {
        $api_result = 'Ошибка API: ' . $e->getMessage();
    }
}

// Выводим таблицу с результатами и API-ответом
foreach ($geo_list as $geo) {
    $cc = $geo['cc'];
    $kadam_id = $geo['kadam_id'];

    // Получаем текущую ставку и режим
    $query_state = 'SELECT `bid`, `mode` FROM `geo_bid_state` WHERE `camp_id`='.$camp_id.' AND `offer_id`='.$offer_id.' AND `geo`="'.$cc.'"';
    $result_state = $conn->query($query_state);
    $bid = $min_bid;
    $mode = 'test';
    if ($result_state && $result_state->num_rows > 0) {
        $row = $result_state->fetch_assoc();
        $bid = (float)$row['bid'];
        $mode = $row['mode'];
    }

    // SKEEP: если mode=SKEEP, выводим в таблицу, но не отправляем в API
    if ($mode === 'SKEEP') {
        $status = 'SKEEP';
        $status_class = 'status_skip';
    } elseif ($mode === 'cpc') {
        $status = 'Плюс (CPC)';
        $status_class = 'status_plus';
    } elseif ($mode === 'skip') {
        $status = 'SKIP';
        $status_class = 'status_skip';
    } else {
        $status = 'Тест';
        $status_class = 'status_test';
    }

    // Получаем статистику только из $geo_stats!
    $clicks = $leads = $cost = $rev = 0;
    if (isset($geo_stats[$cc])) {
        $clicks = $geo_stats[$cc]['clicks'];
        $leads = $geo_stats[$cc]['leads'];
        $cost = $geo_stats[$cc]['cost'];
        $rev = $geo_stats[$cc]['rev'];
    }
    $cr = $clicks > 0 ? round($leads / $clicks * 100, 2) : 0;
    $epc = $clicks > 0 ? round($rev / $clicks, 5) : 0;
    $profit = $rev - $cost;
    $roi = ($cost > 0) ? round(($profit / $cost) * 100, 1) : 0;

    // Цветовая раскраска по ROI
    $roi_class = ($roi >= 0) ? 'roi_pos' : 'roi_neg';

    echo '<tr class="'.$status_class.'">
        <td>'.$cc.'</td>
        <td>'.$kadam_id.'</td>
        <td>'.number_format($bid, 5, '.', '').'</td>
        <td>'.$clicks.'</td>
        <td>'.$cr.'</td>
        <td>'.$epc.'</td>
        <td>'.$profit.'</td>
        <td class="'.$roi_class.'">'.$roi.'</td>
        <td>'.$status.'</td>
        <td>';
    // Если mode=SKEEP, не показываем API-ответ
    if ($mode === 'SKEEP') {
        echo '-';
    } else {
        echo ($api_result ? htmlspecialchars($api_result) : '-');
    }
    echo '</td>
    </tr>';
}
echo '</table>';
// ======================================================================================================================================================================
// ======================================================================================================================================================================
// ==================================================================ПАБЛИШЕРЫ==========================================================================================
// ======================================================================================================================================================================
// ======================================================================================================================================================================
// ======================================================================================================================================================================
// ====== РАЗДЕЛИТЕЛЬ ======
echo '<hr style="margin:30px 0 20px 0; border:2px solid #197e1f;">';
echo '<h3 style="margin-top:0;">--- ПАБЛИШЕРЫ (token1, статистика, BL) ---</h3>';

// Собираем статистику по паблишерам за последние 4 дня
$date_from_bl = date('Y-m-d', strtotime("-100 day")) . ' 00:00:00';
$date_to_bl = date('Y-m-d') . ' 23:59:00';
$query_bl_stat = 'SELECT token1, COUNT(*) as click_count, SUM(CASE WHEN lead=1 THEN 1 ELSE 0 END) as lead_sum, SUM(CASE WHEN lp_view=1 THEN 1 ELSE 0 END) as lp_view_sum, SUM(cpc) as cost_sum, SUM(rev) as rev_sum FROM `click` WHERE click_time >= "'.$date_from_bl.'" AND click_time <= "'.$date_to_bl.'" AND `camp_id`="'.$camp_id.'" GROUP BY `token1`';
$result_bl_stat = $conn->query($query_bl_stat);

$publishers = [];
if ($result_bl_stat && $result_bl_stat->num_rows > 0) {
    while($line = $result_bl_stat->fetch_assoc()) {
        $token1 = $line['token1'];
        $click_count = (int)$line['click_count'];
        $lead_sum = (int)$line['lead_sum'];
        $cost_sum = (float)$line['cost_sum'];
        $rev_sum = (float)$line['rev_sum'];
        $roi = ($cost_sum > 0 && $rev_sum > 0) ? round((($rev_sum - $cost_sum) / $cost_sum) * 100, 1) : -100;
        $cr = $click_count > 0 ? round($lead_sum / $click_count * 100, 2) : 0;

        // BL-логика
        $query_bl = 'SELECT * FROM `black_list_ts` WHERE `pub_name` = "'.$conn->real_escape_string($token1).'" AND `camp_id`="'.$camp_id.'"';
        $result_bl = $conn->query($query_bl);
        $is_bl = ($result_bl && $result_bl->num_rows > 0);

        $ban_condition = ($click_count > $global_geo_setup['min_click_bl'] && $roi < $global_geo_setup['roi_limit_bl']);

        if ($ban_condition) {
            if (!$is_bl) {
                $query2 = 'INSERT INTO `black_list_ts` (`id`, `pub_name`, `source_id`, `camp_id`, `status_api`) VALUES (NULL, "'.$conn->real_escape_string($token1).'", "'.$conn->real_escape_string($source_id ?? '').'", "'.$camp_id.'", "0");';
                $conn->query($query2);
                $is_bl = true;
            }
        } else {
            if ($is_bl) {
                while($line_bl = $result_bl->fetch_assoc()) {
                    $query2 = 'DELETE FROM `black_list_ts` WHERE id='.$line_bl['id'];
                    $conn->query($query2);
                }
                $is_bl = false;
            }
        }

        $publishers[] = [
            'token1' => $token1,
            'click_count' => $click_count,
            'cr' => $cr,
            'roi' => $roi,
            'is_bl' => $is_bl
        ];
    }
}

 // Сортировка по кликам (по убыванию)
usort($publishers, function($a, $b) {
    return $b['click_count'] <=> $a['click_count'];
});

echo '<table style="margin-top:10px; margin-bottom:10px; min-width:900px;"><tr>
<th>Паблишер (token1)</th>
<th>Клики</th>
<th>CR (%)</th>
<th>ROI (%)</th>
<th>BL</th>
</tr>';

foreach ($publishers as $pub) {
    $row_color = '';
    if ($pub['roi'] < $global_geo_setup['roi_limit_bl']) {
        $row_color = 'background:#ffeaea;';
    } elseif ($pub['roi'] > 0) {
        $row_color = 'background:#eaffea;';
    } else {
        $row_color = 'background:#fffbe6;';
    }
    $bl_cell = $pub['is_bl'] ? '<b>BL</b>' : '';
    echo '<tr style="'.$row_color.'">
        <td>'.htmlspecialchars($pub['token1']).'</td>
        <td>'.$pub['click_count'].'</td>
        <td>'.$pub['cr'].'</td>
        <td>'.$pub['roi'].'</td>
        <td>'.$bl_cell.'</td>
    </tr>';
}
echo '</table>';

// ====== БЛОК: Black List паблишеров (token1, black_list_ts) ======
echo '<h3>Black List паблишеров (token1, black_list_ts)</h3>';
$start_bl = microtime(true);

// Собираем статистику по паблишерам за последние 4 дня
$date_from_bl = date('Y-m-d', strtotime("-4 day")) . ' 00:00:00';
$date_to_bl = date('Y-m-d') . ' 23:59:00';
$query_bl_stat = 'SELECT token1, COUNT(*) as click_count, SUM(CASE WHEN lead=1 THEN 1 ELSE 0 END) as lead_sum, SUM(CASE WHEN lp_view=1 THEN 1 ELSE 0 END) as lp_view_sum, SUM(cpc) as cost_sum, SUM(rev) as rev_sum FROM `click` WHERE click_time >= "'.$date_from_bl.'" AND click_time <= "'.$date_to_bl.'" AND `camp_id`="'.$camp_id.'" GROUP BY `token1` HAVING click_count>' . (int)($global_geo_setup['min_click_bl'] ?? 50) . ' ORDER BY `click_count` DESC';
$result_bl_stat = $conn->query($query_bl_stat);

if ($result_bl_stat && $result_bl_stat->num_rows > 0) {
    while($line = $result_bl_stat->fetch_assoc()) {
        $token1 = $line['token1'];
        $click_count = (int)$line['click_count'];
        $lead_sum = (int)$line['lead_sum'];
        $lp_view_sum = (int)$line['lp_view_sum'];
        $cost_sum = (float)$line['cost_sum'];
        $rev_sum = (float)$line['rev_sum'];
        $roi = ($cost_sum > 0 && $rev_sum > 0) ? round((($rev_sum - $cost_sum) / $cost_sum) * 100, 1) : -100;
        $cr = $click_count > 0 ? round($lead_sum / $click_count * 100, 2) : 0;

        // Амнистия: если ROI>0 и CR>80 — удаляем из black_list_ts
        if ($roi > 0 && $cr > 80) {
            $query_bl = 'SELECT * FROM `black_list_ts` WHERE `pub_name` = "'.$conn->real_escape_string($token1).'" AND `camp_id`="'.$camp_id.'"';
            $result_bl = $conn->query($query_bl);
            if ($result_bl && $result_bl->num_rows > 0) {
                while($line_bl = $result_bl->fetch_assoc()) {
                    $query2 = 'DELETE FROM `black_list_ts` WHERE id='.$line_bl['id'];
                    $conn->query($query2);
                }
            }
        }

        // Добавление в black_list_ts: если ROI ниже порога и click_count>min_click_bl или click_count>2*min_click_bl и CR<70
        if (($click_count > ($global_geo_setup['min_click_bl'] ?? 50) && $roi < ($global_geo_setup['roi_limit_bl'] ?? -10)) || ($click_count > 2*($global_geo_setup['min_click_bl'] ?? 50) && $cr < 70)) {
            $flag_pub = 0;
            $query_bl = 'SELECT * FROM `black_list_ts` WHERE `pub_name` = "'.$conn->real_escape_string($token1).'" AND `camp_id`="'.$camp_id.'"';
            $result_bl = $conn->query($query_bl);
            if ($result_bl && $result_bl->num_rows > 0) {
                $flag_pub = 1;
            }
            if ($flag_pub == 0) {
                $query2 = 'INSERT INTO `black_list_ts` (`id`, `pub_name`, `source_id`, `camp_id`, `status_api`) VALUES (NULL, "'.$conn->real_escape_string($token1).'", "'.$conn->real_escape_string($source_id ?? '').'", "'.$camp_id.'", "0");';
                $conn->query($query2);
            }
        }
    }
}

// Собираем все паблишеры из black_list_ts для camp_id
$query_bl_all = 'SELECT * FROM `black_list_ts` WHERE `camp_id` = '.$camp_id;
$result_bl_all = $conn->query($query_bl_all);
$where_bl = [];
$flag_need_update = 0;
if ($result_bl_all && $result_bl_all->num_rows > 0) {
    while($line_bl = $result_bl_all->fetch_assoc()) {
        if ($line_bl['pub_name'] != '{zoneid}') {
            if ($line_bl['status_api'] == 0) $flag_need_update = 1;
            $where_bl[] = $line_bl['pub_name'];
            if ($line_bl['status_api'] == '0') {
                $query2 = 'UPDATE `black_list_ts` SET `status_api` = "1" WHERE `black_list_ts`.`id` = '.$line_bl['id'].';';
                $conn->query($query2);
            }
        }
    }
}

// Если есть новые паблишеры для отправки — делаем API-запрос в Kadam
if ($flag_need_update == 1 && !empty($where_bl) && isset($source_camp)) {
    require_once __DIR__.'/source/vendor/autoload.php';
    $appId = 141381;
    $secretKey = '7be1c295ac0bc2630bcb32edbb27dbdd5df18464f308cb90f50cfa14ddb5c394';
    $kadamApi = new \kadam\KadamApi($appId, $secretKey);
    $campaignId = $source_camp;
    $response_bl = $kadamApi->updateCampaign($campaignId, [
        'black_list' => $where_bl,
    ]);
    echo '<h4>Обновлён black_list паблишеров через Kadam API</h4>';
    var_dump($where_bl);
    var_dump($response_bl);
}
$diff_bl = sprintf('%.6f sec.', microtime(true) - $start_bl);
echo "<h4>Время выполнения блока black_list_ts: $diff_bl</h4>";
echo '</form>';

echo '</div>';
