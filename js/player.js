/* Swipe hint */
function showSwipeHint() {
  if (hasShownSwipeHint) return;
  const el = document.getElementById('swipeHint');
  if (!el) return;
  el.classList.remove('hidden');
}
function hideSwipeHint() {
  const el = document.getElementById('swipeHint');
  if (!el) return;
  el.classList.add('hidden');
  if (!hasShownSwipeHint) {
    hasShownSwipeHint = true;
    try { localStorage.setItem('fapa_onb_swipe_hint', '1'); } catch(e){}
  }
}
function setupSwipeHint() {
  const el = document.getElementById('swipeHint');
  if (!el) return;
  el.addEventListener('click', (e) => {
    e.stopPropagation();
    if (swiper) swiper.slideNext();
    hideSwipeHint();
  });
}

/* Like hint */
function showLikeHint() {
  if (hasShownLike) return;
  const el = document.getElementById('likeHint');
  if (!el) return;
  el.classList.remove('hidden');
}
function hideLikeHint() {
  const el = document.getElementById('likeHint');
  if (!el) return;
  el.classList.add('hidden');
  if (!hasShownLike) {
    hasShownLike = true;
    try { localStorage.setItem('fapa_onb_like', '1'); } catch(e){}
  }
}
function setupLikeHint() {
  const el = document.getElementById('likeHint');
  if (!el) return;
  el.addEventListener('click', (e) => {
    e.stopPropagation();
    hideLikeHint();
  });
}

/* Slides */
function createSlide(video, index) {
  const wrapper = document.getElementById('swiperWrapper');
  const slide = document.createElement('div');
  slide.className = 'swiper-slide flex justify-center w-full h-full';
  slide.setAttribute('data-idx', index);

  slide.innerHTML = `
    <div class="relative w-full max-w-[575px] bg-black flex items-center justify-center overflow-hidden"
         style="height:100dvh;">
      <video
        class="feedVideo w-full h-full object-cover"
        preload="auto"
        playsinline
        webkit-playsinline
        loop
        crossorigin="anonymous"
        data-index="${index}"
        data-video-id="${video.id}"
        data-external-id="${video.external_id}"
      ></video>
      <img
        class="feedPoster absolute inset-0 w-full h-full object-cover z-10 pointer-events-none"
        src="/uvid/${video.external_id}/poster.jpg"
        alt="Poster"
      >
      <div class="play-overlay absolute inset-0 flex justify-center items-center z-30 pointer-events-none" style="display:none;">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-20 h-20 fill-white opacity-70" viewBox="0 0 24 24">
          <path d="M8 5v14l11-7z"/>
        </svg>
      </div>
    </div>
  `;

  wrapper.appendChild(slide);
}

function renderInitialSlides() {
  const w = document.getElementById('swiperWrapper');
  w.innerHTML = '';
  videos.forEach((v, idx) => createSlide(v, idx));
}

/* Video info */
function updateVideoInfo(meta) {
  const infoEl = document.getElementById('videoInfo');
  if (!infoEl) return;
  const username = meta.username || ('user_' + meta.external_id);
  const cleanUser = username.startsWith('@') ? username.slice(1) : username;

  infoEl.innerHTML = `
    <p class="font-bold">
      <a href="/u.php?u=${encodeURIComponent(cleanUser)}"
         class="text-white"
         style="text-shadow:0 1px 2px rgba(0,0,0,0.5);">
         ${cleanUser}
      </a>
    </p>
    <p></p>
    <div class="flex flex-wrap gap-2 mt-2">
      ${(meta.tags || []).map(tag => `
        <a href="/t.php?tag=${encodeURIComponent(tag)}"
           class="text-xs px-2 py-1 hover:bg-white/30 transition"
           style="background: rgba(255,255,255,0.3); border-radius:5px;">#${tag}</a>
      `).join('')}
    </div>
  `;

  const likeBtn    = document.querySelector('.likeBtn');
  const likesCount = document.querySelector('.likesCount');
  if (likesCount) likesCount.textContent = meta.likes_cnt ?? 0;
  if (likeBtn) {
    const likedKey = `liked_${meta.id}`;
    likeBtn.src = document.cookie.includes(`${likedKey}=1`)
      ? '/assets/red-heart.png'
      : '/assets/heart.gif';
  }
}

/* HLS helpers */
function destroyCurrentHls() {
  if (currentHls) {
    try { currentHls.destroy(); } catch(e){}
    currentHls = null;
  }
}
function destroyPreloadHls() {
  if (preloadHls) {
    try { preloadHls.destroy(); } catch(e){}
    preloadHls = null;
    preloadIndex = -1;
  }
}

