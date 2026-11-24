# fapa.cam — site + AI generations

Code snapshot of the fapa.cam site and its GPU generation portal (2026-11-24).

## Contents

- `index.php`, `catalog.php`, `gen.php`, `v.php`, `profile.php`, `login.php`, `s.php` — public pages
- `cmd/` — admin panel (`cmd/menu.php`, protected by nginx basic auth) and helper scripts
- `click.php`, `click_core.php` — click tracking, writes to `fapaio.clicks`
- `scripts/` — lead/view tracking endpoints (`track_lead.php`, `track_view.php`, `track_watch.php`, `track_js.php`)
- `py/` — video ingestion pipeline: `xikfap_scraper.py` (category polling + ID sweep), `mp4_to_dir.py`, `watch_hls_json.py`, `xikfap_cron.sh`, `deploy/fapa-xikfap.cron`
- `tools/` — Python maintenance scripts for parsing/auditing: `inventory.py` (visible IDs vs DB vs files), `prune_old.py` (disk cleanup), `seed_showcase.py` (generation catalog seed)
- `gpu-portal/api.py` — FastAPI generation portal (port 8899); `showcase.json` — template catalog; `templates.json`, `workflows/`
- `js/`, `css/`, `assets/`, `lang/`, `api/`, `showcase/` — frontend assets

## Requirements

- PHP 8.5 with mysqli, MariaDB database `fapaio`, nginx + PHP-FPM
- `gpu-portal.service` (systemd); `/gpu/` reverse-proxied to `127.0.0.1:8899`
- Environment variables: `FAPAIO_DB_PASSWORD`, `HOTAPI_KEY`, `XAI_API_KEY_V2`
- Basic auth for `/cmd/` (`.htpasswd`)

## Parsing pipeline notes

The scraper only sees the newest ~20 videos per category: the upstream `load_more.php` API ignores `offset`/`page`, and per-video HTML pages are behind Cloudflare Turnstile. Historical ID sweeps are therefore ineffective; the catalog grew from the category top pages only. `DELAY_VIDEO` controls per-video pacing; keep `DELAY_API` at 0.3s. Do not run two scrapers at once — `xikfap_cron.sh` uses `flock`.

## IMPORTANT

Secrets have been removed from this repository: passwords are replaced with `REPLACE_WITH_DB_PASSWORD` / `REPLACE_WITH_ADMIN_PASSWORD`. On the live server they come from env/php-fpm configuration and htpasswd. **Never commit real credentials here.**

Video files (`/home/claude2/uvid`, ~617 MB) and generated results are intentionally excluded.
