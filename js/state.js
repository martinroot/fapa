const BOOT = window.__FAPA_BOOT__ || {};
const HAS_MORE_INIT = !!BOOT.HAS_MORE_INIT;

let videos     = Array.isArray(BOOT.videos) ? BOOT.videos : [];
let offset     = videos.length;
const pageSize = Number(BOOT.pageSize || 20);

// Deduplication: persist across sessions via localStorage
const seenVideoIds = new Set();
try {
  const stored = localStorage.getItem('fapa_seen_ids');
  if (stored) {
    JSON.parse(stored).forEach(function(id) { seenVideoIds.add(id); });
  }
} catch(_) {}
videos.forEach(v => seenVideoIds.add(v.id));

function persistSeenIds() {
  try {
    var arr = Array.from(seenVideoIds);
    if (arr.length > 2000) arr = arr.slice(-2000);
    localStorage.setItem('fapa_seen_ids', JSON.stringify(arr));
    document.cookie = 'seen_ids=' + arr.slice(-500).join(',') + ';path=/;max-age=31536000';
  } catch(_) {}
}
persistSeenIds();

let swiper          = null;
let activeIndex     = 0;
let isLoadingMore   = false;
let hasMoreVideos   = HAS_MORE_INIT;

let currentHls      = null;
let currentVideoEl  = null;
let currentPosterEl = null;
let isMuted         = true;

// preload next clip
let preloadHls      = null;
let preloadIndex    = -1;

// swipe counters — restore from daily localStorage so limit persists across reloads
let totalSwipes = (function(){ try { var k = "fapa_swipes_" + new Date().toISOString().slice(0,10); return parseInt(localStorage.getItem(k)||"0",10)||0; } catch(_){ return 0; } })();

// leads flags
let lead1Sent = false; // 1 касание
let lead2Sent = false; // 5 свайпов
let lead3Sent = false; // 20 свайпов
let lead4Sent = false; // ✅ PWA install
let lead5Sent = false; // 10 свайпов
let lead6Sent = false; // 15 свайпов
let lead7Sent = false; // Sound ON
let soundHintShownAt = -1;

// просмотренные видео (по позициям), баннер
let viewedCount       = (videos.length > 0) ? 1 : 0;
let personalizedShown = false;

// onboarding flags
let hasShownLike      = false;
let hasShownSwipeHint = false;
try {
  hasShownLike      = localStorage.getItem('fapa_onb_like') === '1';
  hasShownSwipeHint = localStorage.getItem('fapa_onb_swipe_hint') === '1';
} catch (e) {}

// VPN клик когда-либо (чтобы больше не показывать алерт)
let vpnClickedEver = false;
try {
  vpnClickedEver = localStorage.getItem('fapa_vpn_clicked') === '1';
} catch (e) {}

// ===== PWA installed ever =====
let pwaInstalledEver = false;
try { pwaInstalledEver = localStorage.getItem('fapa_pwa_installed') === '1'; } catch(e) {}

// ===== VPN click URL builder =====
const VPN_INSTALL_BASE = 'https://strong-super.website/click.php';
const VPN_KEY = '67d557c216cb4195b4c3ddf855777cf1';

// ===== iOS only (VPN) =====
function isIOSDevice() {
  const ua = navigator.userAgent || '';
  const iOS = /iPad|iPhone|iPod/.test(ua);
  const iPadOS = (navigator.platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1);
  return iOS || iPadOS;
}
const IS_IOS = isIOSDevice();

function isStandaloneMode(){
  return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || (window.navigator.standalone === true);
}

// активный язык
let _activeLang = 'en';

/* Mark JS + active tab → clicks.js */
let jsMarked = false;

// ✅ СТАТА ТОЛЬКО ПОСЛЕ ПЕРВОГО КАСАНИЯ
let statsArmed = false;

/* Первый контакт (lead1) */
let firstInteractionDone = false;

// VPN modal state
let vpnModalOpen = false;
let vpnCloseTimer = null;
let vpnCloseRemain = 3;

// PWA state
let pwaPrompt = null;
let pwaModalOpen = false;
