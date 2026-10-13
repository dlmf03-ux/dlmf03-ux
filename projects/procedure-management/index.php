<?php
declare(strict_types=1);
require __DIR__.'/app/portfolio-bootstrap.php';
require __DIR__.'/app/improvements.php';
$db=db();
upgrade_practices($db);
refresh_identity();
if((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===0){require __DIR__.'/app/install.php';exit;}
$page=$_GET['page']??'dashboard';

if ($page==='login') {
    if (current_user()) redirect('?page=dashboard');
    $error='';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_csrf();
        if(login_blocked((string)($_POST['login']??''))){http_response_code(429);exit('Слишком много попыток. Повторите вход через 15 минут.');}
        $q=$db->prepare('SELECT * FROM users WHERE login=? AND is_active=1');
        $q->execute([trim((string)($_POST['login']??''))]);
        $u=$q->fetch();
        if ($u && password_verify((string)($_POST['password']??''),$u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'role'=>$u['role']];
            $_SESSION['credential']=hash('sha256',$u['password_hash']);
            $_SESSION['signed_at']=$_SESSION['last_seen']=time();
            $q=$db->prepare('SELECT version FROM session_versions WHERE user_id=?');$q->execute([$u['id']]);
            $_SESSION['session_version']=(int)($q->fetchColumn()?:0);
            audit('login','user',(int)$u['id']); redirect('?page=dashboard');
        }
        failed_login((string)($_POST['login']??''));$error='Неверный логин или пароль';
    }
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Вход — <?=APP_NAME?></title><link rel="stylesheet" href="assets/vendor/bootstrap.min.css"><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/refinements.css"></head>
    <body class="login-page"><main class="login-card">
      <img class="official-logo" src="assets/demo-mark.svg" alt="Demo workspace">
      <h1>Вход в систему</h1><p class="muted">Procedure management workspace</p>
      <?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif?>
      <form method="post" class="form-grid one"><input type="hidden" name="csrf" value="<?=csrf()?>">
        <label>Логин<input name="login" autocomplete="username" required autofocus></label>
        <label>Пароль<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="btn primary">Войти</button>
      </form>
      <p class="safe-note">Demo access only</p>
    </main></body></html><?php exit;
}
if ($page==='logout' && $_SERVER['REQUEST_METHOD']==='POST') { check_csrf();session_destroy(); redirect('?page=login'); }
require_auth();
if((current_user()['must_change']??0) && $page!=='password')redirect('?page=password');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    $action=$_POST['action']??'';
    try {
        practices_action((string)$action);
        if ($action==='save_client') {
            $db->beginTransaction();
            $id=(int)($_POST['id']??0);
            $values=[trim((string)$_POST['last_name']),trim((string)$_POST['first_name']),trim((string)($_POST['middle_name']??'')),
                $_POST['birth_date']?:null,trim((string)($_POST['phone']??'')),trim((string)($_POST['email']??'')),
                trim((string)($_POST['document_note']??'')),trim((string)($_POST['notes']??'')),now()];
            if (!$values[0]||!$values[1]) throw new RuntimeException('Укажите фамилию и имя клиента.');
            if($values[3]!==null){
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$values[3]);
                if(!$date || $date->format('Y-m-d')!==$values[3] || $values[3]>date('Y-m-d'))throw new RuntimeException('Проверьте дату рождения.');
            }
            if($values[5] && !filter_var($values[5],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Некорректный email.');
            check_duplicate($values,$id);
            if ($id) {
                $before=record('clients',$id);
                if($before['archived_at'])throw new RuntimeException('Клиент находится в архиве.');
                $q=$db->prepare('UPDATE clients SET last_name=?,first_name=?,middle_name=?,birth_date=?,phone=?,email=?,document_note=?,notes=?,updated_at=? WHERE id=? AND archived_at IS NULL');
                $q->execute([...$values,$id]); audit('update','client',$id,json_encode(['before'=>$before,'after'=>record('clients',$id)],JSON_UNESCAPED_UNICODE)); flash('success','Данные клиента обновлены.');
            } else {
                $q=$db->prepare('INSERT INTO clients(last_name,first_name,middle_name,birth_date,phone,email,document_note,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $q->execute([...$values,now()]); $id=(int)$db->lastInsertId(); audit('create','client',$id); flash('success','Клиент создан.');
            }
            $db->commit();unset($_SESSION['client_draft'],$_SESSION['duplicate_matches']);redirect('?page=client&id='.$id);
        }
        if ($action==='save_order') {
            $clientId=(int)($_POST['client_id']??0);
            $items=json_decode((string)($_POST['items_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            if (!$clientId || !is_array($items) || !$items || count($items)>200) throw new RuntimeException('Выберите клиента и добавьте от 1 до 200 позиций.');
            $client=record('clients',$clientId);if($client['archived_at'])throw new RuntimeException('Клиент находится в архиве.');
            $submission=(string)($_POST['submission']??'');
            if(!preg_match('/^[a-f0-9]{48}$/',$submission))throw new RuntimeException('Обновите форму назначения.');
            $db->beginTransaction();
            $existing=$db->prepare('SELECT order_id FROM submission_keys WHERE token=?');$existing->execute([$submission]);
            if($existingId=$existing->fetchColumn()){$db->rollBack();redirect('?page=order&id='.(int)$existingId);}
            $q=$db->prepare('INSERT INTO orders(client_id,visit_at,notes,created_by,created_at) VALUES(?,?,?,?,?)');
            $visit=valid_datetime((string)($_POST['visit_at']??''));
            $q->execute([$clientId,$visit?:now(),trim((string)($_POST['notes']??'')),current_user()['id'],now()]);
            $orderId=(int)$db->lastInsertId(); $number='DM-'.date('ymd').'-'.str_pad((string)$orderId,4,'0',STR_PAD_LEFT);
            $db->prepare('UPDATE orders SET number=? WHERE id=?')->execute([$number,$orderId]);
            $find=$db->prepare('SELECT * FROM services WHERE id=? AND is_active=1');
            $insert=$db->prepare('INSERT INTO order_items(order_id,service_id,service_name,price_cents,quantity,duration,room,scheduled_at,line_total_cents) VALUES(?,?,?,?,?,?,?,?,?)');
            $total=0;
            foreach ($items as $item) {
                $find->execute([(int)$item['service_id']]); $s=$find->fetch();
                $qty=filter_var($item['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>99]]);
                if(!$qty || !$s) throw new RuntimeException('Проверьте услугу и количество (1–99).');
                $line=(int)$s['price_cents']*$qty; $total+=$line;
                $scheduled=trim((string)($item['scheduled_at']??'')); if($scheduled)$scheduled=valid_datetime($scheduled);
                $insert->execute([$orderId,$s['id'],$s['name'],$s['price_cents'],$qty,$s['duration'],trim((string)($item['room']??$s['room'])),$scheduled?:null,$line]);
            }
            $db->prepare('UPDATE orders SET total_cents=? WHERE id=?')->execute([$total,$orderId]);
            $db->prepare('INSERT INTO submission_keys(token,order_id) VALUES(?,?)')->execute([$submission,$orderId]);
            audit('create','order',$orderId,'Сумма: '.$total.' коп.'); $db->commit();
            flash('success','Назначение '.$number.' сохранено.'); redirect('?page=order&id='.$orderId);
        }
        if ($action==='set_status') {
            $db->beginTransaction();
            $id=(int)$_POST['id']; $status=(string)$_POST['status'];
            $before=record('orders',$id);
            if($status!=='paid' || $before['status']!=='unpaid' || $before['archived_at'])throw new RuntimeException('Оплату можно зафиксировать только один раз для неоплаченного назначения.');
            $extra=$status==='paid'?', paid_at=:paid_at, paid_by=:paid_by':'';
            $q=$db->prepare("UPDATE orders SET status=:status $extra WHERE id=:id AND status='unpaid' AND archived_at IS NULL");
            $args=['status'=>$status,'id'=>$id]; if($status==='paid'){$args['paid_at']=now();$args['paid_by']=current_user()['id'];}
            $q->execute($args); if($q->rowCount())audit('payment','order',$id,json_encode(['amount_cents'=>$before['total_cents'],'status'=>$status],JSON_UNESCAPED_UNICODE));$db->commit(); flash('success','Оплата зафиксирована.'); redirect('?page=order&id='.$id);
        }
        if($action==='perform'){
            $itemId=(int)($_POST['item_id']??0);
            $db->beginTransaction();$db->exec('UPDATE appointments SET id=id WHERE id=-1');
            $token=(string)($_POST['event_token']??'');
            if(!preg_match('/^[a-f0-9]{48}$/',$token))throw new RuntimeException('Обновите форму учета сеанса.');
            $q=$db->prepare('SELECT entity_id FROM action_receipts WHERE token=? AND user_id=? AND kind=?');
            $q->execute([$token,current_user()['id'],'perform']);
            if($orderId=$q->fetchColumn()){$db->rollBack();redirect('?page=order&id='.(int)$orderId);}
            $it=appointment_item($itemId);
            $q=$db->prepare("SELECT COUNT(*) FROM appointments WHERE item_id=? AND status='scheduled'");$q->execute([$itemId]);
            if((int)$q->fetchColumn()>0)throw new RuntimeException('Для этой процедуры есть запись в расписании. Отметьте оказание на странице «Расписание».');
            $performed=valid_datetime((string)($_POST['performed_at']??''));
            complete_event($it,$performed,trim((string)($_POST['notes']??'')));
            $db->prepare('INSERT INTO action_receipts(token,user_id,kind,entity_id) VALUES(?,?,?,?)')->execute([$token,current_user()['id'],'perform',$it['order_id']]);
            $db->commit();flash('success','Один сеанс отмечен как оказанный.');redirect('?page=order&id='.$it['order_id']);
        }
        if ($action==='save_service') {
            $db->beginTransaction();
            if(!preg_match('/^\d{1,7}([.,]\d{1,2})?$/',(string)($_POST['price']??'')))throw new RuntimeException('Введите корректную цену.');
            $id=(int)$_POST['id']; $price=(int)round((float)str_replace(',','.',(string)$_POST['price'])*100);
            if (!$id || $price<0 || !trim((string)$_POST['name'])) throw new RuntimeException('Проверьте данные услуги.');
            $before=record('services',$id);
            $q=$db->prepare('UPDATE services SET name=?,category=?,duration=?,price_cents=?,room=? WHERE id=?');
            $q->execute([trim((string)$_POST['name']),trim((string)$_POST['category']),trim((string)$_POST['duration']),$price,trim((string)$_POST['room']),$id]);
            audit('update','service',$id,json_encode(['before'=>$before,'after'=>record('services',$id)],JSON_UNESCAPED_UNICODE));$db->commit(); flash('success','Услуга обновлена.'); redirect('?page=services');
        }
        if($action==='restore'){
            if(!is_manager())throw new RuntimeException('Восстановление доступно только руководителю.');
            $type=(string)($_POST['type']??'');$id=(int)($_POST['id']??0);
            $tables=['client'=>'clients','order'=>'orders'];
            if(!isset($tables[$type]))throw new RuntimeException('Недопустимый объект.');
            $db->beginTransaction();
            $before=record($tables[$type],$id);
            $db->prepare("UPDATE {$tables[$type]} SET archived_at=NULL,archived_by=NULL,archive_reason=NULL WHERE id=?")->execute([$id]);
            audit('restore',$type,$id,json_encode($before,JSON_UNESCAPED_UNICODE));$db->commit();
            flash('success','Запись восстановлена.');redirect('?page=archive');
        }
        if ($action==='archive') {
            if (!is_manager()) { http_response_code(403); throw new RuntimeException('Архивирование доступно только руководителю.'); }
            $type=(string)$_POST['type']; $id=(int)$_POST['id']; $reason=trim((string)($_POST['reason']??''));
            if (!$reason) throw new RuntimeException('Укажите причину архивирования.');
            $tables=['client'=>'clients','order'=>'orders']; if(!isset($tables[$type])) throw new RuntimeException('Недопустимый объект.');
            $db->beginTransaction();
            record($tables[$type],$id);
            $q=$db->prepare("SELECT COUNT(*) FROM appointments a JOIN order_items i ON i.id=a.item_id JOIN orders o ON o.id=i.order_id WHERE a.status='scheduled' AND ".($type==='client'?'o.client_id':'o.id').'=?');
            $q->execute([$id]);if((int)$q->fetchColumn())throw new RuntimeException('Сначала отмените активные записи в расписании с указанием причины.');
            $q=$db->prepare("UPDATE {$tables[$type]} SET archived_at=?,archived_by=?,archive_reason=? WHERE id=? AND archived_at IS NULL");
            $q->execute([now(),current_user()['id'],$reason,$id]); audit('archive',$type,$id,$reason);
            $db->commit();
            flash('success','Запись перенесена в архив.'); redirect($type==='client'?'?page=clients':'?page=orders');
        }
    } catch(Throwable $ex) {
        if($db->inTransaction())$db->rollBack();
        flash('danger',$ex instanceof PDOException?'Ошибка базы данных. Проверьте данные и повторите действие.':$ex->getMessage());
        $returnTo='?page='.urlencode((string)$page).'&id='.(int)($_GET['id']??0);
        if($page==='schedule')$returnTo.='&date='.urlencode((string)($_GET['date']??date('Y-m-d')));
        redirect($returnTo);
    }
}

if ($page==='print') {
    $id=(int)($_GET['id']??0);
    $q=$db->prepare('SELECT o.*,c.last_name,c.first_name,c.middle_name,c.birth_date,c.phone,o.id order_id,o.number order_number,o.created_at order_created FROM orders o JOIN clients c ON c.id=o.client_id WHERE o.id=?');
    $q->execute([$id]); $order=$q->fetch(); if(!$order){http_response_code(404);exit('Назначение не найдено');}
    $q=$db->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$q->execute([$id]);$items=$q->fetchAll();
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><title><?=e($order['order_number'])?></title><link rel="stylesheet" href="assets/app.css"></head>
    <body class="print-sheet"><div class="print-actions"><button class="btn primary" onclick="print()">Печать</button><button class="btn" onclick="history.back()">Назад</button></div>
    <header class="print-head"><div class="brand"><span class="brand-mark">Л</span><span><b>Demo</b><small>organization</small></span></div><div class="print-meta"><b>Назначение № <?=e($order['order_number'])?></b><br>Оформлено: <?=e(date('d.m.Y H:i',strtotime($order['order_created'])))?></div></header>
    <h1>Перечень назначенных процедур</h1><p style="text-align:center;font-size:12px">Расчетный лист · не является кассовым чеком · <?=status_label($order['status'])?></p>
    <div class="client-line"><b>Клиент:</b> <?=e(client_name($order))?><?php if($order['birth_date']):?> · <?=e(date('d.m.Y',strtotime($order['birth_date'])))?><?php endif?><?php if($order['phone']):?> · <?=e($order['phone'])?><?php endif?></div>
    <div class="visit-line"><b>Время обращения / начала:</b> <?=e(date('d.m.Y H:i',strtotime($order['visit_at'])))?></div>
    <table class="print-table"><thead><tr><th>№</th><th>Процедура / услуга</th><th>Кол-во</th><th>Кабинет</th><th>Дата и время</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>
    <?php foreach($items as $i=>$it):?><tr><td><?=$i+1?></td><td><?=e($it['service_name'])?><?php if($it['duration']):?><small><?=e($it['duration'])?> мин.</small><?php endif?></td><td><?=$it['quantity']?></td><td><?=e($it['room']?:'—')?></td><td><?=e($it['scheduled_at']?date('d.m.Y H:i',strtotime($it['scheduled_at'])):'—')?></td><td><?=money((int)$it['price_cents'])?></td><td><?=money((int)$it['line_total_cents'])?></td></tr><?php endforeach?>
    </tbody><tfoot><tr><td colspan="6">Итого к оплате</td><td><?=money((int)$order['total_cents'])?></td></tr></tfoot></table>
    <p style="font-size:12px">Кабинет и дата в перечне — пожелания при оформлении. Подтвержденное расписание сеансов приведено ниже.</p>
    <?php $q=$db->prepare("SELECT a.*,i.service_name FROM appointments a JOIN order_items i ON i.id=a.item_id WHERE i.order_id=? AND a.status<>'cancelled' ORDER BY a.starts_at");$q->execute([$id]);$bookings=$q->fetchAll();?>
    <h2>Подтвержденные сеансы</h2>
    <?php if($bookings):?><table class="print-table"><thead><tr><th>Процедура</th><th>Начало</th><th>Окончание</th><th>Кабинет / место</th></tr></thead><tbody>
    <?php foreach($bookings as $b):?><tr><td><?=e($b['service_name'])?></td><td><?=e($b['starts_at'])?></td><td><?=e($b['ends_at'])?></td><td><?=e($b['room'])?></td></tr><?php endforeach?></tbody></table>
    <?php else:?><p>Сеансы еще не записаны. Обратитесь к администратору для выбора времени.</p><?php endif?>
    <?php if($order['notes']):?><p><b>Примечание:</b> <?=nl2br(e($order['notes']))?></p><?php endif?>
    <div class="signatures"><span>Администратор __________________</span><span>Клиент __________________</span></div>
    <footer>АО «organization „Demo“» · Demo address · +1-555-0100</footer>
    </body></html><?php exit;
}

function layout_start(string $title,string $active=''): void {
    $flashes=$_SESSION['flash']??[]; unset($_SESSION['flash']); $u=current_user();
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> — <?=APP_NAME?></title><link rel="stylesheet" href="assets/vendor/bootstrap.min.css"><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/refinements.css"></head><body>
    <aside class="sidebar"><a class="brand" href="?"><img class="official-logo" src="assets/demo-mark.svg" alt="Procedure management demo"></a>
      <div class="practice-shortcuts"><a href="?page=schedule">Расписание сеансов</a><a href="?page=password">Мой пароль</a><?php if(is_manager()):?><a href="?page=users">Сотрудники</a><a href="?page=reports">Отчет об оплатах</a><?php endif?></div>
      <?php if(is_manager()):?><a class="text-white mt-3" href="?page=archive">Архив и восстановление →</a><?php endif?>
      <nav><a class="<?=$active==='dashboard'?'active':''?>" href="?">Обзор</a><a class="<?=$active==='clients'?'active':''?>" href="?page=clients">Клиенты</a><a class="<?=$active==='new'?'active':''?>" href="?page=new-order">Новое назначение</a><a class="<?=$active==='orders'?'active':''?>" href="?page=orders">Назначения</a><a class="<?=$active==='services'?'active':''?>" href="?page=services">Прайс и кабинеты</a><?php if(is_manager()):?><a class="<?=$active==='audit'?'active':''?>" href="?page=audit">Журнал действий</a><?php endif?></nav>
      <div class="user-box"><span class="avatar"><?=e(mb_substr($u['name'],0,1))?></span><span><b><?=e($u['name'])?></b><small><?=$u['role']==='manager'?'Руководитель':'Администратор'?></small></span><form method="post" action="?page=logout"><input type="hidden" name="csrf" value="<?=csrf()?>"><button class="logout-button" title="Выйти">↗</button></form></div>
    </aside><main class="content"><header class="topbar"><button class="menu-toggle" aria-label="Меню">☰</button><div><h1><?=e($title)?></h1><p><?=e(date('d.m.Y, H:i'))?></p></div><a class="btn primary" href="?page=new-order">+ Назначить процедуры</a></header>
    <?php foreach($flashes as $f):?><div class="alert <?=e($f['type'])?>"><?=e($f['text'])?></div><?php endforeach;
}
function layout_end(): void { echo '</main><script src="assets/app.js"></script></body></html>'; }
if(in_array($page,['schedule','users','password','reports'],true)){require __DIR__.'/app/practices-pages.php';exit;}

if ($page==='dashboard') {
    $stats=[
      'clients'=>(int)$db->query('SELECT COUNT(*) FROM clients WHERE archived_at IS NULL')->fetchColumn(),
      'today'=>(int)$db->query("SELECT COUNT(*) FROM orders WHERE date(created_at)=".$db->quote(date('Y-m-d'))." AND archived_at IS NULL")->fetchColumn(),
      'unpaid'=>(int)$db->query("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE status='unpaid' AND archived_at IS NULL")->fetchColumn(),
      'paid'=>(int)$db->query("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE status IN ('paid','completed') AND date(paid_at)=".$db->quote(date('Y-m-d')))->fetchColumn(),
    ];
    $recent=$db->query('SELECT o.*,c.last_name,c.first_name FROM orders o JOIN clients c ON c.id=o.client_id WHERE o.archived_at IS NULL ORDER BY o.created_at DESC LIMIT 8')->fetchAll();
    layout_start('Рабочий стол','dashboard');?>
    <section class="welcome"><div><span class="eyebrow">Today</span><h2>Все назначения под контролем</h2><p>Найдите клиента или сразу оформите новый перечень процедур.</p></div><form action="" method="get" class="quick-search"><input type="hidden" name="page" value="clients"><label>Быстрый поиск клиента<input name="q" placeholder="Фамилия или телефон"></label><button class="btn light">Найти</button></form></section>
    <section class="stats"><article><span>Клиентов</span><strong><?=$stats['clients']?></strong><small>в активной базе</small></article><article><span>Обращений сегодня</span><strong><?=$stats['today']?></strong><small>зафиксировано</small></article><article><span>Ожидает оплаты</span><strong><?=money($stats['unpaid'])?></strong><small>по всем назначениям</small></article><article><span>Оплачено сегодня</span><strong><?=money($stats['paid'])?></strong><small>поступления</small></article></section>
    <section class="panel"><div class="panel-head"><div><h2>Последние назначения</h2><p>Недавно оформленные обращения</p></div><a href="?page=orders">Все назначения →</a></div><?php order_table($recent);?></section>
    <?php layout_end(); exit;
}

if ($page==='clients') {
    $search=trim((string)($_GET['q']??'')); $like='%'.$search.'%';
    $q=$db->prepare("SELECT c.*,COUNT(o.id) orders_count,COALESCE(SUM(CASE WHEN o.status IN ('paid','completed') THEN o.total_cents ELSE 0 END),0) spent FROM clients c LEFT JOIN orders o ON o.client_id=c.id WHERE c.archived_at IS NULL AND (utf8_lower(c.last_name||' '||c.first_name||' '||c.middle_name) LIKE utf8_lower(?) OR c.phone LIKE ?) GROUP BY c.id ORDER BY c.last_name,c.first_name LIMIT 100");
    $q->execute([$like,$like]);$clients=$q->fetchAll(); layout_start('Клиенты','clients');?>
    <section class="toolbar"><form><input type="hidden" name="page" value="clients"><input name="q" value="<?=e($search)?>" placeholder="Фамилия, имя или телефон"><button class="btn">Найти</button></form><a class="btn primary" href="?page=client">+ Новый клиент</a></section>
    <section class="panel"><div class="panel-head"><div><h2><?= $search?'Результаты поиска':'Клиентская база'?></h2><p>Найдено: <?=count($clients)?></p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Клиент</th><th>Телефон</th><th>Дата рождения</th><th>Обращений</th><th>Оплачено</th><th></th></tr></thead><tbody>
    <?php foreach($clients as $c):?><tr><td><a class="client-name" href="?page=client&id=<?=$c['id']?>"><?=e(client_name($c))?></a><small>Создан <?=e(date('d.m.Y H:i',strtotime($c['created_at'])))?></small></td><td><?=e($c['phone']?:'—')?></td><td><?=e($c['birth_date']?date('d.m.Y',strtotime($c['birth_date'])):'—')?></td><td><?=$c['orders_count']?></td><td><b><?=money((int)$c['spent'])?></b></td><td><a class="row-link" href="?page=new-order&client_id=<?=$c['id']?>">Назначить</a></td></tr><?php endforeach?>
    <?php if(!$clients):?><tr><td colspan="6" class="empty">Клиенты не найдены. Уточните запрос или создайте новую карточку.</td></tr><?php endif?>
    </tbody></table></div></section><?php layout_end();exit;
}

if ($page==='client') {
    $id=(int)($_GET['id']??0); $client=null;$orders=[];
    if($id){$q=$db->prepare('SELECT * FROM clients WHERE id=?');$q->execute([$id]);$client=$q->fetch();if(!$client){http_response_code(404);exit('Клиент не найден');}$q=$db->prepare('SELECT * FROM orders WHERE client_id=? AND archived_at IS NULL ORDER BY created_at DESC');$q->execute([$id]);$orders=$q->fetchAll();}
    layout_start($client?client_name($client):'Новый клиент','clients');?>
    <?php
    $draft=$_SESSION['client_draft']??[];
    $showDraft=$draft && (int)($draft['id']??0)===$id;
    if($showDraft):?>
    <section class="panel mb-3"><div class="panel-head"><div><h2>Возможные дубли — проверьте перед сохранением</h2><p>Одинаковый телефон может принадлежать семье. Мы не объединяем карточки автоматически.</p></div></div><div class="p-3">
    <?php foreach($_SESSION['duplicate_matches']??[] as $match):?><p><a href="?page=client&id=<?=$match['id']?>"><?=e(client_name($match))?> · <?=e($match['birth_date']??'')?> · <?=e($match['phone'])?></a> <?=$match['archived_at']?'(в архиве — обратитесь к руководителю)':''?></p><?php endforeach?>
    </div></section><?php endif?>
    <?php if($client):?><div class="client-summary"><div><span class="avatar large"><?=e(mb_substr($client['first_name'],0,1))?></span></div><div><span class="eyebrow">Карточка клиента № <?=$client['id']?></span><h2><?=e(client_name($client))?></h2><p><?=e($client['phone']?:'Телефон не указан')?><?= $client['birth_date']?' · '.e(date('d.m.Y',strtotime($client['birth_date']))):''?></p></div><a class="btn primary" href="?page=new-order&client_id=<?=$id?>">+ Новое назначение</a></div><?php endif?>
    <div class="two-cols"><section class="panel"><div class="panel-head"><div><h2><?=$client?'Личные данные':'Создание клиента'?></h2><p>Сохраняется время создания и каждого изменения</p></div></div>
      <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_client"><input type="hidden" name="id" value="<?=$id?>">
       <?php $formClient=$showDraft?$draft:($client??[]);?>
       <label>Фамилия *<input name="last_name" required value="<?=e($formClient['last_name']??'')?>"></label><label>Имя *<input name="first_name" required value="<?=e($formClient['first_name']??'')?>"></label>
       <label>Отчество<input name="middle_name" value="<?=e($formClient['middle_name']??'')?>"></label><label>Дата рождения<input type="date" name="birth_date" value="<?=e($formClient['birth_date']??'')?>"></label>
       <label>Телефон<input name="phone" value="<?=e($formClient['phone']??'')?>" placeholder="+7 (___) ___-__-__"></label><label>Email<input type="email" name="email" value="<?=e($formClient['email']??'')?>"></label>
       <label class="full">Документ / номер reference number<input name="document_note" value="<?=e($formClient['document_note']??'')?>"></label><label class="full">Примечание<textarea name="notes" rows="3"><?=e($formClient['notes']??'')?></textarea></label>
       <?php if($showDraft):?><label class="full">Это другой человек: причина отдельной карточки<input name="duplicate_reason" maxlength="500" placeholder="Например: общий семейный телефон"></label><?php endif?>
       <div class="form-actions full"><button class="btn primary">Сохранить</button><?php if($client&&is_manager()):?><button type="button" class="btn danger-outline archive-open" data-type="client" data-id="<?=$id?>">В архив</button><?php endif?></div>
      </form></section>
      <section class="panel"><div class="panel-head"><div><h2>История обращений</h2><p><?=count($orders)?> назначений</p></div></div><?php if($client): order_table($orders);else:?><div class="empty">История появится после сохранения клиента.</div><?php endif?></section></div>
    <?php archive_dialog();layout_end();exit;
}

if ($page==='new-order') {
    $clients=$db->query('SELECT id,last_name,first_name,middle_name,phone FROM clients WHERE archived_at IS NULL ORDER BY last_name,first_name')->fetchAll();
    $services=$db->query('SELECT * FROM services WHERE is_active=1 ORDER BY category,CAST(price_number AS REAL),price_number')->fetchAll();
    $selected=(int)($_GET['client_id']??0); layout_start('Новое назначение','new');?>
    <form method="post" id="order-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="submission" value="<?=bin2hex(random_bytes(24))?>"><input type="hidden" name="action" value="save_order"><input type="hidden" name="items_json" id="items-json">
    <div class="order-layout"><section class="panel order-main"><div class="steps"><span class="done">1</span><b>Клиент</b><i></i><span class="active">2</span><b>Процедуры</b><i></i><span>3</span><b>Сохранение и печать</b></div>
      <div class="form-grid"><label class="full">Клиент *<select name="client_id" required><option value="">Выберите из клиентской базы</option><?php foreach($clients as $c):?><option value="<?=$c['id']?>" <?=$selected===$c['id']?'selected':''?>><?=e(client_name($c).' · '.($c['phone']?:'без телефона'))?></option><?php endforeach?></select></label>
      <label>Время обращения / начала *<input type="datetime-local" name="visit_at" required value="<?=date('Y-m-d\TH:i')?>"></label><label>Примечание<input name="notes" placeholder="Направление, пожелания"></label><p class="full muted">Дата и кабинет в строках — предварительные пожелания. После сохранения запишите каждый сеанс на странице «Расписание» с проверкой занятости.</p></div>
      <div class="service-picker"><div class="picker-head"><div><h2>Добавьте процедуры</h2><p>Цены из прейскуранта с 01.06.2025</p></div><input id="service-search" placeholder="Поиск по названию"></div>
      <div class="category-tabs" id="category-tabs"><button type="button" class="active" data-category="">Все</button><?php foreach(array_unique(array_column($services,'category')) as $cat):?><button type="button" data-category="<?=e($cat)?>"><?=e($cat)?></button><?php endforeach?></div>
      <div class="service-list" id="service-list"><?php foreach($services as $s):?><button type="button" class="service-card" data-id="<?=$s['id']?>" data-name="<?=e($s['name'])?>" data-price="<?=$s['price_cents']?>" data-duration="<?=e($s['duration'])?>" data-room="<?=e($s['room'])?>" data-category="<?=e($s['category'])?>"><span><b><?=e($s['name'])?></b><small><?=e($s['category'])?> · <?=e($s['duration']?:'время не указано')?><?=is_numeric($s['duration'])?' мин.':''?></small></span><strong><?=money((int)$s['price_cents'])?></strong><i>+</i></button><?php endforeach?></div></div>
    </section><aside class="panel cart"><div class="panel-head"><div><h2>Назначено</h2><p id="cart-count">0 позиций</p></div></div><div id="cart-items" class="cart-items"><div class="empty compact">Выберите процедуры слева</div></div><div class="cart-total"><span>Итого</span><strong id="cart-total">0,00 ₽</strong></div><button class="btn primary wide" id="save-order" disabled>Сохранить назначение</button><small class="safe-note">Цены фиксируются на момент назначения</small></aside></div></form>
    <script>window.servicesReady=true;</script><?php layout_end();exit;
}

if ($page==='orders') {
    $status=$_GET['status']??'';$where='o.archived_at IS NULL';$args=[];
    if(in_array($status,['unpaid','paid','completed','cancelled'],true)){$where.=' AND o.status=?';$args[]=$status;}
    $q=$db->prepare("SELECT o.*,c.last_name,c.first_name FROM orders o JOIN clients c ON c.id=o.client_id WHERE $where ORDER BY o.created_at DESC LIMIT 150");$q->execute($args);$orders=$q->fetchAll();
    layout_start('Назначения','orders');?>
    <div class="filter-tabs"><a class="<?=$status===''?'active':''?>" href="?page=orders">Все</a><?php foreach(['unpaid','paid','completed','cancelled'] as $s):?><a class="<?=$status===$s?'active':''?>" href="?page=orders&status=<?=$s?>"><?=status_label($s)?></a><?php endforeach?></div>
    <section class="panel"><?php order_table($orders);?></section><?php layout_end();exit;
}

if ($page==='order') {
    $id=(int)($_GET['id']??0);$q=$db->prepare('SELECT o.*,c.last_name,c.first_name,c.middle_name,c.phone,c.birth_date,u.name creator FROM orders o JOIN clients c ON c.id=o.client_id JOIN users u ON u.id=o.created_by WHERE o.id=?');$q->execute([$id]);$o=$q->fetch();if(!$o){http_response_code(404);exit('Назначение не найдено');}
    $q=$db->prepare('SELECT * FROM order_items WHERE order_id=?');$q->execute([$id]);$items=$q->fetchAll(); layout_start('Назначение '.$o['number'],'orders');?>
    <div class="order-head"><div><span class="status <?=$o['status']?>"><?=status_label($o['status'])?></span><h2><?=e(client_name($o))?></h2><p>Оформил: <?=e($o['creator'])?> · <?=e(date('d.m.Y H:i',strtotime($o['created_at'])))?></p></div><div class="order-actions"><a class="btn" target="_blank" href="?page=print&id=<?=$id?>">🖨 Печатная форма</a></div></div>
    <div class="two-cols order-detail"><section class="panel"><div class="panel-head"><div><h2>Процедуры</h2><p>Начало: <?=e(date('d.m.Y H:i',strtotime($o['visit_at'])))?></p></div></div><div class="table-wrap"><table><thead><tr><th>Услуга</th><th>Кол-во</th><th>Кабинет / время</th><th>Сумма</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e($it['service_name'])?><small><?=e($it['duration']?($it['duration'].' мин.'):'')?></small></td><td><?=$it['quantity']?></td><td><?=e($it['room']?:'Не указан')?><small><?=e($it['scheduled_at']?date('d.m.Y H:i',strtotime($it['scheduled_at'])):'')?></small></td><td><b><?=money((int)$it['line_total_cents'])?></b></td></tr><?php endforeach?></tbody><tfoot><tr><td colspan="3">Итого</td><td><?=money((int)$o['total_cents'])?></td></tr></tfoot></table></div></section>
    <aside class="panel payment"><h2>Оплата и выполнение</h2><div class="big-total"><?=money((int)$o['total_cents'])?></div><p class="muted">Текущий статус: <?=status_label($o['status'])?></p>
    <form method="post" class="status-actions"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?=$id?>">
    <?php if($o['status']==='unpaid'&&!$o['archived_at']):?><button class="btn success" name="status" value="paid">Отметить оплату</button><?php endif?></form>
    <?php if($o['paid_at']):?><div class="receipt-note">Оплата зафиксирована<br><b><?=e(date('d.m.Y H:i',strtotime($o['paid_at'])))?></b></div><?php endif?>
    <?php if(is_manager()&&!$o['archived_at']):?><button class="btn danger-outline archive-open" data-type="order" data-id="<?=$id?>">Перенести в архив</button><?php endif?></aside></div>
    <?php
    $q=$db->prepare('SELECT e.*,i.service_name,u.name employee FROM procedure_events e JOIN order_items i ON i.id=e.item_id JOIN users u ON u.id=e.user_id WHERE i.order_id=? ORDER BY e.created_at DESC');
    $q->execute([$id]);$events=$q->fetchAll();
    ?>
    <section class="panel mt-3"><div class="panel-head"><div><h2>Учет оказанных сеансов</h2><p>Фактическое время проведения, сотрудник и время записи сохраняются отдельно.</p><a href="?page=schedule">Запланировать или отметить сеанс в расписании →</a></div></div>
    <?php if($o['status']==='paid'&&!$o['archived_at']):?>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="event_token" value="<?=bin2hex(random_bytes(24))?>"><input type="hidden" name="action" value="perform">
    <label class="full">Процедура<select name="item_id" required>
    <?php foreach($items as $it): $done=count(array_filter($events,static fn($event)=>(int)$event['item_id']===(int)$it['id']));if($done<$it['quantity']):?>
    <option value="<?=$it['id']?>"><?=e($it['service_name'])?> — выполнено <?=$done?> из <?=$it['quantity']?></option><?php endif;endforeach?>
    </select></label><label>Проведено<input type="datetime-local" name="performed_at" required value="<?=date('Y-m-d\TH:i')?>"></label><label>Примечание<input name="notes"></label><button class="btn primary">Записать один сеанс</button></form><?php endif?>
    <div class="table-wrap"><table><thead><tr><th>Процедура</th><th>Проведено</th><th>Записал / время записи</th><th>Примечание</th></tr></thead><tbody>
    <?php foreach($events as $event):?><tr><td><?=e($event['service_name'])?></td><td><?=e($event['performed_at'])?></td><td><?=e($event['employee'])?><small><?=e($event['created_at'])?></small></td><td><?=e($event['notes'])?></td></tr><?php endforeach?>
    <?php if(!$events):?><tr><td colspan="4" class="empty">Оказанные сеансы еще не зарегистрированы.</td></tr><?php endif?></tbody></table></div></section>
    <?php archive_dialog();layout_end();exit;
}

if ($page==='services') {
    $services=$db->query('SELECT * FROM services WHERE is_active=1 ORDER BY category,CAST(price_number AS REAL),price_number')->fetchAll();layout_start('Прайс и кабинеты','services');?>
    <div class="info-strip"><b><?=count($services)?> позиций из прейскуранта</b><span>Цены и длительность импортированы из документа с 01.06.2025. Назначьте кабинет перед началом работы.</span></div>
    <section class="panel"><div class="table-wrap"><table class="service-table"><thead><tr><th>№</th><th>Услуга</th><th>Категория</th><th>Время</th><th>Цена</th><th>Кабинет</th><th></th></tr></thead><tbody>
    <?php foreach($services as $s):$fid='service-'.$s['id'];?><tr>
    <td><?=e($s['price_number'])?></td>
    <td><input form="<?=$fid?>" aria-label="Наименование услуги" name="name" value="<?=e($s['name'])?>" required></td>
    <td><input form="<?=$fid?>" aria-label="Категория" name="category" value="<?=e($s['category'])?>"></td>
    <td><input form="<?=$fid?>" aria-label="Длительность" class="small-input" name="duration" value="<?=e($s['duration'])?>"></td>
    <td><input form="<?=$fid?>" aria-label="Цена в рублях" class="price-input" name="price" type="number" min="0" max="1000000" step="0.01" required value="<?=number_format($s['price_cents']/100,2,'.','')?>"></td>
    <td><input form="<?=$fid?>" aria-label="Кабинет" class="room-input" name="room" value="<?=e($s['room'])?>" placeholder="№"></td>
    <td><form id="<?=$fid?>" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_service"><input type="hidden" name="id" value="<?=$s['id']?>"><button class="btn small">Сохранить</button></form></td>
    </tr><?php endforeach?></tbody></table></div></section><?php layout_end();exit;
}

if ($page==='archive' && is_manager()) {
    layout_start('Архив','audit');
    $archived=$db->query("SELECT id,last_name||' '||first_name label,archived_at,archive_reason,'client' type FROM clients WHERE archived_at IS NOT NULL UNION ALL SELECT id,number label,archived_at,archive_reason,'order' type FROM orders WHERE archived_at IS NOT NULL ORDER BY archived_at DESC")->fetchAll();?>
    <section class="panel"><div class="panel-head"><div><h2>Архивные записи</h2><p>Физически данные не удаляются. Руководитель может восстановить запись.</p></div></div><div class="table-wrap"><table><thead><tr><th>Запись</th><th>Архивировано</th><th>Причина</th><th></th></tr></thead><tbody>
    <?php foreach($archived as $a):?><tr><td><a href="?page=<?=$a['type']?>&id=<?=$a['id']?>"><?=e($a['label'])?></a></td><td><?=e($a['archived_at'])?></td><td><?=e($a['archive_reason'])?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="type" value="<?=$a['type']?>"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn small">Восстановить</button></form></td></tr><?php endforeach?>
    <?php if(!$archived):?><tr><td colspan="4" class="empty">Архив пуст.</td></tr><?php endif?></tbody></table></div></section>
    <?php layout_end();exit;
}
if ($page==='audit' && is_manager()) {
    $logs=$db->query('SELECT a.*,u.name user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 300')->fetchAll();layout_start('Журнал действий','audit');?>
    <section class="panel"><div class="panel-head"><div><h2>Неизменяемый журнал</h2><p>Создание, изменение, оплата и архивирование записей</p></div></div><div class="table-wrap"><table><thead><tr><th>Время</th><th>Сотрудник</th><th>Действие</th><th>Объект</th><th>Подробности</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td><?=e(date('d.m.Y H:i:s',strtotime($l['created_at'])))?></td><td><?=e($l['user_name']?:'Система')?></td><td><?=e($l['action'])?></td><td><?=e($l['entity_type'].' #'.$l['entity_id'])?></td><td><?=e($l['details'])?></td></tr><?php endforeach?></tbody></table></div></section><?php layout_end();exit;
}
http_response_code(404); layout_start('Страница не найдена'); echo '<section class="panel empty">Такой страницы нет.</section>';layout_end();

function order_table(array $orders): void { ?>
<div class="table-wrap"><table><thead><tr><th>Номер / время</th><th>Клиент</th><th>Статус</th><th>Сумма</th><th></th></tr></thead><tbody>
<?php foreach($orders as $o):?><tr><td><b><?=e($o['number'])?></b><small><?=e(date('d.m.Y H:i',strtotime($o['created_at'])))?></small></td><td><?=e(trim(($o['last_name']??'').' '.($o['first_name']??'')))?></td><td><span class="status <?=e($o['status'])?>"><?=status_label($o['status'])?></span></td><td><b><?=money((int)$o['total_cents'])?></b></td><td><a class="row-link" href="?page=order&id=<?=$o['id']?>">Открыть</a></td></tr><?php endforeach?>
<?php if(!$orders):?><tr><td colspan="5" class="empty">Назначений пока нет.</td></tr><?php endif?></tbody></table></div><?php }
function archive_dialog(): void { if(!is_manager())return;?>
<dialog id="archive-dialog"><form method="post" class="dialog-card"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="type" id="archive-type"><input type="hidden" name="id" id="archive-id"><h2>Перенести запись в архив?</h2><p>Данные останутся в базе и журнале. Действие доступно только руководителю.</p><label>Причина *<textarea name="reason" required rows="3"></textarea></label><div class="form-actions"><button type="button" class="btn" data-close-dialog>Отмена</button><button class="btn danger">В архив</button></div></form></dialog><?php }


