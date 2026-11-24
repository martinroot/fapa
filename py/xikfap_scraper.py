#!/usr/bin/env python3
"""
xikfap_scraper.py — Real-time xikfap.com ingestion pipeline
============================================================
Fetches videos from xikfap.com API, downloads HLS from CDN,
validates, stores locally in uvid/{id}/, adds to DB immediately.

Modes:
  cats (default) — rotate through all categories, pick up new videos
  ids            — sweep IDs 1968..MAX sequentially (bulk history)

Run:
    python3 xikfap_scraper.py                     # category mode, continuous
    python3 xikfap_scraper.py --mode ids          # ID sweep (bulk, run once)
    python3 xikfap_scraper.py --mode ids --start-id 5000 --end-id 10000
    python3 xikfap_scraper.py --limit 20          # stop after N new videos
"""

import argparse, json, os, pathlib, re, subprocess, sys, time
from typing import Optional
import pymysql
import urllib.request
import urllib.error

# ─────── PATHS ───────
TARGET_ROOT  = pathlib.Path("/home/claude2/uvid")
_DEFAULT_LOG = pathlib.Path("/home/claude2/uvid/../core-vpn-click-scraper.log")
LOG_FILE     = _DEFAULT_LOG   # overridden by --log-file arg in main()
SKIP_FILE    = pathlib.Path("/home/claude2/uvid/../core-vpn-click_skip.txt")
FFMPEG       = "/usr/bin/ffmpeg"

# ─────── ID SWEEP BOUNDS ───────
ID_FIRST    = 1968    # first known valid ID on xikfap
ID_LAST_URL = "https://xikfap.com"  # fetch current max from homepage

# ─────── XIKFAP API ───────
BASE_URL    = "https://xikfap.com"
LIST_API    = BASE_URL + "/api/load_more.php"
PLAY_API    = BASE_URL + "/api/playback_proxy.php"
CATEGORIES  = ["all", "hetero", "lesbian", "trans", "gay"]
PAGE_SIZE   = 20     # xikfap API returns max 20 items per page

# ─────── RATE LIMITS ───────
DELAY_API   = 0.3    # seconds between API calls
DELAY_VIDEO = 10     # новинки редки; реакция важнее паузы (~22с/видео ≈ 3900/сутки потолок)
LOOP_PAUSE  = 120    # seconds between full category round-trips

# ─────── DB ───────
DB_HOST = "127.0.0.1"
DB_PORT = 3306
DB_USER = "pma"
DB_PASS = os.environ.get("FAPAIO_DB_PASSWORD", "")
DB_NAME = "fapaio"

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
      "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36")

HEADERS = {
    "User-Agent": UA,
    "Referer": BASE_URL + "/",
    "Accept": "application/json, */*",
    "Accept-Language": "ru-RU,ru;q=0.9,en;q=0.8",
}


# ═══════════════════════ LOGGING ═══════════════════════

def log(msg: str):
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{ts}] {msg}"
    print(line, flush=True)
    try:
        LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
        with open(LOG_FILE, "a") as f:
            f.write(line + "\n")
    except Exception:
        pass


# ═══════════════════════ HTTP ═══════════════════════

def http_get(url: str, params: dict = None) -> Optional[dict]:
    if params:
        qs = "&".join(f"{k}={v}" for k, v in params.items())
        url = url + "?" + qs
    req = urllib.request.Request(url, headers=HEADERS)
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        log(f"  HTTP error {url}: {e}")
        return None


# ═══════════════════════ DB ═══════════════════════

def db_connect():
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, user=DB_USER,
        password=DB_PASS, database=DB_NAME,
        charset="utf8mb4", autocommit=True
    )


def db_get_existing(conn) -> set:
    cur = conn.cursor()
    cur.execute("SELECT external_id FROM videos")
    return {row[0] for row in cur.fetchall()}


