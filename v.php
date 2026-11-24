<?php
declare(strict_types=1);

require_once __DIR__ . '/cmd/config.php'; // db_connect(), h()

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Bad video id';
    exit;
}

$conn = db_connect();

$sql = "
    SELECT 
        v.id,
        v.external_id,
        COALESCE(u.username, v.username) AS username,
        v.likes_cnt,
        v.views_cnt,
        GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR '||') AS tags_str
    FROM videos v
    LEFT JOIN users u   ON u.id  = v.user_id
    LEFT JOIN video_tags vt ON vt.video_id = v.id
    LEFT JOIN tags t    ON t.id  = vt.tag_id
    WHERE v.id = ?
    GROUP BY v.id
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo 'DB error (prepare)';
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$video = $res->fetch_assoc();
$stmt->close();
$conn->close();

if (!$video) {
    http_response_code(404);
    echo 'Video not found';
    exit;
}

$tags = [];
if (!empty($video['tags_str'])) {
    $tags = array_filter(array_map('trim', explode('||', $video['tags_str'])));
}

$videoPayload = [
    'id'          => (int)$video['id'],
    'external_id' => (int)$video['external_id'],
    'username'    => $video['username'] ?: ('user_' . (int)$video['external_id']),
    'likes_cnt'   => (int)$video['likes_cnt'],
    'views_cnt'   => (int)$video['views_cnt'],
    'tags'        => $tags,
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Fapa — video #<?php echo (int)$video['external_id']; ?></title>
  <meta name="description" content="Watch short NSFW videos by creators and tags." />
  <meta name="theme-color" content="#000000" />
  <meta name="referrer" content="no-referrer-when-downgrade" />
  <link rel="manifest" href="/manifest.json">

  <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
  <script src="/scripts/ads.js" id="ads-js"></script>

  <style>
  html, body {
    height: 100%;
    margin: 0;
    padding: 0;
    overflow: hidden;
    overscroll-behavior: none;
    touch-action: none;
  }
  .swiper {
    width: 100%;
    height: 100%;
  }
  .swiper-slide { display: flex; justify-content: center; height: 100%; }

  @media (max-width: 574px) {
    #rightPanel { right: 10px !important; }
  }

  :root { --safe-bottom: env(safe-area-inset-bottom, 0px); }
  :root { --bottom-ui: 64px; }

  .safe-offset-16 { bottom: calc(var(--bottom-ui) + 16px); }

  .safe-bottom {
    bottom: 0;
    padding-bottom: 12px;
  }
  @supports (padding: max(0px)) {
    .safe-bottom { padding-bottom: max(var(--safe-bottom), 12px); }
  }
  .bottom-bar {
    display:flex; align-items:center; justify-content:space-around;
    min-height:56px;
    padding-top:8px;
    padding-bottom:max(env(safe-area-inset-bottom,0px),8px);
  }
  .bottom-bar a {
    min-width:44px; min-height:44px;
    display:flex; align-items:center; justify-content:center;
  }
  .progress-wrapper {
    bottom: var(--bottom-ui);
    margin-bottom: 1px;
  }

  /* === НОВЫЙ стиль для панели с логотипом === */
  #upPanel {
    position: fixed;
    top: 12px;
    left: 50%;
    transform: translateX(-50%);
    width: 100%;
    max-width: 575px;
    padding: 0 12px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    pointer-events: none; /* чтобы не блокировать свайпы */
    z-index: 50;
  }
  #upPanel a {
    pointer-events: auto; /* клик по логотипу включён */
  }
  </style>
