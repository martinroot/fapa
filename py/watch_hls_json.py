#!/usr/bin/env python3
"""
watch_hls_json.py — Server version (Linux/Docker)
=================================================
Watches input/ for .json files (dropped by Tampermonkey or manually),
downloads the stream URL via ffmpeg, creates HLS + poster,
saves to uvid/{ID}/, auto-imports to DB.

Input JSON format (from Tampermonkey):
  {
    "id":     12345,
    "page":   "https://xikfap.com/video/12345",
    "stream": "https://cdn.example.com/12345.mp4?token=...",
    "user":   "creator_name",       # optional → falls back to DEFAULT_USER
    "tags":   ["tag1", "tag2"]      # optional → falls back to DEFAULT_TAGS
  }

Drop .json files into:
  /var/www/core-vpn.click/py/input/

After processing:
  OK    → moved to  py/done/
  ERROR → moved to  py/errors/
  HLS   → saved in  uvid/{ID}/
"""

import json, os, time, pathlib, subprocess, base64, urllib.parse, sys
from datetime import datetime
import pymysql

# ───────── PATHS ─────────
WATCH_DIR   = pathlib.Path("/var/www/core-vpn.click/py/input")
DONE_DIR    = pathlib.Path("/var/www/core-vpn.click/py/done")
ERROR_DIR   = pathlib.Path("/var/www/core-vpn.click/py/errors")
TARGET_ROOT = pathlib.Path("/home/claude2/uvid")

# ───────── FFMPEG ─────────
FFMPEG_BIN       = "/usr/bin/ffmpeg"
SEGMENT_DURATION = 2          # seconds per HLS chunk (short = fast start)
SKIP_IF_MP4      = True       # skip download if MP4 already exists

# ───────── DEFAULTS ─────────
DEFAULT_USER = "ai_porn"
DEFAULT_TAGS = ["AI Porn", "NSFW", "AI Generated", "Adult"]

# ───────── DB ─────────
DB_HOST = "127.0.0.1"
DB_PORT = 3306
DB_USER = "pma"
DB_PASS = os.environ.get("FAPAIO_DB_PASSWORD", "")
DB_NAME = "fapaio"

# ───────── HTTP HEADERS (for CDN download) ─────────
UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
      "AppleWebKit/537.36 (KHTML, like Gecko) "
      "Chrome/124.0.0.0 Safari/537.36")

HEADERS_TEMPLATE = [
    "accept: */*",
    "accept-language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
    "origin: https://xikfap.com",
    "priority: u=1, i",
    "referer: {REFERER}",
    'sec-ch-ua: "Chromium";v="124", "Google Chrome";v="124", "Not_A Brand";v="99"',
    "sec-ch-ua-mobile: ?0",
    'sec-ch-ua-platform: "Linux"',
    "sec-fetch-dest: empty",
    "sec-fetch-mode: cors",
    "sec-fetch-site: cross-site",
    f"user-agent: {UA}",
]


# ═══════════════════════════════ HELPERS ════════════════════════════════

def build_headers(referer: str) -> str:
    lines = []
    for t in HEADERS_TEMPLATE:
        line = t.replace("{REFERER}", referer).strip().replace("'", "")
        if line:
            lines.append(line + "\r\n")
    return "".join(lines)


def run_cmd(cmd: list) -> bool:
    print("    $", " ".join(str(x) for x in cmd[:6]) + (" ..." if len(cmd) > 6 else ""))
    try:
        res = subprocess.run(cmd, check=True, stdout=subprocess.PIPE,
                             stderr=subprocess.STDOUT, text=True)
        return True
    except subprocess.CalledProcessError as e:
        out = (e.output or "")[-600:]
        print(f"    ✗ exit {e.returncode}: {out}")
        return False