def db_import(conn, vid_id: int, username: str, tags: list,
              m3u8: str, poster: str):
    cur = conn.cursor()

    user_id = None
    if username:
        cur.execute("INSERT IGNORE INTO users (username) VALUES (%s)", (username,))
        cur.execute("SELECT id FROM users WHERE username=%s LIMIT 1", (username,))
        row = cur.fetchone()
        if row:
            user_id = row[0]

    page   = f"{BASE_URL}/video/{vid_id}"
    stream = ""

    cur.execute("""
        INSERT INTO videos
          (external_id, user_id, username, page, stream, folder_id, poster_path, m3u8_path)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s)
        ON DUPLICATE KEY UPDATE
          user_id=VALUES(user_id), username=VALUES(username),
          page=VALUES(page), poster_path=VALUES(poster_path),
          m3u8_path=VALUES(m3u8_path), updated_at=NOW()
    """, (vid_id, user_id, username, page, stream, vid_id, poster, m3u8))

    cur.execute("SELECT id FROM videos WHERE external_id=%s LIMIT 1", (vid_id,))
    row = cur.fetchone()
    if not row:
        return
    vid_db = row[0]

    for tag in tags:
        tag = tag.strip()
        if not tag:
            continue
        slug = tag.lower().replace(" ", "-")
        cur.execute("INSERT IGNORE INTO tags (name,slug) VALUES (%s,%s)", (tag, slug))
        cur.execute("SELECT id FROM tags WHERE name=%s LIMIT 1", (tag,))
        t = cur.fetchone()
        if t:
            cur.execute("INSERT IGNORE INTO video_tags (video_id,tag_id) VALUES (%s,%s)",
                        (vid_db, t[0]))


# ═══════════════════════ FFMPEG ═══════════════════════

def run_ffmpeg(cmd: list) -> bool:
    try:
        res = subprocess.run(
            cmd, check=True,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
            timeout=120
        )
        return True
    except subprocess.CalledProcessError as e:
        log(f"    ffmpeg error: {(e.output or '')[-300:]}")
        return False
    except subprocess.TimeoutExpired:
        log("    ffmpeg timeout (120s)")
        return False


def download_hls(hls_url: str, vid_dir: pathlib.Path, vid_id: int) -> bool:
    """
    Download CDN HLS → local HLS TS chunks.
    Try stream copy first (fast), fallback to re-encode.
    """
    m3u8    = vid_dir / "index.m3u8"
    seg_pat = str(vid_dir / "chunk_%05d.ts")

    # Pass Referer header to ffmpeg so CDN accepts the request
    headers = f"Referer: {BASE_URL}/\r\nUser-Agent: {UA}\r\n"

    # Attempt 1: stream copy (no re-encode, fastest)
    ok = run_ffmpeg([
        FFMPEG, "-y", "-loglevel", "warning",
        "-headers", headers,
        "-i", hls_url,
        "-c", "copy",
        "-bsf:a", "aac_adtstoasc",
        "-f", "hls",
        "-hls_time", "6",
        "-hls_playlist_type", "vod",
        "-hls_segment_filename", seg_pat,
        str(m3u8),
    ])

    if not ok or not m3u8.exists() or m3u8.stat().st_size < 32:
        log(f"  [{vid_id}] stream copy failed → re-encoding")
        # Clean up partial output
        for f in vid_dir.glob("chunk_*.ts"):
            f.unlink(missing_ok=True)
        if m3u8.exists():
            m3u8.unlink()

        ok = run_ffmpeg([
            FFMPEG, "-y", "-loglevel", "warning",
            "-headers", headers,
            "-i", hls_url,
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "23",
            "-c:a", "aac", "-b:a", "128k", "-ac", "2",
            "-f", "hls",
            "-hls_time", "6",
            "-hls_playlist_type", "vod",
            "-hls_segment_filename", seg_pat,
            str(m3u8),
        ])

    return ok and m3u8.exists() and m3u8.stat().st_size > 32


def extract_poster(hls_url: str, vid_dir: pathlib.Path, vid_id: int,
                   poster_url: str = "") -> bool:
    poster = vid_dir / "poster.jpg"
    headers = f"Referer: {BASE_URL}/\r\nUser-Agent: {UA}\r\n"

    # Try CDN poster_url first (PNG)
    if poster_url:
        ok = run_ffmpeg([
            FFMPEG, "-y", "-loglevel", "warning",
            "-headers", headers,
            "-i", poster_url,
            "-frames:v", "1",
            str(poster),
        ])
        if ok and poster.exists() and poster.stat().st_size > 500:
            return True

    # Fallback: first frame from HLS stream
    ok = run_ffmpeg([
        FFMPEG, "-y", "-loglevel", "warning",
        "-headers", headers,
        "-i", hls_url,
        "-vf", r"select=eq(n\,0)",
        "-vframes", "1", "-q:v", "2",
        str(poster),
    ])
    return ok and poster.exists() and poster.stat().st_size > 500


# ═══════════════════════ VALIDATION ═══════════════════════

