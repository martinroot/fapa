#!/usr/bin/env python3
# fapa.cam: чистка старых каталогов /home/claude2/uvid по возрасту.
# usage: prune_old.py --days 30 [--dry|--go]
# Хранит строку в БД (folder_id=NULL), удаляет только каталог с файлами.
import argparse, os, shutil, sys, pymysql
ap=argparse.ArgumentParser()
ap.add_argument('--days',type=int,default=30)
ap.add_argument('--dry',action='store_true')
ap.add_argument('--go',action='store_true')
a=ap.parse_args()
if a.go==a.dry:
    print('укажи --dry или --go'); sys.exit(1)
conn=pymysql.connect(host='127.0.0.1',port=3306,user='pma',password='REPLACE_WITH_DB_PASSWORD',database='fapaio')
cur=conn.cursor()
cur.execute("SELECT folder_id FROM videos WHERE folder_id IS NOT NULL AND starter_rank IS NULL AND created_at < NOW() - INTERVAL %d DAY" % a.days)
rows=[r[0] for r in cur.fetchall()]
base='/home/claude2/uvid'
total=0; n=0
for f in rows:
    d=os.path.join(base,str(f))
    if not os.path.isdir(d): continue
    sz=sum(os.path.getsize(os.path.join(r_,fn)) for r_,_,fs in os.walk(d) for fn in fs)
    print(f'{d} {sz//1024//1024}MB')
    total+=sz; n+=1
print('candidates:',n,'size:',total//1024//1024,'MB')
if a.go:
    for f in rows:
        d=os.path.join(base,str(f))
        if os.path.isdir(d): shutil.rmtree(d,ignore_errors=True)
        cur.execute('UPDATE videos SET folder_id=NULL, m3u8_path=%s, poster_path=%s WHERE folder_id=%s',(str(d)+'/index.m3u8','',f))
    conn.commit()
    print('DELETED',n)
