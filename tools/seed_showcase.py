#!/usr/bin/env python3
# fapa.cam: сид showcase.json минимальным каталогом из доступных пар до/после
import json, time
P='/var/www/fapa.cam/gpu-portal/showcase.json'
A='/showcase/test_1783605158.jpg'      # before (девушка)
B='/showcase/undress_result_001.png'   # after
C='/showcase/demo_girl_new.jpg'        # before #2
D='/showcase/gen_1783626085_44d7e7.png'# after #2
now=int(time.time())
items=[
 {'id':'tpl-outfit','name':'Смена наряда','type':'img2img','template_id':'tpl-outfit','source_image':A,'generated_image':B,'categories':['outfit'],'tags':['outfit'],'likes':12,'created_at':now},
 {'id':'tpl-beauty','name':'Бьюти-ретушь','type':'img2img','template_id':'tpl-beauty','source_image':A,'generated_image':B,'categories':['beauty'],'tags':['beauty','glam'],'likes':9,'created_at':now-60},
 {'id':'tpl-anime','name':'Аниме-стиль','type':'img2img','template_id':'tpl-anime','source_image':C,'generated_image':D,'categories':['cartoon'],'tags':['anime','cartoon'],'likes':21,'created_at':now-120},
 {'id':'tpl-fantasy','name':'Фэнтези-фон','type':'img2img','template_id':'tpl-fantasy','source_image':C,'generated_image':D,'categories':['fantasy'],'tags':['fantasy'],'likes':7,'created_at':now-180},
 {'id':'tpl-style','name':'Стиль-трансфер','type':'img2img','template_id':'tpl-style','source_image':A,'generated_image':B,'categories':['style_transfer'],'tags':['style'],'likes':5,'created_at':now-240},
 {'id':'tpl-glam','name':'Гламур-образ','type':'img2img','template_id':'tpl-glam','source_image':C,'generated_image':D,'categories':['beauty'],'tags':['glam','beauty'],'likes':14,'created_at':now-300},
]
json.dump(items, open(P,'w'), ensure_ascii=False, indent=1)
print('seeded', len(items))
