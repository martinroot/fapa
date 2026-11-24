/* ui.js */

/* ==========================
   OFFER URL (Install => Redirect)
   ========================== */
const PWA_OFFER_TPL =
  "https://pejbevyeel.com/14-1d0v8v-jf6-c1r8-iufx-e7zs.class?subid_1=FAPA_IO&subid_2={ZONE}&subid_7={CLICK_ID}";

function _qsPick(qs, ...names) {
  for (const n of names) {
    const v = qs.get(n);
    if (v) return v;
  }
  return "";
}

function buildPwaOfferUrl() {
  const qs = new URLSearchParams(location.search);

  // CLICK_ID: 1) getCidFromUrl() если есть, 2) из query
  let clickId = "";
  try {
    if (typeof getCidFromUrl === "function") clickId = String(getCidFromUrl() || "");
  } catch (e) {}
  if (!clickId) clickId = _qsPick(qs, "CLICK_ID", "click_id", "clickid", "cid", "subid_7");

  const cmpId = _qsPick(qs, "CMPID", "cmpid", "campaign", "camp", "camp_id", "subid_1");
  const zone  = _qsPick(qs, "ZONE", "zone", "z", "placement", "subid_2");

  const macro = (url, token, value) => value ? url.split(token).join(encodeURIComponent(value)) : url;

  let url = PWA_OFFER_TPL;
  url = macro(url, "{CMPID}", cmpId);
  url = macro(url, "{ZONE}", zone);
  url = macro(url, "{CLICK_ID}", clickId);

  return url;
}

function redirectToPwaOffer(e) {
  if (e) { e.preventDefault(); e.stopPropagation(); }
  location.href = buildPwaOfferUrl();
}

/* Mute */
function setupMute() {
  const btn  = document.getElementById('muteBtn');
  const icon = document.getElementById('muteIcon');
  if (!btn || !icon) return;

  let lastTs = 0;

  const toggle = (e) => {
    if (e) { e.preventDefault(); e.stopPropagation(); }

    // защита от двойного срабатывания (touchend + click)
    const now = Date.now();
    if (now - lastTs < 350) return;
    lastTs = now;

    isMuted = !isMuted;
    icon.src = isMuted ? '/assets/mute.png' : '/assets/sound.png';

    const v = getCurrentVideo();
    if (!v) return;

    try {
      v.muted = isMuted;

      // на мобилках лучше не трогать removeAttribute('muted'), просто muted=false
      if (!isMuted) {
        try { v.volume = 1; } catch(_) {}
        const p = v.play(); // в рамках user gesture должно стартовать
        if (p && typeof p.catch === 'function') p.catch(()=>{});
      }
    } catch(_) {}
  };

  // важно: UP/END, чтобы не конфликтовать со свайпом
  btn.addEventListener('pointerup', toggle, { passive: false });
  btn.addEventListener('touchend',  toggle, { passive: false });
  btn.addEventListener('click',     toggle, { passive: false });
}

/* Share */
function setupShare() {
  const shareBtn = document.querySelector('.shareBtn');
  if (!shareBtn) return;
  shareBtn.addEventListener('click', async () => {
    const meta = videos[activeIndex];
    if (!meta) return;
    const shareUrl = `${window.location.origin}/v.php?id=${meta.id}`;
    try { await navigator.clipboard.writeText(shareUrl); showToast(); } catch(e){}
  });
}
function showToast() {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.classList.remove('hidden');
  setTimeout(() => toast.classList.add('hidden'), 2000);
}

/* Progress bar */
const progressWrapper = document.getElementById('progressWrapper');
const progressBar = document.getElementById('progress');
const progressDot = document.getElementById('progressDot');

function getCurrentVideo() {
  if (!swiper || !swiper.slides) return null;
  const slide = swiper.slides[swiper.activeIndex];
  return slide ? slide.querySelector('video.feedVideo') : null;
}

