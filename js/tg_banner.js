(function () {
  var TG_URL = 'https://t.me/+T_0TM43EDlgRXPrt';
  var LS_SWIPES = 'tg_swipe_count';
  var LS_GEN    = 'tg_gen_shown';
  var LS_SUB    = 'tg_subscribed';
  var SWIPE_EACH = 10;

  function subscribed() { return localStorage.getItem(LS_SUB) === '1'; }

  function showBanner() {
    if (subscribed()) return;
    var el = document.getElementById('tgBannerOverlay');
    if (!el) return;
    el.style.display = 'flex';
    el.removeAttribute('aria-hidden');
    // re-apply i18n in case it ran before banner was in DOM
    if (typeof applyI18n === 'function') applyI18n();
  }

  function hideBanner() {
    var el = document.getElementById('tgBannerOverlay');
    if (el) { el.style.display = 'none'; el.setAttribute('aria-hidden', 'true'); }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn   = document.getElementById('tgBannerBtn');
    var close = document.getElementById('tgBannerClose');
    var overlay = document.getElementById('tgBannerOverlay');

    if (btn) {
      btn.addEventListener('click', function () {
        localStorage.setItem(LS_SUB, '1');
        hideBanner();
      });
    }
    if (close)   close.addEventListener('click', hideBanner);
    if (overlay) overlay.addEventListener('click', function (e) {
      if (e.target === overlay) hideBanner();
    });
  });

  // Swipe hook — waits for swiper (index.php only)
  var _tries = 0;
  var _swipeWatcher = setInterval(function () {
    _tries++;
    if (_tries > 20) { clearInterval(_swipeWatcher); return; } // give up after 6s
    if (typeof swiper === 'undefined' || !swiper || !swiper.on) return;
    clearInterval(_swipeWatcher);
    swiper.on('slideChange', function () {
      if (subscribed()) return;
      var n = (parseInt(localStorage.getItem(LS_SWIPES) || '0', 10) || 0) + 1;
      localStorage.setItem(LS_SWIPES, n);
      if (n % SWIPE_EACH === 0) showBanner();
    });
  }, 300);

  // Generation hook — called from gen.php showResult()
  window.tgBanner = {
    onGeneration: function () {
      if (subscribed()) return;
      if (localStorage.getItem(LS_GEN) === '1') return;
      localStorage.setItem(LS_GEN, '1');
      setTimeout(showBanner, 800);
    }
  };
})();
