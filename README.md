# fapa.cam — site + AI generations

Снимок кода сайта fapa.cam и GPU-портала генераций (2026-11-24).

## Состав

- `index.php`, `catalog.php`, `gen.php`, `v.php`, `profile.php`, `login.php`, `s.php` — публичные страницы
- `cmd/` — админка (`cmd/menu.php`, basic-auth на nginx) и вспомогательные скрипки
- `click.php`, `click_core.php` — учёт кликов, пишет в `fapaio.clicks`
- `scripts/` — трекинг лидов/просмотров (`track_lead.php`, `track_view.php`, `track_watch.php`, `track_js.php`)
- `py/xikfap_scraper.py` — скрапер видео (категории + ID-sweep), см. отдельный skill
- `gpu-portal/api.py` — FastAPI-портал генераций (порт 8899), `showcase.json` — каталог шаблонов, `templates.json`, `workflows/`
- `js/`, `css/`, `assets/`, `lang/`, `api/`, `showcase/` — фронтенд и ассеты

## Требуется для запуска

- PHP 8.5 + mysqli, MariaDB `fapaio`, nginx с PHP-FPM
- Сервис `gpu-portal.service` (systemd), Reverse-proxy `/gpu/` → `127.0.0.1:8899`
- Переменные окружения: `FAPAIO_DB_PASSWORD`, `HOTAPI_KEY`, `XAI_API_KEY_V2`
- Basic-auth для `/cmd/` (`.htpasswd`)

## ВАЖНО

Секреты из репозитория вырезаны: вместо паролей — `REPLACE_WITH_DB_PASSWORD` / `REPLACE_WITH_ADMIN_PASSWORD`. На боевом сервере значения подставляются из env/FPM-пула и htpasswd; **в репозиторий их не добавлять**.

Видео (`/home/claude2/uvid`, ~617 МБ) и сгенерированные результаты в репозиторий не входят.