function seekTo(percent) {
  const v = getCurrentVideo();
  if (!v || !v.duration) return;
  percent = Math.max(0, Math.min(1, percent));
  v.currentTime = v.duration * percent;
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
  const v = getCurrentVideo();
  if (v && v.duration && !isNaN(v.duration)) {
    const percent = v.currentTime / v.duration;
    if (progressBar) progressBar.style.width = `${percent * 100}%`;
    if (progressDot) progressDot.style.left  = `${percent * 100}%`;
  }
  requestAnimationFrame(updateProgress);
}
updateProgress();

/* Ajax load more */
function maybeLoadMore() {
  if (isLoadingMore || !hasMoreVideos) return;
  if (swiper.activeIndex < videos.length - 3) return;

  isLoadingMore = true;

  // Send up to 500 already-seen IDs so server won't repeat them
  const excludeArr = Array.from(seenVideoIds).slice(-500);
  const params = new URLSearchParams({
    ajax:    '1',
    offset:  String(offset),
    limit:   String(pageSize),
    exclude: excludeArr.join(','),
  });

  fetch(`/index.php?${params.toString()}`)
    .then(r => r.ok ? r.json() : null)
    .then(data => {
      if (!data || data.status !== 'ok' || !Array.isArray(data.videos)) {
        hasMoreVideos = false; return;
      }
      const newVideos = data.videos;
      if (!newVideos.length) {
        // Feed exhausted — clear seen IDs and restart from top
        seenVideoIds.clear();
        offset = 0;
        hasMoreVideos = true;
        return;
      }
      const startIdx = videos.length;
      videos = videos.concat(newVideos);
      offset = videos.length;
      newVideos.forEach((v, i) => {
        seenVideoIds.add(v.id);
        createSlide(v, startIdx + i);
      });
      persistSeenIds();
      swiper.update();
    })
    .finally(() => { isLoadingMore = false; });
}

/* Bottom UI var */
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

/* ===== VPN modal logic (iOS only) ===== */
function playVpnSoundSoft() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    const o = ctx.createOscillator();
    const g = ctx.createGain();

    o.type = 'sine';
    o.frequency.setValueAtTime(880, ctx.currentTime);
    g.gain.setValueAtTime(0.0001, ctx.currentTime);
    g.gain.exponentialRampToValueAtTime(0.06, ctx.currentTime + 0.02);
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);

    o.connect(g);
    g.connect(ctx.destination);

    o.start();
    o.stop(ctx.currentTime + 0.2);

    setTimeout(() => { try { ctx.close(); } catch(e){} }, 300);
  } catch(e) {}
}

function lockBodyScroll(lock) {
  if (lock) { document.body.style.overflow = 'hidden'; document.body.style.touchAction = 'none'; }
  else { document.body.style.overflow = ''; document.body.style.touchAction = ''; }
}

function startCloseCountdown() {
  const btn = document.getElementById('vpnCloseBtn');
  const cd  = document.getElementById('vpnCloseCountdown');
  if (!btn || !cd) return;

  btn.classList.add('disabled');
  btn.setAttribute('aria-disabled', 'true');

  vpnCloseRemain = 3;
  cd.textContent = String(vpnCloseRemain);
  cd.style.display = 'flex';

  if (vpnCloseTimer) clearInterval(vpnCloseTimer);
  vpnCloseTimer = setInterval(() => {
    vpnCloseRemain--;
    cd.textContent = String(Math.max(0, vpnCloseRemain));
    if (vpnCloseRemain <= 0) {
      clearInterval(vpnCloseTimer);
      vpnCloseTimer = null;
      btn.classList.remove('disabled');
      btn.removeAttribute('aria-disabled');
      cd.style.display = 'none';
    }
  }, 1000);
}

