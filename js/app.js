/* app.js — auth, comments, profile nav
 * Loaded after ui.js. Does not modify swipe/player logic.
 */


// ══════════════════════════════════════════════
//  Registration Wall — nick only, zero friction
// ══════════════════════════════════════════════
async function submitRegWall() {
  const nick = ((document.getElementById('regWallNick') || {}).value || '').trim();
  const err  = document.getElementById('regWallError');
  const btn  = document.getElementById('regWallSubmit');

  if (err) err.style.display = 'none';
  if (!nick) { showRegWallError('Enter a nickname'); return; }
  if (nick.length < 2) { showRegWallError('Min 2 characters'); return; }

  if (btn) { btn.disabled = true; btn.textContent = '…'; }

  try {
    const resp = await fetch('/api/auth_password.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'register_nick_only', nick: nick})
    });
    const d = await resp.json();

    if (d.ok) {
      if (window.__FAPA_BOOT__) {
        window.__FAPA_BOOT__.siteUser = {id: d.id, name: nick, paid: false};
      }
      fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'reg_wall_success_register'})}).catch(()=>{});
      if (typeof onSwipeLimitDismissed === 'function') onSwipeLimitDismissed();
    } else {
      if (d.error === 'taken') {
        showRegWallError('Nickname taken — try another');
      } else {
        showRegWallError(d.error || 'Error, try again');
      }
      if (btn) { btn.disabled = false; btn.textContent = 'Continue →'; }
    }
  } catch(e) {
    showRegWallError('No connection');
    if (btn) { btn.disabled = false; btn.textContent = 'Continue →'; }
  }
}

function toggleRegWallMode() {} // kept for compatibility

function showRegWallError(msg) {
  const err = document.getElementById('regWallError');
  if (err) { err.textContent = msg; err.style.display = 'block'; }
}

// Legacy — kept so old references don't crash
window.onEmailLoginFromOverlay = function() {};
// ══════════════════════════════════════════════
//  Telegram Login Auth
// ══════════════════════════════════════════════
window.onTelegramAuth = async function(user) {
  try {
    const resp = await fetch('/api/tg_auth.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(user),
    });
    const data = await resp.json();
    if (data.ok) {
      // Patch boot data so ui.js gates work without reload
      if (window.__FAPA_BOOT__) {
        window.__FAPA_BOOT__.siteUser = {
          id:   data.id,
          name: data.name,
          paid: data.paid || false,
        };
      }
      appUser = {id: data.id, name: data.name};
      updateProfileAvatar();
      fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'tg_login_click'})}).catch(()=>{});
      if (typeof onSwipeLimitDismissed === 'function') onSwipeLimitDismissed();
      if (typeof maybeShowPaywall === 'function') maybeShowPaywall();
    }
  } catch(e) { console.error('TG auth error', e); }
};

// ══════════════════════════════════════════════
//  Telegram Stars Paywall
// ══════════════════════════════════════════════
let _pollPaidTimer = null;

window.startStarPayment = async function() {
  const btn = document.getElementById('paywallPayBtn');
  const status = document.getElementById('paywallStatus');
  if (btn) { btn.disabled = true; btn.textContent = 'Создаём счёт…'; }

  try {
    const resp = await fetch('/api/create_invoice.php', {method: 'POST'});
    const data = await resp.json();
    if (!data.ok || !data.invoice_url) throw new Error(data.error || 'no url');

    // Open invoice in Telegram
    window.open(data.invoice_url, '_blank');

    if (btn) { btn.disabled = false; btn.textContent = 'Оплатить в Telegram ⭐'; }
    if (status) status.textContent = 'Ожидаем оплату…';

    _pollPaid();
  } catch(e) {
    if (btn) { btn.disabled = false; btn.textContent = 'Оплатить в Telegram ⭐'; }
    if (status) status.textContent = 'Ошибка. Попробуйте ещё раз.';
  }
};

function _pollPaid() {
  clearInterval(_pollPaidTimer);
  _pollPaidTimer = setInterval(async () => {
    try {
      const r = await fetch('/api/check_paid.php');
      const d = await r.json();
      if (d.paid) {
        clearInterval(_pollPaidTimer);
        if (window.__FAPA_BOOT__ && window.__FAPA_BOOT__.siteUser) {
          window.__FAPA_BOOT__.siteUser.paid = true;
        }
        if (window.location.pathname.indexOf('premium') !== -1) {
          window.location.href = '/?paid=1';
        } else if (typeof onPaywallDismissed === 'function') {
          onPaywallDismissed();
        }
      }
    } catch(_) {}
  }, 3000);
}

// ══════════════════════════════════════════════
//  State
// ══════════════════════════════════════════════
let appUser = (window.__FAPA_BOOT__ && window.__FAPA_BOOT__.siteUser) || null;
let commentCurrentVideoId = null;
let commentCurrentCount   = 0;

