<?php
declare(strict_types=1);
require_once __DIR__ . '/ip_guard.php';
require_once __DIR__ . '/cmd/config.php';

$uiq = $_COOKIE['uiq'] ?? bin2hex(random_bytes(8));
if (!isset($_COOKIE['uiq'])) {
    setcookie('uiq', $uiq, time() + 31536000, '/', '', false, true);
}

$conn = db_connect();
ensure_site_schema($conn);
$siteUser     = get_site_user($conn);
$siteUserJson = json_encode($siteUser, JSON_UNESCAPED_UNICODE);
$isPaid = false;
$genCount = 0;
$__startTok = defined('START_TOKENS') ? (int)START_TOKENS : 900;
$__anonStart = defined('ANON_START_TOKENS') ? (int)ANON_START_TOKENS : 300;
$__freeCached = defined('ANON_FREE_CACHED') ? (int)ANON_FREE_CACHED : 1;
$__genCost  = defined('GEN_TOKEN_COST') ? (int)GEN_TOKEN_COST : 300;
// баланс анонима сервер ведёт в куке fapa_spent — показываем то же число
$__anonTok  = max(0, $__anonStart - $__genCost * max(0, (int)($_COOKIE['fapa_spent'] ?? 0)));
if ($siteUser) {
    $isPaid = !empty($siteUser['paid_until']) && strtotime($siteUser['paid_until']) > time();
    $uid = (int)$siteUser['id'];
    if (!$isPaid) $siteUser['tokens'] = daily_refill_tokens($conn, $uid); // 5 генераций в сутки
    $qToday = $conn->prepare('SELECT COUNT(*) c FROM user_generations WHERE site_user_id = ? AND created_at > NOW() - INTERVAL 1 DAY');
    $qToday->bind_param('i', $uid);
    $qToday->execute();
    $usedToday = min(5, (int)($qToday->get_result()->fetch_assoc()['c'] ?? 0)); // витрина: шкала 5
    $qToday->close();
    $r = $conn->query("SELECT COUNT(*) as cnt FROM user_generations WHERE site_user_id = $uid AND status = 'done'");
    if ($r) $genCount = (int)($r->fetch_assoc()['cnt'] ?? 0);
} else {
    $usedToday = 0;
}
// Check anon paid session
if (!$siteUser && $uiq) {
    $usedToday = 0;
    try {
        $ac = db_connect();
        $ac->query("CREATE TABLE IF NOT EXISTS anon_paid_sessions (uiq VARCHAR(64) NOT NULL PRIMARY KEY, paid_until DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $uiqE2 = $ac->real_escape_string($uiq);
        $apr = $ac->query("SELECT paid_until FROM anon_paid_sessions WHERE uiq='$uiqE2' AND paid_until > NOW() LIMIT 1");
        if ($apr && $apr->num_rows > 0) { $isPaid = true; }
        $ac->close();
    } catch (Throwable $_) {}
}
$conn->close();

// Show payment success toast if returning from Trybit
$showPaidToast = !empty($_GET['paid']);

// Track gen page visit
try {
    $tc = db_connect();
    $uiqE = $tc->real_escape_string($uiq);
    $tc->query("INSERT INTO funnel_events (event_type, page, uiq) VALUES ('gen_page_visit', 'gen', '$uiqE')");
    $tc->close();
} catch (Throwable $_) {}

$avatarColor = '#ec4899';
if ($siteUser) {
    $initial = mb_strtoupper(mb_substr($siteUser['name'], 0, 1));
    $colors  = ['#ec4899','#a855f7','#3b82f6','#10b981','#f59e0b','#ef4444','#06b6d4','#8b5cf6'];
    $avatarColor = $colors[ord($initial) % count($colors)];
}

// Load showcase catalog from GPU portal
$showcaseRaw  = @file_get_contents(__DIR__ . '/gpu-portal/showcase.json');
$showcaseAll  = $showcaseRaw ? (json_decode($showcaseRaw, true) ?? []) : [];
// Filter: must have name + source_image + generated_image, skip numeric-only names
$showcaseItems = array_values(array_filter($showcaseAll, function($it) {
    $name = trim($it['name'] ?? '');
    $src  = $it['source_image'] ?? '';
    $gen  = $it['generated_image'] ?? '';
    if (!$name || !$src || !$gen) return false;
    if (preg_match('/^\d+$/', $name)) return false;
    if (($it['type'] ?? '') === 'video') return false;
    // файл шаблона мог быть удалён в админке — не показываем битые плитки
    $need = [$src, $gen];
    if (($it['type'] ?? '') === 'dual' && !empty($it['scene_image'])) $need[] = $it['scene_image'];
    foreach ($need as $u) {
        if (!preg_match('#^/(showcase|dataset)/#', $u)) return false;
        if (!is_file(__DIR__ . '/gpu-portal' . $u)) return false;
    }
    return true;
}));

// Active template from URL
$activeTplId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['tpl'] ?? '');
$activeTpl   = null;
foreach ($showcaseItems as $it) {
    if ($it['id'] === $activeTplId) { $activeTpl = $it; break; }
}
if (!$activeTpl && $showcaseItems) $activeTpl = $showcaseItems[0];