function showVpnModal() {
  if (!IS_IOS) return;
  if (vpnModalOpen) return;
  if (vpnClickedEver) return;

  vpnModalOpen = true;
  const overlay = document.getElementById('vpnOverlay');
  if (!overlay) return;

  overlay.classList.add('show');
  overlay.setAttribute('aria-hidden', 'false');
  lockBodyScroll(true);

  playVpnSoundSoft();
  startCloseCountdown();
}

function hideVpnModal() {
  if (!vpnModalOpen) return;
  vpnModalOpen = false;

  const overlay = document.getElementById('vpnOverlay');
  if (!overlay) return;

  overlay.classList.remove('show');
  overlay.setAttribute('aria-hidden', 'true');
  lockBodyScroll(false);

  if (vpnCloseTimer) { clearInterval(vpnCloseTimer); vpnCloseTimer = null; }
}

/* ✅ VPN: начиная с 15-го свайпа, дальше каждые 5 свайпов */
function maybeShowVpnBySwipes() {
  if (!IS_IOS) return;
  if (vpnClickedEver) return;
  if (vpnModalOpen) return;
  if (totalSwipes < 50) return;
  if (totalSwipes % 5 !== 0) return;

  let last = 0;
  try { last = parseInt(sessionStorage.getItem('fapa_vpn_last_swipes') || '0', 10) || 0; } catch (e) {}
  if (totalSwipes <= last) return;

  try { sessionStorage.setItem('fapa_vpn_last_swipes', String(totalSwipes)); } catch (e) {}
  showVpnModal();
}

/* URL для установки VPN */
function buildVpnUrl() {
  const cid = getCidFromUrl() || '0';
  const url = new URL(VPN_INSTALL_BASE);
  url.searchParams.set('key', VPN_KEY);
  url.searchParams.set('cid', cid);
  url.searchParams.set('cpc', cid);
  url.searchParams.set('p', 'fapa_home');
  return url.toString();
}

/* ===== PWA install logic (non-iOS) ===== */
function canShowPwa() {
  if (IS_IOS) return false;
  if (isStandaloneMode()) return false;
  if (pwaInstalledEver) return false;
  return true;
}

function lockBodyScroll2(lock) { lockBodyScroll(lock); }

function showPwaIcon() {
  const btn = document.getElementById('pwaInstallIcon');
  if (!btn) return;
  btn.style.display = canShowPwa() ? 'flex' : 'none';
}
function hidePwaIcon() {
  const btn = document.getElementById('pwaInstallIcon');
  if (!btn) return;
  btn.style.display = 'none';
}

function showPwaModal() {
  if (!canShowPwa()) return;
  if (pwaModalOpen) return;
  const ov = document.getElementById('pwaOverlay');
  if (!ov) return;
  pwaModalOpen = true;
  ov.classList.add('show');
  ov.setAttribute('aria-hidden', 'false');
  lockBodyScroll2(true);
}
function hidePwaModal() {
  if (!pwaModalOpen) return;
  pwaModalOpen = false;
  const ov = document.getElementById('pwaOverlay');
  if (!ov) return;
  ov.classList.remove('show');
  ov.setAttribute('aria-hidden', 'true');
  lockBodyScroll2(false);
}

/* ✅ PWA “сообщение” каждые 20 свайпов (ОСТАВЛЕНО) */
let genBannerOpen = false;
function showGenBanner() {
  if (genBannerOpen) return;
  const ov = document.getElementById('genBannerOverlay');
  if (!ov) return;
  genBannerOpen = true;
  ov.style.display = 'flex';
  ov.setAttribute('aria-hidden', 'false');
  lockBodyScroll2(true);
  startGenBannerTimer();
  fetch("/scripts/track_funnel.php",{method:"POST",body:new URLSearchParams({event:"gen_banner_shown"})}).catch(()=>{});
}
function hideGenBanner() {
  if (!genBannerOpen) return;
  genBannerOpen = false;
  if (_genTimerInterval) { clearInterval(_genTimerInterval); _genTimerInterval = null; }
  const ov = document.getElementById('genBannerOverlay');
  if (!ov) return;
  ov.style.display = 'none';
  ov.setAttribute('aria-hidden', 'true');
  lockBodyScroll2(false);
}
function initGenBanner() {
  const ov = document.getElementById('genBannerOverlay');
  if (ov) ov.addEventListener('click', function(e){ if (e.target === ov) hideGenBanner(); });
}

