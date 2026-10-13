<?php
if($page==='reports'){
    require_manager();
    $from=(string)($_GET['from']??date('Y-m-01'));$to=(string)($_GET['to']??date('Y-m-d'));
    try{$start=valid_datetime($from.' 00:00:00');$end=valid_datetime($to.' 23:59:59');if($start>$end)throw new RuntimeException();}
    catch(Throwable $ex){$from=date('Y-m-01');$to=date('Y-m-d');$start=$from.' 00:00:00';$end=$to.' 23:59:59';}
    $q=$db->prepare("SELECT o.id,o.number,o.total_cents,o.paid_at,o.archived_at,u.name employee FROM orders o LEFT JOIN users u ON u.id=o.paid_by WHERE o.status IN ('paid','completed') AND o.paid_at BETWEEN ? AND ? ORDER BY o.paid_at DESC");
    $q->execute([$start,$end]);$rows=$q->fetchAll();$total=array_sum(array_column($rows,'total_cents'));
    layout_start('Отчет об оплатах');?>
    <section class="toolbar"><form><input type="hidden" name="page" value="reports"><label>С<input type="date" name="from" value="<?=e($from)?>" required></label><label>По<input type="date" name="to" value="<?=e($to)?>" required></label><button class="btn">Сформировать</button></form></section>
    <section class="stats"><article><span>Оплачено за период</span><strong><?=money((int)$total)?></strong><small>Включая архивные назначения</small></article><article><span>Оплат</span><strong><?=count($rows)?></strong><small>Полная оплата назначения</small></article></section>
    <p class="muted">Внутренний учет. Архивирование не отменяет поступление и не является возвратом.</p>
    <section class="panel"><div class="table-wrap"><table><thead><tr><th>Дата оплаты</th><th>Назначение</th><th>Сотрудник</th><th>Сумма</th></tr></thead><tbody>
    <?php foreach($rows as $row):?><tr><td><?=e($row['paid_at'])?></td><td><a href="?page=order&id=<?=$row['id']?>"><?=e($row['number'])?></a><?=$row['archived_at']?' · в архиве':''?></td><td><?=e($row['employee']??'Не указан')?></td><td><?=money((int)$row['total_cents'])?></td></tr><?php endforeach?>
    <?php if(!$rows):?><tr><td colspan="4" class="empty">За период оплат не было.</td></tr><?php endif?></tbody></table></div></section>
    <?php layout_end();return;
}
if($page==='password'){
    layout_start('Мой пароль');?>
    <section class="panel"><div class="panel-head"><div><h2>Смена пароля</h2><p>После смены остальные сеансы входа будут завершены. Минимум 12 символов, максимум 72 байта.</p></div></div>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="password_change">
    <label class="full">Текущий / временный пароль<input type="password" name="current_password" autocomplete="current-password" required></label>
    <label>Новый пароль<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
    <label>Повторите новый пароль<input type="password" name="password_confirm" autocomplete="new-password" minlength="12" required></label>
    <button class="btn primary">Изменить пароль</button></form></section>
    <?php layout_end();return;
}
if($page==='users'){
    require_manager();layout_start('Сотрудники');
    $users=$db->query('SELECT id,name,login,role,is_active FROM users ORDER BY name')->fetchAll();?>
    <section class="panel mb-3"><div class="panel-head"><div><h2>Личный доступ каждому сотруднику</h2><p>Не используйте общий логин: в истории должны быть имена реальных исполнителей.</p></div></div>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="user_create">
    <label>ФИО<input name="name" required maxlength="160"></label><label>Логин<input name="login" pattern="[a-zA-Z0-9_.-]{3,64}" required></label>
    <label>Роль<select name="role"><option value="admin">Администратор</option><option value="manager">Руководитель</option></select></label>
    <label>Временный пароль<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><button class="btn primary">Создать сотрудника</button></form></section>
    <section class="panel"><div class="table-wrap"><table><thead><tr><th>Сотрудник</th><th>Роль</th><th>Доступ</th><th>Действия</th></tr></thead><tbody>
    <?php foreach($users as $u):?><tr><td><?=e($u['name'])?><small><?=e($u['login'])?></small></td><td><?=$u['role']==='manager'?'Руководитель':'Администратор'?></td><td><?=$u['is_active']?'Активен':'Отключен'?></td><td>
    <?php if($u['id']!==current_user()['id']):?>
    <form method="post" class="mb-2"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="user_id" value="<?=$u['id']?>"><input type="hidden" name="action" value="user_toggle"><button class="btn small"><?=$u['is_active']?'Отключить доступ':'Включить доступ'?></button></form>
    <details><summary>Сбросить пароль</summary><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="user_id" value="<?=$u['id']?>"><input type="hidden" name="action" value="user_reset"><label>Новый временный пароль<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><button class="btn small mt-2">Сбросить</button></form></details>
    <?php else:?><a href="?page=password">Мой пароль</a><?php endif?></td></tr><?php endforeach?></tbody></table></div></section>
    <?php layout_end();return;
}
if($page==='schedule'){
    $date=(string)($_GET['date']??date('Y-m-d'));
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || date('Y-m-d',strtotime($date))!==$date)$date=date('Y-m-d');
    $q=$db->prepare("SELECT a.*,i.service_name,i.order_id,o.number,o.status order_status,c.last_name,c.first_name,c.middle_name FROM appointments a JOIN order_items i ON i.id=a.item_id JOIN orders o ON o.id=i.order_id JOIN clients c ON c.id=o.client_id WHERE a.starts_at<? AND a.ends_at>? ORDER BY a.starts_at,a.room");
    $q->execute([date('Y-m-d',strtotime($date.' +1 day')).' 00:00:00',$date.' 00:00:00']);$appointments=$q->fetchAll();
    $items=$db->query("SELECT i.*,o.number,c.last_name,c.first_name FROM order_items i JOIN orders o ON o.id=i.order_id JOIN clients c ON c.id=o.client_id WHERE o.archived_at IS NULL AND c.archived_at IS NULL AND o.status IN ('unpaid','paid') AND i.quantity>(SELECT COUNT(*) FROM procedure_events e WHERE e.item_id=i.id)+(SELECT COUNT(*) FROM appointments a WHERE a.item_id=i.id AND a.status='scheduled') ORDER BY o.created_at DESC")->fetchAll();
    layout_start('Расписание сеансов');?>
    <section class="toolbar"><form><input type="hidden" name="page" value="schedule"><label>Дата<input type="date" name="date" value="<?=e($date)?>" required></label><button class="btn">Показать</button></form></section>
    <section class="panel mb-3"><div class="panel-head"><div><h2>Запись на один сеанс</h2><p>Проверяются пересечения кабинета/места и клиента. Для группового кабинета укажите место: «101 / 1».</p></div></div>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="appointment_add">
    <label class="full">Назначенная процедура<select name="item_id" required><option value="">Выберите назначение</option><?php foreach($items as $i):?><option value="<?=$i['id']?>"><?=e($i['number'].' · '.$i['last_name'].' '.$i['first_name'].' · '.$i['service_name'])?></option><?php endforeach?></select></label>
    <label>Начало<input type="datetime-local" name="starts_at" value="<?=e($date)?>T09:00" required></label><label>Длительность, минут<input type="number" name="minutes" min="1" max="480" required></label><label>Кабинет / место<input name="room" maxlength="80" required></label>
    <div class="form-actions"><button class="btn primary">Записать</button></div></form></section>
    <section class="panel"><div class="table-wrap"><table><thead><tr><th>Время / кабинет</th><th>Клиент и процедура</th><th>Состояние</th><th>Действия</th></tr></thead><tbody>
    <?php foreach($appointments as $a):?><tr><td><?=e(substr($a['starts_at'],11,5).' — '.substr($a['ends_at'],11,5))?><small><?=e($a['room'])?></small></td><td><?=e(client_name($a))?><small><?=e($a['service_name'])?></small><a href="?page=order&id=<?=$a['order_id']?>"><?=e($a['number'])?></a></td><td><?=['scheduled'=>'Запланировано','done'=>'Оказано','cancelled'=>'Отменено'][$a['status']]?><small><?=e($a['reason'])?></small><small><?=status_label($a['order_status'])?></small></td><td>
    <?php if($a['status']==='scheduled'):?>
    <?php if($a['order_status']==='paid'):?><details><summary>Отметить оказание</summary><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="appointment_done"><input type="hidden" name="appointment_id" value="<?=$a['id']?>"><label>Фактическое начало<input type="datetime-local" name="performed_at" value="<?=date('Y-m-d\TH:i')?>" required></label><label>Примечание<input name="notes"></label><button class="btn small mt-2">Сеанс оказан</button></form></details><?php endif?>
    <?php if(is_manager()):?><details class="mt-2"><summary>Отменить запись</summary><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="appointment_cancel"><input type="hidden" name="appointment_id" value="<?=$a['id']?>"><label>Причина<input name="reason" required></label><button class="btn danger-outline small mt-2">Отменить</button></form></details><?php endif?>
    <?php endif?></td></tr><?php endforeach?>
    <?php if(!$appointments):?><tr><td colspan="4" class="empty">На выбранный день записей нет.</td></tr><?php endif?></tbody></table></div></section>
    <?php layout_end();return;
}
