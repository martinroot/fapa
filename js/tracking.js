/* CID from URL */
function getCidFromUrl() {
  const m = window.location.search.match(/[?&]cid=(\d+)/);
  return m ? m[1] : '';
}

/* Lead tracking by cid */
function sendLead(leadNum) {
  const cid = getCidFromUrl();
  if (!cid) return;
  fetch('/scripts/track_lead.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ cid: cid, lead: String(leadNum) })
  }).catch(()=>{});
}

function markJsActive() {
  // "live" = JS executed for this click (matches tube semantics). Do NOT gate
  // on interaction or document.hidden — popunder opens in a background tab and
  // still runs JS / renders ads; gating on those undercounted live ~5x.
  if (jsMarked) return;
  const cid = getCidFromUrl();
  if (!cid) return;

  jsMarked = true;
  const data = new URLSearchParams({ cid: cid });
  if (navigator.sendBeacon) navigator.sendBeacon('/scripts/track_js.php', data);
  else fetch('/scripts/track_js.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: data }).catch(()=>{});
}
// Fire as soon as the script runs (JS is alive), plus on focus as a fallback.
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', markJsActive, { once: true });
else markJsActive();
document.addEventListener('visibilitychange', markJsActive);
window.addEventListener('focus', markJsActive);

function onFirstInteraction() {
  if (firstInteractionDone) return;
  firstInteractionDone = true;

  statsArmed = true;
  markJsActive();

  if (!lead1Sent) { sendLead(1); lead1Sent = true; }
}
window.addEventListener('touchstart', onFirstInteraction, { passive: true });
window.addEventListener('mousedown', onFirstInteraction);

/* Конфетти + баннер персонализации */
function showConfetti() {
  const container = document.getElementById('confettiContainer');
  if (!container) return;
  container.innerHTML = '';
  container.classList.remove('hidden');

  const colors = ['#f97316','#fb7185','#22c55e','#38bdf8','#a855f7','#facc15'];
  const pieces = 60;
  const vw = window.innerWidth || 360;

  for (let i = 0; i < pieces; i++) {
    const el = document.createElement('div');
    el.className = 'confetti-piece';
    const color = colors[Math.floor(Math.random() * colors.length)];
    const left  = Math.random() * vw;
    const delay = (Math.random() * 0.8).toFixed(2);
    const dur   = (1.4 + Math.random() * 0.8).toFixed(2);

    el.style.background = color;
    el.style.left = `${left}px`;
    el.style.animationDelay = `${delay}s`;
    el.style.animationDuration = `${dur}s`;
    container.appendChild(el);
  }

  setTimeout(() => {
    container.classList.add('hidden');
    container.innerHTML = '';
  }, 2200);
}

function showPersonalizedBannerOnce() {
  if (personalizedShown) return;
  personalizedShown = true;

  const banner = document.getElementById('personalizedBanner');
  if (!banner) return;

  banner.classList.remove('hidden', 'hide');
  banner.classList.add('show');
  showConfetti();

  const timeout = 4000 + Math.random() * 3000;
  setTimeout(() => {
    banner.classList.remove('show');
    banner.classList.add('hide');
    setTimeout(() => { banner.classList.add('hidden'); }, 450);
  }, timeout);
}

/* View / watchtime */
function saveView(videoId) {
  if (!statsArmed) return;
  const fd = new FormData();
  fd.append('video_id', videoId);
  fetch('/scripts/track_view.php', { method:'POST', body:fd }).catch(()=>{});
}
function saveWatchtime(videoId, percent) {
  if (!statsArmed) return;
  const fd = new FormData();
  fd.append('video_id', videoId);
  fd.append('percent', percent);
  fetch('/scripts/track_watch.php', { method:'POST', body:fd }).catch(()=>{});
}

window.addEventListener('beforeunload', () => {
  if (!statsArmed) return;
  const meta = videos[activeIndex];
  const v = getCurrentVideo();
  // view уже записан 1s-таймером в player.js; здесь только watchtime
  if (meta && v && v.duration && !isNaN(v.duration)) {
    const percent = (v.currentTime / v.duration) * 100;
    saveWatchtime(meta.id, percent.toFixed(2));
  }
});
