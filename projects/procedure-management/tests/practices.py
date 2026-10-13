"""Run on a new test database only; includes the original regression suite."""
import runpy
from pathlib import Path
g=runpy.run_path(str(Path(__file__).with_name("smoke.py")))
globals().update({k:v for k,v in g.items() if not k.startswith("__")})

# Duplicate warnings preserve form input and require deliberate override.
draft=dict(action="save_client",id=0,last_name="Тестов",first_name="Иван",middle_name="Петрович",birth_date="1980-01-01",phone="8 (999) 000-11-22",email="",document_note="",notes="Не потерять")
url,html=post(s,"?page=client",dict(draft,csrf=token(get(s,"?page=client"))))
assert "Возможные дубли" in html and "Не потерять" in html
assert "id=0" in url
url,html=post(s,"?page=client",dict(draft,csrf=token(html),duplicate_reason="Другой человек, семейный номер"))
client2=re.search(r'id=(\d+)',url).group(1)
assert client2!=client_id

def make_order(client,quantity=3):
    html=get(s,"?page=new-order")
    url,html=post(s,"?page=new-order",dict(csrf=token(html),submission=token(html,"submission"),action="save_order",client_id=client,visit_at=TEST_DATE+"T08:00",items_json=json.dumps([dict(service_id=1,quantity=quantity,room="101",scheduled_at="")])))
    oid=re.search(r'id=(\d+)',url).group(1)
    _,html=post(s,"?page=order&id="+oid,dict(csrf=token(html),action="set_status",id=oid,status="paid"))
    iid=re.search(r'<option value="(\d+)">',html).group(1)
    return oid,iid
oid,iid=make_order(client_id)
oid2,iid2=make_order(client2)
schedule="?page=schedule&date="+TEST_DATE
def book(item,time,room):
    return post(s,schedule,dict(csrf=token(get(s,schedule)),action="appointment_add",item_id=item,starts_at=TEST_DATE+"T"+time,minutes=20,room=room))[1]
html=book(iid,"09:00","101")
assert "Расписание обновлено" in html
html=book(iid,"09:10","102")
assert "Пересечение" in html,"Client double booking allowed"
html=book(iid2,"09:10","101")
assert "Пересечение" in html,"Room double booking allowed"
html=book(iid,"09:20","101")
assert "Расписание обновлено" in html,"Adjacent slots rejected"
assert "Подтвержденные сеансы" in get(s,"?page=print&id="+oid)
ids=re.findall(r'name="appointment_id" value="(\d+)"',html)
aid=ids[0]
try:
    post(s,schedule,dict(csrf=token(html),action="appointment_cancel",appointment_id=aid,reason="Недопустимо"))
except urllib.error.HTTPError as ex: assert ex.code==403
else: raise AssertionError("Admin cancelled appointment")
html=get(manager,schedule)
_,html=post(manager,schedule,dict(csrf=token(html),action="appointment_cancel",appointment_id=aid,reason="По просьбе клиента"))
assert "По просьбе клиента" in html and "Отменено" in html
# Complete the remaining appointment once, then reject repeat.
html=get(s,schedule)
remaining=re.search(r'name="appointment_id" value="(\d+)"',html).group(1)
payload=dict(csrf=token(html),action="appointment_done",appointment_id=remaining,performed_at=TEST_DATE+"T09:20")
_,html=post(s,schedule,payload);assert "Расписание обновлено" in html
_,html=post(s,schedule,payload);assert "уже обработана" in html
# Direct entry idempotency: one token can only consume one session.
html=get(s,"?page=order&id="+oid)
payload=dict(csrf=token(html),event_token=token(html,"event_token"),action="perform",item_id=iid,performed_at=TEST_DATE+"T10:00")
post(s,"?page=order&id="+oid,payload)
post(s,"?page=order&id="+oid,payload)
html=get(s,"?page=order&id="+oid)
assert "выполнено 2 из 3" in html
# Create an individual staff member and force private password setup.
html=get(manager,"?page=users")
_,html=post(manager,"?page=users",dict(csrf=token(html),action="user_create",name="Анна Тестовая",login="anna-test",role="admin",password="Temporary-729!"))
assert "Анна Тестовая" in html
staff=session();h=get(staff,"?page=login")
url,h=post(staff,"?page=login",dict(csrf=token(h),login="anna-test",password="Temporary-729!"))
assert "page=password" in url
_,h=post(staff,"?page=password",dict(csrf=token(h),action="password_change",current_password="Temporary-729!",password="Personal-892!",password_confirm="Personal-892!"))
assert "Пароль изменен" in h
try: get(staff,"?page=users")
except urllib.error.HTTPError as ex: assert ex.code==403
else: raise AssertionError("Staff can manage users")
# The new user is the last row for this test database; obtain ID from the matching row.
h=get(manager,"?page=users")
row=re.search(r"<tr><td>Анна Тестовая.*?</tr>",h,re.S).group(0)
uid=re.search(r'name="user_id" value="(\d+)"',row).group(1)
post(manager,"?page=users",dict(csrf=token(h),action="user_toggle",user_id=uid))
post(manager,"?page=users",dict(csrf=token(h),action="user_toggle",user_id=uid))
assert "Вход в систему" in get(staff,"?page=dashboard")
assert "Отчет об оплатах" in get(manager,"?page=reports")
try: get(s,"?page=reports")
except urllib.error.HTTPError as ex: assert ex.code==403
else: raise AssertionError("Payment report exposed to admin")
# Throttle cannot be bypassed by starting a fresh session.
for _ in range(5):
    bad=session();h=get(bad,"?page=login")
    post(bad,"?page=login",dict(csrf=token(h),login="does-not-exist",password="wrong"))
bad=session();h=get(bad,"?page=login")
try: post(bad,"?page=login",dict(csrf=token(h),login="does-not-exist",password="wrong"))
except urllib.error.HTTPError as ex: assert ex.code==429
else: raise AssertionError("Persistent login rate limit not enforced")
print("PASS: duplicate preservation/override, client and room conflicts, adjacent slots, manager-only cancellation, appointment completion, event replay, named users, forced password change, disabled session revocation, persistent login throttle.")