def validate(vid_dir: pathlib.Path, vid_id: int) -> bool:
    """Check m3u8 exists, has segments, all .ts non-empty."""
    m3u8 = vid_dir / "index.m3u8"
    if not m3u8.exists() or m3u8.stat().st_size < 32:
        log(f"  [{vid_id}] ✗ validate: m3u8 missing/empty")
        return False

    chunks = list(vid_dir.glob("chunk_*.ts"))
    if not chunks:
        log(f"  [{vid_id}] ✗ validate: no .ts chunks")
        return False

    bad = [c for c in chunks if c.stat().st_size < 512]
    if bad:
        log(f"  [{vid_id}] ✗ validate: {len(bad)} tiny/empty chunks")
        return False

    total_kb = sum(c.stat().st_size for c in chunks) // 1024
    log(f"  [{vid_id}] ✓ validate: {len(chunks)} chunks, {total_kb} KB total")
    return True


# ═══════════════════════ CORE: process one video ═══════════════════════

def process_video(item: dict, conn, existing_ids: set) -> bool:
    vid_id   = int(item["id"])
    shortcode = item.get("src", "")
    username  = (item.get("title") or item.get("username") or "xikfap").strip().removesuffix(".mp4")
    tags      = item.get("tags") or []
    duration  = item.get("duration") or 0

    if vid_id in existing_ids:
        return False  # already have it

    log(f"\n[{vid_id}] {username}  dur={duration}s  tags={tags[:4]}")

    # Get playback URL
    play = http_get(PLAY_API, {"shortcode": shortcode})
    if not play or not play.get("hls_url"):
        log(f"  [{vid_id}] ✗ no playback URL — skipping permanently")
        existing_ids.add(vid_id)   # don't retry this session
        save_skip_id(vid_id)
        return False

    hls_url    = play["hls_url"]
    poster_url = play.get("poster_url", "")
    time.sleep(DELAY_API)

    # Prepare directory
    vid_dir = TARGET_ROOT / str(vid_id)
    vid_dir.mkdir(parents=True, exist_ok=True)

    # Download HLS
    log(f"  [{vid_id}] Downloading HLS...")
    ok_hls = download_hls(hls_url, vid_dir, vid_id)
    if not ok_hls:
        log(f"  [{vid_id}] ✗ HLS download failed, cleaning up")
        import shutil
        shutil.rmtree(vid_dir, ignore_errors=True)
        existing_ids.add(vid_id)
        save_skip_id(vid_id)
        return False

    # Validate
    if not validate(vid_dir, vid_id):
        import shutil
        shutil.rmtree(vid_dir, ignore_errors=True)
        existing_ids.add(vid_id)   # CDN content gone — don't retry
        save_skip_id(vid_id)
        return False

    # Poster
    log(f"  [{vid_id}] Extracting poster...")
    extract_poster(hls_url, vid_dir, vid_id, poster_url)

    # Write JSON
    json_out = {
        "id":     vid_id,
        "user":   username,
        "page":   f"{BASE_URL}/video/{vid_id}",
        "stream": hls_url,
        "tags":   tags,
        "src":    shortcode,
    }
    (vid_dir / f"{vid_id}.json").write_text(
        json.dumps(json_out, ensure_ascii=False, indent=2), "utf-8"
    )

    # DB import
    m3u8_path   = str(vid_dir / "index.m3u8")
    poster_path = str(vid_dir / "poster.jpg") if (vid_dir / "poster.jpg").exists() else ""
    db_import(conn, vid_id, username, tags, m3u8_path, poster_path)

    existing_ids.add(vid_id)
    chunks = len(list(vid_dir.glob("chunk_*.ts")))
    log(f"  [{vid_id}] ✓ DONE — {chunks} chunks | @{username} | {len(tags)} tags")
    return True


# ═══════════════════════ FETCH PAGE ═══════════════════════

def fetch_page(category: str, offset: int) -> list:
    data = http_get(LIST_API, {
        "category": category,
        "offset":   offset,
        "limit":    PAGE_SIZE,
    })
    if not data:
        return []
    # filter only video items
    return [v for v in data if v.get("type") == "video" or v.get("id")]


# ═══════════════════════ MAIN ═══════════════════════

