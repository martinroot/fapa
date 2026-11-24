<?php
declare(strict_types=1);

require_once __DIR__ . '/config_admin.php';

$page = $_GET['page'] ?? 'dashboard';

global $DB_NAME, $DB_USER, $DB_HOST;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Fapa.io — Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">

    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            min-height: 100vh;
            background-color: #212529;
        }
        .sidebar .nav-link {
            color: #ced4da;
        }
        .sidebar .nav-link.active,
        .sidebar .nav-link:hover {
            color: #ffffff;
            background-color: #343a40;
        }
        pre.schema-json {
            max-height: 500px;
            overflow: auto;
            background: #1e1e1e;
            color: #e9ecef;
            padding: 1rem;
            border-radius: .5rem;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav class="col-md-2 d-none d-md-block sidebar p-3">
            <h4 class="text-light mb-4">Fapa.io</h4>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"
                       href="menu.php?page=dashboard">
                        Dashboard
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'db' ? 'active' : ''; ?>"
                       href="menu.php?page=db">
                        DB manager
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'video_import' ? 'active' : ''; ?>"
                       href="menu.php?page=video_import">
                        Video import (bulk)
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'video_upload' ? 'active' : ''; ?>"
                       href="menu.php?page=video_upload">
                        Video upload (single)
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'videos' ? 'active' : ''; ?>"
                       href="menu.php?page=videos">
                        Videos list
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'clicks' ? 'active' : ''; ?>"
                       href="menu.php?page=clicks">
                        Clicks stats
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'watch_stats' ? 'active' : ''; ?>"
                       href="menu.php?page=watch_stats">
                        Watch stats (videos)
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'user_watch' ? 'active' : ''; ?>"
                       href="menu.php?page=user_watch">
                        Users watch stats
                    </a>
                </li>

                <!-- (опционально) пункт "User history" — можно оставить, чтобы подсветка работала,
                     но в целом на историю ты попадаешь кликом по user_id -->
                <li>
                    <a class="nav-link <?php echo $page === 'user_history' ? 'active' : ''; ?>"
                       href="menu.php?page=user_history">
                        User history
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'publishers' ? 'active' : ''; ?>"
                       href="menu.php?page=publishers">
                        Publishers
                    </a>
                </li>

                <li>
                    <a class="nav-link <?php echo $page === 'countries' ? 'active' : ''; ?>"
                       href="menu.php?page=countries">
                        Countries
                    </a>
                </li>

                <!-- управление GEO-ставками Kadam -->
                <li>
                    <a class="nav-link <?php echo $page === 'kadam_geo' ? 'active' : ''; ?>"
                       href="menu.php?page=kadam_geo">
                        Kadam GEO bids
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main -->
        <main class="col-md-10 ms-sm-auto col-lg-10 px-md-4 py-4">
            <?php if ($page === 'dashboard'): ?>

                <h2 class="mb-4">Dashboard</h2>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Сводка</h5>
                        <p class="card-text mb-1">
                            Текущая БД по умолчанию: <b><?php echo h($DB_NAME); ?></b>
                        </p>
                        <p class="card-text mb-0">
                            Подключение: <b><?php echo h($DB_USER); ?></b> @ <b><?php echo h($DB_HOST); ?></b>
                        </p>
                    </div>
                </div>

            <?php elseif ($page === 'db'): ?>

                <?php require __DIR__ . '/pages/db_manager.php'; ?>

            <?php elseif ($page === 'video_import'): ?>

                <?php require __DIR__ . '/pages/video_import.php'; ?>

            <?php elseif ($page === 'video_upload'): ?>

                <?php require __DIR__ . '/pages/video_upload.php'; ?>

            <?php elseif ($page === 'videos'): ?>

                <?php require __DIR__ . '/pages/videos_list.php'; ?>

            <?php elseif ($page === 'clicks'): ?>

                <?php require __DIR__ . '/pages/clicks_stats.php'; ?>

            <?php elseif ($page === 'watch_stats'): ?>

                <?php require __DIR__ . '/pages/watch_stats.php'; ?>

            <?php elseif ($page === 'user_watch'): ?>

                <?php require __DIR__ . '/pages/user_watch_stats.php'; ?>

            <?php elseif ($page === 'user_history'): ?>

                <?php require __DIR__ . '/pages/user_history.php'; ?>

            <?php elseif ($page === 'publishers'): ?>

                <?php require __DIR__ . '/pages/publishers.php'; ?>

            <?php elseif ($page === 'countries'): ?>

                <?php require __DIR__ . '/pages/countries.php'; ?>

            <?php elseif ($page === 'kadam_geo'): ?>

                <?php require __DIR__ . '/pages/kadam_geo.php'; ?>

            <?php else: ?>

                <div class="alert alert-warning">Неизвестная страница.</div>

            <?php endif; ?>
        </main>
    </div>
</div>
</body>
</html>
