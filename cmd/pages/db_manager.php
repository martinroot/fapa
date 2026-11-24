<?php
// pages/db_manager.php
/** @var string $DB_NAME */

$message    = '';
$schemaJson = '';

/**
 * Сброс только видео-таблиц для нового импорта:
 *  - users
 *  - videos
 *  - tags
 *  - video_tags
 *  - video_views
 *  - video_likes
 *
 * Таблицу click и остальное не трогаем.
 *
 * @param mysqli $conn
 * @param string $dbName
 * @return void
 */
function reset_fapaio_video_tables(mysqli $conn, string $dbName): void
{
    // убедимся, что работаем с нужной БД
    if ($conn->select_db($dbName) === false) {
        throw new RuntimeException('Не удалось выбрать БД: ' . $dbName . ' — ' . $conn->error);
    }

    // отключаем внешние ключи, чтобы спокойно дропать
    $conn->query("SET FOREIGN_KEY_CHECKS=0");

    $tables = [
        'video_views',
        'video_likes',
        'video_tags',
        'tags',
        'videos',
        'users',
    ];

    foreach ($tables as $t) {
        $sql = "DROP TABLE IF EXISTS `$t`";
        if (!$conn->query($sql)) {
            // не кидаем фатал, просто логично
            error_log("reset_fapaio_video_tables: ошибка DROP TABLE $t: " . $conn->error);
        }
    }

    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    // если есть функция ensure_fapaio_schema — переинициализируем схему
    if (function_exists('ensure_fapaio_schema')) {
        ensure_fapaio_schema($conn);
    }
}

/**
 * --- ПЕРСОНАЛИЗАЦИЯ ---
 * Таблицы для учёта поведения пользователя:
 *  - user_video_stats  (просмотры, % досмотра, лайк)
 *  - user_tag_score    (веса тегов для рекомендаций)
 */
function ensure_personalization_schema(mysqli $conn): void
{
    // user_video_stats
    $sql1 = "
        CREATE TABLE IF NOT EXISTS `user_video_stats` (
          `user_id`       VARCHAR(64) NOT NULL,
          `video_id`      INT NOT NULL,
          `views`         INT NOT NULL DEFAULT 0,
          `max_watch_pct` TINYINT UNSIGNED NOT NULL DEFAULT 0,
          `liked`         TINYINT(1) NOT NULL DEFAULT 0,
          `last_view_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`user_id`, `video_id`),
          KEY `idx_uvs_last_view` (`last_view_at`),
          KEY `idx_uvs_video` (`video_id`)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci;
    ";
    if (!$conn->query($sql1)) {
        error_log('ensure_personalization_schema: error creating user_video_stats: ' . $conn->error);
    }

    // user_tag_score
    $sql2 = "
        CREATE TABLE IF NOT EXISTS `user_tag_score` (
          `user_id`  VARCHAR(64) NOT NULL,
          `tag_id`   INT NOT NULL,
          `score`    FLOAT NOT NULL DEFAULT 0,
          `last_upd` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`user_id`, `tag_id`),
          KEY `idx_uts_score` (`score`),
          KEY `idx_uts_tag` (`tag_id`)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci;
    ";
    if (!$conn->query($sql2)) {
        error_log('ensure_personalization_schema: error creating user_tag_score: ' . $conn->error);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_db') {
        $dbName = trim($_POST['db_name'] ?? '');
        if ($dbName !== '') {
            $conn = db_connect_no_db();
            if ($conn->query("CREATE DATABASE `" . $conn->real_escape_string($dbName) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
                $message = "База данных '{$dbName}' создана.";
            } else {
                $message = "Ошибка создания базы '{$dbName}': " . $conn->error;
            }
            $conn->close();
        } else {
            $message = "Укажите имя базы данных.";
        }
    }

    if ($action === 'drop_db') {
        $dbName = trim($_POST['db_name'] ?? '');
        if ($dbName !== '') {
            $conn = db_connect_no_db();
            if ($conn->query("DROP DATABASE `" . $conn->real_escape_string($dbName) . "`")) {
                $message = "База данных '{$dbName}' удалена.";
            } else {
                $message = "Ошибка удаления базы '{$dbName}': " . $conn->error;
            }
            $conn->close();
        } else {
            $message = "Укажите имя базы для DROP.";
        }
    }

    if ($action === 'get_schema') {
        $dbName = trim($_POST['db_name_schema'] ?? $DB_NAME);
        if ($dbName === '') {
            $dbName = $DB_NAME;
        }

        $conn = db_connect($dbName);
        $schema = [];
        $dbEsc = $conn->real_escape_string($dbName);

        $sql = "
            SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = '{$dbEsc}'
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $tbl = $row['TABLE_NAME'];
                if (!isset($schema[$tbl])) {
                    $schema[$tbl] = [];
                }
                $schema[$tbl][] = [
                    'column'   => $row['COLUMN_NAME'],
                    'type'     => $row['DATA_TYPE'],
                    'nullable' => $row['IS_NULLABLE'],
                    'key'      => $row['COLUMN_KEY'],
                    'default'  => $row['COLUMN_DEFAULT'],
                    'extra'    => $row['EXTRA'],
                ];
            }
            $res->free();
        }

        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $conn->close();
    }

    // Инициализация схемы (создание всех нужных таблиц)
    if ($action === 'init_schema') {
        $dbName = trim($_POST['db_name_schema'] ?? $DB_NAME);
        if ($dbName === '') {
            $dbName = $DB_NAME;
        }

        $conn = db_connect($dbName);
        if (function_exists('ensure_fapaio_schema')) {
            ensure_fapaio_schema($conn);
            ensure_personalization_schema($conn); // <--- НОВОЕ
            $message = "Схема (users, videos, tags, video_tags, video_views, video_likes, user_video_stats, user_tag_score) создана/проверена в БД '{$dbName}'.";
        } else {
            $message = "Функция ensure_fapaio_schema() не найдена. Проверь подключение схемы.";
        }
        $conn->close();
    }

    // НОВОЕ: полный ресет видео-таблиц для быстрого переимпорта
    if ($action === 'reset_video_tables') {
        $dbName = trim($_POST['db_name_schema'] ?? $DB_NAME);
        if ($dbName === '') {
            $dbName = $DB_NAME;
        }

        try {
            $conn = db_connect($dbName);
            reset_fapaio_video_tables($conn, $dbName);
            $conn->close();
            $message = "Видео-таблицы в '{$dbName}' очищены и пересозданы. Можно делать новый импорт JSON/HLS.";
        } catch (Throwable $e) {
            $message = "Ошибка reset_video_tables: " . $e->getMessage();
        }
    }
}
?>

