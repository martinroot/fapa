<?php
// cmd/config.php — bootstrap для fapa.cam (видеокаталог + ИИ-генерация)
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/lang_patch.php';

define('START_TOKENS', 900);
define('ANON_START_TOKENS', 300);
define('ANON_FREE_CACHED', 1);
define('GEN_TOKEN_COST', 300);
define('VIDEO_TOKEN_COST', 1000);

// Секреты подставляются из /etc/fapaio/secrets.env (читает root/окружение пула)
// FAPAIO_DB_PASSWORD задаётся в env пула php-fpm либо default ниже.
$__dbPass = getenv('FAPAIO_DB_PASSWORD') ?: 'REPLACE_WITH_DB_PASSWORD';

function db_connect(): mysqli {
    global $__dbPass;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        return new mysqli('127.0.0.1', 'pma', $__dbPass, 'fapaio');
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        exit('DB unavailable');
    }
}

function ensure_site_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS site_users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(64) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        tokens INT NOT NULL DEFAULT 900,
        tokens_refilled_at DATETIME NULL,
        paid_until DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS funnel_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(64) NOT NULL,
        page VARCHAR(64) NOT NULL DEFAULT '',
        uiq VARCHAR(64) NOT NULL DEFAULT '',
        ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY ev (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS blocked_clients (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        uiq VARCHAR(64) NOT NULL DEFAULT '',
        fingerprint VARCHAR(128) NOT NULL DEFAULT '',
        reason VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY bp (ip), KEY bq (uiq), KEY bf (fingerprint)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function get_site_user(mysqli $conn): ?array {
    if (empty($_SESSION['site_user_id'])) return null;
    $st = $conn->prepare('SELECT id, name, email, tokens, paid_until FROM site_users WHERE id = ? LIMIT 1');
    $id = (int)$_SESSION['site_user_id'];
    $st->bind_param('i', $id);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    return $u ?: null;
}

// Суточный рефилл: 900 токенов раз в 24 часа, если не оплачен аккаунт
function daily_refill_tokens(mysqli $conn, int $uid): int {
    $st = $conn->prepare('SELECT tokens, tokens_refilled_at, paid_until FROM site_users WHERE id = ? LIMIT 1');
    $st->bind_param('i', $uid);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$u) return 0;
    if (!empty($u['paid_until']) && strtotime((string)$u['paid_until']) > time()) {
        return (int)$u['tokens'];
    }
    $last = $u['tokens_refilled_at'] ? strtotime((string)$u['tokens_refilled_at']) : 0;
    if ($last && (time() - $last) < 86400) return (int)$u['tokens'];
    $st = $conn->prepare('UPDATE site_users SET tokens = ?, tokens_refilled_at = NOW() WHERE id = ?');
    $refill = (int)$u['tokens'] + 900;
    $st->bind_param('ii', $refill, $uid);
    $st->execute();
    $st->close();
    return $refill;
}

function client_ip(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

// Стабильный отпечаток клиента: IP + соль + опциональный hint из браузера
function client_fingerprint(string $ip, string $hint): string {
    return hash('sha256', $ip . '|' . $hint . '|fapa-cam');
}

function client_blocked(mysqli $conn, string $ip, string $uiq, string $fp, string $uid, string $fpHint): bool {
    return false; // сайт общедоступный, IP-блокировки отключены
}

// ── Telegram-хелперы: TG_NOTIFY_BOT = "<token>:<chat_id>" ──
function _tg_send(string $text): bool {
    $cfg = getenv('TG_NOTIFY_BOT') ?: '';
    if (!$cfg || !str_contains($cfg, ':')) return false;
    [$token, $chat] = explode(':', $cfg, 2);
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => $text],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $ok = curl_exec($ch) !== false;
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
    return $ok;
}

if (!function_exists("tg_notify")) {
function tg_notify(string $text): bool { return _tg_send($text); }
function tg_notify_error(string $msg): bool { return _tg_send('❌ ' . $msg); }
function tg_gen_notify(array $d): bool { return _tg_send('🎨 gen: ' . json_encode($d, JSON_UNESCAPED_UNICODE)); }
function tg_notify_new_session(string $ip, string $ua): bool { return _tg_send("🆕 session: {$ip} " . mb_substr($ua, 0, 80)); }
function tg_notify_result(array $j, array $src, array $res, string $m = ''): bool {
    return _tg_send('✅ result: ' . ($j['job_id'] ?? '?') . " via {$m}");
}
}
