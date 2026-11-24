<?php
// ip_guard.php — мягкий гейт: сейчас ничего не блокирует, логирует аномалии
declare(strict_types=1);
function ip_guard_check(): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) { http_response_code(400); exit; }
}