// ══════════════════════════════════════════════
//  Auth helpers
// ══════════════════════════════════════════════
function isLoggedIn() {
  return !!appUser;
}

function updateProfileAvatar() {
  const avatar = document.getElementById('profileNavAvatar');
  if (!avatar) return;

  if (isLoggedIn()) {
    const initial = (appUser.name || '?').charAt(0).toUpperCase();
    avatar.innerHTML = '<span class="profile-nav-initial" style="font-size:15px;font-weight:900;">' + initial + '</span>';
    avatar.classList.add('logged-in');
  } else {
    avatar.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>';
    avatar.classList.remove('logged-in');
  }
}

// ══════════════════════════════════════════════
//  Auth Modal
// ══════════════════════════════════════════════
function openAuthModal() {
  document.getElementById('authOverlay').classList.add('open');
  document.getElementById('authModal').classList.add('open');
  document.getElementById('authOverlay').setAttribute('aria-hidden', 'false');
  setTimeout(() => {
    const nameInput = document.getElementById('authName');
    if (nameInput) nameInput.focus();
  }, 350);
}

function closeAuthModal() {
  document.getElementById('authOverlay').classList.remove('open');
  document.getElementById('authModal').classList.remove('open');
  document.getElementById('authOverlay').setAttribute('aria-hidden', 'true');
  const err = document.getElementById('authError');
  if (err) err.style.display = 'none';
}

async function submitAuth(name, email) {
  const btn = document.getElementById('authSubmitBtn');
  if (btn) { btn.disabled = true; btn.textContent = '…'; }

  try {
    const resp = await fetch('/api/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, email }),
    });
    const data = await resp.json();

    if (data.ok) {
      appUser = { id: data.id, name: data.name, email: data.email };
      try { localStorage.setItem('fapa_user_name', data.name); } catch(_) {}
      updateProfileAvatar();
      closeAuthModal();
      // If on premium page — reload to show payment buttons
      if (window.location.pathname.indexOf('premium') !== -1) {
        window.location.reload();
        return;
      }
      // If comment drawer was waiting — refresh its input
      refreshCommentInput();
    } else {
      showAuthError(data.error || 'Ошибка');
    }
  } catch (e) {
    showAuthError('Нет соединения');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Continue →'; }
  }
}

function showAuthError(msg) {
  const err = document.getElementById('authError');
  if (!err) return;
  err.textContent = msg;
  err.style.display = 'block';
}

// ══════════════════════════════════════════════
//  Comment Drawer
// ══════════════════════════════════════════════
function openCommentDrawer(videoId) {
  commentCurrentVideoId = videoId;
  document.getElementById('commentOverlay').classList.add('open');
  document.getElementById('commentDrawer').classList.add('open');
  loadComments(videoId);
  refreshCommentInput();
}

function closeCommentDrawer() {
  document.getElementById('commentOverlay').classList.remove('open');
  document.getElementById('commentDrawer').classList.remove('open');
}

function refreshCommentInput() {
  const notAuth  = document.getElementById('commentNotAuth');
  const form     = document.getElementById('commentForm');
  if (!notAuth || !form) return;

  if (isLoggedIn()) {
    notAuth.style.display = 'none';
    form.style.display    = 'flex';
  } else {
    notAuth.style.display = 'flex';
    form.style.display    = 'none';
  }
}

function formatTimeAgo(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr + ' UTC').getTime()) / 1000);
  if (diff < 60)   return 'just now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return Math.floor(diff / 86400) + 'd ago';
}

function renderComments(comments, total) {
  const list = document.getElementById('commentList');
  const title = document.getElementById('commentDrawerTitle');
  if (!list) return;

  if (title) title.textContent = 'Comments' + (total > 0 ? ' ' + total : '');

  if (!comments.length) {
    list.innerHTML = '<div id="commentEmpty">Be the first to comment 💬</div>';
    return;
  }

  list.innerHTML = comments.map(c => {
    const name = escHtml(c.display_name || 'Guest');
    const text = escHtml(c.text || '');
    const time = formatTimeAgo(c.created_at || '');
    return `<div class="comment-item">
      <div class="comment-name">${name}</div>
      <div class="comment-text">${text}</div>
      <div class="comment-time">${time}</div>
    </div>`;
  }).join('');
}

function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function loadComments(videoId) {
  const list = document.getElementById('commentList');
  if (list) list.innerHTML = '<div id="commentEmpty" style="opacity:0.5">Loading…</div>';

  try {
    const resp = await fetch('/api/comments.php?video_id=' + videoId + '&limit=30');
    const data = await resp.json();
    renderComments(data.comments || [], data.total || 0);
  } catch(e) {
    if (list) list.innerHTML = '<div id="commentEmpty">Failed to load</div>';
  }
}