function prepareNext(index) {
  if (!videos[index]) return;
  if (preloadHls && preloadIndex === index) return;

  destroyPreloadHls();

  const slide = document.querySelector(`.swiper-slide[data-idx="${index}"]`);
  if (!slide) return;
  const videoEl = slide.querySelector('video.feedVideo');
  if (!videoEl) return;

  const meta   = videos[index];
  const hlsUrl = `/uvid/${meta.external_id}/index.m3u8`;

  if (window.Hls && Hls.isSupported()) {
    preloadHls = new Hls({ autoStartLoad: true, maxBufferLength: 12, maxMaxBufferLength: 24 });
    preloadHls.attachMedia(videoEl);
    preloadHls.on(Hls.Events.MEDIA_ATTACHED, () => { preloadHls.loadSource(hlsUrl); });
    preloadIndex = index;
  } else if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
    videoEl.src = hlsUrl;
    preloadIndex = index;
  }
}

/* Player init */
function initSlidePlayer(index) {
  if (!videos[index]) return;
  const slide = document.querySelector(`.swiper-slide[data-idx="${index}"]`);
  if (!slide) return;

  const videoEl   = slide.querySelector('video.feedVideo');
  const posterEl  = slide.querySelector('.feedPoster');
  const overlayEl = slide.querySelector('.play-overlay');
  if (!videoEl) return;

  const meta   = videos[index];
  const hlsUrl = `/uvid/${meta.external_id}/index.m3u8`;
  updateVideoInfo(meta);

  if (preloadHls && preloadIndex === index) {
    destroyCurrentHls();
    currentHls      = preloadHls;
    preloadHls      = null;
    preloadIndex    = -1;

    currentVideoEl  = videoEl;
    currentPosterEl = posterEl;

    videoEl.muted = isMuted;
    videoEl.play().catch(()=>{});

    videoEl.addEventListener('playing', () => {
      if (posterEl)  posterEl.style.display  = 'none';
      if (overlayEl) overlayEl.style.display = 'none';
      // real view = the slide is still active 1s after playback started
      setTimeout(() => { if (activeIndex === index) saveView(meta.id); }, 1000);
      prepareNext(index + 1);
    }, { once:true });

    videoEl.addEventListener('click', () => {
      if (videoEl.paused) {
        videoEl.play().catch(()=>{});
        if (overlayEl) overlayEl.style.display = 'none';
      } else {
        videoEl.pause();
        if (overlayEl) overlayEl.style.display = 'flex';
      }
    });

    updateLikesHandlers();
    return;
  }

  destroyCurrentHls();
  currentVideoEl  = videoEl;
  currentPosterEl = posterEl;

  if (window.Hls && Hls.isSupported()) {
    currentHls = new Hls({ autoStartLoad: true, maxBufferLength: 12, maxMaxBufferLength: 24 });
    currentHls.attachMedia(videoEl);
    currentHls.on(Hls.Events.MEDIA_ATTACHED, () => { currentHls.loadSource(hlsUrl); });
    currentHls.on(Hls.Events.MANIFEST_PARSED, () => {
      videoEl.muted = isMuted;
      videoEl.play().catch(()=>{});
    });
  } else if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
    videoEl.src = hlsUrl;
    videoEl.muted = isMuted;
    videoEl.play().catch(()=>{});
  }

  videoEl.addEventListener('playing', () => {
    if (posterEl)  posterEl.style.display  = 'none';
    if (overlayEl) overlayEl.style.display = 'none';
    // real view = the slide is still active 1s after playback started
    setTimeout(() => { if (activeIndex === index) saveView(meta.id); }, 1000);
    prepareNext(index + 1);
  }, { once:true });

  videoEl.addEventListener('click', () => {
    if (videoEl.paused) {
      videoEl.play().catch(()=>{});
      if (overlayEl) overlayEl.style.display = 'none';
    } else {
      videoEl.pause();
      if (overlayEl) overlayEl.style.display = 'flex';
    }
  });

  updateLikesHandlers();
}

/* Likes */
function updateLikesHandlers() {
  const likeBtn    = document.querySelector('.likeBtn');
  const likesCount = document.querySelector('.likesCount');
  if (!likeBtn || !likesCount) return;

  const meta = videos[activeIndex];
  if (!meta) return;

  const likedKey = `liked_${meta.id}`;
  likeBtn.src = document.cookie.includes(`${likedKey}=1`)
    ? '/assets/red-heart.png'
    : '/assets/heart.gif';
  likesCount.textContent = meta.likes_cnt ?? 0;

  likeBtn.onclick = () => {
    const liked = document.cookie.includes(`${likedKey}=1`);
    const action = liked ? 'unlike' : 'like';

    if (liked) {
      document.cookie = `${likedKey}=; path=/; max-age=0`;
      meta.likes_cnt = Math.max(0, (meta.likes_cnt ?? 0) - 1);
      likeBtn.src = '/assets/heart.gif';
    } else {
      document.cookie = `${likedKey}=1; path=/; max-age=31536000`;
      meta.likes_cnt = (meta.likes_cnt ?? 0) + 1;
      likeBtn.src = '/assets/red-heart.png';
    }
    likesCount.textContent = meta.likes_cnt;

    fetch('/scripts/track_like.php', {
      method:'POST',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ video_id: meta.id, action })
    }).catch(()=>{});
  };
}
