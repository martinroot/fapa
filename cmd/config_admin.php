<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

// === DB CONFIG ===
$DB_HOST = "127.0.0.1";
$DB_USER = "pma";
$DB_PASS = "REPLACE_WITH_DB_PASSWORD";
$DB_NAME = "fapaio";

/**
 * polyfill для str_contains под старый PHP
 */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

/**
 * Подключение к конкретной базе (по умолчанию fapaio)
 */
function db_connect(?string $dbName = null): mysqli {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;

    $db = $dbName ?? $DB_NAME;

    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $db);
    if ($mysqli->connect_error) {
        die("DB connection error ({$db}): " . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

/**
 * Подключение без выбора БД (для CREATE/DROP DATABASE)
 */
function db_connect_no_db(): mysqli {
    global $DB_HOST, $DB_USER, $DB_PASS;

    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($mysqli->connect_error) {
        die("DB connection error (no DB): " . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

/**
 * Экранирование html
 */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Проверка: есть ли колонка в таблице
 */
function column_exists(mysqli $conn, string $table, string $column): bool {
    $tableEsc  = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'";
    $res = $conn->query($sql);
    if ($res) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
}

/**
 * Создание базовых таблиц под видео/теги/лайки/просмотры
 * ВАЖНО: таблицу click НЕ трогаем.
 *
 * Таблицы:
 *  - users
 *  - videos
 *  - tags
 *  - video_tags
 *  - video_views
 *  - video_likes
 */
function ensure_fapaio_schema(mysqli $conn): void
{

    // clicks — лог входящих посещений
    $conn->query("
        CREATE TABLE IF NOT EXISTS clicks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uiq VARCHAR(64) NOT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            country VARCHAR(6) DEFAULT NULL,
            page VARCHAR(255) DEFAULT 'index',
            referer TEXT DEFAULT NULL,
            ua TEXT DEFAULT NULL,
            click_id VARCHAR(255) DEFAULT '',
            browser TEXT DEFAULT NULL,
            device VARCHAR(255) DEFAULT '',
            isp VARCHAR(255) DEFAULT '',
            platform VARCHAR(255) DEFAULT '',
            language VARCHAR(64) DEFAULT '',
            campaign_id INT UNSIGNED NOT NULL DEFAULT 1,
            source_id INT UNSIGNED NOT NULL DEFAULT 0,
            cost DECIMAL(10,6) NOT NULL DEFAULT 0,
            c1 VARCHAR(255) DEFAULT '',
            c2 VARCHAR(255) DEFAULT '',
            c3 VARCHAR(255) DEFAULT '',
            c4 VARCHAR(255) DEFAULT '',
            c5 VARCHAR(255) DEFAULT '',
            external_id VARCHAR(255) DEFAULT NULL,
            external_postback TINYINT NOT NULL DEFAULT 0,
            revenue DECIMAL(10,6) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_uiq (uiq),
            KEY idx_ts (ts),
            KEY idx_country (country),
            KEY idx_campaign (campaign_id),
            KEY idx_source (source_id),
            KEY idx_external (external_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // users — авторы роликов (по полю user из JSON)
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // videos — основная таблица роликов
    $conn->query("
        CREATE TABLE IF NOT EXISTS videos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_id INT UNSIGNED NOT NULL,
            -- user_id добавим/обновим ниже через ALTER при необходимости
            username VARCHAR(255) DEFAULT NULL,
            page VARCHAR(1024) DEFAULT NULL,
            stream VARCHAR(2048) DEFAULT NULL,
            folder_id INT UNSIGNED NOT NULL,
            poster_path VARCHAR(512) DEFAULT NULL,
            m3u8_path VARCHAR(512) DEFAULT NULL,
            likes_cnt INT UNSIGNED NOT NULL DEFAULT 0,
            views_cnt INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_external (external_id),
            KEY idx_username (username),
            KEY idx_folder (folder_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Стартовое значение автоинкремента, если пусто — делаем чуть повыше
    $conn->query("ALTER TABLE videos AUTO_INCREMENT = 13800;");

    // Добавляем user_id + FK к users, если ещё нет
    if (!column_exists($conn, 'videos', 'user_id')) {
        $conn->query("ALTER TABLE videos ADD COLUMN user_id INT UNSIGNED NULL AFTER external_id");
        $conn->query("ALTER TABLE videos ADD INDEX idx_user_id (user_id)");
        // FK — если users есть, constraint повесится; если уже есть, MySQL просто ругнётся в логах, но не критично
        $conn->query("
            ALTER TABLE videos
            ADD CONSTRAINT fk_videos_user
            FOREIGN KEY (user_id) REFERENCES users(id)
            ON DELETE SET NULL
        ");
    }

    // tags
    $conn->query("
        CREATE TABLE IF NOT EXISTS tags (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_tag_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // video_tags — связь роликов с тегами
    $conn->query("
        CREATE TABLE IF NOT EXISTS video_tags (
            video_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (video_id, tag_id),
            KEY idx_tag_id (tag_id),
            CONSTRAINT fk_video_tags_video
                FOREIGN KEY (video_id) REFERENCES videos(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_video_tags_tag
                FOREIGN KEY (tag_id) REFERENCES tags(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // video_views — лог просмотров (совместимо с track_view.php)
    // track_view.php делает: INSERT INTO video_views (video_id, uiq, ip) VALUES ...
    $conn->query("
        CREATE TABLE IF NOT EXISTS video_views (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            video_id INT UNSIGNED NOT NULL,
            ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uiq VARCHAR(64) DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            country VARCHAR(2) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_video_id (video_id),
            CONSTRAINT fk_video_views_video
                FOREIGN KEY (video_id) REFERENCES videos(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // video_likes — лог лайков (совместимо с track_like.php)
    // track_like.php делает: INSERT IGNORE INTO video_likes (video_id, uiq, ip) VALUES ...
    $conn->query("
        CREATE TABLE IF NOT EXISTS video_likes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            video_id INT UNSIGNED NOT NULL,
            ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uiq VARCHAR(64) DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_video_uiq (video_id, uiq),
            KEY idx_video_id (video_id),
            CONSTRAINT fk_video_likes_video
                FOREIGN KEY (video_id) REFERENCES videos(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

/**
 * Создание таблиц для пользователей сайта, комментариев и AI-генераций.
 * Безопасно вызывать при каждом запросе (CREATE TABLE IF NOT EXISTS).
 */
function ensure_site_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS site_users (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uiq        VARCHAR(64)  NOT NULL,
            name       VARCHAR(255) NOT NULL,
            email      VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_email (email),
            KEY idx_uiq (uiq)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS video_comments (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            video_id     INT UNSIGNED NOT NULL,
            site_user_id INT UNSIGNED NULL,
            uiq          VARCHAR(64)  NOT NULL,
            display_name VARCHAR(255) NOT NULL DEFAULT 'Guest',
            text         TEXT NOT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_video_id (video_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS user_generations (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_user_id INT UNSIGNED NULL,
            uiq          VARCHAR(64)  NOT NULL,
            job_id       VARCHAR(128) NOT NULL,
            template_id  VARCHAR(64)  NOT NULL DEFAULT 'flux2-img2img',
            source_image VARCHAR(512) NULL,
            result_url   VARCHAR(512) NULL,
            status       VARCHAR(32)  DEFAULT 'queued',
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_uiq (uiq),
            KEY idx_site_user (site_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

/**
 * Получить зарегистрированного пользователя по cookie fapa_uid.
 * Возвращает ['id','name','email'] или null.
 */
function get_site_user(mysqli $conn): ?array
{
    $uid = isset($_COOKIE['fapa_uid']) ? (int)$_COOKIE['fapa_uid'] : 0;
    if ($uid <= 0) return null;

    $stmt = $conn->prepare("SELECT id, name, email FROM site_users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