def decode_token(stream_url: str):
    """Extract token expiry from ?p= param (base64 JSON with 'e' field)."""
    try:
        qs    = urllib.parse.parse_qs(urllib.parse.urlparse(stream_url).query)
        token = qs.get("p", [None])[0]
        if not token:
            return None
        pad  = "=" * (-len(token) % 4)
        data = json.loads(base64.urlsafe_b64decode(token + pad))
        e    = data.get("e")
        return datetime.fromtimestamp(float(e)) if isinstance(e, (int, float)) else None
    except Exception:
        return None


# ═══════════════════════════════ DB ════════════════════════════════

def db_import(vid_id: int, username: str, page: str, stream: str,
              tags: list, m3u8: str, poster: str):
    try:
        conn = pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER,
                               password=DB_PASS, database=DB_NAME,
                               charset="utf8mb4", autocommit=True)
        cur = conn.cursor()

        # users
        user_id = None
        if username:
            cur.execute("INSERT IGNORE INTO users (username) VALUES (%s)", (username,))
            cur.execute("SELECT id FROM users WHERE username=%s LIMIT 1", (username,))
            row = cur.fetchone()
            if row:
                user_id = row[0]

        # videos
        cur.execute("""
            INSERT INTO videos
              (external_id, user_id, username, page, stream, folder_id, poster_path, m3u8_path)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE
              user_id=VALUES(user_id), username=VALUES(username),
              page=VALUES(page), stream=VALUES(stream),
              poster_path=VALUES(poster_path), m3u8_path=VALUES(m3u8_path),
              updated_at=NOW()
        """, (vid_id, user_id, username, page, stream, vid_id, poster, m3u8))

        cur.execute("SELECT id FROM videos WHERE external_id=%s LIMIT 1", (vid_id,))
        row = cur.fetchone()
        if not row:
            conn.close(); return

        vid_db = row[0]
        for tag in tags:
            tag = tag.strip()
            if not tag: continue
            slug = tag.lower().replace(" ", "-")
            cur.execute("INSERT IGNORE INTO tags (name,slug) VALUES (%s,%s)", (tag, slug))
            cur.execute("SELECT id FROM tags WHERE name=%s LIMIT 1", (tag,))
            t = cur.fetchone()
            if t:
                cur.execute("INSERT IGNORE INTO video_tags (video_id,tag_id) VALUES (%s,%s)",
                            (vid_db, t[0]))

        conn.close()
        print(f"    ✓ DB: id={vid_id} user_id={user_id} tags={tags}")
    except Exception as e:
        print(f"    ✗ DB error: {e}")


# ═══════════════════════════════ CORE ════════════════════════════════

