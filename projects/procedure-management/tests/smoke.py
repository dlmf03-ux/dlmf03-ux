"""HTTP regression checks. Run against a fresh test DB and local router only."""
import json
import re
import sys
import urllib.parse
import urllib.request
import http.cookiejar
from pathlib import Path
from datetime import datetime,timedelta
TEST_DATE=(datetime.now()-timedelta(days=1)).strftime("%Y-%m-%d")

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080/"
def session():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(s, page=""):
    return s.open(BASE + page).read().decode("utf-8")
def token(html, name="csrf"):
    return re.search(r'name="' + name + r'" value="([^"]+)"', html).group(1)
def post(s, page, values):
    request=urllib.request.Request(BASE+page, urllib.parse.urlencode(values).encode())
    response=s.open(request)
    body=response.read().decode("utf-8")
    assert "Fatal error" not in body and "<b>Warning</b>" not in body and "<b>Deprecated</b>" not in body, body[:1000]
    return response.url,body

s=session()
html=get(s)
if "Первый запуск" in html:
    install_key=Path(sys.argv[2]).read_text().strip()
    post(s,"",dict(csrf=token(html),token=install_key,manager_password="Manager-Test-729!",admin_password="Admin-Test-729!"))
html=get(s,"?page=login")
post(s,"?page=login",dict(csrf=token(html),login="admin",password="Admin-Test-729!"))
html=get(s,"?page=client")
url,html=post(s,"?page=client",dict(csrf=token(html),action="save_client",id=0,last_name="Тестов",first_name="Иван",middle_name="Петрович",birth_date="1980-01-01",phone="+79990001122",email="",document_note="",notes="<script>alert(1)</script>"))
assert "id=" in url,(url,html[:1000])
client_id=re.search(r'id=(\d+)',url).group(1)
assert "&lt;script&gt;" in html
assert "Тестов" in get(s,"?page=clients&q="+urllib.parse.quote("тестов"))
html=get(s,"?page=new-order&client_id="+client_id)
values=dict(csrf=token(html),submission=token(html,"submission"),action="save_order",client_id=client_id,visit_at=TEST_DATE+"T10:00",notes="Тест",items_json=json.dumps([dict(service_id=1,quantity=2,room="101",scheduled_at=TEST_DATE+"T11:00")]))
url,html=post(s,"?page=new-order",values)
order_id=re.search(r'id=(\d+)',url).group(1)
assert "200,00" in html
service_page=get(s,"?page=services")
post(s,"?page=services",dict(csrf=token(service_page),action="save_service",id=1,name="Подкожные инъекции (1 инъекция)",category="Инъекции",duration="8",price="250.00",room="102"))
assert "200,00" in get(s,"?page=order&id="+order_id),"Catalog price changed historic total"
again,_=post(s,"?page=new-order",values)
assert again==url,"Duplicate submission created another order"
assert "101" in get(s,"?page=print&id="+order_id)
_,html=post(s,"?page=order&id="+order_id,dict(csrf=token(html),action="set_status",id=order_id,status="paid"))
assert "Оплата зафиксирована" in html
item_id=re.search(r'<option value="(\d+)">',html).group(1)
for i in range(2):
    _,html=post(s,"?page=order&id="+order_id,dict(csrf=token(html),event_token=token(html,"event_token"),action="perform",item_id=item_id,performed_at=TEST_DATE+"T11:00",notes="Сеанс"))
assert "Оказано" in html
_,html=post(s,"?page=order&id="+order_id,dict(csrf=token(html),action="set_status",id=order_id,status="unpaid"))
assert "Оказано" in html
_,html=post(s,"?page=order&id="+order_id,dict(csrf=token(html),action="archive",id=order_id,type="order",reason="Тест запрета"))
assert "только руководителю" in html
assert "Тестов" in get(s,"?page=orders")
manager=session()
html=get(manager,"?page=login")
post(manager,"?page=login",dict(csrf=token(html),login="manager",password="Manager-Test-729!"))
html=get(manager,"?page=order&id="+order_id)
post(manager,"?page=order&id="+order_id,dict(csrf=token(html),action="archive",id=order_id,type="order",reason="Тест руководителя"))
assert "Тестов" not in get(manager,"?page=orders")
assert "Тест руководителя" in get(manager,"?page=audit")
archive=get(manager,"?page=archive")
assert "Тест руководителя" in archive
post(manager,"?page=archive",dict(csrf=token(archive),action="restore",type="order",id=order_id))
assert "Тестов" in get(manager,"?page=orders")
try:
    post(manager,"?page=archive",dict(action="archive",type="order",id=order_id,reason="Без CSRF"))
except urllib.error.HTTPError as ex:
    assert ex.code==419
else:
    raise AssertionError("Missing CSRF accepted")
for path in ["storage/data.sqlite","app/services.php","tmp/php.zip",".git/config"]:
    try: get(manager,path)
    except urllib.error.HTTPError as ex: assert ex.code==404
    else: raise AssertionError("Private file exposed: "+path)
print("PASS: setup, authentication, Cyrillic search, client, XSS escaping, order total, immutable pricing, idempotency, print, payment, individual sessions, RBAC, audit, restore, CSRF, private paths.")
