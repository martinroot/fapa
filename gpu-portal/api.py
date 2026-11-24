#!/usr/bin/env python3
"""
GPU Templates Portal V2 — API + Worker Manager + Auth + xAI Fallback
Port 8899

Cleaned-up version: removed Easy Mode, AutoCreator, Vast Debug, Jobs/Test API.
Added: xAI fallback, generation history, smart routing.

- Bootstrap sidebar portal with auth
- Dashboard with GPU metrics
- Template CRUD with weights management
- Debug pages
- 1 machine, 5min idle timeout
"""

import asyncio
import copy
import hashlib
import traceback
import json
import shutil
import os
import re
import subprocess
from datetime import datetime
import random
import secrets
import socket
import time
import uuid
import base64
from pathlib import Path
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional
from urllib.parse import urlencode, quote

from PIL import Image as PILImage

import aiohttp
from aiohttp import web
try:
    import aiomysql
    _MYSQL_AVAILABLE = True
except ImportError:
    _MYSQL_AVAILABLE = False

# ──────────── Config ────────────
VAST_API_KEY = os.getenv("VAST_API_KEY", "")
VAST_TEMPLATE_HASH_ID = os.getenv("VAST_TEMPLATE_HASH_ID", "e6c5ad164d71fe4740db66fa7eccd48e")
VAST_DISK_GB = float(os.getenv("VAST_DISK_GB", "120"))
# GPU types to search — all searched, sorted by price (cheapest first)
VAST_GPU_PRIORITY = ["H200 NVL", "H200", "B200", "H100"]
VAST_GPU_NAME = os.getenv("VAST_GPU_NAME", "H200")  # legacy single-gpu fallback
VAST_MAX_DPH_TOTAL = float(os.getenv("VAST_MAX_DPH_TOTAL", "5.0"))
VAST_MIN_RELIABILITY = float(os.getenv("VAST_MIN_RELIABILITY", "0.90"))
VAST_VERIFIED_ONLY = False
VAST_EXTERNAL_API_PORT = 8288
VAST_EXTERNAL_COMFY_PORT = 8188
OFFER_LIMIT = 50

CONSOLE_BASE = "https://console.vast.ai"
INSTANCE_LABEL_PREFIX = "gpu-portal-test"

HEALTH_PATH = "/health"
GENERATE_SYNC_PATH = "/generate/sync"
HEALTH_MAX_WAIT_SEC = 2400  # 40 min — slow machines need 20+ min provision + model load
INSTANCE_MAX_WAIT_SEC = 1200  # 20 min — some machines slow to appear in API
STUCK_INSTANCE_TIMEOUT_SEC = 1500  # 25 min total — auto-destroy if still in "starting"
REQUEST_TIMEOUT_SEC = 14400
IDLE_TIMEOUT_SEC = 2700  # 45 min idle → auto-stop

GITHUB_REPO = "martinroot/vastai_wan22"
GITHUB_URL = f"https://github.com/{GITHUB_REPO}"

PORTAL_DIR = Path(__file__).parent
UPLOADS_DIR = PORTAL_DIR / "uploads"
RESULTS_DIR = PORTAL_DIR / "results"
WORKFLOWS_DIR = PORTAL_DIR / "workflows"
TEMPLATES_FILE = PORTAL_DIR / "templates.json"
METRICS_FILE = PORTAL_DIR / "metrics.json"
EVENT_LOG_FILE = PORTAL_DIR / "events.jsonl"
MACHINE_BLACKLIST_FILE = PORTAL_DIR / "machine_blacklist.json"
UPLOADS_DIR.mkdir(exist_ok=True)
RESULTS_DIR.mkdir(exist_ok=True)
WORKFLOWS_DIR.mkdir(exist_ok=True)

# ──────────── Auth ────────────
PORTAL_USER = os.getenv("PORTAL_USER", "admin")
PORTAL_PASS = os.getenv("PORTAL_PASS", "")
AUTH_SESSIONS: Dict[str, float] = {}
SESSION_TTL = 86400  # 24h
SESSIONS_FILE = PORTAL_DIR / "sessions.json"

def _load_sessions():
    try:
        if SESSIONS_FILE.exists():
            data = json.loads(SESSIONS_FILE.read_text())
            now = time.time()
            return {k: v for k, v in data.items() if now - v < SESSION_TTL}
    except Exception:
        pass
    return {}

def _save_sessions():
    try:
        SESSIONS_FILE.write_text(json.dumps(AUTH_SESSIONS))
    except Exception:
        pass

AUTH_SESSIONS.update(_load_sessions())

# ──────────── Provision timing log ────────────
# Stores per-instance boot timing from provisioning script callbacks
PROVISION_LOGS: Dict[str, List[Dict]] = {}  # instance_id → [{step, elapsed, detail, ts}]

# ──────────── xAI Fallback Config ────────────
XAI_API_KEY_V2 = os.getenv("XAI_API_KEY", "")
os.environ["XAI_API_KEY"] = XAI_API_KEY_V2  # Ensure SDK can find the key
XAI_IMAGE_MODEL = os.getenv("XAI_IMAGE_MODEL", "grok-imagine-image")
XAI_VIDEO_MODEL = os.getenv("XAI_VIDEO_MODEL", "grok-imagine-video")
XAI_DOWNLOAD_TIMEOUT = 300

# ──────────── HotAPI Config ────────────
HOTAPI_KEY = os.getenv("HOTAPI_KEY", "")
HOTAPI_BASE = "https://api.hotapi.ai"
HOTAPI_IMG_MODEL    = os.getenv("HOTAPI_IMG_MODEL",    "qwen-image-edit-spicy")
HOTAPI_IMG2_MODEL   = os.getenv("HOTAPI_IMG2_MODEL",   "seedream-5.0-lite-spicy/image-edit")
HOTAPI_IMG_T2I_MODEL= os.getenv("HOTAPI_IMG_T2I_MODEL","z-image-spicy")
HOTAPI_VID_MODEL    = os.getenv("HOTAPI_VID_MODEL",    "wan-2.7-spicy")
HOTAPI_POLL_INTERVAL = int(os.getenv("HOTAPI_POLL_INTERVAL", "3"))
HOTAPI_UA = os.getenv("HOTAPI_UA", "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36")
HOTAPI_TASK_TIMEOUT  = int(os.getenv("HOTAPI_TASK_TIMEOUT",  "600"))
PORTAL_PUBLIC_URL    = os.getenv("PORTAL_PUBLIC_URL", "https://hotbabestube.ru")
# Admin panel of THIS portal (aiohttp :8899). Used only in Telegram notifications.
ADMIN_PUBLIC_URL     = os.getenv("ADMIN_PUBLIC_URL", "http://169.58.162.156:8899")
# Public base for source images handed to HotAPI (their side downloads them).
# Must be a host THIS server actually serves (HotAPI downloads sources from it).
HOTAPI_SOURCE_BASE   = os.getenv("HOTAPI_SOURCE_BASE", PORTAL_PUBLIC_URL)

# Видео-генерации отключены: дороже и дольше всего остального.
# Вернуть = True (или переменная окружения VIDEO_ENABLED=1).
VIDEO_ENABLED = os.getenv("VIDEO_ENABLED", "0") == "1"
GROK_ENABLED = os.getenv("GROK_ENABLED", "1") == "1"  # Grok fallback ON by default
GPU_BOOT_ENABLED = os.getenv("GPU_BOOT_ENABLED", "0") == "1"  # GPU boot OFF by default
XAI_ETA_THRESHOLD_SEC = 120  # fallback to xAI if GPU ETA > 2 min
XAI_STALL_EXTRA_IMG = 15     # extra stall seconds for img2img/dual (gen + 15s)
XAI_STALL_EXTRA_VID = 40     # extra stall seconds for img2video (gen + 40s)
XAI_STALL_INTERVAL_SEC = 10  # interval between GPU checks while stalling

# ──────────── Telegram Notifications (GPU events from API) ────────────
TG_NOTIFY_BOT_TOKEN = os.getenv("TG_NOTIFY_BOT_TOKEN", "")
TG_NOTIFY_CHAT_ID = os.getenv("TG_NOTIFY_CHAT_ID", "-1003717235096")

# GPU Portal owner notifications
_TG_GPU_BOT = os.getenv("TG_GPU_BOT_TOKEN", "")
_TG_GPU_CHAT = "-1003717235096"

async def _tg_gpu_notify(prompt: str, model_id: str, img_urls: list,
                          result_path, media_type: str, elapsed: float, cost_usd: float = 0.0,
                          client_ip: str = "", client_uiq: str = "", template_id: str = "",
                          client_click_id: str = "", age_detected: int = 0, error: str = ""):
    try:
        icon = "❌" if error else "🎨"
        lines = [f"{icon} GPU Portal — Генерация", ""]
        lines.append(f"🤖 {model_id}")
        if template_id:
            lines.append(f"📋 {template_id}")
        if prompt:
            lines.append(f"📝 {prompt[:300]}")
        lines.append(f"⏱ {elapsed:.1f} сек")
        if cost_usd:
            lines.append(f"💰 ${cost_usd:.4f}")
        if client_ip:
            real_ip = client_ip.split(",")[0].strip()
            geo = _geoip_lookup(real_ip)
            cc = geo.get("country_code", "")
            flag = _flag_emoji(cc) if cc else "🌐"
            country = geo.get("country_name", "")
            geo_str = f" {flag} {country}" if country else (f" {flag}" if cc else "")
            lines.append(f"👤 {real_ip}{geo_str}")
        if client_click_id:
            lines.append(f"🔗 cid={client_click_id}")
        if client_uiq:
            lines.append(f"🔑 uiq={client_uiq[:16]}")
        if age_detected > 0:
            lines.append(f"👶 Age: {age_detected}")
        if error:
            lines.append(f"⚠️ {error[:300]}")
        caption = "\n".join(lines)

        base = f"https://api.telegram.org/bot{_TG_GPU_BOT}"
        p = Path(result_path) if result_path else None
        p_exists = p and p.exists()

        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=60)) as sess:
            # Build media group items: source URLs + result file
            media_items = []
            for url in img_urls[:4]:
                media_items.append({"type": "photo", "media": url})

            if p_exists and media_type != "audio":
                mt = "video" if media_type == "video" else "photo"
                media_items.append({"type": mt, "media": "attach://result_file"})

            # Caption on first item
            if media_items:
                media_items[0]["caption"] = caption

            if not media_items:
                await sess.post(f"{base}/sendMessage",
                                json={"chat_id": _TG_GPU_CHAT, "text": caption})
            elif len(media_items) == 1:
                item = media_items[0]
                if item["media"] == "attach://result_file":
                    form = aiohttp.FormData()
                    form.add_field("chat_id", _TG_GPU_CHAT)
                    form.add_field("caption", caption)
                    if media_type == "video":
                        form.add_field("video", open(str(p), "rb"), filename=p.name, content_type="video/mp4")
                        await sess.post(f"{base}/sendVideo", data=form)
                    else:
                        ct = "image/png" if p.suffix.lower() == ".png" else "image/jpeg"
                        form.add_field("photo", open(str(p), "rb"), filename=p.name, content_type=ct)
                        await sess.post(f"{base}/sendPhoto", data=form)
                else:
                    await sess.post(f"{base}/sendPhoto",
                                    json={"chat_id": _TG_GPU_CHAT, "photo": item["media"], "caption": caption})
            else:
                form = aiohttp.FormData()
                form.add_field("chat_id", _TG_GPU_CHAT)
                form.add_field("media", json.dumps(media_items))
                if p_exists and media_type != "audio":
                    ct = "video/mp4" if media_type == "video" else (
                        "image/png" if p.suffix.lower() == ".png" else "image/jpeg")
                    form.add_field("result_file", open(str(p), "rb"), filename=p.name, content_type=ct)
                await sess.post(f"{base}/sendMediaGroup", data=form)

            # Audio always separate (can't be in media group)
            if media_type == "audio" and p_exists:
                af = aiohttp.FormData()
                af.add_field("chat_id", _TG_GPU_CHAT)
                af.add_field("caption", caption)
                af.add_field("audio", open(str(p), "rb"), filename=p.name, content_type="audio/mpeg")
                await sess.post(f"{base}/sendAudio", data=af)

    except Exception as exc:
        debug_log.warn("tg_gpu", f"notify failed: {exc}")

# Cooldown: IP → last session notification timestamp (avoid spam)
_session_notify_cooldown: Dict[str, float] = {}
_SESSION_NOTIFY_COOLDOWN_SEC = 1800  # 30 min

async def tg_notify(text: str, files: list = None):
    """Send notification to Telegram. Supports text + optional photo/video files."""
    debug_log.info("tg", text)
    if not TG_NOTIFY_BOT_TOKEN:
        return
    try:
        base = f"https://api.telegram.org/bot{TG_NOTIFY_BOT_TOKEN}"
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=30)) as sess:
            # Send text
            await sess.post(f"{base}/sendMessage", json={"chat_id": TG_NOTIFY_CHAT_ID, "text": text})
            # Send files if any
            if files:
                for fpath in files:
                    p = Path(fpath)
                    if not p.exists():
                        continue
                    data = aiohttp.FormData()
                    data.add_field("chat_id", TG_NOTIFY_CHAT_ID)
                    if p.suffix.lower() in (".mp4", ".mov", ".webm"):
                        data.add_field("video", open(fpath, "rb"), filename=p.name)
                        await sess.post(f"{base}/sendVideo", data=data)
                    else:
                        data.add_field("photo", open(fpath, "rb"), filename=p.name)
                        await sess.post(f"{base}/sendPhoto", data=data)
    except Exception as e:
        debug_log.warn("tg", f"Notify failed: {e}")


async def tg_notify_result(job: Dict, source_files: list, result_files: list, method: str = "hotapi"):
    """Send ONE Telegram message: before/after photos + job summary."""
    if not TG_NOTIFY_BOT_TOKEN:
        return
    try:
        status = job.get("status", "")
        sc_type = job.get("type", "video")
        template_id = job.get("preset_id") or job.get("template_id") or "—"
        gen_sec = job.get("generation_sec")
        total_sec = job.get("total_sec")
        cost = job.get("cost_usd")
        error = job.get("error")
        gpu_name = job.get("gpu") or ""
        uiq = job.get("_uiq", "") or ""

        sc_item = next((s for s in showcase if s.get("id") == template_id), None)
        if not sc_item:
            sc_item = next((s for s in dual_showcase if s.get("id") == template_id), None)
        tpl_name = (sc_item["name"] if sc_item else template_id) or "—"
        method_str = f"🖥 GPU ({gpu_name})" if method == "gpu" else "🤖 HotAPI"
        icon = "✅" if status == "done" else "❌"
        source_label = "portal"

        lines = [f"{icon} {tpl_name} [{source_label}]", f"📋 {template_id}", f"⚙️ {method_str}"]

        elapsed_str = f"{gen_sec}s" if gen_sec else "—"
        if gen_sec and total_sec and total_sec != gen_sec:
            elapsed_str += f" (total {total_sec}s)"
        lines.append(f"⏱ {elapsed_str}")
        lines.append(f"💰 ${cost:.3f}" if cost else "💰 —")

        user_ip = job.get("_ip", "") or ""
        real_ip = user_ip.split(",")[0].strip() if user_ip else ""
        if real_ip:
            geo = _geoip_lookup(real_ip)
            cc = geo.get("country_code", "")
            flag = _flag_emoji(cc) if cc else "🌐"
            country = geo.get("country_name", "")
            geo_str = f" {flag} {country}" if country else (f" {flag}" if cc else "")
            lines.append(f"👤 {real_ip}{geo_str}")
        else:
            lines.append("👤 —")
        lines.append(f"🔑 uiq={uiq[:16]}" if uiq else "🔑 uiq=—")
        lines.append(f"🔗 {ADMIN_PUBLIC_URL}/admin#catalog/{template_id}" if template_id and template_id != "—" else "🔗 —")
        if error:
            lines.append(f"💬 {str(error)[:300]}")

        caption = "\n".join(lines)

        # Collect valid files: source first, then result (before/after)
        all_files = []
        for fp in (source_files + result_files):
            if fp:
                p = Path(fp) if not isinstance(fp, Path) else fp
                if p.exists():
                    all_files.append(p)

        base = f"https://api.telegram.org/bot{TG_NOTIFY_BOT_TOKEN}"
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=60)) as sess:
            if not all_files:
                await sess.post(f"{base}/sendMessage", json={"chat_id": TG_NOTIFY_CHAT_ID, "text": caption})
                return

            # Send everything in ONE media group (photos + videos mixed, up to 10)
            video_exts = {".mp4", ".mov", ".webm"}
            if len(all_files) == 1 and all_files[0].suffix.lower() not in video_exts:
                # Single image — sendPhoto
                form = aiohttp.FormData()
                form.add_field("chat_id", TG_NOTIFY_CHAT_ID)
                form.add_field("photo", open(str(all_files[0]), "rb"), filename=all_files[0].name)
                form.add_field("caption", caption)
                await sess.post(f"{base}/sendPhoto", data=form)
            elif len(all_files) == 1 and all_files[0].suffix.lower() in video_exts:
                # Single video — sendVideo with caption
                form = aiohttp.FormData()
                form.add_field("chat_id", TG_NOTIFY_CHAT_ID)
                form.add_field("video", open(str(all_files[0]), "rb"), filename=all_files[0].name)
                form.add_field("caption", caption)
                await sess.post(f"{base}/sendVideo", data=form)
            else:
                # Multiple files — sendMediaGroup with mixed photos+videos
                form = aiohttp.FormData()
                form.add_field("chat_id", TG_NOTIFY_CHAT_ID)
                media_json = []
                for i, fp in enumerate(all_files[:10]):
                    key = f"media_{i}"
                    mtype = "video" if fp.suffix.lower() in video_exts else "photo"
                    form.add_field(key, open(str(fp), "rb"), filename=fp.name)
                    entry = {"type": mtype, "media": f"attach://{key}"}
                    if i == 0:
                        entry["caption"] = caption
                    media_json.append(entry)
                form.add_field("media", json.dumps(media_json))
                await sess.post(f"{base}/sendMediaGroup", data=form)

    except Exception as e:
        debug_log.warn("tg", f"tg_notify_result failed: {e}")
        # Fallback to plain text
        try:
            await tg_notify(f"{icon if 'icon' in dir() else '?'} Генерация #{gen_num if 'gen_num' in dir() else '?'} завершена ({method})")
        except Exception:
            pass


async def _mysql_upsert_user(ip: str, ua: str, geo: Dict):
    """Upsert a user record on session start (no generation increment)."""
    if not _MYSQL_AVAILABLE or not ip:
        return
    pool = await _get_mysql_pool()
    if not pool:
        return
    try:
        cc = (geo.get("country_code") or "")[:2] or None
        cn = (geo.get("country_name") or "")[:64] or None
        isp = (geo.get("isp") or "")[:128] or None
        now_dt = datetime.now()
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("""
                    INSERT INTO users (real_ip, country_code, country_name, isp, user_agent, first_seen, last_seen, total_generations)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, 0)
                    ON DUPLICATE KEY UPDATE
                        last_seen = VALUES(last_seen),
                        country_code = COALESCE(NULLIF(VALUES(country_code), ''), country_code),
                        country_name = COALESCE(NULLIF(VALUES(country_name), ''), country_name),
                        isp = COALESCE(NULLIF(VALUES(isp), ''), isp)
                """, (ip[:64], cc, cn, isp, (ua or "")[:512] or None, now_dt, now_dt))
    except Exception as e:
        print(f"[MySQL] upsert_user error: {e}")


async def tg_notify_new_session(ip: str, ua: str):
    """Send Telegram alert when new iOS session detected (catalog download). Cooldown per IP."""
    now = time.time()
    last = _session_notify_cooldown.get(ip, 0)
    if now - last < _SESSION_NOTIFY_COOLDOWN_SEC:
        return
    _session_notify_cooldown[ip] = now
    geo = _geoip_lookup(ip)
    # Upsert user record on session start
    asyncio.ensure_future(_mysql_upsert_user(ip, ua, geo))
    cc = geo.get("country_code", "")
    flag = _flag_emoji(cc)
    country = geo.get("country_name", "") or "—"
    isp = geo.get("isp", "")
    ts_str = datetime.now().strftime("%H:%M %d.%m")
    user_url = f"{ADMIN_PUBLIC_URL}/admin#users/{quote(ip, safe='')}"
    lines = [
        f"📲 Новая установка",
        f"IP: {ip}",
        f"Страна: {flag} {country}" + (f" / {isp}" if isp else ""),
        f"Время: {ts_str}",
        f"👤 {user_url}",
    ]
    asyncio.ensure_future(tg_notify("\n".join(lines)))

# ──────────── Generation History ────────────
generation_history: List[Dict] = []


# ──────────── xAI Helper Functions (from anna_bot.py, Telegram deps removed) ────────────

def image_to_data_uri(path: Path) -> str:
    suffix = path.suffix.lower()
    mime = {"png": "image/png", "webp": "image/webp"}.get(suffix.lstrip("."), "image/jpeg")
    return f"data:{mime};base64,{base64.b64encode(path.read_bytes()).decode()}"


async def xai_download_bytes(url: str, timeout_s: int = XAI_DOWNLOAD_TIMEOUT) -> bytes:
    async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=timeout_s)) as sess:
        async with sess.get(url) as r:
            r.raise_for_status()
            return await r.read()


async def xai_1img_to_image(source_path: Path, out_path: Path, prompt: str, aspect_ratio: str = "9:16") -> tuple:
    """Image edit via xAI SDK — preserves source image identity."""
    import xai_sdk
    t0 = time.monotonic()
    try:
        client = xai_sdk.AsyncClient()
        resp = await client.image.sample(
            prompt=prompt,
            model=XAI_IMAGE_MODEL,
            image_url=image_to_data_uri(source_path),
            image_format="url",
            aspect_ratio=aspect_ratio,
        )
        url = str(getattr(resp, "url", "") or "")
    except Exception as sdk_err:
        debug_log.warn("xai", f"SDK failed ({sdk_err}), trying REST /v1/images/edits")
        # Fallback to REST API /v1/images/edits
        headers = {"Authorization": f"Bearer {XAI_API_KEY_V2}", "Content-Type": "application/json"}
        payload = {
            "model": XAI_IMAGE_MODEL,
            "prompt": prompt,
            "image": {"url": image_to_data_uri(source_path), "type": "image_url"},
            "aspect_ratio": aspect_ratio,
        }
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=300)) as sess:
            async with sess.post("https://api.x.ai/v1/images/edits", headers=headers, json=payload) as r:
                body = await r.text()
                if r.status >= 400: raise RuntimeError(f"xAI edit HTTP {r.status}: {body[:500]}")
                data = json.loads(body)
        url = ""
        if isinstance(data, dict):
            arr = data.get("data") or []
            if arr: url = str((arr[0] or {}).get("url") or "")
            if not url: url = str(data.get("url") or "")
    if not url: raise RuntimeError(f"xAI 1img->image: URL missing")
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_bytes(await xai_download_bytes(url))
    return time.monotonic() - t0, url


async def xai_2img_to_image(source_paths: List[Path], out_path: Path, prompt: str, aspect_ratio: str = "9:16") -> tuple:
    """Dual image edit via xAI SDK, fallback to REST."""
    if len(source_paths) < 2: raise RuntimeError("Need >= 2 images")
    debug_log.info("xai", f"2img: encoding {len(source_paths)} images...")
    image_urls = [image_to_data_uri(p) for p in source_paths]
    debug_log.info("xai", f"2img: encoded, uri_lens={[len(u) for u in image_urls]}, prompt={prompt[:80]}")
    t0 = time.monotonic()
    url = ""
    # Try SDK first (with 60s timeout)
    try:
        import xai_sdk
        client = xai_sdk.AsyncClient()
        debug_log.info("xai", "2img: SDK image.sample starting...")
        resp = await asyncio.wait_for(client.image.sample(
            prompt=prompt, model=XAI_IMAGE_MODEL,
            image_urls=image_urls, image_format="url",
            aspect_ratio=aspect_ratio,
        ), timeout=60)
        url = str(getattr(resp, "url", "") or "")
        debug_log.info("xai", f"2img: SDK done, url={'yes' if url else 'no'}")
    except asyncio.TimeoutError:
        debug_log.warn("xai", "2img SDK timeout (60s), trying REST")
    except Exception as sdk_err:
        debug_log.warn("xai", f"2img SDK failed ({sdk_err}), trying REST")
    # Fallback to REST
    if not url:
        headers = {"Authorization": f"Bearer {XAI_API_KEY_V2}", "Content-Type": "application/json"}
        payload = {"model": XAI_IMAGE_MODEL, "prompt": prompt, "images": [{"url": u, "type": "image_url"} for u in image_urls], "aspect_ratio": aspect_ratio}
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=300)) as sess:
            async with sess.post("https://api.x.ai/v1/images/edits", headers=headers, json=payload) as r:
                body = await r.text()
                if r.status >= 400: raise RuntimeError(f"xAI 2img edit HTTP {r.status}: {body[:500]}")
                data = json.loads(body)
        if isinstance(data, dict):
            url = str(data.get("url") or "")
            if not url:
                arr = data.get("data") or []
                if arr: url = str((arr[0] or {}).get("url") or "")
    if not url: raise RuntimeError("xAI 2img edit: URL missing")
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_bytes(await xai_download_bytes(url))
    return time.monotonic() - t0, url


async def xai_1img_to_video(source_path: Path, out_path: Path, prompt: str, duration: int = 6, resolution: str = "720p", aspect_ratio: str = "9:16") -> tuple:
    """Video generation via xAI SDK — uses source image as reference (first frame)."""
    import xai_sdk
    t0 = time.monotonic()
    src_uri = image_to_data_uri(source_path)
    debug_log.info("xai", f"Video: source={source_path.name} ({source_path.stat().st_size}b), uri_len={len(src_uri)}, prompt={prompt[:80]}")
    # Try SDK first
    try:
        client_async = xai_sdk.AsyncClient()
        sdk_payload = dict(
            prompt=prompt, model=XAI_VIDEO_MODEL, duration=duration,
            resolution=resolution, aspect_ratio=aspect_ratio,
            image_url=src_uri,
        )
        try:
            debug_log.info("xai", "Video: SDK async generate starting...")
            resp = await client_async.video.generate(**sdk_payload)
            debug_log.info("xai", f"Video: SDK async done, resp type={type(resp).__name__}")
        except AttributeError:
            debug_log.info("xai", "Video: SDK async no generate, falling back to sync thread")
            import os
            def run_sync():
                client = xai_sdk.Client(api_key=os.environ.get("XAI_API_KEY", XAI_API_KEY_V2))
                return client.video.generate(**sdk_payload)
            resp = await asyncio.to_thread(run_sync)
            debug_log.info("xai", f"Video: SDK sync done, resp type={type(resp).__name__}")
        url = str(getattr(resp, "url", "") or "")
        debug_log.info("xai", f"Video: SDK url={'yes' if url else 'EMPTY'} ({url[:80] if url else 'none'})")
        if url:
            out_path.parent.mkdir(parents=True, exist_ok=True)
            out_path.write_bytes(await xai_download_bytes(url, timeout_s=300))
            debug_log.info("xai", f"Video: SDK saved {out_path.name} ({out_path.stat().st_size}b) in {time.monotonic()-t0:.1f}s")
            return time.monotonic() - t0, url
    except Exception as sdk_err:
        debug_log.warn("xai", f"Video SDK failed ({sdk_err}), trying REST")
    # Fallback to REST
    headers = {"Authorization": f"Bearer {XAI_API_KEY_V2}", "Content-Type": "application/json"}
    payload = {"model": XAI_VIDEO_MODEL, "prompt": prompt, "image_url": image_to_data_uri(source_path), "duration": duration, "resolution": resolution, "aspect_ratio": aspect_ratio}
    t0_rest = time.monotonic()
    async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=600)) as sess:
        async with sess.post("https://api.x.ai/v1/videos/generations", headers=headers, json=payload) as r:
            body = await r.text()
            if r.status >= 400: raise RuntimeError(f"xAI video HTTP {r.status}: {body[:500]}")
            data = json.loads(body)
    url = ""
    if isinstance(data, dict):
        url = str(data.get("url") or "")
        if not url:
            arr = data.get("data") or []
            if arr: url = str((arr[0] or {}).get("url") or "")
        request_id = data.get("request_id")
        if not url and request_id:
            deadline = time.monotonic() + 600
            while time.monotonic() < deadline:
                async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=30)) as sess:
                    async with sess.get(f"https://api.x.ai/v1/videos/{request_id}", headers={"Authorization": f"Bearer {XAI_API_KEY_V2}"}) as pr:
                        pdata = await pr.json()
                st = pdata.get("status")
                if st == "done":
                    url = (pdata.get("video") or {}).get("url") or str(pdata.get("url") or "")
                    break
                if st in ("expired", "failed", "error", "cancelled"):
                    raise RuntimeError(f"xAI video failed: {st}")
                await asyncio.sleep(5)
            else: raise RuntimeError("xAI video timeout")
    if not url: raise RuntimeError("xAI video: URL missing")
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_bytes(await xai_download_bytes(url, timeout_s=300))
    return time.monotonic() - t0, url



# ──────────── HotAPI Generation Functions ────────────

async def hotapi_cdn_upload(image_path: Path) -> str:
    suffix = image_path.suffix.lower()
    if suffix in ('.jpg', '.jpeg'):
        mime = 'image/jpeg'
    elif suffix == '.webp':
        mime = 'image/webp'
    else:
        mime = 'image/png'
    with open(image_path, 'rb') as fh:
        form = aiohttp.FormData()
        form.add_field('file', fh, filename=image_path.name, content_type=mime)
        async with aiohttp.ClientSession() as sess:
            async with sess.post(
                f"{HOTAPI_BASE}/v1/uploads/",
                headers={"Authorization": f"Bearer {HOTAPI_KEY}"},
                data=form,
                timeout=aiohttp.ClientTimeout(total=120),
            ) as r:
                resp = await r.json()
    if 'error' in resp:
        raise Exception(f"HotAPI CDN upload failed: {resp['error']}")
    debug_log.info('hotapi', f'CDN upload: {image_path.name} -> {resp["url"][:60]}')
    return resp['url']


async def hotapi_upload_image(image_path: Path) -> str:
    """Return public HTTPS URL for a local image via our server."""
    import shutil
    target = SHOWCASE_DIR / image_path.name
    if image_path.resolve() != target.resolve():
        shutil.copy2(image_path, target)
    return f"{HOTAPI_SOURCE_BASE}/showcase/{target.name}"


async def hotapi_wait_task(task_id: str, timeout: int = HOTAPI_TASK_TIMEOUT) -> dict:
    """Poll hotapi.ai task until terminal. Returns completed task dict."""
    if not task_id:
        raise Exception("hotapi_wait_task: empty task_id")
    terminal = {"succeeded", "failed", "cancelled", "expired"}
    t0 = time.monotonic()
    async with aiohttp.ClientSession() as sess:
        while True:
            async with sess.get(
                f"{HOTAPI_BASE}/v1/tasks/{task_id}",
                headers={"Authorization": f"Bearer {HOTAPI_KEY}"},
                timeout=aiohttp.ClientTimeout(total=30),
            ) as r:
                task = await r.json()
            if task.get("status") in terminal:
                return task
            if time.monotonic() - t0 > timeout:
                raise Exception(f"HotAPI task {task_id} timeout after {timeout}s")
            await asyncio.sleep(HOTAPI_POLL_INTERVAL)


async def hotapi_download(url: str, out_path: Path) -> None:
    """Download file from hotapi CDN to local path."""
    async with aiohttp.ClientSession() as sess:
        async with sess.get(url, timeout=aiohttp.ClientTimeout(total=300)) as r:
            out_path.write_bytes(await r.read())


async def hotapi_1img_to_image(source_path: Path, out_path: Path, prompt: str, aspect_ratio: str = "9:16", model: str = "") -> tuple:
    """Image editing via hotapi.ai. Returns (elapsed_sec, cdn_url)."""
    model = model or HOTAPI_IMG_MODEL
    t0 = time.monotonic()
    debug_log.info("hotapi", f"img2img: uploading {source_path.name} ({source_path.stat().st_size}b) model={model}")
    img_url = await hotapi_upload_image(source_path)
    debug_log.info("hotapi", f"img2img: uploaded → {img_url[:80]}, submitting task...")

    async with aiohttp.ClientSession() as sess:
        async with sess.post(
            f"{HOTAPI_BASE}/v1/{model}",
            headers={"Authorization": f"Bearer {HOTAPI_KEY}", "Content-Type": "application/json"},
            json={"prompt": prompt, "image": img_url},
            timeout=aiohttp.ClientTimeout(total=60),
        ) as r:
            _raw = await r.text()
            try:
                task = json.loads(_raw)
            except Exception:
                raise Exception(f"HotAPI img2img: bad JSON response (http={r.status}): {_raw[:300]}")

    task_id = task.get("id", "")
    if not task_id:
        raise Exception(f"HotAPI img2img: no task_id in response: {str(task)[:300]}")
    debug_log.info("hotapi", f"img2img: task {task_id} submitted, polling...")
    task = await hotapi_wait_task(task_id)

    if task["status"] != "succeeded":
        raise Exception(f"HotAPI img2img failed [{task['status']}]: {task.get('error')}")

    assets = task.get("output", {}).get("assets", [])
    images = [a for a in assets if a.get("type") == "image"] or assets
    if not images:
        raise Exception("HotAPI img2img: no output images in response")

    cdn_url = images[0]["url"]
    await hotapi_download(cdn_url, out_path)
    elapsed = time.monotonic() - t0
    debug_log.info("hotapi", f"img2img done in {elapsed:.1f}s → {out_path.name} ({out_path.stat().st_size}b)")
    return elapsed, cdn_url


async def hotapi_2img_to_image(source_paths: List[Path], out_path: Path, prompt: str, aspect_ratio: str = "9:16", model: str = "") -> tuple:
    """Multi-image blending via hotapi.ai (seedream-5.0-lite-spicy/image-edit). Returns (elapsed_sec, cdn_url)."""
    t0 = time.monotonic()
    debug_log.info("hotapi", f"2img: uploading {len(source_paths)} images...")
    img_urls = []
    for p in source_paths:
        url = await hotapi_upload_image(p)
        img_urls.append(url)
        debug_log.info("hotapi", f"2img: uploaded {p.name} → {url[:60]}")

    payload_2img = {"prompt": prompt}
    payload_2img["image"] = img_urls[0] if len(img_urls) == 1 else img_urls
    async with aiohttp.ClientSession() as sess:
        async with sess.post(
            f"{HOTAPI_BASE}/v1/{model or HOTAPI_IMG2_MODEL}",
            headers={"Authorization": f"Bearer {HOTAPI_KEY}", "Content-Type": "application/json"},
            json=payload_2img,
            timeout=aiohttp.ClientTimeout(total=60),
        ) as r:
            _raw = await r.text()
            try:
                task = json.loads(_raw)
            except Exception:
                raise Exception(f"HotAPI 2img: bad JSON (http={r.status}): {_raw[:300]}")

    task_id = task.get("id", "")
    if not task_id:
        raise Exception(f"HotAPI 2img: no task_id in response: {str(task)[:300]}")
    debug_log.info("hotapi", f"2img: task {task_id} submitted, polling...")
    task = await hotapi_wait_task(task_id)

    if task["status"] != "succeeded":
        raise Exception(f"HotAPI 2img failed [{task['status']}]: {task.get('error')}")

    assets = task.get("output", {}).get("assets", [])
    images = [a for a in assets if a.get("type") == "image"] or assets
    if not images:
        raise Exception("HotAPI 2img: no output images in response")

    cdn_url = images[0]["url"]
    await hotapi_download(cdn_url, out_path)
    elapsed = time.monotonic() - t0
    debug_log.info("hotapi", f"2img done in {elapsed:.1f}s → {out_path.name}")
    return elapsed, cdn_url


async def hotapi_1img_to_video(source_path: Path, out_path: Path, prompt: str, duration: int = 6, resolution: str = "720p", aspect_ratio: str = "9:16", model: str = "") -> tuple:
    """Image-to-video via hotapi.ai. Returns (elapsed_sec, cdn_url)."""
    model = model or HOTAPI_VID_MODEL
    t0 = time.monotonic()
    img_url = await hotapi_upload_image(source_path)
    debug_log.info("hotapi", f"img2vid: {source_path.name} ({source_path.stat().st_size}b) url={img_url[:80]}, model={model}, prompt={prompt[:80]}")

    async with aiohttp.ClientSession() as sess:
        async with sess.post(
            f"{HOTAPI_BASE}/v1/{model}",
            headers={"Authorization": f"Bearer {HOTAPI_KEY}", "Content-Type": "application/json"},
            json={"prompt": prompt, "image_url": img_url, "duration_seconds": duration, "resolution": resolution},
            timeout=aiohttp.ClientTimeout(total=60),
        ) as r:
            task = await r.json()

    task_id = task.get("id", "")
    if not task_id:
        raise Exception(f"HotAPI img2vid: no task_id in response: {str(task)[:200]}")
    debug_log.info("hotapi", f"img2vid: task {task_id} submitted, polling (timeout={HOTAPI_TASK_TIMEOUT}s)...")
    task = await hotapi_wait_task(task_id, timeout=HOTAPI_TASK_TIMEOUT)

    if task["status"] != "succeeded":
        raise Exception(f"HotAPI img2vid failed [{task['status']}]: {task.get('error')}")

    # Extract video URL from output - hotapi returns output.assets[] with {type, url}
    output = task.get("output") or {}
    assets = output.get("assets", [])
    videos = [a for a in assets if a.get("type") == "video"]
    if not videos:
        # fallback: try output.video_url or any asset
        vid_url = output.get("video_url") or output.get("url") or (assets[0].get("url") if assets else "")
        if not vid_url:
            raise Exception(f"HotAPI img2vid: no video in response: {str(output)[:200]}")
    else:
        vid_url = videos[0]["url"]

    await hotapi_download(vid_url, out_path)
    elapsed = time.monotonic() - t0
    debug_log.info("hotapi", f"img2vid done in {elapsed:.1f}s → {out_path.name} ({out_path.stat().st_size}b)")
    return elapsed, vid_url


# ──────────── xAI Generation Flows ────────────

def _xai_resize_image(img_path: Path):
    """Resize xAI output to 496x880 (9:16). Grok preserves input aspect ratio,
    so if input was 9:16, output is also ~9:16 — just needs resize to exact target.
    """
    try:
        crop_and_resize_9_16(img_path, img_path)
    except Exception as e:
        debug_log.warn("xai", f"Image resize failed: {e}")


def _xai_resize_video(vid_path: Path):
    """Resize xAI output video to match GPU format (496x880) via ffmpeg."""
    import subprocess, tempfile
    try:
        tmp = vid_path.with_suffix(".tmp.mp4")
        crop_filter = (
            f"crop=if(gt(iw/ih\\,{TARGET_W}/{TARGET_H})\\,"
            f"ih*{TARGET_W}/{TARGET_H}\\,iw):"
            f"if(gt(iw/ih\\,{TARGET_W}/{TARGET_H})\\,"
            f"ih\\,iw*{TARGET_H}/{TARGET_W}),"
            f"scale={TARGET_W}:{TARGET_H}"
        )
        subprocess.run([
            "ffmpeg", "-y", "-i", str(vid_path),
            "-vf", crop_filter,
            "-c:v", "libx264", "-preset", "fast", "-crf", "23",
            "-c:a", "aac", "-b:a", "128k",
            "-movflags", "+faststart", str(tmp)
        ], capture_output=True, timeout=60)
        if tmp.exists() and tmp.stat().st_size > 100:
            tmp.replace(vid_path)
        else:
            tmp.unlink(missing_ok=True)
    except Exception as e:
        debug_log.warn("xai", f"Video resize failed: {e}")


async def hotapi_flow_video(source_image_path: Path, image_prompt: str, video_prompt: str, img_model: str = "", vid_model: str = "") -> Dict:
    """hotapi flow for video.
    Stage 1: original full-res image → img edit → Stage1 image
    Stage 2: Stage1 (cropped 496x880) → img2vid → video
    """
    t0 = time.time()
    ts, uid = int(time.time()), uuid.uuid4().hex[:6]
    src_size = source_image_path.stat().st_size

    # ── Stage 1: img→img (ORIGINAL full-res source) ──
    gen_path = SHOWCASE_DIR / f"xai_gen_{ts}_{uid}.jpg"
    debug_log.info("xai", f"Video Stage1 START: source={source_image_path.name} ({src_size}b), model={img_model or HOTAPI_IMG_MODEL}, prompt={image_prompt[:80]}")
    elapsed1, url1 = await hotapi_1img_to_image(source_image_path, gen_path, image_prompt, model=img_model)
    from PIL import Image as PILImage
    raw_size = PILImage.open(gen_path).size
    debug_log.info("xai", f"Video Stage1 DONE: {gen_path.name} raw={raw_size[0]}x{raw_size[1]} ({gen_path.stat().st_size}b) in {elapsed1:.1f}s")

    # ── Crop Stage1 to 496x880 ──
    _xai_resize_image(gen_path)
    crop_size = PILImage.open(gen_path).size
    debug_log.info("xai", f"Video Stage1 CROPPED: {crop_size[0]}x{crop_size[1]} ({gen_path.stat().st_size}b)")

    # ── Stage 2: img→video (cropped Stage1) ──
    vid_path = SHOWCASE_DIR / f"xai_vid_{ts}_{uid}.mp4"
    vid_prompt_final = video_prompt or image_prompt
    debug_log.info("xai", f"Video Stage2 START: source={gen_path.name} ({gen_path.stat().st_size}b), model={vid_model or HOTAPI_VID_MODEL}, prompt={vid_prompt_final[:80]}")
    elapsed2, url2 = await hotapi_1img_to_video(gen_path, vid_path, vid_prompt_final, model=vid_model)
    debug_log.info("xai", f"Video Stage2 DONE: {vid_path.name} ({vid_path.stat().st_size}b) in {elapsed2:.1f}s")

    # ── Crop video to 496x880 ──
    _xai_resize_video(vid_path)
    debug_log.info("xai", f"Video COMPLETE: img={gen_path.name} vid={vid_path.name} total={time.time()-t0:.1f}s")

    # Cost: $0.02 image stage + $0.05/sec video stage
    vid_duration = 6.0  # default
    try:
        import subprocess as _sp
        dur_out = _sp.check_output(
            ["ffprobe", "-v", "quiet", "-show_entries", "format=duration",
             "-of", "default=noprint_wrappers=1:nokey=1", str(vid_path)],
            timeout=5
        ).decode().strip()
        vid_duration = float(dur_out)
    except Exception:
        pass
    cost_video = round(0.02 + 0.05 * vid_duration, 4)

    return {
        "generated_image": f"/showcase/{gen_path.name}",
        "video_url": f"/showcase/{vid_path.name}",
        "outputs": [f"/showcase/{gen_path.name}", f"/showcase/{vid_path.name}"],
        "generation_sec": round(time.time() - t0, 1),
        "method": "hotapi",
        "cost_usd": cost_video,
        "video_duration_sec": round(vid_duration, 1),
    }


async def hotapi_flow_img2img(source_image_path: Path, prompt: str, img_model: str = "") -> Dict:
    """hotapi img2img flow. Post-processes to 496x880."""
    t0 = time.time()
    ts, uid = int(time.time()), uuid.uuid4().hex[:6]
    gen_path = SHOWCASE_DIR / f"xai_img_{ts}_{uid}.jpg"
    await hotapi_1img_to_image(source_image_path, gen_path, prompt, model=img_model)
    _xai_resize_image(gen_path)  # resize to 496x880
    return {"generated_image": f"/showcase/{gen_path.name}", "outputs": [f"/showcase/{gen_path.name}"], "generation_sec": round(time.time() - t0, 1), "method": "hotapi", "cost_usd": 0.02}


async def hotapi_flow_dual(image1_path: Path, image2_path: Path, prompt: str, img_model: str = "") -> Dict:
    """Dual: seedream-5.0-lite-spicy/image-edit with image array. image1+image2 → blended result."""
    debug_log.info("hotapi", f"Dual flow: img1={image1_path.name} img2={image2_path.name} model={img_model or HOTAPI_IMG2_MODEL}")
    t0 = time.time()
    ts, uid = int(time.time()), uuid.uuid4().hex[:6]
    result_path = SHOWCASE_DIR / f"hotapi_dual_{ts}_{uid}.jpg"
    await hotapi_2img_to_image([image1_path, image2_path], result_path, prompt, model=img_model)
    _xai_resize_image(result_path)
    elapsed = round(time.time() - t0, 1)
    debug_log.info("hotapi", f"Dual done in {elapsed}s → {result_path.name}")
    return {"result_image": f"/showcase/{result_path.name}", "generated_image": f"/showcase/{result_path.name}", "outputs": [f"/showcase/{result_path.name}"], "generation_sec": elapsed, "method": "hotapi", "cost_usd": 0.02}


# ──────────── Smart Routing ────────────

async def estimate_generation_eta() -> float:
    """Estimate seconds until GPU worker can start generating.
    Verifies GPU health before routing. Checks vast.ai if idle.
    """
    w = manager.worker
    if w.status == "ready":
        # Quick health check — verify GPU is actually alive
        try:
            async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=5)) as sess:
                headers = w.auth_headers if hasattr(w, 'auth_headers') and w.auth_headers else {}
                async with sess.get(f"{w.base_url}/health", headers=headers) as r:
                    if r.status in (200, 401, 403):  # Any response = server alive
                        return 0.0
            # Health failed — GPU is dead, reset worker
            debug_log.warn("v2", f"GPU health check failed — instance {w.instance_id} is dead, resetting worker")
            await tg_notify(f"⚠️ GPU instance {w.instance_id} не отвечает — сброс, переключаем на Grok")
            w.status = "idle"
            if w.instance_id:
                try:
                    await manager._destroy_instance(w.instance_id)
                except Exception:
                    pass
            w.instance_id = None
            w.base_url = None
            w.comfy_url = None
        except Exception:
            debug_log.warn("v2", f"GPU health unreachable — resetting worker")
            w.status = "idle"
            w.instance_id = None
            w.base_url = None
            w.comfy_url = None
    if w.status == "starting":
        if w.created_at: return max(0.0, 730.0 - (time.time() - w.created_at))
        return 730.0
    if w.status == "busy": return 60.0
    # Worker is idle — check if there's already a running instance on vast.ai
    try:
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=10)) as sess:
            async with sess.get(f"https://cloud.vast.ai/api/v0/instances/?api_key={VAST_API_KEY}") as r:
                data = await r.json()
        instances = data.get("instances", data) if isinstance(data, dict) else data
        if isinstance(instances, list):
            running = [i for i in instances if i.get("actual_status") == "running" and "gpu-portal" in str(i.get("label", ""))]
            if running:
                debug_log.info("v2", f"Found {len(running)} running GPU instance(s) on vast.ai — ETA ~5s (attach)")
                return 5.0  # Fast attach, below threshold
    except Exception as e:
        debug_log.warn("v2", f"ETA check vast.ai failed: {e}")
    return 730.0


def should_use_xai(eta_seconds: float) -> bool:
    """True if xAI fallback should be used. Respects GROK_ENABLED flag."""
    if not GROK_ENABLED:
        return False
    return eta_seconds > XAI_ETA_THRESHOLD_SEC


# ──────────── Generation History ────────────

def _record_history(job: Dict, method: str = None):
    outputs = (job.get("result") or {}).get("outputs", [])
    generation_history.append({
        "id": job.get("job_id"),
        "source_image": job.get("source_image"),
        "result_image": next((f for f in outputs if any(f.endswith(e) for e in (".png", ".jpg", ".jpeg", ".webp"))), (job.get("result") or {}).get("generated_image")),
        "video_url": next((f for f in outputs if any(f.endswith(e) for e in (".mp4", ".webm", ".mov"))), (job.get("result") or {}).get("video_url")),
        "prompt": job.get("image_prompt_positive", "") or job.get("video_prompt_positive", ""),
        "method": method or job.get("method", "gpu"), "type": job.get("type", "video"),
        "generation_sec": job.get("generation_sec"), "cost_usd": job.get("cost_usd"),
        "created_at": time.time(),
    })
    if len(generation_history) > 1000: generation_history[:] = generation_history[-1000:]


def check_auth(request) -> bool:
    token = request.cookies.get("gpu_session")
    if not token:
        auth = request.headers.get("Authorization", "")
        if auth.startswith("Bearer "):
            token = auth[7:]
        elif auth.startswith("Basic "):
            # Support Basic auth: base64(username:password)
            try:
                decoded = base64.b64decode(auth[6:]).decode()
                user, pwd = decoded.split(":", 1)
                if user == PORTAL_USER and pwd == PORTAL_PASS:
                    return True
            except Exception:
                pass
    if token and token in AUTH_SESSIONS:
        if time.time() - AUTH_SESSIONS[token] < SESSION_TTL:
            return True
        del AUTH_SESSIONS[token]
    # Also check user sessions (public site users)
    if token and token in USER_SESSIONS:
        session = USER_SESSIONS[token]
        if session.get("expires", 0) > time.time():
            return True
        del USER_SESSIONS[token]
    return False


# Paths excluded from request logging (noisy or internal)
_LOG_EXCLUDE_PREFIXES = ("/showcase/", "/static/", "/api/health")

async def _mysql_log_request(method: str, path: str, ip: str, ua: str, status: int, ms: int, geo: dict = None):
    """Fire-and-forget: log one API request to MySQL."""
    if geo is None:
        geo = {}
    if not _MYSQL_AVAILABLE:
        return
    pool = await _get_mysql_pool()
    if not pool:
        return
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "INSERT INTO api_requests (ts, method, path, ip, user_agent, status_code, response_ms, country_code, country_name, isp) VALUES (NOW(3),%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                    (method, path[:512], (ip or "")[:64], (ua or "")[:512], status, ms,
                     geo.get("country_code","")[:2], geo.get("country_name","")[:64], geo.get("isp","")[:128])
                )
    except Exception as e:
        pass  # Never break request flow for logging errors


@web.middleware
async def request_log_middleware(request, handler):
    """Log all API requests to MySQL."""
    t0 = time.monotonic()
    response = await handler(request)
    path = request.path
    if not any(path.startswith(p) for p in _LOG_EXCLUDE_PREFIXES):
        ms = int((time.monotonic() - t0) * 1000)
        ip = request.headers.get("X-Forwarded-For", request.remote or "")
        ua = request.headers.get("User-Agent", "")
        geo = _geoip_lookup(ip)
        asyncio.ensure_future(_mysql_log_request(request.method, path, ip, ua, response.status, ms, geo))
        # Alert on 401 from iOS
        if response.status == 401 and ("GrokMVP" in ua or "GPUPortalApp" in ua):
            real_ip = ip.split(",")[0].strip()
            ts_str = datetime.now().strftime("%H:%M %d.%m")
            user_url = f"{ADMIN_PUBLIC_URL}/admin#users/{quote(real_ip, safe='')}"
            asyncio.ensure_future(tg_notify(
                f"🔒 401 от iOS\n{request.method} {path}\nIP: {real_ip}\nВремя: {ts_str}\n👤 {user_url}"
            ))
    return response


_IOS_GENERATE_PATHS = {"/api/showcase/generate", "/api/dual/generate", "/api/t2v/generate"}
_IOS_UA_PREFIXES = ("GrokMVP", "GPUPortalApp")

@web.middleware
async def auth_middleware(request, handler):
    public = {"/api/login", "/login", "/api/health", "/api/easy/catalog", "/api/provision/log",
              "/api/auth/register", "/api/auth/login", "/api/auth/me",
              "/api/ios/catalog/export/video/json", "/api/ios/catalog/export/img2img/json",
              "/api/ios/catalog/export/dual/json", "/api/ios/catalog/export/main/json",
              "/api/ios/catalog/export/video/zip", "/api/ios/catalog/export/img2img/zip",
              "/api/ios/catalog/export/dual/zip", "/api/ios/catalog/export/main/zip",
              "/api/ios/catalog/export/json", "/api/ios/catalog/export/zip",
              "/api/ios/catalog/export/zip/v2", "/api/ios/catalog/export/zip/images"}
    if request.path in public or request.path.startswith("/static") or request.path.startswith("/showcase/") or request.path.startswith("/dataset/") or request.path.startswith("/api/ios/public/") or request.path.startswith("/api/ios/catalog/export/") or request.path == "/api/dataset/random":
        return await handler(request)
    # iOS app requests bypass auth (identified by User-Agent)
    ua = request.headers.get("User-Agent", "")
    is_ios = any(p in ua for p in _IOS_UA_PREFIXES)
    if is_ios and (request.path in _IOS_GENERATE_PATHS or request.path.startswith("/api/jobs/")):
        return await handler(request)
    if not check_auth(request):
        if request.path.startswith("/api/"):
            return web.json_response({"error": "unauthorized"}, status=401)
        raise web.HTTPFound("/gpu/login")
    return await handler(request)


# ──────────── Models / Weights registry ────────────
KNOWN_WEIGHTS = [
    {"repo": "Comfy-Org/Wan_2.1_ComfyUI_repackaged", "file": "split_files/text_encoders/umt5_xxl_fp8_e4m3fn_scaled.safetensors", "dest": "text_encoders/", "category": "text_encoder", "size_gb": 4.9},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/vae/wan_2.1_vae.safetensors", "dest": "vae/", "category": "vae", "size_gb": 0.2},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/diffusion_models/wan2.2_i2v_low_noise_14B_fp8_scaled.safetensors", "dest": "diffusion_models/", "category": "unet", "size_gb": 14.5},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/diffusion_models/wan2.2_i2v_high_noise_14B_fp8_scaled.safetensors", "dest": "diffusion_models/", "category": "unet", "size_gb": 14.5},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/loras/wan2.2_i2v_lightx2v_4steps_lora_v1_low_noise.safetensors", "dest": "loras/", "category": "lora", "size_gb": 1.2},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/loras/wan2.2_i2v_lightx2v_4steps_lora_v1_high_noise.safetensors", "dest": "loras/", "category": "lora", "size_gb": 1.2},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/diffusion_models/wan2.2_t2v_low_noise_14B_fp8_scaled.safetensors", "dest": "diffusion_models/", "category": "unet", "size_gb": 14.5},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/diffusion_models/wan2.2_t2v_high_noise_14B_fp8_scaled.safetensors", "dest": "diffusion_models/", "category": "unet", "size_gb": 14.5},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/loras/wan2.2_t2v_lightx2v_4steps_lora_v1.1_high_noise.safetensors", "dest": "loras/", "category": "lora", "size_gb": 1.2},
    {"repo": "Comfy-Org/Wan_2.2_ComfyUI_Repackaged", "file": "split_files/loras/wan2.2_t2v_lightx2v_4steps_lora_v1.1_low_noise.safetensors", "dest": "loras/", "category": "lora", "size_gb": 1.2},
    # Flux 2
    {"repo": "Comfy-Org/flux2-dev", "file": "split_files/text_encoders/mistral_3_small_flux2_bf16.safetensors", "dest": "text_encoders/", "category": "text_encoder", "size_gb": 8.5},
    {"repo": "Comfy-Org/flux2-dev", "file": "split_files/diffusion_models/flux2_dev_fp8mixed.safetensors", "dest": "diffusion_models/", "category": "unet", "size_gb": 12.0},
    {"repo": "Comfy-Org/flux2-dev", "file": "split_files/vae/flux2-vae.safetensors", "dest": "vae/", "category": "vae", "size_gb": 0.3},
    {"repo": "ByteZSzn/Flux.2-Turbo-ComfyUI", "file": "Flux_2-Turbo-LoRA_comfyui.safetensors", "dest": "loras/", "category": "lora", "size_gb": 1.5},
]

DEFAULT_TEMPLATES = [
    {
        "id": "flux2-img2img",
        "name": "Flux 2 Image-to-Image",
        "description": "Flux 2 Dev перерисовывает фото (img2img с reference latent). Turbo LoRA для быстрой генерации. ~20GB весов.",
        "category": "img2img",
        "gpu": "H200",
        "model": "Flux 2 Dev FP8 + Turbo LoRA",
        "avg_time_sec": 15,
        "cost_per_run": 0.01,
        "vast_template_hash": "b0eb4a914bfe6083ab77ae93dd969f48",
        "workflow_file": "flux2_img2img.json",
        "setup_script": "flux2_wan22_i2v.sh",
        "provisioning_url": "https://raw.githubusercontent.com/martinroot/template_vast/main/flux2_wan22_i2v.sh",
        "disk_gb": 40,
        "weights": [
            "text_encoders/mistral_3_small_flux2_bf16.safetensors",
            "diffusion_models/flux2_dev_fp8mixed.safetensors",
            "vae/flux2-vae.safetensors",
            "loras/Flux_2-Turbo-LoRA_comfyui.safetensors",
        ],
        "params": {
            "sampler": "euler",
            "guidance": 4,
            "turbo_lora_enabled": True,
            "steps_turbo": 8,
            "steps_normal": 20,
            "megapixels": 1,
        },
        "demo": {
            "before_image": "https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=512",
            "after_image": None,
            "prompt": "identical person same face, evening dress, night city background with neon lights",
        },
        "tags": ["flux2", "img2img", "fast", "turbo", "20GB"],
        "experimental": False,
    },
    {
        "id": "wan22-i2v",
        "name": "Wan 2.2 Image-to-Video",
        "description": "Генерация видео из изображения. Wan 2.2 I2V 14B. Двухпроходный сэмплинг high+low noise.",
        "category": "video",
        "gpu": "H200",
        "model": "Wan 2.2 I2V 14B FP8",
        "avg_time_sec": 45,
        "cost_per_run": 0.03,
        "vast_template_hash": "e82521203035e2c8bd124820eeb49f83",
        "workflow_file": "wan22_i2v.json",
        "setup_script": "wan22_v2.sh",
        "provisioning_url": "https://raw.githubusercontent.com/martinroot/template_vast/main/wan22_i2v.sh",
        "disk_gb": 60,
        "weights": [
            "text_encoders/umt5_xxl_fp8_e4m3fn_scaled.safetensors",
            "vae/wan_2.1_vae.safetensors",
            "diffusion_models/wan2.2_i2v_low_noise_14B_fp8_scaled.safetensors",
            "diffusion_models/wan2.2_i2v_high_noise_14B_fp8_scaled.safetensors",
            "loras/wan2.2_i2v_lightx2v_4steps_lora_v1_low_noise.safetensors",
            "loras/wan2.2_i2v_lightx2v_4steps_lora_v1_high_noise.safetensors",
        ],
        "params": {
            "width": 640, "height": 640, "length": 81, "fps": 16,
            "steps": 4, "cfg": 1, "sampler": "euler", "scheduler": "simple",
            "shift": 5.0, "split_step": 2, "lora_enabled": True,
        },
        "demo": {
            "before_image": "https://images.unsplash.com/photo-1529778873920-4da4926a72c2?w=512",
            "after_video": None,
            "prompt": "cat slowly turns head and blinks, cinematic"
        },
        "tags": ["i2v", "wan2.2", "14B", "fp8", "comfyui", "4step-lora"],
        "experimental": False,
    },
    {
        "id": "wan22-t2v",
        "name": "Wan 2.2 Text-to-Video",
        "description": "Генерация видео из текстового промпта. Wan 2.2 T2V 14B с LightX2V LoRA.",
        "category": "video",
        "gpu": "H200",
        "model": "Wan 2.2 T2V 14B FP8 + LoRA",
        "avg_time_sec": 35,
        "cost_per_run": 0.02,
        "vast_template_hash": "e82521203035e2c8bd124820eeb49f83",
        "workflow_file": None,
        "setup_script": "wan22.sh",
        "weights": [
            "text_encoders/umt5_xxl_fp8_e4m3fn_scaled.safetensors",
            "vae/wan_2.1_vae.safetensors",
            "diffusion_models/wan2.2_t2v_low_noise_14B_fp8_scaled.safetensors",
            "diffusion_models/wan2.2_t2v_high_noise_14B_fp8_scaled.safetensors",
            "loras/wan2.2_t2v_lightx2v_4steps_lora_v1.1_high_noise.safetensors",
            "loras/wan2.2_t2v_lightx2v_4steps_lora_v1.1_low_noise.safetensors",
        ],
        "params": {
            "width": 640, "height": 640, "length": 81, "fps": 16,
            "steps": 4, "cfg": 1, "sampler": "euler", "scheduler": "simple",
        },
        "demo": {"before_image": None, "after_video": None, "prompt": "a beautiful sunset over the ocean, cinematic"},
        "tags": ["t2v", "wan2.2", "14B", "fp8", "comfyui"],
        "experimental": True,
    },
    {
        "id": "flux2-wan22-i2v",
        "name": "Flux 2 img2img + Wan 2.2 I2V",
        "description": "Двухступенчатый пайплайн: Flux 2 Dev перерисовывает фото (img2img с reference latent), затем Wan 2.2 I2V анимирует результат в видео. ~60GB весов.",
        "category": "video",
        "gpu": "H200",
        "model": "Flux 2 Dev FP8 + Turbo LoRA → Wan 2.2 I2V 14B + LightX2V",
        "avg_time_sec": 90,
        "cost_per_run": 0.06,
        "vast_template_hash": "b0eb4a914bfe6083ab77ae93dd969f48",
        "workflow_file": "image_flux2_60GB.json",
        "setup_script": "flux2_wan22_i2v.sh",
        "provisioning_url": "https://raw.githubusercontent.com/martinroot/template_vast/main/flux2_wan22_i2v.sh",
        "disk_gb": 100,
        "weights": [
            "text_encoders/mistral_3_small_flux2_bf16.safetensors",
            "diffusion_models/flux2_dev_fp8mixed.safetensors",
            "vae/flux2-vae.safetensors",
            "loras/Flux_2-Turbo-LoRA_comfyui.safetensors",
            "text_encoders/umt5_xxl_fp8_e4m3fn_scaled.safetensors",
            "vae/wan_2.1_vae.safetensors",
            "diffusion_models/wan2.2_i2v_low_noise_14B_fp8_scaled.safetensors",
            "diffusion_models/wan2.2_i2v_high_noise_14B_fp8_scaled.safetensors",
            "loras/wan2.2_i2v_lightx2v_4steps_lora_v1_low_noise.safetensors",
            "loras/wan2.2_i2v_lightx2v_4steps_lora_v1_high_noise.safetensors",
        ],
        "params": {
            "stage1_flux2": {
                "sampler": "euler",
                "guidance": 4,
                "turbo_lora_enabled": False,
                "steps_turbo": 8,
                "steps_normal": 20,
                "megapixels": 1,
            },
            "stage2_wan22": {
                "width": 496, "height": 1008, "length": 81, "fps": 16,
                "steps": 4, "cfg": 1, "sampler": "euler", "scheduler": "simple",
                "shift": 5.0, "split_step": 2, "lora_enabled": True,
            },
        },
        "demo": {
            "before_image": "https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=512",
            "after_video": None,
            "prompt": "girl slowly turns head and smiles, cinematic night city background with fireworks",
        },
        "tags": ["flux2", "i2v", "img2img", "wan2.2", "combo", "14B", "60GB"],
        "experimental": True,
    },
    {
        "id": "flux2-wan22-i2v-foley",
        "name": "Flux 2 + Wan 2.2 I2V + Audio (Foley)",
        "description": "Трёхступенчатый пайплайн: Flux 2 Dev перерисовывает фото, Wan 2.2 I2V анимирует в видео, HunyuanFoley генерирует звук. ~72GB весов.",
        "category": "video",
        "gpu": "H200",
        "model": "Flux 2 Dev FP8 + Turbo LoRA → Wan 2.2 I2V 14B + LightX2V → HunyuanFoley",
        "avg_time_sec": 150,
        "cost_per_run": 0.10,
        "vast_template_hash": "a7c3f1d2e8b04956c1d3a2f7e9b81234",
        "workflow_file": "flux2_wan22_i2v_foley.json",
        "setup_script": "flux2_wan22_i2v_foley.sh",
        "provisioning_url": "https://raw.githubusercontent.com/martinroot/template_vast/main/flux2_wan22_i2v_foley.sh",
        "disk_gb": 120,
        "weights": [
            "text_encoders/mistral_3_small_flux2_bf16.safetensors",
            "diffusion_models/flux2_dev_fp8mixed.safetensors",
            "vae/flux2-vae.safetensors",
            "loras/Flux_2-Turbo-LoRA_comfyui.safetensors",
            "text_encoders/umt5_xxl_fp8_e4m3fn_scaled.safetensors",
            "vae/wan_2.1_vae.safetensors",
            "diffusion_models/wan2.2_i2v_low_noise_14B_fp8_scaled.safetensors",
            "diffusion_models/wan2.2_i2v_high_noise_14B_fp8_scaled.safetensors",
            "loras/wan2.2_i2v_lightx2v_4steps_lora_v1_low_noise.safetensors",
            "loras/wan2.2_i2v_lightx2v_4steps_lora_v1_high_noise.safetensors",
            "hunyuanfoley/hunyuanvideo_foley.pth",
            "hunyuanfoley/vae_128d_48k.pth",
            "hunyuanfoley/synchformer_state_dict.pth",
            "hunyuanfoley/config.yaml",
        ],
        "params": {
            "stage1_flux2": {
                "sampler": "euler",
                "guidance": 4,
                "turbo_lora_enabled": False,
                "steps_turbo": 8,
                "steps_normal": 20,
                "megapixels": 1,
            },
            "stage2_wan22": {
                "width": 496, "height": 1008, "length": 81, "fps": 16,
                "steps": 4, "cfg": 1, "sampler": "euler", "scheduler": "simple",
                "shift": 5.0, "split_step": 2, "lora_enabled": True,
            },
            "stage3_foley": {
                "fps": 16.0, "duration": 5.1, "cfg_scale": 5.0,
                "steps": 60, "sampler": "euler",
            },
        },
        "demo": {
            "before_image": "https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=512",
            "after_video": None,
            "prompt": "girl slowly turns head and smiles, cinematic night city background with fireworks",
            "foley_prompt": "ambient city sounds, distant fireworks explosions, gentle wind",
        },
        "tags": ["flux2", "i2v", "img2img", "wan2.2", "foley", "audio", "combo", "14B", "72GB"],
        "experimental": True,
    },
]


def load_templates() -> List[Dict]:
    if TEMPLATES_FILE.exists():
        return json.loads(TEMPLATES_FILE.read_text())
    save_templates(DEFAULT_TEMPLATES)
    return DEFAULT_TEMPLATES


def save_templates(tpls: List[Dict]):
    TEMPLATES_FILE.write_text(json.dumps(tpls, indent=2, ensure_ascii=False))


# ──────────── Metrics store ────────────
class MetricsStore:
    def __init__(self):
        self.jobs_history: List[Dict] = []
        self.worker_snapshots: List[Dict] = []
        self.worker_sessions: List[Dict] = []
        self._load()

    def _load(self):
        if METRICS_FILE.exists():
            try:
                d = json.loads(METRICS_FILE.read_text())
                self.jobs_history = d.get("jobs_history", [])
                self.worker_snapshots = d.get("worker_snapshots", [])
                self.worker_sessions = d.get("worker_sessions", [])
            except Exception:
                pass

    def save(self):
        METRICS_FILE.write_text(json.dumps({
            "jobs_history": self.jobs_history[-500:],
            "worker_snapshots": self.worker_snapshots[-1000:],
            "worker_sessions": self.worker_sessions[-200:],
        }, indent=2))

    def record_job(self, job: Dict):
        self.jobs_history.append({
            "job_id": job.get("job_id"),
            "template_id": job.get("template_id"),
            "type": job.get("type"),
            "source": job.get("source", "portal"),
            "status": job.get("status"),
            "created_at": job.get("created_at"),
            "finished_at": time.time(),
            "generation_sec": job.get("generation_sec"),
            "worker_boot_sec": job.get("worker_boot_sec"),
            "total_sec": job.get("total_sec"),
            "gpu": job.get("gpu"),
            "dph": job.get("dph"),
            "cost_usd": job.get("cost_usd"),
            "error": job.get("error"),
        })
        self.save()

    def record_worker_session(self, session: Dict):
        self.worker_sessions.append(session)
        if len(self.worker_sessions) > 200:
            self.worker_sessions = self.worker_sessions[-200:]
        self.save()

    def record_worker_snapshot(self, status: Dict):
        self.worker_snapshots.append({
            "ts": time.time(),
            **status,
        })
        if len(self.worker_snapshots) > 1000:
            self.worker_snapshots = self.worker_snapshots[-1000:]
        self.save()

    def get_dashboard_data(self):
        done = [j for j in self.jobs_history if j.get("status") == "done"]
        errors = [j for j in self.jobs_history if j.get("status") == "error"]
        gen_times = [j["generation_sec"] for j in done if j.get("generation_sec")]
        boot_times = [j["worker_boot_sec"] for j in done if j.get("worker_boot_sec")]
        total_times = [j["total_sec"] for j in done if j.get("total_sec")]
        costs = [j["cost_usd"] for j in done if j.get("cost_usd")]

        # Source breakdown
        ios_jobs = [j for j in self.jobs_history if j.get("source") == "ios"]
        portal_jobs = [j for j in self.jobs_history if j.get("source") in ("portal", None, "")]
        api_jobs = [j for j in self.jobs_history if j.get("source") == "api"]

        # Real cost including idle (from sessions)
        session_costs = [s["cost_usd"] for s in self.worker_sessions if s.get("cost_usd")]
        idle_costs = [s["idle_cost_usd"] for s in self.worker_sessions if s.get("idle_cost_usd")]
        total_real_cost = sum(session_costs) if session_costs else sum(costs)

        return {
            "total_jobs": len(self.jobs_history),
            "done_jobs": len(done),
            "error_jobs": len(errors),
            "ios_jobs": len(ios_jobs),
            "portal_jobs": len(portal_jobs),
            "api_jobs": len(api_jobs),
            "avg_generation_sec": round(sum(gen_times) / len(gen_times), 1) if gen_times else None,
            "avg_boot_sec": round(sum(boot_times) / len(boot_times), 1) if boot_times else None,
            "avg_total_sec": round(sum(total_times) / len(total_times), 1) if total_times else None,
            "min_generation_sec": round(min(gen_times), 1) if gen_times else None,
            "max_generation_sec": round(max(gen_times), 1) if gen_times else None,
            "total_cost_usd": round(sum(costs), 4) if costs else 0,
            "avg_cost_usd": round(sum(costs) / len(costs), 4) if costs else None,
            "total_real_cost_usd": round(total_real_cost, 4),
            "total_idle_cost_usd": round(sum(idle_costs), 4) if idle_costs else 0,
            "worker_sessions": self.worker_sessions[-20:],
            "jobs_timeline": self.jobs_history[-50:],
            "worker_timeline": self.worker_snapshots[-200:],
        }


metrics = MetricsStore()


# ──────────── Debug log ────────────
class DebugLog:
    def __init__(self, max_entries=500):
        self.entries: List[Dict] = []
        self.max = max_entries

    def log(self, level: str, source: str, message: str, data: Any = None):
        entry = {
            "ts": time.time(),
            "level": level,
            "source": source,
            "message": message,
        }
        if data is not None:
            entry["data"] = data
        self.entries.append(entry)
        if len(self.entries) > self.max:
            self.entries = self.entries[-self.max:]

    def info(self, src, msg, data=None): self.log("info", src, msg, data)
    def warn(self, src, msg, data=None): self.log("warn", src, msg, data)
    def error(self, src, msg, data=None): self.log("error", src, msg, data)

    def get_entries(self, level=None, limit=100):
        out = self.entries
        if level:
            out = [e for e in out if e["level"] == level]
        return out[-limit:]


debug_log = DebugLog()


# ──────────── Persistent Event Log ────────────
class EventLog:
    """Persistent JSONL event log — survives restarts."""

    def __init__(self, max_entries: int = 5000):
        self.max = max_entries
        self.entries: List[Dict] = []
        self._load()

    def _load(self):
        if EVENT_LOG_FILE.exists():
            try:
                lines = EVENT_LOG_FILE.read_text().splitlines()
                for line in lines[-self.max:]:
                    self.entries.append(json.loads(line))
            except Exception:
                pass

    def log(self, event_type: str, source: str, message: str, data: Any = None):
        entry = {
            "ts": time.time(),
            "ts_str": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "type": event_type,
            "source": source,
            "message": message,
        }
        if data is not None:
            entry["data"] = data
        self.entries.append(entry)
        if len(self.entries) > self.max:
            self.entries = self.entries[-self.max:]
        try:
            with open(EVENT_LOG_FILE, "a") as f:
                f.write(json.dumps(entry) + "\n")
        except Exception:
            pass

    def get(self, limit: int = 200, event_type: str = None, source: str = None) -> List[Dict]:
        out = self.entries
        if event_type:
            out = [e for e in out if e.get("type") == event_type]
        if source:
            out = [e for e in out if e.get("source") == source]
        return list(reversed(out[-limit:]))


event_log = EventLog()


# ──────────── Worker Manager ────────────
@dataclass
class TestWorker:
    instance_id: Optional[int] = None
    status: str = "idle"
    base_url: Optional[str] = None
    comfy_base_url: Optional[str] = None
    auth_headers: Dict[str, str] = field(default_factory=dict)
    created_at: Optional[float] = None
    last_used_at: Optional[float] = None
    gpu_name: Optional[str] = None
    dph_total: Optional[float] = None
    public_ip: Optional[str] = None
    error: Optional[str] = None
    offer_snapshot: Optional[Dict] = None


class WorkerManager:
    def __init__(self):
        self.worker = TestWorker()
        self.session: Optional[aiohttp.ClientSession] = None
        self.jobs: Dict[str, Dict] = {}
        self._idle_task: Optional[asyncio.Task] = None
        self._snapshot_task: Optional[asyncio.Task] = None
        # Session tracking for real cost accounting
        self._session_ready_at: Optional[float] = None
        self._session_jobs: List[str] = []
        self._session_gen_cost: float = 0.0
        # Machine blacklist: machine_id → blacklist_until (epoch), persisted to disk
        self._machine_blacklist: Dict[int, float] = self._load_machine_blacklist()

    def _load_machine_blacklist(self) -> Dict[int, float]:
        try:
            if MACHINE_BLACKLIST_FILE.exists():
                data = json.loads(MACHINE_BLACKLIST_FILE.read_text())
                now = time.time()
                return {int(k): v for k, v in data.items() if v > now}
        except Exception:
            pass
        return {}

    def _save_machine_blacklist(self):
        try:
            MACHINE_BLACKLIST_FILE.write_text(json.dumps({str(k): v for k, v in self._machine_blacklist.items()}))
        except Exception:
            pass

    def _blacklist_machine(self, machine_id, reason: str, hours: float = 2.0):
        mid = int(machine_id)
        until = time.time() + hours * 3600
        self._machine_blacklist[mid] = until
        self._save_machine_blacklist()
        debug_log.warn("worker", f"Machine {mid} blacklisted {hours}h: {reason[:100]}")
        event_log.log("machine_blacklisted", "worker",
            f"Machine {mid} blacklisted {hours}h: {reason[:100]}",
            {"machine_id": mid, "reason": reason[:200], "until": until})

    async def _get_instance(self, instance_id: int) -> dict:
        """Get instance data from vast.ai. Uses list endpoint (single-instance endpoint is broken)."""
        async with self.session.get(
            f"{CONSOLE_BASE}/api/v0/instances/",
            headers=self.vast_headers, timeout=aiohttp.ClientTimeout(total=15)
        ) as r:
            data = await r.json()
        instances = data if isinstance(data, list) else data.get("instances", [])
        for inst in instances:
            if inst.get("id") == instance_id:
                return inst
        return None

    async def start(self):
        self.session = aiohttp.ClientSession(
            timeout=aiohttp.ClientTimeout(total=REQUEST_TIMEOUT_SEC),
            connector=aiohttp.TCPConnector(ssl=False),
        )
        self._snapshot_task = asyncio.create_task(self._snapshot_loop())
        self._stuck_watchdog_task = asyncio.create_task(self._stuck_instance_watchdog())
        # Auto-attach to existing running GPU on startup
        asyncio.create_task(self._auto_attach_on_startup())

    async def _auto_attach_on_startup(self):
        """Check vast.ai for running instances and attach on startup."""
        await asyncio.sleep(2)  # Let session initialize
        try:
            async with self.session.get(
                f"{CONSOLE_BASE}/api/v0/instances/",
                headers=self.vast_headers, timeout=aiohttp.ClientTimeout(total=15)
            ) as r:
                data = await r.json()
            instances = data if isinstance(data, list) else data.get("instances", [])
            running = [i for i in instances if i.get("actual_status") == "running"
                       and INSTANCE_LABEL_PREFIX in str(i.get("label", ""))
                       and (i.get("gpu_ram", 0) or 0) >= 140000]  # Skip weak GPUs
            if running:
                inst = running[0]
                iid = inst["id"]
                token = inst.get("jupyter_token", "")
                ip, api_port = self._find_port(inst, VAST_EXTERNAL_API_PORT)
                _, comfy_port = self._find_port(inst, VAST_EXTERNAL_COMFY_PORT)
                w = self.worker
                w.instance_id = iid
                w.public_ip = ip
                w.base_url = f"http://{ip}:{api_port}"
                w.comfy_base_url = f"http://{ip}:{comfy_port}"
                w.auth_headers = self._bearer_auth(token)
                w.gpu_name = inst.get("gpu_name", "?")
                w.dph_total = inst.get("dph_total", 0)
                w.created_at = time.time()
                # Quick health check
                try:
                    async with self.session.get(
                        f"{w.base_url}/health", headers=w.auth_headers,
                        timeout=aiohttp.ClientTimeout(total=5)
                    ) as hr:
                        if hr.status in (200, 401, 403):
                            w.status = "ready"
                            w.last_used_at = time.time()
                            self._start_idle_timer()
                            debug_log.info("worker", f"Auto-attached to running instance {iid}: {w.gpu_name} at {w.base_url}")
                            await tg_notify(f"🟢 Портал перезапущен — подхватил GPU {w.gpu_name} instance {iid}")
                            return
                except Exception:
                    pass
                # Health failed but instance running — wait for health in background
                w.status = "starting"
                w.created_at = time.time()
                debug_log.info("worker", f"Found instance {iid} ({w.gpu_name}) but health not ready, monitoring in background")
                asyncio.create_task(self._wait_and_attach(iid, w))
            else:
                debug_log.info("worker", "No running GPU instances on startup")
        except Exception as e:
            debug_log.warn("worker", f"Auto-attach failed: {e}")

    async def _wait_and_attach(self, instance_id, w):
        """Background: poll health until GPU is ready after auto-attach."""
        try:
            for attempt in range(300):  # up to 25 min
                await asyncio.sleep(5)
                try:
                    async with self.session.get(
                        f"{w.base_url}/health", headers=w.auth_headers,
                        timeout=aiohttp.ClientTimeout(total=5)
                    ) as r:
                        if r.status in (200,):
                            w.status = "ready"
                            w.last_used_at = time.time()
                            self._start_idle_timer()
                            debug_log.info("worker", f"Background attach: GPU {w.gpu_name} instance {instance_id} READY after {attempt*5}s")
                            await tg_notify(f"🟢 GPU {w.gpu_name} instance {instance_id} ready (boot {attempt*5}s)")
                            return
                except Exception:
                    pass
                if attempt % 12 == 0 and attempt > 0:
                    debug_log.info("worker", f"Background attach: still waiting for health ({attempt*5}s)...")
            debug_log.warn("worker", f"Background attach: timeout after 25 min for instance {instance_id}")
            w.status = "idle"
        except Exception as e:
            debug_log.error("worker", f"Background attach failed: {e}")
            w.status = "idle"

    async def stop(self):
        if self._idle_task:
            self._idle_task.cancel()
        if self._snapshot_task:
            self._snapshot_task.cancel()
        if self.worker.instance_id and self.worker.status not in ("idle", "stopping"):
            await self._destroy_instance(self.worker.instance_id)
        if self.session:
            await self.session.close()

    async def _snapshot_loop(self):
        while True:
            await asyncio.sleep(30)
            try:
                metrics.record_worker_snapshot(self.worker_status())
            except Exception:
                pass

    @property
    def vast_headers(self):
        return {
            "Authorization": f"Bearer {VAST_API_KEY}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    def _basic_auth(self, password: str):
        raw = f"vastai:{password}".encode()
        token = base64.b64encode(raw).decode()
        return {
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    def _bearer_auth(self, token: str):
        return {
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    def _update_active_jobs_msg(self, msg: str):
        """Set status_msg on all pending jobs so UI shows boot progress."""
        active_statuses = {"queued", "starting_worker", "waiting_health"}
        all_jobs = self.jobs
        for job in all_jobs.values():
            if job.get("status") in active_statuses:
                job["status_msg"] = msg

    def _calc_provision_eta(self, instance_id: int) -> str:
        """Estimate remaining download time from provision log."""
        TOTAL_MB = 90_000  # ~90GB total (Wan2.2 ~35GB + Flux2 ~55GB)
        logs = PROVISION_LOGS.get(str(instance_id)) or PROVISION_LOGS.get(f"C.{instance_id}", [])
        downloaded_mb = 0
        for e in logs:
            if e.get("step") == "download_done":
                detail = e.get("detail", "")
                # "filename.safetensors — 13633MB in 158s"
                try:
                    mb = int(detail.split("MB")[0].rsplit("—", 1)[-1].strip().replace(",", ""))
                    downloaded_mb += mb
                except Exception:
                    pass
        if downloaded_mb < 100:
            return ""
        first_download = next((e for e in logs if e.get("step") == "download_start"), None)
        if not first_download:
            return ""
        elapsed = time.time() - first_download.get("ts", time.time())
        if elapsed <= 0:
            return ""
        speed = downloaded_mb / elapsed  # MB/s
        remaining = max(0, TOTAL_MB - downloaded_mb)
        eta_sec = int(remaining / speed) if speed > 0 else 0
        if eta_sec <= 0:
            return "almost done"
        if eta_sec < 60:
            return f"ETA ~{eta_sec}s"
        return f"ETA ~{eta_sec // 60}min {eta_sec % 60:02d}s"

    async def _wait_health_with_monitoring(self, instance_id: int, base_url: str, auth_headers: dict) -> dict:
        """Wait for worker health with full monitoring:
        - Provision log stall detection (>240s no update → bad GPU/network)
        - Vast instance status polling (exited/failed → immediate fail)
        - Job status messages with ETA
        - Detailed event log
        """
        start = time.time()
        last_health_err = ""
        last_health_log_t = 0
        health_attempts = 0
        last_prov_count = 0
        last_prov_update_t = None  # None = haven't seen any provision logs yet
        STALL_AFTER_START_SEC = 600   # >600s no provision update after first log = stall (large models take 300s+ per file)
        FIRST_LOG_WAIT_SEC = 180      # allow 180s for first provision log to arrive
        last_vast_check_t = 0
        VAST_CHECK_INTERVAL = 30
        # Disk stall detection
        last_disk_util: Optional[float] = None
        last_disk_increase_t: Optional[float] = None
        DISK_BOOT_GRACE_SEC = 120   # allow 2min for instance to boot before checking disk
        DISK_STALL_SEC = 600        # 10min no disk growth = download stalled (matches provision stall; gaps between large files can be 3-5min)

        debug_log.info("worker", f"Health monitoring started: {base_url} instance={instance_id}")
        event_log.log("worker_health_start", "worker", f"Health monitoring: {base_url} instance={instance_id}", {"instance_id": instance_id})

        while time.time() - start < HEALTH_MAX_WAIT_SEC:
            elapsed = round(time.time() - start)

            # ── 1. Health endpoint ──────────────────────────────────────────
            try:
                async with self.session.get(
                    f"{base_url}{HEALTH_PATH}", headers=auth_headers,
                    timeout=aiohttp.ClientTimeout(total=10)
                ) as r:
                    health_attempts += 1
                    if r.status == 200:
                        body = await r.json()
                        if body.get("status") == "healthy":
                            debug_log.info("worker", f"Health OK after {elapsed}s ({health_attempts} attempts)")
                            event_log.log("worker_health_ok", "worker", f"Health OK +{elapsed}s ({health_attempts} polls)", {"elapsed": elapsed, "attempts": health_attempts, "instance_id": instance_id})
                            # ── Disk write test: catch "No space left on device" early ──
                            self._update_active_jobs_msg(f"Worker up +{elapsed}s — checking disk…")
                            try:
                                import io as _io
                                tiny = _io.BytesIO(b"\xff\xd8\xff\xe0" + b"\x00" * 16 + b"\xff\xd9")
                                fd = aiohttp.FormData()
                                fd.add_field("image", tiny, filename="disktest.jpg", content_type="image/jpeg")
                                fd.add_field("type", "input")
                                fd.add_field("overwrite", "true")
                                async with self.session.post(
                                    f"{base_url}/upload/image",
                                    headers=auth_headers, data=fd,
                                    timeout=aiohttp.ClientTimeout(total=15)
                                ) as dr:
                                    if dr.status >= 500:
                                        dtxt = await dr.text()
                                        raise RuntimeError(f"Disk full (HTTP {dr.status}): {dtxt[:100]}")
                                    debug_log.info("worker", f"Disk write test OK (HTTP {dr.status})")
                            except RuntimeError:
                                raise
                            except Exception as de:
                                debug_log.warn("worker", f"Disk test warning (non-fatal): {de}")
                            self._update_active_jobs_msg(f"Worker ready! +{elapsed}s")
                            return body
                    else:
                        cur_err = f"HTTP {r.status}"
                        if cur_err != last_health_err or elapsed - last_health_log_t >= 60:
                            debug_log.warn("worker", f"Health +{elapsed}s: {cur_err}")
                            last_health_err = cur_err
                            last_health_log_t = elapsed
            except Exception as ex:
                cur_err = type(ex).__name__
                if cur_err != last_health_err or elapsed - last_health_log_t >= 60:
                    debug_log.warn("worker", f"Health +{elapsed}s: {ex}")
                    event_log.log("vast_health_poll", "worker", f"Health poll +{elapsed}s: {type(ex).__name__}", {"elapsed": elapsed, "url": base_url})
                    last_health_err = cur_err
                    last_health_log_t = elapsed

            # ── 2. Provision log monitoring ─────────────────────────────────
            prov_logs = PROVISION_LOGS.get(str(instance_id)) or PROVISION_LOGS.get(f"C.{instance_id}", [])
            cur_count = len(prov_logs)
            if cur_count > last_prov_count:
                last_prov_count = cur_count
                last_prov_update_t = time.time()
                last_entry = prov_logs[-1]
                step = last_entry.get("step", "?")
                detail = last_entry.get("detail", "")[:80]
                prov_elapsed = round(last_entry.get("elapsed_sec", 0))
                eta = self._calc_provision_eta(instance_id)
                msg = f"Provisioning +{prov_elapsed}s: {step} — {detail} {eta}".strip()
                self._update_active_jobs_msg(msg)
                event_log.log("provision_progress", "worker",
                    f"[{instance_id}] +{prov_elapsed}s {step}: {detail} {eta}",
                    {"instance_id": instance_id, "step": step, "prov_elapsed": prov_elapsed, "eta": eta})
            elif last_prov_update_t is not None:
                # Stall check: had logs but stopped
                stall_sec = round(time.time() - last_prov_update_t)
                if stall_sec > STALL_AFTER_START_SEC:
                    last_entry = prov_logs[-1] if prov_logs else {}
                    raise RuntimeError(
                        f"Provision stall: {stall_sec}s without progress (last: {last_entry.get('step','?')} — {last_entry.get('detail','')[:60]})"
                    )
                elif stall_sec > 60:
                    self._update_active_jobs_msg(f"Download paused? {stall_sec}s no update…")
            elif elapsed > FIRST_LOG_WAIT_SEC:
                # Never received any provision log — provisioning script may not be running
                debug_log.warn("worker", f"No provision logs after {elapsed}s — provisioning script may not be running")
                self._update_active_jobs_msg(f"Booting GPU +{elapsed}s (no provision log yet)")

            # ── 3. Vast instance status check ───────────────────────────────
            if time.time() - last_vast_check_t >= VAST_CHECK_INTERVAL:
                last_vast_check_t = time.time()
                try:
                    inst_data = await self._get_instance(instance_id)
                    if inst_data is None:
                        # Instance no longer exists in vast.ai
                        event_log.log("worker_instance_gone", "worker",
                            f"Instance {instance_id} not found in vast.ai (destroyed externally)",
                            {"instance_id": instance_id, "elapsed": elapsed})
                        raise RuntimeError(f"Instance {instance_id} vanished from vast.ai after {elapsed}s")

                    actual_status = inst_data.get("actual_status", "?")
                    status_msg_vast = inst_data.get("status_msg", "")[:100]
                    disk_util = inst_data.get("disk_util")  # GB used on disk

                    if actual_status in ("exited", "failed", "error", "stopped", "destroyed") or "error" in status_msg_vast.lower():
                        event_log.log("worker_instance_died", "worker",
                            f"Instance {instance_id} died: {actual_status} — {status_msg_vast}",
                            {"instance_id": instance_id, "status": actual_status, "msg": status_msg_vast})
                        raise RuntimeError(f"Instance {instance_id} died: status={actual_status} — {status_msg_vast}")

                    # ── Disk space stall detection ──────────────────────────
                    if disk_util is not None and elapsed > DISK_BOOT_GRACE_SEC:
                        if last_disk_util is None or disk_util > last_disk_util + 0.5:
                            # Disk growing — good
                            if last_disk_util is not None:
                                delta = disk_util - (last_disk_util or 0)
                                event_log.log("disk_progress", "worker",
                                    f"Instance {instance_id}: disk {disk_util:.1f}GB (+{delta:.1f}GB) +{elapsed}s",
                                    {"instance_id": instance_id, "disk_gb": disk_util, "elapsed": elapsed})
                            last_disk_util = disk_util
                            last_disk_increase_t = time.time()
                        elif last_disk_increase_t is not None:
                            disk_stall_sec = round(time.time() - last_disk_increase_t)
                            if disk_stall_sec >= DISK_STALL_SEC:
                                event_log.log("disk_stall", "worker",
                                    f"Instance {instance_id}: disk stall {disk_stall_sec}s at {disk_util:.1f}GB — bad network",
                                    {"instance_id": instance_id, "disk_gb": disk_util, "stall_sec": disk_stall_sec})
                                raise RuntimeError(
                                    f"Disk stall: {disk_stall_sec}s no disk growth (stuck at {disk_util:.1f}GB) — bad network/GPU"
                                )
                            elif disk_stall_sec > 60:
                                self._update_active_jobs_msg(
                                    f"Disk {disk_util:.1f}GB — no growth for {disk_stall_sec}s, monitoring…"
                                )

                    debug_log.info("vast", f"Instance {instance_id} status +{elapsed}s: {actual_status} disk={disk_util}GB")
                    event_log.log("vast_status", "worker",
                        f"Instance {instance_id}: {actual_status} +{elapsed}s disk={disk_util}GB {status_msg_vast[:60]}",
                        {"instance_id": instance_id, "status": actual_status, "elapsed": elapsed, "disk_gb": disk_util})

                    if not prov_logs:
                        disk_info = f" disk={disk_util:.1f}GB" if disk_util else ""
                        self._update_active_jobs_msg(f"GPU status: {actual_status} +{elapsed}s{disk_info}")

                except RuntimeError:
                    raise
                except Exception as e:
                    debug_log.warn("vast", f"Instance status check failed: {e}")

            await asyncio.sleep(5)

        event_log.log("worker_health_timeout", "worker",
            f"Health timeout after {HEALTH_MAX_WAIT_SEC}s: {base_url}",
            {"instance_id": instance_id, "url": base_url})
        raise RuntimeError(f"Health check timeout after {HEALTH_MAX_WAIT_SEC}s")

    async def _boot_new_instance(self, offers: list, attempt_label: str = "") -> TestWorker:
        """Try each offer in sequence. Destroy bad instances and retry. Max 3 offers."""
        w = self.worker
        MAX_ATTEMPTS = min(3, len(offers))
        last_err = "no offers"

        for attempt, offer in enumerate(offers[:MAX_ATTEMPTS], 1):
            offer_id = offer.get("ask_contract_id") or offer.get("id")
            gpu = offer.get("gpu_name", "?")
            dph = offer.get("dph_total", 0)
            debug_log.info("worker", f"Boot attempt {attempt}/{MAX_ATTEMPTS}: offer {offer_id} ({gpu} ${dph:.2f}/hr)")
            event_log.log("worker_boot_attempt", "worker",
                f"Boot attempt {attempt}/{MAX_ATTEMPTS}: offer {offer_id} {gpu} ${dph:.2f}/hr",
                {"attempt": attempt, "offer_id": offer_id, "gpu": gpu, "dph": dph})
            self._update_active_jobs_msg(f"Ordering GPU (attempt {attempt}/{MAX_ATTEMPTS}): {gpu} ${dph:.2f}/hr")

            iid = None
            try:
                # Get portal URL for provisioning callbacks
                try:
                    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
                    s.connect(("8.8.8.8", 80))
                    _portal_ip = s.getsockname()[0]
                    s.close()
                except Exception:
                    _portal_ip = "127.0.0.1"
                portal_url = os.getenv("PORTAL_PUBLIC_URL", f"http://{_portal_ip}:8899")

                create_body = {
                    "template_hash_id": VAST_TEMPLATE_HASH_ID,
                    "disk": VAST_DISK_GB,
                    "label": f"{INSTANCE_LABEL_PREFIX}-{int(time.time())}",
                    "env": {
                        "PORTAL_URL": portal_url,
                        "COMFY_API_TIMEOUT": "600",
                        "WS_TIMEOUT": "600",
                    },
                }
                async with self.session.put(
                    f"{CONSOLE_BASE}/api/v0/asks/{offer_id}/",
                    headers=self.vast_headers, json=create_body,
                    timeout=aiohttp.ClientTimeout(total=30)
                ) as r:
                    resp = await r.json()

                if not resp.get("success", True) and resp.get("error"):
                    last_err = resp.get("msg", resp.get("error", "unknown"))
                    debug_log.warn("vast", f"Attempt {attempt}: offer {offer_id} rejected: {last_err[:80]}")
                    event_log.log("worker_boot_fail", "worker", f"Attempt {attempt}: offer rejected: {last_err[:80]}", {"offer_id": offer_id})
                    continue

                iid = resp.get("new_contract") or resp.get("instance_id") or resp.get("id")
                if not iid:
                    last_err = f"No instance ID in response: {str(resp)[:100]}"
                    debug_log.warn("vast", f"Attempt {attempt}: {last_err}")
                    continue

                iid = int(iid)
                w.instance_id = iid
                w.dph_total = dph
                w.gpu_name = gpu
                w.offer_snapshot = offer
                w.created_at = time.time()
                debug_log.info("vast", f"Created instance {iid} (attempt {attempt}): {gpu} ${dph:.2f}/hr")
                event_log.log("vast_instance_created", "worker",
                    f"Instance {iid} created (attempt {attempt}): {gpu} ${dph:.2f}/hr",
                    {"instance_id": iid, "gpu": gpu, "dph": dph, "attempt": attempt})
                self._update_active_jobs_msg(f"GPU {gpu} ordered — waiting for ports…")

                inst = await self._wait_instance_ready(iid)
                token = inst.get("jupyter_token", "")
                ip, api_port = self._find_port(inst, VAST_EXTERNAL_API_PORT)
                _, comfy_port = self._find_port(inst, VAST_EXTERNAL_COMFY_PORT)
                w.public_ip = ip
                w.base_url = f"http://{ip}:{api_port}"
                w.comfy_base_url = f"http://{ip}:{comfy_port}"
                w.auth_headers = self._bearer_auth(token)
                debug_log.info("worker", f"Instance {iid} ports ready: {w.base_url}")
                self._update_active_jobs_msg(f"GPU {gpu} booting — downloading models…")

                await self._wait_health_with_monitoring(iid, w.base_url, w.auth_headers)

                # ── Success ──
                w.status = "ready"
                w.last_used_at = time.time()
                self._on_worker_ready("new_instance")
                self._start_idle_timer()
                asyncio.ensure_future(self._cleanup_stopped_instances())
                asyncio.ensure_future(self._stop_extra_running_instances(keep_id=iid))
                debug_log.info("worker", f"Worker ready (attempt {attempt}): {w.base_url}")
                return w

            except Exception as e:
                last_err = str(e)
                tb = traceback.format_exc()
                debug_log.warn("worker", f"Boot attempt {attempt} failed: {e}\n{tb}")
                event_log.log("worker_boot_fail", "worker",
                    f"Attempt {attempt}/{MAX_ATTEMPTS} failed: {e}",
                    {"attempt": attempt, "instance_id": iid, "error": last_err, "traceback": tb})

                # Blacklist the machine for 2h if it stalled/timed out/died
                machine_id = offer.get("machine_id")
                stall_keywords = ("stall", "timeout", "died", "exited", "failed", "network", "space", "disk")
                if machine_id and any(kw in last_err.lower() for kw in stall_keywords):
                    self._blacklist_machine(machine_id, last_err)

                if iid:
                    debug_log.info("worker", f"Destroying failed instance {iid}")
                    asyncio.ensure_future(self._destroy_instance(iid))
                    w.instance_id = None
                    w.base_url = None
                    w.comfy_base_url = None
                if attempt < MAX_ATTEMPTS:
                    self._update_active_jobs_msg(f"Attempt {attempt} failed, trying next GPU offer…")
                    await asyncio.sleep(5)
                continue

        raise RuntimeError(f"All {MAX_ATTEMPTS} boot attempts failed. Last: {last_err}")

    async def ensure_worker_ready(self) -> TestWorker:
        w = self.worker
        if w.status == "ready":
            return w
        if w.status == "starting":
            while w.status == "starting":
                await asyncio.sleep(2)
            if w.status == "ready":
                return w
            raise RuntimeError(f"Worker failed: {w.error}")
        if w.status == "busy":
            raise RuntimeError("Worker is busy with another job")

        w.status = "starting"
        w.error = None
        debug_log.info("worker", "Checking for existing running GPU instances...")
        try:
            # First: try to attach to an already running vast.ai instance
            existing = await self._find_existing_instance()
            if existing:
                debug_log.info("worker", f"Found existing instance {existing['id']}, attaching...")
                token = existing.get("jupyter_token", "")
                ip, api_port = self._find_port(existing, VAST_EXTERNAL_API_PORT)
                _, comfy_port = self._find_port(existing, VAST_EXTERNAL_COMFY_PORT)
                w.instance_id = existing["id"]
                w.public_ip = ip
                w.base_url = f"http://{ip}:{api_port}"
                w.comfy_base_url = f"http://{ip}:{comfy_port}"
                w.auth_headers = self._bearer_auth(existing.get("jupyter_token", ""))
                w.gpu_name = existing.get("gpu_name", VAST_GPU_NAME)
                w.dph_total = existing.get("dph_total", 0)
                w.created_at = time.time()
                self._update_active_jobs_msg(f"Attaching to existing GPU instance {existing['id']}…")
                try:
                    await self._wait_health_with_monitoring(existing["id"], w.base_url, w.auth_headers)
                    w.status = "ready"
                    w.last_used_at = time.time()
                    debug_log.info("worker", f"Attached to existing instance: {w.base_url}")
                    self._on_worker_ready("attach_existing")
                    self._start_idle_timer()
                    asyncio.ensure_future(self._stop_extra_running_instances(keep_id=w.instance_id))
                    return w
                except Exception as e:
                    debug_log.warn("worker", f"Could not attach to existing instance: {e}, booting new one")
                    w.instance_id = None
                    w.base_url = None
                    w.comfy_base_url = None

            # Second: try to resume a stopped instance (disk preserved → fast boot ~2 min)
            stopped = await self._find_stopped_instance()
            if stopped:
                debug_log.info("worker", f"Found stopped instance {stopped['id']}, resuming…")
                self._update_active_jobs_msg(f"Resuming stopped GPU {stopped['id']} (fast ~2min)…")
                resumed = await self._resume_instance(stopped)
                if resumed:
                    w.status = "ready"
                    w.last_used_at = time.time()
                    self._on_worker_ready("resume_stopped")
                    self._start_idle_timer()
                    asyncio.ensure_future(self._cleanup_stopped_instances(keep_id=w.instance_id))
                    asyncio.ensure_future(self._stop_extra_running_instances(keep_id=w.instance_id))
                    return w
                debug_log.warn("worker", "Resume failed, starting new instance")
                asyncio.ensure_future(self._cleanup_stopped_instances())

            # Third: collect offers and boot new instance with retry
            debug_log.info("worker", "No existing instance — searching GPU offers…")
            self._update_active_jobs_msg("Searching GPU offers…")
            all_offers = []
            for gpu in VAST_GPU_PRIORITY:
                offers = await self._search_offers(gpu)
                debug_log.info("vast", f"GPU={gpu}: {len(offers)} offers (max ${VAST_MAX_DPH_TOTAL}/hr)")
                event_log.log("vast_offers", "worker", f"GPU={gpu}: {len(offers)} offers", {"gpu": gpu, "count": len(offers)})
                all_offers.extend(offers)
            # Sort ALL offers by price (cheapest first)
            all_offers.sort(key=lambda o: float(o.get("dph_total", 999)))

            # Filter blacklisted machines
            now = time.time()
            expired = [mid for mid, until in self._machine_blacklist.items() if until < now]
            for mid in expired:
                del self._machine_blacklist[mid]
            if self._machine_blacklist:
                before = len(all_offers)
                all_offers = [o for o in all_offers if int(o.get("machine_id", 0)) not in self._machine_blacklist]
                skipped = before - len(all_offers)
                if skipped:
                    bl_ids = list(self._machine_blacklist.keys())
                    debug_log.warn("worker", f"Skipped {skipped} blacklisted machine(s): {bl_ids}")
                    event_log.log("machine_blacklist_filter", "worker",
                        f"Filtered {skipped} blacklisted machine(s) from offers",
                        {"skipped": skipped, "blacklisted": bl_ids})

            if not all_offers:
                raise RuntimeError(f"No GPU offers available (tried: {', '.join(VAST_GPU_PRIORITY)}, max ${VAST_MAX_DPH_TOTAL}/hr)")

            debug_log.info("worker", f"Found {len(all_offers)} total offers, will try up to 3")
            return await self._boot_new_instance(all_offers)

        except Exception as e:
            w.status = "idle"
            w.error = str(e)
            debug_log.error("worker", f"Worker startup failed: {e}")
            if w.instance_id:
                asyncio.ensure_future(self._destroy_instance(w.instance_id))
                w.instance_id = None
            raise

    async def _find_existing_instance(self):
        """Find already running vast.ai instance that belongs to this portal."""
        try:
            async with self.session.get(
                f"{CONSOLE_BASE}/api/v0/instances/", headers=self.vast_headers
            ) as r:
                data = await r.json()
            instances = data if isinstance(data, list) else data.get("instances", [])
            all_statuses = {i.get("id"): i.get("actual_status") for i in instances}
            debug_log.info("vast", f"Instances query: {len(instances)} total — {all_statuses}")
            event_log.log("vast_instances", "worker", f"Instances found: {len(instances)} — {all_statuses}", {"instances": [{
                "id": i.get("id"), "status": i.get("actual_status"), "gpu": i.get("gpu_name"), "ip": i.get("public_ipaddr")
            } for i in instances]})
            running = [i for i in instances if i.get("actual_status") == "running"]
            if running:
                debug_log.info("worker", f"Found {len(running)} running instance(s)")
                return running[0]
        except Exception as e:
            debug_log.warn("worker", f"Could not query existing instances: {e}")
        return None

    async def _find_stopped_instance(self):
        """Find our stopped/paused vast.ai instance (disk preserved, models intact).
        Prefers most-recently-started instance with our label prefix."""
        try:
            async with self.session.get(
                f"{CONSOLE_BASE}/api/v0/instances/", headers=self.vast_headers
            ) as r:
                data = await r.json()
            instances = data if isinstance(data, list) else data.get("instances", [])
            stopped = [
                i for i in instances
                if i.get("actual_status") in ("stopped", "exited")
                and str(i.get("label", "")).startswith(INSTANCE_LABEL_PREFIX)
            ]
            debug_log.info("vast", f"Stopped instances with label '{INSTANCE_LABEL_PREFIX}': {[i.get('id') for i in stopped]}")
            if stopped:
                # Pick the most recently started (freshest disk state)
                stopped.sort(key=lambda i: i.get("start_date", 0), reverse=True)
                debug_log.info("worker", f"Found {len(stopped)} stopped instance(s), using newest: {stopped[0]['id']}")
                return stopped[0]
        except Exception as e:
            debug_log.warn("worker", f"Could not query stopped instances: {e}")
        return None

    async def _stop_extra_running_instances(self, keep_id: int):
        """If multiple GPU instances are running and job queue is small, stop the extras.
        One GPU is enough for normal load (up to 3-4 queued jobs).
        Stops (pauses) extra instances — preserves disk so resume is fast next time."""
        MAX_QUEUE_FOR_SINGLE_GPU = 3
        try:
            async with self.session.get(
                f"{CONSOLE_BASE}/api/v0/instances/", headers=self.vast_headers
            ) as r:
                data = await r.json()
            instances = data if isinstance(data, list) else data.get("instances", [])
            extras = [
                i for i in instances
                if i.get("actual_status") == "running"
                and i["id"] != keep_id
            ]
            if not extras:
                return
            active_statuses = {"queued", "starting_worker", "waiting_health", "generating"}
            active_jobs = sum(1 for j in self.jobs.values() if j.get("status") in active_statuses)
            debug_log.info("worker", f"Extra running instances: {[i['id'] for i in extras]}, active jobs: {active_jobs}")
            if active_jobs <= MAX_QUEUE_FOR_SINGLE_GPU:
                debug_log.info("worker", f"Queue small ({active_jobs} jobs) — stopping {len(extras)} extra instance(s)")
                for inst in extras:
                    iid = inst["id"]
                    try:
                        async with self.session.put(
                            f"{CONSOLE_BASE}/api/v0/instances/{iid}/",
                            headers=self.vast_headers,
                            json={"state": "stopped"},
                        ) as r:
                            await r.text()
                        debug_log.info("worker", f"Extra instance {iid} stopped (disk preserved)")
                    except Exception as e:
                        debug_log.warn("worker", f"Could not stop extra instance {iid}: {e}")
            else:
                debug_log.info("worker", f"Queue large ({active_jobs} jobs) — keeping all {len(extras)+1} instances running")
        except Exception as e:
            debug_log.warn("worker", f"_stop_extra_running_instances failed: {e}")

    async def _cleanup_stopped_instances(self, keep_id: int = None):
        """Destroy all stopped/paused instances with our label, optionally keeping one.
        Called when resume fails (before new boot) or after new instance boots successfully."""
        try:
            async with self.session.get(
                f"{CONSOLE_BASE}/api/v0/instances/", headers=self.vast_headers
            ) as r:
                data = await r.json()
            instances = data if isinstance(data, list) else data.get("instances", [])
            stopped = [
                i for i in instances
                if i.get("actual_status") in ("stopped", "exited")
                and str(i.get("label", "")).startswith(INSTANCE_LABEL_PREFIX)
                and i["id"] != keep_id
            ]
            if not stopped:
                return
            debug_log.info("worker", f"Cleanup: destroying {len(stopped)} stale stopped instance(s): {[i['id'] for i in stopped]}")
            for inst in stopped:
                await self._destroy_instance(inst["id"])
        except Exception as e:
            debug_log.warn("worker", f"Cleanup stopped instances failed: {e}")

    async def _stop_instance(self, instance_id):
        """Stop (pause) instance — keeps disk and models, faster next boot.
        Also purges any other stale stopped instances (keep only this one)."""
        debug_log.info("worker", f"Stopping (pausing) instance {instance_id} — disk preserved")
        event_log.log("worker_stop", "worker", f"Stopping instance {instance_id} (disk preserved)", {"instance_id": instance_id})
        try:
            async with self.session.put(
                f"{CONSOLE_BASE}/api/v0/instances/{instance_id}/",
                headers=self.vast_headers,
                json={"state": "stopped"},
            ) as r:
                await r.text()
            debug_log.info("worker", f"Instance {instance_id} stopped (disk preserved)")
        except Exception as e:
            debug_log.error("worker", f"Stop failed: {e}, falling back to destroy")
            await self._destroy_instance(instance_id)
            self.worker = TestWorker()
            return
        self.worker = TestWorker()
        # Purge other stale stopped instances, keep only this one
        asyncio.ensure_future(self._cleanup_stopped_instances(keep_id=instance_id))

    async def _check_resume_alive(self, iid: int, timeout: float = 10.0) -> Optional[dict]:
        """Send resume command and check if machine responds within timeout seconds.
        Returns instance dict if machine is alive (status loading/running), None if dead/taken."""
        # Send start command
        try:
            async with self.session.put(
                f"{CONSOLE_BASE}/api/v0/instances/{iid}/",
                headers=self.vast_headers,
                json={"state": "running"},
                timeout=aiohttp.ClientTimeout(total=10),
            ) as r:
                await r.text()
            debug_log.info("worker", f"Resume command sent to {iid}")
        except Exception as e:
            debug_log.warn("worker", f"Resume command failed: {e}")
            return None

        # Poll status for up to `timeout` seconds — vast.ai should flip to "loading" fast
        t0 = time.time()
        while time.time() - t0 < timeout:
            await asyncio.sleep(3)
            try:
                inst = await self._get_instance(iid)
                if not inst:
                    debug_log.warn("worker", f"Resume probe {iid}: not found")
                    return None
                status = inst.get("actual_status", "")
                debug_log.info("worker", f"Resume probe {iid}: status={status} ({round(time.time()-t0, 1)}s)")
                if status in ("loading", "running"):
                    return inst  # machine is alive
                if status in ("error", "deleted", "destroyed", "stopped"):
                    debug_log.warn("worker", f"Instance {iid} not responding to resume: {status}")
                    return None
            except Exception as e:
                debug_log.warn("worker", f"Resume probe error: {e}")

        debug_log.warn("worker", f"Instance {iid} did not flip to loading within {timeout}s — assuming taken")
        return None

    async def _resume_instance(self, instance) -> bool:
        """Resume a stopped instance. Returns True if it became healthy.
        Fast-fail: if machine doesn't respond within 10s → return False immediately (caller starts new)."""
        iid = instance["id"]
        debug_log.info("worker", f"Trying to resume stopped instance {iid} (10s liveness check)...")
        w = self.worker
        t_start = time.time()

        # Quick liveness check — 10 seconds max
        inst = await self._check_resume_alive(iid, timeout=10)
        if not inst:
            debug_log.warn("worker", f"Instance {iid} not alive after 10s — machine likely taken, using new")
            return False

        # Machine is alive (loading/running) — wait for docker to finish + get IP
        debug_log.info("worker", f"Instance {iid} alive, waiting for running + IP...")
        DOCKER_TIMEOUT = 120  # docker itself takes ~40s
        t_docker = time.time()
        while time.time() - t_docker < DOCKER_TIMEOUT:
            await asyncio.sleep(5)
            try:
                inst = await self._get_instance(iid)
                if not inst:
                    continue
                status = inst.get("actual_status", "")
                if status == "running":
                    ip, _ = self._find_port(inst, VAST_EXTERNAL_API_PORT)
                    if ip:
                        break
                if status in ("error", "deleted", "destroyed"):
                    debug_log.warn("worker", f"Instance {iid} errored during resume: {status}")
                    return False
            except Exception:
                pass
        else:
            debug_log.warn("worker", f"Instance {iid} docker not ready after {DOCKER_TIMEOUT}s")
            return False

        # Set up worker connection
        token = inst.get("jupyter_token", "")
        ip, api_port = self._find_port(inst, VAST_EXTERNAL_API_PORT)
        _, comfy_port = self._find_port(inst, VAST_EXTERNAL_COMFY_PORT)
        w.instance_id = iid
        w.public_ip = ip
        w.base_url = f"http://{ip}:{api_port}"
        w.comfy_base_url = f"http://{ip}:{comfy_port}"
        w.auth_headers = self._bearer_auth(token)
        w.gpu_name = inst.get("gpu_name", VAST_GPU_NAME)
        w.dph_total = inst.get("dph_total", 0)
        w.created_at = time.time()

        # Wait for ComfyUI health — models on disk, just VRAM load (~90s)
        HEALTH_TIMEOUT_RESUME = 300  # 5 min — if longer, something's wrong
        debug_log.info("worker", f"Waiting for ComfyUI health: {w.base_url} (VRAM load ~90s)")
        t_health = time.time()
        while time.time() - t_health < HEALTH_TIMEOUT_RESUME:
            try:
                async with self.session.get(
                    f"{w.base_url}{HEALTH_PATH}",
                    headers=w.auth_headers,
                    timeout=aiohttp.ClientTimeout(total=10),
                ) as hr:
                    if hr.status == 200:
                        txt = await hr.text()
                        if json.loads(txt).get("status") == "healthy":
                            elapsed = round(time.time() - t_start, 1)
                            debug_log.info("worker", f"Resumed instance healthy in {elapsed}s total (vs ~730s cold)")
                            return True
            except Exception:
                pass
            await asyncio.sleep(5)

        debug_log.warn("worker", f"Resumed instance health timeout after {HEALTH_TIMEOUT_RESUME}s")
        w.instance_id = None
        w.base_url = None
        w.comfy_base_url = None
        return False

    async def _search_offers(self, gpu_name: str) -> list:
        """Search rentable 1-GPU offers for given GPU model."""
        import urllib.parse, json as _json
        q = _json.dumps({
            "type": "ondemand",
            "rentable": {"eq": True},
            "rented": {"eq": False},
            "gpu_name": {"eq": gpu_name},
            "num_gpus": {"eq": 1},
            "gpu_ram": {"gte": 140000},
            "disk_space": {"gte": VAST_DISK_GB},  # min disk — must fit weights + workspace
            "reliability": {"gte": VAST_MIN_RELIABILITY},
            "dph_total": {"lte": VAST_MAX_DPH_TOTAL},
            "inet_down": {"gte": 300},  # min 300 MB/s download — skip machines with 0 or unmeasured bandwidth
        })
        url = f"https://cloud.vast.ai/api/v0/bundles/?api_key={VAST_API_KEY}&q={urllib.parse.quote(q)}"
        try:
            async with self.session.get(url, timeout=aiohttp.ClientTimeout(total=15)) as r:
                data = await r.json()
            offers = data.get("offers", data if isinstance(data, list) else [])
            offers.sort(key=lambda o: float(o.get("dph_total", 999)))
            debug_log.info("vast", f"GPU={gpu_name}: {len(offers)} offers (max ${VAST_MAX_DPH_TOTAL}/hr)")
            return offers
        except Exception as e:
            debug_log.warn("vast", f"Offer search failed for {gpu_name}: {e}")
            return []

    async def _create_instance(self):
        # Search all GPU types, sort by price (cheapest first)
        all_offers = []
        for gpu in VAST_GPU_PRIORITY:
            offers = await self._search_offers(gpu)
            all_offers.extend(offers)
        all_offers.sort(key=lambda o: float(o.get("dph_total", 999)))

        if not all_offers:
            raise RuntimeError(f"No GPU offers available (tried: {', '.join(VAST_GPU_PRIORITY)}, max ${VAST_MAX_DPH_TOTAL}/hr)")

        last_err = "unknown"
        for offer in all_offers:
            offer_id = offer.get("ask_contract_id") or offer.get("id")
            gpu = offer.get("gpu_name", "?")
            dph = offer.get("dph_total", 0)
            try:
                # Get our public IP so provisioning script can report timing back
                try:
                    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
                    s.connect(("8.8.8.8", 80))
                    _portal_ip = s.getsockname()[0]
                    s.close()
                except Exception:
                    _portal_ip = "127.0.0.1"
                portal_url = os.getenv("PORTAL_PUBLIC_URL", f"http://{_portal_ip}:8899")

                create_body = {
                    "template_hash_id": VAST_TEMPLATE_HASH_ID,
                    "disk": VAST_DISK_GB,
                    "label": f"{INSTANCE_LABEL_PREFIX}-{int(time.time())}",
                    "env": {
                        "PORTAL_URL": portal_url,
                        "COMFY_API_TIMEOUT": "600",
                        "WS_TIMEOUT": "600",
                    },
                }
                debug_log.info("vast", f"Trying offer {offer_id} ({gpu}, ${dph:.2f}/hr)...")
                async with self.session.put(
                    f"{CONSOLE_BASE}/api/v0/asks/{offer_id}/",
                    headers=self.vast_headers, json=create_body,
                    timeout=aiohttp.ClientTimeout(total=30)
                ) as r:
                    resp = await r.json()

                if not resp.get("success", True) and resp.get("error"):
                    last_err = resp.get("msg", resp.get("error", "unknown"))
                    debug_log.warn("vast", f"Offer {offer_id} rejected: {last_err[:80]}")
                    continue

                iid = resp.get("new_contract") or resp.get("instance_id") or resp.get("id")
                if iid:
                    debug_log.info("vast", f"Created instance {iid} from offer {offer_id} ({gpu} ${dph:.2f}/hr)")
                    return int(iid), offer
                last_err = f"No instance ID in response: {str(resp)[:100]}"
                debug_log.warn("vast", f"Offer {offer_id}: {last_err}")
            except Exception as e:
                last_err = str(e)
                debug_log.warn("vast", f"Offer {offer_id} exception: {e}")
                continue
        raise RuntimeError(f"All offers failed. Last error: {last_err}")

    async def _wait_instance_ready(self, instance_id: int):
        start = time.time()
        last_status = None
        while time.time() - start < INSTANCE_MAX_WAIT_SEC:
            # Use list endpoint — single-instance endpoint returns incomplete data
            async with self.session.get(
                f"{CONSOLE_BASE}/api/v0/instances/",
                headers=self.vast_headers
            ) as r:
                data = await r.json()
            instances_list = data if isinstance(data, list) else data.get("instances", [])
            inst = next((i for i in instances_list if i.get("id") == instance_id), None)
            if not inst:
                elapsed = round(time.time() - start)
                if elapsed > 120:  # Only fail after 2 min of not appearing
                    raise RuntimeError(f"Instance {instance_id} not found in vast.ai after {elapsed}s")
                debug_log.warn("vast", f"Instance {instance_id} not in list yet ({elapsed}s), retrying...")
                await asyncio.sleep(5)
                continue

            ip = str(inst.get("public_ipaddr") or inst.get("public_ip") or "").strip()
            token = inst.get("jupyter_token")
            ports = inst.get("ports") or {}
            api_rows = [r for r in (ports.get(f"{VAST_EXTERNAL_API_PORT}/tcp") or []) if isinstance(r, dict) and r.get("HostPort")]
            comfy_rows = [r for r in (ports.get(f"{VAST_EXTERNAL_COMFY_PORT}/tcp") or []) if isinstance(r, dict) and r.get("HostPort")]
            has_api = bool(api_rows)
            has_comfy = bool(comfy_rows)
            actual_status = inst.get("actual_status", "?")
            status_msg = str(inst.get("status_msg") or "")
            elapsed = round(time.time() - start)

            if actual_status != last_status:
                debug_log.info("vast", f"Instance {instance_id} status: {actual_status} ({elapsed}s) {status_msg[:80]}")
                event_log.log("vast_status", "worker", f"Instance {instance_id}: {actual_status} +{elapsed}s", {"instance_id": instance_id, "status": actual_status, "ip": ip, "has_api": has_api, "has_comfy": has_comfy})
                last_status = actual_status

            if ip and token and has_api and has_comfy:
                debug_log.info("vast", f"Instance {instance_id} ready: ip={ip} ports ok ({elapsed}s)")
                return inst
            await asyncio.sleep(5)
        raise RuntimeError(f"Instance {instance_id} not ready after {INSTANCE_MAX_WAIT_SEC}s")

    def _find_port(self, inst, container_port):
        ip = str(inst.get("public_ipaddr") or inst.get("public_ip") or "").strip()
        ports = inst.get("ports", {})
        rows = [r for r in (ports.get(f"{container_port}/tcp") or []) if isinstance(r, dict) and r.get("HostPort")]
        if rows:
            return ip, int(rows[0]["HostPort"])
        raise RuntimeError(f"Port {container_port} not mapped")

    async def _wait_health(self, base_url, auth_headers):
        start = time.time()
        last_log = 0
        last_err = ""
        attempt = 0
        while time.time() - start < HEALTH_MAX_WAIT_SEC:
            elapsed = round(time.time() - start)
            try:
                async with self.session.get(f"{base_url}{HEALTH_PATH}",
                                             headers=auth_headers,
                                             timeout=aiohttp.ClientTimeout(total=10)) as r:
                    attempt += 1
                    if r.status == 200:
                        body = await r.json()
                        debug_log.info("worker", f"Health OK after {elapsed}s ({attempt} attempts): {body}")
                        event_log.log("worker_health_ok", "worker", f"Health OK: {base_url} after {elapsed}s", {"elapsed": elapsed, "attempts": attempt})
                        return body
                    else:
                        txt = await r.text()
                        cur_err = f"HTTP {r.status}: {txt[:80]}"
                        if cur_err != last_err or elapsed - last_log >= 60:
                            debug_log.warn("worker", f"Health check {elapsed}s: {cur_err}")
                            last_err = cur_err
                            last_log = elapsed
            except Exception as ex:
                cur_err = str(ex)[:80]
                if cur_err != last_err or elapsed - last_log >= 60:
                    debug_log.warn("worker", f"Health check {elapsed}s: {cur_err}")
                    event_log.log("vast_health_poll", "worker", f"Health poll +{elapsed}s: {cur_err}", {"elapsed": elapsed, "url": base_url})
                    last_err = cur_err
                    last_log = elapsed
            await asyncio.sleep(5)
        event_log.log("worker_health_timeout", "worker", f"Health timeout after {HEALTH_MAX_WAIT_SEC}s: {base_url}", {"url": base_url})
        raise RuntimeError(f"Health check timeout after {HEALTH_MAX_WAIT_SEC}s")

    async def _destroy_instance(self, instance_id):
        debug_log.info("worker", f"Destroying instance {instance_id}")
        try:
            async with self.session.delete(
                f"{CONSOLE_BASE}/api/v0/instances/{instance_id}/",
                headers=self.vast_headers
            ) as r:
                await r.text()
        except Exception as e:
            debug_log.error("worker", f"Destroy failed: {e}")
        self.worker = TestWorker()

    def _on_worker_ready(self, boot_type: str = "new"):
        """Called when worker becomes ready — starts session tracking and logs event."""
        w = self.worker
        self._session_ready_at = time.time()
        self._session_jobs = []
        self._session_gen_cost = 0.0
        event_log.log("worker_start", "worker", f"Worker ready ({boot_type}): {w.base_url}", {
            "instance_id": w.instance_id, "gpu": w.gpu_name, "dph": w.dph_total,
            "ip": w.public_ip, "boot_type": boot_type,
        })

    def _on_worker_stop(self, reason: str = "idle_timeout"):
        """Called before worker stops — records session cost and logs event."""
        w = self.worker
        if not self._session_ready_at:
            return
        session_sec = time.time() - self._session_ready_at
        dph = w.dph_total or 0
        session_cost = round(dph * session_sec / 3600, 4)
        idle_cost = round(max(0.0, session_cost - self._session_gen_cost), 4)
        session = {
            "ts": self._session_ready_at,
            "ts_str": datetime.fromtimestamp(self._session_ready_at).strftime("%Y-%m-%d %H:%M:%S"),
            "instance_id": w.instance_id, "gpu": w.gpu_name, "dph": dph,
            "session_sec": round(session_sec, 1),
            "jobs_count": len(self._session_jobs),
            "jobs": self._session_jobs,
            "cost_usd": session_cost,
            "gen_cost_usd": round(self._session_gen_cost, 4),
            "idle_cost_usd": idle_cost,
            "reason": reason,
        }
        metrics.record_worker_session(session)
        event_log.log("worker_stop", "worker", f"Worker stopped ({reason}): session {round(session_sec/60, 1)}min, ${session_cost} (idle ${idle_cost})", {
            "instance_id": w.instance_id, "session_sec": round(session_sec, 1),
            "cost_usd": session_cost, "idle_cost_usd": idle_cost, "jobs": len(self._session_jobs),
        })
        self._session_ready_at = None
        self._session_jobs = []
        self._session_gen_cost = 0.0

    def _record_job_in_session(self, job: Dict):
        """Called when a job completes — accumulates cost in current session."""
        self._session_jobs.append(job.get("job_id", "?"))
        self._session_gen_cost += job.get("cost_usd") or 0.0

    def _start_idle_timer(self):
        if self._idle_task:
            self._idle_task.cancel()
        self._idle_task = asyncio.create_task(self._idle_shutdown())

    async def _idle_shutdown(self):
        while True:
            await asyncio.sleep(30)
            w = self.worker
            if w.status != "ready":
                continue
            # Don't destroy if any job is actively using the worker
            active_statuses = {"queued", "starting_worker", "waiting_health", "generating"}
            all_jobs = self.jobs
            active = [j for j in all_jobs.values() if j.get("status") in active_statuses]
            if active:
                # Job running — refresh last_used_at so idle clock resets
                w.last_used_at = time.time()
                continue
            if w.last_used_at:
                idle_sec = time.time() - w.last_used_at
                if idle_sec >= IDLE_TIMEOUT_SEC:
                    debug_log.info("worker", f"Idle timeout ({IDLE_TIMEOUT_SEC}s), stopping (pausing) instance to preserve disk")
                    self._on_worker_stop("idle_timeout")
                    w.status = "stopping"
                    if w.instance_id:
                        await self._stop_instance(w.instance_id)
                    else:
                        self.worker = TestWorker()
                    break

    async def _stuck_instance_watchdog(self):
        """Auto-destroy worker stuck in 'starting' state for too long.

        Some vast.ai instances boot but never come up on health check.
        Without this watchdog they hang forever in 'starting' status,
        blocking new generations.
        """
        while True:
            try:
                await asyncio.sleep(60)
                w = self.worker
                if w.status != "starting" or not w.created_at:
                    continue
                stuck_sec = time.time() - w.created_at
                if stuck_sec < STUCK_INSTANCE_TIMEOUT_SEC:
                    continue
                iid = w.instance_id
                debug_log.warn("worker", f"Stuck watchdog: instance {iid} in 'starting' for {stuck_sec:.0f}s — auto-destroying")
                event_log.log("worker_stuck_destroyed", "worker",
                    f"Auto-destroyed stuck instance {iid} after {stuck_sec:.0f}s in 'starting'",
                    {"instance_id": iid, "stuck_sec": round(stuck_sec)})
                # Blacklist the machine so we don't pick it again
                if w.offer_snapshot:
                    machine_id = w.offer_snapshot.get("machine_id")
                    if machine_id:
                        self._blacklist_machine(machine_id, f"stuck in starting for {stuck_sec:.0f}s")
                if iid:
                    asyncio.ensure_future(self._destroy_instance(iid))
                self.worker = TestWorker()
            except Exception as e:
                debug_log.warn("worker", f"Stuck watchdog error: {e}")

    def worker_status(self):
        w = self.worker
        now = time.time()
        bl = {mid: round(until - now) for mid, until in self._machine_blacklist.items() if until > now}
        return {
            "status": w.status,
            "instance_id": w.instance_id,
            "gpu": w.gpu_name,
            "dph": w.dph_total,
            "public_ip": w.public_ip,
            "base_url": w.base_url,
            "comfy_url": w.comfy_base_url,
            "uptime_sec": round(now - w.created_at, 1) if w.created_at else None,
            "idle_sec": round(now - w.last_used_at, 1) if w.last_used_at else None,
            "idle_timeout_sec": IDLE_TIMEOUT_SEC,
            "error": w.error,
            "machine_blacklist": bl,  # {machine_id: seconds_remaining}
        }


# ──────────── Grok Auto Creator ────────────





# Shared fallback







# ──────────── Showcase ────────────
IOS_CATALOG_FILE = PORTAL_DIR / "ios_catalog.json"

SHOWCASE_DIR = PORTAL_DIR / "showcase"
DATASET_DIR  = PORTAL_DIR / "dataset"
SHOWCASE_FILE = PORTAL_DIR / "showcase.json"
DUAL_SHOWCASE_FILE = PORTAL_DIR / "showcase_dual.json"  # legacy, merged into showcase.json
CATEGORIES_FILE = PORTAL_DIR / "categories.json"
CATEGORIES_IMG2IMG_FILE = PORTAL_DIR / "categories_img2img.json"

DEFAULT_CATEGORIES = [
    {"id": "outfit", "name": "Смена одежды", "icon": "bi-bag"},
    {"id": "profession", "name": "Профессии", "icon": "bi-briefcase"},
    {"id": "male", "name": "Мужские", "icon": "bi-gender-male"},
    {"id": "female", "name": "Женские", "icon": "bi-gender-female"},
    {"id": "era", "name": "Эпоха", "icon": "bi-hourglass"},
    {"id": "emotion", "name": "Эмоции", "icon": "bi-emoji-laughing"},
    {"id": "cringe", "name": "Кринж", "icon": "bi-emoji-dizzy"},
    {"id": "tiktok", "name": "Тик-Ток Тренды", "icon": "bi-phone"},
    {"id": "cartoon", "name": "Мультяшный", "icon": "bi-stars"},
    {"id": "action", "name": "Экшен", "icon": "bi-lightning"},
    {"id": "other", "name": "Разное", "icon": "bi-three-dots"},
]

DEFAULT_CATEGORIES_IMG2IMG = [
    {"id": "style_transfer", "name": "Стиль", "icon": "bi-palette"},
    {"id": "face_swap", "name": "Лицо", "icon": "bi-person-bounding-box"},
    {"id": "outfit", "name": "Одежда", "icon": "bi-bag"},
    {"id": "background", "name": "Фон", "icon": "bi-image"},
    {"id": "age", "name": "Возраст", "icon": "bi-hourglass-split"},
    {"id": "cartoon", "name": "Мультяшный", "icon": "bi-stars"},
    {"id": "beauty", "name": "Бьюти", "icon": "bi-gem"},
    {"id": "fantasy", "name": "Фэнтези", "icon": "bi-magic"},
    {"id": "other", "name": "Разное", "icon": "bi-three-dots"},
]


def load_categories(fpath=None):
    fpath = fpath or CATEGORIES_FILE
    if fpath.exists():
        try:
            return json.loads(fpath.read_text())
        except Exception:
            pass
    defaults = DEFAULT_CATEGORIES if fpath == CATEGORIES_FILE else DEFAULT_CATEGORIES_IMG2IMG
    save_categories(defaults, fpath)
    return list(defaults)


def save_categories(cats, fpath=None):
    fpath = fpath or CATEGORIES_FILE
    fpath.write_text(json.dumps(cats, indent=2, ensure_ascii=False))


categories: List[Dict] = []


def load_dual_showcase() -> List[Dict]:
    if DUAL_SHOWCASE_FILE.exists():
        try:
            return json.loads(DUAL_SHOWCASE_FILE.read_text())
        except Exception:
            pass
    return []


def save_dual_showcase(items: List[Dict]):
    DUAL_SHOWCASE_FILE.write_text(json.dumps(items, indent=2, ensure_ascii=False))


dual_showcase: List[Dict] = []
categories_img2img: List[Dict] = []
SHOWCASE_DIR.mkdir(exist_ok=True)
GIFFS_DIR = SHOWCASE_DIR / "giffs"
GIFFS_DIR.mkdir(exist_ok=True)


TARGET_W = 496
TARGET_H = 880  # 9:16 ratio, divisible by 16 (required by Flux 2 / Wan latent space)


def crop_and_resize_9_16(input_path: Path, output_path: Path = None) -> Path:
    """Crop image to 9:16 center crop, resize to TARGET_W x TARGET_H."""
    if output_path is None:
        output_path = input_path
    img = PILImage.open(input_path)
    img = img.convert("RGB")
    w, h = img.size
    target_ratio = 9 / 16

    current_ratio = w / h
    if current_ratio > target_ratio:
        new_w = int(h * target_ratio)
        left = (w - new_w) // 2
        img = img.crop((left, 0, left + new_w, h))
    elif current_ratio < target_ratio:
        new_h = int(w / target_ratio)
        top = (h - new_h) // 2
        img = img.crop((0, top, w, top + new_h))

    img = img.resize((TARGET_W, TARGET_H), PILImage.LANCZOS)
    img.save(output_path, quality=95)
    return output_path


def make_split_preview(source_path: Path, generated_path: Path, output_path: Path) -> Path:
    """Left half = source, right half = generated. Same size, stitched."""
    src = PILImage.open(source_path).convert("RGB")
    gen = PILImage.open(generated_path).convert("RGB")
    # Resize both to same dimensions
    w, h = TARGET_W, TARGET_H
    src = src.resize((w, h), PILImage.LANCZOS)
    gen = gen.resize((w, h), PILImage.LANCZOS)
    # Stitch: left half of source + right half of generated
    combined = PILImage.new("RGB", (w, h))
    half = w // 2
    combined.paste(src.crop((0, 0, half, h)), (0, 0))
    combined.paste(gen.crop((half, 0, w, h)), (half, 0))
    combined.save(output_path, quality=95)
    return output_path


GIF_W, GIF_H = 900, 730
GIF_FPS = 10
_GIF_FONT_PATH = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"


def _gif_load_resize(url_rel: str, top_pct: int = 25) -> "PILImage.Image":
    """Load image, crop-to-fill GIF_W×GIF_H. top_pct=0 crops from very top, 50=center, 100=bottom."""
    if url_rel:
        for p in [SHOWCASE_DIR / Path(url_rel).name, PORTAL_DIR / url_rel.lstrip("/")]:
            if p.exists():
                try:
                    img = PILImage.open(p).convert("RGB")
                    iw, ih = img.size
                    scale = max(GIF_W / iw, GIF_H / ih)
                    new_w, new_h = int(iw * scale), int(ih * scale)
                    img = img.resize((new_w, new_h), PILImage.LANCZOS)
                    left = (new_w - GIF_W) // 2
                    top = int((new_h - GIF_H) * (top_pct / 100.0))
                    top = max(0, min(top, new_h - GIF_H))
                    img = img.crop((left, top, left + GIF_W, top + GIF_H))
                    return img
                except Exception:
                    pass
    return PILImage.new("RGB", (GIF_W, GIF_H), (20, 20, 20))


def _gif_add_label(img: "PILImage.Image", text: str) -> "PILImage.Image":
    """Add centred label with dark rounded background."""
    from PIL import ImageDraw, ImageFont
    result = img.copy().convert("RGBA")
    try:
        font = ImageFont.truetype(_GIF_FONT_PATH, 88)
    except Exception:
        font = ImageFont.load_default()
    d = ImageDraw.Draw(result)
    bbox = d.textbbox((0, 0), text, font=font)
    tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
    x = (GIF_W - tw) // 2
    y = (GIF_H - th) // 2
    overlay = PILImage.new("RGBA", result.size, (0, 0, 0, 0))
    od = ImageDraw.Draw(overlay)
    od.rounded_rectangle([x - 24, y - 16, x + tw + 24, y + th + 16], radius=18, fill=(0, 0, 0, 180))
    result = PILImage.alpha_composite(result, overlay)
    ImageDraw.Draw(result).text((x, y), text, font=font, fill=(255, 255, 255, 255))
    return result.convert("RGB")


def _gif_encode_ffmpeg(frames_with_dur: list, out_path: Path):
    """Encode animated GIF via ffmpeg two-pass palette. frames_with_dur = [(PIL_Image, sec), ...]"""
    import tempfile, shutil
    tmpdir = tempfile.mkdtemp(prefix="gif_")
    try:
        concat_lines = []
        for i, (img, dur) in enumerate(frames_with_dur):
            fpath = os.path.join(tmpdir, f"f{i:04d}.png")
            img.save(fpath)
            concat_lines.append(f"file '{fpath}'")
            concat_lines.append(f"duration {dur}")
        # concat needs last file repeated
        concat_lines.append(f"file '{os.path.join(tmpdir, f'f{len(frames_with_dur)-1:04d}.png')}'")
        concat_file = os.path.join(tmpdir, "concat.txt")
        with open(concat_file, "w") as f:
            f.write("\n".join(concat_lines))

        palette = os.path.join(tmpdir, "palette.png")
        vf_scale = f"fps={GIF_FPS},scale={GIF_W}:{GIF_H}:flags=lanczos"

        # Pass 1: palette
        subprocess.run([
            "ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", concat_file,
            "-vf", f"{vf_scale},palettegen=stats_mode=diff",
            palette
        ], capture_output=True, timeout=120)

        # Pass 2: encode GIF
        r = subprocess.run([
            "ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", concat_file,
            "-i", palette,
            "-lavfi", f"{vf_scale} [x]; [x][1:v] paletteuse=dither=bayer:bayer_scale=3",
            str(out_path)
        ], capture_output=True, timeout=120)
        if r.returncode != 0:
            raise RuntimeError(f"ffmpeg GIF pass2 failed: {r.stderr[-300:].decode(errors='replace')}")
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


def make_gif_video(item: Dict, out_path: Path, top_pct: int = 25) -> Path:
    """GIF: 1s black → video frames (up to 5s)."""
    import tempfile, shutil
    video_rel = item.get("video_url", "")
    video_path = None
    if video_rel:
        for vp in [SHOWCASE_DIR / Path(video_rel).name, PORTAL_DIR / video_rel.lstrip("/")]:
            if vp.exists():
                video_path = vp
                break
    if not video_path:
        img = _gif_load_resize(item.get("generated_image") or item.get("source_image", ""), top_pct)
        black = PILImage.new("RGB", (GIF_W, GIF_H), (0, 0, 0))
        _gif_encode_ffmpeg([(black, 1.0), (img, 3.0)], out_path)
        return out_path

    # Use ffmpeg: video only, crop to GIF_W x GIF_H, two-pass palette
    tmpdir = tempfile.mkdtemp(prefix="gifv_")
    try:
        palette = os.path.join(tmpdir, "palette.png")
        vf_crop = (
            f"fps={GIF_FPS},"
            f"scale={GIF_W}:{GIF_H}:force_original_aspect_ratio=increase,"
            f"crop={GIF_W}:{GIF_H}:(iw-{GIF_W})/2:(ih-{GIF_H})*{top_pct/100.0}"
        )

        # Pass 1: palette
        r1 = subprocess.run([
            "ffmpeg", "-y", "-i", str(video_path), "-t", "5",
            "-vf", f"{vf_crop},palettegen=stats_mode=diff",
            palette
        ], capture_output=True, timeout=120)

        if r1.returncode == 0 and os.path.exists(palette):
            # Pass 2: encode with palette
            r2 = subprocess.run([
                "ffmpeg", "-y", "-i", str(video_path), "-i", palette, "-t", "5",
                "-lavfi", f"{vf_crop} [x]; [x][1:v] paletteuse=dither=bayer:bayer_scale=3",
                str(out_path)
            ], capture_output=True, timeout=120)
            if r2.returncode == 0 and out_path.exists():
                debug_log.info("gif", f"GIF video 2-pass ok: {out_path.stat().st_size//1024}KB")
            else:
                debug_log.warn("gif", f"GIF pass2 failed: {r2.stderr[-200:].decode(errors='replace')}")

        # Fallback: simple single-pass
        if not out_path.exists() or out_path.stat().st_size < 100:
            debug_log.warn("gif", "GIF complex failed, trying simple")
            subprocess.run([
                "ffmpeg", "-y", "-i", str(video_path),
                "-vf", f"{vf_crop}",
                "-t", "5", str(out_path)
            ], capture_output=True, timeout=120)
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)

    return out_path


def make_gif_img2img(item: Dict, out_path: Path, top_pct: int = 25) -> Path:
    """GIF: 1.5s До → 0.3s fade → 1.5s После → 0.3s fade, loop."""
    src = _gif_load_resize(item.get("source_image", ""), top_pct)
    gen = _gif_load_resize(item.get("generated_image", "") or item.get("source_image", ""), top_pct)
    before = _gif_add_label(src, "До")
    after  = _gif_add_label(gen, "После")
    black  = PILImage.new("RGB", (GIF_W, GIF_H), (0, 0, 0))

    frames_dur = [(before, 1.5)]
    for step in range(1, 4):
        frames_dur.append((PILImage.blend(before, black, step / 3.0), 0.1))
    frames_dur.append((after, 1.5))
    for step in range(1, 4):
        frames_dur.append((PILImage.blend(after, black, step / 3.0), 0.1))

    _gif_encode_ffmpeg(frames_dur, out_path)
    return out_path


def make_gif_dual(item: Dict, out_path: Path, top_pct: int = 25) -> Path:
    """GIF: 1s фото1 → 1s фото2 → 2.5s результат, loop."""
    img1   = _gif_load_resize(item.get("image1", ""), top_pct)
    img2   = _gif_load_resize(item.get("image2", ""), top_pct)
    result = _gif_load_resize(item.get("result_image", "") or item.get("generated_image", ""), top_pct)

    _gif_encode_ffmpeg([(img1, 1.0), (img2, 1.0), (result, 2.5)], out_path)
    return out_path


def make_gif_for_item(item: Dict, top_pct: int = 25) -> Path:
    """Dispatch GIF generation by item type. Returns path to saved GIF."""
    out_path = GIFFS_DIR / f"{item['id']}.gif"
    t = item.get("type", "video")
    if t == "img2img":
        make_gif_img2img(item, out_path, top_pct)
    elif t == "dual":
        make_gif_dual(item, out_path, top_pct)
    else:
        make_gif_video(item, out_path, top_pct)
    return out_path


def load_showcase() -> List[Dict]:
    if SHOWCASE_FILE.exists():
        try:
            return json.loads(SHOWCASE_FILE.read_text())
        except Exception:
            pass
    return []


def save_showcase(items: List[Dict]):
    # Отмечаем время правки только у реально изменившихся записей: по нему
    # потом видно, какие заготовки демо-кэша устарели после правки шаблона.
    try:
        _old = {str(i.get("id")): i for i in json.loads(SHOWCASE_FILE.read_text())}
    except Exception:
        _old = {}
    _now = int(time.time())
    for _it in items:
        _prev = _old.get(str(_it.get("id")))
        _a = {k: v for k, v in _it.items() if k != "updated_at"}
        _b = {k: v for k, v in (_prev or {}).items() if k != "updated_at"}
        if _prev is None or _a != _b:
            _it["updated_at"] = _now
    SHOWCASE_FILE.write_text(json.dumps(items, indent=2, ensure_ascii=False))
    # Dual write: sync changed items to MySQL in background
    try:
        asyncio.ensure_future(_mysql_sync_showcase_all(items))
    except RuntimeError:
        pass  # No event loop (startup)


async def _mysql_upsert_showcase_item(cur, item: Dict):
    """UPSERT a single showcase item into MySQL."""
    ts = item.get("created_at")
    created_dt = datetime.fromtimestamp(ts) if ts else None
    await cur.execute("""
        INSERT INTO showcase
          (id, name, description, type, template_id,
           source_image, generated_image, split_preview, video_url, gif_url, audio_url,
           image1, image2, result_image,
           image_prompt_positive, image_prompt_negative,
           video_prompt_positive, video_prompt_negative,
           foley_prompt, prompt, categories, tags, likes,
           cost_usd, generation_sec, total_sec, method,
           banner_subtitle, banner_description, outputs, created_at)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        ON DUPLICATE KEY UPDATE
          name=VALUES(name), type=VALUES(type), template_id=VALUES(template_id),
          source_image=VALUES(source_image), generated_image=VALUES(generated_image),
          split_preview=VALUES(split_preview), video_url=VALUES(video_url),
          gif_url=VALUES(gif_url), audio_url=VALUES(audio_url),
          image1=VALUES(image1), image2=VALUES(image2), result_image=VALUES(result_image),
          image_prompt_positive=VALUES(image_prompt_positive), image_prompt_negative=VALUES(image_prompt_negative),
          video_prompt_positive=VALUES(video_prompt_positive), video_prompt_negative=VALUES(video_prompt_negative),
          foley_prompt=VALUES(foley_prompt), prompt=VALUES(prompt),
          categories=VALUES(categories), tags=VALUES(tags), likes=VALUES(likes),
          cost_usd=VALUES(cost_usd), generation_sec=VALUES(generation_sec), total_sec=VALUES(total_sec),
          method=VALUES(method), banner_subtitle=VALUES(banner_subtitle),
          banner_description=VALUES(banner_description), outputs=VALUES(outputs)
    """, (
        item.get("id",""), item.get("name",""), item.get("description"),
        item.get("type","video"), item.get("template_id",""),
        item.get("source_image",""), item.get("generated_image",""),
        item.get("split_preview",""), item.get("video_url",""),
        item.get("gif_url",""), item.get("audio_url",""),
        item.get("image1",""), item.get("image2",""), item.get("result_image",""),
        item.get("image_prompt_positive"), item.get("image_prompt_negative"),
        item.get("video_prompt_positive"), item.get("video_prompt_negative"),
        item.get("foley_prompt"), item.get("prompt"),
        json.dumps(item.get("categories") or []),
        json.dumps(item.get("tags") or []),
        item.get("likes", 0),
        item.get("cost_usd"), item.get("generation_sec"), item.get("total_sec"),
        item.get("method",""),
        item.get("banner_subtitle",""), item.get("banner_description"),
        json.dumps(item.get("outputs") or []), created_dt,
    ))


async def _mysql_sync_showcase_all(items: List[Dict]):
    """Upsert all showcase items to MySQL (called after any save_showcase)."""
    if not _MYSQL_AVAILABLE:
        return
    pool = await _get_mysql_pool()
    if not pool:
        return
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                for item in items:
                    if not item.get("id"):
                        continue
                    await _mysql_upsert_showcase_item(cur, item)
    except Exception as e:
        print(f"[MySQL] sync_showcase error: {e}")


def _save_showcase_item(job: Dict):
    """Save a completed job as a showcase item. Skip GIF regen jobs."""
    job_id = job.get("job_id", "")
    # Don't save GIF regen jobs or API-only requests as new showcase items
    if job_id.startswith("gif-"):
        return
    if not job.get("name"):
        return  # Skip unnamed requests (API, iOS, dashboard generations)
    item = {
        "id": job_id,
        "name": job.get("name", ""),
        "type": job.get("type", "img2img"),
        "template_id": job.get("template_id", ""),
        "source_image": job.get("source_image", "") or job.get("image1", ""),
        "scene_image": job.get("scene_image", "") or job.get("image2", ""),
        "generated_image": job.get("generated_image", ""),
        "video_url": job.get("video_url", ""),
        "audio_url": job.get("audio_url", ""),
        "split_preview": job.get("split_preview", ""),
        "image_prompt_positive": job.get("image_prompt_positive", ""),
        "image_prompt_negative": job.get("image_prompt_negative", ""),
        "video_prompt_positive": job.get("video_prompt_positive", ""),
        "video_prompt_negative": job.get("video_prompt_negative", ""),
        "categories": job.get("categories", []),
        "tags": job.get("tags", []),
        "likes": 0,
        "generation_sec": job.get("generation_sec"),
        "cost_usd": job.get("cost_usd"),
        "method": job.get("method", "gpu"),
        "created_at": job.get("created_at", time.time()),
    }
    showcase.append(item)
    save_showcase(showcase)


# ──────────── Global state ────────────
manager = WorkerManager()
templates: List[Dict] = []
showcase: List[Dict] = []


# ──────────── API Handlers ────────────

async def api_login(request):
    data = await request.json()
    if data.get("username") == PORTAL_USER and data.get("password") == PORTAL_PASS:
        token = secrets.token_hex(32)
        AUTH_SESSIONS[token] = time.time()
        resp = web.json_response({"ok": True, "token": token})
        resp.set_cookie("gpu_session", token, max_age=SESSION_TTL, httponly=True, samesite="Lax")
        debug_log.info("auth", f"Login success for {data.get('username')}")
        return resp
    debug_log.warn("auth", f"Login failed for {data.get('username', '?')}")
    return web.json_response({"error": "invalid credentials"}, status=401)


async def api_logout(request):
    token = request.cookies.get("gpu_session")
    if token and token in AUTH_SESSIONS:
        del AUTH_SESSIONS[token]
    resp = web.json_response({"ok": True})
    resp.del_cookie("gpu_session")
    return resp


# Templates
async def api_templates_list(request):
    return web.json_response({"templates": templates})


async def api_template_get(request):
    tid = request.match_info["id"]
    t = next((t for t in templates if t["id"] == tid), None)
    if not t:
        return web.json_response({"error": "not found"}, status=404)
    return web.json_response(t)


async def api_template_create(request):
    data = await request.json()
    if not data.get("id") or not data.get("name"):
        return web.json_response({"error": "id and name required"}, status=400)
    if any(t["id"] == data["id"] for t in templates):
        return web.json_response({"error": "id already exists"}, status=409)
    templates.append(data)
    save_templates(templates)
    debug_log.info("templates", f"Created template: {data['id']}")
    return web.json_response(data, status=201)


async def api_template_update(request):
    tid = request.match_info["id"]
    data = await request.json()
    for i, t in enumerate(templates):
        if t["id"] == tid:
            templates[i].update(data)
            save_templates(templates)
            debug_log.info("templates", f"Updated template: {tid}")
            return web.json_response(templates[i])
    return web.json_response({"error": "not found"}, status=404)


async def api_template_delete(request):
    tid = request.match_info["id"]
    for i, t in enumerate(templates):
        if t["id"] == tid:
            templates.pop(i)
            save_templates(templates)
            global showcase
            before = len(showcase)
            showcase = [s for s in showcase if s.get("template_id") != tid and s.get("id") != tid]
            if len(showcase) != before:
                save_showcase(showcase)
            debug_log.info("templates", "Deleted template: " + tid)
            return web.json_response({"ok": True})
    return web.json_response({"error": "not found"}, status=404)


# Weights
async def api_weights_list(request):
    return web.json_response({"weights": KNOWN_WEIGHTS})


# Workflows
async def api_workflows_list(request):
    files = []
    for f in WORKFLOWS_DIR.iterdir():
        if f.is_file():
            files.append({"name": f.name, "size": f.stat().st_size})
    return web.json_response({"workflows": files})


async def api_workflow_get(request):
    name = request.match_info["name"]
    path = WORKFLOWS_DIR / name
    if not path.exists():
        return web.json_response({"error": "not found"}, status=404)
    return web.json_response(json.loads(path.read_text()))


async def api_workflow_upload(request):
    reader = await request.multipart()
    part = await reader.next()
    if not part:
        return web.json_response({"error": "no file"}, status=400)
    filename = part.filename or "workflow.json"
    content = await part.read()
    path = WORKFLOWS_DIR / filename
    path.write_bytes(content)
    debug_log.info("workflows", f"Uploaded workflow: {filename}")
    return web.json_response({"ok": True, "name": filename})


# Worker
async def api_worker_status(request):
    return web.json_response(manager.worker_status())


async def api_worker_destroy(request):
    try:
        data = await request.json()
    except Exception:
        data = {}
    stop_only = data.get("stop_only", False)
    w = manager.worker
    if w.instance_id:
        if stop_only:
            await manager._stop_instance(w.instance_id)
            return web.json_response({"ok": True, "message": "stopped (disk preserved)", "instance_id": w.instance_id})
        await manager._destroy_instance(w.instance_id)
        return web.json_response({"ok": True, "message": "destroyed"})
    return web.json_response({"ok": True, "message": "no active worker"})


# Dashboard
async def api_dashboard(request):
    pool = await _get_mysql_pool()
    if pool:
        try:
            async with pool.acquire() as conn:
                async with conn.cursor(aiomysql.DictCursor) as cur:
                    # Aggregate stats
                    await cur.execute("""
                        SELECT
                            COUNT(*) AS total_jobs,
                            SUM(CASE WHEN status='done' OR status IS NULL THEN 1 ELSE 0 END) AS done_jobs,
                            SUM(CASE WHEN status IN ('error','failed') THEN 1 ELSE 0 END) AS error_jobs,
                            SUM(CASE WHEN source='ios' THEN 1 ELSE 0 END) AS ios_jobs,
                            SUM(CASE WHEN source IN ('portal','') OR source IS NULL THEN 1 ELSE 0 END) AS portal_jobs,
                            SUM(CASE WHEN source='api' THEN 1 ELSE 0 END) AS api_jobs,
                            AVG(CASE WHEN gen_sec > 0 AND (status='done' OR status IS NULL) THEN gen_sec END) AS avg_gen_sec,
                            MIN(CASE WHEN gen_sec > 0 AND (status='done' OR status IS NULL) THEN gen_sec END) AS min_gen_sec,
                            MAX(CASE WHEN gen_sec > 0 AND (status='done' OR status IS NULL) THEN gen_sec END) AS max_gen_sec,
                            SUM(CASE WHEN status='done' OR status IS NULL THEN COALESCE(cost_usd,0) ELSE 0 END) AS total_cost,
                            AVG(CASE WHEN cost_usd > 0 AND (status='done' OR status IS NULL) THEN cost_usd END) AS avg_cost
                        FROM generations
                    """)
                    row = await cur.fetchone()

                    # Timeline for charts (last 100 done jobs)
                    await cur.execute("""
                        SELECT UNIX_TIMESTAMP(ts) AS ts, job_id, source, status, gen_sec, cost_usd
                        FROM generations
                        WHERE status='done' OR status IS NULL
                        ORDER BY ts DESC LIMIT 100
                    """)
                    timeline_rows = list(reversed(await cur.fetchall()))

                    # Country breakdown — unique users per country
                    await cur.execute("""
                        SELECT COALESCE(NULLIF(country_code,''), '??') AS cc,
                               COALESCE(NULLIF(country_name,''), 'Unknown') AS cn,
                               COUNT(DISTINCT real_ip) AS cnt
                        FROM users
                        WHERE country_code IS NOT NULL AND country_code != ''
                        GROUP BY cc, cn ORDER BY cnt DESC
                    """)
                    country_rows = await cur.fetchall()

                    # Generation counts per country
                    await cur.execute("""
                        SELECT COALESCE(NULLIF(country_code,''), '??') AS cc,
                               COUNT(*) AS gen_cnt
                        FROM generations
                        WHERE country_code IS NOT NULL AND country_code != ''
                        GROUP BY cc
                    """)
                    gen_country_map = {r["cc"]: int(r["gen_cnt"]) for r in await cur.fetchall()}

            def _f(v, d=None):
                if v is None: return d
                try: return float(v)
                except: return d

            jobs_timeline = [{
                "status": "done",
                "source": r.get("source") or "portal",
                "generation_sec": _f(r.get("gen_sec")),
                "cost_usd": _f(r.get("cost_usd")),
            } for r in timeline_rows]

            # Session data still comes from MetricsStore (idle cost, boot time, worker sessions)
            ms = metrics.get_dashboard_data()
            return web.json_response({
                "total_jobs": int(row["total_jobs"] or 0),
                "done_jobs": int(row["done_jobs"] or 0),
                "error_jobs": int(row["error_jobs"] or 0),
                "ios_jobs": int(row["ios_jobs"] or 0),
                "portal_jobs": int(row["portal_jobs"] or 0),
                "api_jobs": int(row["api_jobs"] or 0),
                "avg_generation_sec": round(_f(row["avg_gen_sec"]), 1) if row.get("avg_gen_sec") else None,
                "min_generation_sec": round(_f(row["min_gen_sec"]), 1) if row.get("min_gen_sec") else None,
                "max_generation_sec": round(_f(row["max_gen_sec"]), 1) if row.get("max_gen_sec") else None,
                "total_cost_usd": round(_f(row["total_cost"], 0.0), 4),
                "avg_cost_usd": round(_f(row["avg_cost"]), 4) if row.get("avg_cost") else None,
                # Session-based data from MetricsStore
                "total_real_cost_usd": ms.get("total_real_cost_usd", 0),
                "total_idle_cost_usd": ms.get("total_idle_cost_usd", 0),
                "avg_boot_sec": ms.get("avg_boot_sec"),
                "worker_sessions": ms.get("worker_sessions", []),
                "jobs_timeline": jobs_timeline,
                "worker_timeline": ms.get("worker_timeline", []),
                "by_country": [{"country_code": r["cc"], "country_name": r["cn"], "count": int(r["cnt"]), "gen_count": gen_country_map.get(r["cc"], 0)} for r in country_rows],
            })
        except Exception as e:
            print(f"[MySQL] api_dashboard error: {e}")
            # Fall back to MetricsStore on error
    return web.json_response(metrics.get_dashboard_data())


# Event log
async def api_events(request):
    limit = int(request.query.get("limit", "200"))
    event_type = request.query.get("type")
    source = request.query.get("source")
    return web.json_response({"events": event_log.get(limit, event_type, source)})


# Debug
async def api_debug_logs(request):
    level = request.query.get("level")
    limit = int(request.query.get("limit", "200"))
    return web.json_response({"logs": debug_log.get_entries(level, limit)})


async def api_debug_config(request):
    return web.json_response({
        "VAST_GPU_NAME": VAST_GPU_NAME,
        "VAST_TEMPLATE_HASH_ID": VAST_TEMPLATE_HASH_ID,
        "VAST_DISK_GB": VAST_DISK_GB,
        "VAST_MAX_DPH_TOTAL": VAST_MAX_DPH_TOTAL,
        "VAST_MIN_RELIABILITY": VAST_MIN_RELIABILITY,
        "OFFER_LIMIT": OFFER_LIMIT,
        "IDLE_TIMEOUT_SEC": IDLE_TIMEOUT_SEC,
        "HEALTH_MAX_WAIT_SEC": HEALTH_MAX_WAIT_SEC,
        "INSTANCE_MAX_WAIT_SEC": INSTANCE_MAX_WAIT_SEC,
        "INSTANCE_LABEL_PREFIX": INSTANCE_LABEL_PREFIX,
    })


# ──────────── Provision timing log endpoints ────────────

async def api_provision_log(request):
    """Receive timing checkpoint from provisioning script running on worker.
    Public endpoint — no auth (worker calls this during boot, before any auth is available).
    Body: {instance_id, step, elapsed_sec, detail}
    """
    try:
        data = await request.json()
    except Exception:
        return web.json_response({"error": "invalid json"}, status=400)

    instance_id = str(data.get("instance_id", "unknown"))
    step = str(data.get("step", "?"))
    elapsed = float(data.get("elapsed_sec", 0))
    detail = str(data.get("detail", ""))

    entry = {"step": step, "elapsed_sec": elapsed, "detail": detail, "ts": time.time()}
    if instance_id not in PROVISION_LOGS:
        PROVISION_LOGS[instance_id] = []
    PROVISION_LOGS[instance_id].append(entry)

    debug_log.info("provision", f"[{instance_id}] +{elapsed:.0f}s {step}: {detail[:120]}")
    return web.json_response({"ok": True})


async def api_provision_status(request):
    """Get provision timing log for an instance (or latest)."""
    if not check_auth(request):
        return web.json_response({"error": "unauthorized"}, status=401)

    instance_id = request.match_info.get("instance_id", "")
    if instance_id == "latest":
        # Return the most recently updated instance log
        if not PROVISION_LOGS:
            return web.json_response({"instance_id": None, "steps": []})
        # Find instance with most recent entry
        latest_id = max(PROVISION_LOGS, key=lambda k: PROVISION_LOGS[k][-1]["ts"] if PROVISION_LOGS[k] else 0)
        return web.json_response({"instance_id": latest_id, "steps": PROVISION_LOGS[latest_id]})

    if instance_id in PROVISION_LOGS:
        return web.json_response({"instance_id": instance_id, "steps": PROVISION_LOGS[instance_id]})

    # Return all if no ID specified
    return web.json_response({"logs": PROVISION_LOGS})


# Vast launch string generator
async def api_vast_launch(request):
    tid = request.match_info.get("id")
    tpl = next((t for t in templates if t["id"] == tid), None)
    if not tpl:
        return web.json_response({"error": "template not found"}, status=404)

    prov_url = tpl.get("provisioning_url", "")
    hash_id = tpl.get("vast_template_hash", "")
    disk = tpl.get("disk_gb", 100)

    launch_args = (
        f'-p 1111:1111 -p 8080:8080 -p 8384:8384 -p 72299:72299 -p 8188:8188 -p 8288:8288'
        f' -e COMFYUI_ARGS="--disable-auto-launch --disable-xformers --port 18188 --enable-cors-header"'
        f' -e COMFYUI_API_BASE=http://localhost:18188'
        f' -e PROVISIONING_SCRIPT={prov_url}'
        f' -e PORTAL_CONFIG="localhost:1111:11111:/:Instance Portal|localhost:8188:18188:/:ComfyUI|localhost:8288:18288:/docs:API Wrapper|localhost:8080:18080:/:Jupyter|localhost:8080:8080:/terminals/1:Jupyter Terminal|localhost:8384:18384:/:Syncthing"'
        f' -e OPEN_BUTTON_PORT=1111 -e JUPYTER_DIR=/ -e DATA_DIRECTORY=/workspace/ -e OPEN_BUTTON_TOKEN=1'
    )

    return web.json_response({
        "template_id": tid,
        "template_name": tpl.get("name"),
        "vast_template_hash": hash_id,
        "disk_gb": disk,
        "provisioning_url": prov_url,
        "launch_args": launch_args,
        "weights": tpl.get("weights", []),
        "weights_count": len(tpl.get("weights", [])),
        "total_weights_gb": sum(
            w.get("size_gb", 0) for w in KNOWN_WEIGHTS
            if any(w["file"].endswith(wn.split("/")[-1]) for wn in tpl.get("weights", []))
        ),
    })


# Version / Changelog
async def api_version(request):
    version = "0.0.0"
    changelog = ""
    version_file = PORTAL_DIR / "VERSION"
    changelog_file = PORTAL_DIR / "CHANGELOG.md"
    if version_file.exists():
        version = version_file.read_text().strip()
    if changelog_file.exists():
        changelog = changelog_file.read_text()

    # Get git tags/commits
    import subprocess
    commits = []
    try:
        result = subprocess.run(
            ["git", "log", "--oneline", "--decorate", "-30"],
            capture_output=True, text=True, cwd=str(PORTAL_DIR), timeout=5,
        )
        for line in result.stdout.strip().split("\n"):
            if line.strip():
                commits.append(line.strip())
    except Exception:
        pass

    tags = []
    try:
        result = subprocess.run(
            ["git", "tag", "-l", "--sort=-creatordate"],
            capture_output=True, text=True, cwd=str(PORTAL_DIR), timeout=5,
        )
        tags = [t.strip() for t in result.stdout.strip().split("\n") if t.strip()]
    except Exception:
        pass

    return web.json_response({
        "version": version,
        "changelog": changelog,
        "github_url": GITHUB_URL,
        "commits": commits,
        "tags": tags,
    })


# Test run
# Static
async def serve_result_file(request):
    filename = request.match_info["filename"]
    path = RESULTS_DIR / filename
    if not path.exists():
        return web.json_response({"error": "not found"}, status=404)
    return web.FileResponse(path)


# ──────────── Сплит-тест видеомоделей ────────────
# Один кадр и один текст уходят сразу в несколько моделей, чтобы сравнивать их
# в равных условиях. Длительность у каждой своя: 3 секунды берёт только Wan 2.7,
# Wan 2.2 умеет ровно 5 или 8, Seedance — от 4. Просим минимально возможное,
# иначе провайдер просто отклоняет запрос.
SPLIT_VIDEO_MODELS = {
    "wan-2.7-spicy":                          {"param": "image_url",            "min": 2, "max": 15},
    "wan-2.2-spicy":                          {"param": "image_url",            "allowed": [5, 8]},
    "seedance-2.0-spicy/image-to-video":      {"param": "image_url",            "min": 4, "max": 15},
    "seedance-2.0-fast-spicy/image-to-video": {"param": "image_url",            "min": 4, "max": 15},
    "seedance-2.5-spicy/image-to-video":      {"param": "image_url",            "min": 4, "max": 30},
    "seedance-2.5-spicy/reference-to-video":  {"param": "reference_image_urls", "min": 4, "max": 30},
}
SPLIT_DIR = UPLOADS_DIR / "splittests"


def split_duration(spec: dict, want: int) -> int:
    """Ближайшая длительность, которую модель реально принимает."""
    if "allowed" in spec:
        return min(spec["allowed"], key=lambda d: (abs(d - want), d))
    return max(spec["min"], min(spec.get("max", 15), want))


async def _split_one(model: str, spec: dict, img_url: str, prompt: str,
                     want_dur: int, resolution: str, run_dir: Path) -> dict:
    dur = split_duration(spec, want_dur)
    payload = {"prompt": prompt, "duration_seconds": dur, "resolution": resolution}
    if spec["param"] == "reference_image_urls":
        payload["reference_image_urls"] = [img_url]
    else:
        payload["image_url"] = img_url
    out = run_dir / (model.replace("/", "_") + ".mp4")
    t0 = time.monotonic()
    try:
        await hotapi_call_model(model, payload, out, media_type="video")
        return {"model": model, "duration": dur, "status": "ok",
                "seconds": round(time.monotonic() - t0, 1),
                "size": out.stat().st_size,
                "url": f"/uploads/splittests/{run_dir.name}/{out.name}"}
    except Exception as e:
        return {"model": model, "duration": dur, "status": "failed",
                "seconds": round(time.monotonic() - t0, 1), "error": str(e)[:400]}


async def api_split_test_models(request):
    """Что вообще можно сравнивать и какие у моделей ограничения."""
    return web.json_response({
        "models": [
            {"id": m, "param": sp["param"],
             "durations": sp.get("allowed") or f'{sp["min"]}-{sp.get("max", 15)}'}
            for m, sp in SPLIT_VIDEO_MODELS.items()
        ]
    })


async def api_split_test(request):
    """POST /api/split-test — один промпт, один кадр, несколько моделей.

    Принимает multipart с полем image (или image_url), prompt, models
    (через запятую, по умолчанию все), duration (по умолчанию 3), resolution.
    Модели гоняются параллельно; отказ одной не мешает остальным.
    """
    data = await request.post()
    prompt = str(data.get("prompt", "")).strip()
    if not prompt:
        return web.json_response({"error": "prompt is required"}, status=400)

    want_dur = int(data.get("duration") or 3)
    resolution = str(data.get("resolution") or "720p")
    raw_models = str(data.get("models") or "").strip()
    models = [m.strip() for m in raw_models.split(",") if m.strip()] if raw_models else list(SPLIT_VIDEO_MODELS)
    unknown = [m for m in models if m not in SPLIT_VIDEO_MODELS]
    if unknown:
        return web.json_response({"error": "unknown models", "models": unknown}, status=400)

    run_id = time.strftime("%Y%m%d-%H%M%S") + "-" + uuid.uuid4().hex[:6]
    run_dir = SPLIT_DIR / run_id
    run_dir.mkdir(parents=True, exist_ok=True)

    img_url = str(data.get("image_url") or "").strip()
    if not img_url:
        field = data.get("image")
        if field is None or not hasattr(field, "file"):
            return web.json_response({"error": "image file or image_url is required"}, status=400)
        src = run_dir / ("source" + (Path(getattr(field, "filename", "src.jpg")).suffix or ".jpg"))
        src.write_bytes(field.file.read())
        # отдаём провайдеру через их CDN, чтобы исходник не светился на нашем домене
        img_url = await hotapi_cdn_upload(src)

    t0 = time.monotonic()
    results = await asyncio.gather(*[
        _split_one(m, SPLIT_VIDEO_MODELS[m], img_url, prompt, want_dur, resolution, run_dir)
        for m in models
    ], return_exceptions=False)

    report = {
        "run_id": run_id, "prompt": prompt, "resolution": resolution,
        "requested_duration": want_dur, "source": img_url,
        "total_seconds": round(time.monotonic() - t0, 1),
        "ok": sum(1 for r in results if r["status"] == "ok"),
        "failed": sum(1 for r in results if r["status"] != "ok"),
        "results": results,
    }
    (run_dir / "report.json").write_text(json.dumps(report, ensure_ascii=False, indent=1))
    debug_log.info("split", f"run {run_id}: {report['ok']} ok / {report['failed']} failed "
                            f"за {report['total_seconds']}s")
    return web.json_response(report)


async def api_health(request):
    return web.json_response({
        "status": "ok",
        "version": "v2", "port": 8899, "xai_enabled": bool(XAI_API_KEY_V2), "hotapi_enabled": bool(HOTAPI_KEY),
        "templates_count": len(templates),
        "worker": manager.worker_status(),
        "jobs_total": len(manager.jobs),
    })


# Pages
async def serve_login(request):
    return web.FileResponse(PORTAL_DIR / "login.html")


async def serve_portal(request):
    resp = web.FileResponse(PORTAL_DIR / "index.html")
    resp.headers["Cache-Control"] = "no-cache, no-store, must-revalidate"
    return resp


async def serve_portal_catch_all(request):
    """SPA catch-all"""
    resp = web.FileResponse(PORTAL_DIR / "index.html")
    resp.headers["Cache-Control"] = "no-cache, no-store, must-revalidate"
    return resp


async def serve_catalog(request):
    return web.FileResponse(PORTAL_DIR / "catalog.html")


async def serve_generate(request):
    return web.FileResponse(PORTAL_DIR / "generate.html")


async def serve_catalog_img2img(request):
    return web.FileResponse(PORTAL_DIR / "catalog-img2img.html")


async def serve_generate_img2img(request):
    return web.FileResponse(PORTAL_DIR / "generate-img2img.html")




# ──────────── Easy iOS API ────────────


async def api_worker_attach(request):
    """Manually attach a running worker instance to the portal manager."""
    if not check_auth(request):
        return web.json_response({"error": "unauthorized"}, status=401)
    data = await request.json()
    base_url = data.get("base_url", "").rstrip("/")
    bearer_token = data.get("bearer_token", "") or data.get("worker_auth", "")
    gpu = data.get("gpu", "H200")
    dph = data.get("dph", 0.0)
    instance_id = data.get("instance_id")
    if not base_url:
        return web.json_response({"error": "base_url required"}, status=400)
    w = manager.worker
    w.base_url = base_url
    w.public_ip = base_url.split("//")[-1].split(":")[0]
    w.auth_headers = {
        "Authorization": f"Bearer {bearer_token}",
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    w.gpu_name = gpu
    w.dph_total = dph
    w.instance_id = instance_id
    w.status = "ready"
    w.last_used_at = time.time()
    comfy_url = data.get("comfy_url", "").rstrip("/")
    w.comfy_base_url = comfy_url or base_url
    manager._on_worker_ready("manual_attach")
    debug_log.info("worker", f"Manual attach: {base_url} comfy={w.comfy_base_url} gpu={gpu}")
    return web.json_response({"ok": True, "base_url": base_url, "gpu": gpu, "status": "ready"})

# ──────────── App ────────────
# ──────────── Showcase API ────────────

async def api_showcase_list(request):
    page = int(request.query.get("page", "1"))
    per_page = int(request.query.get("per_page", "40"))
    tag = request.query.get("tag", "")
    sc_type = request.query.get("type", "")  # video | img2img

    search = request.query.get("search", "").strip().lower()
    sort = request.query.get("sort", "likes")  # likes | newest
    items = list(showcase)
    if search:
        items = [s for s in items if
                 search in (s.get("id", "")).lower() or
                 search in (s.get("name", "")).lower()]
    elif sc_type:
        items = [s for s in items if s.get("type", "video") == sc_type]
    if tag == "__uncategorized__":
        # Items not belonging to any *defined* category for their flow type
        if sc_type == "img2img":
            known = {c["id"] for c in (load_categories(CATEGORIES_IMG2IMG_FILE) if CATEGORIES_IMG2IMG_FILE.exists() else [])}
        elif sc_type == "dual":
            known = {c["id"] for c in (json.loads((PORTAL_DIR / "categories_dual.json").read_text()) if (PORTAL_DIR / "categories_dual.json").exists() else [])}
        else:
            known = {c["id"] for c in (load_categories(CATEGORIES_FILE) if CATEGORIES_FILE.exists() else [])}
        def _in_known(s):
            item_cats = set(s.get("categories") or [])
            if s.get("category"):
                item_cats.add(s["category"])
            return bool(item_cats & known)
        items = [s for s in items if not _in_known(s)]
    elif tag:
        items = [s for s in items if tag in (s.get("categories") or []) or s.get("category") == tag]
    if sort == "likes":
        items.sort(key=lambda s: (s.get("likes") or 0), reverse=True)
    elif sort == "newest":
        items.sort(key=lambda s: (s.get("created_at") or 0), reverse=True)

    total = len(items)
    start = (page - 1) * per_page
    end = start + per_page
    page_items = items[start:end]

    # Strip heavy fields for list view
    light = []
    for s in page_items:
        light.append({
            "id": s.get("id"),
            "name": s.get("name"),
            "category": s.get("category"),
            "categories": s.get("categories", []),
            "tags": s.get("tags"),
            "likes": s.get("likes", 0),
            "source_image": s.get("source_image"),
            "scene_image": s.get("scene_image"),
            "split_preview": s.get("split_preview"),
            "video_url": s.get("video_url") or (next((f for f in (s.get("outputs") or []) if f.endswith((".mp4",".webm",".mov"))), "")),
            "generated_image": s.get("generated_image"),
            "description": s.get("description"),
            "created_at": s.get("created_at"),
            "type": s.get("type", "video"),
            "image1": s.get("image1"),
            "image2": s.get("image2"),
            "result_image": s.get("result_image"),
            "gif_url": s.get("gif_url"),
        })

    return web.json_response({
        "items": light,
        "total": total,
        "page": page,
        "per_page": per_page,
        "pages": (total + per_page - 1) // per_page,
    })


async def api_showcase_get(request):
    sid = request.match_info["id"]
    item = next((s for s in showcase if s["id"] == sid), None)
    if not item:
        return web.json_response({"error": "not found"}, status=404)
    return web.json_response(item)


async def api_showcase_save(request):
    """Save a showcase item (after generation preview approved)."""
    data = await request.json()
    sid = data.get("id")
    if not sid:
        return web.json_response({"error": "id required"}, status=400)

    def _url_to_local(url: str) -> Path | None:
        """Convert /showcase/x.jpg or https://host/showcase/x.jpg to local Path."""
        if not url:
            return None
        name = Path(url).name
        p = SHOWCASE_DIR / name
        return p if p.exists() else None

    # Auto-generate split_preview for img2img/dual if source+result both present
    item_type = data.get("type", "")
    if item_type in ("img2img", "dual") and not data.get("split_preview"):
        src_path = _url_to_local(data.get("source_image", ""))
        gen_path = _url_to_local(data.get("generated_image", ""))
        if src_path and gen_path:
            try:
                import uuid as _uuid, time as _time
                sp_name = f"split_{sid}_{_uuid.uuid4().hex[:6]}.jpg"
                sp_path = SHOWCASE_DIR / sp_name
                make_split_preview(src_path, gen_path, sp_path)
                data["split_preview"] = f"/showcase/{sp_name}"
            except Exception as _e:
                debug_log.warn("showcase", f"split_preview failed: {_e}")

    # Update existing or append
    for i, s in enumerate(showcase):
        if s["id"] == sid:
            showcase[i].update(data)
            save_showcase(showcase)
            return web.json_response(showcase[i])
    showcase.append(data)
    save_showcase(showcase)
    debug_log.info("showcase", f"Saved showcase item: {sid}")
    return web.json_response(data, status=201)


async def api_showcase_patch(request):
    """Patch a showcase item — update prompt/meta fields only (no file paths)."""
    sid = request.match_info["id"]
    item = next((s for s in showcase if s["id"] == sid), None)
    if not item:
        return web.json_response({"error": "not found"}, status=404)
    data = await request.json()
    allowed = {
        "name", "template_id", "categories", "category", "tags",
        "image_prompt_positive", "image_prompt_negative",
        "video_prompt_positive", "video_prompt_negative",
        "prompt", "description",
    }
    patch = {k: v for k, v in data.items() if k in allowed}
    if not patch:
        return web.json_response({"error": "no valid fields"}, status=400)
    item.update(patch)
    save_showcase(showcase)
    debug_log.info("showcase", f"Patched {sid}: {list(patch.keys())}")
    return web.json_response(item)


async def api_showcase_replace_image(request):
    """Replace source/dual image. Crops to 9:16."""
    sid = request.match_info["id"]
    item = next((s for s in showcase if s["id"] == sid), None)
    if not item:
        return web.json_response({"error": "not found"}, status=404)

    reader = await request.multipart()
    target_field = "source_image"  # default
    raw_path = None

    while True:
        part = await reader.next()
        if part is None:
            break
        if part.name == "field":
            target_field = (await part.text()).strip()  # source_image | image1 | image2
        elif part.name == "image":
            filename = f"replace_{int(time.time())}_{uuid.uuid4().hex[:6]}{Path(part.filename or 'img.png').suffix}"
            raw_path = SHOWCASE_DIR / filename
            with open(raw_path, "wb") as f:
                while True:
                    chunk = await part.read_chunk()
                    if not chunk:
                        break
                    f.write(chunk)

    if not raw_path:
        return web.json_response({"error": "no file"}, status=400)

    # Crop to 9:16
    cropped_name = f"replace_{int(time.time())}_{uuid.uuid4().hex[:6]}.jpg"
    cropped_path = SHOWCASE_DIR / cropped_name
    try:
        crop_and_resize_9_16(raw_path, cropped_path)
    except Exception:
        cropped_path = raw_path
        cropped_name = filename

    new_url = f"/showcase/{cropped_name}"

    # Update the right field
    if target_field in ("image1", "image2"):
        item[target_field] = new_url
        # dual: витрина показывает source_image (Фото 1) и scene_image (Фото 2) —
        # держим их в синхроне с тем, что реально уходит в генерацию
        if item.get("type") == "dual":
            item["source_image" if target_field == "image1" else "scene_image"] = new_url
    else:
        item["source_image"] = new_url
        if item.get("type") == "dual":
            item["image1"] = new_url

    # Rebuild split preview for non-dual items
    if item.get("type") != "dual":
        gen_img_url = item.get("generated_image", "")
        if gen_img_url and gen_img_url.startswith("/showcase/"):
            gen_path = SHOWCASE_DIR / Path(gen_img_url).name
            if gen_path.exists():
                try:
                    split_name = f"{sid}_split_new.jpg"
                    make_split_preview(cropped_path, gen_path, SHOWCASE_DIR / split_name)
                    item["split_preview"] = f"/showcase/{split_name}"
                except Exception as e:
                    debug_log.warn("showcase", f"Split rebuild failed: {e}")

    save_showcase(showcase)
    debug_log.info("showcase", f"Replaced {target_field} for {sid}")
    return web.json_response({"ok": True, "field": target_field, "url": new_url})


async def api_showcase_like(request):
    data = await request.json()
    sid = data.get("id")
    delta = data.get("delta", 1)
    for s in showcase:
        if s["id"] == sid:
            s["likes"] = max(0, (s.get("likes") or 0) + delta)
            save_showcase(showcase)
            return web.json_response({"ok": True, "likes": s["likes"]})
    return web.json_response({"error": "not found"}, status=404)


async def api_showcase_clone(request):
    """Clone a showcase item with a new ID and '(copy)' name suffix."""
    sid = request.match_info["id"]
    item = next((s for s in showcase if s["id"] == sid), None)
    if not item:
        return web.json_response({"error": "not found"}, status=404)
    import copy as _copy
    new_item = _copy.deepcopy(item)
    new_id = f"clone_{int(time.time()*1000)}_{uuid.uuid4().hex[:6]}"
    new_item["id"] = new_id
    new_item["name"] = (item.get("name") or "Untitled") + " (copy)"
    new_item["created_at"] = int(time.time())
    new_item.pop("likes", None)
    showcase.append(new_item)
    save_showcase(showcase)
    debug_log.info("showcase", f"Cloned {sid} → {new_id}")
    return web.json_response(new_item, status=201)


async def api_showcase_delete(request):
    sid = request.match_info["id"]
    for i, s in enumerate(showcase):
        if s["id"] == sid:
            # Файлы шаблонов бывают общими (клон наследует исходники оригинала),
            # поэтому сносим только то, на что больше никто не ссылается,
            # и не стираем безвозвратно, а складываем в _trash.
            MEDIA_FIELDS = ("source_image", "scene_image", "split_preview", "video_url",
                            "generated_image", "image1", "image2", "result_image")

            def _names(item):
                out = set()
                for f in MEDIA_FIELDS:
                    v = item.get(f) or ""
                    if isinstance(v, str) and v.startswith("/showcase/"):
                        out.add(Path(v).name)
                for v in (item.get("outputs") or []):
                    if isinstance(v, str) and v.startswith("/showcase/"):
                        out.add(Path(v).name)
                return out

            mine = _names(s)
            still_used = set()
            for other in showcase:
                if other is not s:
                    still_used |= _names(other)

            trash = SHOWCASE_DIR / "_trash"
            for name in sorted(mine - still_used):
                fpath = SHOWCASE_DIR / name
                if fpath.exists():
                    try:
                        trash.mkdir(exist_ok=True)
                        shutil.move(str(fpath), str(trash / name))
                    except Exception as e:
                        debug_log.warn("showcase", f"trash move failed for {name}: {e}")
            kept = sorted(mine & still_used)
            if kept:
                debug_log.info("showcase", f"delete {sid}: файлы оставлены (нужны другим): {kept}")

            gif_rel = s.get("gif_url", "")
            if gif_rel:
                gif_fpath = GIFFS_DIR / Path(gif_rel).name
                gif_fpath.unlink(missing_ok=True)
            showcase.pop(i)
            save_showcase(showcase)
            return web.json_response({"ok": True})
    return web.json_response({"error": "not found"}, status=404)


async def api_showcase_bulk_delete(request):
    data = await request.json()
    ids = set(data.get("ids", []))
    if not ids:
        return web.json_response({"error": "ids required"}, status=400)
    deleted = 0
    remaining = []
    for s in showcase:
        if s["id"] in ids:
            # Delete media files
            for field in ("source_image", "split_preview", "video_url", "generated_image"):
                path_str = s.get(field, "")
                if path_str and path_str.startswith("/showcase/"):
                    fpath = SHOWCASE_DIR / Path(path_str).name
                    if fpath.exists():
                        fpath.unlink(missing_ok=True)
            for out in (s.get("outputs") or []):
                if out.startswith("/showcase/"):
                    fpath = SHOWCASE_DIR / Path(out).name
                    if fpath.exists():
                        fpath.unlink(missing_ok=True)
            gif_rel = s.get("gif_url", "")
            if gif_rel:
                gif_fpath = GIFFS_DIR / Path(gif_rel).name
                gif_fpath.unlink(missing_ok=True)
            deleted += 1
        else:
            remaining.append(s)
    showcase.clear()
    showcase.extend(remaining)
    save_showcase(showcase)
    debug_log.info("showcase", f"Bulk deleted {deleted} items")
    return web.json_response({"ok": True, "deleted": deleted})


async def api_showcase_bulk_category(request):
    data = await request.json()
    ids = set(data.get("ids", []))
    category = data.get("category", "other")
    action = data.get("action", "add")  # add | remove | set
    if not ids:
        return web.json_response({"error": "ids required"}, status=400)
    updated = 0
    for s in showcase:
        if s["id"] in ids:
            cats = s.get("categories") or []
            if isinstance(s.get("category"), str) and s["category"] and s["category"] not in cats:
                cats.append(s["category"])  # migrate old single value
            if action == "add":
                if category not in cats:
                    cats.append(category)
            elif action == "remove":
                cats = [c for c in cats if c != category]
            elif action == "set":
                cats = [category]
            s["categories"] = cats
            s["category"] = cats[0] if cats else ""
            updated += 1
    save_showcase(showcase)
    debug_log.info("showcase", f"Bulk category '{category}' ({action}) applied to {updated} items")
    return web.json_response({"ok": True, "updated": updated})


async def api_showcase_gif_generate(request):
    """POST /api/showcase/{id}/gif?top_offset=25 — generate GIF for one item."""
    sid = request.match_info["id"]
    item = next((s for s in showcase if s["id"] == sid), None)
    if not item:
        return web.json_response({"error": "not found"}, status=404)
    top_offset = int(request.query.get("top_offset", "25"))
    top_offset = max(0, min(100, top_offset))
    try:
        loop = asyncio.get_event_loop()
        out_path = await loop.run_in_executor(None, make_gif_for_item, item, top_offset)
        gif_url = f"/showcase/giffs/{out_path.name}"
        item["gif_url"] = gif_url
        save_showcase(showcase)
        size_kb = out_path.stat().st_size // 1024
        debug_log.info("gif", f"GIF {sid}: {out_path.name} ({size_kb}KB)")
        return web.json_response({"ok": True, "gif_url": gif_url, "id": sid, "size_kb": size_kb})
    except Exception as e:
        debug_log.error("gif", f"GIF failed for {sid}: {e}")
        return web.json_response({"error": str(e)}, status=500)


async def api_showcase_gif_generate_all(request):
    """POST /api/showcase/gif/generate-all — generate GIFs for all items in background."""
    async def _run_all():
        ok, errors = 0, 0
        for item in list(showcase):
            sid = item["id"]
            try:
                loop = asyncio.get_event_loop()
                out_path = await loop.run_in_executor(None, make_gif_for_item, item)
                item["gif_url"] = f"/showcase/giffs/{out_path.name}"
                ok += 1
            except Exception as e:
                errors += 1
                debug_log.error("gif", f"GIF bulk error {sid}: {e}")
        save_showcase(showcase)
        debug_log.info("gif", f"Bulk GIF done: {ok} ok, {errors} errors")
    asyncio.create_task(_run_all())
    return web.json_response({"ok": True, "queued": len(showcase), "message": "GIF generation started"})


GIF_REGEN_W = 896   # nearest 16-multiple to 900
GIF_REGEN_H = 736   # nearest 16-multiple to 730


async def api_showcase_gif_regen(request):
    """POST /api/showcase/{id}/gif-regen — regenerate at 900x730 via ComfyUI, save as GIF."""
    sid = request.match_info["id"]
    item = next((s for s in showcase if s["id"] == sid), None)
    if not item:
        return web.json_response({"error": "not found"}, status=404)

    data = {}
    try:
        data = await request.json()
    except Exception:
        pass
    worker_url = data.get("worker_url", "").strip()
    worker_auth = data.get("worker_auth", "").strip()

    job_id = f"gif-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    job = {
        "job_id": job_id,
        "template_id": item.get("template_id", "flux2-wan22-i2v"),
        "type": item.get("type", "video"),
        "source": "gif-regen",
        "status": "queued",
        "created_at": time.time(),
        "source_image": item.get("source_image"),
        "image_prompt_positive": item.get("image_prompt_positive", ""),
        "image_prompt_negative": item.get("image_prompt_negative", ""),
        "video_prompt_positive": item.get("video_prompt_positive", ""),
        "video_prompt_negative": item.get("video_prompt_negative", ""),
        "foley_prompt": item.get("foley_prompt", ""),
        "worker_url": worker_url,
        "result": None, "error": None,
        "generation_sec": None, "worker_boot_sec": None, "total_sec": None,
        "gpu": None, "dph": None, "cost_usd": None,
        "_gif_regen": True,
        "_gif_item_id": sid,
        "_gif_w": GIF_REGEN_W,
        "_gif_h": GIF_REGEN_H,
    }
    manager.jobs[job_id] = job

    fields = {
        "worker_url": worker_url,
        "worker_auth": worker_auth,
        "template_id": item.get("template_id", "flux2-wan22-i2v"),
        "source_image": item.get("source_image", ""),
        "image_prompt_positive": item.get("image_prompt_positive", ""),
        "image_prompt_negative": item.get("image_prompt_negative", ""),
        "video_prompt_positive": item.get("video_prompt_positive", ""),
        "video_prompt_negative": item.get("video_prompt_negative", ""),
        "foley_prompt": item.get("foley_prompt", ""),
    }
    asyncio.create_task(_run_gif_regen_job(job, fields, item))
    return web.json_response({"job_id": job_id, "status": "queued"})


async def _run_gif_regen_job(job: Dict, fields: Dict, item: Dict):
    """Run generation at GIF resolution, then build GIF and update showcase item."""
    # Temporarily override target resolution
    orig_w, orig_h = TARGET_W, TARGET_H
    try:
        # Run the standard showcase job with modified resolution
        job["_override_w"] = GIF_REGEN_W
        job["_override_h"] = GIF_REGEN_H
        await _run_showcase_job(job, fields)

        if job["status"] == "done":
            outputs = job.get("result", {}).get("outputs", [])
            # Build GIF from the regenerated outputs
            gif_item = dict(item)
            # Replace media paths with regenerated ones
            for out in outputs:
                if any(out.endswith(ext) for ext in (".mp4", ".webm", ".mov")):
                    gif_item["video_url"] = out
                elif any(out.endswith(ext) for ext in (".png", ".jpg", ".jpeg", ".webp")):
                    gif_item["generated_image"] = out

            gif_path = GIFFS_DIR / f"{item['id']}.gif"
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(None, make_gif_for_item, gif_item)
            item["gif_url"] = f"/showcase/giffs/{item['id']}.gif"
            save_showcase(showcase)
            debug_log.info("gif", f"GIF regen done for {item['id']}: {gif_path}")
    except Exception as e:
        debug_log.error("gif", f"GIF regen failed for {item['id']}: {e}")
        if job["status"] != "error":
            job["status"] = "error"
            job["error"] = str(e)


async def api_job_status(request):
    """GET /api/jobs/{id} — poll job status. V1-compatible response."""
    job_id = request.match_info["id"]
    job = manager.jobs.get(job_id)
    if not job:
        return web.json_response({"error": "not found"}, status=404)
    # Add result_url for app compatibility (app looks for result_url/output_url/url)
    resp = dict(job)
    if resp.get("status") == "done":
        outputs = (resp.get("result") or {}).get("outputs", [])
        if not resp.get("result_url"):
            # Pick first image output as result_url
            for o in outputs:
                if any(o.endswith(ext) for ext in (".png", ".jpg", ".jpeg", ".webp")):
                    resp["result_url"] = o
                    break
            if not resp.get("result_url") and outputs:
                resp["result_url"] = outputs[0]
    return web.json_response(resp)


async def api_jobs_list(request):
    """GET /api/jobs — list recent jobs."""
    jobs = sorted(manager.jobs.values(), key=lambda j: j.get("created_at", 0), reverse=True)
    return web.json_response({"jobs": jobs[:50]})


async def api_showcase_generate(request):
    """Generate showcase preview: upload image + prompts, run on selected worker."""
    reader = await request.multipart()
    fields = {}
    image_path = None

    while True:
        part = await reader.next()
        if part is None:
            break
        if part.name == "image":
            filename = f"sc_{int(time.time())}_{uuid.uuid4().hex[:6]}{Path(part.filename or 'img.png').suffix}"
            image_path = SHOWCASE_DIR / filename
            with open(image_path, "wb") as f:
                while True:
                    chunk = await part.read_chunk()
                    if not chunk:
                        break
                    f.write(chunk)
            # Crop & resize to 9:16 (496x882)
            cropped_name = f"sc_{int(time.time())}_{uuid.uuid4().hex[:6]}.jpg"
            cropped_path = SHOWCASE_DIR / cropped_name
            try:
                crop_and_resize_9_16(image_path, cropped_path)
                debug_log.info("showcase", f"Image cropped to {TARGET_W}x{TARGET_H}: {cropped_name}")
                # Keep original too for reference but use cropped
                fields["source_image_original"] = f"/showcase/{filename}"
                fields["source_image"] = f"/showcase/{cropped_name}"
                image_path = cropped_path
            except Exception as e:
                debug_log.warn("showcase", f"Crop failed, using original: {e}")
                fields["source_image"] = f"/showcase/{filename}"
        else:
            fields[part.name] = (await part.text()).strip()

    # Temporary: log all fields from iOS requests for debugging
    ua_dbg = request.headers.get("User-Agent", "")
    if "GrokMVP" in ua_dbg or "GPUPortalApp" in ua_dbg:
        debug_log.info("ios_fields", f"iOS request fields: {list(fields.keys())} ua={ua_dbg}")

    if not image_path:
        return web.json_response({"error": "image required"}, status=400)
    if not image_path.exists() or image_path.stat().st_size < 100:
        return web.json_response({"error": "image file is empty or too small"}, status=400)

    ua = request.headers.get("User-Agent", "")
    req_source = fields.get("source") or ("ios" if ("GPUPortalApp" in ua or "GrokMVP" in ua) else "portal")

    job_id = f"sc-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    template_id = fields.get("template_id", "flux2-wan22-i2v")
    preset_id = fields.get("preset_id", "") or await _match_preset_by_prompts(fields)  # showcase item used as source template
    tpl = next((t for t in templates if t["id"] == template_id), None)
    sc_type = "img2img" if (tpl and tpl.get("category") == "img2img") else "video"
    # flow/model overrides from portal UI
    flow_override = fields.get("flow", "").strip()   # "img2vid" | "img2img" | "direct_vid"
    img_model_override = fields.get("img_model", "").strip()
    vid_model_override = fields.get("vid_model", "").strip()
    if flow_override == "img2img":
        sc_type = "img2img"
    if not VIDEO_ENABLED:
        # Видео отключено. Тип задачи здесь выводится из списка templates,
        # а id шаблонов каталога там нет — без этой строки любая такая
        # генерация молча уходила в видео.
        sc_type = "img2img"
        flow_override = "img2img"
    job = {
        "job_id": job_id,
        "template_id": template_id,
        "preset_id": preset_id,
        "type": sc_type,
        "flow": flow_override or ("img2img" if sc_type == "img2img" else "img2vid"),
        "img_model": img_model_override or HOTAPI_IMG_MODEL,
        "vid_model": vid_model_override or HOTAPI_VID_MODEL,
        "source": req_source,
        "status": "queued",
        "created_at": time.time(),
        "source_image": fields.get("source_image"),
        "source_image_original": fields.get("source_image_original") or fields.get("source_image"),
        "image_prompt_positive": fields.get("image_prompt_positive", ""),
        "image_prompt_negative": fields.get("image_prompt_negative", ""),
        "video_prompt_positive": fields.get("video_prompt_positive", ""),
        "video_prompt_negative": fields.get("video_prompt_negative", ""),
        "foley_prompt": fields.get("foley_prompt", ""),
        "worker_url": fields.get("worker_url", ""),
        "result": None,
        "error": None,
        "generation_sec": None,
        "worker_boot_sec": None,
        "total_sec": None,
        "gpu": None, "dph": None, "cost_usd": None,
        "_ip": request.headers.get("X-Forwarded-For", request.remote or ""),
        "_uiq": request.headers.get("X-Client-UIQ", "") or fields.get("uiq", ""),
        "_ua": ua,
    }
    manager.jobs[job_id] = job
    asyncio.ensure_future(_mysql_insert_request(job, fields))
    debug_log.info("showcase", f"Showcase generation started: {job_id}")
    event_log.log("job_queued", req_source, f"Job queued: {job_id} template={template_id} type={sc_type}", {"job_id": job_id, "template_id": template_id})

    asyncio.create_task(_run_showcase_job(job, fields))
    return web.json_response({"job_id": job_id, "status": "queued"})


async def _background_gpu_boot():
    """Boot GPU worker in background (non-blocking). Notifies when ready."""
    try:
        if not GPU_BOOT_ENABLED:
            debug_log.info("v2", "GPU boot disabled (GPU_BOOT_ENABLED=0)")
            return
        if manager.worker.status == "ready":
            return
        debug_log.info("v2", "Background GPU boot starting...")
        await tg_notify("🔄 GPU запускается в фоне...")
        t0 = time.time()
        w = await manager.ensure_worker_ready()
        boot_sec = round(time.time() - t0, 1)
        debug_log.info("v2", f"Background GPU boot done: {w.gpu_name} in {boot_sec}s")
        await tg_notify(f"🟢 GPU поднят: {w.gpu_name} за {boot_sec}s — следующие запросы на GPU")
    except Exception as e:
        debug_log.error("v2", f"Background GPU boot failed: {e}")
        await tg_notify(f"❌ GPU boot failed: {e}")


async def _run_showcase_job(job: Dict, fields: Dict):
    """Run showcase generation — uses worker_url if provided, otherwise boots new."""
    t_start = time.time()
    try:
        worker_url = fields.get("worker_url", "").strip()
        worker_auth = fields.get("worker_auth", "").strip()

        if worker_url:
            # Use existing worker directly
            job["status"] = "generating"
            base_url = worker_url.rstrip("/")
            auth_headers = {}
            if worker_auth:
                raw = f"vastai:{worker_auth}".encode()
                token = base64.b64encode(raw).decode()
                auth_headers = {"Authorization": f"Basic {token}", "Content-Type": "application/json", "Accept": "application/json"}
            job["worker_boot_sec"] = 0
        else:
            # ── V2: xAI fallback check BEFORE GPU boot ──
            # ── V2: Smart routing with stalling ──
            # Strategy: if GPU not ready, start hotapi generation + GPU boot in parallel.
            # HotAPI result ready fast (~12-50s) but we stall the response to ~80s total
            # while GPU boots. Once GPU is up, future requests go to GPU directly.
            eta = await estimate_generation_eta()
            sc_type = job.get("type", "video")
            # flow/model overrides from job (set by portal UI dropdowns)
            flow = job.get("flow", "img2img" if sc_type == "img2img" else "img2vid")
            if not VIDEO_ENABLED and flow != "dual":
                flow = "img2img"
            img_model = job.get("img_model", "")
            vid_model = job.get("vid_model", "")
            src_path = SHOWCASE_DIR / Path(job["source_image"]).name

            if HOTAPI_KEY:
                job["status"] = "generating"
                job["method"] = "hotapi"
                debug_log.info("v2", f"hotapi generation: flow={flow}, img_model={img_model or HOTAPI_IMG_MODEL}, vid_model={vid_model or HOTAPI_VID_MODEL}")

                # Start GPU boot in background (non-blocking)
                gpu_boot_task = asyncio.create_task(_background_gpu_boot())

                src_path_for_tg = SHOWCASE_DIR / Path(job["source_image"]).name
                t_gen = time.time()
                gen_result = {}
                gen_file = None
                vid_file = None
                try:
                    # ── Generation: route by flow ──
                    if flow == "dual" or (sc_type == "dual" and flow not in ("img2img", "img2vid", "direct_vid")):
                        # Dual: 2 reference photos → seedream/image-edit
                        img1_rel = job.get("image1") or job.get("source_image", "")
                        img2_rel = job.get("image2") or job.get("scene_image", "")
                        img1_path = SHOWCASE_DIR / Path(img1_rel).name if img1_rel else src_path
                        img2_path = SHOWCASE_DIR / Path(img2_rel).name if img2_rel else src_path
                        gen_result = await hotapi_flow_dual(img1_path, img2_path, job.get("image_prompt_positive", ""), img_model=img_model)
                        gen_sec = round(time.time() - t_gen, 1)
                        gen_file = str(SHOWCASE_DIR / Path(gen_result["generated_image"]).name)
                    elif flow == "img2img":
                        gen_result = await hotapi_flow_img2img(src_path, job.get("image_prompt_positive", ""), img_model=img_model)
                        gen_sec = round(time.time() - t_gen, 1)
                        gen_file = str(SHOWCASE_DIR / Path(gen_result["generated_image"]).name)
                    elif flow == "direct_vid":
                        # Skip stage 1: go straight to video from source image
                        ts2, uid2 = int(time.time()), uuid.uuid4().hex[:6]
                        vid_path2 = SHOWCASE_DIR / f"hotapi_vid_{ts2}_{uid2}.mp4"
                        _vid_prompt_raw = job.get("video_prompt_positive", "") or job.get("image_prompt_positive", "")
                        _vid_prompt_lang = _video_prompt_with_lang(_vid_prompt_raw, job.get("_ip", ""))
                        elapsed_v, url_v = await hotapi_1img_to_video(src_path, vid_path2, _vid_prompt_lang, model=vid_model)
                        _xai_resize_video(vid_path2)
                        gen_sec = round(time.time() - t_gen, 1)
                        gen_result = {
                            "video_url": f"/showcase/{vid_path2.name}",
                            "outputs": [f"/showcase/{vid_path2.name}"],
                            "generation_sec": gen_sec, "method": "hotapi", "cost_usd": round(0.05 * 6, 4),
                        }
                        vid_file = str(vid_path2)
                    else:
                        # img2vid (default): stage1 img2img + stage2 video
                        _vid_prompt_raw = job.get("video_prompt_positive", "")
                        _vid_prompt_lang = _video_prompt_with_lang(_vid_prompt_raw, job.get("_ip", ""))
                        gen_result = await hotapi_flow_video(src_path, job.get("image_prompt_positive", ""), _vid_prompt_lang, img_model=img_model, vid_model=vid_model)
                        gen_sec = round(time.time() - t_gen, 1)
                        gen_file = str(SHOWCASE_DIR / Path(gen_result["generated_image"]).name)
                        vid_file = str(SHOWCASE_DIR / Path(gen_result["video_url"]).name)
                    # Dynamic stall: only if GPU boot is enabled and generation was fast
                    stall_extra = XAI_STALL_EXTRA_VID if sc_type == 'video' else XAI_STALL_EXTRA_IMG
                    stall_target = gen_sec + stall_extra
                    # Skip stall if: portal/admin request, GPU disabled, or generation already took long enough
                    max_expected = 20 if sc_type != 'video' else 60
                    if job.get("source") in ("portal", "admin") or not GPU_BOOT_ENABLED or gen_sec >= max_expected:
                        stall_target = 0  # no stall needed
                        debug_log.info('hotapi', f'Done in {gen_sec}s, no stall (source={job.get("source")}, GPU_BOOT={GPU_BOOT_ENABLED})')
                    else:
                        debug_log.info('hotapi', f'Done in {gen_sec}s, stalling +{stall_extra}s = {stall_target}s target')

                    # Stall: wait extra time, checking GPU every interval
                    elapsed = time.time() - t_start
                    while elapsed < stall_target:
                        remaining = stall_target - elapsed
                        wait = min(XAI_STALL_INTERVAL_SEC, remaining)
                        if wait <= 1:
                            break
                        job['status'] = 'generating'
                        debug_log.info('hotapi', f'Stalling: {elapsed:.0f}s/{stall_target:.0f}s, wait {wait:.0f}s')
                        await asyncio.sleep(wait)
                        elapsed = time.time() - t_start
                        if manager.worker.status == 'ready':
                            debug_log.info('hotapi', f'GPU ready during stall at {elapsed:.0f}s!')
                            await tg_notify(f'🟢 GPU поднят ({elapsed:.0f}s) — следующие запросы на GPU')
                            break

                    asyncio.ensure_future(_mysql_update_grok(job["job_id"], {"method": "hotapi", "gen_sec": gen_sec, "outputs": gen_result.get("outputs", [])}))
                    job["generation_sec"] = gen_sec
                    job["total_sec"] = round(time.time() - t_start, 1)
                    job["status"] = "done"
                    for key in ("generated_image", "video_url"):
                        if key in gen_result and gen_result[key]:
                            job[key] = gen_result[key]
                    # Build result.outputs
                    outputs = []
                    if job.get("generated_image"): outputs.append(job["generated_image"])
                    if job.get("video_url"): outputs.append(job["video_url"])
                    if job.get("generated_image"):
                        gen_path = SHOWCASE_DIR / Path(job["generated_image"]).name
                        split_name = f"{job['job_id']}_split.jpg"
                        split_path = SHOWCASE_DIR / split_name
                        try:
                            make_split_preview(src_path, gen_path, split_path)
                            job["split_preview"] = f"/showcase/{split_name}"
                        except Exception:
                            pass
                    job["result"] = {"outputs": outputs, "split_preview": job.get("split_preview", "")}
                    job["cost_usd"] = gen_result.get("cost_usd", 0.02)
                    _record_history(job)
                except Exception as e:
                    job["status"] = "error"
                    job["error"] = f"hotapi generation failed: {e}"
                    job["total_sec"] = round(time.time() - t_start, 1)
                    debug_log.error("hotapi", f"generation error: {e}")
                # Send result to TG: for video flow skip intermediate image
                if vid_file:
                    result_files = [vid_file]
                else:
                    result_files = [f for f in [gen_file] if f]
                await tg_notify_result(job, [str(src_path_for_tg)], result_files, method="hotapi")
                # Save and return
                _save_showcase_item(job)
                metrics.record_job(job)
                manager._record_job_in_session(job)
                _record_gen_stats(job)
                src = job.get("source", "portal")
                if job["status"] == "done":
                    event_log.log("job_done", src, f"Job done: {job['job_id']} gen={job.get('generation_sec')}s total={job.get('total_sec')}s method=hotapi", {"job_id": job["job_id"], "gen_sec": job.get("generation_sec"), "cost": job.get("cost_usd")})
                else:
                    event_log.log("job_error", src, f"Job error: {job['job_id']}: {job.get('error','?')}", {"job_id": job["job_id"], "error": job.get("error")})
                return
            # ── GPU is ready — use it directly ──
            debug_log.info("v2", f"GPU ready (ETA={eta:.0f}s), using GPU directly")

            job["status"] = "starting_worker"
            t_boot = time.time()
            w = await manager.ensure_worker_ready()
            job["worker_boot_sec"] = round(time.time() - t_boot, 1)
            job["gpu"] = w.gpu_name
            job["dph"] = w.dph_total
            base_url = w.base_url
            auth_headers = w.auth_headers
            job["status"] = "generating"

        # Load workflow — auto-downgrade foley on GPUs with <180GB VRAM
        template_id = job["template_id"]
        if template_id == "flux2-wan22-i2v-foley":
            gpu_ram = 0
            try:
                async with manager.session.get(f"{CONSOLE_BASE}/api/v0/instances/", headers=manager.vast_headers, timeout=aiohttp.ClientTimeout(total=10)) as r:
                    inst_data = await r.json()
                for inst in (inst_data if isinstance(inst_data, list) else inst_data.get("instances", [])):
                    if inst.get("id") == manager.worker.instance_id:
                        gpu_ram = inst.get("gpu_ram", 0)
                        break
            except Exception:
                pass
            if gpu_ram and gpu_ram < 180000:
                template_id = "flux2-wan22-i2v"
                job["template_id"] = template_id
                debug_log.info("showcase", f"Auto-downgrade: foley→no-foley (GPU RAM {gpu_ram//1000}GB < 180GB)")
                await tg_notify(f"⚠️ Auto-downgrade: foley→video only (GPU {gpu_ram//1000}GB < 180GB, OOM risk)")

        tpl = next((t for t in templates if t["id"] == template_id), None)
        workflow_json = {}
        if tpl and tpl.get("workflow_file"):
            wf_path = WORKFLOWS_DIR / tpl["workflow_file"]
            if wf_path.exists():
                workflow_json = json.loads(wf_path.read_text())

        # Patch prompts into workflow
        if workflow_json:
            wf = copy.deepcopy(workflow_json)
            # Image prompt (Flux 2 stage — node 68:6)
            img_prompt = job.get("image_prompt_positive", "")
            if img_prompt and "68:6" in wf:
                wf["68:6"]["inputs"]["text"] = img_prompt

            # Video prompt positive (Wan stage — node 132:93)
            vid_prompt = job.get("video_prompt_positive", "")
            if vid_prompt and "132:93" in wf:
                wf["132:93"]["inputs"]["text"] = vid_prompt

            # Video prompt negative (Wan stage — node 132:89)
            vid_neg = job.get("video_prompt_negative", "")
            if vid_neg and "132:89" in wf:
                wf["132:89"]["inputs"]["text"] = vid_neg

            # Foley audio prompt (HunyuanFoley stage — node F3)
            foley_prompt = job.get("foley_prompt", "")
            if foley_prompt and "F3" in wf:
                wf["F3"]["inputs"]["prompt"] = foley_prompt

            # Sync foley duration with video length to prevent tensor mismatch
            if "F3" in wf and "132:98" in wf:
                video_length = wf["132:98"]["inputs"].get("length", 81)
                foley_fps = wf["F3"]["inputs"].get("fps", 16.0)
                # Video duration = frames / fps, round down to avoid overrun
                sync_duration = round((video_length / foley_fps) - 0.1, 1)
                wf["F3"]["inputs"]["duration"] = sync_duration
                debug_log.info("showcase", f"Foley duration synced: {video_length} frames / {foley_fps} fps → {sync_duration}s")

            # Randomize foley seed
            if "F3" in wf and "inputs" in wf["F3"]:
                if "seed" in wf["F3"]["inputs"]:
                    wf["F3"]["inputs"]["seed"] = random.randint(1, 2**63 - 1)

            # Force resolution in Wan I2V node (132:98) — allow override for GIF regen
            _ow = job.get("_override_w", TARGET_W)
            _oh = job.get("_override_h", TARGET_H)
            if "132:98" in wf and "inputs" in wf["132:98"]:
                wf["132:98"]["inputs"]["width"] = _ow
                wf["132:98"]["inputs"]["height"] = _oh
            # Also patch standalone Wan node (129:98) for wan22 templates
            if "129:98" in wf and "inputs" in wf["129:98"]:
                wf["129:98"]["inputs"]["width"] = _ow
                wf["129:98"]["inputs"]["height"] = _oh

            # Force Flux 2 output resolution to 9:16 (496×882)
            # Override width/height refs in EmptyFlux2LatentImage and Flux2Scheduler
            # (replaces ["68:72", 0] reference with direct integer value)
            for flux_node in ["68:47", "68:48"]:
                if flux_node in wf and "inputs" in wf[flux_node]:
                    wf[flux_node]["inputs"]["width"] = _ow
                    wf[flux_node]["inputs"]["height"] = _oh

            # Randomize seeds
            for key in ["68:25", "132:86"]:
                if key in wf and "inputs" in wf[key]:
                    if "noise_seed" in wf[key]["inputs"]:
                        wf[key]["inputs"]["noise_seed"] = random.randint(1, 2**63 - 1)

            workflow_json = wf

        # Build public URL for the source image (worker will download it)
        src_path = SHOWCASE_DIR / Path(job["source_image"]).name
        src_filename = src_path.name
        # Our server's public URL — worker fetches image from here
        server_ip = os.getenv("SERVER_IP", "")
        if not server_ip:
            try:
                s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
                s.connect(("8.8.8.8", 80))
                server_ip = s.getsockname()[0]
                s.close()
            except Exception:
                server_ip = "127.0.0.1"
        image_public_url = f"http://{server_ip}:8899/showcase/{src_filename}"
        debug_log.info("showcase", f"Image public URL: {image_public_url}")

        # Patch ALL LoadImage nodes with the public URL (same as pupupu.py does)
        uploaded_name = image_public_url
        if workflow_json:
            for node_id, node in workflow_json.items():
                if node.get("class_type") == "LoadImage":
                    node["inputs"]["image"] = image_public_url
                    debug_log.info("showcase", f"Patched LoadImage node {node_id} → URL")

        payload = {
            "input": {
                "request_id": job["job_id"],
                "workflow_json": workflow_json,
                "ws_timeout": 600,
            },
            "ws_timeout": 600,
            "timeout": 600,
        }

        # Debug info
        job["debug"] = {
            "worker_url": base_url,
            "health_url": f"{base_url}{HEALTH_PATH}",
            "generate_url": f"{base_url}{GENERATE_SYNC_PATH}",
            "has_auth": bool(auth_headers),
            "image_url": uploaded_name,
            "workflow_nodes": len(workflow_json) if workflow_json else 0,
            "image_size_kb": round(src_path.stat().st_size / 1024, 1),
            "image_dimensions": f"{TARGET_W}x{TARGET_H}",
            "steps": [],
        }

        # Step 1: Health check — poll until ready (up to 15 min)
        job["debug"]["steps"].append({"step": "health_check_wait", "ts": time.time()})
        job["status"] = "waiting_health"
        health_start = time.time()
        health_timeout = HEALTH_MAX_WAIT_SEC  # 900s = 15 min
        last_health_err = None
        attempt = 0
        debug_log.info("showcase", f"Waiting for health: {base_url}{HEALTH_PATH} (max {health_timeout}s)")

        while time.time() - health_start < health_timeout:
            attempt += 1
            try:
                async with manager.session.get(
                    f"{base_url}{HEALTH_PATH}", headers=auth_headers, timeout=aiohttp.ClientTimeout(total=15)
                ) as hr:
                    health_text = await hr.text()
                    job["debug"]["health_status"] = hr.status
                    job["debug"]["health_body"] = health_text[:500]
                    job["debug"]["health_attempts"] = attempt
                    job["debug"]["health_wait_sec"] = round(time.time() - health_start, 1)
                    if hr.status == 200:
                        try:
                            health_data = json.loads(health_text)
                            if health_data.get("status") == "healthy":
                                debug_log.info("showcase", f"Health OK after {attempt} attempts ({job['debug']['health_wait_sec']}s)")
                                break
                        except json.JSONDecodeError:
                            pass
                    last_health_err = f"HTTP {hr.status}"
            except Exception as e:
                last_health_err = str(e)
                job["debug"]["health_attempts"] = attempt
                job["debug"]["health_wait_sec"] = round(time.time() - health_start, 1)

            await asyncio.sleep(5)
        else:
            job["debug"]["health_error"] = f"Timeout after {attempt} attempts ({round(time.time()-health_start)}s). Last: {last_health_err}"
            debug_log.error("showcase", f"Health timeout: {last_health_err}")
            raise RuntimeError(f"Worker not ready after {round(time.time()-health_start)}s. Last error: {last_health_err}")

        job["debug"]["steps"].append({"step": "health_ok", "ts": time.time(), "attempts": attempt})
        job["status"] = "generating"

        # Step 2: Generate
        job["debug"]["steps"].append({"step": "generate", "ts": time.time()})
        t_gen = time.time()

        # Resolve ComfyUI URL early (needed for direct ComfyUI path)
        comfy_url = fields.get("comfy_url", "").strip()
        if not comfy_url and not worker_url and manager.worker.comfy_base_url:
            comfy_url = manager.worker.comfy_base_url
        if not comfy_url:
            comfy_url = base_url  # fallback
        # Use direct ComfyUI API for foley workflows (runner has 60s WS timeout bug)
        use_direct_comfy = job.get("template_id") == "flux2-wan22-i2v-foley" and comfy_url
        if use_direct_comfy:
            debug_log.info("showcase", f"Direct ComfyUI mode for foley: {comfy_url}")
            # Upload source image to ComfyUI
            src_filename = Path(job["source_image"]).name
            async with manager.session.post(
                f"{comfy_url}/upload/image",
                headers={k: v for k, v in auth_headers.items() if k != "Content-Type"},
                data={"subfolder": "", "type": "input"},
                timeout=aiohttp.ClientTimeout(total=30),
            ) as ur:
                form_data = aiohttp.FormData()
                form_data.add_field("image", open(SHOWCASE_DIR / src_filename, "rb"), filename=src_filename)
                form_data.add_field("subfolder", "")
                form_data.add_field("type", "input")
            async with manager.session.post(
                f"{comfy_url}/upload/image",
                headers={k: v for k, v in auth_headers.items() if k != "Content-Type"},
                data=form_data,
                timeout=aiohttp.ClientTimeout(total=30),
            ) as ur:
                upload_r = await ur.json()
                uploaded_name_comfy = upload_r.get("name", src_filename)
                debug_log.info("showcase", f"Uploaded to ComfyUI: {uploaded_name_comfy}")

            # Patch LoadImage nodes with uploaded filename (not URL)
            for node_id, node in workflow_json.items():
                if node.get("class_type") == "LoadImage":
                    node["inputs"]["image"] = uploaded_name_comfy

            # Submit to ComfyUI /prompt
            prompt_payload = {"prompt": workflow_json, "client_id": job["job_id"]}
            async with manager.session.post(
                f"{comfy_url}/prompt",
                headers=auth_headers, json=prompt_payload,
                timeout=aiohttp.ClientTimeout(total=30),
            ) as pr:
                prompt_resp = await pr.json()
                if pr.status >= 400:
                    raise RuntimeError(f"ComfyUI prompt failed: {json.dumps(prompt_resp)[:500]}")
                prompt_id = prompt_resp.get("prompt_id", "")
                debug_log.info("showcase", f"ComfyUI prompt submitted: {prompt_id}")

            # Poll /history until complete (up to 15 min)
            deadline = time.time() + 900
            result = {"output": [], "status": "pending"}
            while time.time() < deadline:
                await asyncio.sleep(10)
                async with manager.session.get(
                    f"{comfy_url}/history/{prompt_id}",
                    headers=auth_headers,
                    timeout=aiohttp.ClientTimeout(total=10),
                ) as hr:
                    hist = await hr.json()
                if prompt_id in hist:
                    entry = hist[prompt_id]
                    status_info = entry.get("status", {})
                    if status_info.get("completed", False) or status_info.get("status_str") == "success":
                        # Extract outputs
                        outputs_raw = entry.get("outputs", {})
                        output_list = []
                        for node_id, node_out in outputs_raw.items():
                            for key, files in node_out.items():
                                if isinstance(files, list):
                                    for f in files:
                                        if isinstance(f, dict) and f.get("filename"):
                                            output_list.append(f)
                        result = {"output": output_list, "status": "completed", "message": "Processing complete."}
                        debug_log.info("showcase", f"ComfyUI foley done: {len(output_list)} outputs in {time.time()-t_gen:.1f}s")
                        break
                    elif status_info.get("status_str") == "error":
                        raise RuntimeError(f"ComfyUI foley failed: {json.dumps(status_info)[:300]}")
            else:
                raise RuntimeError("ComfyUI foley timeout (15 min)")

            job["debug"]["generate_status"] = 200
            job["debug"]["generate_body_preview"] = json.dumps(result)[:1000]
        else:
            # Standard runner path
            gen_url = f"{base_url}{GENERATE_SYNC_PATH}"
            debug_log.info("showcase", f"POST {gen_url} (workflow nodes: {len(workflow_json)}, image: {job['debug']['image_size_kb']}KB)")
            try:
                async with manager.session.post(
                    gen_url,
                    headers=auth_headers,
                    json=payload,
                    timeout=aiohttp.ClientTimeout(total=REQUEST_TIMEOUT_SEC),
                ) as r:
                    raw_text = await r.text()
                    job["debug"]["generate_status"] = r.status
                    job["debug"]["generate_content_type"] = r.headers.get("Content-Type", "")
                    job["debug"]["generate_body_preview"] = raw_text[:1000]

                    if r.status >= 400:
                        raise RuntimeError(f"Generate failed: HTTP {r.status}: {raw_text[:500]}")

                    try:
                        result = json.loads(raw_text)
                    except json.JSONDecodeError:
                        raise RuntimeError(f"Generate returned non-JSON (content-type: {r.headers.get('Content-Type')}): {raw_text[:500]}")
            except aiohttp.ClientError as e:
                job["debug"]["generate_error"] = str(e)
                debug_log.error("showcase", f"Generate request failed: {e}")
                raise RuntimeError(f"Generate request failed: {e}")

        job["generation_sec"] = round(time.time() - t_gen, 1)
        job["debug"]["steps"].append({"step": "generate_done", "ts": time.time(), "sec": job["generation_sec"]})

        # Download outputs
        # Determine comfy URL — from field, or from worker, or try to find from vast instances
        comfy_url = fields.get("comfy_url", "").strip()
        if not comfy_url and not worker_url and manager.worker.comfy_base_url:
            comfy_url = manager.worker.comfy_base_url
        if not comfy_url:
            # Try to find comfy port from vast instances API
            try:
                async with manager.session.get(
                    f"{CONSOLE_BASE}/api/v0/instances/", headers=manager.vast_headers
                ) as r:
                    inst_data = await r.json()
                instances = inst_data if isinstance(inst_data, list) else inst_data.get("instances", inst_data.get("results", []))
                # Find instance matching our worker IP
                worker_ip = base_url.split("://")[1].split(":")[0] if "://" in base_url else ""
                for inst in instances:
                    inst_ip = str(inst.get("public_ipaddr") or "")
                    if inst_ip == worker_ip:
                        ports = inst.get("ports") or {}
                        comfy_rows = ports.get(f"{VAST_EXTERNAL_COMFY_PORT}/tcp", [])
                        if comfy_rows:
                            comfy_port = int(comfy_rows[0]["HostPort"])
                            comfy_url = f"http://{inst_ip}:{comfy_port}"
                            debug_log.info("showcase", f"Found ComfyUI port from vast: {comfy_url}")
                            break
            except Exception as e:
                debug_log.warn("showcase", f"Could not find comfy port from vast: {e}")
        if not comfy_url:
            comfy_url = base_url  # fallback — use same URL
        debug_log.info("showcase", f"ComfyUI URL for downloads: {comfy_url}")

        outputs = result.get("output", [])
        downloaded = []
        if isinstance(outputs, list):
            for item in outputs:
                fname = item.get("filename", "")
                if not fname:
                    continue
                subfolder = item.get("subfolder", "")
                type_ = item.get("type", "output")
                params = urlencode({"filename": fname, "subfolder": subfolder, "type": type_})
                url = f"{comfy_url}/view?{params}"
                dst_name = f"{job['job_id']}_{fname}"
                dst = SHOWCASE_DIR / dst_name
                async with manager.session.get(url, headers=auth_headers) as dr:
                    dst.write_bytes(await dr.read())
                downloaded.append(f"/showcase/{dst_name}")

        # Build split preview: left=source, right=generated
        split_preview_url = None
        gen_image_path = None
        for d_path in downloaded:
            local = SHOWCASE_DIR / Path(d_path).name
            if local.suffix.lower() in (".png", ".jpg", ".jpeg", ".webp") and local.exists():
                gen_image_path = local
                break
        if gen_image_path and src_path.exists():
            try:
                split_name = f"{job['job_id']}_split.jpg"
                split_path = SHOWCASE_DIR / split_name
                make_split_preview(src_path, gen_image_path, split_path)
                split_preview_url = f"/showcase/{split_name}"
                debug_log.info("showcase", f"Split preview created: {split_name}")
            except Exception as e:
                debug_log.warn("showcase", f"Split preview failed: {e}")

        job["status"] = "done"
        job["total_sec"] = round(time.time() - t_start, 1)
        job["result"] = {"outputs": downloaded, "split_preview": split_preview_url}
        # Extract individual file fields from outputs
        for d_path in downloaded:
            ext = Path(d_path).suffix.lower()
            if ext in (".png", ".jpg", ".jpeg", ".webp") and not job.get("generated_image"):
                job["generated_image"] = d_path
            elif ext in (".mp4", ".mov", ".webm") and not job.get("video_url"):
                job["video_url"] = d_path
            elif ext in (".flac", ".wav", ".mp3", ".ogg") and not job.get("audio_url"):
                job["audio_url"] = d_path
        if split_preview_url:
            job["split_preview"] = split_preview_url
        if manager.worker.dph_total and job["total_sec"]:
            job["cost_usd"] = round(manager.worker.dph_total * job["total_sec"] / 3600, 4)

        if manager.worker.last_used_at:
            manager.worker.last_used_at = time.time()
            manager._start_idle_timer()

        debug_log.info("showcase", f"Showcase {job['job_id']} done: {len(downloaded)} outputs in {job['total_sec']}s")

    except Exception as e:
        job["status"] = "error"
        job["error"] = str(e)
        job["total_sec"] = round(time.time() - t_start, 1)
        debug_log.error("showcase", f"Showcase {job['job_id']} failed: {e}")

    metrics.record_job(job)
    manager._record_job_in_session(job)
    _record_gen_stats(job)
    src = job.get("source", "portal")
    if job["status"] == "done":
        event_log.log("job_done", src, f"Job done: {job['job_id']} template={job.get('template_id')} gen={job.get('generation_sec')}s cost=${job.get('cost_usd')}", {"job_id": job["job_id"], "template_id": job.get("template_id"), "gen_sec": job.get("generation_sec"), "cost": job.get("cost_usd")})
    else:
        event_log.log("job_error", src, f"Job error: {job['job_id']}: {job.get('error','?')}", {"job_id": job["job_id"], "error": job.get("error")})
    # One result notification (GPU path): for video type skip intermediate image
    src_file = str(SHOWCASE_DIR / Path(job["source_image"]).name) if job.get("source_image") else None
    result_vids = [str(SHOWCASE_DIR / Path(job["video_url"]).name)] if job.get("video_url") else []
    if result_vids:
        result_files_tg = result_vids
    else:
        result_files_tg = [str(SHOWCASE_DIR / Path(job["generated_image"]).name)] if job.get("generated_image") else []
    asyncio.create_task(tg_notify_result(job, [src_file] if src_file else [], result_files_tg, method="gpu"))


# ──────────── T2V Generate ────────────

async def api_t2v_generate(request):
    """POST /api/t2v/generate — JSON body: {text_prompt, worker_url, worker_auth, comfy_url, negative_prompt}"""
    data = await request.json()
    text_prompt = data.get("text_prompt", "").strip()
    if not text_prompt:
        return web.json_response({"error": "text_prompt required"}, status=400)
    worker_url = data.get("worker_url", "").strip()
    worker_auth = data.get("worker_auth", "").strip()
    comfy_url = data.get("comfy_url", "").strip()
    negative_prompt = data.get("negative_prompt", "").strip()

    job_id = f"t2v-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    job = {
        "job_id": job_id,
        "template_id": "wan22-t2v",
        "type": "video",
        "source": "portal",
        "status": "queued",
        "created_at": time.time(),
        "source_image": None,
        "video_prompt_positive": text_prompt,
        "video_prompt_negative": negative_prompt,
        "worker_url": worker_url,
        "result": None, "error": None,
        "generation_sec": None, "worker_boot_sec": None, "total_sec": None,
        "gpu": None, "dph": None, "cost_usd": None,
    }
    manager.jobs[job_id] = job
    asyncio.create_task(_run_t2v_job(job, worker_url, worker_auth, comfy_url))
    return web.json_response({"job_id": job_id, "status": "queued"})


async def _run_t2v_job(job: Dict, worker_url: str, worker_auth: str, comfy_url_override: str = ""):
    """Run Wan 2.2 T2V job on a worker."""
    t_start = time.time()
    try:
        if worker_url:
            job["status"] = "generating"
            base_url = worker_url.rstrip("/")
            auth_headers = {}
            if worker_auth:
                raw = f"vastai:{worker_auth}".encode()
                token = base64.b64encode(raw).decode()
                auth_headers = {"Authorization": f"Basic {token}", "Content-Type": "application/json", "Accept": "application/json"}
            job["worker_boot_sec"] = 0
        else:
            job["status"] = "starting_worker"
            t_boot = time.time()
            w = await manager.ensure_worker_ready()
            job["worker_boot_sec"] = round(time.time() - t_boot, 1)
            job["gpu"] = w.gpu_name
            job["dph"] = w.dph_total
            base_url = w.base_url
            auth_headers = w.auth_headers
            job["status"] = "generating"

        # Load T2V workflow
        wf_path = WORKFLOWS_DIR / "wan22_t2v.json"
        workflow_json = json.loads(wf_path.read_text())
        wf = copy.deepcopy(workflow_json)

        # Patch positive prompt → node 129:93
        text_prompt = job.get("video_prompt_positive", "")
        if text_prompt:
            wf["129:93"]["inputs"]["text"] = text_prompt

        # Patch negative prompt → node 129:89
        neg_prompt = job.get("video_prompt_negative", "")
        if neg_prompt:
            wf["129:89"]["inputs"]["text"] = neg_prompt

        # Randomize seed → node 129:86
        wf["129:86"]["inputs"]["noise_seed"] = random.randint(1, 2**63 - 1)

        payload = {
            "input": {
                "request_id": job["job_id"],
                "workflow_json": wf,
                "ws_timeout": 600,
            },
            "ws_timeout": 600,
            "timeout": 600,
        }

        job["debug"] = {
            "worker_url": base_url,
            "workflow_nodes": len(wf),
            "steps": [],
        }

        # Health check
        job["status"] = "waiting_health"
        health_start = time.time()
        attempt = 0
        last_err = None
        while time.time() - health_start < HEALTH_MAX_WAIT_SEC:
            attempt += 1
            try:
                async with manager.session.get(
                    f"{base_url}{HEALTH_PATH}", headers=auth_headers, timeout=aiohttp.ClientTimeout(total=15)
                ) as hr:
                    if hr.status == 200:
                        hd = await hr.json()
                        if hd.get("status") == "healthy":
                            break
                    last_err = f"HTTP {hr.status}"
            except Exception as e:
                last_err = str(e)
            await asyncio.sleep(5)
        else:
            raise RuntimeError(f"Worker not ready after {round(time.time()-health_start)}s. Last: {last_err}")

        job["status"] = "generating"
        t_gen = time.time()
        async with manager.session.post(
            f"{base_url}{GENERATE_SYNC_PATH}",
            headers=auth_headers,
            json=payload,
            timeout=aiohttp.ClientTimeout(total=REQUEST_TIMEOUT_SEC),
        ) as r:
            raw_text = await r.text()
            if r.status >= 400:
                raise RuntimeError(f"Generate failed: HTTP {r.status}: {raw_text[:500]}")
            result = json.loads(raw_text)

        job["generation_sec"] = round(time.time() - t_gen, 1)

        # Find ComfyUI URL for downloads (port 8188, NOT the API wrapper port 8288)
        comfy_url = comfy_url_override or None
        if not comfy_url and not worker_url and manager.worker.comfy_base_url:
            comfy_url = manager.worker.comfy_base_url
        if not comfy_url:
            # Derive from worker_url by querying vast instances for the ComfyUI port
            worker_ip = base_url.split("://")[1].split(":")[0] if "://" in base_url else ""
            try:
                async with manager.session.get(
                    f"{CONSOLE_BASE}/api/v0/instances/", headers=manager.vast_headers
                ) as r:
                    inst_data = await r.json()
                instances = inst_data if isinstance(inst_data, list) else inst_data.get("instances", inst_data.get("results", []))
                for inst in instances:
                    if str(inst.get("public_ipaddr") or "") == worker_ip:
                        ports = inst.get("ports") or {}
                        comfy_rows = ports.get(f"{VAST_EXTERNAL_COMFY_PORT}/tcp", [])
                        if comfy_rows:
                            comfy_url = f"http://{worker_ip}:{int(comfy_rows[0]['HostPort'])}"
                            debug_log.info("t2v", f"Discovered ComfyUI URL: {comfy_url}")
                            break
            except Exception as e:
                debug_log.warn("t2v", f"Could not find ComfyUI port: {e}")
        if not comfy_url:
            comfy_url = base_url  # last resort fallback

        # Download outputs
        outputs = result.get("output", [])
        downloaded = []
        if isinstance(outputs, list):
            for item in outputs:
                fname = item.get("filename", "")
                if not fname:
                    continue
                subfolder = item.get("subfolder", "")
                type_ = item.get("type", "output")
                params = urlencode({"filename": fname, "subfolder": subfolder, "type": type_})
                url = f"{comfy_url}/view?{params}"
                dst_name = f"{job['job_id']}_{fname}"
                dst = SHOWCASE_DIR / dst_name
                async with manager.session.get(url, headers=auth_headers) as dr:
                    dst.write_bytes(await dr.read())
                downloaded.append(f"/showcase/{dst_name}")

        job["status"] = "done"
        job["total_sec"] = round(time.time() - t_start, 1)
        job["result"] = {"outputs": downloaded}
        if manager.worker.dph_total and job["total_sec"]:
            job["cost_usd"] = round(manager.worker.dph_total * job["total_sec"] / 3600, 4)

        debug_log.info("t2v", f"T2V {job['job_id']} done: {len(downloaded)} outputs in {job['total_sec']}s")

    except Exception as e:
        job["status"] = "error"
        job["error"] = str(e)
        job["total_sec"] = round(time.time() - t_start, 1)
        debug_log.error("t2v", f"T2V {job['job_id']} failed: {e}")

    metrics.record_job(job)
    manager._record_job_in_session(job)


# ──────────── Auto Creator API ────────────

# ──────────── Dual Image Showcase API ────────────

async def api_dual_list(request):
    page = int(request.query.get("page", "1"))
    per_page = int(request.query.get("per_page", "40"))
    items = sorted(dual_showcase, key=lambda s: s.get("likes", 0), reverse=True)
    total = len(items)
    start = (page - 1) * per_page
    page_items = items[start:start + per_page]
    light = []
    for s in page_items:
        light.append({
            "id": s.get("id"), "name": s.get("name"),
            "image1": s.get("image1"), "image2": s.get("image2"),
            "result_image": s.get("result_image"),
            "prompt": s.get("prompt"), "likes": s.get("likes", 0),
            "description": s.get("description"),
            "created_at": s.get("created_at"),
        })
    return web.json_response({"items": light, "total": total, "page": page, "pages": (total + per_page - 1) // per_page})


async def api_dual_get(request):
    sid = request.match_info["id"]
    item = next((s for s in dual_showcase if s["id"] == sid), None)
    if not item:
        return web.json_response({"error": "not found"}, status=404)
    return web.json_response(item)


async def api_dual_save(request):
    """Save dual item into main showcase with type=dual."""
    data = await request.json()
    data["type"] = "dual"
    data["categories"] = data.get("categories", ["dual"])
    if "dual" not in data["categories"]:
        data["categories"].append("dual")
    data["category"] = "dual"
    sid = data.get("id")
    if not sid:
        return web.json_response({"error": "id required"}, status=400)
    # Save into main showcase
    for i, s in enumerate(showcase):
        if s["id"] == sid:
            showcase[i].update(data)
            save_showcase(showcase)
            return web.json_response(showcase[i])
    showcase.append(data)
    save_showcase(showcase)
    return web.json_response(data, status=201)


async def api_dual_delete(request):
    sid = request.match_info["id"]
    for i, s in enumerate(dual_showcase):
        if s["id"] == sid:
            dual_showcase.pop(i)
            save_dual_showcase(dual_showcase)
            return web.json_response({"ok": True})
    return web.json_response({"error": "not found"}, status=404)


async def api_dual_bulk_delete(request):
    data = await request.json()
    ids = set(data.get("ids", []))
    remaining = [s for s in dual_showcase if s["id"] not in ids]
    deleted = len(dual_showcase) - len(remaining)
    dual_showcase.clear()
    dual_showcase.extend(remaining)
    save_dual_showcase(dual_showcase)
    return web.json_response({"ok": True, "deleted": deleted})


async def api_dual_generate(request):
    """Generate from 2 images: upload image1 + image2 + prompt."""
    reader = await request.multipart()
    fields = {}
    img1_path = img2_path = None

    while True:
        part = await reader.next()
        if part is None:
            break
        if part.name == "image1":
            fname = f"dual1_{int(time.time())}_{uuid.uuid4().hex[:6]}{Path(part.filename or 'a.png').suffix}"
            img1_path = SHOWCASE_DIR / fname
            with open(img1_path, "wb") as f:
                while True:
                    chunk = await part.read_chunk()
                    if not chunk:
                        break
                    f.write(chunk)
            fields["image1"] = f"/showcase/{fname}"
        elif part.name == "image2":
            fname = f"dual2_{int(time.time())}_{uuid.uuid4().hex[:6]}{Path(part.filename or 'b.png').suffix}"
            img2_path = SHOWCASE_DIR / fname
            with open(img2_path, "wb") as f:
                while True:
                    chunk = await part.read_chunk()
                    if not chunk:
                        break
                    f.write(chunk)
            fields["image2"] = f"/showcase/{fname}"
        else:
            fields[part.name] = (await part.text()).strip()

    if not img1_path or not img2_path:
        return web.json_response({"error": "both image1 and image2 required"}, status=400)

    # Crop both to 9:16
    for n, path in [("1", img1_path), ("2", img2_path)]:
        try:
            cropped = SHOWCASE_DIR / f"dual{n}_{int(time.time())}_{uuid.uuid4().hex[:4]}.jpg"
            crop_and_resize_9_16(path, cropped)
            fields[f"image{n}"] = f"/showcase/{cropped.name}"
            if n == "1": img1_path = cropped
            else: img2_path = cropped
        except Exception as e:
            debug_log.warn("dual", f"Crop image{n} failed: {e}")

    job_id = f"dual-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    ua = request.headers.get("User-Agent", "")
    req_source = "ios" if ("GPUPortalApp" in ua or "GrokMVP" in ua) else "portal"
    dual_preset_id = fields.get("preset_id", "") or await _match_preset_by_prompts(fields)
    job = {
        "job_id": job_id, "template_id": "flux2-dual", "type": "dual",
        "preset_id": dual_preset_id,
        "source": req_source,
        "status": "queued", "created_at": time.time(),
        "source_image": fields.get("image1"),
        "image1": fields.get("image1"), "image2": fields.get("image2"),
        "image_prompt_positive": fields.get("prompt", ""),
        "image_prompt_negative": "", "video_prompt_positive": "", "video_prompt_negative": "",
        "worker_url": fields.get("worker_url", ""),
        "result": None, "error": None,
        "generation_sec": None, "worker_boot_sec": None, "total_sec": None,
        "gpu": None, "dph": None, "cost_usd": None,
        "_ip": request.headers.get("X-Forwarded-For", request.remote or ""),
        "_ua": ua,
    }
    manager.jobs[job_id] = job
    asyncio.ensure_future(_mysql_insert_request(job, fields))
    event_log.log("job_queued", req_source, f"Dual job queued: {job_id}", {"job_id": job_id})

    asyncio.create_task(_run_dual_job(job, fields))
    return web.json_response({"job_id": job_id, "status": "queued"})


async def _run_dual_job(job: Dict, fields: Dict):
    """Run dual-image generation."""
    t_start = time.time()
    try:
        worker_url = fields.get("worker_url", "").strip()
        worker_auth = fields.get("worker_auth", "").strip()

        if worker_url:
            job["status"] = "generating"
            base_url = worker_url.rstrip("/")
            auth_headers = {}
            if worker_auth:
                raw = f"vastai:{worker_auth}".encode()
                token = base64.b64encode(raw).decode()
                auth_headers = {"Authorization": f"Basic {token}", "Content-Type": "application/json", "Accept": "application/json"}
            job["worker_boot_sec"] = 0
        else:
            # ── V2: hotapi fallback for dual ──
            eta = await estimate_generation_eta()
            if HOTAPI_KEY:
                job["status"] = "generating"
                job["method"] = "hotapi"
                debug_log.info("hotapi", f"dual generation start")

                # GPU boot in background
                gpu_boot_task = asyncio.create_task(_background_gpu_boot())

                img1_path = SHOWCASE_DIR / Path(fields["image1"]).name
                img2_path = SHOWCASE_DIR / Path(fields["image2"]).name
                prompt_text = fields.get("prompt", "")
                t_gen = time.time()
                result_file = None
                last_exc = None
                gen_result = None
                for _attempt in range(3):
                    try:
                        if _attempt > 0:
                            debug_log.info("hotapi", f"dual retry attempt {_attempt+1}")
                            await asyncio.sleep(2)
                        gen_result = await hotapi_flow_dual(img1_path, img2_path, prompt_text)
                        last_exc = None
                        break
                    except Exception as e:
                        last_exc = e
                        debug_log.warn("hotapi", f"dual attempt {_attempt+1} failed: {e}")
                if last_exc is not None or gen_result is None:
                    job["status"] = "error"
                    job["error"] = f"hotapi dual failed: {last_exc}" if last_exc else "hotapi dual: no result"
                    job["total_sec"] = round(time.time() - t_start, 1)
                else:
                    gen_sec = round(time.time() - t_gen, 1)
                    asyncio.ensure_future(_mysql_update_grok(job["job_id"], {"method": "hotapi", "gen_sec": gen_sec, "output": gen_result.get("generated_image", "")}))
                    job["generation_sec"] = gen_sec

                    if gen_result.get("generated_image"):
                        result_file = str(SHOWCASE_DIR / Path(gen_result["generated_image"]).name)

                    # Stall: skip if portal/admin or GPU disabled
                    stall_target = gen_sec + XAI_STALL_EXTRA_IMG
                    if job.get("source") in ("portal", "admin") or not GPU_BOOT_ENABLED or gen_sec >= 20:
                        stall_target = 0
                    elapsed = time.time() - t_start
                    while elapsed < stall_target:
                        remaining = stall_target - elapsed
                        wait = min(XAI_STALL_INTERVAL_SEC, remaining)
                        if wait <= 1:
                            break
                        job["status"] = "processing"
                        await asyncio.sleep(wait)
                        elapsed = time.time() - t_start
                        if manager.worker.status == "ready":
                            await tg_notify(f"🟢 GPU поднят ({elapsed:.0f}s) — следующие запросы на GPU")
                            break

                    job["status"] = "done"
                    job["result"] = {"outputs": [gen_result.get("generated_image", "")]}
                    if gen_result.get("generated_image"):
                        job["result_image"] = gen_result["generated_image"]
                        job["generated_image"] = gen_result["generated_image"]
                    job["total_sec"] = round(time.time() - t_start, 1)
                    job["cost_usd"] = gen_result.get("cost_usd", 0.02)
                    _record_history(job)
                # Send ONE result message
                src_files = [str(img1_path), str(img2_path)]
                await tg_notify_result(job, src_files, [result_file] if result_file else [], method="hotapi")
                if manager.worker and manager.worker.last_used_at:
                    manager.worker.last_used_at = time.time()
                metrics.record_job(job)
                manager._record_job_in_session(job)
                _record_gen_stats(job)
                src = job.get("source", "portal")
                if job["status"] == "done":
                    event_log.log("job_done", src, f"Dual done: {job['job_id']} gen={job.get('generation_sec')}s method=hotapi", {"job_id": job["job_id"], "gen_sec": job.get("generation_sec"), "cost": job.get("cost_usd")})
                else:
                    event_log.log("job_error", src, f"Dual error: {job['job_id']}: {job.get('error','?')}", {"job_id": job["job_id"], "error": job.get("error")})
                return
            # ── End V2 hotapi dual fallback ──

            job["status"] = "starting_worker"
            t_boot = time.time()
            w = await manager.ensure_worker_ready()
            job["worker_boot_sec"] = round(time.time() - t_boot, 1)
            base_url = w.base_url
            auth_headers = w.auth_headers

        # Debug
        job["debug"] = {
            "worker_url": base_url,
            "has_auth": bool(auth_headers),
            "steps": [],
        }

        # Load workflow
        wf_path = WORKFLOWS_DIR / "workflow_simple_t2i_v2_api.json"
        workflow_json = json.loads(wf_path.read_text()) if wf_path.exists() else {}

        # Build public URLs for both images
        server_ip = os.getenv("SERVER_IP", "")
        if not server_ip:
            try:
                s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
                s.connect(("8.8.8.8", 80))
                server_ip = s.getsockname()[0]
                s.close()
            except Exception:
                server_ip = "127.0.0.1"

        img1_url = f"http://{server_ip}:8899{fields['image1']}"
        img2_url = f"http://{server_ip}:8899{fields['image2']}"

        # Patch LoadImage nodes: node "1" = Photo 1, node "2" = Photo 2
        if "1" in workflow_json:
            workflow_json["1"]["inputs"]["image"] = img1_url
        if "2" in workflow_json:
            workflow_json["2"]["inputs"]["image"] = img2_url

        # Patch prompt (node 5:6)
        prompt_text = fields.get("prompt", "")
        if prompt_text and "5:6" in workflow_json:
            workflow_json["5:6"]["inputs"]["text"] = prompt_text

        # Force Flux 2 output resolution to 9:16 (496×880) for dual flow
        # Only patch PrimitiveInt source nodes — keep references intact in 5:47/5:48
        # (overriding references causes orphaned nodes → ComfyUI validation error)
        if "5:100" in workflow_json and "inputs" in workflow_json["5:100"]:
            workflow_json["5:100"]["inputs"]["value"] = TARGET_W
        if "5:101" in workflow_json and "inputs" in workflow_json["5:101"]:
            workflow_json["5:101"]["inputs"]["value"] = TARGET_H

        # Randomize seed
        if "5:25" in workflow_json:
            workflow_json["5:25"]["inputs"]["noise_seed"] = random.randint(1, 2**63 - 1)

        job["status"] = "waiting_health"
        # Health check loop
        health_start = time.time()
        attempt = 0
        while time.time() - health_start < HEALTH_MAX_WAIT_SEC:
            attempt += 1
            try:
                async with manager.session.get(
                    f"{base_url}{HEALTH_PATH}", headers=auth_headers, timeout=aiohttp.ClientTimeout(total=15)
                ) as hr:
                    if hr.status == 200:
                        txt = await hr.text()
                        try:
                            if json.loads(txt).get("status") == "healthy":
                                break
                        except Exception:
                            pass
            except Exception:
                pass
            await asyncio.sleep(5)
        else:
            raise RuntimeError(f"Worker not ready after {HEALTH_MAX_WAIT_SEC}s")

        job["status"] = "generating"
        payload = {"input": {"request_id": job["job_id"], "workflow_json": workflow_json, "ws_timeout": 600}, "ws_timeout": 600, "timeout": 600}

        t_gen = time.time()
        async with manager.session.post(
            f"{base_url}{GENERATE_SYNC_PATH}", headers=auth_headers, json=payload,
            timeout=aiohttp.ClientTimeout(total=REQUEST_TIMEOUT_SEC),
        ) as r:
            raw_text = await r.text()
            if r.status >= 400:
                raise RuntimeError(f"Generate failed: HTTP {r.status}: {raw_text[:500]}")
            result = json.loads(raw_text)
        job["generation_sec"] = round(time.time() - t_gen, 1)
        job["debug"]["generate_status"] = "ok"
        job["debug"]["generate_body_preview"] = raw_text[:1000] if raw_text else ""
        job["debug"]["output_count"] = len(result.get("output", []))
        debug_log.info("dual", f"ComfyUI response status={r.status}, output_count={len(result.get('output', []))}, body_preview={raw_text[:300]}")

        # Download outputs — find comfy URL
        comfy_url = ""
        if not worker_url and manager.worker.comfy_base_url:
            comfy_url = manager.worker.comfy_base_url
        if not comfy_url:
            try:
                async with manager.session.get(
                    f"{CONSOLE_BASE}/api/v0/instances/", headers=manager.vast_headers
                ) as r:
                    inst_data = await r.json()
                instances = inst_data if isinstance(inst_data, list) else inst_data.get("instances", inst_data.get("results", []))
                worker_ip = base_url.split("://")[1].split(":")[0] if "://" in base_url else ""
                for inst in instances:
                    if str(inst.get("public_ipaddr", "")) == worker_ip:
                        ports = inst.get("ports") or {}
                        comfy_rows = ports.get(f"{VAST_EXTERNAL_COMFY_PORT}/tcp", [])
                        if comfy_rows:
                            comfy_url = f"http://{worker_ip}:{int(comfy_rows[0]['HostPort'])}"
                            break
            except Exception:
                pass
        if not comfy_url:
            comfy_url = base_url
        debug_log.info("dual", f"ComfyUI URL: {comfy_url}")

        outputs = result.get("output", [])
        downloaded = []
        if isinstance(outputs, list):
            for item in outputs:
                fname = item.get("filename", "")
                if not fname:
                    continue
                subfolder = item.get("subfolder", "")
                type_ = item.get("type", "output")
                params = urlencode({"filename": fname, "subfolder": subfolder, "type": type_})
                url = f"{comfy_url}/view?{params}"
                dst_name = f"{job['job_id']}_{fname}"
                dst = SHOWCASE_DIR / dst_name
                try:
                    async with manager.session.get(url, headers=auth_headers) as dr:
                        data = await dr.read()
                        if dr.status < 400 and len(data) > 100:
                            dst.write_bytes(data)
                            downloaded.append(f"/showcase/{dst_name}")
                            debug_log.info("dual", f"Downloaded: {dst_name} ({len(data)} bytes)")
                        else:
                            debug_log.warn("dual", f"Download failed: {url} status={dr.status}")
                except Exception as e:
                    debug_log.error("dual", f"Download error: {e}")

        job["status"] = "done"
        job["total_sec"] = round(time.time() - t_start, 1)
        job["result"] = {"outputs": downloaded}

    except Exception as e:
        job["status"] = "error"
        job["error"] = str(e)
        job["total_sec"] = round(time.time() - t_start, 1)
        debug_log.error("dual", f"Dual job {job['job_id']} failed: {e}")

    # Reset idle timer after dual job
    if manager.worker and manager.worker.last_used_at:
        manager.worker.last_used_at = time.time()

    metrics.record_job(job)
    manager._record_job_in_session(job)
    _record_gen_stats(job)
    src = job.get("source", "portal")
    if job["status"] == "done":
        event_log.log("job_done", src, f"Dual done: {job['job_id']} gen={job.get('generation_sec')}s cost=${job.get('cost_usd')}", {"job_id": job["job_id"], "gen_sec": job.get("generation_sec"), "cost": job.get("cost_usd")})
    else:
        event_log.log("job_error", src, f"Dual error: {job['job_id']}: {job.get('error','?')}", {"job_id": job["job_id"], "error": job.get("error")})
    # One result notification (GPU dual path)
    src1 = str(SHOWCASE_DIR / Path(job["image1"]).name) if job.get("image1") else None
    src2 = str(SHOWCASE_DIR / Path(job["image2"]).name) if job.get("image2") else None
    res_img = str(SHOWCASE_DIR / Path(job["generated_image"]).name) if job.get("generated_image") else None
    asyncio.create_task(tg_notify_result(job, [f for f in [src1, src2] if f], [res_img] if res_img else [], method="gpu"))


# ──────────── iOS Catalog API ────────────

def load_ios_catalog():
    if IOS_CATALOG_FILE.exists():
        try:
            return json.loads(IOS_CATALOG_FILE.read_text())
        except Exception:
            pass
    default = {
        "tabs": {
            "main": {"sections": [
                {"id": "trending", "name": "Trending", "icon": "flame.fill", "pinned": True, "items": []},
                {"id": "new", "name": "New", "icon": "sparkles", "pinned": True, "items": []},
            ]},
            "video": {"sections": []},
            "img2img": {"sections": []},
            "dual": {"sections": []},
        }
    }
    save_ios_catalog(default)
    return default


def save_ios_catalog(data):
    IOS_CATALOG_FILE.write_text(json.dumps(data, indent=2, ensure_ascii=False))


ios_catalog = {}


def _resolve_ios_item(item_ref, local_paths=False):
    """Resolve item ref to full data from showcase.
    local_paths=True: use media/filename (for ZIP/offline JSON)
    local_paths=False: use full server URLs (for public API)
    """
    sid = item_ref.get("id", "")
    sc = next((s for s in showcase if s.get("id") == sid), None)
    if not sc:
        return None

    def _url(path):
        if not path:
            return None
        fname = Path(path).name
        if local_paths:
            return f"media/{fname}"
        return f"http://{_get_server_ip()}:8899{path}"

    result = {
        "id": sc.get("id"),
        "name": sc.get("name", ""),
        "type": sc.get("type", "video"),
        "tags": item_ref.get("tags", []),
        "sort_order": item_ref.get("sort_order", 0),
        "prompt": sc.get("prompt") or sc.get("image_prompt_positive", ""),
        "image_prompt_positive": sc.get("image_prompt_positive", ""),
        "video_prompt_positive": sc.get("video_prompt_positive", ""),
        "source_image": _url(sc.get("source_image")),
        "generated_image": _url(sc.get("generated_image")),
        "video_url": _url(sc.get("video_url")),
        "split_preview": _url(sc.get("generated_image") or sc.get("split_preview")),
        "result_image": _url(sc.get("result_image")),
        "image1": _url(sc.get("image1")),
        "image2": _url(sc.get("image2")),
        "gif_url": _url(sc.get("gif_url")),
        "likes": sc.get("likes", 0),
    }
    return {k: v for k, v in result.items() if v is not None}


def _get_server_ip():
    ip = os.getenv("SERVER_IP", "")
    if not ip:
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.connect(("8.8.8.8", 80))
            ip = s.getsockname()[0]
            s.close()
        except Exception:
            ip = "127.0.0.1"
    return ip


# iOS Catalog — admin endpoints (auth required)
async def api_ios_catalog_get(request):
    return web.json_response(ios_catalog)


async def api_ios_catalog_save(request):
    global ios_catalog
    data = await request.json()
    ios_catalog = data
    save_ios_catalog(data)
    return web.json_response({"ok": True})


async def api_ios_catalog_banner_add(request):
    """Add showcase item to main GIF banners with description."""
    global ios_catalog
    data = await request.json()
    sid = data.get("id", "")
    description = data.get("description", "")
    subtitle = data.get("subtitle", "")

    sc = next((s for s in showcase if s.get("id") == sid), None)
    if not sc:
        return web.json_response({"error": "item not found"}, status=404)
    if not sc.get("gif_url"):
        return web.json_response({"error": "no GIF generated for this item"}, status=400)

    banners = ios_catalog.setdefault("main_gif_banners", [])
    # Update if already exists, otherwise add
    existing = next((b for b in banners if b.get("id") == sid), None)
    if existing:
        existing["name"] = description or sc.get("name", "")
        existing["description"] = description or sc.get("name", "")
        existing["subtitle"] = subtitle
    else:
        banners.append({
            "id": sid,
            "name": description or sc.get("name", ""),
            "description": description or sc.get("name", ""),
            "subtitle": subtitle,
            "gif": sc["gif_url"],
        })

    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True, "banners_count": len(banners)})


async def api_ios_catalog_banner_remove(request):
    """Remove showcase item from main GIF banners."""
    global ios_catalog
    data = await request.json()
    sid = data.get("id", "")

    banners = ios_catalog.get("main_gif_banners", [])
    ios_catalog["main_gif_banners"] = [b for b in banners if b.get("id") != sid]

    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True, "banners_count": len(ios_catalog["main_gif_banners"])})


async def api_ios_catalog_rename_section(request):
    """Rename a section in iOS catalog."""
    data = await request.json()
    tab = data.get("tab", "main")
    section_id = data.get("section_id", "")
    new_name = data.get("name", "").strip()
    if not new_name:
        return web.json_response({"error": "name required"}, status=400)
    if tab not in ios_catalog.get("tabs", {}):
        return web.json_response({"error": f"tab {tab} not found"}, status=400)
    section = next((s for s in ios_catalog["tabs"][tab]["sections"] if s["id"] == section_id), None)
    if not section:
        return web.json_response({"error": "section not found"}, status=404)
    section["name"] = new_name
    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True})


async def api_ios_catalog_add_section(request):
    data = await request.json()
    tab = data.get("tab", "main")
    name = data.get("name", "New Section")
    sid = data.get("id") or name.lower().replace(" ", "_")
    icon = data.get("icon", "folder")
    if tab not in ios_catalog.get("tabs", {}):
        return web.json_response({"error": f"tab {tab} not found"}, status=400)
    sections = ios_catalog["tabs"][tab]["sections"]
    if any(s["id"] == sid for s in sections):
        return web.json_response({"error": "section exists"}, status=409)
    sections.append({"id": sid, "name": name, "icon": icon, "pinned": False, "items": []})
    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True})


async def api_ios_catalog_reorder(request):
    """Reorder sections or items. Body: {tab, sections: [ids]} or {tab, section_id, items: [ids]}"""
    data = await request.json()
    tab = data.get("tab")
    if tab not in ios_catalog.get("tabs", {}):
        return web.json_response({"error": "bad tab"}, status=400)

    if "sections" in data:
        # Reorder sections
        order = data["sections"]
        sections = ios_catalog["tabs"][tab]["sections"]
        ordered = []
        for sid in order:
            s = next((x for x in sections if x["id"] == sid), None)
            if s:
                ordered.append(s)
        # Add any missing
        for s in sections:
            if s["id"] not in order:
                ordered.append(s)
        ios_catalog["tabs"][tab]["sections"] = ordered
    elif "section_id" in data and "items" in data:
        # Reorder items within section
        sec_id = data["section_id"]
        item_order = data["items"]
        section = next((s for s in ios_catalog["tabs"][tab]["sections"] if s["id"] == sec_id), None)
        if not section:
            return web.json_response({"error": "section not found"}, status=404)
        items = section["items"]
        ordered = []
        for iid in item_order:
            it = next((x for x in items if x["id"] == iid), None)
            if it:
                ordered.append(it)
        for it in items:
            if it["id"] not in item_order:
                ordered.append(it)
        section["items"] = ordered
        # Update sort_order to match new array position
        for idx, it in enumerate(ordered):
            it["sort_order"] = (idx + 1) * 2

    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True})


async def api_ios_catalog_move_item(request):
    """Move item between sections (same or different tabs).
    Body: {from_tab, from_section, to_tab, to_section, item_id, to_index?}"""
    data = await request.json()
    from_tab = data.get("from_tab") or data.get("tab")
    to_tab = data.get("to_tab") or data.get("tab")
    from_sec_id = data.get("from_section")
    to_sec_id = data.get("to_section")
    item_id = data.get("item_id")
    to_index = int(data.get("to_index", 9999))

    if not all([from_tab, to_tab, from_sec_id, to_sec_id, item_id]):
        return web.json_response({"error": "from_tab, from_section, to_tab, to_section, item_id required"}, status=400)

    tabs = ios_catalog.get("tabs", {})
    from_sections = tabs.get(from_tab, {}).get("sections", [])
    to_sections = tabs.get(to_tab, {}).get("sections", [])

    from_sec = next((s for s in from_sections if s["id"] == from_sec_id), None)
    to_sec = next((s for s in to_sections if s["id"] == to_sec_id), None)

    if not from_sec or not to_sec:
        return web.json_response({"error": "section not found"}, status=404)

    # Find and remove from source
    item_ref = next((it for it in from_sec["items"] if it["id"] == item_id), None)
    if not item_ref:
        return web.json_response({"error": "item not in source section"}, status=404)

    if from_tab == to_tab and from_sec_id == to_sec_id:
        return web.json_response({"ok": True, "note": "same section"})

    from_sec["items"] = [it for it in from_sec["items"] if it["id"] != item_id]

    # Insert at target position
    item_ref = {**item_ref, "sort_order": to_index}
    to_sec["items"].insert(min(to_index, len(to_sec["items"])), item_ref)

    # Renumber sort_order
    for i, it in enumerate(from_sec["items"]): it["sort_order"] = i
    for i, it in enumerate(to_sec["items"]): it["sort_order"] = i

    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True, "moved": item_id, "from": f"{from_tab}/{from_sec_id}", "to": f"{to_tab}/{to_sec_id}"})


async def api_ios_catalog_add_item(request):
    data = await request.json()
    tab = data.get("tab")
    sec_id = data.get("section_id")
    showcase_id = data.get("showcase_id")
    tags = data.get("tags", [])
    if not all([tab, sec_id, showcase_id]):
        return web.json_response({"error": "tab, section_id, showcase_id required"}, status=400)
    section = next((s for s in ios_catalog["tabs"].get(tab, {}).get("sections", []) if s["id"] == sec_id), None)
    if not section:
        return web.json_response({"error": "section not found"}, status=404)
    if any(i["id"] == showcase_id for i in section["items"]):
        return web.json_response({"error": "already in section"}, status=409)
    section["items"].append({"id": showcase_id, "tags": tags, "sort_order": len(section["items"])})
    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True})


async def api_ios_catalog_remove_item(request):
    data = await request.json()
    tab = data.get("tab")
    sec_id = data.get("section_id")
    showcase_id = data.get("showcase_id")
    section = next((s for s in ios_catalog["tabs"].get(tab, {}).get("sections", []) if s["id"] == sec_id), None)
    if not section:
        return web.json_response({"error": "section not found"}, status=404)
    section["items"] = [i for i in section["items"] if i["id"] != showcase_id]
    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True})


async def api_ios_catalog_toggle_tag(request):
    data = await request.json()
    tab = data.get("tab")
    sec_id = data.get("section_id")
    showcase_id = data.get("showcase_id")
    tag = data.get("tag")
    section = next((s for s in ios_catalog["tabs"].get(tab, {}).get("sections", []) if s["id"] == sec_id), None)
    if not section:
        return web.json_response({"error": "section not found"}, status=404)
    item = next((i for i in section["items"] if i["id"] == showcase_id), None)
    if not item:
        return web.json_response({"error": "item not found"}, status=404)
    tags = item.get("tags", [])
    if tag in tags:
        tags.remove(tag)
    else:
        tags.append(tag)
    item["tags"] = tags
    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True, "tags": tags})


async def api_ios_catalog_set_hidden(request):
    """Bulk set hidden flag on items. Body: {tab, items:[{section_id, item_id}], hidden: bool}"""
    data = await request.json()
    tab = data.get("tab")
    hidden = bool(data.get("hidden", True))
    entries = data.get("items", [])
    if tab not in ios_catalog.get("tabs", {}):
        return web.json_response({"error": "bad tab"}, status=400)
    # Collect item IDs to change
    item_ids = set()
    for entry in entries:
        sec_id = entry.get("section_id")
        item_id = entry.get("item_id")
        section = next((s for s in ios_catalog["tabs"][tab]["sections"] if s["id"] == sec_id), None)
        if section and any(i["id"] == item_id for i in section["items"]):
            item_ids.add(item_id)

    # Apply hidden flag across ALL tabs for these item IDs
    updated = 0
    for t_data in ios_catalog.get("tabs", {}).values():
        for sec in t_data.get("sections", []):
            for item in sec.get("items", []):
                if item["id"] in item_ids:
                    if hidden:
                        item["hidden"] = True
                    else:
                        item.pop("hidden", None)
                    updated += 1

    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True, "updated": updated})


async def api_ios_catalog_delete_section(request):
    data = await request.json()
    tab = data.get("tab")
    sec_id = data.get("section_id")
    sections = ios_catalog["tabs"].get(tab, {}).get("sections", [])
    ios_catalog["tabs"][tab]["sections"] = [s for s in sections if s["id"] != sec_id]
    save_ios_catalog(ios_catalog)
    return web.json_response({"ok": True})


# iOS Catalog — public endpoints (no auth)
async def api_ios_catalog_public(request):
    """Public resolved catalog for iOS app."""
    # Notify on new iOS session (catalog download)
    ua = request.headers.get("User-Agent", "")
    if "GrokMVP" in ua or "GPUPortalApp" in ua:
        ip = request.headers.get("X-Forwarded-For", request.remote or "").split(",")[0].strip()
        if ip:
            asyncio.ensure_future(tg_notify_new_session(ip, ua))
    result = {"tabs": {}}
    for tab_name, tab_data in ios_catalog.get("tabs", {}).items():
        sections = []
        for sec in tab_data.get("sections", []):
            items = []
            for ref in sorted(sec.get("items", []), key=lambda x: x.get("sort_order", 0)):
                if ref.get("hidden"):
                    continue
                resolved = _resolve_ios_item(ref)
                if resolved:
                    items.append(resolved)
            sections.append({
                "id": sec["id"],
                "name": sec["name"],
                "icon": sec.get("icon", ""),
                "items": items,
            })
        result["tabs"][tab_name] = {"sections": sections}

    # Main GIF banners (top of main tab)
    if "main_gif_banners" in ios_catalog:
        banners = []
        for b in ios_catalog["main_gif_banners"]:
            sc = next((s for s in showcase if s.get("id") == b.get("id")), None)
            if sc and sc.get("gif_url"):
                gif_path = sc["gif_url"]
                banners.append({
                    "id": b["id"],
                    "name": b.get("name", sc.get("name", "")),
                    "description": b.get("description", b.get("name", sc.get("name", ""))),
                    "subtitle": b.get("subtitle", ""),
                    "gif": f"http://{_get_server_ip()}:8899{gif_path}",
                })
        result["main_gif_banners"] = banners

    return web.json_response(result)


async def api_ios_catalog_latest_zip(request):
    """Public: serve pre-built ios_catalog_v2_latest.zip from static location."""
    p = PORTAL_DIR / "public" / "ios_catalog_v2_latest.zip"
    if not p.exists():
        return web.Response(status=404, text="Catalog not built yet")
    mtime = int(p.stat().st_mtime)
    return web.FileResponse(p, headers={
        "Content-Disposition": "attachment; filename=ios_catalog_v2_latest.zip",
        "X-Catalog-Built": str(mtime),
    })


async def api_ios_catalog_version(request):
    """Public: return catalog version timestamp."""
    p = PORTAL_DIR / "public" / "ios_catalog_version.txt"
    if not p.exists():
        return web.json_response({"version": None})
    ts = p.read_text().strip()
    return web.json_response({"version": ts})


# ──────────── Onboarding Sets API ────────────

ONBOARDING_SETS_FILE = PORTAL_DIR / "onboarding_sets.json"
STATS_FILE = PORTAL_DIR / "generation_stats.json"

# ──────────── GeoIP ────────────
_GEOIP_DIR = PORTAL_DIR / "geoip"
_geoip_country = None
_geoip_asn = None

def _init_geoip():
    global _geoip_country, _geoip_asn
    try:
        import geoip2.database
        c_path = _GEOIP_DIR / "country.mmdb"
        a_path = _GEOIP_DIR / "asn.mmdb"
        if c_path.exists():
            _geoip_country = geoip2.database.Reader(str(c_path))
        if a_path.exists():
            _geoip_asn = geoip2.database.Reader(str(a_path))
        if _geoip_country or _geoip_asn:
            print(f"[GeoIP] Loaded: country={bool(_geoip_country)}, asn={bool(_geoip_asn)}")
    except Exception as e:
        print(f"[GeoIP] Init failed: {e}")

def _geoip_lookup(ip_str: str) -> dict:
    """Return {country_code, country_name, isp} for an IP. Empty strings on failure."""
    raw_ip = ip_str.split(",")[0].strip()
    result = {"country_code": "", "country_name": "", "isp": ""}
    if not raw_ip or raw_ip in ("127.0.0.1", "::1"):
        return result
    try:
        if _geoip_country:
            rec = _geoip_country.country(raw_ip)
            result["country_code"] = rec.country.iso_code or ""
            result["country_name"] = rec.country.name or ""
    except Exception:
        pass
    try:
        if _geoip_asn:
            rec = _geoip_asn.asn(raw_ip)
            result["isp"] = rec.autonomous_system_organization or ""
    except Exception:
        pass
    return result

def _flag_emoji(country_code: str) -> str:
    if not country_code or len(country_code) != 2:
        return "🌐"
    return chr(0x1F1E6 + ord(country_code[0]) - ord('A')) + chr(0x1F1E6 + ord(country_code[1]) - ord('A'))
_COUNTRY_LANG_MAP = {
    'IT': 'Italian', 'ES': 'Spanish', 'MX': 'Spanish', 'AR': 'Spanish',
    'CO': 'Spanish', 'VE': 'Spanish', 'PE': 'Spanish', 'CL': 'Spanish',
    'FR': 'French', 'BE': 'French',
    'DE': 'German', 'AT': 'German', 'CH': 'German',
    'RU': 'Russian', 'BY': 'Russian', 'UA': 'Russian',
    'PT': 'Portuguese', 'BR': 'Portuguese',
    'TR': 'Turkish',
    'PL': 'Polish',
    'NL': 'Dutch',
    'AL': 'Albanian',
    'RS': 'Serbian', 'HR': 'Serbian',
    'GR': 'Greek',
    'RO': 'Romanian',
    'SK': 'Slovak', 'CZ': 'Czech',
    'HU': 'Hungarian',
    'SE': 'Swedish', 'NO': 'Norwegian', 'DK': 'Danish',
    'JP': 'Japanese', 'KR': 'Korean',
    'CN': 'Chinese', 'TW': 'Chinese',
    'SA': 'Arabic', 'AE': 'Arabic', 'EG': 'Arabic',
}

def _get_lang_from_ip(ip: str) -> str:
    """Return language name for given IP, or empty string for English/unknown."""
    geo = _geoip_lookup(ip)
    cc = geo.get('country_code', '')
    lang = _COUNTRY_LANG_MAP.get(cc, '')
    return lang  # empty = English (default, no suffix needed)

def _video_prompt_with_lang(prompt: str, ip: str) -> str:
    """Append language instruction to video prompt based on sender's country."""
    lang = _get_lang_from_ip(ip)
    if lang and lang != 'English':
        suffix = f' If any text or words appear in the video, render them in {lang}.'
        return prompt.rstrip() + suffix
    return prompt




# ──────────── MySQL ────────────
MYSQL_CONFIG = {
    "host": "127.0.0.1", "port": 3306,
    "user": "motionfy", "password": "motionfy2026",
    "db": "motionfy", "charset": "utf8mb4", "autocommit": True,
}
_mysql_pool = None

async def _get_mysql_pool():
    global _mysql_pool
    if _mysql_pool is None and _MYSQL_AVAILABLE:
        try:
            _mysql_pool = await aiomysql.create_pool(minsize=1, maxsize=5, **MYSQL_CONFIG)
        except Exception as e:
            print(f"[MySQL] Pool creation failed: {e}")
    return _mysql_pool

async def _mysql_insert_request(job: Dict, fields: Dict):
    """INSERT row at request time (status=queued) with all fields iOS/portal sent."""
    if not _MYSQL_AVAILABLE:
        return
    pool = await _get_mysql_pool()
    if not pool:
        return
    try:
        # Exclude binary uploads but keep string paths (image1/image2 are already /showcase/ paths for dual)
        safe_fields = {k: v for k, v in fields.items() if k not in ("image",)}
        req_json = json.dumps(safe_fields, ensure_ascii=False)[:4000]
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("""
                    INSERT IGNORE INTO generations
                        (ts, job_id, preset_id, template_id, type, source, status, step,
                         request_json, user_agent, ip)
                    VALUES (%s,%s,%s,%s,%s,%s,'queued','queued',%s,%s,%s)
                """, (
                    datetime.fromtimestamp(job.get("created_at") or time.time()),
                    job.get("job_id", ""),
                    job.get("preset_id", "") or "",
                    job.get("template_id", "") or "",
                    job.get("type", "video"),
                    job.get("source", "portal"),
                    req_json,
                    (job.get("_ua", "") or "")[:255] or None,
                    (job.get("_ip", "") or "")[:64] or None,
                ))
    except Exception as e:
        print(f"[MySQL] insert_request error: {e}")


async def _mysql_update_grok(job_id: str, grok_data: Dict):
    """UPDATE row after Grok generates prompts."""
    if not _MYSQL_AVAILABLE:
        return
    pool = await _get_mysql_pool()
    if not pool:
        return
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE generations SET step='grok', grok_response=%s WHERE job_id=%s",
                    (json.dumps(grok_data, ensure_ascii=False)[:4000], job_id)
                )
    except Exception as e:
        print(f"[MySQL] update_grok error: {e}")


async def _mysql_log_generation(job: Dict, ip: str = "", ua: str = ""):
    """UPDATE (or INSERT if missing) completed generation into MySQL."""
    if not _MYSQL_AVAILABLE:
        return
    pool = await _get_mysql_pool()
    if not pool:
        return
    try:
        _ip = ip or job.get("_ip", "") or ""
        _ua = ua or job.get("_ua", "") or ""
        real_ip = _ip.split(",")[0].strip()
        geo = _geoip_lookup(real_ip) if real_ip else {}
        cc = geo.get("country_code", "") or ""
        cn = geo.get("country_name", "") or ""
        isp = geo.get("isp", "") or ""
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                # Try UPDATE first (row should exist from _mysql_insert_request)
                await cur.execute("""
                    UPDATE generations SET
                        status=%s, step='done',
                        preset_id=COALESCE(NULLIF(%s,''), preset_id),
                        gen_sec=%s, total_sec=%s, cost_usd=%s,
                        preview=%s, error=%s,
                        country_code=%s, country_name=%s, isp=%s
                    WHERE job_id=%s
                """, (
                    job.get("status", "done"),
                    job.get("preset_id", "") or "",
                    job.get("generation_sec"),
                    job.get("total_sec"),
                    job.get("cost_usd"),
                    (job.get("generated_image") or job.get("source_image") or "")[:255],
                    (str(job.get("error", "")) or None),
                    cc[:2] or None, cn[:64] or None, isp[:128] or None,
                    job.get("job_id", ""),
                ))
                if cur.rowcount == 0:
                    # Fallback INSERT if row wasn't created at request time
                    await cur.execute("""
                        INSERT IGNORE INTO generations
                            (ts, job_id, preset_id, template_id, type, source, status, step,
                             gen_sec, total_sec, cost_usd, preview, error, user_agent, ip,
                             country_code, country_name, isp)
                        VALUES (%s,%s,%s,%s,%s,%s,%s,'done',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                    """, (
                        datetime.fromtimestamp(job.get("created_at") or time.time()),
                        job.get("job_id", ""),
                        job.get("preset_id", "") or "",
                        job.get("template_id", "") or "",
                        job.get("type", "video"),
                        job.get("source", "portal"),
                        job.get("status", "done"),
                        job.get("generation_sec"),
                        job.get("total_sec"),
                        job.get("cost_usd"),
                        (job.get("generated_image") or job.get("source_image") or "")[:255],
                        (str(job.get("error", "")) or None),
                        _ua[:255] or None,
                        _ip[:64] or None,
                        cc[:2] or None, cn[:64] or None, isp[:128] or None,
                    ))
                # Upsert into users table + track tokens
                now_dt = datetime.now()
                gen_type = job.get("type", "video")
                tokens_delta = 200 if gen_type == "video" else 40
                if job.get("status", "done") != "done":
                    tokens_delta = 0
                await cur.execute("""
                    INSERT INTO users (real_ip, country_code, country_name, isp, user_agent, first_seen, last_seen, total_generations, tokens_used)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, 1, %s)
                    ON DUPLICATE KEY UPDATE
                        last_seen = VALUES(last_seen),
                        total_generations = total_generations + 1,
                        tokens_used = tokens_used + %s,
                        country_code = COALESCE(NULLIF(VALUES(country_code), ''), country_code),
                        country_name = COALESCE(NULLIF(VALUES(country_name), ''), country_name),
                        isp = COALESCE(NULLIF(VALUES(isp), ''), isp)
                """, (
                    real_ip[:64] or None,
                    cc[:2] or None, cn[:64] or None, isp[:128] or None,
                    _ua[:512] or None,
                    now_dt, now_dt, tokens_delta, tokens_delta,
                ))
                # Check if user just became premium (tokens crossed 200)
                if tokens_delta > 0 and real_ip:
                    await cur.execute("""
                        SELECT tokens_used, premium_since FROM users WHERE real_ip = %s
                    """, (real_ip[:64],))
                    urow = await cur.fetchone()
                    if urow and urow["tokens_used"] > 200 and not urow["premium_since"]:
                        await cur.execute("""
                            UPDATE users SET premium_since = %s WHERE real_ip = %s AND premium_since IS NULL
                        """, (now_dt, real_ip[:64]))
                        # Send one-time premium TG notification
                        flag = _flag_emoji(cc) if cc else ""
                        country_str = f" {flag} {cn}" if cn else ""
                        asyncio.ensure_future(tg_notify(
                            f"⭐ Премиум-юзер\n"
                            f"👤 {real_ip}{country_str}\n"
                            f"Использовано токенов: {urow['tokens_used']}\n"
                            f"Время: {now_dt.strftime('%H:%M %d.%m')}"
                        ))
    except Exception as e:
        print(f"[MySQL] log error: {e}")


def _load_onboarding_sets():
    if ONBOARDING_SETS_FILE.exists():
        try:
            return json.loads(ONBOARDING_SETS_FILE.read_text())
        except Exception:
            pass
    return {"sets": []}


def _save_onboarding_sets(data):
    ONBOARDING_SETS_FILE.write_text(json.dumps(data, indent=2, ensure_ascii=False))
    try:
        asyncio.ensure_future(_mysql_sync_onboarding(data))
    except RuntimeError:
        pass


async def _mysql_sync_onboarding(data: Dict):
    """Upsert onboarding sets + screens to MySQL."""
    if not _MYSQL_AVAILABLE:
        return
    pool = await _get_mysql_pool()
    if not pool:
        return
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                for s in data.get("sets", []):
                    funnel = s.get("funnel", {}).get("trial_generation", {})
                    await cur.execute("""
                        INSERT INTO onboarding_sets
                          (id, name, paywall_source, funnel_enabled, funnel_preset_id, funnel_flow, funnel_position, created_at, updated_at)
                        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
                        ON DUPLICATE KEY UPDATE
                          name=VALUES(name), paywall_source=VALUES(paywall_source),
                          funnel_enabled=VALUES(funnel_enabled), funnel_preset_id=VALUES(funnel_preset_id),
                          funnel_flow=VALUES(funnel_flow), funnel_position=VALUES(funnel_position),
                          updated_at=VALUES(updated_at)
                    """, (
                        s["id"], s.get("name",""), s.get("paywall_source",""),
                        1 if funnel.get("enabled") else 0,
                        funnel.get("preset_id",""), funnel.get("flow",""), funnel.get("position",""),
                        s.get("created_at",0), s.get("updated_at",0)
                    ))
                    await cur.execute("DELETE FROM onboarding_screens WHERE set_id=%s", (s["id"],))
                    for pos, scr in enumerate(s.get("screens",[])):
                        await cur.execute(
                            "INSERT INTO onboarding_screens (set_id, position, image, image_url, title, subtitle) VALUES (%s,%s,%s,%s,%s,%s)",
                            (s["id"], pos, scr.get("image",""), scr.get("image_url",""), scr.get("title",""), scr.get("subtitle",""))
                        )
    except Exception as e:
        print(f"[MySQL] sync_onboarding error: {e}")


# Public endpoints (no auth) — used by iOS app
async def api_onboarding_list_public(request):
    """Return all onboarding sets (id + name only, for discovery)."""
    data = _load_onboarding_sets()
    sets = [{"id": s["id"], "name": s["name"]} for s in data.get("sets", [])]
    return web.json_response({"sets": sets})


async def api_onboarding_get_public(request):
    """Return full onboarding set by ID. Used by app after Adjust attribution."""
    set_id = request.match_info.get("set_id", "")
    data = _load_onboarding_sets()
    s = next((s for s in data.get("sets", []) if s["id"] == set_id), None)
    if not s:
        # Fallback to default set
        s = next((s for s in data.get("sets", []) if s["id"] == "default"), None)
    if not s:
        return web.json_response({"error": "set not found"}, status=404)
    return web.json_response(s)


# Admin endpoints (auth required)
async def api_onboarding_list(request):
    """List all onboarding sets with full data."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    data = _load_onboarding_sets()
    return web.json_response(data)


async def api_onboarding_create(request):
    """Create a new onboarding set."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    body = await request.json()
    set_id = body.get("id", "").strip()
    if not set_id:
        return web.json_response({"error": "id required"}, status=400)
    data = _load_onboarding_sets()
    if any(s["id"] == set_id for s in data["sets"]):
        return web.json_response({"error": "set with this id already exists"}, status=409)
    now = int(time.time())
    new_set = {
        "id": set_id,
        "name": body.get("name", set_id),
        "screens": body.get("screens", []),
        "funnel": body.get("funnel", {"trial_generation": {"enabled": False, "preset_id": None, "flow": None, "position": None}}),
        "paywall_source": body.get("paywall_source", f"onboarding_{set_id}"),
        "created_at": now,
        "updated_at": now,
    }
    data["sets"].append(new_set)
    _save_onboarding_sets(data)
    return web.json_response({"ok": True, "set": new_set})


def _generate_onb_gif(before_url: str, after_url: str) -> str:
    """Generate a before/after GIF: 1.5s before + 3s after, looped."""
    import uuid as _uuid
    def url_to_path(url):
        return PORTAL_DIR / url.lstrip("/")
    before_path = url_to_path(before_url)
    after_path = url_to_path(after_url)
    img_before = Image.open(before_path).convert("RGBA")
    img_after = Image.open(after_path).convert("RGBA")
    size = (img_after.width, img_after.height)
    img_before = img_before.resize(size, Image.LANCZOS)
    uid = _uuid.uuid4().hex[:8]
    gif_name = f"onb_ba_{int(time.time())}_{uid}.gif"
    gif_path = SHOWCASE_DIR / gif_name
    img_before.save(
        gif_path, save_all=True,
        append_images=[img_after],
        duration=[700, 2300],
        loop=0,
        optimize=True
    )
    return f"/showcase/{gif_name}"


async def api_onboarding_update(request):
    """Update an existing onboarding set."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    set_id = request.match_info.get("set_id", "")
    body = await request.json()
    data = _load_onboarding_sets()
    s = next((s for s in data["sets"] if s["id"] == set_id), None)
    if not s:
        return web.json_response({"error": "set not found"}, status=404)
    for field in ("name", "screens", "funnel", "paywall_source"):
        if field in body:
            s[field] = body[field]
    s["updated_at"] = int(time.time())
    # Auto-derive trial_generation.flow from preset type
    tg = s.get("funnel", {}).get("trial_generation", {})
    if tg.get("enabled") and tg.get("preset_id"):
        sc_list = load_showcase()
        sc_map = {x["id"]: x for x in sc_list}
        preset = sc_map.get(tg["preset_id"])
        if preset:
            tg["flow"] = preset.get("type", "video")
    # Auto-generate before/after GIFs: replace image_url with GIF when before_after=True
    for scr in s.get("screens", []):
        src = scr.get("source_image_url", "")
        img = scr.get("image_url", "")
        is_gif = img.endswith(".gif")
        if scr.get("before_after") and src and img and not is_gif:
            try:
                gif_url = _generate_onb_gif(src, img)
                scr["image_url"] = gif_url
                print(f"[onb_gif] generated {gif_url}", flush=True)
            except Exception as e:
                print(f"[onb_gif] error: {e}", flush=True)
    _save_onboarding_sets(data)
    return web.json_response({"ok": True, "set": s})


async def api_onboarding_delete(request):
    """Delete an onboarding set."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    set_id = request.match_info.get("set_id", "")
    if set_id == "default":
        return web.json_response({"error": "cannot delete default set"}, status=400)
    data = _load_onboarding_sets()
    before = len(data["sets"])
    data["sets"] = [s for s in data["sets"] if s["id"] != set_id]
    if len(data["sets"]) == before:
        return web.json_response({"error": "set not found"}, status=404)
    _save_onboarding_sets(data)
    return web.json_response({"ok": True})


# ──────────── Generation Stats API ────────────

def _load_gen_stats() -> List[Dict]:
    if STATS_FILE.exists():
        try:
            return json.loads(STATS_FILE.read_text())
        except Exception:
            pass
    return []


def _save_gen_stats(records: List[Dict]):
    STATS_FILE.write_text(json.dumps(records, ensure_ascii=False))


def _record_gen_stats(job: Dict, ip: str = "", ua: str = ""):
    """Append a generation record when a job completes (success or error)."""
    _ip = ip or job.get("_ip", "")
    _ua = ua or job.get("_ua", "")
    try:
        records = _load_gen_stats()
        records.append({
            "ts": time.time(),
            "job_id": job.get("job_id", ""),
            "preset_id": job.get("preset_id", ""),
            "template_id": job.get("template_id", ""),
            "type": job.get("type", "video"),
            "source": job.get("source", "portal"),
            "status": job.get("status", ""),
            "gen_sec": job.get("generation_sec"),
            "cost_usd": job.get("cost_usd"),
            "preview": job.get("generated_image") or job.get("source_image") or "",
        })
        _save_gen_stats(records)
    except Exception as e:
        debug_log.warn("stats", f"Failed to record stats: {e}")
    # Also log to MySQL
    asyncio.ensure_future(_mysql_log_generation(job, ip=_ip, ua=_ua))


def _parse_events_stats() -> List[Dict]:
    """Parse events.jsonl to build historical generation records."""
    records = []
    events_file = PORTAL_DIR / "events.jsonl"
    if not events_file.exists():
        return records
    try:
        for line in events_file.read_text().splitlines():
            line = line.strip()
            if not line:
                continue
            ev = json.loads(line)
            if ev.get("type") != "job_done":
                continue
            data = ev.get("data", {})
            records.append({
                "ts": ev.get("ts", 0),
                "job_id": data.get("job_id", ""),
                "preset_id": data.get("preset_id", ""),
                "template_id": data.get("template_id", ""),
                "type": "dual" if "dual" in ev.get("message", "") else "video",
                "source": ev.get("source", "portal"),
                "status": "done",
                "gen_sec": data.get("gen_sec"),
                "cost_usd": data.get("cost"),
                "preview": "",
            })
    except Exception as e:
        debug_log.warn("stats", f"Error parsing events: {e}")
    return records


async def api_stats_generations(request):
    """GET /api/stats/generations — aggregated generation stats from MySQL."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)

    server_url = os.getenv("PORTAL_PUBLIC_URL", PORTAL_PUBLIC_URL)

    def _url(p):
        if not p: return None
        return f"{server_url}{p}" if str(p).startswith("/") else p

    def _f(v, default=None):
        """Convert MySQL Decimal/int/None to float safely."""
        if v is None: return default
        try: return float(v)
        except: return default

    pool = await _get_mysql_pool()
    if not pool:
        return web.json_response({"error": "MySQL unavailable"}, status=503)

    try:
        async with pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                # Totals
                await cur.execute("""
                    SELECT
                        COUNT(*) AS total,
                        COALESCE(SUM(cost_usd), 0) AS total_cost,
                        AVG(CASE WHEN gen_sec > 0 THEN gen_sec END) AS avg_gen_sec
                    FROM generations WHERE status IN ('done','error','failed') OR status IS NULL
                """)
                totals = await cur.fetchone()

                # By source
                await cur.execute("""
                    SELECT COALESCE(source,'portal') AS src, COUNT(*) AS cnt
                    FROM generations GROUP BY COALESCE(source,'portal')
                """)
                by_source = {r["src"]: r["cnt"] for r in await cur.fetchall()}

                # By type
                await cur.execute("""
                    SELECT COALESCE(type,'video') AS typ, COUNT(*) AS cnt
                    FROM generations GROUP BY COALESCE(type,'video')
                """)
                by_type = {r["typ"]: r["cnt"] for r in await cur.fetchall()}

                # By country — unique users
                await cur.execute("""
                    SELECT COALESCE(NULLIF(country_code,''), '??') AS cc,
                           COALESCE(NULLIF(country_name,''), 'Unknown') AS cn,
                           COUNT(DISTINCT real_ip) AS cnt
                    FROM users
                    WHERE country_code IS NOT NULL AND country_code != ''
                    GROUP BY cc, cn ORDER BY cnt DESC LIMIT 30
                """)
                by_country = [{"country_code": r["cc"], "country_name": r["cn"], "count": r["cnt"]}
                              for r in await cur.fetchall()]

                # By preset
                await cur.execute("""
                    SELECT preset_id,
                           COALESCE(generations.type,'video') AS type,
                           COUNT(*) AS cnt,
                           SUM(CASE WHEN status NOT IN ('done') AND status IS NOT NULL THEN 1 ELSE 0 END) AS errors,
                           COALESCE(SUM(cost_usd),0) AS total_cost,
                           AVG(CASE WHEN gen_sec > 0 THEN gen_sec END) AS avg_gen_sec,
                           MAX(UNIX_TIMESTAMP(ts)) AS last_ts
                    FROM generations
                    WHERE preset_id IS NOT NULL AND preset_id != ''
                    GROUP BY preset_id, generations.type ORDER BY cnt DESC LIMIT 200
                """)
                preset_rows = await cur.fetchall()

                # Recent 50
                await cur.execute("""
                    SELECT UNIX_TIMESTAMP(ts) AS ts, job_id, preset_id, type, source, status,
                           gen_sec, cost_usd, preview, ip, country_code, country_name, isp
                    FROM generations ORDER BY ts DESC LIMIT 50
                """)
                recent_rows = await cur.fetchall()

                # Daily counts (last 30 days)
                await cur.execute("""
                    SELECT DATE(ts) AS day, COUNT(*) AS cnt
                    FROM generations
                    WHERE ts >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND (status = 'done' OR status IS NULL)
                    GROUP BY DATE(ts) ORDER BY day
                """)
                daily_rows = await cur.fetchall()

    except Exception as e:
        print(f"[MySQL] api_stats_generations error: {e}")
        return web.json_response({"error": str(e)}, status=500)

    # Enrich presets with showcase data
    preset_list = []
    for row in preset_rows:
        key = row["preset_id"]
        sc_item = next((s for s in showcase if s.get("id") == key), None)
        name = key
        typ = row["type"]
        preview = None
        split_preview = source_image = video_url = image1 = image2 = None
        if sc_item:
            name = sc_item.get("name", key)
            typ = sc_item.get("type", typ)
            preview = _url(sc_item.get("generated_image") or sc_item.get("source_image"))
            split_preview = _url(sc_item.get("split_preview") or sc_item.get("generated_image") or sc_item.get("result_image") or sc_item.get("source_image"))
            source_image = _url(sc_item.get("source_image"))
            video_url = _url(sc_item.get("video_url"))
            image1 = _url(sc_item.get("image1"))
            image2 = _url(sc_item.get("image2"))
        preset_list.append({
            "preset_id": key,
            "name": name,
            "type": typ,
            "count": int(row["cnt"] or 0),
            "errors": int(row["errors"] or 0),
            "avg_gen_sec": round(_f(row["avg_gen_sec"]), 1) if row["avg_gen_sec"] else None,
            "total_cost_usd": round(_f(row["total_cost"], 0.0), 4),
            "last_ts": _f(row["last_ts"], 0),
            "preview_image": preview,
            "split_preview": split_preview,
            "source_image": source_image,
            "video_url": video_url,
            "image1": image1,
            "image2": image2,
        })

    # Enrich recent with preview URLs
    recent = []
    for r in recent_rows:
        preview = _url(r.get("preview"))
        if not preview:
            sc_item = next((s for s in showcase if s.get("id") == r.get("job_id")), None)
            if sc_item:
                preview = _url(sc_item.get("generated_image") or sc_item.get("source_image"))
        recent.append({
            "ts": float(r["ts"]) if r["ts"] else 0,
            "job_id": r.get("job_id", ""),
            "preset_id": r.get("preset_id", ""),
            "type": r.get("type", "video"),
            "source": r.get("source", "portal"),
            "status": r.get("status", ""),
            "gen_sec": float(r["gen_sec"]) if r.get("gen_sec") else None,
            "cost_usd": float(r["cost_usd"]) if r.get("cost_usd") else None,
            "preview": preview,
            "ip": r.get("ip", ""),
            "country_code": r.get("country_code", ""),
            "country_name": r.get("country_name", ""),
            "isp": r.get("isp", ""),
        })

    daily_list = [{"date": str(r["day"]), "count": r["cnt"]} for r in daily_rows]

    avg_sec = totals.get("avg_gen_sec")
    return web.json_response({
        "total": int(totals["total"] or 0),
        "total_cost_usd": round(_f(totals["total_cost"], 0.0), 2),
        "avg_gen_sec": round(_f(avg_sec), 1) if avg_sec else None,
        "by_source": {k: int(v) for k, v in by_source.items()},
        "by_type": {k: int(v) for k, v in by_type.items()},
        "by_country": [{"country_code": c["country_code"], "country_name": c["country_name"], "count": int(c["count"])} for c in by_country],
        "presets": preset_list,
        "recent": recent,
        "daily": daily_list,
    })


_DUAL_IMG_PREFIX_RE = re.compile(r'^\[IMAGE URL \d+[^\]]*\]\s*', re.IGNORECASE)

async def _match_preset_by_prompts(fields: Dict) -> str:
    """Find showcase preset_id by matching prompts. MySQL primary, in-memory fallback."""
    ip_prompt = fields.get("image_prompt_positive", "").strip()
    vp_prompt = fields.get("video_prompt_positive", "").strip()
    raw_dual = fields.get("prompt", "").strip()
    # Strip iOS placeholder prefix (may appear multiple times): "[IMAGE URL 1 - ...] [IMAGE URL 2 - ...] actual prompt"
    dual_prompt = raw_dual
    while True:
        stripped = _DUAL_IMG_PREFIX_RE.sub("", dual_prompt).strip()
        if stripped == dual_prompt:
            break
        dual_prompt = stripped

    if not ip_prompt and not vp_prompt and not dual_prompt:
        return ""

    matched_id = ""
    match_method = ""

    # ── 1. MySQL exact match ──
    pool = await _get_mysql_pool()
    if pool:
        try:
            async with pool.acquire() as conn:
                async with conn.cursor() as cur:
                    if ip_prompt:
                        await cur.execute(
                            "SELECT id FROM showcase WHERE TRIM(image_prompt_positive)=%s LIMIT 1",
                            (ip_prompt,)
                        )
                        row = await cur.fetchone()
                        if row:
                            matched_id, match_method = row[0], "mysql_exact_img"
                    if not matched_id and vp_prompt:
                        await cur.execute(
                            "SELECT id FROM showcase WHERE TRIM(video_prompt_positive)=%s LIMIT 1",
                            (vp_prompt,)
                        )
                        row = await cur.fetchone()
                        if row:
                            matched_id, match_method = row[0], "mysql_exact_vid"
                    if not matched_id and dual_prompt:
                        await cur.execute(
                            "SELECT id FROM showcase WHERE TRIM(image_prompt_positive)=%s LIMIT 1",
                            (dual_prompt,)
                        )
                        row = await cur.fetchone()
                        if row:
                            matched_id, match_method = row[0], "mysql_exact_dual"
                    # Partial match: first 100 chars
                    if not matched_id and ip_prompt:
                        await cur.execute(
                            "SELECT id FROM showcase WHERE image_prompt_positive LIKE %s LIMIT 1",
                            (ip_prompt[:100] + "%",)
                        )
                        row = await cur.fetchone()
                        if row:
                            matched_id, match_method = row[0], "mysql_partial_img"
                    if not matched_id and vp_prompt:
                        await cur.execute(
                            "SELECT id FROM showcase WHERE video_prompt_positive LIKE %s LIMIT 1",
                            (vp_prompt[:100] + "%",)
                        )
                        row = await cur.fetchone()
                        if row:
                            matched_id, match_method = row[0], "mysql_partial_vid"
        except Exception as e:
            print(f"[preset_match] MySQL error: {e}")

    # ── 2. In-memory fallback ──
    if not matched_id:
        for item in showcase:
            if ip_prompt and item.get("image_prompt_positive", "").strip() == ip_prompt:
                matched_id, match_method = item["id"], "mem_exact_img"
                break
            if vp_prompt and item.get("video_prompt_positive", "").strip() == vp_prompt:
                matched_id, match_method = item["id"], "mem_exact_vid"
                break
    # Dual: search showcase items with type=dual by their prompt field
    if not matched_id and dual_prompt:
        for item in showcase:
            if item.get("type") != "dual":
                continue
            raw_item_prompt = item.get("prompt", "").strip()
            if not raw_item_prompt:
                continue
            # Strip prefix from item prompt too (some items store it with prefix)
            item_prompt = raw_item_prompt
            while True:
                s = _DUAL_IMG_PREFIX_RE.sub("", item_prompt).strip()
                if s == item_prompt: break
                item_prompt = s
            if dual_prompt == item_prompt or dual_prompt.startswith(item_prompt[:80]) or item_prompt.startswith(dual_prompt[:80]):
                matched_id, match_method = item["id"], "mem_exact_dual"
                break
    # Also search legacy dual_showcase list if populated
    if not matched_id and dual_prompt:
        for item in dual_showcase:
            item_prompt = item.get("prompt", "").strip()
            if item_prompt and (dual_prompt == item_prompt or dual_prompt.startswith(item_prompt[:80])):
                matched_id, match_method = item["id"], "mem_exact_dual2"
                break
    if not matched_id and ip_prompt:
        prefix = ip_prompt[:80]
        for item in showcase:
            if item.get("image_prompt_positive", "").startswith(prefix):
                matched_id, match_method = item["id"], "mem_partial_img"
                break

    if matched_id:
        debug_log.info("preset_match", f"Matched preset_id={matched_id} via {match_method} | img_prompt={ip_prompt[:60]!r}")
    else:
        debug_log.warn("preset_match", f"No preset match | img={ip_prompt[:60]!r} vid={vp_prompt[:60]!r} dual={dual_prompt[:60]!r}")

    return matched_id


async def api_stats_preset_detail(request):
    """GET /api/stats/preset/{preset_id} — all generations for a preset."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    preset_id = request.match_info.get("preset_id", "")
    server_url = os.getenv("PORTAL_PUBLIC_URL", PORTAL_PUBLIC_URL)

    def _url(p):
        if not p: return None
        return f"{server_url}{p}" if str(p).startswith("/") else p

    pool = await _get_mysql_pool()
    if not pool:
        return web.json_response({"error": "MySQL unavailable"}, status=503)
    try:
        async with pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("""
                    SELECT UNIX_TIMESTAMP(ts) AS ts, job_id, type, source, status,
                           gen_sec, cost_usd, preview, request_json, ip, country_code, country_name
                    FROM generations
                    WHERE preset_id = %s
                    ORDER BY ts DESC LIMIT 200
                """, (preset_id,))
                rows = await cur.fetchall()
    except Exception as e:
        return web.json_response({"error": str(e)}, status=500)

    gens = []
    for r in rows:
        rj = {}
        try:
            rj = json.loads(r["request_json"]) if r.get("request_json") else {}
        except Exception:
            pass
        # Extract source images
        src1 = rj.get("image") or rj.get("source_image") or rj.get("image1") or ""
        src2 = rj.get("image2") or ""
        gens.append({
            "ts": float(r["ts"]) if r["ts"] else 0,
            "job_id": r.get("job_id", ""),
            "type": r.get("type", ""),
            "source": r.get("source", ""),
            "status": r.get("status", ""),
            "gen_sec": float(r["gen_sec"]) if r.get("gen_sec") else None,
            "cost_usd": float(r["cost_usd"]) if r.get("cost_usd") else None,
            "result": _url(r.get("preview")),
            "source_img": _url(src1),
            "source_img2": _url(src2) if src2 else None,
            "ip": r.get("ip", "").split(",")[0].strip(),
            "country_code": r.get("country_code", "") or "",
            "country_name": r.get("country_name", "") or "",
        })

    # Preset info from showcase
    sc_item = next((s for s in showcase if s.get("id") == preset_id), None)
    preset_info = {}
    if sc_item:
        preset_info = {
            "name": sc_item.get("name", preset_id),
            "type": sc_item.get("type", ""),
            "preview": _url(sc_item.get("generated_image") or sc_item.get("source_image")),
        }

    return web.json_response({"preset_id": preset_id, "preset": preset_info, "generations": gens})


async def api_stats_log(request):
    """GET /api/stats/log — recent API requests from MySQL."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    pool = await _get_mysql_pool()
    if not pool:
        return web.json_response({"error": "MySQL unavailable"}, status=503)
    limit = min(int(request.rel_url.query.get("limit", 200)), 1000)
    path_filter = request.rel_url.query.get("path", "")
    ip_filter = request.rel_url.query.get("ip", "")
    ios_only = request.rel_url.query.get("ios_only", "0") == "1"
    try:
        async with pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                where = "WHERE 1=1"
                params = []
                if ios_only:
                    where += " AND (user_agent LIKE %s OR user_agent LIKE %s)"
                    params.extend(["%GrokMVP%", "%GPUPortalApp%"])
                if path_filter:
                    where += " AND path LIKE %s"
                    params.append(f"%{path_filter}%")
                if ip_filter:
                    where += " AND ip LIKE %s"
                    params.append(f"%{ip_filter}%")
                await cur.execute(
                    f"SELECT ts, method, path, ip, user_agent, status_code, response_ms, country_code, country_name, isp FROM api_requests {where} ORDER BY ts DESC LIMIT %s",
                    params + [limit]
                )
                rows = await cur.fetchall()
                # Summary stats
                await cur.execute("SELECT COUNT(*) as total, MAX(ts) as last FROM api_requests")
                summary = await cur.fetchone()
        entries = [{
            "ts": r["ts"].isoformat() if r["ts"] else None,
            "method": r["method"],
            "path": r["path"],
            "ip": r["ip"] or "",
            "user_agent": r["user_agent"] or "",
            "status": r["status_code"],
            "ms": r["response_ms"],
            "country_code": r["country_code"] or "",
            "country_name": r["country_name"] or "",
            "isp": r["isp"] or "",
        } for r in rows]
        return web.json_response({
            "total": int(summary["total"] or 0) if summary else 0,
            "last": summary["last"].isoformat() if summary and summary["last"] else None,
            "entries": entries,
        })
    except Exception as e:
        return web.json_response({"error": str(e)}, status=500)


async def api_stats_users(request):
    """GET /api/stats/users — list IPs with generation counts + catalog sessions (from MySQL)."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    pool = await _get_mysql_pool()
    if not pool:
        return web.json_response({"error": "MySQL unavailable"}, status=503)
    try:
        async with pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                # Generation stats per real IP (group by first IP in X-Forwarded-For chain)
                await cur.execute("""
                    SELECT SUBSTRING_INDEX(ip, ',', 1) AS ip,
                           COUNT(*) AS total,
                           SUM(status='done') AS done,
                           SUM(status='error') AS errors,
                           MAX(ts) AS last_gen,
                           MIN(ts) AS first_gen,
                           MAX(source) AS source,
                           MAX(user_agent) AS user_agent
                    FROM generations
                    WHERE ip IS NOT NULL AND ip != ''
                    GROUP BY SUBSTRING_INDEX(ip, ',', 1)
                    ORDER BY total DESC
                    LIMIT 200
                """)
                gen_rows = await cur.fetchall()
                # Catalog session hits per IP (= app installs/opens)
                await cur.execute("""
                    SELECT SUBSTRING_INDEX(ip, ',', 1) AS ip,
                           COUNT(*) AS catalog_hits,
                           MIN(ts) AS first_session,
                           MAX(ts) AS last_session,
                           MAX(user_agent) AS user_agent
                    FROM api_requests
                    WHERE path LIKE '/api/ios/%catalog%' OR path LIKE '/api/ios/public/catalog%'
                    GROUP BY SUBSTRING_INDEX(ip, ',', 1)
                """)
                cat_rows = await cur.fetchall()
                # iOS requests on generate endpoints (incl. 401 failed attempts) per IP
                await cur.execute("""
                    SELECT SUBSTRING_INDEX(ip, ',', 1) AS ip,
                           COUNT(*) AS attempts,
                           SUM(status_code = 401) AS auth_errors,
                           MIN(ts) AS first_attempt,
                           MAX(ts) AS last_attempt,
                           MAX(user_agent) AS user_agent
                    FROM api_requests
                    WHERE (path LIKE '/api/showcase/generate%' OR path LIKE '/api/dual/generate%')
                    AND (user_agent LIKE '%GrokMVP%' OR user_agent LIKE '%GPUPortalApp%')
                    GROUP BY SUBSTRING_INDEX(ip, ',', 1)
                """)
                attempt_rows = await cur.fetchall()
                # Premium / token data from users table
                await cur.execute("""
                    SELECT real_ip, tokens_used, premium_since FROM users
                """)
                premium_rows = await cur.fetchall()
        cat_map = {r["ip"].strip(): r for r in cat_rows}
        attempt_map = {r["ip"].strip(): r for r in attempt_rows}
        premium_map = {r["real_ip"].strip(): r for r in premium_rows}
        # Collect IPs from api_requests not in generations table
        gen_ips = {r["ip"] for r in gen_rows}
        extra_ips = set()
        extra = []
        for ip_raw, r in cat_map.items():
            if ip_raw not in gen_ips and ip_raw not in extra_ips:
                extra_ips.add(ip_raw)
                extra.append({
                    "ip": ip_raw,
                    "total": 0, "done": 0, "errors": 0,
                    "last_seen": r["last_session"].isoformat() if r["last_session"] else None,
                    "first_seen": r["first_session"].isoformat() if r["first_session"] else None,
                    "source": "ios",
                    "user_agent": r["user_agent"] or "",
                    "catalog_hits": int(r["catalog_hits"] or 0),
                    "first_session": r["first_session"].isoformat() if r["first_session"] else None,
                    "auth_errors": 0,
                })
        for ip_raw, r in attempt_map.items():
            if ip_raw not in gen_ips and ip_raw not in extra_ips:
                extra_ips.add(ip_raw)
                extra.append({
                    "ip": ip_raw,
                    "total": 0, "done": 0, "errors": int(r["auth_errors"] or 0),
                    "last_seen": r["last_attempt"].isoformat() if r["last_attempt"] else None,
                    "first_seen": r["first_attempt"].isoformat() if r["first_attempt"] else None,
                    "source": "ios",
                    "user_agent": r["user_agent"] or "",
                    "catalog_hits": 0,
                    "first_session": None,
                    "auth_errors": int(r["auth_errors"] or 0),
                })
        def _enrich_geo(ip: str) -> dict:
            g = _geoip_lookup(ip)
            return {"country_code": g.get("country_code",""), "country_name": g.get("country_name",""), "isp": g.get("isp","")}

        users = []
        for r in gen_rows:
            real_ip = r["ip"].strip()
            cat = cat_map.get(real_ip, {})
            att = attempt_map.get(real_ip, {})
            prem = premium_map.get(real_ip, {})
            first = r["first_gen"] or cat.get("first_session")
            last = r["last_gen"] or cat.get("last_session")
            geo = _enrich_geo(real_ip)
            tokens_used = int(prem.get("tokens_used") or 0)
            users.append({
                "ip": real_ip,
                "total": int(r["total"] or 0),
                "done": int(r["done"] or 0),
                "errors": int(r["errors"] or 0),
                "auth_errors": int(att.get("auth_errors") or 0),
                "last_seen": last.isoformat() if last else None,
                "first_seen": first.isoformat() if first else None,
                "source": r["source"] or "portal",
                "user_agent": r["user_agent"] or "",
                "catalog_hits": int(cat.get("catalog_hits") or 0) if cat else 0,
                "first_session": cat["first_session"].isoformat() if cat and cat.get("first_session") else None,
                "tokens_used": tokens_used,
                "is_premium": tokens_used > 200,
                "premium_since": prem["premium_since"].isoformat() if prem.get("premium_since") else None,
                **geo,
            })
        for u in extra:
            geo = _enrich_geo(u["ip"])
            u.update(geo)
            prem = premium_map.get(u["ip"], {})
            tokens_used = int(prem.get("tokens_used") or 0)
            u["tokens_used"] = tokens_used
            u["is_premium"] = tokens_used > 200
            u["premium_since"] = prem["premium_since"].isoformat() if prem.get("premium_since") else None
        users.extend(extra)
        users.sort(key=lambda x: x["last_seen"] or "", reverse=True)
        return web.json_response({"users": users})
    except Exception as e:
        return web.json_response({"error": str(e)}, status=500)


async def api_stats_user_detail(request):
    """GET /api/stats/users/{ip} — all generations for a single IP."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    ip = request.match_info["ip"]
    pool = await _get_mysql_pool()
    if not pool:
        return web.json_response({"error": "MySQL unavailable"}, status=503)
    server_url = os.getenv("PORTAL_PUBLIC_URL", PORTAL_PUBLIC_URL)
    try:
        async with pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                # Match by real IP (first in X-Forwarded-For chain)
                await cur.execute("""
                    SELECT job_id, ts, preset_id, template_id, type, source, status,
                           step, gen_sec, total_sec, cost_usd, preview, error,
                           request_json, grok_response, user_agent
                    FROM generations
                    WHERE SUBSTRING_INDEX(ip, ',', 1) = %s
                    ORDER BY ts DESC
                    LIMIT 200
                """, (ip,))
                rows = await cur.fetchall()
                # Also fetch catalog session events for this IP
                await cur.execute("""
                    SELECT ts, path, user_agent
                    FROM api_requests
                    WHERE (path LIKE '/api/ios/%%catalog%%' OR path LIKE '/api/ios/public/catalog%%')
                    AND (ip = %s OR ip LIKE %s)
                    ORDER BY ts ASC
                    LIMIT 100
                """, (ip, f"{ip},%"))
                cat_rows = await cur.fetchall()
        gens = []
        for r in rows:
            pid = r["preset_id"] or ""
            # Enrich with showcase data
            sc_item = next((s for s in showcase if s.get("id") == pid), None)
            if not sc_item:
                sc_item = next((s for s in dual_showcase if s.get("id") == pid), None)
            name = sc_item["name"] if sc_item else (pid or r["template_id"] or "—")
            def _url(p):
                if not p: return None
                return f"{server_url}{p}" if str(p).startswith("/") else str(p)
            split_preview = None
            source_image = None
            video_url = None
            if sc_item:
                split_preview = _url(sc_item.get("split_preview") or sc_item.get("generated_image") or sc_item.get("result_image"))
                source_image = _url(sc_item.get("source_image"))
                video_url = _url(sc_item.get("video_url"))
            preview = _url(r["preview"]) if r["preview"] else split_preview
            # Extract source images from request_json
            req_src = None
            req_src2 = None
            gen_video_url = None
            try:
                rj = json.loads(r["request_json"] or "{}")
                raw_src = rj.get("source_image_original") or rj.get("source_image") or rj.get("image1") or ""
                req_src = _url(raw_src)
                raw_src2 = rj.get("image2") or ""
                req_src2 = _url(raw_src2) if raw_src2 else None
            except Exception:
                pass
            # Extract actual generated video from grok_response outputs
            try:
                gr = json.loads(r["grok_response"] or "{}")
                for out in gr.get("outputs", []):
                    if str(out).endswith(".mp4"):
                        gen_video_url = _url(out)
                        break
            except Exception:
                pass
            gens.append({
                "job_id": r["job_id"],
                "ts": r["ts"].isoformat() if r["ts"] else None,
                "preset_id": pid,
                "preset_name": name,
                "type": r["type"] or "video",
                "source": r["source"] or "portal",
                "status": r["status"] or "queued",
                "step": r["step"] or "",
                "gen_sec": float(r["gen_sec"]) if r["gen_sec"] else None,
                "total_sec": float(r["total_sec"]) if r["total_sec"] else None,
                "cost_usd": float(r["cost_usd"]) if r["cost_usd"] else None,
                "error": r["error"],
                "preview": preview,
                "split_preview": split_preview,
                "source_image": source_image,
                "req_source_image": req_src,
                "req_source_image2": req_src2,
                "video_url": gen_video_url or video_url,
                "gen_video_url": gen_video_url,
                "request_json": r["request_json"],
                "user_agent": r["user_agent"] or "",
                "template_id": r["template_id"] or "",
            })
        sessions = [{"ts": r["ts"].isoformat() if r["ts"] else None, "path": r["path"], "user_agent": r["user_agent"] or ""} for r in cat_rows]
        return web.json_response({"ip": ip, "total": len(gens), "generations": gens, "sessions": sessions})
    except Exception as e:
        return web.json_response({"error": str(e)}, status=500)


async def api_ios_template_get(request):
    """Public: get single template by showcase ID."""
    sid = request.match_info["id"]
    ref = {"id": sid, "tags": []}
    resolved = _resolve_ios_item(ref)
    if not resolved:
        return web.json_response({"error": "not found"}, status=404)
    # Add full prompts
    sc = next((s for s in showcase if s.get("id") == sid), None)
    if sc:
        resolved["image_prompt_positive"] = sc.get("image_prompt_positive", "")
        resolved["image_prompt_negative"] = sc.get("image_prompt_negative", "")
        resolved["video_prompt_positive"] = sc.get("video_prompt_positive", "")
        resolved["video_prompt_negative"] = sc.get("video_prompt_negative", "")
        resolved["generation_sec"] = sc.get("generation_sec")
        resolved["cost_usd"] = sc.get("cost_usd")
    return web.json_response(resolved)


async def api_ios_catalog_export_json(request):
    """Export resolved catalog with local media/ paths (for ZIP bundle)."""
    result = {"tabs": {}}
    for tab_name, tab_data in ios_catalog.get("tabs", {}).items():
        sections = []
        for sec in tab_data.get("sections", []):
            items = []
            for ref in sorted(sec.get("items", []), key=lambda x: x.get("sort_order", 0)):
                if ref.get("hidden"):
                    continue
                resolved = _resolve_ios_item(ref, local_paths=True)
                if resolved:
                    items.append(resolved)
            sections.append({"id": sec["id"], "name": sec["name"], "icon": sec.get("icon", ""), "items": items})
        result["tabs"][tab_name] = {"sections": sections}

    # Main GIF banners with local paths
    if "main_gif_banners" in ios_catalog:
        banners = []
        for b in ios_catalog["main_gif_banners"]:
            sc = next((s for s in showcase if s.get("id") == b.get("id")), None)
            if sc and sc.get("gif_url"):
                gif_fname = Path(sc["gif_url"]).name
                banners.append({
                    "id": b["id"],
                    "name": b.get("name", sc.get("name", "")),
                    "description": b.get("description", b.get("name", sc.get("name", ""))),
                    "subtitle": b.get("subtitle", ""),
                    "gif": f"media/{gif_fname}",
                })
        result["main_gif_banners"] = banners

    resp = web.Response(
        text=json.dumps(result, indent=2, ensure_ascii=False),
        content_type="application/json",
        headers={"Content-Disposition": "attachment; filename=ios_catalog.json"},
    )
    return resp


async def api_ios_catalog_export_tab_json(request):
    """Export single tab as JSON with local media/ paths."""
    tab_id = request.match_info.get("tab_id", "")
    tab_data = ios_catalog.get("tabs", {}).get(tab_id)
    if not tab_data:
        return web.json_response({"error": f"tab '{tab_id}' not found"}, status=404)
    sections = []
    for sec in tab_data.get("sections", []):
        items = []
        for ref in sec.get("items", []):
            resolved = _resolve_ios_item(ref, local_paths=True)
            if resolved:
                items.append(resolved)
        if items:
            sections.append({"id": sec["id"], "name": sec["name"], "icon": sec.get("icon",""), "items": items})
    result = {"tab": tab_id, "sections": sections, "total": sum(len(s["items"]) for s in sections)}
    fname = f"{tab_id}_catalog.json"
    return web.Response(
        text=json.dumps(result, indent=2, ensure_ascii=False),
        content_type="application/json",
        headers={"Content-Disposition": f"attachment; filename={fname}"},
    )


async def api_ios_catalog_export_tab_zip(request):
    """Export single tab JSON + its media files as ZIP."""
    import zipfile, io
    tab_id = request.match_info.get("tab_id", "")
    tab_data = ios_catalog.get("tabs", {}).get(tab_id)
    if not tab_data:
        return web.json_response({"error": f"tab '{tab_id}' not found"}, status=404)
    sections = []
    media_files = set()
    for sec in tab_data.get("sections", []):
        items = []
        for ref in sec.get("items", []):
            resolved = _resolve_ios_item(ref, local_paths=True)
            if not resolved:
                continue
            for field in ("source_image","generated_image","video_url","split_preview","result_image","image1","image2","preview_url","preview_zip"):
                val = resolved.get(field,"")
                if val and val.startswith("media/"):
                    media_files.add(val.replace("media/",""))
            items.append(resolved)
        if items:
            sections.append({"id": sec["id"], "name": sec["name"], "icon": sec.get("icon",""), "items": items})
    catalog = {"tab": tab_id, "sections": sections, "total": sum(len(s["items"]) for s in sections)}
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(f"{tab_id}_catalog.json", json.dumps(catalog, indent=2, ensure_ascii=False))
        for fname in media_files:
            local = SHOWCASE_DIR / fname
            if local.exists():
                zf.write(str(local), f"media/{fname}")
    buf.seek(0)
    return web.Response(
        body=buf.read(),
        content_type="application/zip",
        headers={"Content-Disposition": f"attachment; filename={tab_id}_catalog.zip"},
    )


async def api_ios_catalog_export_tab_zip_images(request):
    """Export single tab JSON + images only (no videos). Videos stay as URLs."""
    import zipfile, io
    tab_id = request.match_info.get("tab_id", "")
    tab_data = ios_catalog.get("tabs", {}).get(tab_id)
    if not tab_data:
        return web.json_response({"error": f"tab '{tab_id}' not found"}, status=404)

    server_url = f"http://{_get_server_ip()}:8899"
    video_exts = (".mp4", ".mov", ".webm", ".flac", ".wav")
    sections = []
    media_files = set()
    gif_files = set()

    for sec in tab_data.get("sections", []):
        items = []
        for ref in sec.get("items", []):
            resolved = _resolve_ios_item(ref, local_paths=True)
            if not resolved:
                continue
            # Collect image files
            for field in ("source_image", "generated_image", "split_preview", "result_image", "image1", "image2"):
                val = resolved.get(field, "")
                if val and val.startswith("media/"):
                    media_files.add(val.replace("media/", ""))
            # Videos → full URLs
            for field in ("video_url", "gif_url"):
                val = resolved.get(field, "")
                if val and val.startswith("media/"):
                    fname = val.replace("media/", "")
                    resolved[field] = f"{server_url}/showcase/{fname}"
            items.append(resolved)
        if items:
            sections.append({"id": sec["id"], "name": sec["name"], "icon": sec.get("icon", ""), "items": items})

    catalog = {"tab": tab_id, "sections": sections, "total": sum(len(s["items"]) for s in sections)}

    # Include GIF banners if this is the main tab
    if tab_id == "main" and "main_gif_banners" in ios_catalog:
        banners = []
        for b in ios_catalog["main_gif_banners"]:
            sc = next((s for s in showcase if s.get("id") == b.get("id")), None)
            if sc and sc.get("gif_url"):
                gif_fname = Path(sc["gif_url"]).name
                banners.append({
                    "id": b["id"],
                    "name": b.get("name", sc.get("name", "")),
                    "description": b.get("description", b.get("name", sc.get("name", ""))),
                    "subtitle": b.get("subtitle", ""),
                    "gif": f"media/{gif_fname}",
                })
                gif_files.add(gif_fname)
        catalog["main_gif_banners"] = banners

    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(f"{tab_id}_catalog.json", json.dumps(catalog, indent=2, ensure_ascii=False))
        for fname in media_files:
            if any(fname.lower().endswith(ext) for ext in video_exts):
                continue
            local = SHOWCASE_DIR / fname
            if not local.exists():
                local = SHOWCASE_DIR / "giffs" / fname
            if local.exists():
                zf.write(str(local), f"media/{fname}")
        for fname in gif_files:
            local = SHOWCASE_DIR / fname
            if not local.exists():
                local = SHOWCASE_DIR / "giffs" / fname
            if local.exists():
                zf.write(str(local), f"media/{fname}")

    buf.seek(0)
    return web.Response(
        body=buf.read(),
        content_type="application/zip",
        headers={"Content-Disposition": f"attachment; filename={tab_id}_catalog_images.zip"},
    )


async def api_ios_catalog_export_zip(request):
    """Export catalog JSON (local paths) + all media as ZIP."""
    import zipfile
    import io

    buf = io.BytesIO()
    catalog = {"tabs": {}}
    media_files = set()

    # Build catalog with local paths and collect media filenames
    for tab_name, tab_data in ios_catalog.get("tabs", {}).items():
        sections = []
        for sec in tab_data.get("sections", []):
            items = []
            for ref in sec.get("items", []):
                resolved = _resolve_ios_item(ref, local_paths=True)
                if not resolved:
                    continue
                # Collect media filenames
                for field in ("source_image", "generated_image", "video_url", "split_preview", "result_image", "image1", "image2", "gif_url"):
                    val = resolved.get(field, "")
                    if val and val.startswith("media/"):
                        media_files.add(val.replace("media/", ""))
                items.append(resolved)
            sections.append({"id": sec["id"], "name": sec["name"], "icon": sec.get("icon", ""), "items": items})
        catalog["tabs"][tab_name] = {"sections": sections}

    # Main GIF banners with local paths
    if "main_gif_banners" in ios_catalog:
        banners = []
        for b in ios_catalog["main_gif_banners"]:
            sc = next((s for s in showcase if s.get("id") == b.get("id")), None)
            if sc and sc.get("gif_url"):
                gif_fname = Path(sc["gif_url"]).name
                banners.append({
                    "id": b["id"],
                    "name": b.get("name", sc.get("name", "")),
                    "description": b.get("description", b.get("name", sc.get("name", ""))),
                    "subtitle": b.get("subtitle", ""),
                    "gif": f"media/{gif_fname}",
                })
                media_files.add(gif_fname)
        catalog["main_gif_banners"] = banners

    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("catalog.json", json.dumps(catalog, indent=2, ensure_ascii=False))
        for fname in media_files:
            local = SHOWCASE_DIR / fname
            if not local.exists():
                # Check in giffs/ subfolder
                local = SHOWCASE_DIR / "giffs" / fname
            if local.exists():
                zf.write(str(local), f"media/{fname}")

    buf.seek(0)
    return web.Response(
        body=buf.read(),
        content_type="application/zip",
        headers={"Content-Disposition": "attachment; filename=ios_catalog.zip"},
    )


ZIP_IMAGES_CACHE_FILE = SHOWCASE_DIR / "ios_catalog_images.zip"
_zip_rebuild_task = None

def _build_zip_images_sync():
    """Build optimized images-only ZIP (112x200 thumbnails 9:16, videos as URLs). ~15-20MB."""
    import zipfile, io, subprocess, tempfile
    from PIL import Image as PILImage

    TW, TH = 112, 200  # 9:16 ratio — matches source images, no crop
    server_url = f"http://{_get_server_ip()}:8899"
    buf = io.BytesIO()
    catalog = {"tabs": {}}
    media_files = set()
    video_exts = (".mp4", ".mov", ".webm", ".flac", ".wav")

    for tab_name, tab_data in ios_catalog.get("tabs", {}).items():
        sections = []
        for sec in tab_data.get("sections", []):
            items = []
            for ref in sorted(sec.get("items", []), key=lambda x: x.get("sort_order", 0)):
                if ref.get("hidden"):
                    continue
                resolved = _resolve_ios_item(ref, local_paths=True)
                if not resolved:
                    continue
                for field in ("source_image", "generated_image", "split_preview", "result_image", "image1", "image2"):
                    val = resolved.get(field, "")
                    if val and val.startswith("media/"):
                        media_files.add(val.replace("media/", ""))
                for field in ("video_url", "gif_url"):
                    val = resolved.get(field, "")
                    if val and val.startswith("media/"):
                        fname = val.replace("media/", "")
                        resolved[field] = f"{server_url}/showcase/{fname}"
                items.append(resolved)
            sections.append({"id": sec["id"], "name": sec["name"], "icon": sec.get("icon", ""), "items": items})
        catalog["tabs"][tab_name] = {"sections": sections}

    gif_files = set()
    if "main_gif_banners" in ios_catalog:
        banners = []
        for b in ios_catalog["main_gif_banners"]:
            sc = next((s for s in showcase if s.get("id") == b.get("id")), None)
            if sc and sc.get("gif_url"):
                gif_fname = Path(sc["gif_url"]).name
                mp4_fname = gif_fname.rsplit(".", 1)[0] + ".mp4"
                banners.append({
                    "id": b["id"],
                    "name": b.get("name", sc.get("name", "")),
                    "description": b.get("description", b.get("name", sc.get("name", ""))),
                    "subtitle": b.get("subtitle", ""),
                    "gif": f"media/{mp4_fname}",
                })
                gif_files.add(gif_fname)
        catalog["main_gif_banners"] = banners

    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("catalog.json", json.dumps(catalog, indent=2, ensure_ascii=False))
        # Images → 134x200 JPEG thumbnails
        for fname in media_files:
            if any(fname.lower().endswith(ext) for ext in video_exts):
                continue
            local = SHOWCASE_DIR / fname
            if not local.exists():
                local = SHOWCASE_DIR / "giffs" / fname
            if not local.exists():
                continue
            ext = fname.rsplit(".", 1)[-1].lower() if "." in fname else ""
            if ext in ("jpg", "jpeg", "png", "webp"):
                try:
                    img = PILImage.open(str(local)).convert("RGB")
                    sw, sh = img.size
                    tr = TW / TH
                    sr = sw / sh
                    if sr > tr:
                        nw = int(sh * tr)
                        off = (sw - nw) // 2
                        img = img.crop((off, 0, off + nw, sh))
                    else:
                        nh = int(sw / tr)
                        off = (sh - nh) // 2
                        img = img.crop((0, off, sw, off + nh))
                    img = img.resize((TW, TH), PILImage.LANCZOS)
                    img_buf = io.BytesIO()
                    img.save(img_buf, format="JPEG", quality=80, optimize=True)
                    img_buf.seek(0)
                    out_name = fname.rsplit(".", 1)[0] + ".jpg"
                    zf.writestr(f"media/{out_name}", img_buf.read())
                except Exception:
                    zf.write(str(local), f"media/{fname}")
            else:
                zf.write(str(local), f"media/{fname}")
        # GIF banners → MP4
        for gif_fname in gif_files:
            local = SHOWCASE_DIR / "giffs" / gif_fname
            if not local.exists():
                local = SHOWCASE_DIR / gif_fname
            if not local.exists():
                continue
            try:
                with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as tmp:
                    tmp_path = tmp.name
                subprocess.run([
                    "ffmpeg", "-y", "-i", str(local),
                    "-c:v", "libx264", "-preset", "fast", "-crf", "23",
                    "-pix_fmt", "yuv420p", "-an", "-movflags", "+faststart",
                    tmp_path
                ], capture_output=True, timeout=120)
                mp4_fname = gif_fname.rsplit(".", 1)[0] + ".mp4"
                zf.write(tmp_path, f"media/{mp4_fname}")
                os.unlink(tmp_path)
            except Exception:
                zf.write(str(local), f"media/{gif_fname}")

    buf.seek(0)
    ZIP_IMAGES_CACHE_FILE.write_bytes(buf.read())
    size_mb = ZIP_IMAGES_CACHE_FILE.stat().st_size / 1024 / 1024
    debug_log.info("zip", f"ZIP images cache rebuilt: {size_mb:.1f}MB ({len(media_files)} images, {len(gif_files)} gifs)")
    return size_mb


async def _zip_rebuild_loop():
    """Rebuild ZIP cache every hour."""
    await asyncio.sleep(10)  # Initial build after startup
    while True:
        try:
            await asyncio.to_thread(_build_zip_images_sync)
        except Exception as e:
            debug_log.error("zip", f"ZIP rebuild failed: {e}")
        await asyncio.sleep(3600)  # Every hour


async def api_ios_catalog_export_zip_images_only(request):
    """Serve cached optimized ZIP (134x200 thumbnails). Rebuilt every hour."""
    ua = request.headers.get("User-Agent", "")
    if "GrokMVP" in ua or "GPUPortalApp" in ua:
        ip = request.headers.get("X-Forwarded-For", request.remote or "").split(",")[0].strip()
        if ip:
            asyncio.ensure_future(tg_notify_new_session(ip, ua))
    if not ZIP_IMAGES_CACHE_FILE.exists():
        await asyncio.to_thread(_build_zip_images_sync)
    if ZIP_IMAGES_CACHE_FILE.exists():
        return web.FileResponse(
            ZIP_IMAGES_CACHE_FILE,
            headers={"Content-Disposition": "attachment; filename=ios_catalog_images.zip"},
        )
    return web.json_response({"error": "ZIP cache not available"}, status=503)


async def api_ios_catalog_export_zip_v2(request):
    """Export catalog JSON + all media resized to 134x200 as optimized ZIP.
    Serves pre-built cache if available (rebuilt hourly by cron)."""
    cached = PORTAL_DIR / "public" / "ios_catalog_v2_latest.zip"
    if cached.exists() and cached.stat().st_size > 0:
        return web.FileResponse(cached, headers={
            "Content-Disposition": "attachment; filename=ios_catalog_v2.zip",
            "X-Catalog-Built": str(int(cached.stat().st_mtime)),
        })

    import zipfile
    import io
    import subprocess
    import tempfile
    from PIL import Image as PILImage

    TARGET_W, TARGET_H = 112, 200  # 9:16 ratio — matches source

    buf = io.BytesIO()
    catalog = {"tabs": {}}
    media_files = set()

    # Build catalog with local paths and collect media filenames
    for tab_name, tab_data in ios_catalog.get("tabs", {}).items():
        sections = []
        for sec in tab_data.get("sections", []):
            items = []
            for ref in sorted(sec.get("items", []), key=lambda x: x.get("sort_order", 0)):
                if ref.get("hidden"):
                    continue
                resolved = _resolve_ios_item(ref, local_paths=True)
                if not resolved:
                    continue
                for field in ("source_image", "generated_image", "video_url", "split_preview", "result_image", "image1", "image2", "gif_url"):
                    val = resolved.get(field, "")
                    if val and val.startswith("media/"):
                        media_files.add(val.replace("media/", ""))
                items.append(resolved)
            sections.append({"id": sec["id"], "name": sec["name"], "icon": sec.get("icon", ""), "items": items})
        catalog["tabs"][tab_name] = {"sections": sections}

    # Main GIF banners — convert to MP4 in V2
    gif_banner_files = set()
    if "main_gif_banners" in ios_catalog:
        banners = []
        for b in ios_catalog["main_gif_banners"]:
            sc = next((s for s in showcase if s.get("id") == b.get("id")), None)
            if sc and sc.get("gif_url"):
                gif_fname = Path(sc["gif_url"]).name
                mp4_fname = gif_fname.rsplit(".", 1)[0] + ".mp4"
                banners.append({
                    "id": b["id"],
                    "name": b.get("name", sc.get("name", "")),
                    "description": b.get("description", b.get("name", sc.get("name", ""))),
                    "subtitle": b.get("subtitle", ""),
                    "gif": f"media/{mp4_fname}",
                })
                gif_banner_files.add(gif_fname)
        catalog["main_gif_banners"] = banners

    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("catalog.json", json.dumps(catalog, indent=2, ensure_ascii=False))

        # Process GIF banners — keep original 900x730, convert GIF→MP4
        for gif_fname in gif_banner_files:
            local = SHOWCASE_DIR / "giffs" / gif_fname
            if not local.exists():
                local = SHOWCASE_DIR / gif_fname
            if not local.exists():
                continue
            try:
                with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as tmp:
                    tmp_path = tmp.name
                subprocess.run([
                    "ffmpeg", "-y", "-i", str(local),
                    "-c:v", "libx264", "-preset", "fast", "-crf", "23",
                    "-pix_fmt", "yuv420p", "-an", "-movflags", "+faststart",
                    tmp_path
                ], capture_output=True, timeout=120)
                mp4_fname = gif_fname.rsplit(".", 1)[0] + ".mp4"
                zf.write(tmp_path, f"media/{mp4_fname}")
                os.unlink(tmp_path)
            except Exception:
                zf.write(str(local), f"media/{gif_fname}")

        # Process regular media files
        for fname in media_files:
            local = SHOWCASE_DIR / fname
            if not local.exists():
                local = SHOWCASE_DIR / "giffs" / fname
            if not local.exists():
                continue

            ext = fname.rsplit(".", 1)[-1].lower() if "." in fname else ""

            if ext in ("jpg", "jpeg", "png", "webp"):
                # Cover-crop image to 134x200 (preserve aspect ratio, crop center)
                try:
                    img = PILImage.open(str(local))
                    img = img.convert("RGB")
                    src_w, src_h = img.size
                    target_ratio = TARGET_W / TARGET_H  # 0.67
                    src_ratio = src_w / src_h
                    if src_ratio > target_ratio:
                        # Source is wider — crop sides
                        new_w = int(src_h * target_ratio)
                        offset = (src_w - new_w) // 2
                        img = img.crop((offset, 0, offset + new_w, src_h))
                    else:
                        # Source is taller — crop top/bottom
                        new_h = int(src_w / target_ratio)
                        offset = (src_h - new_h) // 2
                        img = img.crop((0, offset, src_w, offset + new_h))
                    img = img.resize((TARGET_W, TARGET_H), PILImage.LANCZOS)
                    img_buf = io.BytesIO()
                    img.save(img_buf, format="JPEG", quality=80, optimize=True)
                    img_buf.seek(0)
                    # Change extension to .jpg in archive
                    out_name = fname.rsplit(".", 1)[0] + ".jpg"
                    zf.writestr(f"media/{out_name}", img_buf.read())
                except Exception:
                    zf.write(str(local), f"media/{fname}")

            elif ext == "mp4":
                # Cover-crop video to 134x200 (same as images — no black bars)
                try:
                    with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as tmp:
                        tmp_path = tmp.name
                    # crop to target aspect ratio from center, then scale down
                    crop_filter = (
                        f"crop=if(gt(iw/ih\\,{TARGET_W}/{TARGET_H})\\,"
                        f"ih*{TARGET_W}/{TARGET_H}\\,iw):"
                        f"if(gt(iw/ih\\,{TARGET_W}/{TARGET_H})\\,"
                        f"ih\\,iw*{TARGET_H}/{TARGET_W}),"
                        f"scale={TARGET_W}:{TARGET_H}"
                    )
                    subprocess.run([
                        "ffmpeg", "-y", "-i", str(local),
                        "-vf", crop_filter,
                        "-c:v", "libx264", "-preset", "fast", "-crf", "28",
                        "-an", "-movflags", "+faststart",
                        tmp_path
                    ], capture_output=True, timeout=60)
                    zf.write(tmp_path, f"media/{fname}")
                    os.unlink(tmp_path)
                except Exception:
                    zf.write(str(local), f"media/{fname}")

            elif ext == "gif":
                # Convert GIF to small mp4 with cover-crop
                try:
                    with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as tmp:
                        tmp_path = tmp.name
                    crop_filter = (
                        f"crop=if(gt(iw/ih\\,{TARGET_W}/{TARGET_H})\\,"
                        f"ih*{TARGET_W}/{TARGET_H}\\,iw):"
                        f"if(gt(iw/ih\\,{TARGET_W}/{TARGET_H})\\,"
                        f"ih\\,iw*{TARGET_H}/{TARGET_W}),"
                        f"scale={TARGET_W}:{TARGET_H}"
                    )
                    subprocess.run([
                        "ffmpeg", "-y", "-i", str(local),
                        "-vf", crop_filter,
                        "-c:v", "libx264", "-preset", "fast", "-crf", "28",
                        "-an", "-movflags", "+faststart",
                        tmp_path
                    ], capture_output=True, timeout=60)
                    out_name = fname.rsplit(".", 1)[0] + ".mp4"
                    zf.writestr(f"media/{out_name}", open(tmp_path, "rb").read())
                    os.unlink(tmp_path)
                except Exception:
                    zf.write(str(local), f"media/{fname}")

            elif ext == "flac":
                # Convert FLAC to MP3
                try:
                    with tempfile.NamedTemporaryFile(suffix=".mp3", delete=False) as tmp:
                        tmp_path = tmp.name
                    subprocess.run([
                        "ffmpeg", "-y", "-i", str(local),
                        "-c:a", "libmp3lame", "-q:a", "6",
                        tmp_path
                    ], capture_output=True, timeout=30)
                    out_name = fname.rsplit(".", 1)[0] + ".mp3"
                    zf.writestr(f"media/{out_name}", open(tmp_path, "rb").read())
                    os.unlink(tmp_path)
                except Exception:
                    zf.write(str(local), f"media/{fname}")
            else:
                zf.write(str(local), f"media/{fname}")

    buf.seek(0)
    return web.Response(
        body=buf.read(),
        content_type="application/zip",
        headers={"Content-Disposition": "attachment; filename=ios_catalog_v2.zip"},
    )


async def api_ios_catalog_auto_populate(request):
    """Auto-populate iOS catalog from existing showcase categories."""
    global ios_catalog

    cats_video = load_categories(CATEGORIES_FILE) if CATEGORIES_FILE.exists() else []
    cats_img2img = load_categories(CATEGORIES_IMG2IMG_FILE) if CATEGORIES_IMG2IMG_FILE.exists() else []
    cats_dual_list = load_categories_dual()

    catalog = {"tabs": {}}
    mapping = [
        ("video", cats_video, "video"),
        ("img2img", cats_img2img, "img2img"),
        ("dual", cats_dual_list, "dual"),
        ("main", [], None),
    ]

    for tab_name, cat_list, sc_type in mapping:
        sections = []
        if tab_name == "main":
            top_liked = sorted(showcase, key=lambda s: s.get("likes", 0), reverse=True)[:20]
            sections.append({"id": "trending", "name": "Trending", "icon": "flame.fill", "pinned": True,
                "items": [{"id": s["id"], "tags": ["trending"], "sort_order": i} for i, s in enumerate(top_liked)]})
            newest = sorted(showcase, key=lambda s: s.get("created_at", 0), reverse=True)[:20]
            sections.append({"id": "new", "name": "New", "icon": "sparkles", "pinned": True,
                "items": [{"id": s["id"], "tags": ["new"], "sort_order": i} for i, s in enumerate(newest)]})
        else:
            all_cat_ids = {c["id"] for c in cat_list}
            for cat in cat_list:
                items_in = [s for s in showcase
                    if s.get("type", "video") == sc_type and (cat["id"] in (s.get("categories") or []) or s.get("category") == cat["id"])]
                sections.append({"id": cat["id"], "name": cat["name"], "icon": cat.get("icon", "folder"), "pinned": False,
                    "items": [{"id": s["id"], "tags": [], "sort_order": i} for i, s in enumerate(items_in)]})

            uncategorized = [s for s in showcase if s.get("type", "video") == sc_type
                and not (set(s.get("categories") or []) | ({s.get("category")} if s.get("category") else set())).intersection(all_cat_ids)]
            if uncategorized:
                sections.append({"id": "uncategorized", "name": "Без категории", "icon": "tray", "pinned": False,
                    "items": [{"id": s["id"], "tags": [], "sort_order": i} for i, s in enumerate(uncategorized)]})

        catalog["tabs"][tab_name] = {"sections": sections}

    ios_catalog = catalog
    save_ios_catalog(catalog)

    stats = {tab: {"sections": len(d["sections"]), "items": sum(len(s["items"]) for s in d["sections"])}
             for tab, d in catalog["tabs"].items()}
    debug_log.info("ios", f"Auto-populated: {stats}")
    return web.json_response({"ok": True, "stats": stats})


async def api_ios_catalog_sync(request):
    """Sync iOS catalog 1-to-1 from defined categories + showcase data.
    Uses categories*.json as section structure (read-only, never written).
    Showcase Catalog filter is completely untouched.

    Main tab: Trending + New, then ALL category sections from video → img2img → dual in sequence.
    Video/Img2Img/Dual tabs: one section per defined category, filled with matching showcase items.
    """
    global ios_catalog

    sc = load_showcase()
    cats_video   = load_categories(CATEGORIES_FILE) if CATEGORIES_FILE.exists() else []
    cats_img2img = load_categories(CATEGORIES_IMG2IMG_FILE) if CATEGORIES_IMG2IMG_FILE.exists() else []
    cats_dual    = json.loads((PORTAL_DIR / "categories_dual.json").read_text()) \
                   if (PORTAL_DIR / "categories_dual.json").exists() else []

    def _item_ref(s, i, tags=None):
        return {"id": s["id"], "tags": tags or [], "sort_order": i}

    def _build_sections(cat_list, sc_type):
        all_cat_ids = {c["id"] for c in cat_list}
        sections = []
        for cat in cat_list:
            items_in = [s for s in sc
                if s.get("type") == sc_type
                and (cat["id"] in (s.get("categories") or []) or s.get("category") == cat["id"])]
            sections.append({
                "id": cat["id"], "name": cat["name"],
                "icon": cat.get("icon", "folder"), "pinned": False,
                "items": [_item_ref(s, i) for i, s in enumerate(items_in)],
            })
        # uncategorized
        uncateg = [s for s in sc if s.get("type") == sc_type
            and not (set(s.get("categories") or []) | ({s.get("category")} if s.get("category") else set())).intersection(all_cat_ids)]
        if uncateg:
            sections.append({"id": "other", "name": "Other", "icon": "tray", "pinned": False,
                "items": [_item_ref(s, i) for i, s in enumerate(uncateg)]})
        return sections

    video_sections   = _build_sections(cats_video,   "video")
    img2img_sections = _build_sections(cats_img2img,  "img2img")
    dual_sections    = _build_sections(cats_dual,     "dual")

    top_liked = sorted(sc, key=lambda s: s.get("likes", 0), reverse=True)[:20]
    newest    = sorted(sc, key=lambda s: s.get("created_at", 0), reverse=True)[:20]

    main_sections = [
        {"id": "trending", "name": "Trending", "icon": "flame.fill", "pinned": True,
         "items": [_item_ref(s, i, ["trending"]) for i, s in enumerate(top_liked)]},
        {"id": "new", "name": "New", "icon": "sparkles", "pinned": True,
         "items": [_item_ref(s, i, ["new"]) for i, s in enumerate(newest)]},
    ] + video_sections + img2img_sections + dual_sections

    catalog = {"tabs": {
        "main":    {"sections": main_sections},
        "video":   {"sections": video_sections},
        "img2img": {"sections": img2img_sections},
        "dual":    {"sections": dual_sections},
    }}

    ios_catalog = catalog
    save_ios_catalog(catalog)

    stats = {tab: {"sections": len(d["sections"]), "items": sum(len(s["items"]) for s in d["sections"])}
             for tab, d in catalog["tabs"].items()}
    debug_log.info("ios", f"iOS catalog synced from showcase: {stats}")
    return web.json_response({"ok": True, "stats": stats})


# Categories API
CATEGORIES_DUAL_FILE = PORTAL_DIR / "categories_dual.json"
categories_dual: List[Dict] = []

DEFAULT_CATEGORIES_DUAL = [
    {"id": "couple", "name": "Пара", "icon": "bi-heart"},
    {"id": "friends", "name": "Друзья", "icon": "bi-people"},
    {"id": "family", "name": "Семья", "icon": "bi-house-heart"},
    {"id": "funny", "name": "Смешное", "icon": "bi-emoji-laughing"},
    {"id": "fantasy", "name": "Фэнтези", "icon": "bi-stars"},
    {"id": "movie", "name": "Кино-сцена", "icon": "bi-film"},
    {"id": "other_dual", "name": "Разное", "icon": "bi-three-dots"},
]


def load_categories_dual():
    if CATEGORIES_DUAL_FILE.exists():
        try:
            return json.loads(CATEGORIES_DUAL_FILE.read_text())
        except Exception:
            pass
    save_categories_dual(DEFAULT_CATEGORIES_DUAL)
    return list(DEFAULT_CATEGORIES_DUAL)


def save_categories_dual(cats):
    CATEGORIES_DUAL_FILE.write_text(json.dumps(cats, indent=2, ensure_ascii=False))


async def api_categories_list(request):
    cat_type = request.query.get("type", "")
    if cat_type == "img2img":
        return web.json_response({"categories": categories_img2img})
    if cat_type == "dual":
        return web.json_response({"categories": categories_dual})
    return web.json_response({"categories": categories})


async def api_category_create(request):
    data = await request.json()
    name = data.get("name", "").strip()
    cat_type = data.get("type", "video")
    if not name:
        return web.json_response({"error": "name required"}, status=400)
    cid = data.get("id") or name.lower().replace(" ", "_")
    icon = data.get("icon", "bi-folder")
    if cat_type == "dual":
        target_list, target_file = categories_dual, CATEGORIES_DUAL_FILE
    elif cat_type == "img2img":
        target_list, target_file = categories_img2img, CATEGORIES_IMG2IMG_FILE
    else:
        target_list, target_file = categories, CATEGORIES_FILE
    if any(c["id"] == cid for c in target_list):
        return web.json_response({"error": "category exists"}, status=409)
    cat = {"id": cid, "name": name, "icon": icon}
    target_list.append(cat)
    save_categories(target_list, target_file)
    return web.json_response(cat, status=201)


async def api_category_update(request):
    cid = request.match_info["id"]
    data = await request.json()
    cat_type = data.get("type", request.query.get("type", "video"))
    if cat_type == "dual":
        target_list, target_file = categories_dual, CATEGORIES_DUAL_FILE
    elif cat_type == "img2img":
        target_list, target_file = categories_img2img, CATEGORIES_IMG2IMG_FILE
    else:
        target_list, target_file = categories, CATEGORIES_FILE
    for c in target_list:
        if c["id"] == cid:
            if "name" in data: c["name"] = data["name"]
            if "icon" in data: c["icon"] = data["icon"]
            save_categories(target_list, target_file)
            return web.json_response(c)
    return web.json_response({"error": "not found"}, status=404)


async def api_category_delete(request):
    cid = request.match_info["id"]
    cat_type = request.query.get("type", "video")
    if cat_type == "dual":
        target_list, target_file = categories_dual, CATEGORIES_DUAL_FILE
    elif cat_type == "img2img":
        target_list, target_file = categories_img2img, CATEGORIES_IMG2IMG_FILE
    else:
        target_list, target_file = categories, CATEGORIES_FILE
    for i, c in enumerate(target_list):
        if c["id"] == cid:
            target_list.pop(i)
            save_categories(target_list, target_file)
            return web.json_response({"ok": True})
    return web.json_response({"error": "not found"}, status=404)


async def on_startup(app):
    global templates, showcase, dual_showcase
    templates = load_templates()
    showcase = load_showcase()
    dual_showcase.clear()
    dual_showcase.extend(load_dual_showcase())
    categories.clear()
    categories.extend(load_categories(CATEGORIES_FILE))
    categories_img2img.clear()
    categories_img2img.extend(load_categories(CATEGORIES_IMG2IMG_FILE))
    categories_dual.clear()
    categories_dual.extend(load_categories_dual())
    global ios_catalog
    ios_catalog = load_ios_catalog()
    await manager.start()
    await _get_mysql_pool()
    _init_geoip()
    global _zip_rebuild_task
    _zip_rebuild_task = asyncio.create_task(_zip_rebuild_loop())
    debug_log.info("system", "Portal started")


async def on_shutdown(app):
    await manager.stop()



# ──────────── V2 API Handlers ────────────

# ──────────── User Auth System ────────────
USERS_FILE = PORTAL_DIR / "users.json"
USER_SESSIONS_FILE = PORTAL_DIR / "user_sessions.json"
def _load_user_sessions() -> Dict[str, Dict]:
    if USER_SESSIONS_FILE.exists():
        try:
            data = json.loads(USER_SESSIONS_FILE.read_text())
            # Clean expired
            now = time.time()
            return {k: v for k, v in data.items() if v.get("expires", 0) > now}
        except Exception:
            pass
    return {}
def _save_user_sessions():
    try:
        USER_SESSIONS_FILE.write_text(json.dumps(USER_SESSIONS, default=str))
    except Exception:
        pass
USER_SESSIONS: Dict[str, Dict] = _load_user_sessions()
FREE_PHOTO_LIMIT = 5
FREE_VIDEO_LIMIT = 2

def _load_users() -> List[Dict]:
    if USERS_FILE.exists():
        return json.loads(USERS_FILE.read_text())
    return []

def _save_users(users: List[Dict]):
    USERS_FILE.write_text(json.dumps(users, indent=2, ensure_ascii=False))

async def api_auth_register(request):
    """POST /api/auth/register — register new user."""
    import hashlib
    data = await request.json()
    email = (data.get("email") or "").strip().lower()
    password = data.get("password", "")
    name = data.get("name", "").strip()
    if not email or "@" not in email:
        return web.json_response({"error": "Valid email required"}, status=400)
    if len(password) < 6:
        return web.json_response({"error": "Password must be at least 6 characters"}, status=400)
    users = _load_users()
    if any(u["email"] == email for u in users):
        return web.json_response({"error": "Email already registered"}, status=409)
    user_id = f"u-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    pw_hash = hashlib.sha256((password + user_id).encode()).hexdigest()
    user = {
        "id": user_id, "email": email, "name": name, "password_hash": pw_hash,
        "created_at": time.time(), "verified": False,
        "limits": {"photo": FREE_PHOTO_LIMIT, "video": FREE_VIDEO_LIMIT, "dual": 0},
        "used": {"photo": 0, "video": 0, "dual": 0},
        "plan": "free",
    }
    users.append(user)
    _save_users(users)
    # Generate session token
    token = uuid.uuid4().hex + uuid.uuid4().hex
    USER_SESSIONS[token] = {"user_id": user_id, "email": email, "expires": time.time() + 86400 * 30}
    _save_user_sessions()
    debug_log.info("auth", f"User registered: {email} ({user_id})")
    return web.json_response({
        "token": token,
        "user": {"id": user_id, "email": email, "name": name, "plan": "free",
                 "limits": user["limits"], "used": user["used"]},
        "message": "Account created successfully"
    }, status=201)

async def api_auth_login(request):
    """POST /api/auth/login — login user."""
    import hashlib
    data = await request.json()
    email = (data.get("email") or "").strip().lower()
    password = data.get("password", "")
    users = _load_users()
    user = next((u for u in users if u["email"] == email), None)
    if not user:
        return web.json_response({"error": "Email not found"}, status=401)
    pw_hash = hashlib.sha256((password + user["id"]).encode()).hexdigest()
    if pw_hash != user["password_hash"]:
        return web.json_response({"error": "Wrong password"}, status=401)
    token = uuid.uuid4().hex + uuid.uuid4().hex
    USER_SESSIONS[token] = {"user_id": user["id"], "email": email, "expires": time.time() + 86400 * 30}
    _save_user_sessions()
    debug_log.info("auth", f"User login: {email}")
    return web.json_response({
        "token": token,
        "user": {"id": user["id"], "email": email, "name": user.get("name", ""),
                 "plan": user.get("plan", "free"),
                 "limits": user.get("limits", {}), "used": user.get("used", {})},
    })

async def api_auth_me(request):
    """GET /api/auth/me — get current user."""
    auth = request.headers.get("Authorization", "")
    token = auth.replace("Bearer ", "") if auth.startswith("Bearer ") else ""
    session = USER_SESSIONS.get(token)
    if not session or session.get("expires", 0) < time.time():
        return web.json_response({"error": "Not authenticated"}, status=401)
    users = _load_users()
    user = next((u for u in users if u["id"] == session["user_id"]), None)
    if not user:
        return web.json_response({"error": "User not found"}, status=404)
    return web.json_response({
        "user": {"id": user["id"], "email": user["email"], "name": user.get("name", ""),
                 "plan": user.get("plan", "free"),
                 "limits": user.get("limits", {}), "used": user.get("used", {})},
    })


async def api_v2_grok_toggle(request):
    """POST /api/v2/grok/toggle — enable/disable Grok fallback at runtime."""
    global GROK_ENABLED
    data = await request.json()
    if "enabled" in data:
        GROK_ENABLED = bool(data["enabled"])
    debug_log.info("v2", f"Grok {'ENABLED' if GROK_ENABLED else 'DISABLED'}")
    return web.json_response({"grok_enabled": GROK_ENABLED})

async def api_v2_gpu_toggle(request):
    """POST /api/v2/gpu/toggle — enable/disable GPU boot at runtime."""
    global GPU_BOOT_ENABLED
    data = await request.json()
    if "enabled" in data:
        GPU_BOOT_ENABLED = bool(data["enabled"])
        if not GPU_BOOT_ENABLED:
            # Destroy running instances when disabling
            try:
                async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=10)) as sess:
                    async with sess.get(f"{CONSOLE_BASE}/api/v0/instances/", headers=manager.vast_headers) as r:
                        instances = (await r.json()).get("instances", [])
                    for inst in instances:
                        iid = inst.get("id")
                        async with sess.delete(f"{CONSOLE_BASE}/api/v0/instances/{iid}/", headers=manager.vast_headers) as dr:
                            pass
                        debug_log.info("v2", f"GPU disabled — destroyed instance {iid}")
                manager.worker.status = "idle"
                manager.worker.instance_id = None
            except Exception as e:
                debug_log.warn("v2", f"GPU cleanup error: {e}")
    debug_log.info("v2", f"GPU boot {'ENABLED' if GPU_BOOT_ENABLED else 'DISABLED'}")
    return web.json_response({"gpu_enabled": GPU_BOOT_ENABLED, "grok_enabled": GROK_ENABLED})


async def api_v2_xai_status(request):
    """GET /api/v2/xai/status — xAI fallback system status."""
    eta = await estimate_generation_eta()
    return web.json_response({
        "grok_enabled": GROK_ENABLED,
        "gpu_enabled": GPU_BOOT_ENABLED,
        "xai_enabled": bool(XAI_API_KEY_V2), "hotapi_enabled": bool(HOTAPI_KEY),
        "xai_image_model": XAI_IMAGE_MODEL,
        "xai_video_model": XAI_VIDEO_MODEL,
        "gpu_eta_seconds": round(eta, 1),
        "eta_threshold_seconds": XAI_ETA_THRESHOLD_SEC,
        "would_use_xai": should_use_xai(eta),
        "worker_status": manager.worker.status,
        "history_count": len(generation_history),
    })


async def api_v2_history_record(request):
    """POST /api/v2/history/record — save generation record."""
    data = await request.json()
    record = {
        "id": data.get("id", f"hist-{int(time.time()*1000)}"),
        "source_image": data.get("source_image"),
        "result_image": data.get("result_image"),
        "video_url": data.get("video_url"),
        "prompt": data.get("prompt", ""),
        "method": data.get("method", "unknown"),
        "type": data.get("type", "video"),
        "generation_sec": data.get("generation_sec"),
        "cost_usd": data.get("cost_usd"),
        "created_at": data.get("created_at", time.time()),
    }
    generation_history.append(record)
    if len(generation_history) > 1000: generation_history[:] = generation_history[-1000:]
    return web.json_response({"ok": True, "id": record["id"]})


async def api_v2_history_list(request):
    """GET /api/v2/history — list recent generations."""
    limit = int(request.query.get("limit", "50"))
    method = request.query.get("method", "")
    items = list(reversed(generation_history))
    if method and method != "all":
        items = [i for i in items if i.get("method") == method]
    return web.json_response({"items": items[:limit], "total": len(items)})





# ──────────── AI Content Wizard ────────────

def _build_wizard_prompts(cat_filter: str = '', previous_ids: list = None) -> tuple:
    """Build (system_prompt, user_message) for the wizard Grok call."""
    sc_summary = [
        {
            "id": s.get("id"),
            "name": s.get("name"),
            "model": s.get("model"),
            "type": s.get("type"),
            "prompt": (s.get("prompt") or "")[:120] or None,
        }
        for s in showcase[-50:]
    ]
    # Remove None prompt fields to keep JSON compact
    sc_summary = [{k: v for k, v in item.items() if v is not None} for item in sc_summary]
    cats_summary = [{"id": c.get("id"), "name": c.get("name")} for c in categories]
    cat_focus = ''
    if cat_filter:
        cat_name = next((c.get('name', cat_filter) for c in categories if c.get('id') == cat_filter), cat_filter)
        cat_focus = f'\nСфокусируйся на категории "{cat_name}".'

    system_prompt = (
        "Ты — креативный генератор ультра-хардкорных пресетов для adult 18+ AI-платформы.\n"
        "Каждый раз создавай 15 полностью уникальных пресетов с максимальным разнообразием.\n"
        "Ключевые приоритеты (обязательно):\n"
        "- Лицо — максимальная похожесть на исходное фото (самый высокий приоритет).\n"
        "- Окружение, пространство, локация и фон — сильно варьируй: публичные места, лес, заброшка, офис, подвал, крыша, пляж ночью, грязный переулок, больница, тюрьма, секс-шоп, стройка, машина, общественный транспорт и т.д.\n"
        "- Позы — максимально разнообразные и сложные: стоя, вися, на коленях, upside down, растянута, привязана в воздухе, на четвереньках на столе, прижата к стеклу, в неудобных позах и т.д.\n"
        "- Всё остальное (действия, объекты, фетиши) — лютый хардкор, треш, максимально откровенно и разнообразно.\n"
        "Никогда не повторяй идеи, объекты и сценарии из предыдущих генераций. Избегай шаблонов.\n"
        "Верни СТРОГО JSON: ключ suggestions — массив из 15 объектов. Никакого лишнего текста вне JSON.\n"
        "Структура каждого объекта строго:\n"
        "id (unique latin slug), "
        "name (preset name in ENGLISH, explicit and direct), "
        "description_ru (короткое описание на русском, 4-7 слов, что именно видно на фото — конкретно и откровенно), "
        "description (what explicit transformation it makes, 1-2 sentences in ENGLISH), "
        "category_suggestion (category name in ENGLISH), "
        "model (ВСЕГДА \"qwen-image-edit-spicy\"), "
        "prompt (детальный промт на английском — обязательно: photorealistic, img2img, максимальная сохранность лица и идентичности, новое окружение + новая поза, очень детальное описание гениталий, жидкостей, повреждений, объектов; минимум 30 слов), "
        "photos_count (ВСЕГДА 1), "
        "type (ВСЕГДА \"img2img\")." +
        cat_focus
    )

    _cat_dump = json.dumps(cats_summary, ensure_ascii=False, indent=2)
    _sc_dump = json.dumps(sc_summary, ensure_ascii=False, indent=2)
    _prev_block = ''
    if previous_ids:
        _prev_block = '\n\nУЖЕ ПРЕДЛОЖЕННЫЕ — НЕ ПОВТОРЯТЬ (id и названия):\n' + json.dumps(previous_ids, ensure_ascii=False)
    _cat_line = (f'\nФокус: {cat_filter}' if cat_filter else '')
    user_message = (
        f'Текущий каталог:\n{_sc_dump}\n\n'
        f'Категории:\n{_cat_dump}{_cat_line}{_prev_block}\n\n'
        'Предложи 15 пресетов. Модель только qwen-image-edit-spicy, photos_count всегда 1, type всегда img2img. Верни JSON.'
    )
    return system_prompt, user_message
async def _call_grok_wizard(system_prompt: str, user_message: str) -> list:
    """Call Grok with wizard prompts, return parsed suggestions list."""
    headers = {"Authorization": f"Bearer {XAI_API_KEY_V2}", "Content-Type": "application/json"}
    payload = {
        "model": "grok-3-mini",
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_message},
        ],
        "response_format": {"type": "json_object"},
        "temperature": 0.7,
    }
    async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=60)) as sess:
        async with sess.post("https://api.x.ai/v1/chat/completions", headers=headers, json=payload) as r:
            body = await r.text()
            if r.status >= 400:
                raise Exception(f"Grok API error {r.status}: {body[:300]}")
            resp_data = json.loads(body)
    content_str = resp_data["choices"][0]["message"]["content"]
    parsed = json.loads(content_str)
    suggestions = parsed.get("suggestions", parsed) if isinstance(parsed, dict) else parsed
    return suggestions if isinstance(suggestions, list) else []


async def api_wizard_prompt(request):
    """GET /api/wizard/prompt \u2014 return wizard prompts for preview/editing, without calling Grok."""
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    cat_filter = request.rel_url.query.get('category', '').strip()
    system_prompt, user_message = _build_wizard_prompts(cat_filter)
    return web.json_response({"system_prompt": system_prompt, "user_message": user_message})


async def api_wizard_suggest(request):
    """GET/POST /api/wizard/suggest — call Grok with wizard prompts.
    POST {system_prompt?, user_message?, category?} — use custom prompts if provided.
    GET ?category=... — use default prompts.
    """
    if not check_auth(request):
        return web.json_response({"error": "Unauthorized"}, status=401)
    try:
        if request.method == 'POST':
            body = await request.json()
            system_prompt = body.get('system_prompt', '').strip()
            user_message = body.get('user_message', '').strip()
            cat_filter = body.get('category', '').strip()
            previous_ids = body.get('previous_ids', [])
            if not system_prompt or not user_message:
                default_sys, default_usr = _build_wizard_prompts(cat_filter, previous_ids or None)
                if not system_prompt: system_prompt = default_sys
                if not user_message: user_message = default_usr
        else:
            cat_filter = request.rel_url.query.get('category', '').strip()
            system_prompt, user_message = _build_wizard_prompts(cat_filter)
        suggestions = await _call_grok_wizard(system_prompt, user_message)
        return web.json_response({"suggestions": suggestions})
    except Exception as e:
        import traceback as _tb
        return web.json_response({"error": str(e), "trace": _tb.format_exc()[-500:]}, status=500)


# ──────────── Prompt Rewrite (Grok text) ────────────

async def api_prompt_rewrite(request):
    """POST /api/prompt/rewrite — rewrite prompt using Grok text API."""
    try:
        data = await request.json()
    except Exception:
        return web.json_response({"error": "invalid JSON"}, status=400)

    prompt = data.get("prompt", "").strip()
    note = data.get("note", "").strip()
    field = data.get("field", "image")  # "image" or "video"

    if not prompt and not note:
        return web.json_response({"error": "prompt or note required"}, status=400)

    system = (
        "You are an expert at writing prompts for adult AI image/video generation. "
        "Rewrite the given prompt incorporating the user's note. "
        "Be explicit, vivid, detailed. Return ONLY the improved prompt text, nothing else."
    )
    user_msg = f"Current prompt:\n{prompt}\n\nUser note:\n{note}\n\nRewrite the prompt."

    headers = {"Authorization": f"Bearer {XAI_API_KEY_V2}", "Content-Type": "application/json"}
    payload = {
        "model": "grok-3-mini",
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": user_msg},
        ],
        "temperature": 0.8,
    }

    try:
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=30)) as sess:
            async with sess.post("https://api.x.ai/v1/chat/completions", headers=headers, json=payload) as r:
                body = await r.json()
                if r.status >= 400:
                    return web.json_response({"error": f"Grok error {r.status}"}, status=502)
        result = body["choices"][0]["message"]["content"].strip()
        return web.json_response({"result": result})
    except Exception as e:
        return web.json_response({"error": str(e)}, status=500)


# ──────────── HotAPI Models ────────────
_hotapi_models_cache: dict = {}

async def api_hotapi_models(request):
    global _hotapi_models_cache
    import time as _time
    if _hotapi_models_cache.get('ts', 0) + 300 > _time.time():
        return web.json_response(_hotapi_models_cache['data'])
    try:
        async with aiohttp.ClientSession() as sess:
            async with sess.get(
                f"{HOTAPI_BASE}/v1/models",
                headers={"Authorization": f"Bearer {HOTAPI_KEY}"},
                timeout=aiohttp.ClientTimeout(total=10),
            ) as r:
                data = await r.json()
        _hotapi_models_cache = {'ts': _time.time(), 'data': data}
        return web.json_response(data)
    except Exception as e:
        return web.json_response({'error': str(e)}, status=502)


# ──────────── Unified Generate (any hotapi model) ────────────

async def hotapi_call_model(model_id: str, payload: dict, out_path: Path, media_type: str = "image") -> tuple:
    """Call any hotapi model, poll, download result. Returns (elapsed_sec, local_path)."""
    t0 = time.monotonic()
    task = None
    last_err = None
    async with aiohttp.ClientSession() as sess:
        for attempt in range(3):
            if attempt > 0:
                await asyncio.sleep(3)
            async with sess.post(
                f"{HOTAPI_BASE}/v1/{model_id}",
                headers={"Authorization": f"Bearer {HOTAPI_KEY}", "Content-Type": "application/json",
                         "User-Agent": HOTAPI_UA},
                json=payload,
                timeout=aiohttp.ClientTimeout(total=60),
            ) as r:
                _raw = await r.text()
                try:
                    task = json.loads(_raw)
                except Exception:
                    last_err = f"HotAPI: bad JSON (http={r.status}): {_raw[:200]}"
                    debug_log.warn("hotapi", f"attempt {attempt+1} bad JSON: {_raw[:100]}")
                    continue
            if "error" in task:
                _err = task["error"]
                _msg = _err.get("message", str(_err)) if isinstance(_err, dict) else str(_err)
                last_err = f"HotAPI submit error: {_msg}"
                debug_log.warn("hotapi", f"attempt {attempt+1} failed: {_msg[:100]}")
                if "validation" not in _msg and "found" not in _msg:
                    raise Exception(last_err)
                continue
            if not task.get("id"):
                last_err = f"HotAPI: no task_id in response: {str(task)[:200]}"
                debug_log.warn("hotapi", f"attempt {attempt+1} no task_id: {str(task)[:100]}")
                continue
            break
        else:
            raise Exception(last_err or "HotAPI: all submit attempts failed")
    task_id = task.get("id", "")
    debug_log.info("hotapi", f"unified: model={model_id} task={task_id}")
    task = await hotapi_wait_task(task_id)
    if task["status"] != "succeeded":
        raise Exception(f"HotAPI task failed [{task['status']}]: {task.get('error')}")
    assets = task.get("output", {}).get("assets", [])
    if media_type == "video":
        picked = [a for a in assets if a.get("type") == "video"] or assets
    elif media_type == "audio":
        picked = [a for a in assets if a.get("type") == "audio"] or assets
    else:
        picked = [a for a in assets if a.get("type") == "image"] or assets
    if not picked:
        raise Exception(f"HotAPI: no {media_type} assets in response")
    cdn_url = picked[0]["url"]
    await hotapi_download(cdn_url, out_path)
    elapsed = time.monotonic() - t0
    debug_log.info("hotapi", f"unified done {elapsed:.1f}s -> {out_path.name}")
    return elapsed, out_path


async def _save_upload_field(field) -> Path:
    """Save a multipart file field to a temp path in SHOWCASE_DIR."""
    ts = int(time.time())
    uid = uuid.uuid4().hex[:6]
    ext = Path(field.filename or "img.jpg").suffix or ".jpg"
    dest = SHOWCASE_DIR / f"up_{ts}_{uid}{ext}"
    with open(dest, "wb") as f:
        while True:
            chunk = await field.read_chunk(65536)
            if not chunk:
                break
            f.write(chunk)
    return dest


async def api_generate_unified(request):
    """POST /api/generate — unified single-step generation for any hotapi model."""
    # Extract caller context from headers (set by ai_generate.php)
    client_ip = request.headers.get("X-Client-IP", "").split(",")[0].strip()
    client_uiq = request.headers.get("X-Client-UIQ", "").strip()
    client_template = request.headers.get("X-Template-ID", "").strip()
    client_click_id = request.headers.get("X-Click-ID", "").strip()
    client_age = int(request.headers.get("X-Age-Detected", "0").strip() or "0")

    reader = await request.multipart()
    fields = {}
    image_paths = []  # list of Path for uploaded images
    async for part in reader:
        if part.filename:
            p = await _save_upload_field(part)
            image_paths.append(p)
        else:
            raw = await part.read(decode=True)
            fields[part.name] = raw.decode() if hasattr(raw, "decode") else str(raw)

    model_id = fields.get("model", "")
    prompt = fields.get("prompt", "")
    duration = int(fields.get("duration", "6"))
    resolution = fields.get("resolution", "720p")

    if not model_id:
        return web.json_response({"error": "model required"}, status=400)

    # Determine output media type from model id
    if "video" in model_id or "wan-" in model_id:
        if not VIDEO_ENABLED:
            return web.json_response(
                {"error": "video_disabled", "message": "Video generation is disabled"}, status=403)
        media_type = "video"
        ext = ".mp4"
    elif "tts" in model_id:
        media_type = "audio"
        ext = ".mp3"
    else:
        media_type = "image"
        ext = ".png"

    ts = int(time.time()); uid = uuid.uuid4().hex[:6]
    out_path = SHOWCASE_DIR / f"gen_{ts}_{uid}{ext}"

    # Resolve all image URL inputs to absolute public URLs
    img_urls = []
    for key in ['image_url', 'image_url2', 'image_url3', 'image_url4']:
        v = fields.get(key, '').strip()
        if v:
            if not (v.startswith('http://') or v.startswith('https://')):
                path_part = v[4:] if v.startswith('/gpu/') else v
                v = HOTAPI_SOURCE_BASE + path_part
            img_urls.append(v)
    for p in image_paths:
        import shutil
        target = SHOWCASE_DIR / p.name
        if p.resolve() != target.resolve():
            shutil.copy2(p, target)
        img_urls.append(f"{HOTAPI_SOURCE_BASE}/showcase/{target.name}")

    audio_url = fields.get('audio_url', '').strip()

    # Per-model duration clamping
    if 'wan-2.2' in model_id:
        dur_sec = 8 if duration > 6 else 5   # wan-2.2 only supports 5s or 8s
    elif 'wan-' in model_id:
        dur_sec = max(2, min(15, duration))   # wan-2.7: 2-15s
    else:
        dur_sec = max(4, min(15, duration))   # seedance etc: 4-15s

    payload = {}
    if 'tts' in model_id:
        payload = {'input': fields.get('text', prompt) or prompt}
        if fields.get('voice'):
            payload['voice'] = fields['voice']
    elif 'face-swap' in model_id:
        if len(img_urls) < 2:
            return web.json_response({'error': 'face-swap requires 2 images: target photo + face source'}, status=400)
        payload = {'image': img_urls[0], 'face_image': img_urls[1]}
    elif 'reference-to-video' in model_id:
        if not img_urls:
            return web.json_response({'error': f'{model_id} requires at least 1 reference image'}, status=400)
        payload = {'prompt': prompt, 'reference_image_urls': img_urls, 'duration_seconds': dur_sec}
    elif 'wan-' in model_id:
        if not img_urls:
            return web.json_response({'error': 'wan models require a source image (img→vid only)'}, status=400)
        payload = {'prompt': prompt, 'image_url': img_urls[0], 'duration_seconds': dur_sec}
        if audio_url:
            payload['audio_url'] = audio_url
    elif 'image-to-video' in model_id:
        if not img_urls:
            return web.json_response({'error': f'{model_id} requires a source image'}, status=400)
        payload = {'prompt': prompt, 'image_url': img_urls[0], 'duration_seconds': dur_sec}
        if len(img_urls) >= 2:
            payload['last_frame_url'] = img_urls[1]  # optional last frame interpolation
    elif 'video' in model_id:
        payload = {'prompt': prompt, 'duration_seconds': dur_sec}
    elif 'z-image' in model_id:
        payload = {'prompt': prompt}
        if fields.get('width'):  payload['width'] = int(fields['width'])
        if fields.get('height'): payload['height'] = int(fields['height'])
    else:
        # qwen-image-edit, seedream/image-edit, seedream/text-to-image
        if prompt:
            payload['prompt'] = prompt
        if img_urls:
            if len(img_urls) == 1:
                payload['image'] = img_urls[0]
            else:
                # multi-image: seedream/image-edit accepts image as array (docs confirmed)
                payload['image'] = img_urls
                if 'qwen' in model_id:
                    model_id = 'seedream-5.0-lite-spicy/image-edit'

    debug_log.info('hotapi', f'unified: model={model_id} payload_keys={list(payload.keys())} img_urls={img_urls}')
    _t0 = time.monotonic()
    try:
        elapsed, result_path = await hotapi_call_model(model_id, payload, out_path, media_type)

        # Crop images to 9:16 portrait
        if media_type == "image":
            try:
                cropped_path = result_path.parent / f"c_{result_path.name}"
                crop_and_resize_9_16(result_path, cropped_path)
                result_path = cropped_path
            except Exception:
                pass  # keep original if crop fails

        rel_url = f"/showcase/{result_path.name}"

        # Cost estimate from model pricing cache
        _cost = 0.0
        try:
            _mdata = _hotapi_models_cache.get("data", {})
            _mlist = _mdata.get("data", []) if isinstance(_mdata, dict) else []
            _mobj = next((m for m in _mlist if m.get("id") == model_id), None)
            if _mobj:
                _pr = _mobj.get("pricing", {})
                if "per_image_micros" in _pr:
                    _cost = _pr["per_image_micros"] / 1_000_000
                elif "per_second_micros" in _pr:
                    _rates = _pr["per_second_micros"]
                    _rate = _rates.get(resolution, next(iter(_rates.values()), 0))
                    _cost = _rate * dur_sec / 1_000_000
        except Exception:
            pass

        asyncio.create_task(_tg_gpu_notify(
            prompt=prompt or fields.get("text", "") or fields.get("input", ""),
            model_id=model_id,
            img_urls=img_urls,
            result_path=result_path,
            media_type=media_type,
            elapsed=elapsed,
            cost_usd=_cost,
            client_ip=client_ip,
            client_uiq=client_uiq,
            template_id=client_template or fields.get("template_id", ""),
            client_click_id=client_click_id,
            age_detected=client_age,
        ))

        return web.json_response({
            "ok": True,
            "url": rel_url,
            "media_type": media_type,
            "model": model_id,
            "elapsed": round(elapsed, 1),
        })
    except Exception as e:
        asyncio.create_task(_tg_gpu_notify(
            prompt=prompt or fields.get("text", "") or fields.get("input", ""),
            model_id=model_id,
            img_urls=img_urls,
            result_path=None,
            media_type=media_type,
            elapsed=time.monotonic() - _t0,
            cost_usd=0,
            client_ip=client_ip,
            client_uiq=client_uiq,
            template_id=client_template or fields.get("template_id", ""),
            client_click_id=client_click_id,
            age_detected=client_age,
            error=str(e),
        ))
        return web.json_response({"error": str(e)}, status=500)


async def api_dataset_random(request):
    """GET /api/dataset/random — return URL of a random image from the dataset folder."""
    exts = {'.jpg', '.jpeg', '.png', '.webp'}
    try:
        files = [p for p in DATASET_DIR.iterdir() if p.suffix.lower() in exts]
    except FileNotFoundError:
        return web.json_response({'error': 'dataset not found'}, status=404)
    if not files:
        return web.json_response({'error': 'dataset is empty'}, status=404)
    chosen = random.choice(files)
    return web.json_response({
        'name': chosen.name,
        'url': f'/dataset/{chosen.name}',
        'full_url': f'{PORTAL_PUBLIC_URL}/dataset/{chosen.name}',
    })


async def api_upload_temp(request):
    """POST /api/upload — save one image, return its public URL."""
    reader = await request.multipart()
    async for part in reader:
        if part.filename:
            ts = int(time.time()); uid = uuid.uuid4().hex[:6]
            ext = Path(part.filename).suffix or '.jpg'
            dest = SHOWCASE_DIR / f'up_{ts}_{uid}{ext}'
            with open(dest, 'wb') as f:
                while True:
                    chunk = await part.read_chunk(65536)
                    if not chunk: break
                    f.write(chunk)
            return web.json_response({'url': f'/showcase/{dest.name}', 'full_url': f'{PORTAL_PUBLIC_URL}/showcase/{dest.name}'})
    return web.json_response({'error': 'no file'}, status=400)

import subprocess as _subprocess

async def api_push_stats(request):
    """Return push subscriber count from fapaio DB."""
    try:
        import aiomysql as _aiomysql
        conn = await _aiomysql.connect(
            host='localhost', user='pma', password=os.getenv("FAPAIO_DB_PASSWORD", ""), db='fapaio'
        )
        async with conn.cursor() as cur:
            await cur.execute('SELECT COUNT(*) FROM push_subscriptions')
            total = (await cur.fetchone())[0]
            await cur.execute('SELECT lang, COUNT(*) as cnt FROM push_subscriptions GROUP BY lang ORDER BY cnt DESC')
            rows = await cur.fetchall()
        conn.close()
        by_lang = [{'lang': r[0], 'count': r[1]} for r in rows]
        return web.json_response({'ok': True, 'total': total, 'by_lang': by_lang})
    except Exception as e:
        return web.json_response({'ok': False, 'error': str(e)})


async def api_push_send(request):
    """Send push notification to all or filtered subscribers."""
    data = await request.json()
    title = data.get('title', '').strip()
    body  = data.get('body', '').strip()
    url   = data.get('url', '/genpromo.php').strip()
    lang  = data.get('lang', '').strip() or None
    if not title or not body:
        return web.json_response({'ok': False, 'error': 'title and body required'})
    script = '/var/www/core-vpn.click/scripts/send_push.py'
    cmd = ['python3', script, '--title', title, '--body', body, '--url', url]
    if lang:
        cmd += ['--lang', lang]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
        output = (result.stdout + result.stderr).strip()
        return web.json_response({'ok': True, 'output': output})
    except Exception as e:
        return web.json_response({'ok': False, 'error': str(e)})


def create_app():
    app = web.Application(
        client_max_size=100 * 1024 * 1024,
        middlewares=[request_log_middleware, auth_middleware],
    )
    app.on_startup.append(on_startup)
    app.on_shutdown.append(on_shutdown)

    # Public
    app.router.add_post("/api/login", api_login)
    app.router.add_get("/api/health", api_health)

    # User auth (public)
    app.router.add_post("/api/auth/register", api_auth_register)
    app.router.add_post("/api/auth/login", api_auth_login)
    app.router.add_get("/api/auth/me", api_auth_me)
    app.router.add_get("/login", serve_login)

    # Auth required
    app.router.add_post("/api/logout", api_logout)
    app.router.add_get("/api/templates", api_templates_list)
    app.router.add_get("/api/templates/{id}", api_template_get)
    app.router.add_post("/api/templates", api_template_create)
    app.router.add_put("/api/templates/{id}", api_template_update)
    app.router.add_delete("/api/templates/{id}", api_template_delete)
    app.router.add_get("/api/weights", api_weights_list)
    app.router.add_get("/api/workflows", api_workflows_list)
    app.router.add_get("/api/workflows/{name}", api_workflow_get)
    app.router.add_post("/api/workflows", api_workflow_upload)
    app.router.add_get("/api/worker", api_worker_status)
    app.router.add_post("/api/worker/destroy", api_worker_destroy)
    app.router.add_get("/api/dashboard", api_dashboard)
    app.router.add_get("/api/events", api_events)
    app.router.add_get("/api/debug/logs", api_debug_logs)
    app.router.add_get("/api/debug/config", api_debug_config)
    app.router.add_post("/api/provision/log", api_provision_log)
    app.router.add_get("/api/provision/status/{instance_id}", api_provision_status)
    app.router.add_get("/api/provision/status", api_provision_status)
    app.router.add_get("/api/version", api_version)
    app.router.add_get("/api/vast-launch/{id}", api_vast_launch)
    app.router.add_get("/api/showcase", api_showcase_list)
    app.router.add_get("/api/showcase/{id}", api_showcase_get)
    app.router.add_post("/api/showcase", api_showcase_save)
    app.router.add_patch("/api/showcase/{id}", api_showcase_patch)
    app.router.add_post("/api/showcase/{id}/replace-image", api_showcase_replace_image)
    app.router.add_post("/api/showcase/{id}/clone", api_showcase_clone)
    app.router.add_post("/api/showcase/like", api_showcase_like)
    app.router.add_delete("/api/showcase/{id}", api_showcase_delete)
    app.router.add_post("/api/showcase/bulk-delete", api_showcase_bulk_delete)
    app.router.add_post("/api/showcase/bulk-category", api_showcase_bulk_category)
    app.router.add_post("/api/showcase/gif/generate-all", api_showcase_gif_generate_all)
    app.router.add_post("/api/showcase/{id}/gif", api_showcase_gif_generate)
    app.router.add_post("/api/showcase/{id}/gif-regen", api_showcase_gif_regen)
    app.router.add_get("/api/categories", api_categories_list)
    app.router.add_post("/api/categories", api_category_create)
    app.router.add_put("/api/categories/{id}", api_category_update)
    app.router.add_delete("/api/categories/{id}", api_category_delete)
    app.router.add_post("/api/showcase/generate", api_showcase_generate)
    app.router.add_get("/api/jobs", api_jobs_list)
    app.router.add_get("/api/jobs/{id}", api_job_status)
    app.router.add_get("/api/dual", api_dual_list)
    app.router.add_get("/api/dual/{id}", api_dual_get)
    app.router.add_post("/api/dual", api_dual_save)
    app.router.add_delete("/api/dual/{id}", api_dual_delete)
    app.router.add_post("/api/dual/generate", api_dual_generate)
    app.router.add_post("/api/dual/bulk-delete", api_dual_bulk_delete)
    # iOS Catalog — admin
    app.router.add_get("/api/ios/catalog", api_ios_catalog_get)
    app.router.add_post("/api/ios/catalog", api_ios_catalog_save)
    app.router.add_post("/api/ios/catalog/section", api_ios_catalog_add_section)
    app.router.add_post("/api/ios/catalog/section/rename", api_ios_catalog_rename_section)
    app.router.add_post("/api/ios/catalog/section/delete", api_ios_catalog_delete_section)
    app.router.add_post("/api/ios/catalog/reorder", api_ios_catalog_reorder)
    app.router.add_post("/api/ios/catalog/item/add", api_ios_catalog_add_item)
    app.router.add_post("/api/ios/catalog/item/remove", api_ios_catalog_remove_item)
    app.router.add_post("/api/ios/catalog/item/move", api_ios_catalog_move_item)
    app.router.add_post("/api/ios/catalog/item/tag", api_ios_catalog_toggle_tag)
    app.router.add_post("/api/ios/catalog/item/hide", api_ios_catalog_set_hidden)
    app.router.add_get("/api/ios/catalog/export/json", api_ios_catalog_export_json)
    app.router.add_get("/api/ios/catalog/export/zip/v2", api_ios_catalog_export_zip_v2)
    app.router.add_get("/api/ios/catalog/export/zip/images", api_ios_catalog_export_zip_images_only)
    app.router.add_get("/api/ios/catalog/export/zip", api_ios_catalog_export_zip)
    app.router.add_get("/api/ios/catalog/export/{tab_id}/json", api_ios_catalog_export_tab_json)
    app.router.add_get("/api/ios/catalog/export/{tab_id}/zip", api_ios_catalog_export_tab_zip)
    app.router.add_get("/api/ios/catalog/export/{tab_id}/zip/images", api_ios_catalog_export_tab_zip_images)
    app.router.add_post("/api/ios/catalog/auto-populate", api_ios_catalog_auto_populate)
    app.router.add_post("/api/ios/catalog/sync", api_ios_catalog_sync)
    app.router.add_post("/api/ios/catalog/banners/add", api_ios_catalog_banner_add)
    app.router.add_post("/api/ios/catalog/banners/remove", api_ios_catalog_banner_remove)
    # iOS Catalog — public (no auth)
    app.router.add_get("/api/ios/public/catalog", api_ios_catalog_public)
    app.router.add_get("/api/ios/public/template/{id}", api_ios_template_get)
    app.router.add_get("/api/ios/public/catalog/latest.zip", api_ios_catalog_latest_zip)
    app.router.add_get("/api/ios/public/catalog/version", api_ios_catalog_version)

    # Onboarding Sets — public
    app.router.add_get("/api/ios/public/onboarding/sets", api_onboarding_list_public)
    app.router.add_get("/api/ios/public/onboarding/sets/{set_id}", api_onboarding_get_public)
    # Onboarding Sets — admin CRUD
    app.router.add_get("/api/onboarding/sets", api_onboarding_list)
    app.router.add_post("/api/onboarding/sets", api_onboarding_create)
    app.router.add_put("/api/onboarding/sets/{set_id}", api_onboarding_update)
    app.router.add_delete("/api/onboarding/sets/{set_id}", api_onboarding_delete)

    # Push
    app.router.add_get("/api/push/stats", api_push_stats)
    app.router.add_post("/api/push/send", api_push_send)

    # Stats
    app.router.add_get("/api/stats/generations", api_stats_generations)
    app.router.add_get("/api/stats/preset/{preset_id}", api_stats_preset_detail)
    app.router.add_get("/api/stats/log", api_stats_log)
    app.router.add_get("/api/stats/users", api_stats_users)
    app.router.add_get("/api/stats/users/{ip}", api_stats_user_detail)

    app.router.add_get("/api/wizard/suggest", api_wizard_suggest)
    app.router.add_post("/api/wizard/suggest", api_wizard_suggest)
    app.router.add_get("/api/wizard/prompt", api_wizard_prompt)
    app.router.add_get("/api/hotapi/models", api_hotapi_models)
    app.router.add_post("/api/split-test", api_split_test)
    app.router.add_get("/api/split-test/models", api_split_test_models)
    app.router.add_post("/api/prompt/rewrite", api_prompt_rewrite)
    app.router.add_post("/api/t2v/generate", api_t2v_generate)
    app.router.add_post("/api/generate", api_generate_unified)
    app.router.add_post("/api/upload", api_upload_temp)
    app.router.add_get("/api/dataset/random", api_dataset_random)

    # Static
    app.router.add_get("/results/{filename}", serve_result_file)
    app.router.add_static("/showcase", SHOWCASE_DIR)
    app.router.add_static("/uploads", UPLOADS_DIR)
    app.router.add_static("/workflows", WORKFLOWS_DIR)
    app.router.add_static("/dataset", DATASET_DIR)


    # V2 new endpoints — xAI status and generation history
    app.router.add_get("/api/v2/xai/status", api_v2_xai_status)
    app.router.add_post("/api/v2/grok/toggle", api_v2_grok_toggle)
    app.router.add_post("/api/v2/gpu/toggle", api_v2_gpu_toggle)
    app.router.add_post("/api/v2/history/record", api_v2_history_record)
    app.router.add_get("/api/v2/history", api_v2_history_list)

    # SPA
    app.router.add_post("/api/worker/attach", api_worker_attach)
    app.router.add_get("/catalog", serve_catalog)
    app.router.add_get("/generate", serve_generate)
    app.router.add_get("/img2img", serve_catalog_img2img)
    app.router.add_get("/img2img/generate", serve_generate_img2img)
    app.router.add_get("/admin", serve_portal)
    app.router.add_get("/admin/", serve_portal)
    app.router.add_get("/", serve_portal)
    app.router.add_get("/{path:.*}", serve_portal_catch_all)

    return app




if __name__ == '__main__':
    web.run_app(create_app(), host='127.0.0.1', port=8899)
