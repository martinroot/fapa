#!/usr/bin/env python3
# fapa.cam: сверка visible (listing API) <-> DB <-> files; одна выборка строк
import urllib.request, urllib.parse, json
import pymysql
H={'User-Agent':'Mozilla/5.0 (Windows NT 10.0) Chrome/124.0','Referer':'https://xikfap.com/'}
CATS=['all','hetero','lesbian','trans','gay']
visible={}
for cat in CATS:
    url='https://xikfap.com/api/load_more.php?'+urllib.parse.urlencode({'category':cat,'offset':0,'limit':20})
    try:
        d=json.loads(urllib.request.urlopen(urllib.request.Request(url,headers=H),timeout=12).read().decode())
        for v in d:
            if isinstance(v,dict) and v.get('id'): visible[int(v['id'])]=cat
    except Exception as e:
        print(f'{cat}: ERR {str(e)[:40]}')
conn=pymysql.connect(host='127.0.0.1',port=3306,user='pma',password='REPLACE_WITH_DB_PASSWORD',database='fapaio')
cur=conn.cursor(); cur.execute('SELECT external_id, folder_id FROM videos')
rows=cur.fetchall()
db={r[0] for r in rows}; folders={r[1] for r in rows}
missing_db=sorted(set(visible)-db)
no_file=sorted(i for i in visible if i in db and i not in folders)
print('VISIBLE unique:', len(visible))
print('in DB:', len(set(visible) & db))
print('NOT in DB:', missing_db)
print('file-missing:', no_file)
