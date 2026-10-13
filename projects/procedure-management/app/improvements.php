<?php
declare(strict_types=1);

function upgrade_practices(PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS security_profiles (
 user_id INTEGER PRIMARY KEY REFERENCES users(id), must_change INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS session_versions (
 user_id INTEGER PRIMARY KEY REFERENCES users(id), version INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS auth_failures (
 id INTEGER PRIMARY KEY, bucket TEXT NOT NULL, attempted_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_auth_bucket_time ON auth_failures(bucket,attempted_at);
CREATE TABLE IF NOT EXISTS appointments (
 id INTEGER PRIMARY KEY, item_id INTEGER NOT NULL REFERENCES order_items(id),
 starts_at TEXT NOT NULL, ends_at TEXT NOT NULL, room TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'scheduled' CHECK(status IN ('scheduled','done','cancelled')),
 created_by INTEGER NOT NULL REFERENCES users(id), created_at TEXT NOT NULL,
 reason TEXT NOT NULL DEFAULT '', event_id INTEGER UNIQUE REFERENCES procedure_events(id)
);
CREATE INDEX IF NOT EXISTS idx_appointments_time ON appointments(starts_at,ends_at);
CREATE INDEX IF NOT EXISTS idx_appointments_item_status ON appointments(item_id,status);
CREATE INDEX IF NOT EXISTS idx_events_item ON procedure_events(item_id);
CREATE TABLE IF NOT EXISTS action_receipts (
 token TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id),
 kind TEXT NOT NULL, entity_id INTEGER NOT NULL
);
PRAGMA optimize;
SQL);
}
function refresh_identity(): void {
    if(!current_user())return;
    $q=db()->prepare('SELECT u.*,COALESCE(s.must_change,0) must_change,COALESCE(v.version,0) session_version FROM users u LEFT JOIN security_profiles s ON s.user_id=u.id LEFT JOIN session_versions v ON v.user_id=u.id WHERE u.id=?');
    $q->execute([current_user()['id']]);$u=$q->fetch();
    if(!$u || !$u['is_active'] || !hash_equals(hash('sha256',$u['password_hash']),$_SESSION['credential']??'')
        || (int)$u['session_version']!==($_SESSION['session_version']??0)
        || time()-($_SESSION['last_seen']??0)>1800 || time()-($_SESSION['signed_at']??0)>28800){
        $_SESSION=[];session_regenerate_id(true);return;
    }
    $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'role'=>$u['role'],'must_change'=>(int)$u['must_change']];
    $_SESSION['last_seen']=time();
}
function auth_buckets(string $login): array {
    return ['login:'.hash('sha256',mb_strtolower(trim($login))),'ip:'.hash('sha256',$_SERVER['REMOTE_ADDR']??'local')];
}
function login_blocked(string $login): bool {
    foreach(auth_buckets($login) as $bucket){
        $q=db()->prepare('SELECT COUNT(*) FROM auth_failures WHERE bucket=? AND attempted_at>?');
        $q->execute([$bucket,time()-900]);
        if((int)$q->fetchColumn()>=(str_starts_with($bucket,'ip:')?30:5))return true;
    }
    return false;
}
function failed_login(string $login): void {
    $q=db()->prepare('INSERT INTO auth_failures(bucket,attempted_at) VALUES(?,?)');
    foreach(auth_buckets($login) as $bucket)$q->execute([$bucket,time()]);
    audit('login_failed','security',null,'Неверные учетные данные');
}
function require_manager(): void {
    if(!is_manager()){audit('access_denied','security',null,'Требуется руководитель');http_response_code(403);exit('Доступ только руководителю.');}
}
function password_valid(string $password): bool {return mb_strlen($password)>=12 && strlen($password)<=72;}
function normalize_phone(string $phone): string {
    $digits=preg_replace('/\D/','',$phone);
    if(strlen($digits)===11 && $digits[0]==='8')$digits='7'.substr($digits,1);
    if(strlen($digits)===10)$digits='7'.$digits;
    return $digits;
}
function check_duplicate(array $v,int $id): void {
    $q=db()->prepare('SELECT * FROM clients WHERE id<>?');
    $q->execute([$id]);$matches=[];
    foreach($q as $c){
        $sameName=mb_strtolower($c['last_name'])===mb_strtolower($v[0]) && mb_strtolower($c['first_name'])===mb_strtolower($v[1])
            && mb_strtolower($c['middle_name'])===mb_strtolower($v[2]);
        $sameBirth=$v[3]!==null && $v[3]===$c['birth_date'];
        $phone=normalize_phone($v[4]);$samePhone=$phone!=='' && $phone===normalize_phone($c['phone']);
        if(($sameName && $sameBirth)||$samePhone)$matches[]=$c;
    }
    if($matches && trim((string)($_POST['duplicate_reason']??''))===''){
        $_SESSION['client_draft']=$_POST;
        $_SESSION['duplicate_matches']=$matches;
        throw new RuntimeException('Найдены похожие карточки. Выберите существующего клиента либо укажите причину создания отдельной записи.');
    }
    if($matches)audit('duplicate_override','client',$id?:null,trim((string)$_POST['duplicate_reason']));
}
function appointment_item(int $id): array {
    $q=db()->prepare("SELECT i.*,o.client_id,o.status order_status,o.archived_at,c.archived_at client_archived FROM order_items i JOIN orders o ON o.id=i.order_id JOIN clients c ON c.id=o.client_id WHERE i.id=?");
    $q->execute([$id]);$i=$q->fetch();
    if(!$i || $i['archived_at'] || $i['client_archived'] || $i['order_status']==='cancelled')throw new RuntimeException('Назначение недоступно для записи.');
    return $i;
}
function check_slot(array $item,string $start,string $end,string $room): void {
    $q=db()->prepare("SELECT a.id FROM appointments a JOIN order_items i ON i.id=a.item_id JOIN orders o ON o.id=i.order_id
      WHERE a.status<>'cancelled' AND a.starts_at<? AND a.ends_at>? AND (utf8_lower(a.room)=utf8_lower(?) OR o.client_id=?) LIMIT 1");
    $q->execute([$end,$start,$room,$item['client_id']]);
    if($q->fetch())throw new RuntimeException('Пересечение: кабинет/место или клиент уже заняты в это время.');
}
function complete_event(array $item,string $performed,string $notes): int {
    $q=db()->prepare('SELECT COUNT(*) FROM procedure_events WHERE item_id=?');$q->execute([$item['id']]);
    if($item['order_status']!=='paid' || (int)$q->fetchColumn()>=(int)$item['quantity'])throw new RuntimeException('Нет оплаченного остатка сеансов.');
    if($performed>now())throw new RuntimeException('Нельзя отметить оказанным будущий сеанс.');
    db()->prepare('INSERT INTO procedure_events(item_id,performed_at,created_at,user_id,notes) VALUES(?,?,?,?,?)')
        ->execute([$item['id'],$performed,now(),current_user()['id'],$notes]);
    $id=(int)db()->lastInsertId();
    $q=db()->prepare('SELECT SUM(quantity)-(SELECT COUNT(*) FROM procedure_events e JOIN order_items j ON j.id=e.item_id WHERE j.order_id=?) FROM order_items WHERE order_id=?');
    $q->execute([$item['order_id'],$item['order_id']]);
    if((int)$q->fetchColumn()===0)db()->prepare("UPDATE orders SET status='completed' WHERE id=?")->execute([$item['order_id']]);
    audit('performed','order',(int)$item['order_id'],json_encode(['item_id'=>$item['id'],'performed_at'=>$performed],JSON_UNESCAPED_UNICODE));
    return $id;
}
function practices_action(string $action): void {
    $db=db();
    if(in_array($action,['user_create','user_toggle','user_reset'],true)){
        require_manager();$db->beginTransaction();
        if($action==='user_create'){
            $name=trim((string)($_POST['name']??''));$login=trim((string)($_POST['login']??''));$role=$_POST['role']??'';
            $password=(string)($_POST['password']??'');
            if(!$name || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/',$login) || !in_array($role,['admin','manager'],true) || !password_valid($password))throw new RuntimeException('Проверьте ФИО, логин и пароль (от 12 символов, до 72 байт).');
            $q=$db->prepare('SELECT id FROM users WHERE lower(login)=lower(?)');$q->execute([$login]);if($q->fetch())throw new RuntimeException('Логин уже занят.');
            $db->prepare('INSERT INTO users(name,login,password_hash,role) VALUES(?,?,?,?)')->execute([$name,$login,password_hash($password,PASSWORD_DEFAULT),$role]);
            $id=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO security_profiles(user_id,must_change) VALUES(?,1)')->execute([$id]);
        }else{
            $id=(int)($_POST['user_id']??0);
            $q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$target=$q->fetch();
            if(!$target)throw new RuntimeException('Сотрудник не найден.');
            if($id===current_user()['id'])throw new RuntimeException('Используйте страницу «Мой пароль». Отключить себя нельзя.');
            if($action==='user_toggle'){
                if($target['is_active'] && $target['role']==='manager' && (int)$db->query("SELECT COUNT(*) FROM users WHERE role='manager' AND is_active=1")->fetchColumn()<=1)throw new RuntimeException('Нельзя отключить последнего руководителя.');
                $db->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([1-(int)$target['is_active'],$id]);
                $db->prepare('INSERT INTO session_versions(user_id,version) VALUES(?,1) ON CONFLICT(user_id) DO UPDATE SET version=version+1')->execute([$id]);
            }else{
                $password=(string)($_POST['password']??'');if(!password_valid($password))throw new RuntimeException('Пароль: от 12 символов, до 72 байт.');
                $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
                $db->prepare('INSERT INTO security_profiles(user_id,must_change) VALUES(?,1) ON CONFLICT(user_id) DO UPDATE SET must_change=1')->execute([$id]);
            }
        }
        audit($action,'user',$id);$db->commit();flash('success','Учетная запись обновлена. Временный пароль сотрудник изменит при входе.');redirect('?page=users');
    }
    if($action==='password_change'){
        $q=$db->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([current_user()['id']]);$old=$q->fetchColumn();
        $password=(string)($_POST['password']??'');
        if(!password_verify((string)($_POST['current_password']??''),$old) || !password_valid($password) || $password!==($_POST['password_confirm']??'') || password_verify($password,$old))throw new RuntimeException('Проверьте текущий пароль; новый должен отличаться и совпадать с подтверждением (от 12 символов).');
        $db->beginTransaction();$hash=password_hash($password,PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash,current_user()['id']]);
        $db->prepare('INSERT INTO security_profiles(user_id,must_change) VALUES(?,0) ON CONFLICT(user_id) DO UPDATE SET must_change=0')->execute([current_user()['id']]);
        audit('password_change','user',current_user()['id']);$db->commit();
        session_regenerate_id(true);$_SESSION['credential']=hash('sha256',$hash);$_SESSION['user']['must_change']=0;
        flash('success','Пароль изменен. Другие сеансы входа завершены.');redirect('?page=password');
    }
    if(in_array($action,['appointment_add','appointment_cancel','appointment_done'],true)){
        if($action==='appointment_cancel')require_manager();
        // A write acquires SQLite's writer lock before availability is read.
        $db->beginTransaction();$db->exec('UPDATE appointments SET id=id WHERE id=-1');
        if($action==='appointment_add'){
            $item=appointment_item((int)($_POST['item_id']??0));
            $start=valid_datetime((string)($_POST['starts_at']??''));
            $minutes=filter_var($_POST['minutes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>480]]);
            $room=preg_replace('/\s*\/\s*/u',' / ',preg_replace('/\s+/u',' ',trim((string)($_POST['room']??''))));
            if(!$minutes || !$room || mb_strlen($room)>80)throw new RuntimeException('Укажите кабинет/место и длительность 1–480 минут.');
            $end=date('Y-m-d H:i:s',strtotime($start)+$minutes*60);
            $q=$db->prepare("SELECT (SELECT COUNT(*) FROM appointments WHERE item_id=? AND status='scheduled')+(SELECT COUNT(*) FROM procedure_events WHERE item_id=?)");
            $q->execute([$item['id'],$item['id']]);if((int)$q->fetchColumn()>=$item['quantity'])throw new RuntimeException('Все сеансы уже запланированы или оказаны.');
            check_slot($item,$start,$end,$room);
            $db->prepare('INSERT INTO appointments(item_id,starts_at,ends_at,room,created_by,created_at) VALUES(?,?,?,?,?,?)')->execute([$item['id'],$start,$end,$room,current_user()['id'],now()]);
            $id=(int)$db->lastInsertId();
        }else{
            $id=(int)($_POST['appointment_id']??0);$q=$db->prepare('SELECT * FROM appointments WHERE id=?');$q->execute([$id]);$a=$q->fetch();
            if(!$a || $a['status']!=='scheduled')throw new RuntimeException('Запись уже обработана.');
            if($action==='appointment_cancel'){
                $reason=trim((string)($_POST['reason']??''));if(!$reason)throw new RuntimeException('Укажите причину отмены.');
                $db->prepare("UPDATE appointments SET status='cancelled',reason=? WHERE id=?")->execute([$reason,$id]);
            }else{
                $item=appointment_item((int)$a['item_id']);
                $event=complete_event($item,valid_datetime((string)($_POST['performed_at']??'')),trim((string)($_POST['notes']??'')));
                $db->prepare("UPDATE appointments SET status='done',event_id=? WHERE id=?")->execute([$event,$id]);
            }
        }
        audit($action,'appointment',$id,json_encode($_POST['reason']??'',JSON_UNESCAPED_UNICODE));
        $db->commit();flash('success','Расписание обновлено.');redirect('?page=schedule&date='.substr($_POST['starts_at']??$a['starts_at'],0,10));
    }
}
