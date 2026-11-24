#!/usr/bin/env python3
"""
mp4_to_dir.py — Server version (Linux/Docker)
=============================================
Batch ingest: scans a source directory for {ID}.mp4 files,
converts each to HLS (index.m3u8 + chunks + poster.jpg),
writes {ID}.json, imports to DB.

Already-imported videos are skipped (checks both DB and HLS existence).

Usage:
    python3 mp4_to_dir.py [source_dir] [options]

    source_dir          Directory with flat {ID}.mp4 files
                        Default: /home/corevpn/web/core-vpn.click/public_html/v1

    --tags "t1,t2,t3"   Comma-separated tags (default: AI Porn,NSFW,AI Generated,Adult)
    --user USERNAME     Author username (default: ai_porn)
    --force             Re-process even if already in DB

Examples:
    python3 mp4_to_dir.py
    python3 mp4_to_dir.py /data/new_batch --tags "Hentai,AI" --user hentai_ai
    python3 mp4_to_dir.py /data/batch2 --force
"""

"""Legacy converter snapshot: DB port and target path need review."""
import argparse, json, os, pathlib, subprocess, sys
import pymysql

# ───────── PATHS ─────────
TARGET_ROOT = pathlib.Path("/home/corevpn/web/core-vpn.click/uvid")
FFMPEG_BIN  = "/usr/bin/ffmpeg"
SEGMENT_DURATION = 6   # seconds (longer = fewer files, fine for VOD)

# ───────── DEFAULTS ─────────
DEFAULT_SOURCE = pathlib.Path("/home/corevpn/web/core-vpn.click/public_html/v1")
DEFAULT_USER   = "ai_porn"
DEFAULT_TAGS   = ["AI Porn", "NSFW", "AI Generated", "Adult"]

# ───────── DB ─────────
DB_HOST = "127.0.0.1"
DB_PORT = 3307
DB_USER = "pma"
DB_PASS = os.environ.get("FAPAIO_DB_PASSWORD", "")
DB_NAME = "fapaio"


# ═══════════════════════════════ HELPERS ════════════════════════════════

def run_cmd(cmd: list):
    """Run command, return (success, output)."""
    try:
        res = subprocess.run(
            cmd, check=True,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True
        )
        return True, res.stdout
    except subprocess.CalledProcessError as e:
        return False, (e.output or "")[-500:]


# ═══════════════════════════════ DB ════════════════════════════════

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


def db_import(conn, vid_id: int, username: str, page: str, stream: str,
              tags: list, m3u8: str, poster: str):
    cur = conn.cursor()

    # users
    user_id = None
    if username:
        cur.execute("INSERT IGNORE INTO users (username) VALUES (%s)", (username,))
        cur.execute("SELECT id FROM users WHERE username=%s LIMIT 1", (username,))
        row = cur.fetchone()
        if row:
            user_id = row[0]

    # videos upsert
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
        return
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


# ═══════════════════════════════ CORE ════════════════════════════════