<h2 class="mb-4">DB manager</h2>

<?php if ($message): ?>
    <div class="alert alert-info"><?php echo h($message); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Create / Drop database</h5>

                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="create_db">
                    <div class="mb-2">
                        <label class="form-label">DB name</label>
                        <input type="text" name="db_name" class="form-control" placeholder="my_database">
                    </div>
                    <button class="btn btn-success" type="submit">Create DB</button>
                </form>

                <form method="post">
                    <input type="hidden" name="action" value="drop_db">
                    <div class="mb-2">
                        <label class="form-label">DB name (DROP)</label>
                        <input type="text" name="db_name" class="form-control" placeholder="my_database">
                    </div>
                    <button class="btn btn-danger" type="submit"
                            onclick="return confirm('Точно DROP DATABASE? ВСЁ в этой базе будет удалено, включая click.');">
                        Drop DB
                    </button>
                </form>

                <small class="text-muted d-block mt-2">
                    ВНИМАНИЕ: команды идут напрямую в MySQL, аккуратнее с DROP.
                </small>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Schema / Init / Reset</h5>

                <!-- Получить схему в JSON -->
                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="get_schema">
                    <div class="mb-2">
                        <label class="form-label">
                            DB name (по умолчанию <?php echo h($DB_NAME); ?>)
                        </label>
                        <input type="text" name="db_name_schema" class="form-control" value="<?php echo h($DB_NAME); ?>">
                    </div>
                    <button class="btn btn-primary" type="submit">Get schema JSON</button>
                </form>

                <!-- Инициализация схемы таблиц -->
                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="init_schema">
                    <div class="mb-2">
                        <label class="form-label">DB name для инициализации схемы</label>
                        <input type="text" name="db_name_schema" class="form-control" value="<?php echo h($DB_NAME); ?>">
                    </div>
                    <button class="btn btn-warning" type="submit">
                        Init fapaio schema (videos / tags / users / views / likes / personalization)
                    </button>
                </form>

                <!-- НОВОЕ: Reset video tables -->
                <form method="post">
                    <input type="hidden" name="action" value="reset_video_tables">
                    <div class="mb-2">
                        <label class="form-label">
                            DB name для сброса видео-таблиц
                        </label>
                        <input type="text" name="db_name_schema" class="form-control" value="<?php echo h($DB_NAME); ?>">
                    </div>
                    <button class="btn btn-outline-danger" type="submit"
                            onclick="return confirm('Пересоздать видео-таблицы (users, videos, tags, video_tags, video_views, video_likes) в этой БД? Таблица click не будет затронута.');">
                        Reset video tables (only video-related)
                    </button>
                </form>

                <?php if ($schemaJson): ?>
                    <hr>
                    <h6>Schema JSON:</h6>
                    <pre class="schema-json"><?php echo h($schemaJson); ?></pre>
                <?php else: ?>
                    <p class="text-muted mt-2">
                        Нажми «Get schema JSON», чтобы увидеть структуру таблиц.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
