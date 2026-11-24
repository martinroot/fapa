<?php
// Единая функция для index.php: определяет страну, собирает все параметры, вставляет клик (полные данные), возвращает cid и переменные
function log_click_error($error, $sql) {
    $logfile = __DIR__ . '/click_log.txt';
    $dt = date('Y-m-d H:i:s');
    $msg = "[$dt] $error\nSQL: $sql\n\n";
    file_put_contents($logfile, $msg, FILE_APPEND | LOCK_EX);
}

function core_index($conn, $request, $server) {
    // Если есть cid — возвращаем все поля из таблицы clicks
    if (isset($request['cid']) && $request['cid'] !== '') {
        $cid = intval($request['cid']);
        $sql = "SELECT * FROM `fapaio`.`clicks` WHERE id = $cid LIMIT 1";
        
        $res = $conn->query($sql);
        if ($res && $row = $res->fetch_assoc()) {
            // Приводим к нужному формату
            return [
                'cid' => $row['id'],
                'uiq' => $row['uiq'] ?? '',
                'country_code' => $row['country'] ?? '',
                'country_name' => '', // если нужно, можно добавить в таблицу
                'ip' => $row['ip'] ?? '',
                'ref' => $row['referer'] ?? '',
                'ua' => $row['ua'] ?? '',
                'browser' => $row['browser'] ?? '',
                'device' => $row['device'] ?? '',
                'isp' => $row['isp'] ?? '',
                'platform' => $row['platform'] ?? '',
                'language' => $row['language'] ?? '',
                'campaign_id' => $row['campaign_id'] ?? '',
                'source_id' => $row['source_id'] ?? '',
                'cost' => $row['cost'] ?? 0,
                'c1' => $row['c1'] ?? '',
                'c2' => $row['c2'] ?? '',
                'c3' => $row['c3'] ?? '',
                'c4' => $row['c4'] ?? '',
                'c5' => $row['c5'] ?? '',
                'external_id' => $row['external_id'] ?? '',
                'external_postback' => $row['external_postback'] ?? 0
            ];
        }
        // Если не нашли — возвращаем только cid
        
        return [
            'cid' => $request['cid'],
            'note' => 'No insert, cid present, not found in clicks'
        ];
        
    }else
    {
            // Генерация/получение uiq
        if (isset($_COOKIE['uiq']) && preg_match('/^[a-zA-Z0-9]{8,64}$/', $_COOKIE['uiq'])) {
            $uiq = $_COOKIE['uiq'];
        } else {
            $uiq = bin2hex(random_bytes(16));
            setcookie('uiq', $uiq, time() + 60*60*24*365, '/');
        }

        // Определение IP
        if (!empty($server['HTTP_CLIENT_IP'])) {
            $ip = $server['HTTP_CLIENT_IP'];
        } elseif (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            $xff = $server['HTTP_X_FORWARDED_FOR'];
            if (strpos($xff, ',') !== false) {
                $parts = explode(',', $xff);
                $ip = trim($parts[0]);
            } else {
                $ip = $xff;
            }
        } else {
            $ip = $server['REMOTE_ADDR'];
        }

        // Определение страны
        if (isset($request['c3']) && preg_match('/^[A-Z]{2,6}$/i', $request['c3'])) {
            $country_code = strtoupper($request['c3']);
            $country_name = '';
        } else {
            $ipEsc = $conn->real_escape_string($ip);
            $sql = "SELECT country_code, country_name FROM ip2location_db1 WHERE INET_ATON('$ipEsc') BETWEEN ip_from AND ip_to LIMIT 1";
            $country_code = '';
            $country_name = '';
            if ($res = @$conn->query($sql)) {
                if ($row = $res->fetch_assoc()) {
                    $country_code = $row['country_code'] ?? '';
                    $country_name = $row['country_name'] ?? '';
                }
                $res->free();
            }
        }

        // Собираем все параметры для полной вставки
        $ref = isset($server['HTTP_REFERER']) ? $conn->real_escape_string($server['HTTP_REFERER']) : '';
        $ua  = isset($server['HTTP_USER_AGENT']) ? $conn->real_escape_string($server['HTTP_USER_AGENT']) : '';
        $page = 'index';

        $ts = date('Y-m-d H:i:s');
        $uiqEsc = $conn->real_escape_string($uiq);
        $ipEsc  = $conn->real_escape_string($ip);
        $ccEsc  = $conn->real_escape_string($country_code);
        $refEsc = $ref;
        $uaEsc  = $ua;
        $pageEsc= $conn->real_escape_string($page);

        $click_id = isset($request['cid']) ? $request['cid'] : (isset($request['click_id']) ? $request['click_id'] : '');
        $cidEsc = $conn->real_escape_string($click_id);

        $browser     = isset($request['browser']) ? $conn->real_escape_string($request['browser']) : $ua;
        $device      = isset($request['device']) ? $conn->real_escape_string($request['device']) : '';
        $isp         = isset($request['isp']) ? $conn->real_escape_string($request['isp']) : '';
        $platform    = isset($request['platform']) ? $conn->real_escape_string($request['platform']) : '';
        $language    = isset($request['language']) ? $conn->real_escape_string($request['language']) : '';
        // Если нет cid — это органика, campaign_id=1
        $campaign_id = '1';
        $source_id   = isset($request['source_id']) ? $conn->real_escape_string($request['source_id']) : '0';

        $c1 = isset($request['c1']) ? $conn->real_escape_string($request['c1']) : '';
        $c2 = isset($request['c2']) ? $conn->real_escape_string($request['c2']) : '';
        $c3 = isset($request['c3']) ? $conn->real_escape_string($request['c3']) : '';
        $c4 = isset($request['c4']) ? $conn->real_escape_string($request['c4']) : '';
        $c5 = isset($request['c5']) ? $conn->real_escape_string($request['c5']) : '';
        $cost = 0;
        if (preg_match('/^\d+\.\d+$/', $c4)) {
            $cost = floatval($c4);
        }

        $external_id = isset($request['external_id']) ? $conn->real_escape_string($request['external_id']) : null;
        $external_postback = isset($request['external_postback']) ? intval($request['external_postback']) : 0;

        // Вставка в таблицу clicks (уникальность по uiq)
        $checkClickSql = "SELECT id FROM `fapaio`.`clicks` WHERE `uiq` = '$uiqEsc' LIMIT 1";
        $resCheckClick = $conn->query($checkClickSql);
        $cid = null;
        if ($resCheckClick && $resCheckClick->num_rows === 0) {
            $insertClickSql = "INSERT INTO `fapaio`.`clicks`
                (`ts`, `uiq`, `ip`, `country`, `page`, `referer`, `ua`, `click_id`, `browser`, `device`, `isp`, `platform`, `language`, `campaign_id`, `source_id`, `cost`, `c1`, `c2`, `c3`, `c4`, `c5`, `external_id`, `external_postback`, `revenue`)
                VALUES
                ('$ts', '$uiqEsc', '$ipEsc', '$ccEsc', '$pageEsc', '$refEsc', '$uaEsc', '$cidEsc', '$browser', '$device', '$isp', '$platform', '$language', '$campaign_id', '$source_id', '$cost', '$c1', '$c2', '$c3', '$c4', '$c5', " . 
                ($external_id !== null ? "'$external_id'" : "NULL") . ", $external_postback, '0')";
                //echo '<textarea style="width:100%;height:200px;">' . htmlspecialchars($insertClickSql) . '</textarea>';
            $conn->query($insertClickSql);
            if ($conn->error) {
                // Логирование ошибки в click_log.txt
                log_click_error("MySQL INSERT ERROR: " . $conn->error, $insertClickSql);
            }

            
            $cid = $conn->insert_id;
            
            //echo '<h1>['.$cid.']</h1>'; // Чтобы избежать проблем с буферизацией при ошибках вставки
            error_log("DEBUG: insert_id = " . $cid . " | SQL: " . $insertClickSql);
        } elseif ($resCheckClick) {
            $row = $resCheckClick->fetch_assoc();
            $cid = $row['id'];
            log_click_error("elseif SELECT: ", "");
        }
        if ($resCheckClick) $resCheckClick->free();

        return [
            'cid' => $cid,
            'uiq' => $uiq,
            'country_code' => $country_code,
            'country_name' => $country_name,
            'ip' => $ip,
            'ref' => $ref,
            'ua' => $ua,
            'browser' => $browser,
            'device' => $device,
            'isp' => $isp,
            'platform' => $platform,
            'language' => $language,
            'campaign_id' => $campaign_id,
            'source_id' => $source_id,
            'cost' => $cost,
            'c1' => $c1,
            'c2' => $c2,
            'c3' => $c3,
            'c4' => $c4,
            'c5' => $c5,
            'external_id' => $external_id,
            'external_postback' => $external_postback
        ];
    }

    
}
