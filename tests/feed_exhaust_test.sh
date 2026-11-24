#!/usr/bin/env bash
# Фид не должен вымирать: даже у юзера, посмотревшего всё, AJAX отдаёт видео (recycle).
UIQ=$(mysql -upma -pREPLACE_WITH_DB_PASSWORD fapaio -N -e \
  "SELECT uiq FROM video_views GROUP BY uiq ORDER BY COUNT(DISTINCT video_id) DESC LIMIT 1")
COUNT=$(curl -s "https://fapa.cam/index.php?ajax=1&offset=0&limit=5" -b "uiq=$UIQ" | grep -o '"count":[0-9]*')
echo "top uiq=$UIQ -> $COUNT"
echo "$COUNT" | grep -q '"count":[1-9]' && echo PASS || echo FAIL
