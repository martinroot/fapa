#!/usr/bin/env bash
# core-vpn.click: xikfap scraper под cron (с защёлкой, один инстанс).
# Правило в /etc/cron.d/fapa-xikfap запускает это раз в 2 минуты.
# Актуальные пути: скрипт /var/www/core-vpn.click/py/xikfap_scraper.py,
# вывод в /home/claude2/fapa-cam-scraper.log.
LOCK=/home/claude2/fapa-cam-scraper.lock
LOG=/home/claude2/fapa-cam-scraper.log

# Один экземпляр: если уже крутится — тихо выходим.
exec 9>"$LOCK"
if ! flock -n 9; then
    exit 0
fi

cd /var/www/core-vpn.click/py
/usr/bin/python3 -u xikfap_scraper.py --mode cats --cat all >> "$LOG" 2>&1