def process_one(mp4_path: pathlib.Path, username: str, tags: list,
                conn, existing_ids: set, force: bool = False) -> str:
    """
    Returns: 'done' | 'skip' | 'fail'
    """
    stem = mp4_path.stem
    if not stem.isdigit():
        return "skip"

    vid_id  = int(stem)
    vid_dir = TARGET_ROOT / stem

    # Skip check
    if not force and vid_id in existing_ids and (vid_dir / "index.m3u8").exists():
        return "skip"

    vid_dir.mkdir(parents=True, exist_ok=True)

    # Read JSON from source dir if present (overrides defaults)
    src_json = mp4_path.parent / f"{stem}.json"
    page = stream = ""
    if src_json.exists():
        try:
            jd       = json.loads(src_json.read_text("utf-8"))
            username = jd.get("user") or username
            tags     = jd.get("tags") or tags
            page     = jd.get("page") or ""
            stream   = jd.get("stream") or ""
        except Exception:
            pass

    print(f"\n  [{stem}] {mp4_path.name}  ({mp4_path.stat().st_size//1024} KB)")

    m3u8_path   = vid_dir / "index.m3u8"
    seg_pattern = str(vid_dir / "chunk_%05d.ts")
    poster_path = vid_dir / "poster.jpg"

    # ── HLS (try stream copy first) ──
    ok, out = run_cmd([
        FFMPEG_BIN, "-y", "-loglevel", "warning",
        "-i", str(mp4_path),
        "-c", "copy", "-copyts", "-muxdelay", "0", "-muxpreload", "0",
        "-f", "hls", "-hls_time", str(SEGMENT_DURATION),
        "-hls_playlist_type", "vod",
        "-hls_segment_filename", seg_pattern,
        str(m3u8_path),
    ])
    if not ok:
        print(f"  [{stem}] copy failed → re-encoding")
        ok, out = run_cmd([
            FFMPEG_BIN, "-y", "-loglevel", "warning",
            "-i", str(mp4_path),
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "23",
            "-c:a", "aac", "-b:a", "128k", "-ac", "2",
            "-f", "hls", "-hls_time", str(SEGMENT_DURATION),
            "-hls_playlist_type", "vod",
            "-hls_segment_filename", seg_pattern,
            str(m3u8_path),
        ])

    if not ok or not m3u8_path.exists():
        print(f"  [{stem}] ✗ HLS failed")
        return "fail"

    # ── Poster ──
    run_cmd([
        FFMPEG_BIN, "-y", "-loglevel", "warning",
        "-i", str(mp4_path),
        "-vf", r"select=eq(n\,0)", "-vframes", "1", "-q:v", "2",
        str(poster_path),
    ])

    # ── JSON ──
    json_out = {
        "id": vid_id, "user": username,
        "page": page, "stream": stream, "tags": tags,
    }
    (vid_dir / f"{stem}.json").write_text(
        json.dumps(json_out, ensure_ascii=False, indent=2), encoding="utf-8"
    )

    # ── DB ──
    db_import(conn, vid_id, username, page, stream, tags,
              str(m3u8_path),
              str(poster_path) if poster_path.exists() else "")

    existing_ids.add(vid_id)
    chunks = len(list(vid_dir.glob("chunk_*.ts")))
    print(f"  [{stem}] ✓  {chunks} chunks, poster={'✓' if poster_path.exists() else '✗'}")
    return "done"


def main():
    parser = argparse.ArgumentParser(
        description="Batch MP4 → HLS ingest into uvid/",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )
    parser.add_argument("source_dir", nargs="?", default=str(DEFAULT_SOURCE),
                        help=f"Source dir with {{ID}}.mp4 files (default: {DEFAULT_SOURCE})")
    parser.add_argument("--tags", default=",".join(DEFAULT_TAGS),
                        help="Comma-separated tags")
    parser.add_argument("--user", default=DEFAULT_USER,
                        help=f"Author username (default: {DEFAULT_USER})")
    parser.add_argument("--force", action="store_true",
                        help="Re-process even if already in DB")
    args = parser.parse_args()

    src    = pathlib.Path(args.source_dir)
    tags   = [t.strip() for t in args.tags.split(",") if t.strip()]
    user   = args.user

    if not src.exists():
        print(f"ERROR: source_dir not found: {src}")
        sys.exit(1)

    mp4s = sorted(
        (p for p in src.glob("*.mp4") if p.stem.isdigit()),
        key=lambda p: int(p.stem)
    )
    if not mp4s:
        print(f"No numeric-ID .mp4 files found in {src}")
        sys.exit(0)

    print("=" * 60)
    print("mp4_to_dir  (server mode)")
    print(f"  Source : {src}  ({len(mp4s)} MP4 files)")
    print(f"  Target : {TARGET_ROOT}")
    print(f"  User   : {user}")
    print(f"  Tags   : {tags}")
    print(f"  Force  : {args.force}")
    print("=" * 60)

    conn = db_connect()
    existing = db_get_existing(conn)
    print(f"  Already in DB: {len(existing)} videos")

    done = skip = fail = 0
    for mp4 in mp4s:
        status = process_one(mp4, user, tags, conn, existing, args.force)
        if   status == "done": done += 1
        elif status == "skip": skip += 1
        else:                  fail += 1

    conn.close()
    print(f"\n{'='*60}")
    print(f"Done: {done} converted, {skip} skipped, {fail} failed  |  total {len(mp4s)}")


if __name__ == "__main__":
    main()