<script defer src="https://vibemetrics.click/tracker.js" data-site-id="fapa-cam" data-site-key="7b8cH_grFS_eZEm3Itb4Mh-hW28sjhia_WegOQDRd_k"></script>
</head>
<body class="bg-black text-white overflow-hidden">

  <div class="swiper">
    <div class="swiper-wrapper" id="swiperWrapper"></div>
  </div>

  <!-- Лого: всегда в пределах 575px контейнера -->
  <div id="upPanel">
    <a href="/" class="block">
      <img src="/assets/logo.png"
           alt="Fapá"
           class="block"
           style="max-width:90px; height:auto; display:block;">
    </a>
  </div>

  <!-- Инфо по видео -->
  <div id="videoInfo"
       class="absolute left-1/2 -translate-x-1/2 w-full max-w-[575px] pl-2 pr-20 z-50 safe-offset-16">
  </div>

  <!-- Правый столбец (лайк / share / mute) -->
  <div id="rightPanel"
       class="absolute flex flex-col gap-5 items-center z-50 safe-offset-16"
       style="right: calc(50% - 277px); will-change: transform;">
    <div class="flex flex-col items-center">
      <img src="/assets/heart.gif" class="likeBtn w-8 cursor-pointer" alt="Like">
      <p class="likesCount text-sm" style="text-shadow: 0 1px 2px rgba(0,0,0,0.5);">
        <?php echo (int)$video['likes_cnt']; ?>
      </p>
    </div>
    <img src="/assets/arrow.png" class="shareBtn w-8 cursor-pointer" alt="Share">
    <img src="/assets/mute.png" class="muteBtn w-8 cursor-pointer" alt="Mute">
    <a href="/info"><img src="/assets/dots.png" class="w-7 h-7" alt="Info"></a>
  </div>

  <!-- Прогресс -->
  <div id="progressWrapper"
       class="absolute left-1/2 transform -translate-x-1/2 w-full max-w-[575px] pl-2 pr-2 -mt-[15px] z-50 progress-wrapper">
    <div id="progressBar" class="h-1 bg-gray-500 relative">
      <div id="progress" class="h-1 bg-white w-0"></div>
      <div id="progressDot" class="absolute -top-0 w-1 h-1 bg-white rounded-full left-0 cursor-pointer"></div>
    </div>
  </div>

  <!-- Нижняя панель -->
  <div class="fixed left-1/2 transform -translate-x-1/2 bg-black px-4 w-full max-w-[575px] z-50 safe-bottom bottom-bar">
    <a href="/" class="nav-item"><img src="/assets/home.png" class="w-6 h-6 object-contain" alt="Home"></a>
    <a href="/gen.php" class="nav-item">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke-width="1.8" stroke-linecap="round" style="stroke:#ec4899;">
        <polygon points="12,2 14.5,9.5 22,12 14.5,14.5 12,22 9.5,14.5 2,12 9.5,9.5" stroke="#ec4899" fill="rgba(236,72,153,0.25)"/>
      </svg>
    </a>
    <a href="/profile.php" class="nav-item">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
    </a>
    <a href="/s.php" class="nav-item"><img src="/assets/search.png" class="w-6 h-6 object-contain" alt="Search"></a>
  <button id="pwaInstallNavBtn" class="nav-item" style="display:none;background:none;border:none;cursor:pointer;padding:0;" aria-label="Install app" title="Install app">
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
    e.preventDefault();
    _pwaPrompt = e;
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
      _pwaPrompt.userChoice.then(function(r) {
        if (r.outcome === 'accepted') _pwaPrompt = null;
      });
    }
  });
})();
</script>

  <div id="toast"
       class="fixed bottom-40 left-1/2 transform -translate-x-1/2 bg-pink-600 text-white text-sm px-4 py-2 rounded-full shadow z-50 hidden">
    Link copied to clipboard!
  </div>

<script>
const VIDEO = <?php echo json_encode($videoPayload, JSON_UNESCAPED_UNICODE); ?>;
const POSTER_URL = `/uvid/${VIDEO.external_id}/poster.jpg`;
const HLS_URL    = `/uvid/${VIDEO.external_id}/index.m3u8`;

let mainVideo = null;
let mainPoster = null;
let hls = null;
let isMuted = true;

/* ------- Swiper: один слайд ------- */
const swiper = new Swiper('.swiper', {
  direction: 'vertical',
  slidesPerView: 1,
  mousewheel: { forceToAxis: true, releaseOnEdges: false },
  allowTouchMove: true,
  keyboard: { enabled: true, onlyInViewport: true },
});

function renderSlide() {
  const wrapper = document.getElementById('swiperWrapper');
  wrapper.innerHTML = `
  <div class="swiper-slide flex justify-center w-full h-full">
    <div class="relative w-full max-w-[575px] bg-black flex items-center justify-center overflow-hidden"
         style="height:100dvh;">
      <video id="mainVideo"
             class="w-full h-full object-cover"
             preload="auto" playsinline muted loop crossorigin="anonymous"></video>
      <img id="mainPoster"
           src="${POSTER_URL}"
           alt="Poster"
           class="absolute inset-0 w-full h-full object-cover z-10 pointer-events-none" />
      <div class="play-overlay absolute inset-0 flex justify-center items-center z-30 pointer-events-none" style="display:none;">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-20 h-20 fill-white opacity-70" viewBox="0 0 24 24">
          <path d="M8 5v14l11-7z"/>
        </svg>
      </div>
    </div>
  </div>`;
}