def scrape(category: str, conn, existing_ids: set,
           max_new: int = 0) -> int:
    """
    Scrape one category, return number of new videos downloaded.
    max_new=0 means no limit.
    """
    new_total = 0
    offset    = 0

    log(f"\n{'='*60}")
    log(f"Scraping category={category} | already in DB: {len(existing_ids)}")

    while True:
        items = fetch_page(category, offset)
        if not items:
            log(f"  No more items at offset={offset}")
            break

        log(f"  Page offset={offset}  got {len(items)} items")
        time.sleep(DELAY_API)

        new_on_page = 0
        skipped     = 0
        for item in items:
            vid_id = int(item.get("id", 0))
            if vid_id in existing_ids:
                skipped += 1
                continue

            ok = process_video(item, conn, existing_ids)
            if ok:
                new_total += 1
                new_on_page += 1
            time.sleep(DELAY_VIDEO)

            if max_new and new_total >= max_new:
                log(f"  Reached max_new={max_new}, stopping")
                return new_total

        log(f"  Page done: {new_on_page} new, {skipped} skipped")

        # If entire page was already in DB, no point going deeper — all older
        # content will also be in DB (videos added newest-first).
        if new_on_page == 0 and skipped == len(items):
            log(f"  Full page already known, stopping pagination")
            break

        offset += PAGE_SIZE

    return new_total


# ═══════════════════════ SKIP FILE ═══════════════════════

def load_skip_ids():
    """Load set of IDs we already know don't exist / permanently failed."""
    if not SKIP_FILE.exists():
        return set()
    result = set()
    for line in SKIP_FILE.read_text().splitlines():
        line = line.strip()
        if line.isdigit():
            result.add(int(line))
    return result


def save_skip_id(vid_id):
    SKIP_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(SKIP_FILE, "a") as f:
        f.write(f"{vid_id}\n")


# ═══════════════════════ VIDEO PAGE PARSER ═══════════════════════

def fetch_video_page(vid_id):
    """
    Fetch https://xikfap.com/video/{id} and extract embedded JSON.
    Returns dict with id/src/title/tags or None if not found.
    """
    url = BASE_URL + "/video/" + str(vid_id)
    req = urllib.request.Request(url, headers=HEADERS)
    try:
        with urllib.request.urlopen(req, timeout=12) as resp:
            html = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        if e.code in (404, 410):
            return None
        log(f"  [{vid_id}] HTTP {e.code} fetching page")
        return None
    except Exception as e:
        log(f"  [{vid_id}] page fetch error: {e}")
        return None

    # Page embeds JSON: {"id":XXXXX,"src":"HASH","poster":"...","title":"...","tags":[...]}
    m = re.search(
        r'"id"\s*:\s*' + str(vid_id) +
        r'\s*,\s*"src"\s*:\s*"([\w\-]+)"'
        r'.*?"title"\s*:\s*"([^"]*)"'
        r'.*?"tags"\s*:\s*(\[[^\]]*\])',
        html, re.DOTALL
    )
    if not m:
        # Maybe page exists but JSON format differs — mark as skip
        return None

    src   = m.group(1)
    title = m.group(2)
    try:
        tags = json.loads(m.group(3))
    except Exception:
        tags = []

    return {"id": vid_id, "src": src, "title": title, "tags": tags}


def get_current_max_id():
    """Fetch xikfap homepage to find highest recent video ID."""
    req = urllib.request.Request(BASE_URL, headers=HEADERS)
    try:
        with urllib.request.urlopen(req, timeout=12) as resp:
            html = resp.read().decode("utf-8", errors="replace")
        ids = [int(x) for x in re.findall(r'"/video/(\d+)"', html)]
        if ids:
            return max(ids)
    except Exception:
        pass
    return 32200  # fallback


# ═══════════════════════ ID SWEEP ═══════════════════════

def id_sweep(start_id, end_id, conn, existing_ids, skip_ids, max_new=0):
    """
    Walk IDs start_id..end_id, fetch page, download HLS for unknown IDs.
    Returns number of new videos added.
    """
    new_total = 0
    consec_missing = 0  # consecutive 404s — sanity check

    log(f"\n{'='*60}")
    log(f"ID sweep {start_id}..{end_id} | DB:{len(existing_ids)} skip:{len(skip_ids)}")

    for vid_id in range(start_id, end_id + 1):
        if vid_id in existing_ids or vid_id in skip_ids:
            continue

        time.sleep(DELAY_API)
        item = fetch_video_page(vid_id)

        if item is None:
            save_skip_id(vid_id)
            skip_ids.add(vid_id)
            consec_missing += 1
            if consec_missing % 50 == 0:
                log(f"  [{vid_id}] {consec_missing} consecutive missing IDs...")
            continue

        consec_missing = 0
        ok = process_video(item, conn, existing_ids)
        if ok:
            new_total += 1
        time.sleep(DELAY_VIDEO)

        if max_new and new_total >= max_new:
            log(f"  Reached max_new={max_new}")
            break

    log(f"ID sweep done: {new_total} new videos")
    return new_total


