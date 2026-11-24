<?php
declare(strict_types=1);

require_once __DIR__ . '/ip_guard.php';
require_once __DIR__ . '/cmd/config.php'; // db_connect(), h()

// Приоритет: кука > GET uiq от click.php > новый
$uiq = $_COOKIE["uiq"] ?? null;
if (!$uiq && !empty($_GET["uiq"])) {
    $rawUiq = preg_replace("/[^a-f0-9]/i", "", substr($_GET["uiq"], 0, 64));
    if (strlen($rawUiq) >= 8) $uiq = $rawUiq;
}
if (!$uiq) { $uiq = bin2hex(random_bytes(8)); }
if (!isset($_COOKIE["uiq"])) {
    setcookie("uiq", $uiq, time() + 31536000, "/", "", false, true);
}

$conn = db_connect();
ensure_site_schema($conn);
$siteUser = get_site_user($conn);

// Track index page visit
try {
    $tc = db_connect();
    $uiqE = $tc->real_escape_string($uiq);
    $tc->query("INSERT INTO funnel_events (event_type, uiq) VALUES ('index_visit', '$uiqE')");
    $tc->close();
} catch (Throwable $_) {}
if ($siteUser) {
    $siteUser['paid'] = !empty($siteUser['paid_until']) && strtotime($siteUser['paid_until']) > time();
}
$_colors = ['#ec4899','#a855f7','#3b82f6','#10b981','#f59e0b','#ef4444','#06b6d4','#8b5cf6'];
$avatarColor = $siteUser ? $_colors[ord(mb_strtoupper(mb_substr($siteUser['name'],0,1))) % count($_colors)] : '#ec4899';
$siteUserJson = json_encode($siteUser, JSON_UNESCAPED_UNICODE);


/**
 * Выборка видео:
 *
 * feed_score = 0.6 * global_score + 0.4 * (v.id / 40000 * 100)
 *   global_score = 0.3 * avg_watch_pct + 0.7 * like_pct
 *
 * Персональный режим:  ORDER BY is_fresh DESC, user_tag_score DESC, feed_score DESC
 * Холодный режим:      ORDER BY feed_score DESC
 *
 * $excludeIds — уже показанные video.id (дедупликация в сессии)
 */
