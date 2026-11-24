<?php
declare(strict_types=1);
require_once __DIR__ . '/cmd/config.php';

$conn = db_connect();
ensure_site_schema($conn);

// ── Пагинация и тег ──
$page  = max(1, (int)($_GET['p'] ?? 1));
$tag   = trim((string)($_GET['tag'] ?? ''));
$per   = 24;
$off   = ($page - 1) * $per;

// ── Список тегов (по популярности) ──
$tags = [];
if ($r = $conn->query("SELECT t.name, t.slug, COUNT(vt.video_id) c
    FROM tags t JOIN video_tags vt ON vt.tag_id = t.id
    GROUP BY t.id ORDER BY c DESC LIMIT 40")) {
    while ($row = $r->fetch_assoc()) $tags[] = $row;
    $r->close();
}

// ── Видео ──
$where  = "v.va_score >= 0";
$types  = '';
$args   = [];
if ($tag !== '') {
    $where .= " AND t.slug = ?";
    $types .= 's';
    $args[] = $tag;
}
$sql = "SELECT v.id, v.external_id, COALESCE(u.username, v.username) username,
        v.likes_cnt, v.views_cnt
        FROM videos v
        LEFT JOIN users u ON u.id = v.user_id
        LEFT JOIN video_tags vt ON vt.video_id = v.id
        LEFT JOIN tags t ON t.id = vt.tag_id
        WHERE $where
        GROUP BY v.id
        ORDER BY v.created_at DESC
        LIMIT $per OFFSET $off";
$st = $conn->prepare($sql);
if ($st && $types) {
    $st->bind_param($types, ...$args);
}
if ($st) $st->execute();
$videos = [];
if ($st && ($res = $st->get_result())) {
    while ($row = $res->fetch_assoc()) $videos[] = $row;
    $st->close();
}
$conn->close();

$hTitle = $tag !== '' ? ('#' . htmlspecialchars($tag)) : 'Video Catalog';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $hTitle ?> — Fapa</title>
<link rel="stylesheet" href="/css/feed.css?v=2">
<style>
body { overflow-y: auto !important; background:#000; color:#fff; }
.catalog { max-width:575px; margin:0 auto; padding:16px 16px 96px; }
.catalog h1 { font-size:18px; font-weight:900; margin:12px 0 14px; }
.tagbar { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.tagbar a { font-size:12px; padding:6px 10px; border-radius:999px;
  background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12);
  color:#cbd5e1; text-decoration:none; }
.tagbar a.act { background:#ec4899; color:#fff; border-color:#ec4899; }
.grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
.card { position:relative; border-radius:14px; overflow:hidden; background:#111;
  aspect-ratio:9/16; }
.card img { width:100%; height:100%; object-fit:cover; display:block; }
.card .meta { position:absolute; left:8px; right:8px; bottom:8px;
  display:flex; justify-content:space-between; font-size:11px;
  text-shadow:0 1px 2px rgba(0,0,0,.8); }
.pager { display:flex; justify-content:center; gap:16px; margin-top:18px; }
.pager a { color:#ec4899; text-decoration:none; font-weight:700; }
.pager span { color:#64748b; }
.topbar { display:flex; align-items:center; gap:12px; padding:12px 0 6px; }
.topbar a { color:#fff; text-decoration:none; font-size:22px; }
</style>
<script defer src="https://vibemetrics.click/tracker.js" data-site-id="fapa-cam" data-site-key="7b8cH_grFS_eZEm3Itb4Mh-hW28sjhia_WegOQDRd_k"></script>
</head>
<body>
<div class="catalog">
  <div class="topbar">
    <a href="/">←</a>
    <h1><?= $hTitle ?></h1>
  </div>

  <div class="tagbar">
    <a href="/catalog.php" <?= $tag === '' ? 'class="act"' : '' ?>>All</a>
    <?php foreach ($tags as $t): ?>
      <a href="/catalog.php?tag=<?= urlencode($t['slug']) ?>" <?= $tag === $t['slug'] ? 'class="act"' : '' ?>>#<?= htmlspecialchars($t['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="grid">
    <?php foreach ($videos as $v): ?>
      <a class="card" href="/v.php?id=<?= (int)$v['id'] ?>">
        <img src="/uvid/<?= (int)$v['external_id'] ?>/poster.jpg" alt="<?= htmlspecialchars($v['username']) ?>" loading="lazy">
        <div class="meta">
          <span>@<?= htmlspecialchars($v['username']) ?></span>
          <span>♥ <?= (int)$v['likes_cnt'] ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="pager">
    <?php if ($page > 1): ?><a href="/catalog.php?p=<?= $page - 1 ?><?= $tag !== '' ? '&tag=' . urlencode($tag) : '' ?>">← Prev</a><?php endif; ?>
    <span>Page <?= $page ?></span>
    <?php if (count($videos) === $per): ?><a href="/catalog.php?p=<?= $page + 1 ?><?= $tag !== '' ? '&tag=' . urlencode($tag) : '' ?>">Next →</a><?php endif; ?>
  </div>
</div>
</body>
</html>