async function postComment(text) {
  if (!commentCurrentVideoId || !text.trim()) return;

  const btn   = document.getElementById('commentSendBtn');
  const input = document.getElementById('commentInput');
  if (btn)   btn.disabled = true;
  if (input) input.disabled = true;

  try {
    const resp = await fetch('/api/comments.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ video_id: commentCurrentVideoId, text: text.trim() }),
    });
    const data = await resp.json();

    if (data.ok) {
      if (input) input.value = '';
      // Reload comments
      await loadComments(commentCurrentVideoId);
      // Update counter on slide
      updateCommentCountOnSlide(commentCurrentVideoId);
    }
  } catch(e) {}

  if (btn)   btn.disabled = false;
  if (input) { input.disabled = false; input.focus(); }
}

function updateCommentCountOnSlide(videoId) {
  // Update the .commentsCount text on the current slide's right panel
  const counts = document.querySelectorAll('.commentsCount');
  counts.forEach(el => {
    const cur = parseInt(el.textContent, 10) || 0;
    el.textContent = String(cur + 1);
  });
}

// ══════════════════════════════════════════════
//  Get current video ID from swiper
// ══════════════════════════════════════════════
function getCurrentVideoMeta() {
  // 'videos' and 'activeIndex' are globals from state.js / ui.js
  if (typeof videos !== 'undefined' && typeof activeIndex !== 'undefined') {
    return videos[activeIndex] || null;
  }
  return null;
}

// ══════════════════════════════════════════════
//  Comment counts on slide change
// ══════════════════════════════════════════════
function fetchAndShowCommentCount(videoId) {
  if (!videoId) return;
  fetch('/api/comments.php?video_id=' + videoId + '&limit=1')
    .then(r => r.json())
    .then(data => {
      const counts = document.querySelectorAll('.commentsCount');
      const total  = data.total || 0;
      counts.forEach(el => { el.textContent = total > 0 ? String(total) : ''; });
    })
    .catch(() => {});
}

// ══════════════════════════════════════════════
//  DOM Ready
// ══════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {

  // Init avatar
  updateProfileAvatar();
  // Enter key in reg wall
  var regNick = document.getElementById('regWallNick');
  if (regNick) regNick.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); submitRegWall(); }
  });

  // ── Auth modal close ──
  var authOverlay = document.getElementById('authOverlay');
  var authClose   = document.getElementById('authClose');
  if (authOverlay) authOverlay.addEventListener('click', closeAuthModal);
  if (authClose)   authClose.addEventListener('click', closeAuthModal);

  // Prevent modal itself from closing on click
  var authModal = document.getElementById('authModal');
  if (authModal) authModal.addEventListener('click', function(e) { e.stopPropagation(); });

  // ── Auth form submit ──
  var authForm = document.getElementById('authForm');
  if (authForm) {
    authForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var email = (document.getElementById('authEmail') || {}).value || '';
      email = email.trim();
      if (!email) { showAuthError('Please enter your email'); return; }
      submitAuth('', email);
    });
  }

  // ── Comment buttons (delegated — slides are created dynamically) ──
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.commentBtn');
    if (!btn) return;
    e.stopPropagation();
    var meta = getCurrentVideoMeta();
    if (meta) openCommentDrawer(meta.id);
  });

  // ── Comment drawer close ──
  var commentOverlay    = document.getElementById('commentOverlay');
  var commentDrawerClose = document.getElementById('commentDrawerClose');
  if (commentOverlay)    commentOverlay.addEventListener('click', closeCommentDrawer);
  if (commentDrawerClose) commentDrawerClose.addEventListener('click', closeCommentDrawer);

  var commentDrawer = document.getElementById('commentDrawer');
  if (commentDrawer) commentDrawer.addEventListener('click', function(e) { e.stopPropagation(); });

  // ── Sign in from comment drawer ──
  var commentSignIn = document.getElementById('commentSignInBtn');
  if (commentSignIn) {
    commentSignIn.addEventListener('click', function() {
      closeCommentDrawer();
      openAuthModal();
    });
  }

  // ── Send comment ──
  var sendBtn    = document.getElementById('commentSendBtn');
  var commentInp = document.getElementById('commentInput');
  if (sendBtn) {
    sendBtn.addEventListener('click', function() {
      if (commentInp) postComment(commentInp.value);
    });
  }
  if (commentInp) {
    commentInp.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        postComment(commentInp.value);
      }
    });
  }

  // ── Update comment count on slide change ──
  // Hook into swiper slideChange after swiper is initialized
  var _initWatcher = setInterval(function() {
    if (typeof swiper !== 'undefined' && swiper && swiper.on) {
      clearInterval(_initWatcher);
      swiper.on('slideChange', function() {
        var meta = getCurrentVideoMeta();
        if (meta) fetchAndShowCommentCount(meta.id);
        // Close comment drawer on swipe
        closeCommentDrawer();
      });
      // Initial count
      var meta = getCurrentVideoMeta();
      if (meta) fetchAndShowCommentCount(meta.id);
    }
  }, 200);


});