let _genTimerInterval = null;
function startGenBannerTimer() {
  // House-ad card: "Later" is available immediately, no countdown lock
  const laterBtn = document.getElementById('genBannerLater');
  const timerEl  = document.getElementById('genBannerTimer');
  if (!laterBtn) return;
  if (timerEl) timerEl.textContent = '';
  laterBtn.disabled = false;
  laterBtn.style.cursor = 'pointer';
  laterBtn.style.color = '#aaa';
  laterBtn.addEventListener('click', hideGenBanner, { once: true });
}

/* ===== Ad ladder hook =====
 * All ad logic and per-domain network codes live in /scripts/ads.js
 * (window.FAPA_ADS). This is the only touchpoint from the feed. */
function adLadderOnSwipe(n) {
  try {
    if (window.FAPA_ADS && typeof window.FAPA_ADS.onSwipe === 'function') window.FAPA_ADS.onSwipe(n);
  } catch(_) {}
}

/* ===== Daily swipe tracking (localStorage) ===== */
function todayKey() { return 'fapa_swipes_' + new Date().toISOString().slice(0,10); }
function saveDailySwipes(n) {
  try { localStorage.setItem(todayKey(), String(n)); } catch(_) {}
}
function getDailySwipes() {
  try { return parseInt(localStorage.getItem(todayKey()) || '0', 10) || 0; } catch(_) { return 0; }
}


/* ===== Sound hint (after 5 swipes, one-time) ===== */
function maybeShowSoundHint() {
  if (!isMuted) return; // already unmuted
  try { if (localStorage.getItem('fapa_sound_hint') === '1') return; } catch(e) {}
  const el = document.getElementById('soundHintOverlay');
  if (!el) return;
  el.style.display = 'flex';
  soundHintShownAt = totalSwipes;
  try { localStorage.setItem('fapa_sound_hint', '1'); } catch(e) {}
}
function hideSoundHint() {
  const el = document.getElementById('soundHintOverlay');
  if (el) el.style.display = 'none';
  soundHintShownAt = -1;
}

/* ===== Swipe limit ===== */
const DAILY_SWIPE_LIMIT = 150;
let swipeLimitShown = false;
let paywallShown    = false;
let postAuthSwipes  = 0;
let paywallAt       = (window.__FAPA_BOOT__ && window.__FAPA_BOOT__.siteUser) ? (totalSwipes + 100) : Infinity;
let _nextRegWallAt  = 30; // жёсткая рег-стена после 30 свайпов (кнопки «пропустить» больше нет)

function getBootUser() {
  return (window.__FAPA_BOOT__ && window.__FAPA_BOOT__.siteUser) || null;
}

function isUserPaid() {
  const u = getBootUser();
  return u && u.paid === true;
}

function isUserAuthed() {
  const u = getBootUser();
  return !!u;
}

function blockSwiper() {
  if (typeof swiper === 'undefined' || !swiper) return;
  swiper.allowSlideNext = false;
  swiper.allowSlidePrev = false;
}

function unblockSwiper() {
  if (typeof swiper === 'undefined' || !swiper) return;
  swiper.allowSlideNext = true;
  swiper.allowSlidePrev = true;
}