# ═══════════════════════ MAIN ═══════════════════════

def main():
    parser = argparse.ArgumentParser(description="xikfap.com real-time scraper")
    parser.add_argument("--mode",     default="cats",
                        choices=["cats", "ids"],
                        help="cats=category polling (default), ids=sequential ID sweep")
    parser.add_argument("--cat",      default="all",
                        help=f"Category for cats mode: {CATEGORIES} (default: all→all cats)")
    parser.add_argument("--start-id", type=int, default=0,
                        help="ID sweep start (default: ID_FIRST=1968)")
    parser.add_argument("--end-id",   type=int, default=0,
                        help="ID sweep end (default: auto-detect current max)")
    parser.add_argument("--limit",    type=int, default=0,
                        help="Stop after N new videos (0=unlimited)")
    parser.add_argument("--once",     action="store_true",
                        help="Run once then exit")
    parser.add_argument("--log-file", default="",
                        help="Override log file path")
    args = parser.parse_args()

    global LOG_FILE
    if args.log_file:
        LOG_FILE = pathlib.Path(args.log_file)

    TARGET_ROOT.mkdir(parents=True, exist_ok=True)
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)

    log("=" * 60)
    log("xikfap_scraper started")
    log(f"  target : {TARGET_ROOT}")
    log(f"  db     : {DB_HOST}:{DB_PORT}/{DB_NAME}")
    log(f"  mode   : {args.mode}  limit={args.limit}  once={args.once}")
    log("=" * 60)

    conn         = db_connect()
    existing_ids = db_get_existing(conn)
    log(f"Videos already in DB: {len(existing_ids)}")

    # ── ID SWEEP MODE ──────────────────────────────────────────
    if args.mode == "ids":
        skip_ids = load_skip_ids()
        log(f"Skip list: {len(skip_ids)} IDs")

        start_id = args.start_id or ID_FIRST
        if args.end_id:
            end_id = args.end_id
        else:
            log("Detecting current max ID from homepage...")
            end_id = get_current_max_id()
            log(f"Max ID detected: {end_id}")

        cycle = 0
        while True:
            cycle += 1
            log(f"\n── ID sweep cycle {cycle}: {start_id}..{end_id} ──")
            n = id_sweep(start_id, end_id, conn, existing_ids, skip_ids,
                         max_new=args.limit)
            log(f"Cycle {cycle}: {n} new videos")

            if args.once or args.limit:
                break

            # After full sweep, re-detect max and sweep new IDs only
            new_end = get_current_max_id()
            if new_end > end_id:
                log(f"New IDs available: {end_id+1}..{new_end}")
                start_id = end_id + 1
                end_id   = new_end
            else:
                log(f"No new IDs, sleeping {LOOP_PAUSE}s...")
                time.sleep(LOOP_PAUSE)
                start_id = end_id + 1
                end_id   = get_current_max_id()

        conn.close()
        log("ID sweep stopped.")
        return

    # ── CATEGORY MODE (default) ─────────────────────────────────
    cats_to_scrape = CATEGORIES if args.cat == "all" else [args.cat]

    cycle = 0
    while True:
        cycle += 1
        log(f"\n── Cycle {cycle} ──")

        n_cycle = 0
        for cat in cats_to_scrape:
            n = scrape(cat, conn, existing_ids, max_new=args.limit)
            n_cycle += n
            if args.limit and n_cycle >= args.limit:
                break

        try:
            _c = conn.cursor(); _c.execute("SELECT COUNT(*) FROM videos")
            _tot = _c.fetchone()[0]
        except Exception:
            _tot = "?"
        log(f"SUMMARY total={_tot} new={n_cycle} at {time.strftime('%H:%M')}")

        if args.once or (args.limit and n_cycle >= args.limit):
            break

        log(f"Sleeping {LOOP_PAUSE}s before next scan...")
        time.sleep(LOOP_PAUSE)

    conn.close()
    log("Scraper stopped.")


if __name__ == "__main__":
    main()