function fetchFeedVideos(mysqli $conn, int $offset, int $limit, ?string $userId = null, array $excludeIds = []): array
{
    $videos  = [];
    $hasUser = ($userId !== null && $userId !== '');

    // Build NOT IN clause (safe — all cast to int)
    $excludeSql = '';
    if (!empty($excludeIds)) {
        $excludeSql = 'AND v.id NOT IN (' . implode(',', array_map('intval', $excludeIds)) . ')';
    }

    if ($hasUser) {
        $sql = "
            SELECT
                v.id,
                v.external_id,
                COALESCE(u.username, v.username) AS username,
                v.likes_cnt,
                v.views_cnt,
                GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR '||') AS tags_str,

                COALESCE(stats.avg_watch_pct, 0) AS avg_watch_pct,
                COALESCE(stats.viewers_cnt, 0)   AS viewers_cnt,

                CASE
                    WHEN v.views_cnt > 0 THEN (100.0 * v.likes_cnt / v.views_cnt)
                    ELSE 0
                END AS like_pct,

                -- global_score: 30% watch + 70% likes
                CASE
                    WHEN v.views_cnt > 0 THEN
                        0.3 * COALESCE(stats.avg_watch_pct, 0)
                      + 0.7 * (100.0 * v.likes_cnt / v.views_cnt)
                    ELSE
                        0.3 * COALESCE(stats.avg_watch_pct, 0)
                END AS global_score,

                -- feed_score: 60% quality (min 5 views) + 40% recency by external_id
                -- external_id: ai_porn=1010-1116, old xikfap=5000-20000, new xikfap=20000-32200
                (
                    0.6 * CASE
                        WHEN v.views_cnt >= 5 THEN
                            0.3 * COALESCE(stats.avg_watch_pct, 0)
                          + 0.7 * (100.0 * v.likes_cnt / v.views_cnt)
                        ELSE 0
                    END
                    + 0.4 * (v.external_id / 40000.0 * 100.0)
                ) AS feed_score,

                COALESCE(ut.score, 0)           AS user_tag_score,
                CASE WHEN uvs.video_id IS NULL THEN 1 ELSE 0 END AS is_fresh

            FROM videos v
            LEFT JOIN users u       ON u.id = v.user_id
            LEFT JOIN video_tags vt ON vt.video_id = v.id
            LEFT JOIN tags t        ON t.id  = vt.tag_id

            LEFT JOIN (
                SELECT
                    video_id,
                    AVG(max_watch_pct) AS avg_watch_pct,
                    COUNT(*)          AS viewers_cnt
                FROM user_video_stats
                GROUP BY video_id
            ) AS stats
                ON stats.video_id = v.id

            LEFT JOIN (
                SELECT
                    s.user_id,
                    vt2.video_id,
                    SUM(s.score) AS score
                FROM user_tag_score s
                JOIN video_tags vt2 ON vt2.tag_id = s.tag_id
                WHERE s.user_id = ?
                GROUP BY s.user_id, vt2.video_id
            ) AS ut
                ON ut.video_id = v.id AND ut.user_id = ?

            LEFT JOIN user_video_stats uvs
                ON uvs.video_id = v.id AND uvs.user_id = ?

            WHERE 1=1 $excludeSql AND v.va_score >= 0
            GROUP BY v.id
            ORDER BY
                v.va_score        DESC,
                is_fresh          DESC,
                user_tag_score    DESC,
                FLOOR(feed_score / 5) DESC,
                RAND()            DESC
            LIMIT ?, ?
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sssii', $userId, $userId, $userId, $offset, $limit);
        } else {
            error_log('fetchFeedVideos: personal query prepare failed: ' . $conn->error);
            $hasUser = false;
        }
    }

    if (!$hasUser) {
        $sql = "
            SELECT
                v.id,
                v.external_id,
                COALESCE(u.username, v.username) AS username,
                v.likes_cnt,
                v.views_cnt,
                GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR '||') AS tags_str,

                COALESCE(stats.avg_watch_pct, 0) AS avg_watch_pct,
                COALESCE(stats.viewers_cnt, 0)   AS viewers_cnt,

                CASE
                    WHEN v.views_cnt > 0 THEN (100.0 * v.likes_cnt / v.views_cnt)
                    ELSE 0
                END AS like_pct,

                -- feed_score: 60% quality (min 5 views) + 40% recency by external_id
                (
                    0.6 * CASE
                        WHEN v.views_cnt >= 5 THEN
                            0.3 * COALESCE(stats.avg_watch_pct, 0)
                          + 0.7 * (100.0 * v.likes_cnt / v.views_cnt)
                        ELSE 0
                    END
                    + 0.4 * (v.external_id / 40000.0 * 100.0)
                ) AS feed_score

            FROM videos v
            LEFT JOIN users u       ON u.id = v.user_id
            LEFT JOIN video_tags vt ON vt.video_id = v.id
            LEFT JOIN tags t        ON t.id  = vt.tag_id

            LEFT JOIN (
                SELECT
                    video_id,
                    AVG(max_watch_pct) AS avg_watch_pct,
                    COUNT(*)          AS viewers_cnt
                FROM user_video_stats
                GROUP BY video_id
            ) AS stats
                ON stats.video_id = v.id

            WHERE 1=1 $excludeSql AND v.va_score >= 0
            GROUP BY v.id
            ORDER BY v.va_score DESC, FLOOR(feed_score / 5) DESC, RAND()
            LIMIT ?, ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('ii', $offset, $limit);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $tags = [];
        if (!empty($row['tags_str'])) {
            $tags = array_filter(array_map('trim', explode('||', (string)$row['tags_str'])));
        }
        $videos[] = [
            'id'          => (int)$row['id'],
            'external_id' => (int)$row['external_id'],
            'username'    => $row['username'] ?: ('user_' . (int)$row['external_id']),
            'likes_cnt'   => (int)$row['likes_cnt'],
            'views_cnt'   => (int)$row['views_cnt'],
            'tags'        => $tags,
        ];
    }
    $stmt->close();
    return $videos;
}