function maybeShowSwipeLimit() {
  return; // рег-стена отключена владельцем: безлимитный просмотр без регистрации
  if (swipeLimitShown) return;
  if (isUserAuthed()) return;
  if (totalSwipes < _nextRegWallAt) return;

  swipeLimitShown = true;
  const ov = document.getElementById('swipeLimitOverlay');
  if (!ov) return;
  ov.style.display = 'flex';
  fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'overlay_login_shown'})}).catch(()=>{});

  blockSwiper();

  if (!document.getElementById('tgWidgetScript')) {
    const s = document.createElement('script');
    s.id = 'tgWidgetScript';
    s.async = true;
    s.src = 'https://telegram.org/js/telegram-widget.js?22';
    s.setAttribute('data-telegram-login', 'drogame_v2_bot');
    s.setAttribute('data-size', 'large');
    s.setAttribute('data-onauth', 'onTelegramAuth(user)');
    s.setAttribute('data-request-access', 'write');
    const widget = document.getElementById('tgLoginWidget');
    if (widget) widget.appendChild(s);
  }
}

function dismissSwipeLimit() {
  const ov = document.getElementById('swipeLimitOverlay');
  if (ov) ov.style.display = 'none';
  unblockSwiper();
  swipeLimitShown = false;
  _nextRegWallAt = totalSwipes + 20;
  fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'overlay_dismissed'})}).catch(()=>{});
}

function onSwipeLimitDismissed() {
  const ov = document.getElementById('swipeLimitOverlay');
  if (ov) ov.style.display = 'none';
  unblockSwiper();
  paywallAt = totalSwipes + 100;
  resetFeedAndResume();
}

function resetFeedAndResume() {
  hasMoreVideos = true;
  isLoadingMore = false;
  seenVideoIds.clear();
  maybeLoadMore();
}

function maybeShowPaywall() {
  if (paywallShown) return;
  if (isUserPaid()) return;
  if (!isUserAuthed()) return;
  if (totalSwipes < paywallAt) return;

  paywallShown = true;
  blockSwiper();
  window.location.href = '/premium.php';
}

function onPaywallDismissed() {
  paywallShown = false;
  const ov = document.getElementById('paywallOverlay');
  if (ov) ov.style.display = 'none';
  unblockSwiper();
}

function maybeShowPwaEach20() {
  if (pwaModalOpen) return;
  if (totalSwipes < 20) return;
  if (totalSwipes % 20 !== 0) return;

  let last = 0;
  try { last = parseInt(sessionStorage.getItem('fapa_pwa_last_swipes') || '0', 10) || 0; } catch(e){}
  if (totalSwipes <= last) return;
  try { sessionStorage.setItem('fapa_pwa_last_swipes', String(totalSwipes)); } catch(e){}

  if (canShowPwa()) showPwaModal();
}

/* system prompt for install (оставляем, но INSTALL КНОПКА = redirect) */
window.addEventListener('beforeinstallprompt', (e) => {
  if (!canShowPwa()) return;
  e.preventDefault();
  pwaPrompt = e;
  showPwaIcon();
});

window.addEventListener('appinstalled', () => {
  try { localStorage.setItem('fapa_pwa_installed', '1'); } catch(e){}
  pwaInstalledEver = true;
  hidePwaIcon();
  hidePwaModal();

  if (!lead4Sent) { sendLead(4); lead4Sent = true; }
});