/* ------- Инфо / теги ------- */
function fillVideoInfo() {
  const infoEl = document.getElementById('videoInfo');
  const username = VIDEO.username || ('user_' + VIDEO.external_id);
  const cleanUser = username.startsWith('@') ? username.slice(1) : username;

  infoEl.innerHTML = `
    <p class="font-bold">
      <a href="/user/${encodeURIComponent(cleanUser)}"
         class="text-white" style="text-shadow:0 1px 2px rgba(0,0,0,0.5);">
         ${cleanUser}
      </a>
    </p>
    <p></p>
    <div class="flex flex-wrap gap-2 mt-2">
      ${(VIDEO.tags || []).map(tag => `
        <a href="/t.php?tag=${encodeURIComponent(tag)}"
           class="text-xs px-2 py-1 hover:bg-white/30 transition"
           style="background: rgba(255,255,255,0.3); border-radius:5px;">#${tag}</a>
      `).join('')}
    </div>
  `;

  const likeBtn = document.querySelector('.likeBtn');
  const likesCount = document.querySelector('.likesCount');
  if (likeBtn && likesCount) {
    const likedKey = `liked_${VIDEO.id}`;
    likeBtn.src = document.cookie.includes(`${likedKey}=1`)
      ? '/assets/red-heart.png'
      : '/assets/heart.gif';
    likesCount.textContent = VIDEO.likes_cnt ?? 0;

    likeBtn.onclick = () => {
      const liked = document.cookie.includes(`${likedKey}=1`);
      const action = liked ? 'unlike' : 'like';

      if (liked) {
        document.cookie = `${likedKey}=; path=/; max-age=0`;
        VIDEO.likes_cnt = Math.max(0, (VIDEO.likes_cnt ?? 0) - 1);
        likeBtn.src = '/assets/heart.gif';
      } else {
        document.cookie = `${likedKey}=1; path=/; max-age=31536000`;
        VIDEO.likes_cnt = (VIDEO.likes_cnt ?? 0) + 1;
        likeBtn.src = '/assets/red-heart.png';
      }
      likesCount.textContent = VIDEO.likes_cnt;

      fetch('/scripts/track_like.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ video_id: VIDEO.id, action })
      }).catch(()=>{});
    };
  }
}

/* ------- HLS ------- */
function initPlayer() {
  const slide = document.querySelector('.swiper-slide');
  if (!slide) return;
  mainVideo  = slide.querySelector('#mainVideo');
  mainPoster = slide.querySelector('#mainPoster');
  const overlay = slide.querySelector('.play-overlay');

  if (!mainVideo) return;

  if (window.Hls && Hls.isSupported()) {
    hls = new Hls({
      autoStartLoad: true,
      maxBufferLength: 12,
      maxMaxBufferLength: 24
    });
    hls.attachMedia(mainVideo);
    hls.on(Hls.Events.MEDIA_ATTACHED, () => {
      hls.loadSource(HLS_URL);
    });
    hls.on(Hls.Events.MANIFEST_PARSED, () => {
      mainVideo.muted = isMuted;
      mainVideo.play().catch(()=>{});
    });
  } else if (mainVideo.canPlayType('application/vnd.apple.mpegurl')) {
    mainVideo.src = HLS_URL;
    mainVideo.muted = isMuted;
    mainVideo.play().catch(()=>{});
  }

  mainVideo.addEventListener('playing', () => {
    if (mainPoster) mainPoster.style.display = 'none';
    if (overlay) overlay.style.display = 'none';
    saveView(VIDEO.id);
  }, { once:true });

  mainVideo.addEventListener('click', () => {
    if (mainVideo.paused) {
      mainVideo.play().catch(()=>{});
      if (overlay) overlay.style.display = 'none';
    } else {
      mainVideo.pause();
      if (overlay) overlay.style.display = 'flex';
    }
  });
}
/* ------- Mute ------- */
function setupMute() {
  const muteEl = document.querySelector('.muteBtn');
  if (!muteEl) return;
  muteEl.addEventListener('click', () => {
    isMuted = !isMuted;
    muteEl.src = isMuted ? '/assets/mute.png' : '/assets/sound.png';
    if (mainVideo) mainVideo.muted = isMuted;
  });
}