// --- AJAX подгрузка ---
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $limit  = isset($_GET['limit'])  ? max(1, min(100, (int)$_GET['limit'])) : 20;

    // Exclude from JS param + DB viewed history
    $excludeIds = [];
    if (!empty($_GET['exclude'])) {
        $raw = substr($_GET['exclude'], 0, 3000);
        foreach (explode(',', $raw) as $eid) {
            $i = (int)$eid;
            if ($i > 0) $excludeIds[] = $i;
        }
    }
    // Read seen_ids cookie (set by JS, persists across sessions)
    if (!empty($_COOKIE['seen_ids'])) {
        foreach (explode(',', substr($_COOKIE['seen_ids'], 0, 6000)) as $eid) {
            $i = (int)$eid; if ($i > 0) $excludeIds[] = $i;
        }
    }
    $currentUserId = $_COOKIE['uiq'] ?? $uiq;
    $seenStmt = $conn->prepare("SELECT video_id FROM video_views WHERE uiq = ? GROUP BY video_id ORDER BY MAX(id) DESC LIMIT 2000");
    if ($seenStmt) {
        $seenStmt->bind_param('s', $currentUserId);
        $seenStmt->execute();
        $seenRes = $seenStmt->get_result();
        while ($row = $seenRes->fetch_assoc()) {
            $excludeIds[] = (int)$row['video_id'];
        }
        $seenStmt->close();
    }
    $excludeIds = array_unique($excludeIds);

    $videos = fetchFeedVideos($conn, $offset, $limit, $currentUserId, $excludeIds);

    // Recycle: пул непросмотренного исчерпан — отдаём старое вперемешку, фид не умирает
    $recycled = false;
    if (empty($videos)) {
        $videos = fetchFeedVideos($conn, 0, $limit, $currentUserId, []);
        shuffle($videos);
        $recycled = true;
    }

    // Mark returned videos seen in DB immediately (so F5 excludes them)
    if (!empty($videos)) {
        $_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $_s = $conn->prepare('INSERT IGNORE INTO video_views (video_id, uiq, ip) VALUES (?, ?, ?)');
        if ($_s) {
            foreach ($videos as $_v) { $_id = (int)$_v['id']; $_s->bind_param('iss', $_id, $currentUserId, $_ip); $_s->execute(); }
            $_s->close();
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'ok',
        'offset' => $offset,
        'count'  => count($videos),
        'videos' => $videos,
        'recycled' => $recycled,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Первый чанк: exclude videos this user already viewed (from DB) ---
$initialLimit   = 20;
$initialExclude = [];
// Read seen_ids cookie (persists across sessions, set by JS)
if (!empty($_COOKIE['seen_ids'])) {
    foreach (explode(',', substr($_COOKIE['seen_ids'], 0, 6000)) as $eid) {
        $i = (int)$eid; if ($i > 0) $initialExclude[] = $i;
    }
}
$seenStmt = $conn->prepare("SELECT video_id FROM video_views WHERE uiq = ? GROUP BY video_id ORDER BY MAX(id) DESC LIMIT 2000");
if ($seenStmt) {
    $seenStmt->bind_param('s', $uiq);
    $seenStmt->execute();
    $seenRes = $seenStmt->get_result();
    while ($row = $seenRes->fetch_assoc()) {
        $initialExclude[] = (int)$row['video_id'];
    }
    $seenStmt->close();
}

// For brand-new users (no DB views yet) prepend starter playlist
$isNewUser = (count($initialExclude) === 0);
$starterPrepend = [];
if ($isNewUser) {
    $sr = $conn->query("SELECT id, external_id, username, likes_cnt, views_cnt, NULL as tags_str, 0 as avg_watch_pct, 0 as viewers_cnt, 0 as like_pct, 0 as feed_score FROM videos WHERE starter_rank IS NOT NULL AND va_score >= 0 ORDER BY starter_rank ASC LIMIT 15");
    if ($sr) {
        while ($sv = $sr->fetch_assoc()) {
            $sv['tags'] = [];
            $starterPrepend[] = $sv;
            $initialExclude[] = (int)$sv['id'];
        }
    }
}

$remaining      = $initialLimit - count($starterPrepend);
$regularVideos  = $remaining > 0 ? fetchFeedVideos($conn, 0, $remaining, $uiq, $initialExclude) : [];

// Recycle в первом чанке: всё просмотрено — отдаём старое вперемешку
if (empty($regularVideos) && $remaining > 0) {
    $regularVideos = fetchFeedVideos($conn, 0, $remaining, $uiq, []);
    shuffle($regularVideos);
}
$initialVideos  = array_merge($starterPrepend, $regularVideos);
$hasMoreInitial = (count($regularVideos) === $remaining);

// View limit removed — gating is handled client-side by swipe count
$freeViewsExceeded = false;

// Mark initial videos seen in DB immediately so F5 excludes them reliably.
// real_view stays 0 here (preload, not a watch) — analytics counts real_view=1 only
if (!empty($initialVideos) && !$freeViewsExceeded) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $insStmt = $conn->prepare("INSERT IGNORE INTO video_views (video_id, uiq, ip) VALUES (?, ?, ?)");
    if ($insStmt) {
        foreach ($initialVideos as $iv) {
            $vid = (int)$iv['id'];
            $insStmt->bind_param('iss', $vid, $uiq, $ip);
            $insStmt->execute();
        }
        $insStmt->close();
    }
}

$conn->close();

$videosJson = json_encode($initialVideos, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Fapa — Swipe NSFW Videos</title>
  <meta name="description" content="Swipe short videos like TikTok. Fresh clips, creators and tags." />
  <meta name="theme-color" content="#000000" />
  <meta name="referrer" content="no-referrer-when-downgrade" />

  <link rel="manifest" href="/manifest.json">

  <!-- iOS -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="apple-touch-icon" href="/assets/pwa/apple-touch-icon.png">

  <!-- ═══ EARLY: трекинг + Метрика + реклама ДО тяжёлых блокирующих скриптов.
       Попандер-вкладка в фоне душится; если ждать тела страницы, ~40% кликов
       не успевают выполнить JS (у нас было 29% живых против 69% у тюба). ═══ -->
  <script>
  (function(){try{var m=location.search.match(/[?&]cid=(\d+)/);if(m&&m[1]){var d='cid='+m[1];if(navigator.sendBeacon){navigator.sendBeacon('/scripts/track_js.php',new URLSearchParams(d));}else{var x=new XMLHttpRequest();x.open('POST','/scripts/track_js.php');x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');x.send(d);}}}catch(e){}})();
  </script>
  <script type="text/javascript">
    (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();for(var j=0;j<e.scripts.length;j++){if(e.scripts[j].src===r){return;}}k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,'script','https://mc.yandex.ru/metrika/tag.js','ym');
    ym(97866307,'init',{clickmap:true,accurateTrackBounce:true,trackLinks:true});
  </script>
  <script src="/scripts/ads.js?v=clean1" id="ads-js"></script>

  <script src="/js/hls.min.js"></script>
  <script src="/js/tailwind.min.js"></script>
  <link rel="stylesheet" href="/css/swiper-bundle.min.css"/>
  <script src="/js/swiper-bundle.min.js"></script>

  <!-- вынесенный CSS -->
  <link rel="stylesheet" href="/css/feed.css?v=1">
  <style>
    #rightPanel button { background: transparent; border: 0; padding: 0; }
    #rightPanel img { -webkit-user-drag: none; user-select: none; -webkit-user-select: none; }
  </style>
<script defer src="https://vibemetrics.click/tracker.js" data-site-id="fapa-cam" data-site-key="7b8cH_grFS_eZEm3Itb4Mh-hW28sjhia_WegOQDRd_k"></script>
</head>

<body class="bg-black text-white overflow-hidden">

  <div class="swiper">
    <div class="swiper-wrapper" id="swiperWrapper"></div>
  </div>

  <!-- Logo -->
  <div id="upPanel">
    <a href="/" class="block">
      <img src="/assets/logo.png" alt="Fapa" style="max-width:90px;height:auto;display:block;">
    </a>
  </div>

  <!-- Personalized banner -->
  <div id="personalizedBanner" class="hidden">
    <div id="personalizedBannerIcon">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="#fff">
        <path d="M12 2l2.39 4.84L20 7.27l-3.8 3.7.9 5.26L12 14.77 6.9 16.23l.9-5.26L4 7.27l5.61-.43L12 2z"/>
      </svg>
    </div>
    <span id="personalizedText" data-i18n="personalized_text">
      10 videos watched — your feed is now personalized
    </span>
  </div>

  <!-- Confetti -->
  <div id="confettiContainer" class="hidden"></div>

  <!-- Video info -->
  <div id="videoInfo"
       class="absolute left-1/2 -translate-x-1/2 w-full max-w-[575px] pl-2 pr-20 z-50 safe-offset-16"></div>

  <!-- Right column -->
  <div id="rightPanel"
       class="absolute flex flex-col gap-5 items-center z-50 safe-offset-16"
       style="right: calc(50% - 277px); will-change: transform;">
    <div class="flex flex-col items-center">
      <img src="/assets/heart.gif" class="likeBtn w-8 cursor-pointer" alt="Like">
      <p class="likesCount text-sm" style="text-shadow: 0 1px 2px rgba(0,0,0,0.5);">0</p>
    </div>
    <img src="/assets/arrow.png" class="shareBtn w-8 cursor-pointer" alt="Share">
    <button id="muteBtn" type="button" aria-label="Mute"
        class="w-8 h-8 flex items-center justify-center"
        style="touch-action: manipulation; -webkit-tap-highlight-color: transparent;">
      <img id="muteIcon" src="/assets/mute.png" class="w-8 h-8" alt="Mute" draggable="false">
    </button>
    <div class="flex flex-col items-center">
      <button class="commentBtn w-8 h-8 flex items-center justify-center" type="button" aria-label="Comments"
              style="touch-action: manipulation; -webkit-tap-highlight-color: transparent;">
        <img src="/assets/message.png" class="w-7 h-7 object-contain" alt="Comments" draggable="false">
      </button>
      <p class="commentsCount text-xs" style="text-shadow: 0 1px 2px rgba(0,0,0,0.5);">0</p>
    </div>
    <a href="/gen.php" id="genNavBtn" class="w-8 h-8 flex items-center justify-center" aria-label="AI Generator"
       style="font-size:26px;text-decoration:none;filter:drop-shadow(0 1px 2px rgba(0,0,0,.5));touch-action:manipulation;-webkit-tap-highlight-color:transparent"
       onclick="try{fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_menu_click'})})}catch(e){}">✨</a>
  </div>

  <!-- Swipe hint circle -->
  <div id="swipeHint" class="hidden">
    <div id="swipeHintCircle">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <path d="M12 3l-6.5 6.5 1.4 1.4L11 6.8V21h2V6.8l4.1 4.1 1.4-1.4z"/>
      </svg>
    </div>
  </div>

  <!-- Like hint bubble -->
  <div id="likeHint" class="hidden">
    <div id="likeHintInner">
      <div id="likeHintHeart">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
          <path d="M12.1 4.53 12 4.65l-.1-.12C9.24 2.24 5.5 2.62 3.24 4.88 1 7.12 1.09 10.77 3.52 13.2l7.07 7.07a1 1 0 0 0 1.42 0l7.07-7.07c2.43-2.43 2.52-6.08.28-8.32-2.26-2.26-6-2.64-8.26-.35z"/>
        </svg>
      </div>
      <span id="likeHintText" data-i18n="like_hint">Like videos – AI tunes your feed</span>
    </div>
  </div>

  <!-- Progress -->
  <div id="progressWrapper"
       class="absolute left-1/2 transform -translate-x-1/2 w-full max-w-[575px] pl-2 pr-2 -mt-[15px] z-50 progress-wrapper">
    <div id="progressBar" class="h-1 bg-gray-500 relative">
      <div id="progress" class="h-1 bg-white w-0"></div>
      <div id="progressDot" class="absolute -top-0 w-1 h-1 bg-white rounded-full left-0 cursor-pointer"></div>
    </div>
  </div>

  <!-- Bottom bar: [Home] [Gen] [Profile] [Search] -->
<div class="safe-bottom bottom-bar">
  <a href="/" class="nav-item"><img src="/assets/home.png" class="w-6 h-6 object-contain" alt="Home"></a>
  <a href="/gen.php" class="nav-item">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke-width="1.8" stroke-linecap="round" style="stroke:#ec4899;">
      <polygon points="12,2 14.5,9.5 22,12 14.5,14.5 12,22 9.5,14.5 2,12 9.5,9.5" stroke="#ec4899" fill="rgba(236,72,153,0.25)"/>
    </svg>
  </a>

  <a href="/profile.php" class="nav-item">
    <div class="profile-nav-avatar<?php echo $siteUser ? ' logged-in' : ''; ?>"
         style="<?php echo $siteUser ? 'background:linear-gradient(135deg,'.$avatarColor.',rgba(168,85,247,0.6));' : ''; ?>">
      <?php if ($siteUser): ?>
        <span style="font-size:15px;font-weight:900;"><?php echo h(mb_strtoupper(mb_substr($siteUser['name'], 0, 1))); ?></span>
      <?php else: ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      <?php endif; ?>
    </div>
  </a>
  <a href="/s.php" class="nav-item"><img src="/assets/search.png" class="w-6 h-6 object-contain" alt="Search"></a>
  <button id="pwaInstallNavBtn" class="nav-item" style="display:none;background:none;border:none;cursor:pointer;padding:0;" aria-label="Install app">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <rect x="5" y="2" width="14" height="20" rx="2"/>
      <path d="M12 7v6M9 10l3 3 3-3"/>
      <circle cx="12" cy="18" r="1" fill="rgba(255,255,255,0.7)" stroke="none"/>
    </svg>
  </button>
</div>
<script>
(function(){
  var _pwaPrompt = null;
  window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault(); _pwaPrompt = e;
    var btn = document.getElementById('pwaInstallNavBtn');
    if (btn) btn.style.display = 'flex';
  });
  window.addEventListener('appinstalled', function() {
    var btn = document.getElementById('pwaInstallNavBtn');
    if (btn) btn.style.display = 'none';
  });
  document.addEventListener('click', function(e) {
    if (e.target.closest('#pwaInstallNavBtn') && _pwaPrompt) {
      _pwaPrompt.prompt();
      _pwaPrompt.userChoice.then(function(r) { if (r.outcome === 'accepted') _pwaPrompt = null; });
    }
  });
})();
</script>

  <div id="toast"
       class="fixed bottom-40 left-1/2 transform -translate-x-1/2 bg-pink-600 text-white text-sm px-4 py-2 rounded-full shadow z-50 hidden"
       data-i18n="toast_copied">
    Link copied to clipboard!
  </div>

  <!-- VPN Overlay -->
  <div id="vpnOverlay" aria-hidden="true">
    <div id="vpnModal" class="vpnPopIn" role="dialog" aria-modal="true" aria-labelledby="vpnTitle">
      <div id="vpnModalInner">

        <div id="vpnModalTop">
          <div id="vpnTopLeft">
            <div id="vpnLogoWrap">
              <img id="vpnLogoImg"
                   src="/assets/200x200ia-75.webp"
                   alt="Logo"
                   width="96" height="96">
            </div>

            <div id="vpnText">
              <div id="vpnTitle" data-i18n="vpn_title">Warning: Prohibited Content</div>
              <div id="vpnSub" data-i18n="vpn_sub">
                For your safety, use StrongNET VPN. With IP VPN full access to private videos, unlimited access, and ad-free access to 74,000 videos.
              </div>
            </div>
          </div>

          <button id="vpnCloseBtn" class="disabled" type="button" aria-label="Close">
            <span id="vpnCloseCountdown">3</span>
            <svg id="vpnCloseX" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.4 4.29 19.71 2.88 18.29 9.17 12 2.88 5.71 4.29 4.29l6.3 6.3 6.29-6.3z"/>
            </svg>
          </button>
        </div>

        <div id="vpnBadges" aria-label="Categories">
          <span class="vpnBadge" data-i18n="vpn_badge_bdsm">🔒 BDSM</span>
          <span class="vpnBadge" data-i18n="vpn_badge_leaks">🔒 Incest</span>
          <span class="vpnBadge" data-i18n="vpn_badge_gay">🔒 Gay</span>
          <span class="vpnBadge" data-i18n="vpn_badge_trans">🔒 Trans</span>
          <span class="vpnBadge" data-i18n="vpn_badge_ai">🔒 AI porn</span>
        </div>

        <div id="vpnCtaRow">
          <button id="vpnInstallBtn" type="button" data-i18n="vpn_install">Install VPN</button>
        </div>

      </div>
    </div>
  </div>

  <!-- PWA Overlay (non-iOS) -->
  <div id="pwaOverlay" aria-hidden="true">
    <div id="pwaModal" role="dialog" aria-modal="true">
      <div id="pwaModalInner">

        <button id="pwaCloseBtn" type="button" aria-label="Close">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.4 4.29 19.71 2.88 18.29 9.17 12 2.88 5.71 4.29 4.29l6.3 6.3 6.29-6.3z"/>
          </svg>
        </button>

        <div id="pwaTop">
          <div id="pwaTopInner">
            <div id="pwaLogo">
              <img src="/assets/logo.png" alt="Fapa" style="max-width:48px;height:auto">
            </div>

            <div>
              <div id="pwaTitle" data-i18n="pwa_title">Do you like Fapa?</div>
              <div id="pwaSub" data-i18n="pwa_sub">
                Install it on your phone. Porn-TikTok — the best app in your pocket. No cards, payments, or ads!
              </div>
            </div>

            <div id="pwaBadges" aria-label="Benefits">
              <span class="pwaBadge" data-i18n="pwa_badge_1">⚡ Fast</span>
              <span class="pwaBadge" data-i18n="pwa_badge_2">📲 App mode</span>
              <span class="pwaBadge" data-i18n="pwa_badge_3">🚫 No ads</span>
              <span class="pwaBadge" data-i18n="pwa_badge_4">💳 No payments</span>
            </div>

            <button id="pwaInstallBtn" type="button" data-i18n="pwa_install">Install</button>
          </div>
        </div>

      </div>
    </div>
  </div>


  <!-- Sound hint overlay (shown after 5 swipes, once) -->
  <div id="soundHintOverlay" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9000;max-width:320px;width:calc(100% - 40px)">
    <div style="background:rgba(20,20,30,.96);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:16px 18px;display:flex;gap:12px;align-items:center;box-shadow:0 8px 32px rgba(0,0,0,.5)">
      <div style="font-size:32px;flex-shrink:0">🔊</div>
      <div style="flex:1">
        <div data-i18n="sound_hint_title" style="color:#fff;font-weight:700;font-size:14px;margin-bottom:4px">Enable sound?</div>
        <div data-i18n="sound_hint_body" style="color:rgba(255,255,255,.5);font-size:12px">Videos are much better with sound</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
        <button id="soundHintYes" style="background:#ec4899;border:none;color:#fff;border-radius:10px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer" data-i18n="sound_hint_yes">Enable</button>
        <button id="soundHintNo"  style="background:rgba(255,255,255,.08);border:none;color:rgba(255,255,255,.5);border-radius:10px;padding:6px 14px;font-size:12px;cursor:pointer" data-i18n="sound_hint_no">No thanks</button>
      </div>
    </div>
  </div>

  <!-- BOOT DATA -->
  <script>
    window.__FAPA_BOOT__ = {
      HAS_MORE_INIT: <?php echo $hasMoreInitial ? 'true' : 'false'; ?>,
      videos: <?php echo $videosJson ?: '[]'; ?>,
      pageSize: 20,
      siteUser: <?php echo $siteUserJson ?: 'null'; ?>,
      freeViewsExceeded: <?php echo !empty($freeViewsExceeded) ? 'true' : 'false'; ?>
    };
  </script>

  <!-- JS (порядок важен) -->
  <script src="/js/state.js?v=6" defer></script>
<?php $__lang = detect_lang(); ?>
  <script src="/lang/<?php echo $__lang; ?>.js?v=13"></script>
  <script>window.__langCode='<?php echo $__lang; ?>';</script>
    <script src="/js/i18n.js?v=4" defer></script>
  <script src="/js/tracking.js?v=3" defer></script>
  <script src="/js/player.js?v=2" defer></script>
  <script src="/js/ui.js?v=32" defer></script>
  <script src="/js/app.js?v=9" defer></script>

  <!-- ══════════ COMMENT DRAWER ══════════ -->
  <div id="commentOverlay" aria-hidden="true"></div>
  <div id="commentDrawer" aria-label="Comments" role="dialog">
    <div id="commentDrawerHandle"></div>
    <div id="commentDrawerHeader">
      <span id="commentDrawerTitle">Comments</span>
      <button id="commentDrawerClose" type="button" aria-label="Close">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div id="commentList"></div>
    <div id="commentInputArea">
      <div id="commentNotAuth">
        <span>Sign in to comment</span>
        <button id="commentSignInBtn" type="button">Sign in</button>
      </div>
      <div id="commentForm" style="display:none;">
        <input id="commentInput" type="text" placeholder="Add a comment…" maxlength="500" autocomplete="off">
        <button id="commentSendBtn" type="button" aria-label="Send">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>
        </button>
      </div>
    </div>
  </div>

  <!-- ══════════ REGISTRATION MODAL ══════════ -->
  <div id="authOverlay" aria-hidden="true"></div>
  <div id="authModal" role="dialog" aria-modal="true" aria-labelledby="authTitle">
    <button id="authClose" type="button" aria-label="Close">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
    <div id="authLogo">
      <img src="/assets/logo.png" alt="Fapa" style="max-width:90px;height:auto">
    </div>
    <h2 id="authTitle" data-i18n="auth_title">Get 50 more free videos</h2>
    <p id="authSub" data-i18n="auth_sub">Enter your email — instant access, no password</p>
    <div id="authBenefits">
      <span data-i18n="auth_benefit_1">🎬 50 videos free</span>
      <span data-i18n="auth_benefit_2">❤️ Likes &amp; history</span>
      <span data-i18n="auth_benefit_3">🧠 Personal feed</span>
    </div>
    <form id="authForm" autocomplete="on">
      <input id="authEmail" type="email" name="email" placeholder="Email" maxlength="255" required autocomplete="email" data-i18n-placeholder="auth_email_ph">
      <button id="authSubmitBtn" type="submit" data-i18n="auth_submit">Get 50 videos →</button>
      <p id="authError" style="display:none;"></p>
    </form>
  </div>

  <!-- Yandex.Metrika: init перенесён в <head> (стреляет до тяжёлых скриптов) -->


  <!-- ══════ TELEGRAM SUBSCRIBE BANNER ══════ -->
  <div id="tgBannerOverlay" aria-hidden="true" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.8);align-items:center;justify-content:center;padding:16px">
    <div role="dialog" aria-modal="true" aria-labelledby="tgBannerTitle" style="background:#1c1c1e;border-radius:18px;padding:28px 24px 24px;max-width:340px;width:100%;position:relative;box-shadow:0 12px 40px rgba(0,0,0,.7)">
      <button id="tgBannerClose" type="button" aria-label="Close" style="position:absolute;top:12px;right:14px;background:none;border:none;color:#666;font-size:22px;cursor:pointer;line-height:1;padding:4px">&#x2715;</button>
      <div style="text-align:center;margin-bottom:14px">
        <svg width="52" height="52" viewBox="0 0 52 52" fill="none"><circle cx="26" cy="26" r="26" fill="#229ED9"/><path d="M37.3 16.1l-4.7 22c-.3 1.5-1.3 1.9-2.6 1.2l-7-5.1-3.4 3.2c-.4.4-.7.7-1.4.7l.5-7.1L34 19.1c.6-.5 0-.8-.9-.3L12 29.7l-6.7-2.1c-1.5-.5-1.5-1.4.3-2.1L35.7 14.7c1.2-.4 2.3.3 1.6 1.4z" fill="#fff"/></svg>
      </div>
      <h3 id="tgBannerTitle" data-i18n="tg_title" style="color:#fff;font-size:17px;font-weight:700;text-align:center;margin:0 0 10px;line-height:1.3">Subscribe to Telegram &mdash; Free Access</h3>
      <p data-i18n="tg_body" style="color:#999;font-size:13px;text-align:center;margin:0 0 20px;line-height:1.5">To use the site for free &mdash; subscribe to our Telegram channel.</p>
      <a id="tgBannerBtn" href="https://t.me/+T_0TM43EDlgRXPrt" target="_blank" rel="noopener noreferrer" data-i18n="tg_btn" style="display:block;background:#229ED9;color:#fff;font-weight:700;font-size:15px;text-align:center;padding:14px 16px;border-radius:12px;text-decoration:none;letter-spacing:.3px">Subscribe for Free &#x2708;&#xFE0F;</a>
    </div>
  </div>
  <!-- /TELEGRAM SUBSCRIBE BANNER -->
  <!-- <script src="/js/tg_banner.js" defer></script> -->




  <!-- ══════ GEN AD BANNER ══════ -->
  <div id="genBannerOverlay" aria-hidden="true" style="display:none;position:fixed;inset:0;z-index:9900;background:rgba(0,0,0,.88);align-items:center;justify-content:center;padding:16px">
    <div role="dialog" aria-modal="true" style="background:#1a1a1e;border-radius:20px;padding:20px 18px 18px;max-width:320px;width:100%;position:relative;box-shadow:0 16px 48px rgba(0,0,0,.8);overflow:hidden">
      <div style="text-align:center;margin-bottom:10px">
        <span style="font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#ec4899;opacity:.9">AI Photo Generator</span>
      </div>
      <!-- Before / After -->
      <div style="display:flex;gap:6px;border-radius:12px;overflow:hidden;height:190px;margin-bottom:12px">
        <div style="flex:1;position:relative">
          <img src="/showcase/demo_girl_new.jpg" style="width:100%;height:100%;object-fit:cover" loading="lazy" alt="Before">
          <div style="position:absolute;bottom:5px;left:5px;background:rgba(0,0,0,.6);color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:20px">BEFORE</div>
        </div>
        <div style="flex:1;position:relative">
          <img src="/showcase/gen_1783626085_44d7e7.png" style="width:100%;height:100%;object-fit:cover" loading="lazy" alt="After">
          <div style="position:absolute;bottom:5px;right:5px;background:rgba(236,72,153,.8);color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:20px">AFTER ✨</div>
        </div>
      </div>
      <p style="color:#fff;font-size:15px;font-weight:700;text-align:center;margin:0 0 6px;line-height:1.3"><span data-i18n="gen_title">AI transforms your photo</span></p>
      <p style="color:#999;font-size:12px;text-align:center;margin:0 0 16px;line-height:1.4"><span data-i18n="gen_body">Upload a selfie — AI generates a stunning result in 2–3 min. Free!</span></p>
      <a href="/gen.php" id="genBannerGo" style="display:flex;align-items:center;justify-content:center;gap:8px;background:linear-gradient(135deg,#ec4899,#a855f7);color:#fff;font-weight:800;font-size:16px;text-align:center;padding:13px 16px;border-radius:13px;text-decoration:none;margin-bottom:8px">
        <span data-i18n="gen_go">Try free</span>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </a>
      <button id="genBannerLater" style="width:100%;background:none;border:none;color:#aaa;font-size:13px;padding:8px;cursor:pointer;transition:color .3s"><span data-i18n="gen_later">Later</span> <span id="genBannerTimer" style="font-size:11px;color:#444"></span></button>
    </div>
  </div>
  <!-- /GEN AD BANNER -->



  <!-- Paywall: redirects to /premium.php -->

</body>
</html>