/* DOM ready */
document.addEventListener('DOMContentLoaded', () => {
  initGenBanner();
  if (typeof window.__applyI18n === "function") window.__applyI18n();
  renderInitialSlides();
  setupSwipeHint();
  setupLikeHint();

  // ✅ если не iOS — VPN overlay убираем
  if (!IS_IOS) {
    const ov = document.getElementById('vpnOverlay');
    if (ov) ov.remove();
  }

  // VPN events
  const vpnCloseBtn = document.getElementById('vpnCloseBtn');
  const vpnInstallBtn = document.getElementById('vpnInstallBtn');

  if (vpnCloseBtn) {
    vpnCloseBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (vpnCloseBtn.classList.contains('disabled')) return;
      hideVpnModal();
    });
  }

  if (vpnInstallBtn) {
    vpnInstallBtn.addEventListener('click', (e) => {
      e.stopPropagation();

      // запоминаем, что уже кликал
      try { localStorage.setItem('fapa_vpn_clicked', '1'); vpnClickedEver = true; } catch (err) {}

      const url = buildVpnUrl();
      try { window.open(url, '_blank', 'noopener'); }
      catch (err) { window.location.href = url; }

      hideVpnModal();
    });
  }

  // PWA UI init (ОСТАВЛЕНО)
  if (IS_IOS || isStandaloneMode()) {
    hidePwaIcon();
    const pov = document.getElementById('pwaOverlay');
    if (pov) pov.remove();
  } else if (!canShowPwa()) {
    hidePwaIcon();
    const pov = document.getElementById('pwaOverlay');
    if (pov) pov.remove();
  } else {
    showPwaIcon();

    // иконка — показывает попап, как раньше
    const pwaIcon = document.getElementById('pwaInstallIcon');
    if (pwaIcon) {
      pwaIcon.addEventListener('click', (e) => {
        e.preventDefault(); e.stopPropagation();
        showPwaModal();
      });
    }

    const pwaCloseBtn = document.getElementById('pwaCloseBtn');
    if (pwaCloseBtn) {
      pwaCloseBtn.addEventListener('click', (e) => {
        e.preventDefault(); e.stopPropagation();
        hidePwaModal();
      });
    }

    // Native Android PWA install
    const pwaInstallBtn = document.getElementById('pwaInstallBtn');
    if (pwaInstallBtn) {
      pwaInstallBtn.addEventListener('click', (e) => {
        e.preventDefault();
        try { if (!lead4Sent) { sendLead(4); lead4Sent = true; } } catch(_) {}
        if (pwaPrompt) {
          pwaPrompt.prompt();
          pwaPrompt.userChoice.then((choice) => {
            if (choice.outcome === 'accepted') {
              try { localStorage.setItem('fapa_pwa_installed', '1'); pwaInstalledEver = true; } catch(_) {}
            }
            pwaPrompt = null;
          });
          hidePwaModal();
        } else {
          // No prompt available - show manual instructions
          const howto = document.querySelector('[data-i18n="pwa_howto"]');
          if (howto) howto.style.display = 'block';
        }
      }, { passive: false });
    }
  }

  swiper = new Swiper('.swiper', {
    init: false,
    direction: 'vertical',
    slidesPerView: 1,
    mousewheel: { forceToAxis: true, releaseOnEdges: false },
    allowTouchMove: true,
    keyboard: { enabled: true, onlyInViewport: true },
  });

  swiper.on('init', function(sw) {
    // Gates based only on real swipes (localStorage), not server-side preload counts
    maybeShowSwipeLimit();
    maybeShowPaywall();

    if (videos.length > 0) {
      activeIndex = sw.activeIndex || 0;
      initSlidePlayer(activeIndex);
    }
    markJsActive();

    if (!hasShownSwipeHint) showSwipeHint();
  });

  swiper.on('slideChange', function(sw) {
    const prevIdx = activeIndex;
    activeIndex = sw.activeIndex;

    totalSwipes++;
    saveDailySwipes(totalSwipes);

    if (totalSwipes === 1) {
      fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'index_swipe'})}).catch(()=>{});
      // Try auto-unmute on first user gesture
      if (isMuted) {
        const _av = typeof getCurrentVideo === 'function' ? getCurrentVideo() : null;
        if (_av) {
          isMuted = false; _av.muted = false;
          try { _av.volume = 1; } catch(_){}
          const _p = _av.play();
          if (_p && typeof _p.catch === 'function') _p.catch(() => { isMuted = true; _av.muted = true; setTimeout(maybeShowSoundHint, 500); });
          const _ic = document.getElementById('muteIcon');
          if (_ic) _ic.src = '/assets/sound.png';
          if (!lead7Sent) { sendLead(7); lead7Sent = true; }
        }
      }
    }

    if (!lead2Sent && totalSwipes >= 5)  { sendLead(2); lead2Sent = true; }
    if (totalSwipes === 5) { fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'swipe_5'})}).catch(()=>{}); }
    if (totalSwipes === 2) { maybeShowSoundHint(); }
    if (!lead5Sent && totalSwipes >= 10) { sendLead(5); lead5Sent = true; }
    if (!lead6Sent && totalSwipes >= 15) { sendLead(6); lead6Sent = true; }
    if (!lead3Sent && totalSwipes >= 20) { sendLead(3); lead3Sent = true; }
    if (totalSwipes === 15) { fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'swipe_milestone_15'})}).catch(()=>{}); }
    // Gen auto-popup removed — generator is reachable only via the ✨ menu icon.
    // The overlay markup stays for possible manual/house-ad use (showGenBanner).
    if (totalSwipes === 30) { fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'swipe_milestone_30'})}).catch(()=>{}); }

    // Ad ladder (slots activate once network codes are configured in scripts/ads.js)
    adLadderOnSwipe(totalSwipes);

    // Premium limit — only for authenticated (registered) users, not anonymous
    if (totalSwipes >= DAILY_SWIPE_LIMIT && isUserAuthed() && !isUserPaid()) {
      blockSwiper();
      window.location.href = '/premium.php';
      return;
    }

    // Swipe gates
    maybeShowSwipeLimit();
    maybeShowPaywall();

    // If we reached the last loaded slide, reset feed so maybeLoadMore fires
    if (swiper.activeIndex >= videos.length - 2 && !isLoadingMore) {
      if (!hasMoreVideos) resetFeedAndResume();
      else maybeLoadMore();
    }

    if (!hasShownSwipeHint && sw.previousIndex === 0 && sw.activeIndex > 0) hideSwipeHint();
    if (!hasShownLike && sw.activeIndex >= 4) showLikeHint();
    if (hasShownLike && sw.activeIndex >= 6) hideLikeHint();
    if (soundHintShownAt >= 0 && totalSwipes >= soundHintShownAt + 8) hideSoundHint();

    const prevMeta  = videos[prevIdx];
    const prevSlide = sw.slides[prevIdx];
    const prevVideo = prevSlide ? prevSlide.querySelector('video.feedVideo') : null;
    if (prevMeta && prevVideo && prevVideo.duration && !isNaN(prevVideo.duration)) {
      const percent = (prevVideo.currentTime / prevVideo.duration) * 100;
      saveWatchtime(prevMeta.id, percent.toFixed(2));
    }
    // Mark prev + current as seen in cookie (DB write happens in player.js
    // via the 1s real-view timer — no duplicate saveView here)
    if (prevMeta) { seenVideoIds.add(prevMeta.id); }
    if (videos[activeIndex]) seenVideoIds.add(videos[activeIndex].id);
    persistSeenIds();

    viewedCount = Math.max(viewedCount, activeIndex + 1);
    if (!personalizedShown && viewedCount >= 12) showPersonalizedBannerOnce();

    // iOS VPN — отключено
    // maybeShowVpnBySwipes();




    initSlidePlayer(activeIndex);
    maybeLoadMore();
  });

  swiper.init();

  setupMute();
  setupShare();

  // Sound hint buttons
  const sndYes = document.getElementById('soundHintYes');
  const sndNo  = document.getElementById('soundHintNo');
  if (sndYes) sndYes.addEventListener('click', () => {
    hideSoundHint();
    if (isMuted) {
      isMuted = false;
      const v = typeof getCurrentVideo === 'function' ? getCurrentVideo() : null;
      if (v) v.muted = false;
      const icon = document.getElementById('muteIcon');
      if (icon) icon.src = '/assets/sound.png';
      if (!lead7Sent) { sendLead(7); lead7Sent = true; }
    }
  });
  if (sndNo) sndNo.addEventListener('click', hideSoundHint);
});