def process(src_json: pathlib.Path):
    print(f"\n{'='*60}")
    print(f"JSON: {src_json.name}")

    try:
        data = json.loads(src_json.read_text(encoding="utf-8"))
    except Exception as e:
        print(f"  Cannot read JSON: {e}"); return False

    vid      = str(data.get("id") or src_json.stem)
    stream   = (data.get("stream") or "").strip()
    page     = (data.get("page") or f"https://xikfap.com/video/{vid}").strip()
    username = (data.get("user") or DEFAULT_USER).strip()
    tags     = data.get("tags") or DEFAULT_TAGS

    if not stream:
        print("  No stream URL"); return False

    # Token expiry check
    exp = decode_token(stream)
    if exp:
        delta = (exp - datetime.now()).total_seconds()
        mark  = "⚠ EXPIRED" if delta < 0 else f"valid {int(delta)}s"
        print(f"  Token: {exp}  [{mark}]")
        if delta < -60:
            print("  Token expired >60s ago, skipping")
            return False

    vid_dir     = TARGET_ROOT / vid
    mp4_path    = vid_dir / f"{vid}.mp4"
    m3u8_path   = vid_dir / "index.m3u8"
    seg_pattern = str(vid_dir / "chunk_%05d.ts")
    poster_path = vid_dir / "poster.jpg"

    vid_dir.mkdir(parents=True, exist_ok=True)

    # Write JSON with full metadata
    (vid_dir / f"{vid}.json").write_text(
        json.dumps({"id": int(vid) if vid.isdigit() else vid,
                    "user": username, "page": page,
                    "stream": stream, "tags": tags},
                   ensure_ascii=False, indent=2),
        encoding="utf-8"
    )

    # ── Download MP4 ──
    if SKIP_IF_MP4 and mp4_path.exists() and mp4_path.stat().st_size > 1024:
        print(f"  MP4 exists ({mp4_path.stat().st_size//1024} KB), skip download")
    else:
        print("  Downloading MP4...")
        ok = run_cmd([
            FFMPEG_BIN, "-y", "-loglevel", "warning",
            "-user_agent", UA,
            "-headers", build_headers(page),
            "-i", stream,
            "-c", "copy",
            str(mp4_path),
        ])
        if not ok or not mp4_path.exists() or mp4_path.stat().st_size < 1024:
            print("  ✗ Download failed"); return False
        print(f"  MP4: {mp4_path.stat().st_size//1024} KB")

    # ── HLS (stream copy first, fallback re-encode) ──
    print("  Making HLS (stream copy)...")
    ok_hls = run_cmd([
        FFMPEG_BIN, "-y", "-loglevel", "warning",
        "-i", str(mp4_path),
        "-c", "copy", "-copyts", "-muxdelay", "0", "-muxpreload", "0",
        "-f", "hls", "-hls_time", str(SEGMENT_DURATION),
        "-hls_playlist_type", "vod",
        "-hls_segment_filename", seg_pattern,
        str(m3u8_path),
    ])
    if not ok_hls:
        print("  Stream copy failed → re-encoding...")
        ok_hls = run_cmd([
            FFMPEG_BIN, "-y", "-loglevel", "warning",
            "-i", str(mp4_path),
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "23",
            "-c:a", "aac", "-b:a", "128k", "-ac", "2",
            "-f", "hls", "-hls_time", str(SEGMENT_DURATION),
            "-hls_playlist_type", "vod",
            "-hls_segment_filename", seg_pattern,
            str(m3u8_path),
        ])

    if not ok_hls:
        print("  ✗ HLS failed"); return False

    # ── Poster ──
    print("  Extracting poster...")
    run_cmd([
        FFMPEG_BIN, "-y", "-loglevel", "warning",
        "-i", str(mp4_path),
        "-vf", r"select=eq(n\,0)", "-vframes", "1", "-q:v", "2",
        str(poster_path),
    ])

    # ── DB import ──
    db_import(
        int(vid) if vid.isdigit() else 0,
        username, page, stream, tags,
        str(m3u8_path),
        str(poster_path) if poster_path.exists() else ""
    )

    chunks = len(list(vid_dir.glob("chunk_*.ts")))
    print(f"  ✓ Done: {chunks} HLS chunks, poster={'yes' if poster_path.exists() else 'no'}")
    return True


def main():
    for d in (WATCH_DIR, DONE_DIR, ERROR_DIR, TARGET_ROOT):
        d.mkdir(parents=True, exist_ok=True)

    print("=" * 60)
    print("watch_hls_json  (server mode)")
    print(f"  Watch : {WATCH_DIR}")
    print(f"  Output: {TARGET_ROOT}")
    print(f"  DB    : {DB_HOST}:{DB_PORT}/{DB_NAME}")
    print("=" * 60)
    print("Waiting for .json files in input/ ...")

    processed: set = set()

    while True:
        try:
            for jf in sorted(WATCH_DIR.glob("*.json")):
                if jf.name in processed:
                    continue
                processed.add(jf.name)
                ok = process(jf)
                dest = DONE_DIR if ok else ERROR_DIR
                try:
                    jf.replace(dest / jf.name)
                except Exception:
                    pass
        except KeyboardInterrupt:
            print("\nStopped.")
            break
        except Exception as e:
            print(f"Loop error: {e}")

        time.sleep(3)


if __name__ == "__main__":
    main()