// BA header — derived from active template
$activeTplType  = $activeTpl['type'] ?? 'img2img';
$heroBeforeImg  = $activeTpl['source_image'] ?? '/showcase/test_1783605158.jpg';
$heroAfterImg   = $activeTpl['generated_image'] ?? '/showcase/undress_result_001.png';
$heroImg1       = $activeTpl['image1'] ?? $heroBeforeImg;
$heroImg2       = $activeTpl['image2'] ?? '';
$heroVideoUrl   = $activeTpl['video_url'] ?? '';
// Preloaded upload photo — new girl (users think it's just a suggested photo)
$defaultDemoSrc    = '/showcase/demo_girl_new.jpg';
// Demo result — pre-generated result of the new girl (shown after fake animation, no GPU cost)
$defaultDemoResult = '/showcase/gen_1783626085_44d7e7.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title>AI Generate — Hardpulse</title>
  <meta name="theme-color" content="#000000"/>
    <?php $__lang = detect_lang(); ?>
  <script src="/lang/<?php echo $__lang; ?>.js?v=13"></script>
  <script>window.__langCode='<?php echo $__lang; ?>';</script>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/css/feed.css?v=2">
  <style>
    html, body { overflow-y: auto !important; overscroll-behavior: auto !important; touch-action: auto !important; }
    body { background:#000; color:#fff; min-height:100vh; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; overflow-x:hidden; }
    img, a, video { -webkit-touch-callout: none !important; }
    * { user-select: none; -webkit-user-select: none; }
    .gen-page { max-width:575px; margin:0 auto; padding:16px 16px 120px; }

    /* Top bar */
    .gen-topbar { display:flex; align-items:center; gap:12px; padding:12px 0 18px; }
    .gen-topbar a { display:flex; align-items:center; justify-content:center; width:36px; height:36px;
      border-radius:9999px; background:rgba(255,255,255,0.08); color:rgba(255,255,255,0.8);
      text-decoration:none; border:1px solid rgba(255,255,255,0.1); }
    .gen-topbar h1 { font-size:18px; font-weight:900; margin:0; flex:1; }

    /* Before / After — fixed same-size portrait thumbnails */
    .ba-wrap { display:flex; align-items:center; justify-content:center; gap:0; margin-bottom:22px; }
    .ba-img-wrap { width:90px; height:160px; border-radius:12px; overflow:hidden; position:relative;
      border:1px solid rgba(255,255,255,0.15); flex-shrink:0; }
    .ba-img { width:100%; height:100%; object-fit:cover; object-position:top center; display:block; }
    .ba-tag { position:absolute; bottom:7px; left:50%; transform:translateX(-50%);
      background:rgba(0,0,0,0.7); backdrop-filter:blur(6px);
      color:#fff; font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase;
      padding:3px 10px; border-radius:9999px; white-space:nowrap; }
    .ba-divider { width:40px; display:flex; flex-direction:column; align-items:center;
      justify-content:center; flex-shrink:0; gap:4px; }
    .ba-divider-icon { width:30px; height:30px; border-radius:9999px;
      background:linear-gradient(135deg,#ec4899,#a855f7);
      display:flex; align-items:center; justify-content:center; font-size:15px; }

    /* Catalog section */
    .cat-section { margin-bottom:8px; }
    .cat-label { font-size:11px; font-weight:700; color:rgba(255,255,255,0.35);
      letter-spacing:.8px; text-transform:uppercase; margin-bottom:10px; }

    /* Tabs */
    .gen-tabs { display:flex; gap:8px; margin-bottom:24px; }
    .gen-tab { flex:1; height:46px; border-radius:13px; border:1.5px solid rgba(255,255,255,0.1);
      background:rgba(255,255,255,0.04); color:rgba(255,255,255,0.5); font-size:12px; font-weight:700;
      cursor:pointer; transition:all 0.15s; display:flex; flex-direction:column;
      align-items:center; justify-content:center; gap:1px; position:relative;
      padding:0; line-height:1.2; }
    .gen-tab .tab-icon { font-size:15px; }
    .gen-tab .tab-name { font-size:11px; }
    .gen-tab .tab-lock { position:absolute; top:4px; right:6px; font-size:9px; opacity:0.6; }
    .gen-tab.active { border-color:rgba(236,72,153,0.7); background:rgba(236,72,153,0.1); color:#fff; }
    .gen-tab.locked { opacity:0.6; cursor:default; }
    .gen-tab:hover:not(.active):not(.locked) { background:rgba(255,255,255,0.08); }

    /* Demo section */
    .demo-label { font-size:14px; font-weight:900; color:#fff; margin-bottom:4px; }
    .demo-sub   { font-size:13px; color:rgba(255,255,255,0.4); margin-bottom:16px; }

    /* Upload */
    .upload-wrap { position:relative; margin-bottom:14px; }
    .upload-area { border:2px dashed rgba(236,72,153,0.45); border-radius:24px; padding:36px 20px 28px;
      text-align:center; cursor:pointer; background:rgba(236,72,153,0.04);
      transition:border-color 0.2s, background 0.2s; position:relative; overflow:hidden;
      margin-bottom:14px; }
    .upload-area:hover, .upload-area.drag { border-color:rgba(236,72,153,0.85); background:rgba(236,72,153,0.09); }
    .upload-area:active { background:rgba(236,72,153,0.13); }
    .upload-area input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; z-index:2; }
    .upload-icon-wrap { margin-bottom:14px; }
    .upload-icon-circle { width:62px; height:62px; border-radius:50%; margin:0 auto;
      background:linear-gradient(135deg,rgba(236,72,153,0.2),rgba(168,85,247,0.2));
      border:1.5px solid rgba(236,72,153,0.4);
      display:flex; align-items:center; justify-content:center;
      color:rgba(236,72,153,0.9); }
    .upload-icon { font-size:34px; margin-bottom:10px; }
    .upload-title { font-size:17px; font-weight:800; margin-bottom:6px; color:#fff; }
    .upload-sub { font-size:13px; color:rgba(255,255,255,0.38); line-height:1.4; }
    /* Photo preview replaces upload area completely */
    .preview-wrap { display:none; position:relative; border-radius:20px; overflow:hidden;
      margin-bottom:14px; border:1px solid rgba(255,255,255,0.12); background:#111;
      width:100%; height:380px; }
    .preview-wrap img { position:absolute; inset:0; width:100%; height:100%;
      object-fit:cover; object-position:center top; display:block; }
    .preview-change { position:absolute; bottom:10px; right:10px; background:rgba(0,0,0,0.72);
      color:#fff; font-size:12px; font-weight:700; padding:5px 12px; border-radius:9999px;
      backdrop-filter:blur(4px); pointer-events:none; }
    .photo-preview { display:none; }


    #demoOverlay { position:absolute; inset:0; pointer-events:none; z-index:4; }
    #demoOverlay .demo-tip { position:absolute; bottom:0; left:0; right:0;
      background:linear-gradient(transparent,rgba(0,0,0,0.82)); padding:28px 12px 12px;
      display:flex; align-items:flex-end; justify-content:space-between; }
    #demoOverlay .demo-tip-text { font-size:12px; color:rgba(255,255,255,0.7); line-height:1.4; }
    #demoOverlay .demo-tip-text b { color:#fff; display:block; font-size:13px; }
    #clearDemoBtn { background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.25);
      color:#fff; border-radius:10px; padding:7px 14px; font-size:12px; font-weight:700;
      cursor:pointer; pointer-events:all; backdrop-filter:blur(4px); flex-shrink:0; }
    #clearDemoBtn:hover { background:rgba(255,255,255,0.25); }

    /* Sign-in prompt (for non-registered on video/dual tabs) */
    .signin-prompt { text-align:center; padding:40px 20px;
      border:1.5px solid rgba(255,255,255,0.1); border-radius:20px;
      background:rgba(255,255,255,0.03); }
    .signin-prompt .sp-icon { font-size:36px; margin-bottom:12px; }
    .signin-prompt p { font-size:15px; color:rgba(255,255,255,0.7); margin-bottom:16px; line-height:1.5; }
    .signin-btn { display:inline-block; padding:12px 28px; border-radius:9999px;
      background:#ec4899; color:#fff; font-weight:700; font-size:15px; text-decoration:none; border:0; cursor:pointer; }

    /* Second photo (dual) */
    .photo2-area { margin-top:12px; }

    /* Generate button */
    .gen-btn { width:100%; height:54px; border-radius:18px; font-weight:900; font-size:17px;
      border:0; cursor:pointer; color:#fff; margin-top:18px;
      background:linear-gradient(90deg,#ec4899,#a855f7,#ec4899);
      background-size:200% auto; animation:btnFlow 2.5s ease-in-out infinite; }
    .gen-btn:disabled { opacity:0.5; animation:none; background:#333; cursor:not-allowed; }
    @keyframes btnFlow { 0%{background-position:0% 50%} 50%{background-position:100% 50%} 100%{background-position:0% 50%} }

    /* Wallet widget */
    .wallet-widget { display:flex; align-items:center; gap:12px; background:rgba(5,22,12,.9); border:1.5px solid rgba(52,211,153,.3); border-radius:16px; padding:12px 14px; margin:10px 0 14px; }
    .wallet-icon-wrap { width:42px; height:42px; border-radius:12px; background:rgba(52,211,153,.12); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .wallet-info { flex:1; min-width:0; }
    .wallet-info-label { font-size:10px; color:rgba(255,255,255,.4); text-transform:uppercase; letter-spacing:.6px; font-weight:700; margin-bottom:2px; }
    .wallet-info-amount { font-size:18px; font-weight:900; color:#fff; line-height:1.1; white-space:nowrap; }
    .wallet-info-amount span { font-size:13px; font-weight:600; opacity:.6; }
    .wallet-info-usd { font-size:12px; color:#34d399; font-weight:700; margin-top:1px; }
    .wallet-topup-btn { background:linear-gradient(135deg,#ec4899,#a855f7); color:#fff; font-size:13px; font-weight:900; padding:10px 18px; border-radius:10px; border:none; cursor:pointer; white-space:nowrap; letter-spacing:.4px; flex-shrink:0; transition:opacity .15s; }
    .wallet-topup-btn:active { opacity:.8; }
    /* Topup popup */
    #topupOverlay { display:none; position:fixed; inset:0; z-index:200; background:rgba(0,0,0,.55); backdrop-filter:blur(5px); }
    #topupOverlay.open { display:block; }
    #topupSheet { display:none; position:fixed; bottom:0; left:50%; transform:translateX(-50%); width:100%; max-width:575px; background:#111; border-radius:24px 24px 0 0; border-top:1px solid rgba(255,255,255,.1); z-index:201; padding:24px 20px max(env(safe-area-inset-bottom,0px),28px); }
    #topupSheet.open { display:block; }
    #topupClose { position:absolute; top:16px; right:16px; width:32px; height:32px; border-radius:9999px; background:rgba(255,255,255,.08); border:0; display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,.6); cursor:pointer; font-size:18px; }
    .topup-balance { text-align:center; margin-bottom:16px; }
    .topup-balance-num { font-size:30px; font-weight:900; color:#facc15; line-height:1; }
    .topup-balance-lbl { font-size:12px; color:rgba(255,255,255,.45); margin-top:3px; }
    .topup-packages { display:flex; gap:8px; margin-bottom:16px; }
    .topup-pkg { flex:1; background:rgba(255,255,255,.05); border:2px solid rgba(255,255,255,.1); border-radius:14px; padding:12px 6px; text-align:center; cursor:pointer; transition:border-color .15s,background .15s; }
    .topup-pkg.selected { border-color:#facc15; background:rgba(250,204,21,.08); }
    .topup-pkg-tokens { font-size:15px; font-weight:900; color:#facc15; line-height:1; }
    .topup-pkg-tok-lbl { font-size:9px; color:rgba(255,255,255,.45); text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; }
    .topup-pkg-price { font-size:13px; font-weight:700; color:rgba(255,255,255,.7); margin-top:4px; }
    .topup-pkg-bonus { font-size:9px; color:#34d399; font-weight:700; margin-top:2px; }
    .topup-pay-btn { display:flex; align-items:center; gap:10px; width:100%; padding:14px 16px; border:none; border-radius:14px; cursor:pointer; margin-bottom:8px; text-decoration:none; transition:opacity .15s; }
    .topup-pay-btn:active { opacity:.8; }
    .topup-pay-btn-inner { flex:1; text-align:left; }
    .topup-pay-btn-title { font-size:15px; font-weight:800; color:#fff; }
    .topup-pay-btn-sub { font-size:11px; color:rgba(255,255,255,.55); margin-top:2px; }
    .topup-pay-btn-price { font-size:17px; font-weight:900; color:#fff; white-space:nowrap; }
    .topup-stars { background:linear-gradient(135deg,#229ED9,#1a7fbf); }
    .topup-crypto { background:linear-gradient(135deg,#18181b,#0f0f10); border:1px solid rgba(247,147,26,.35); }
    .topup-crypto .topup-pay-btn-price { background:linear-gradient(90deg,#f7931a,#f0c040); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
    /* Token balance bar */
    #tokenBar { display:flex; align-items:center; justify-content:space-between; margin-top:12px;
      padding:10px 14px; border-radius:12px; background:rgba(255,255,255,.04);
      border:1px solid rgba(255,255,255,.09); font-size:12px; }
    #tokenBar .tb-cost { color:rgba(255,255,255,.55); }
    #tokenBar .tb-cost b { color:#ec4899; font-weight:800; }
    #tokenBar .tb-bal { color:rgba(255,255,255,.55); }
    #tokenBar .tb-bal b { color:#a855f7; font-weight:800; font-size:14px; }
    #tokenBar.tb-empty .tb-bal b { color:#ef4444; }
    /* Video locked */
    .video-locked-msg { text-align:center; padding:28px 16px 20px; }
    .vlm-icon { font-size:40px; margin-bottom:10px; }
    .vlm-title { font-size:17px; font-weight:900; margin-bottom:4px; }
    .vlm-cost { font-size:13px; color:rgba(255,255,255,.5); margin-bottom:12px; }
    .vlm-cost b { color:#f59e0b; font-weight:800; font-size:15px; }
    .vlm-sub { font-size:12px; color:rgba(255,255,255,.4); line-height:1.5; margin-bottom:18px; }
    .vlm-btn { display:inline-block; padding:11px 28px; border-radius:9999px;
      background:linear-gradient(135deg,#ec4899,#a855f7); color:#fff; font-weight:800;
      font-size:14px; text-decoration:none; }

    /* Progress */
    #progressScreen { display:none; text-align:center; padding:32px 0 20px; }
    .progress-photo-wrap { position:relative; width:130px; margin:0 auto 24px; }
    .progress-photo { width:130px; height:174px; object-fit:cover; border-radius:18px;
      display:block; animation:photoGlow 2s ease-in-out infinite; }
    @keyframes photoGlow {
      0%,100% { box-shadow:0 0 0 0 rgba(236,72,153,0); filter:brightness(1); }
      50%      { box-shadow:0 0 30px 8px rgba(236,72,153,0.5); filter:brightness(1.08); }
    }
    .progress-ring { position:absolute; inset:-8px; border-radius:26px;
      border:2px solid transparent;
      background:linear-gradient(#000,#000) padding-box,
                 linear-gradient(135deg,#ec4899,#a855f7,#3b82f6,#ec4899) border-box;
      animation:ringRotate 3s linear infinite; }
    @keyframes ringRotate {
      0%   { background:linear-gradient(#000,#000) padding-box, linear-gradient(0deg,#ec4899,#a855f7,#3b82f6,#ec4899) border-box; }
      100% { background:linear-gradient(#000,#000) padding-box, linear-gradient(360deg,#ec4899,#a855f7,#3b82f6,#ec4899) border-box; }
    }
    .progress-sparks { position:absolute; inset:0; pointer-events:none; }
    .spark { position:absolute; width:4px; height:4px; border-radius:9999px;
      background:#ec4899; animation:sparkFloat 2s ease-in-out infinite; opacity:0; }
    @keyframes sparkFloat {
      0%   { opacity:0; transform:translate(0,0) scale(1); }
      30%  { opacity:1; }
      100% { opacity:0; transform:translate(var(--tx),var(--ty)) scale(0.3); }
    }
    .progress-title { font-size:20px; font-weight:900; margin-bottom:6px;
      background:linear-gradient(90deg,#ec4899,#a855f7); -webkit-background-clip:text;
      -webkit-text-fill-color:transparent; background-clip:text; }
    .progress-sub { font-size:13px; color:rgba(255,255,255,0.45); margin-bottom:22px; }
    .progress-bar-wrap { background:rgba(255,255,255,0.10); border-radius:9999px; height:10px; overflow:hidden; margin:0 8px; box-shadow:0 0 8px rgba(34,197,94,0.2); }
    .progress-bar-fill { height:100%; border-radius:9999px; width:0%;
      background:linear-gradient(90deg,#22c55e,#4ade80,#22c55e); background-size:200% 100%;
      transition:width 0.8s ease; animation:barShimmer 1.5s linear infinite; }
    @keyframes barShimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
    .progress-status { font-size:12px; color:rgba(255,255,255,0.4); margin-top:10px;
      animation:blink 1.5s ease-in-out infinite; }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.4} }
    @keyframes uploadPulse { 0%,100%{border-color:rgba(236,72,153,0.45);box-shadow:none} 50%{border-color:rgba(236,72,153,1);box-shadow:0 0 0 6px rgba(236,72,153,0.18),0 0 18px rgba(236,72,153,0.35)} }
    .upload-area.pulsing { animation:uploadPulse 1s ease-in-out 4; }
    .progress-time   { font-size:11px; color:rgba(255,255,255,0.25); margin-top:4px; }

    /* Result — fullscreen overlay */
    #resultScreen { display:none; position:fixed; inset:0; z-index:200;
      background:#000; flex-direction:column; }
    #resultScreen.open { display:flex; }
    .result-img-area { flex:1; display:flex; align-items:center; justify-content:center;
      overflow:hidden; padding:0; min-height:0; }
    .result-img-area img,
    .result-img-area video { max-width:100%; max-height:100%; object-fit:contain;
      display:block; border-radius:0; }
    .result-bottom { flex-shrink:0; padding:16px 16px calc(16px + env(safe-area-inset-bottom));
      background:linear-gradient(transparent, rgba(0,0,0,0.95));
      display:flex; flex-direction:column; gap:10px; }
    .result-actions { display:flex; gap:10px; }
    .result-btn { flex:1; min-width:0; height:58px; border-radius:16px; font-weight:900; font-size:17px;
      text-transform:uppercase; letter-spacing:.4px; text-align:center; line-height:1.15; padding:0 10px;
      border:0; cursor:pointer; display:flex; align-items:center; justify-content:center;
      gap:8px; text-decoration:none; }
    .result-btn span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .result-btn-save { background:linear-gradient(135deg,#ec4899,#a855f7); color:#fff;
      box-shadow:0 8px 24px rgba(236,72,153,.28);
      border:1px solid rgba(255,255,255,0.2); }
    .result-btn-back { background:rgba(255,255,255,0.08); color:#fff;
      border:1px solid rgba(255,255,255,0.1); font-size:14px; }
    .result-split-wrap { display:none; padding:0 16px 8px; }
    .result-split-img { width:100%; border-radius:12px; display:block; max-height:120px; object-fit:cover; }

    /* Bottom bar */
    /* пока идёт генерация — уводить со страницы нельзя, результат потеряется */
    body.gen-busy .bottom-bar, body.gen-busy .cat-item { opacity:.4; }
    #genBusyToast { position:fixed; left:50%; bottom:96px; transform:translateX(-50%);
      background:rgba(20,20,22,.96); color:#fff; font-size:13px; font-weight:700; line-height:1.35;
      padding:11px 16px; border-radius:12px; border:1px solid rgba(255,255,255,.14);
      z-index:9999; opacity:0; pointer-events:none; transition:opacity .18s;
      max-width:86vw; text-align:center; }
    #genBusyToast.show { opacity:1; }
    .bottom-bar { position:fixed; bottom:0; left:50%; transform:translateX(-50%);
      background:#000; width:100%; max-width:575px; z-index:50; }
  </style>
<script defer src="https://vibemetrics.click/tracker.js" data-site-id="fapa-cam" data-site-key="7b8cH_grFS_eZEm3Itb4Mh-hW28sjhia_WegOQDRd_k"></script>
</head>
<body>

<div class="gen-page">
  <div class="gen-topbar">
    <a href="/catalog.php" aria-label="Back">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
    </a>
    <h1 data-i18n="gen_page_title">✨ AI Generate</h1>
  </div>

  <!-- Wallet widget -->
  <div class="wallet-widget">
    <div class="wallet-icon-wrap">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M16 12h.01"/><path d="M2 10h20"/></svg>
    </div>
    <div class="wallet-info">
      <div class="wallet-info-label">Daily limit</div>
      <div class="wallet-info-amount"><span id="walletGens"><?php echo $isPaid ? '∞' : max(0, (defined('DAILY_FREE_GEN') ? DAILY_FREE_GEN : 5) - (int)($usedToday ?? 0)); ?></span><span>/<?php echo $isPaid ? '∞' : (defined('DAILY_FREE_GEN') ? DAILY_FREE_GEN : 5); ?> gens</span></div>
      <div class="wallet-info-usd" style="margin-top:6px"><div style="height:6px;border-radius:6px;background:rgba(255,255,255,.12);overflow:hidden"><div id="dailyBar" style="height:100%;width:<?php echo $isPaid ? 6 : max(6, min(100, round(100 * min(5, (int)($usedToday ?? 0)) / 5))); ?>%;background:linear-gradient(90deg,#ec4899,#a855f7 60%,#6366f1);box-shadow:0 0 10px rgba(168,85,247,.65);border-radius:6px;transition:width .3s"></div></div></div>
    </div>
    <button class="wallet-topup-btn" id="topupTriggerBtn" style="display:none">TOP UP</button>
  </div>

  <!-- Before / After -->
  <div class="ba-wrap" id="baWrap">
    <!-- img2img / video: single before photo (hidden for dual) -->
    <div class="ba-img-wrap" id="baSingleBefore" style="<?php echo $activeTplType === 'dual' ? 'display:none' : ''; ?>">
      <img id="baImgBefore" src="<?php echo htmlspecialchars($heroBeforeImg); ?>" class="ba-img" alt="Before" loading="eager">
      <div class="ba-tag" data-i18n="ba_before">Before</div>
    </div>
    <!-- dual: two source thumbnails -->
    <div class="ba-img-wrap ba-dual-photo" id="baDualPhoto1" style="<?php echo $activeTplType !== 'dual' ? 'display:none' : ''; ?>">
      <img id="baDualImg1" src="<?php echo htmlspecialchars($heroImg1); ?>" class="ba-img" alt="Photo 1" loading="eager">
      <div class="ba-tag">Photo 1</div>
    </div>
    <div class="ba-img-wrap ba-dual-photo" id="baDualPhoto2" style="<?php echo $activeTplType !== 'dual' ? 'display:none' : ''; ?>">
      <img id="baDualImg2" src="<?php echo htmlspecialchars($heroImg2); ?>" class="ba-img" alt="Photo 2" loading="eager">
      <div class="ba-tag">Photo 2</div>
    </div>
    <!-- divider -->
    <div class="ba-divider">
      <div class="ba-divider-icon">✨</div>
    </div>
    <!-- after: result image or video -->
    <div class="ba-img-wrap">
      <img id="baImgAfter" src="<?php echo htmlspecialchars($heroAfterImg); ?>" class="ba-img" alt="After" loading="eager"
           style="<?php echo ($activeTplType === 'video' && $heroVideoUrl) ? 'display:none' : ''; ?>">
      <video id="baVideoAfter" loop muted playsinline
             style="display:<?php echo ($activeTplType === 'video' && $heroVideoUrl) ? 'block' : 'none'; ?>;width:100%;height:100%;object-fit:cover;object-position:top center;"
             <?php if ($activeTplType === 'video' && $heroVideoUrl): ?>src="<?php echo htmlspecialchars($heroVideoUrl); ?>" autoplay<?php endif; ?>></video>
      <div class="ba-tag" data-i18n="ba_after">After</div>
    </div>
  </div>

  <!-- Demo generation -->
  <div id="formScreen">
    <div class="demo-label" id="tplTitle"><?php echo htmlspecialchars($activeTpl['name'] ?? 'Demo Generation'); ?></div>
    <div class="demo-sub" data-i18n="gen_demo_sub">Try it now — free</div>

    <!-- img2img panel -->
    <div id="panelImg2img"<?php echo $activeTplType === "dual" || $activeTplType === "video" ? ' style="display:none"' : ""; ?>>
      <input type="file" id="photoInput" accept="image/jpeg,image/png,image/heic,image/webp" style="display:none">
      <div class="preview-wrap" id="previewWrap1" style="display:none;">
        <img id="photoPreview" alt="Preview">
        <div class="preview-change" onclick="document.getElementById('photoInput').click()">📷 Изменить</div>
      </div>
      <div class="upload-area" id="uploadArea" onclick="document.getElementById('photoInput').click()">
        <div class="upload-icon-wrap">
          <div class="upload-icon-circle">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          </div>
        </div>
        <div class="upload-title">Выбрать фото из галереи</div>
        <div class="upload-sub">JPG · PNG · HEIC</div>
      </div>
      <button class="gen-btn" id="genBtnImg2img" disabled data-i18n="gen_btn">Generate ✨</button>
      <div id="tokenBar" style="display:none">
        <span class="tb-cost" data-i18n="gen_token_cost">Cost: <b>200 tokens</b></span>
        <span class="tb-bal">Today: <b id="tokenBalVal">0</b> / 5 generations</span>
      </div>
    </div>

    <!-- dual panel — 2 photos -->
    <div id="panelDual"<?php echo $activeTplType !== "dual" ? ' style="display:none"' : ""; ?>>
      <input type="file" id="dualPhotoInput1" accept="image/jpeg,image/png,image/heic,image/webp" style="display:none">
      <div class="preview-wrap" id="previewWrapD1" style="display:none;">
        <img id="dualPhoto1Preview" alt="Preview">
        <div class="preview-change" onclick="document.getElementById('dualPhotoInput1').click()">📷 Изменить</div>
      </div>
      <div class="upload-area" id="dualUploadArea1" onclick="document.getElementById('dualPhotoInput1').click()">
        <div class="upload-icon-wrap"><div class="upload-icon-circle">🤳</div></div>
        <div class="upload-title">Выбрать фото из галереи</div>
        <div class="upload-sub">JPG · PNG · HEIC</div>
      </div>
      <button class="gen-btn" id="genBtnDual" disabled style="margin-top:12px;">Generate ✨</button>
    </div>

    <!-- video panel — registered users -->
    <div id="panelVideo" style="display:none" data-video-disabled="1">
      <?php if ($siteUser): ?>
      <input type="file" id="videoPhotoInput" accept="image/jpeg,image/png,image/heic,image/webp" style="display:none">
      <div class="preview-wrap" id="previewWrapV" style="display:none;">
        <img id="videoPhotoPreview" alt="Preview">
        <div class="preview-change" onclick="document.getElementById('videoPhotoInput').click()">📷 Изменить</div>
      </div>
      <div class="upload-area" id="videoUploadArea" onclick="document.getElementById('videoPhotoInput').click()">
        <div class="upload-icon">🎬</div>
        <div class="upload-title">Выбрать фото из галереи</div>
        <div class="upload-sub" data-i18n="gen_video_upload_sub">AI превратит фото в видео · ~7 мин</div>
      </div>
      <button class="gen-btn" id="genBtnVideo" disabled data-i18n="gen_video_btn">Generate Video ✨</button>
      <?php else: ?>
      <div class="video-locked-msg" id="videoLockedMsg">
        <div class="vlm-icon">🎬</div>
        <div class="vlm-title" data-i18n="gen_video_locked_title">Video generation</div>
        <div class="vlm-cost" style="display:none">Cost: <b data-i18n="gen_video_cost">1000 tokens</b></div>
        <div class="vlm-sub" data-i18n="gen_video_locked_sub">In demo mode only photo generation is available.<br>Top up your balance to create videos.</div>
        <a href="/topup.php?method=crypto" class="vlm-btn" data-i18n="gen_video_topup">Top up balance</a>
      </div>
      <?php endif; ?>
    </div>



  <!-- ══════ CATALOG SECTION ══════ -->
  <div>
  <style>
  .cat-grid-wrap { margin-top:24px; }
  .cat-grid-title { font-size:11px;font-weight:700;color:rgba(255,255,255,.35);letter-spacing:.8px;text-transform:uppercase;margin-bottom:12px; }
  .cat-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(112px,1fr)); gap:8px; }
  @media (max-width:480px){ .cat-grid { grid-template-columns:repeat(2,1fr); gap:10px; } }
  @media (hover:hover){
    .cat-item { transition:transform .18s, border-color .2s, box-shadow .18s; }
    .cat-item:hover { transform:translateY(-3px); box-shadow:0 10px 26px rgba(236,72,153,.25); }
  }
  .cat-item.selected { border-color:#ec4899; box-shadow:0 0 0 3px rgba(236,72,153,.22); }
  .cat-item { position:relative; border-radius:10px; overflow:hidden; cursor:pointer; border:2px solid transparent; transition:border-color .2s; aspect-ratio:9/16; display:block; text-decoration:none; -webkit-tap-highlight-color:transparent; background:#111; -webkit-touch-callout:none; user-select:none; }
  .cat-item img, .cat-item video { -webkit-touch-callout:none; user-select:none; pointer-events:none; }
  /* result = default visible; source = shown on animation */
  .cat-item img.cat-img-result,
  .cat-item img.cat-img-source { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center top; display:block; transition:opacity .35s; }
  .cat-item img.cat-img-result { opacity:1; }
  .cat-item img.cat-img-source { opacity:0; }
  .cat-item.cat-anim img.cat-img-result { opacity:0; }
  .cat-item.cat-anim img.cat-img-source { opacity:1; }
  .cat-badge { position:absolute;top:5px;left:5px;font-size:8px;font-weight:800;padding:2px 5px;border-radius:6px;letter-spacing:.3px;text-transform:uppercase; }
  .cat-badge-result { background:rgba(236,72,153,.85);color:#fff; }
  .cat-badge-img2img { background:rgba(236,72,153,.9);color:#fff; }
  .cat-badge-dual    { background:rgba(59,130,246,.9);color:#fff; }
  .cat-badge-video   { background:rgba(245,158,11,.9);color:#000; }
  .cat-badge-main    { background:rgba(168,85,247,.9);color:#fff; }
  .cat-badge-source { background:rgba(0,0,0,.6);color:#ccc; display:none; }
  .cat-item.cat-anim .cat-badge-result { display:none; }
  .cat-item.cat-anim .cat-badge-source { display:block; }
  /* Desktop hover — no JS needed */
  @media (hover: hover) {
    .cat-item[data-has-anim="true"]:hover img.cat-img-result { opacity:0; }
    .cat-item[data-has-anim="true"]:hover img.cat-img-source { opacity:1; }
    .cat-item[data-has-anim="true"]:hover .cat-badge-result { display:none; }
    .cat-item[data-has-anim="true"]:hover .cat-badge-source { display:block; }
  }
  .cat-item-label { position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.88));color:#fff;font-size:10px;font-weight:700;padding:20px 5px 7px;text-align:center;line-height:1.2; }
    </style>
  <div class="cat-grid-wrap" id="catSection">
    <div class="cat-grid-title">AI Templates</div>
    <div class="cat-grid" id="catGrid">
<?php
$typeBadge = ['dual' => 'dual', 'img2img' => 'img2img', 'main' => 'img2img', 'video' => 'video'];
    $typeBadgeClass = ['dual' => 'cat-badge-dual', 'img2img' => 'cat-badge-img2img', 'main' => 'cat-badge-img2img', 'video' => 'cat-badge-video'];
$typeSourceLabel = ['dual' => '🎬 Scene', 'img2img' => '📷 Before', 'main' => ''];
foreach ($showcaseItems as $it):
    $itId    = htmlspecialchars($it['id']);
    $itName  = htmlspecialchars($it['name']);
    $itSrc   = htmlspecialchars($it['source_image']);
    $itGen   = htmlspecialchars($it['generated_image']);
    $itType  = $it['type'] ?? 'img2img';
    $hoverImg = ($itType === 'dual') ? ($it['image2'] ?? $itSrc) : $itSrc;
    $hasAnim = ($itType !== 'main' && $hoverImg) ? 'true' : 'false';
    $isActive = ($it['id'] === ($activeTpl['id'] ?? '')) ? ' selected' : '';
    $shortName = mb_strlen($it['name']) > 14 ? mb_substr($it['name'], 0, 13) . '…' : $it['name'];
    $badge = $typeBadge[$itType] ?? '✨';
    $srcLabel = $typeSourceLabel[$itType] ?? '📷';
?>
      <a href="/gen.php?tpl=<?php echo $itId; ?>" class="cat-item<?php echo $isActive; ?>"
         data-id="<?php echo $itId; ?>"
         data-name="<?php echo $itName; ?>"
         data-src="<?php echo $itSrc; ?>"
         data-gen="<?php echo $itGen; ?>"
         data-type="<?php echo htmlspecialchars($itType); ?>"
         data-img1="<?php echo htmlspecialchars($it['image1'] ?? ''); ?>"
         data-img2="<?php echo htmlspecialchars($it['image2'] ?? ''); ?>"
         data-vid="<?php echo htmlspecialchars($it['video_url'] ?? ''); ?>"
         data-tpl-id="<?php echo htmlspecialchars(($itType === 'img2img' && !empty($it['model'])) ? $it['id'] : ($it['template_id'] ?? $it['id'])); ?>"
         data-prompt="<?php echo htmlspecialchars($it['prompt'] ?? ''); ?>"
         data-has-anim="<?php echo $hasAnim; ?>">
        <img class="cat-img-result" src="<?php echo $itGen; ?>" alt="" loading="lazy" onerror="this.src='/showcase/demo_girl_new.jpg'">
        <?php if ($hasAnim === 'true'): ?>
        <img class="cat-img-source" src="<?php echo $hoverImg; ?>" alt="" loading="lazy" onerror="this.src='/showcase/demo_girl_new.jpg'">
        <?php endif; ?>
        <span class="cat-badge <?php echo $typeBadgeClass[$itType] ?? 'cat-badge-img2img'; ?>"><?php echo $badge; ?></span>
        <?php if ($hasAnim === 'true'): ?>
        <span class="cat-badge cat-badge-source"><?php echo $srcLabel; ?></span>
        <?php endif; ?>
        <div class="cat-item-label"><?php echo htmlspecialchars($shortName); ?></div>
      </a>
<?php endforeach; ?>
<?php if (empty($showcaseItems)): ?>
      <div style="grid-column:1/-1;padding:28px 16px;text-align:center;color:rgba(255,255,255,.45);font-size:13px;border:1px dashed rgba(255,255,255,.18);border-radius:12px">
        ✨ <span data-i18n="gen_templates_cooking">Templates are being prepared — check back soon</span>
      </div>
<?php endif; ?>
    </div>
  </div>
  </div><!-- /catalog hidden -->
  <!-- /CATALOG SECTION -->

  </div><!-- /formScreen -->

  <!-- Progress -->
  <div id="progressScreen">
    <div class="progress-photo-wrap">
      <div class="progress-ring"></div>
      <img id="progressPhoto" class="progress-photo" alt="Your photo">
      <div class="progress-sparks" id="progressSparks"></div>
    </div>
    <div class="progress-title" id="progressTitle" data-i18n="gen_progress_img">AI is generating…</div>
    <div class="progress-sub" id="progressSub" data-i18n="gen_progress_img_sub">About 16 seconds…</div>
    <div class="progress-bar-wrap"><div class="progress-bar-fill" id="progressFill"></div></div>
    <div class="progress-status" id="progressStatus">queued</div>
    <div class="progress-time"   id="progressTime"></div>
  </div>

  <!-- Result — fullscreen -->
  <div id="resultScreen">
    <!-- Main media -->
    <div class="result-img-area">
      <video id="resultVideo" controls playsinline style="display:none;"></video>
      <img   id="resultImage" alt="Generated"   style="display:none;">
    </div>
    <!-- Bottom actions -->
    <div class="result-bottom">
      <div class="result-actions">
        <a id="resultSaveBtn" class="result-btn result-btn-save" href="#" download>
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 3v13M5 10l7 7 7-7"/><path d="M5 19h14"/></svg>
          <span data-i18n="gen_save">Save</span>
        </a>
        <button class="result-btn result-btn-back" id="resultNewBtn">
          <span data-i18n="gen_back">← New generation</span>
        </button>
      </div>
    </div>
    <div id="demoUploadBanner" style="display:none;position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.95) 30%);padding:50px 20px 20px;text-align:center;">
      <div id="demoBannerTimer" style="display:inline-flex;align-items:center;gap:5px;background:rgba(236,72,153,.15);border:1px solid rgba(236,72,153,.4);border-radius:20px;padding:4px 12px;margin-bottom:8px;font-size:12px;color:#ec4899;font-weight:700;">⏱ <span id="demoBannerCountdown">2:00</span> free slot</div>
      <div id="demoBannerHeadline" style="font-size:17px;font-weight:800;color:#fff;margin-bottom:14px;line-height:1.3">🔥 Now try with YOUR photo!</div>
      <label id="demoBannerUploadLabel" style="display:block;padding:15px;border-radius:14px;background:linear-gradient(135deg,#ec4899,#a855f7);color:#fff;font-size:16px;font-weight:900;cursor:pointer;margin-bottom:10px;">
        📸 Upload Photo
        <input type="file" accept="image/*" id="demoUploadInput" style="display:none">
      </label>
      <a href="/login.php?redirect=/gen.php" id="demoBannerSignup" style="display:block;padding:12px;border-radius:14px;border:1.5px solid rgba(255,255,255,.25);color:rgba(255,255,255,.8);font-size:14px;font-weight:700;text-decoration:none;">✨ 5 free generations daily with VPN</a>
    </div>
  </div>

</div>

<!-- Anon limit paywall modal -->
<div id="limitModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:20px;">
  <div style="background:linear-gradient(135deg,#1a1a2e,#16213e);border:1px solid rgba(236,72,153,.3);border-radius:24px;max-width:380px;width:100%;padding:28px 22px;text-align:center;position:relative;">
    <div style="font-size:40px;margin-bottom:8px">🎉</div>
    <div style="font-size:20px;font-weight:900;color:#fff;margin-bottom:6px" id="limitModalTitle">Free generation used!</div>
    <div style="font-size:14px;color:rgba(255,255,255,.55);margin-bottom:24px;line-height:1.5" id="limitModalSub">5 free generations daily with your VPN subscription</div>
    <a href="/login.php?redirect=/gen.php" style="display:block;padding:16px;border-radius:14px;background:linear-gradient(135deg,#ec4899,#a855f7);color:#fff;font-size:16px;font-weight:900;text-decoration:none;margin-bottom:10px" id="limitModalTrial">⚡ Sign up FREE — 1500 tokens</a>
    <?php if (!$siteUser): ?>
    <button onclick="limitModalCrypto()" style="display:block;width:100%;padding:13px;border-radius:14px;background:linear-gradient(135deg,#18181b,#0f0f10);border:1px solid rgba(247,147,26,.4);color:#fff;font-size:14px;font-weight:800;cursor:pointer;margin-bottom:10px" id="limitModalPay">₿ Pay $1.99 crypto — no account needed</button>
    <div id="limitModalCryptoStatus" style="font-size:11px;color:rgba(255,255,255,.35);text-align:center;margin-top:-6px;margin-bottom:8px;min-height:14px"></div>
    <?php else: ?>
    <a href="/topup.php?method=crypto" style="display:block;padding:13px;border-radius:14px;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;font-size:14px;font-weight:800;text-decoration:none;margin-bottom:10px" id="limitModalPay">💳 <span data-i18n="gen_video_topup">Top up balance</span></a>
    <?php endif; ?>
    <button onclick="document.getElementById('limitModal').style.display='none'" style="background:none;border:none;color:rgba(255,255,255,.3);font-size:13px;cursor:pointer;padding:4px">Maybe later</button>
  </div>
</div>

<?php if ($showPaidToast): ?>
<script>
window.addEventListener('DOMContentLoaded',function(){
  var t=document.createElement('div');
  t.style.cssText='position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#22c55e;color:#fff;font-size:15px;font-weight:800;padding:14px 28px;border-radius:30px;z-index:99999;box-shadow:0 4px 20px rgba(34,197,94,.5);white-space:nowrap';
  t.textContent='✅ Payment successful! Enjoy unlimited generations';
  document.body.appendChild(t);
  setTimeout(function(){t.style.transition='opacity 0.8s';t.style.opacity='0';setTimeout(function(){t.remove()},800);},4000);
});
</script>
<?php endif; ?>

<!-- Bottom nav -->
<div id="genBusyToast"></div>

<div class="safe-bottom bottom-bar">
  <a href="/" class="nav-item"><img src="/assets/home.png" class="w-6 h-6 object-contain" alt="Home"></a>
  <a href="/gen.php" class="nav-item">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke-width="1.8" stroke-linecap="round" class="nav-ai-icon" style="stroke:#ec4899;">
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

<script src="/js/state.js?v=2"></script>
<script src="/js/tracking.js?v=1"></script>
<script>document.addEventListener('contextmenu', function(e){ e.preventDefault(); return false; }, true);</script>
<script src="/js/i18n.js"></script>
<script src="/js/fx.js"></script>
<script>
window.__GEN_BOOT__ = {
  siteUser: <?php echo $siteUserJson ?: 'null'; ?>,
  demoSrc:    <?php echo json_encode($defaultDemoSrc); ?>,
  demoResult: <?php echo json_encode($defaultDemoResult); ?>,
  isPaid:     <?php echo $isPaid ? 'true' : 'false'; ?>,
  tokens:     <?php echo (int)($siteUser['tokens'] ?? $__anonTok); ?>,
  costPhoto:  <?php echo defined('GEN_TOKEN_COST') ? (int)GEN_TOKEN_COST : 300; ?>,
  costVideo:  <?php echo defined('GEN_TOKEN_COST_VIDEO') ? (int)GEN_TOKEN_COST_VIDEO : 1000; ?>,
  genCount:   <?php echo (int)$genCount; ?>,
  usedToday:  <?php echo (int)($usedToday ?? 0); ?>,
  dailyLimit: <?php echo (int)($isPaid ? 0 : (defined('DAILY_FREE_GEN') ? DAILY_FREE_GEN : 5)); ?>
};
var SHOWCASE = <?php
$jsItems = [];
foreach ($showcaseItems as $it) {
    $jsItems[$it['id']] = [
        'name' => $it['name'],
        'src'  => $it['source_image'],
        'gen'  => $it['generated_image'],
    ];
}
echo json_encode($jsItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;
var ACTIVE_TPL_ID = <?php echo json_encode($activeTpl['id'] ?? ''); ?>;
var ACTIVE_TPL_TEMPLATE_ID = <?php echo json_encode(($activeTplType === 'img2img' && !empty($activeTpl['model'])) ? ($activeTpl['id'] ?? '') : ($activeTpl['template_id'] ?? ($activeTpl['id'] ?? ''))); ?>;
var ACTIVE_TPL_TYPE = <?php echo json_encode($activeTpl['type'] ?? 'img2img'); ?>;

(function() {
  var siteUser     = window.__GEN_BOOT__.siteUser;
  var FREE_CACHED  = <?php echo (int)$__freeCached; ?>;
  function cachedUsed() {
    var m = document.cookie.match(/(?:^|; )fapa_cached=(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
  }
  function bumpCached() {
    document.cookie = 'fapa_cached=' + (cachedUsed() + 1) + ';path=/;max-age=31536000';
  }
  function cachedLeft() { return !!siteUser || cachedUsed() < FREE_CACHED; }
  var isPaid       = window.__GEN_BOOT__.isPaid || false;
  var activeTab    = 'img2img';
  var selectedTpl  = ACTIVE_TPL_TYPE === 'video' ? 'flux2-wan22-i2v-foley' : (ACTIVE_TPL_TYPE === 'dual' ? 'dual' : (ACTIVE_TPL_TEMPLATE_ID || 'undress'));
  var photoFile    = null;  // img2img + video
  var usingDefault = false;
  // Auto-load sample photo for push traffic visitors (have cid param)
  var _isPushTraffic = (function(){
    var params = new URLSearchParams(window.location.search);
    return !!(params.get("cid") && !siteUser);
  })();
  if (_isPushTraffic) {
    setTimeout(function(){
      loadDefaultPhoto();
      var b = document.getElementById("trySampleBtn"); if(b) b.style.display="none";
      // Disable dual/video template cards to avoid HotAPI failures
      document.querySelectorAll('.cat-item').forEach(function(card) {
        var t = card.dataset.type || '';
        if (t === 'dual' || t === 'video') {
          card.style.opacity = '0.35';
          card.style.pointerEvents = 'none';
          card.title = 'Requires login';
        }
      });
      // Auto-generate: poll every 300ms until photo is ready (handles slow mobile connections)
      var _autoGenAttempts = 0;
      var _autoGenTimer = setInterval(function(){
        _autoGenAttempts++;
        if (photoFile && !isGenerating && getAnonUsed() === 0 && activeTab === 'img2img') {
          clearInterval(_autoGenTimer);
          fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_auto_start'})}).catch(function(){});
          startGen(selectedTpl || 'undress', photoFile, null);
        } else if (_autoGenAttempts >= 20) { // give up after 6s
          clearInterval(_autoGenTimer);
        }
      }, 300);
    }, 800);
  }
  var ownPhotoTracked = false;

  // Token system: 1500 tokens free, 300 per photo gen, 1000 for video
  // Дневная квота — витрина: 5 генераций в день, блокировки нет (продукт безлимитный)
  var TOKEN_PHOTO = (window.__GEN_BOOT__.costPhoto || 300);
  var TOKEN_VIDEO = (window.__GEN_BOOT__.costVideo || 1000);
  var UI_LIMIT    = 5;
  var DAILY_LIMIT = UI_LIMIT;
  var USED_TODAY  = Math.min(UI_LIMIT, (window.__GEN_BOOT__.usedToday || 0));
  function gensLeft() { return Math.max(0, UI_LIMIT - USED_TODAY); }
  function getTokenBalance() { return Infinity; } // генерировать можно всегда
  function renderDailyBar() {
    var bar = document.getElementById('dailyBar');
    var pct = Math.min(100, Math.round(100 * USED_TODAY / UI_LIMIT));
    if (bar && !isPaid) {
      bar.style.width = Math.max(6, pct) + '%';
      bar.style.background = pct >= 100
        ? 'linear-gradient(90deg,#facc15,#f59e0b)'
        : 'linear-gradient(90deg,#ec4899,#a855f7 60%,#6366f1)';
      bar.style.boxShadow = '0 0 10px rgba(168,85,247,.65)';
    }
    var wg = document.getElementById('walletGens');
    if (wg && !isPaid) wg.innerHTML = (UI_LIMIT - USED_TODAY) + '<span>/' + UI_LIMIT + ' gens</span>';
  }
  // Короткая подпись устройства — та же, что на промо: защита от накрутки
  // бесплатных генераций. Только характеристики браузера, ничего личного.
  window.__deviceHint = (function() {
    try {
      var sc = window.screen || {};
      return [
        (sc.width || 0) + 'x' + (sc.height || 0),
        (sc.colorDepth || 0),
        (new Date()).getTimezoneOffset(),
        (navigator.hardwareConcurrency || 0),
        (navigator.deviceMemory || 0),
        (navigator.platform || '').replace(/[^a-zA-Z0-9]/g, '')
      ].join(',');
    } catch (e) { return ''; }
  })();

  function T2(key, fallback) {
    var d = window.__LANG_DATA__ || {};
    return d[key] || fallback;
  }

  function renderTokenBar(isVideo) {
    var bar = document.getElementById('tokenBar');
    if (!bar) return;
    var bal = getTokenBalance();
    var cost = isVideo ? TOKEN_VIDEO : TOKEN_PHOTO;
    var balVal = document.getElementById('tokenBalVal');
    if (isPaid) {
      bar.querySelector('.tb-bal').innerHTML = 'Balance: <b style="color:#10b981;font-size:14px">&#8734;</b>';
    } else {
      if (balVal) balVal.textContent = USED_TODAY;
    }
    bar.querySelector('.tb-cost').innerHTML = 'Today: <b>' + USED_TODAY + ' / ' + UI_LIMIT + '</b> generations';
    renderDailyBar();
  }

  function loadDefaultPhoto() {
    fetch(window.__GEN_BOOT__.demoSrc)
      .then(function(r){ return r.blob(); })
      .then(function(blob){
        photoFile = new File([blob], 'demo.jpg', {type:'image/jpeg'});
        usingDefault = true;
        var pi = document.getElementById('photoPreview'); if (pi) pi.src = window.__GEN_BOOT__.demoSrc;
        var w = document.getElementById('previewWrap1'); if (w) w.style.display = 'block';
        var a = document.getElementById('uploadArea'); if (a) a.style.display = 'none';
        var o = document.getElementById('demoOverlay'); if (o) o.style.display = 'block';
        updateBtn('img2img');
      }).catch(function(){});
  }
  var photo2File   = null;  // dual second photo
  var dualFile1    = null;
  var currentJobId = null;
  var pollTimer    = null;
  var pollStart    = null;
  var progressStep = 0;

  function getAnonUsed() {
    try { return parseInt(localStorage.getItem('fapa_gen_used') || '0', 10); } catch(e) { return 0; }
  }
  function incAnonUsed() {
    try { localStorage.setItem('fapa_gen_used', String(getAnonUsed() + 1)); } catch(e) {}
  }
  function isOverLimit() { return !siteUser && getAnonUsed() >= 1; }

  var isGenerating = false;
  window.addEventListener('beforeunload', function(e) {
    if (isGenerating) {
      var msg = 'If you leave — the generation result will be lost!';
      e.preventDefault(); e.returnValue = msg; return msg;
    }
  });

  // ── Защита от дурака на время генерации ────────────────────────────────
  // Уход со страницы или второй клик по «Генерировать» убивает результат:
  // на сервере всё досчитается и спишется, а показать будет уже некуда.
  var _busyMsg = {
    en:'Generation in progress — hold on a few seconds',
    ru:'Идёт генерация — подождите несколько секунд',
    es:'Generación en curso: espera unos segundos',
    pt:'Geração em andamento — aguarde alguns segundos',
    fr:'Génération en cours — patiente quelques secondes',
    de:'Generierung läuft — einen Moment bitte',
    ar:'جارٍ التوليد — انتظر بضع ثوانٍ',
    id:'Sedang membuat — tunggu beberapa detik',
    tr:'Üretim sürüyor — birkaç saniye bekleyin'
  };
  function busyToast() {
    var el = document.getElementById('genBusyToast');
    if (!el) return;
    var lang = (typeof _activeLang !== 'undefined' ? _activeLang : 'en');
    el.textContent = _busyMsg[lang] || _busyMsg.en;
    el.classList.add('show');
    clearTimeout(window._busyT);
    window._busyT = setTimeout(function(){ el.classList.remove('show'); }, 1800);
  }
  document.addEventListener('click', function(e) {
    if (!isGenerating) return;
    if (!e.target || !e.target.closest) return;
    var t = e.target.closest('a[href], button, label, input[type=file]');
    if (!t || t.closest('#progressScreen')) return;   // внутри прогресса не мешаем
    e.preventDefault(); e.stopPropagation();
    busyToast();
  }, true);

  function showScreen(id) {
    ['formScreen','progressScreen'].forEach(function(s) {
      var el = document.getElementById(s);
      if (el) el.style.display = s === id ? 'block' : 'none';
    });
    var rs = document.getElementById('resultScreen');
    if (rs) {
      if (id === 'resultScreen') { rs.classList.add('open'); }
      else { rs.classList.remove('open'); }
    }
    document.body.classList.toggle('gen-busy', id === 'progressScreen');
  }

  // ── Tab switching (locked tabs — no switch) ──
  document.querySelectorAll('.gen-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      if (tab.classList.contains('locked')) return; // locked — ignore
      activeTab = tab.dataset.tab;
      document.querySelectorAll('.gen-tab').forEach(function(t){ t.classList.remove('active'); });
      tab.classList.add('active');

      var panels = { img2img:'panelImg2img', video:'panelVideo', dual:'panelDual' };
      Object.keys(panels).forEach(function(k) {
        var p = document.getElementById(panels[k]);
        if (p) p.style.display = k === activeTab ? 'block' : 'none';
      });

      if (activeTab === 'img2img') { selectedTpl = 'undress'; }
      if (activeTab === 'video')   { selectedTpl = 'flux2-wan22-i2v-foley'; }
      if (activeTab === 'dual')    { selectedTpl = 'dual'; }
    });
  });

  // ── Photo preview helper — показывает фото без растяжки ──
  function previewFile(file, imgId, wrapId, areaId, cb) {
    if (!file) return;
    var img  = document.getElementById(imgId);
    var wrap = wrapId ? document.getElementById(wrapId) : null;
    var area = areaId ? document.getElementById(areaId) : null;
    var r = new FileReader();
    r.onload = function(e) {
      if (img) img.src = e.target.result;
      if (wrap) wrap.style.display = 'block';
      if (area) area.style.display = 'none';
      if (cb) cb();
    };
    r.readAsDataURL(file);
  }

  // ── Crop image to 9:16 via canvas (center-top crop, max 900px wide) ──
  function cropTo916(file) {
    return new Promise(function(resolve) {
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function() {
        URL.revokeObjectURL(url);
        var sw = img.naturalWidth, sh = img.naturalHeight;
        var targetRatio = 9 / 16;
        var srcRatio = sw / sh;
        var cropW, cropH, cropX, cropY;
        if (srcRatio > targetRatio) {
          // wider than 9:16 → crop sides, keep height
          cropH = sh;
          cropW = Math.round(sh * targetRatio);
          cropX = Math.round((sw - cropW) / 2);
          cropY = 0;
        } else {
          // taller than 9:16 → crop bottom, keep top (face stays)
          cropW = sw;
          cropH = Math.round(sw / targetRatio);
          cropX = 0;
          cropY = Math.round((sh - cropH) * 0.15);
        }
        var outW = Math.min(cropW, 900);
        var outH = Math.round(outW / targetRatio);
        var canvas = document.createElement('canvas');
        canvas.width = outW; canvas.height = outH;
        canvas.getContext('2d').drawImage(img, cropX, cropY, cropW, cropH, 0, 0, outW, outH);
        canvas.toBlob(function(blob) {
          resolve(new File([blob], 'photo.jpg', {type:'image/jpeg'}));
        }, 'image/jpeg', 0.92);
      };
      img.onerror = function() { resolve(file); };
      img.src = url;
    });
  }

  // ── IMG2IMG inputs ──
  function bindFileInput(inputEl, onChange) {
    if (!inputEl) return;
    inputEl.addEventListener('change', function() {
      if (inputEl.files[0]) onChange(inputEl.files[0]);
    });
  }

  // Main upload input
  var inp1 = document.getElementById('photoInput');
  // Check if user previously uploaded (set flag to show return prompt)
  try {
    if (localStorage.getItem('fapa_uploaded') === '1') {
      var upSub = document.querySelector('#uploadArea .upload-sub');
      if (upSub && !photoFile) upSub.textContent = '📷 Нажмите, чтобы загрузить снова';
    }
  } catch(e) {}

  bindFileInput(inp1, function(f) {
    if (!ownPhotoTracked) { ownPhotoTracked=true; fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_own_photo'})}).catch(()=>{}); fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_upload_photo'})}).catch(()=>{}); }
    usingDefault = false;
    try { localStorage.setItem('fapa_uploaded','1'); } catch(e){}
    var o = document.getElementById('demoOverlay'); if (o) o.style.display = 'none';
    cropTo916(f).then(function(cf) {
      photoFile = cf;
      previewFile(cf, 'photoPreview', 'previewWrap1', 'uploadArea', function(){
        updateBtn('img2img');
        setTimeout(function(){
          var gb = document.getElementById('genBtnImg2img');
          if (gb && !gb.disabled) {
            gb.scrollIntoView({behavior:'smooth', block:'center'});
            gb.style.transition = 'box-shadow 0.3s';
            gb.style.boxShadow = '0 0 0 6px rgba(236,72,153,0.45), 0 0 30px rgba(236,72,153,0.5)';
            setTimeout(function(){ gb.style.boxShadow = ''; }, 1800);
          }
        }, 300);
      });
    });
  });
  // "Change" input inside preview-wrap
  var previewWrap1 = document.getElementById('previewWrap1');
  if (previewWrap1) {
    var changeInp1 = previewWrap1.querySelector('input[type=file]');
    bindFileInput(changeInp1, function(f) {
      usingDefault = false;
      var o = document.getElementById('demoOverlay'); if (o) o.style.display = 'none';
      cropTo916(f).then(function(cf) {
        photoFile = cf;
        previewFile(cf, 'photoPreview', 'previewWrap1', null, function(){ updateBtn('img2img'); });
      });
    });
  }

  // Drag on img2img
  var area1 = document.getElementById('uploadArea');
  if (area1) {
    area1.addEventListener('dragover', function(e){ e.preventDefault(); area1.classList.add('drag'); });
    area1.addEventListener('dragleave', function(){ area1.classList.remove('drag'); });
    area1.addEventListener('drop', function(e){
      e.preventDefault(); area1.classList.remove('drag');
      var f = e.dataTransfer && e.dataTransfer.files[0];
      if (f && f.type.startsWith('image/')) {
        cropTo916(f).then(function(cf) {
          photoFile = cf;
          previewFile(cf, 'photoPreview', 'previewWrap1', 'uploadArea', function(){ updateBtn('img2img'); });
        });
      }
    });
  }

  // ── VIDEO inputs ──
  var vInp = document.getElementById('videoPhotoInput');
  bindFileInput(vInp, function(f) {
    cropTo916(f).then(function(cf) {
      photoFile = cf;
      previewFile(cf, 'videoPhotoPreview', 'previewWrapV', 'videoUploadArea', function(){ updateBtn('video'); });
    });
  });
  var previewWrapV = document.getElementById('previewWrapV');
  if (previewWrapV) {
    bindFileInput(previewWrapV.querySelector('input[type=file]'), function(f) {
      cropTo916(f).then(function(cf) {
        photoFile = cf;
        previewFile(cf, 'videoPhotoPreview', 'previewWrapV', null, function(){ updateBtn('video'); });
      });
    });
  }

  // ── DUAL inputs ──
  var dInp1 = document.getElementById('dualPhotoInput1');
  bindFileInput(dInp1, function(f) {
    dualFile1 = f;
    previewFile(f, 'dualPhoto1Preview', 'previewWrapD1', 'dualUploadArea1', function(){ updateBtn('dual'); });
  });
  var previewWrapD1 = document.getElementById('previewWrapD1');
  if (previewWrapD1) {
    bindFileInput(previewWrapD1.querySelector('input[type=file]'), function(f) {
      dualFile1 = f;
      previewFile(f, 'dualPhoto1Preview', 'previewWrapD1', null, function(){ updateBtn('dual'); });
    });
  }

  // Photo 2 is template scene — loaded automatically, no user upload

  // ── Update generate button state ──
  function updateBtn(tab) {
    var t = tab || activeTab;
    if (t === 'img2img') {
      var btn = document.getElementById('genBtnImg2img');
      if (!btn) return;
      btn.disabled = !photoFile;
      btn.style.opacity = '';
      if (!photoFile) btn.textContent = 'Generate ✨';
      renderTokenBar(false);
    } else if (t === 'video') {
      var vBtn = document.getElementById('genBtnVideo');
      if (vBtn) vBtn.disabled = !photoFile;
    } else if (t === 'dual') {
      var dBtn = document.getElementById('genBtnDual');
      if (dBtn) dBtn.disabled = !dualFile1;
    }
  }

  // ── Generate button handlers ──
  var genBtnI = document.getElementById('genBtnImg2img');
  if (genBtnI) genBtnI.addEventListener('click', function(){
    if (window.fx) fx('gen_generate_click', selectedTpl || 'undress');
    if (!photoFile) { if (window.fx) fx('gen_no_photo', 'img2img'); return; }
    startGen(selectedTpl || 'undress', photoFile, null);
  });

  var genBtnV = document.getElementById('genBtnVideo');
  if (genBtnV) genBtnV.addEventListener('click', function(){
    if (window.fx) fx('gen_generate_click', 'video');
    if (!photoFile) { if (window.fx) fx('gen_no_photo', 'video'); return; }
    startGen('flux2-wan22-i2v-foley', photoFile, null);
  });

  var genBtnD = document.getElementById('genBtnDual');
  if (genBtnD) genBtnD.addEventListener('click', function(){
    if (window.fx) fx('gen_generate_click', 'dual');
    if (!dualFile1) { if (window.fx) fx('gen_no_photo', 'dual'); return; }
    startGen('dual', dualFile1, null);
  });

  function startGen(tpl, file1, file2) {
    if (isGenerating) { busyToast(); return; }   // второй клик ничего не ускоряет
    // ── Demo fast-path: default photo → fake 6s animation, no GPU ─────────
    if (usingDefault && !cachedLeft()) {
      isGenerating = false;
      if (window.fx) fx('gen_no_photo', 'demo_spent');
      alert('Upload your own photo — one free generation is waiting');
      var _pi = document.getElementById('photoInput');
      if (_pi) _pi.click();
      return;
    }
    if (usingDefault && (tpl === 'undress' || tpl === 'flux2-img2img')) {
      fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_default_click'})}).catch(()=>{});
      var pp = document.getElementById('progressPhoto');
      if (pp) pp.src = window.__GEN_BOOT__.demoSrc;
      showScreen('progressScreen');
      (function(){
        var lang = (typeof _activeLang !== 'undefined' ? _activeLang : 'en');
        var dict = (typeof I18N !== 'undefined' && I18N[lang]) ? I18N[lang] : (typeof I18N !== 'undefined' ? I18N.en : {});
        var ptEl = document.getElementById('progressTitle');
        var psEl = document.getElementById('progressSub');
        if (ptEl && dict) ptEl.textContent = dict.gen_progress_img || 'AI is generating…';
        if (psEl && dict) psEl.textContent = dict.gen_progress_img_sub || 'About 16 seconds…';
      })();
      spawnSparks();
      var fill = document.getElementById('progressFill');
      var stat = document.getElementById('progressStatus');
      if (fill) fill.style.width = '0%';
      var demoStart = Date.now(); var demoDur = 8000;
      var demoTimer = setInterval(function() {
        var ep = Date.now() - demoStart;
        var pct = Math.min(92, ep / demoDur * 100);
        if (fill) fill.style.width = pct + '%';
        var rem = Math.max(0, Math.ceil((demoDur - ep)/1000));
        var timeEl2 = document.getElementById('progressTime'); if(timeEl2) timeEl2.textContent = rem > 0 ? rem + 's remaining…' : 'Almost done…';
        if (ep >= demoDur) {
          clearInterval(demoTimer);
          if (fill) fill.style.width = '100%';
          if (stat) stat.textContent = 'Done!';
          // Change back button to motivate own photo upload
          var nb = document.getElementById('resultNewBtn');
          if (nb) {
            var lang2 = (typeof _activeLang !== 'undefined' ? _activeLang : 'en');
            var tryLabels = {en:'📸 Try with your photo!',ru:'📸 Попробуй своё фото!',es:'📸 ¡Prueba con tu foto!',pt:'📸 Experimente com sua foto!',fr:'📸 Essaie avec ta photo!',de:'📸 Mit deinem Foto!',id:'📸 Coba fotomu!',ja:'📸 自分の写真で！',ro:'📸 Poza ta!',bg:'📸 Твоя снимка!',el:'📸 Η φωτογραφία σου!',ar:'📸 صورتك!',tr:'📸 Kendi fotoğrafın!'};
            nb.textContent = tryLabels[lang2] || tryLabels.en;
          }
          bumpCached();
          showResult({ result_url: window.__GEN_BOOT__.demoResult, _isDemo: true });
        }
      }, 1000);
      return;
    }
    // ── Real GPU generation ───────────────────────────────────────────────

    var fd = new FormData();
    fd.append('template_id', tpl);
    fd.append('image', file1, 'photo.jpg');
    fd.append('fpc', window.__deviceHint || '');
    if (tpl === 'dual') {
      fd.append('showcase_id', window.__activeTplId || ACTIVE_TPL_ID || '');
      if (window.__activeTplPrompt) fd.append('prompt', window.__activeTplPrompt);
    }
    if (tpl === 'flux2-img2img') {
      fd.append('image_prompt_positive', 'A completely naked beautiful young woman in the exact same pose, body proportions, face, hair, hands and lighting as the reference image. She is fully nude with absolutely zero clothing, zero fabric, zero jacket remnants, zero straps, zero edges, zero borders, zero lace, zero outlines or any clothing marks anywhere on her body. Perfect seamless clean smooth skin continuity across the entire body, especially under the breasts, on the torso, hips and intimate area where any clothing pressure lines were removed. No clothing marks, no pressure lines, no indentations from fabric. Highly detailed realistic skin texture with natural micro-details and subtle natural sheen. Her full natural breasts have soft, naturally shaped nipples with smooth, realistic areolas that blend gently into the skin. Soft smooth skin with perfect anatomical continuity from head to toe. Natural beauty of the female form, photorealistic, cinematic volumetric lighting, ultra-detailed clean skin everywhere, 8k anatomical perfection.');
      fd.append('image_prompt_negative', 'clothes, clothing, fabric, dressed, covered, shirt, dress, jacket, bra, underwear, panties, bikini, straps, lace, fabric edges, clothing marks, pressure lines, indentations, blurry, deformed, low quality, different person, extra limbs, bad anatomy');
    } else if (tpl === 'flux2-wan22-i2v-foley') {
      fd.append('image_prompt_positive', 'same person, cinematic, high quality, photorealistic');
      fd.append('image_prompt_negative', 'different person, blurry, low quality');
      fd.append('video_prompt_positive', 'smooth camera movement, cinematic lighting');
      fd.append('video_prompt_negative', 'static, blurry, face morphing');
    }

    // Show preview in progress screen
    var pp = document.getElementById('progressPhoto');
    var previewElId = tpl === 'dual' ? 'dualPhoto1Preview' : (tpl === 'flux2-wan22-i2v-foley' ? 'videoPhotoPreview' : 'photoPreview');
    var previewEl = document.getElementById(previewElId);
    if (pp && previewEl && previewEl.src) pp.src = previewEl.src;

    showScreen('progressScreen');
    // Update progress text for template type
    (function(){
      var lang = (typeof _activeLang !== 'undefined' ? _activeLang : 'en');
      var dict = (typeof I18N !== 'undefined' && I18N[lang]) ? I18N[lang] : (typeof I18N !== 'undefined' ? I18N.en : {});
      var isVid = (tpl === 'flux2-wan22-i2v-foley');
      var ptEl = document.getElementById('progressTitle');
      var psEl = document.getElementById('progressSub');
      if (ptEl && dict) ptEl.textContent = isVid ? (dict.gen_progress_vid || 'AI is generating…') : (dict.gen_progress_img || 'AI is generating…');
      if (psEl && dict) psEl.textContent = isVid ? (dict.gen_progress_vid_sub || 'About 38 seconds…') : (dict.gen_progress_img_sub || 'About 16 seconds…');
    })();
    spawnSparks();
    progressStep = 0;
    pollStart = Date.now();
    var fill = document.getElementById('progressFill');
    var stat = document.getElementById('progressStatus');
    if (fill) fill.style.width = '5%';
    if (stat) stat.textContent = 'Submitting…';

    fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_start'})}).catch(function(){});
    isGenerating = true;
    // Animate progress bar smoothly over 45s (0→95%), polling only handles done/error
    (function startProgressAnim(){
      var fill = document.getElementById('progressFill');
      var timeEl = document.getElementById('progressTime');
      if (fill) fill.style.width = '0%';
      var startTs = Date.now();
      var dur = 60000;   // реальная генерация по факту около минуты
      var timer = setInterval(function(){
        var elapsed = Date.now() - startTs;
        var pct = Math.min(95, elapsed / dur * 100);
        if (fill) fill.style.width = pct + '%';
        var rem = Math.max(0, Math.ceil((dur - elapsed) / 1000));
        if (timeEl) timeEl.textContent = rem > 0 ? rem + 's remaining…' : 'Almost done…';
        if (pct >= 95) clearInterval(timer);
      }, 300);
      window._progTimer = timer;
    })();
    fetch('/api/ai_generate.php', { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.error === 'video_disabled') {
          isGenerating = false;
          if (window._progTimer) { clearInterval(window._progTimer); window._progTimer = null; }
          showScreen('formScreen');
          alert(T2('video_off', 'Video generation is temporarily unavailable'));
          return;
        }
        if (d.error === 'paywall') { isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;} if (window.fx) fx('gen_paywall'); showScreen('formScreen'); setTimeout(function(){ window.location.href = '/topup.php?method=crypto'; }, 150); return; }
        if (d.error === 'daily_limit') {
          // попапы снесены (продукт безлимитный); ветка осталась на крайний случай
          isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;}
          showScreen('formScreen');
          return;
        }
        if (d.error === 'limit') {
          // попапы снесены; потолки подняты, ветка на крайний случай
          isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;}
          showScreen('formScreen');
          return;
        }
        if (d.status === 'done' && d.result_url) {
          // мгновенный ответ — это заготовка из кэша: она бесплатна, баланс не трогаем
          if (!siteUser && !d.cached) { TOKEN_BAL = Math.max(0, TOKEN_BAL - (tpl === 'flux2-wan22-i2v-foley' ? TOKEN_VIDEO : TOKEN_PHOTO)); }
          if (!d.cached && !isPaid) { USED_TODAY++; } // дневной прогресс
          if (fill) fill.style.width = '100%'; showResult(d); renderTokenBar(false); return;
        }
        if (d.error === 'demo_spent') {
          isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;}
          if (window.fx) fx('gen_no_photo', 'demo_spent');
          showScreen('formScreen');
          alert(d.message || 'Upload your own photo to generate');
          var _pi2 = document.getElementById('photoInput');
          if (_pi2) _pi2.click();
          return;
        }
        if (d.error === 'blocked') {
          isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;}
          if (window.fx) fx('gen_error', 'blocked');
          showScreen('formScreen');
          alert(d.message || 'Generation is not available for this device.');
          return;
        }
        if (d.error === 'age_moderation') {
          isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;}
          if (window.fx) fx('gen_moderation_reject', String(d.age || ''));
          showScreen('formScreen');
          alert('Photo rejected: the person looks underage. Try another photo.');
          return;
        }
        if (!d.job_id) {
          isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;}
          if (window.fx) fx('gen_error', 'no_job_id');
          alert('Error. Please try again.'); showScreen('formScreen'); return;
        }
        // считаем не отправку, а выданный результат — см. showResult
        if (!siteUser) { renderTokenBar(false); }
        currentJobId = d.job_id;
        startPolling();
      })
      .catch(function(){
        isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;}
        if (window.fx) fx('gen_error', 'fetch_failed');
        alert('Connection error.'); showScreen('formScreen');
      });
  }

  // ── Sparks animation ──
  function spawnSparks() {
    var container = document.getElementById('progressSparks');
    if (!container) return;
    container.innerHTML = '';
    var colors = ['#ec4899','#a855f7','#3b82f6','#f59e0b','#10b981'];
    for (var i = 0; i < 10; i++) {
      (function(idx) {
        var s = document.createElement('div');
        s.className = 'spark';
        var angle = (idx / 10) * 360;
        var dist  = 55 + Math.random() * 30;
        var tx = Math.cos(angle * Math.PI / 180) * dist;
        var ty = Math.sin(angle * Math.PI / 180) * dist;
        s.style.cssText = 'left:50%;top:50%;margin:-2px;background:'+colors[idx%colors.length]+
          ';--tx:'+tx+'px;--ty:'+ty+'px;animation-delay:'+(idx*0.18)+'s;animation-duration:'+(1.8+Math.random()*0.8)+'s';
        container.appendChild(s);
      })(i);
    }
    // Respawn continuously
    setTimeout(spawnSparks, 3000);
  }

  var LABELS = { queued:'Queued…', starting_worker:'Starting GPU…', generating:'Generating on H200…', done:'Done!', error:'Error' };

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    window._genSlowSent = false; window._pollFails = 0;
    pollTimer = setInterval(pollStatus, 4000);
    pollStatus();
  }

  function pollStatus() {
    fetch('/api/ai_status.php?job_id=' + encodeURIComponent(currentJobId))
      .then(function(r){ return r.json(); })
      .then(function(d){
        var st   = d.status || 'queued';
        var fill = document.getElementById('progressFill');
        var stat = document.getElementById('progressStatus');
        var tEl  = document.getElementById('progressTime');
        if (stat) stat.textContent = LABELS[st] || st;
        window._pollFails = 0;
        var elapsedS = pollStart ? Math.floor((Date.now() - pollStart) / 1000) : 0;
        if (tEl) tEl.textContent = elapsedS + 's elapsed';
        if (elapsedS > 180 && !window._genSlowSent) {
          window._genSlowSent = true;
          if (window.fx) fx('gen_timeout', st + ':' + elapsedS + 's');
          if (stat) stat.textContent = 'Taking longer than usual…';
        }
        if (st === 'done')  { if (pollTimer) clearInterval(pollTimer); if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;} if (fill) fill.style.width='100%'; showResult(d); }
        if (st === 'error') {
          if (pollTimer) clearInterval(pollTimer);
          isGenerating = false; if(window._progTimer){clearInterval(window._progTimer);window._progTimer=null;}
          if (window.fx) fx('gen_error', 'poll:' + (d.error || 'unknown'));
          alert('Failed: ' + (d.error||'unknown')); showScreen('formScreen');
        }
      })
      .catch(function(){
        window._pollFails = (window._pollFails || 0) + 1;
        if (window._pollFails === 5 && window.fx) fx('gen_error', 'poll_fetch_failed');
      });
  }

  function showResult(d) {
    var vid     = document.getElementById('resultVideo');
    var img     = document.getElementById('resultImage');
    var save    = document.getElementById('resultSaveBtn');
    var saveTop = document.getElementById('resultSaveBtnTop');
    var _su = window.__GEN_BOOT__ && window.__GEN_BOOT__.siteUser;
    if (!_su && !d._isDemo && !d.cached) incAnonUsed();
    // reg-wall после результата убран: дневной лимит 5/сутки, попап только при исчерпании

    // Hide both first
    if (vid) { vid.style.display = 'none'; vid.src = ''; }
    if (img) { img.style.display = 'none'; img.src = ''; }

    if (d.result_type === 'video' && d.result_url) {
      if (vid) { vid.src = d.result_url; vid.style.display = 'block'; }
      if (save) { save.href = d.result_url; save.setAttribute('download', 'gen.mp4'); }
      if (saveTop) { saveTop.href = d.result_url; saveTop.setAttribute('download', 'gen.mp4'); }
    } else if (d.result_url) {
      if (img) { img.src = d.result_url; img.style.display = 'block'; }
      if (save) { save.href = d.result_url; save.setAttribute('download', 'gen.jpg'); }
      if (saveTop) { saveTop.href = d.result_url; saveTop.setAttribute('download', 'gen.jpg'); }
    }

    isGenerating = false;
    if (window._progTimer) { clearInterval(window._progTimer); window._progTimer = null; }
    var fillFinal = document.getElementById('progressFill'); if (fillFinal) fillFinal.style.width = '100%';
    window._lastResultWasDemo = !!d._isDemo;
    if (d._isDemo || d.cached) { fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_demo_result'})}).catch(function(){}); setTimeout(function(){ var b=document.getElementById('demoUploadBanner'); if(b){ b.style.display='block'; var lang2=(typeof _activeLang!=='undefined'?_activeLang:'en'); var headlines={en:'🔥 Now try with YOUR photo!',es:'🔥 ¡Ahora prueba con TU foto!',pt:'🔥 Agora tente com SUA foto!',fr:'🔥 Essaie avec TA photo !',ru:'🔥 Попробуй со СВОИМ фото!',ar:'🔥 جرّب بصورتك الخاصة!',id:'🔥 Coba dengan FOTO KAMU!',tr:'🔥 Şimdi KENDİ fotoğrafınla dene!'}; var btns={en:'📸 Upload Photo',es:'📸 Subir Foto',pt:'📸 Enviar Foto',fr:'📸 Télécharger Photo',ru:'📸 Загрузить фото',ar:'📸 رفع صورة',id:'📸 Unggah Foto',tr:'📸 Fotoğraf Yükle'}; var hEl=document.getElementById('demoBannerHeadline'); if(hEl) hEl.textContent=(headlines[lang2]||headlines.en); var lEl=document.getElementById('demoBannerUploadLabel'); if(lEl){ var inp=lEl.querySelector('input'); lEl.textContent=(btns[lang2]||btns.en)+' '; if(inp) lEl.appendChild(inp); } var signups={en:'✨ 5 free generations daily with VPN',es:'✨ Regístrate GRATIS — 24h ilimitado',pt:'✨ Cadastre-se GRÁTIS — 24h ilimitado',fr:'✨ Inscription GRATUITE — 24h illimité',ru:'✨ 5 бесплатных генераций в день с VPN',de:'✨ 5 kostenlose Generierungen täglich mit VPN',ar:'✨ سجّل مجاناً — 24 ساعة بلا حد',id:'✨ Daftar GRATIS — 24 jam unlimited',tr:'✨ ÜCRETSİZ kayıt — 24 saat sınırsız'}; var sEl=document.getElementById('demoBannerSignup'); if(sEl) sEl.textContent=(signups[lang2]||signups.en); } fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_banner_shown'})}).catch(function(){}); var _cdSec=120; var _cdEl=document.getElementById('demoBannerCountdown'); var _cdTimer=setInterval(function(){ _cdSec--; if(_cdEl){ var m=Math.floor(_cdSec/60); var s=_cdSec%60; _cdEl.textContent=m+':'+(s<10?'0':'')+s; } if(_cdSec<=0){ clearInterval(_cdTimer); var b2=document.getElementById('demoUploadBanner'); if(b2) b2.style.display='none'; } },1000); },300); } else { fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_complete'})}).catch(function(){}); }
    showScreen('resultScreen');
    if (window.tgBanner) window.tgBanner.onGeneration();
  }

  function doNewPhoto() {
    usingDefault = false;
    photoFile = null;
    var pi = document.getElementById('photoPreview'); if (pi) pi.src = '';
    var w  = document.getElementById('previewWrap1'); if (w)  w.style.display = 'none';
    var a  = document.getElementById('uploadArea');   if (a)  a.style.display = '';
    var o  = document.getElementById('demoOverlay');  if (o)  o.style.display = 'none';
    showScreen('formScreen');
    updateBtn(activeTab);
    renderTokenBar(activeTab === 'video');
  }

  var newBtn = document.getElementById('resultNewBtn');
  if (newBtn) newBtn.addEventListener('click', function(){
    if (window._lastResultWasDemo && usingDefault) {
      // Keep demo photo, just go back to form and scroll to Generate
      showScreen('formScreen');
      var gBtn = document.getElementById('genBtnImg2img');
      if (gBtn) setTimeout(function(){ gBtn.scrollIntoView({behavior:'smooth',block:'center'}); }, 200);
    } else {
      newBtn.innerHTML = '<span data-i18n="gen_back">← Back to templates</span>';
      if (typeof applyI18n === 'function') applyI18n();
      doNewPhoto();
    }
  });

  // Demo banner upload handler
  var demoUpInput = document.getElementById('demoUploadInput');
  if (demoUpInput) {
    demoUpInput.addEventListener('change', function(e){
      var file = e.target.files && e.target.files[0];
      if (!file) return;
      var banner = document.getElementById('demoUploadBanner'); if(banner) banner.style.display='none';
      photoFile = file;
      usingDefault = false;
      fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_own_photo'})}).catch(function(){});
      startGen(selectedTpl || 'undress', photoFile, null);
    });
  }

  window.limitModalCrypto = function() {
    var btn = document.getElementById('limitModalPay');
    var st  = document.getElementById('limitModalCryptoStatus');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Creating invoice…'; }
    fetch('/api/create_crypto_invoice_anon.php', {method:'POST'})
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok && data.payment_url) {
          if (st) st.textContent = 'Opening payment page…';
          window.location.href = data.payment_url;
        } else {
          var msg = data.error === 'already_paid' ? 'Already paid! Reload page.' : '❌ ' + (data.error||'Error');
          if (st) st.textContent = msg;
          if (btn) { btn.disabled = false; btn.textContent = '₿ Pay $1.99 crypto — no account needed'; }
        }
      }).catch(function(){
        if (st) st.textContent = 'Connection error, try again';
        if (btn) { btn.disabled = false; btn.textContent = '₿ Pay $1.99 crypto — no account needed'; }
      });
  };

  window.demoBannerUseSample = function() {
    var banner = document.getElementById('demoUploadBanner'); if(banner) banner.style.display='none';
    fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_own_photo',value:'sample_banner'})}).catch(function(){});
    fetch(window.__GEN_BOOT__.demoSrc)
      .then(function(r){ return r.blob(); })
      .then(function(blob){
        photoFile = new File([blob], 'sample.jpg', {type:'image/jpeg'});
        usingDefault = false;
        var pi = document.getElementById('photoPreview'); if(pi) pi.src = window.__GEN_BOOT__.demoSrc;
        var w = document.getElementById('previewWrap1'); if(w) w.style.display='block';
        var a = document.getElementById('uploadArea'); if(a) a.style.display='none';
        updateBtn('img2img');
        showScreen('formScreen');
        setTimeout(function(){ startGen(selectedTpl||'undress', photoFile, null); }, 200);
      }).catch(function(){});
  };

  var newBtnTop = document.getElementById('resultNewBtnTop');
  if (newBtnTop) newBtnTop.addEventListener('click', doNewPhoto);

  // ── Catalog interactions ───────────────────────────────────────────────────
  var _tStart = null; // {el, x, y, moved}

  function catApplyTemplate(el) {
    var id   = el.getAttribute('data-id') || '';
    var name = el.getAttribute('data-name') || '';
    var src  = el.getAttribute('data-src') || '';
    var gen  = el.getAttribute('data-gen') || '';
    var type = el.getAttribute('data-type') || 'img2img';
    var img1 = el.getAttribute('data-img1') || src;
    var img2 = el.getAttribute('data-img2') || '';
    var vid    = el.getAttribute('data-vid')  || '';
    var prompt = el.getAttribute('data-prompt') || '';

    if (history.pushState) history.pushState(null, '', '/gen.php?tpl=' + encodeURIComponent(id));
    document.querySelectorAll('.cat-item').forEach(function(c){ c.classList.remove('selected'); });
    el.classList.add('selected');
    fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'gen_tpl_select',value:id})}).catch(function(){});
    window.__activeTplPrompt = prompt;
    window.__activeTplId = id;

    var title = document.getElementById('tplTitle');
    if (title) title.textContent = name;

    // ── Before side ──
    var baSingle   = document.getElementById('baSingleBefore');
    var baImgBefore = document.getElementById('baImgBefore');
    var baDualP1   = document.getElementById('baDualPhoto1');
    var baDualP2   = document.getElementById('baDualPhoto2');
    var baDualImg1 = document.getElementById('baDualImg1');
    var baDualImg2 = document.getElementById('baDualImg2');
    if (type === 'dual') {
      if (baSingle) baSingle.style.display = 'none';
      if (baDualP1) { baDualP1.style.display = 'block'; if (img1 && baDualImg1) baDualImg1.src = img1; }
      if (baDualP2) { baDualP2.style.display = 'block'; if (img2 && baDualImg2) baDualImg2.src = img2; }
    } else {
      if (baSingle) { baSingle.style.display = 'block'; }
      if (baImgBefore) { if (src) baImgBefore.src = src; }
      if (baDualP1) baDualP1.style.display = 'none';
      if (baDualP2) baDualP2.style.display = 'none';
    }

    // ── After side ──
    var baImgAfter   = document.getElementById('baImgAfter');
    var baVideoAfter = document.getElementById('baVideoAfter');
    if (type === 'video' && vid) {
      if (baImgAfter)   baImgAfter.style.display   = 'none';
      if (baVideoAfter) { baVideoAfter.style.display = 'block'; baVideoAfter.src = vid; baVideoAfter.play().catch(function(){}); }
    } else {
      if (baImgAfter)   { baImgAfter.style.display = 'block'; if (gen) baImgAfter.src = gen; }
      if (baVideoAfter) { baVideoAfter.style.display = 'none'; baVideoAfter.pause(); baVideoAfter.src = ''; }
    }

    if (usingDefault && src) {
      fetch(src).then(function(r){ return r.blob(); }).then(function(blob){
        photoFile = new File([blob], 'demo.jpg', {type: blob.type || 'image/jpeg'});
        var pi = document.getElementById('photoPreview');
        if (pi) pi.src = src;
      }).catch(function(){});
    }
    // Switch to correct panel
    var panels = {img2img:'panelImg2img', video:'panelVideo', dual:'panelDual'};
    var targetPanel = type === 'video' ? 'video' : (type === 'dual' ? 'dual' : 'img2img');
    Object.keys(panels).forEach(function(k) {
      var p = document.getElementById(panels[k]);
      if (p) p.style.display = k === targetPanel ? 'block' : 'none';
    });
    activeTab = targetPanel;
    var tplId = el.getAttribute('data-tpl-id') || '';
    selectedTpl = type === 'video' ? 'flux2-wan22-i2v-foley' : (type === 'dual' ? 'dual' : (tplId || 'undress'));

    renderTokenBar(type === 'video');
    if (usingDefault) {
      var gBtnId = type === 'video' ? 'genBtnVideo' : (type === 'dual' ? 'genBtnDual' : 'genBtnImg2img');
      var gBtn = document.getElementById(gBtnId);
      if (gBtn) setTimeout(function(){ gBtn.scrollIntoView({behavior:'smooth',block:'center'}); }, 300);
    } else {
      var up = document.getElementById('uploadArea');
      if (up) {
        // Update upload text to reference selected template
        var upTitle = up.querySelector('.upload-title');
        var upSub   = up.querySelector('.upload-sub');
        if (upTitle) upTitle.textContent = name ? ('📸 Upload photo → ' + name) : 'Upload your photo';
        if (upSub)   upSub.textContent   = 'Tap here to upload · JPG, PNG, HEIC';
        up.scrollIntoView({behavior: 'smooth', block: 'center'});
        setTimeout(function(){
          up.classList.remove('pulsing');
          void up.offsetWidth;
          up.classList.add('pulsing');
        }, 350);
      }
    }
  }

  var catGrid = document.getElementById('catGrid');

  // Mobile: touchstart → show source (animate), touchend → hide + tap check
  catGrid.addEventListener('touchstart', function(e) {
    var el = e.target.closest('.cat-item');
    if (!el || el.getAttribute('data-has-anim') !== 'true') return;
    _tStart = {el: el, x: e.touches[0].clientX, y: e.touches[0].clientY, moved: false};
    el.classList.add('cat-anim');
  }, {passive: true});

  catGrid.addEventListener('touchmove', function(e) {
    if (!_tStart) return;
    var dx = e.touches[0].clientX - _tStart.x;
    var dy = e.touches[0].clientY - _tStart.y;
    if (Math.abs(dx) > 10 || Math.abs(dy) > 10) _tStart.moved = true;
  }, {passive: true});

  catGrid.addEventListener('touchend', function(e) {
    if (!_tStart) return;
    var ts = _tStart; _tStart = null;
    ts.el.classList.remove('cat-anim');
    if (!ts.moved) {
      e.preventDefault();
      catApplyTemplate(ts.el);
    }
  });

  catGrid.addEventListener('touchcancel', function() {
    if (_tStart) { _tStart.el.classList.remove('cat-anim'); _tStart = null; }
  }, {passive: true});

  // Desktop click (hover handled by CSS)
  catGrid.addEventListener('contextmenu', function(e) { if (e.target.closest('.cat-item')) e.preventDefault(); });
  catGrid.addEventListener('click', function(e) {
    var el = e.target.closest('.cat-item');
    if (!el) return;
    e.preventDefault();
    catApplyTemplate(el);
  });

  // Apply translations
  if (typeof applyI18n === 'function') applyI18n();

  // Clear demo button
  var clearDemoBtn = document.getElementById('clearDemoBtn');
  if (clearDemoBtn) {
    clearDemoBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      usingDefault = false;
      photoFile = null;
      var w = document.getElementById('previewWrap1'); if (w) w.style.display = 'none';
      var a = document.getElementById('uploadArea'); if (a) a.style.display = 'block';
      var pi = document.getElementById('photoPreview'); if (pi) pi.src = '';
      updateBtn('img2img');
    });
  }
  // Initial state — set correct panel and load files
  if (ACTIVE_TPL_TYPE === 'dual') {
    activeTab = 'dual';
    updateBtn('dual');
  } else if (ACTIVE_TPL_TYPE === 'video') {
    activeTab = 'video';
  } else {
    updateBtn('img2img');
  }

  showScreen('formScreen');
})();
</script>


  <!-- ══════ REGISTRATION OVERLAY (after 1st gen) ══════ -->
  <div id="genRegOverlay" style="display:none;position:fixed;inset:0;z-index:10020;background:rgba(0,0,0,.93);align-items:center;justify-content:center;padding:20px">
    <div style="background:#141420;border-radius:20px;padding:28px 22px 24px;max-width:340px;width:100%;text-align:center;box-shadow:0 24px 80px rgba(99,102,241,.2),0 0 0 1px rgba(99,102,241,.2)">
      <div style="display:flex;gap:6px;border-radius:12px;overflow:hidden;height:130px;margin-bottom:16px">
        <div style="flex:1;position:relative"><img src="/showcase/demo_girl_new.jpg" style="width:100%;height:100%;object-fit:cover;object-position:top"><div style="position:absolute;bottom:5px;left:5px;background:rgba(0,0,0,.65);color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:20px">BEFORE</div></div>
        <div style="flex:1;position:relative"><img src="/showcase/gen_1783626085_44d7e7.png" style="width:100%;height:100%;object-fit:cover;object-position:top"><div style="position:absolute;bottom:5px;right:5px;background:rgba(236,72,153,.85);color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:20px">AFTER ✨</div></div>
      </div>
      <h3 data-i18n="gen_reg_title" style="color:#fff;font-size:20px;font-weight:900;margin:0 0 6px">5 free generations used</h3>
      <p data-i18n="gen_reg_sub" style="color:rgba(255,255,255,.5);font-size:13px;margin:0 0 18px;line-height:1.5">Sign up and get 3 more free generations</p>
      <div style="display:flex;flex-direction:column;gap:10px;text-align:left">
        <input id="genRegNick" type="text" data-i18n-placeholder="gen_reg_nick_ph" placeholder="Your nickname" autocomplete="username" maxlength="50"
          style="width:100%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.13);border-radius:12px;padding:14px 16px;color:#fff;font-size:16px;outline:none;box-sizing:border-box">
        <button type="button" id="genRegSubmit" onclick="submitGenReg()"
          style="width:100%;height:52px;background:linear-gradient(135deg,#ec4899,#a855f7);border:none;border-radius:14px;color:#fff;font-weight:900;font-size:17px;cursor:pointer">
          <span data-i18n="gen_reg_btn">Get 3 free →</span>
        </button>
      </div>
      <p id="genRegError" style="color:#f87171;font-size:13px;margin:8px 0 0;display:none"></p>
      <button type="button" onclick="document.getElementById('genRegOverlay').style.display='none'"
        style="margin-top:14px;background:none;border:none;color:rgba(255,255,255,.25);font-size:13px;cursor:pointer" data-i18n="gen_reg_skip">Skip</button>
    </div>
  </div>
  <!-- /REGISTRATION OVERLAY -->

  <script>
  async function submitGenReg() {
    var nick = (document.getElementById('genRegNick').value || '').trim();
    var err  = document.getElementById('genRegError');
    var btn  = document.getElementById('genRegSubmit');
    var T    = window.__LANG_DATA__ || {};
    if (err) err.style.display = 'none';
    if (!nick || nick.length < 2) {
      if (err) { err.textContent = T.gen_reg_err_min || 'Min 2 chars'; err.style.display = 'block'; }
      return;
    }
    if (btn) { btn.disabled = true; btn.textContent = '...'; }
    try {
      var r = await fetch('/api/auth_password.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'register_nick_only',nick:nick})});
      var d = await r.json();
      if (d.ok) {
        window.__GEN_BOOT__.siteUser = {id: d.id, name: nick, paid: false};
        document.getElementById('genRegOverlay').style.display = 'none';
        try { localStorage.setItem('fapa_gen_used', '0'); } catch(_) {}
        fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'reg_wall_success_register'})}).catch(function(){});
      } else {
        var msg = d.error === 'taken' ? (T.gen_reg_err_taken || 'Nickname taken') : (d.error || 'Error');
        if (err) { err.textContent = msg; err.style.display = 'block'; }
        if (btn) { btn.disabled = false; btn.textContent = T.gen_reg_btn || 'Get 3 free →'; }
      }
    } catch(e) {
      if (err) { err.textContent = T.gen_reg_err_conn || 'No connection'; err.style.display = 'block'; }
      if (btn) { btn.disabled = false; btn.textContent = T.gen_reg_btn || 'Get 3 free →'; }
    }
  }
  </script>

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
<script src="/js/push.js?v=1"></script>

<!-- Topup modal -->
<div id="topupOverlay"></div>
<div id="topupSheet">
  <button id="topupClose">&#x2715;</button>
  <div class="topup-balance">
    <div class="topup-balance-num" id="topupBalNum">600 T</div>
    <div class="topup-balance-lbl">Ваш баланс</div>
  </div>
  <div class="topup-packages">
    <div class="topup-pkg" data-price="1" data-tokens="1000">
      <div class="topup-pkg-tok-lbl">токенов</div>
      <div class="topup-pkg-tokens">1 000</div>
      <div class="topup-pkg-price">$1</div>
      <div class="topup-pkg-bonus">&nbsp;</div>
    </div>
    <div class="topup-pkg selected" data-price="10" data-tokens="12000">
      <div class="topup-pkg-tok-lbl">токенов</div>
      <div class="topup-pkg-tokens">12 000</div>
      <div class="topup-pkg-price">$10</div>
      <div class="topup-pkg-bonus">+20% бонус</div>
    </div>
    <div class="topup-pkg" data-price="50" data-tokens="70000">
      <div class="topup-pkg-tok-lbl">токенов</div>
      <div class="topup-pkg-tokens">70 000</div>
      <div class="topup-pkg-price">$50</div>
      <div class="topup-pkg-bonus">+40% бонус</div>
    </div>
  </div>
  <a href="/topup.php?method=stars" class="topup-pay-btn topup-stars" id="topupBtnStars">
    <span style="font-size:22px;">⭐</span>
    <div class="topup-pay-btn-inner">
      <div class="topup-pay-btn-title">Telegram Stars &nbsp;<span style="font-size:11px;font-weight:700;background:rgba(255,255,255,.22);border-radius:6px;padding:2px 7px;">Карты</span></div>
      <div class="topup-pay-btn-sub">Visa / Mastercard — через Telegram</div>
    </div>
    <div class="topup-pay-btn-price" id="topupPriceStars">$10</div>
  </a>
  <a href="/topup.php?method=crypto" class="topup-pay-btn topup-crypto" id="topupBtnCrypto">
    <span style="font-size:22px;">₿</span>
    <div class="topup-pay-btn-inner">
      <div class="topup-pay-btn-title">Crypto</div>
      <div class="topup-pay-btn-sub">BTC, USDT, ETH и другие</div>
    </div>
    <div class="topup-pay-btn-price" id="topupPriceCrypto">$10</div>
  </a>
</div>
<script>
(function(){
  var overlay    = document.getElementById('topupOverlay');
  var sheet      = document.getElementById('topupSheet');
  var triggerBtn = document.getElementById('topupTriggerBtn');
  var closeBtn   = document.getElementById('topupClose');
  var priceStars = document.getElementById('topupPriceStars');
  var priceCrypto= document.getElementById('topupPriceCrypto');
  var balNum     = document.getElementById('topupBalNum');
  var pkgs       = document.querySelectorAll('.topup-pkg');

  function openSheet() {
    if (balNum) {
      var t = document.getElementById('walletTokens');
      balNum.textContent = (document.getElementById('walletGens') ? document.getElementById('walletGens').textContent.replace(/[^0-9\/∞]/g,'') : '5/5');
    }
    overlay.classList.add('open'); sheet.classList.add('open');
    fetch('/scripts/track_funnel.php',{method:'POST',body:new URLSearchParams({event:'topup_click'})}).catch(function(){});
  }
  function closeSheet(){ overlay.classList.remove('open'); sheet.classList.remove('open'); }

  function getSelectedPrice() {
    var sel = document.querySelector('.topup-pkg.selected');
    return sel ? sel.getAttribute('data-price') : '10';
  }

  pkgs.forEach(function(pkg) {
    pkg.addEventListener('click', function() {
      pkgs.forEach(function(p){ p.classList.remove('selected'); });
      pkg.classList.add('selected');
      var price = pkg.getAttribute('data-price');
      if (priceStars)  priceStars.textContent  = '$' + price;
      if (priceCrypto) priceCrypto.textContent = '$' + price;
    });
  });

  document.querySelectorAll('#topupBtnStars, #topupBtnCrypto').forEach(function(a) {
    a.addEventListener('click', function(e) {
      e.preventDefault();
      var method = a.id === 'topupBtnStars' ? 'stars' : 'crypto';
      window.location.href = '/topup.php?method=' + method + '&price=' + getSelectedPrice();
    });
  });

  if (triggerBtn) triggerBtn.addEventListener('click', openSheet);
  if (closeBtn)   closeBtn.addEventListener('click', closeSheet);
  if (overlay)    overlay.addEventListener('click', closeSheet);
})();
</script>
</body>
</html>