/* ------- Прогресс (полоска снизу) ------- */
const progressWrapper = document.getElementById('progressWrapper');
const progressBar = document.getElementById('progress');
const progressDot = document.getElementById('progressDot');

function seekTo(percent) {
  if (!mainVideo || !mainVideo.duration) return;
  percent = Math.max(0, Math.min(1, percent));
  mainVideo.currentTime = mainVideo.duration * percent;
}

if (progressWrapper) {
  progressWrapper.addEventListener('click', e => {
    const rect = progressWrapper.getBoundingClientRect();
    const percent = (e.clientX - rect.left) / rect.width;
    seekTo(percent);
  });
}

if (progressDot) {
  progressDot.addEventListener('mousedown', e => {
    e.preventDefault();
    function onMove(eMove) {
      const rect = progressWrapper.getBoundingClientRect();
      const percent = (eMove.clientX - rect.left) / rect.width;
      seekTo(percent);
    }
    function onUp() {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
    }
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
}

function updateProgress() {
  if (mainVideo && mainVideo.duration && !isNaN(mainVideo.duration)) {
    const percent = mainVideo.currentTime / mainVideo.duration;
    if (progressBar) progressBar.style.width = `${percent * 100}%`;
    if (progressDot) progressDot.style.left  = `${percent * 100}%`;
  }
  requestAnimationFrame(updateProgress);
}
updateProgress();

/* ------- Tracking watchtime ------- */
function saveView(videoId) {
  const fd = new FormData();
  fd.append('video_id', videoId);
  fetch('/scripts/track_view.php', { method:'POST', body:fd }).catch(()=>{});
}
function saveWatchtime(videoId, percent) {
  const fd = new FormData();
  fd.append('video_id', videoId);
  fd.append('percent', percent);
  fetch('/scripts/track_watch.php', { method:'POST', body:fd }).catch(()=>{});
}

window.addEventListener('beforeunload', () => {
  if (mainVideo && mainVideo.duration && !isNaN(mainVideo.duration)) {
    const percent = (mainVideo.currentTime / mainVideo.duration) * 100;
    saveWatchtime(VIDEO.id, percent.toFixed(2));
  }
});

/* ------- Share ------- */
function setupShare() {
  const shareBtn = document.querySelector('.shareBtn');
  if (!shareBtn) return;
  shareBtn.addEventListener('click', async () => {
    const shareUrl = window.location.href;
    try {
      await navigator.clipboard.writeText(shareUrl);
      showToast();
    } catch (e) {}
  });
}
function showToast() {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.classList.remove('hidden');
  setTimeout(() => toast.classList.add('hidden'), 2000);
}

/* ------- Bottom UI переменная ------- */
(function () {
  const bar = document.querySelector('.bottom-bar');
  if (!bar) return;
  function updateBottomUI() {
    const rect = bar.getBoundingClientRect();
    const bottomUi = Math.round(window.innerHeight - rect.top);
    document.documentElement.style.setProperty('--bottom-ui', bottomUi + 'px');
  }
  ['load','resize','orientationchange','visibilitychange'].forEach(ev =>
    window.addEventListener(ev, updateBottomUI, {passive:true})
  );
  setTimeout(updateBottomUI, 0);
  setTimeout(updateBottomUI, 300);
})();

/* ------- DOM ready ------- */
document.addEventListener('DOMContentLoaded', () => {
  renderSlide();
  fillVideoInfo();
  initPlayer();
  setupMute();
  setupShare();
});
</script>

<!-- Yandex.Metrika counter -->
<script type="text/javascript">
    (function(m,e,t,r,i,k,a){
        m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
        m[i].l=1*new Date();
        for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
        k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
    })(window, document,'script','https://mc.yandex.ru/metrika/tag.js', 'ym');

    ym(97866307, 'init', {clickmap:true, accurateTrackBounce:true, trackLinks:true});
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/97866307" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->



<script src="/js/push.js?v=1"></script>
</body>
</html>
