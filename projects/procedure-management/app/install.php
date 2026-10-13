<?php
// Однократная установка защищена ключом, доступным только через файловый менеджер хостинга.
$tokenPath=dirname(DB_PATH).'/install-token.txt';
if(!is_file($tokenPath))file_put_contents($tokenPath,bin2hex(random_bytes(24)),LOCK_EX);
$installError='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    try{
        if(!hash_equals(trim(file_get_contents($tokenPath)),trim((string)($_POST['token']??''))))throw new RuntimeException('Неверный ключ установки.');
        $managerPassword=(string)($_POST['manager_password']??'');
        $adminPassword=(string)($_POST['admin_password']??'');
        if(!password_valid($managerPassword) || !password_valid($adminPassword))throw new RuntimeException('Каждый пароль: от 12 символов, до 72 байт.');
        $db->exec('BEGIN IMMEDIATE');
        if((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()>0)throw new RuntimeException('Установка уже завершена.');
        $q=$db->prepare('INSERT INTO users(name,login,password_hash,role) VALUES(?,?,?,?)');
        $q->execute(['Руководитель','manager',password_hash($managerPassword,PASSWORD_DEFAULT),'manager']);
        $q->execute(['Администратор','admin',password_hash($adminPassword,PASSWORD_DEFAULT),'admin']);
        $db->exec('COMMIT');
        flash('success','Установка завершена. Войдите под созданной учетной записью.');
        redirect('?page=login');
    }catch(Throwable $ex){
        try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}
        $installError=$ex instanceof PDOException?'Не удалось завершить установку.':$ex->getMessage();
    }
}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка — Demo</title><link rel="stylesheet" href="assets/vendor/bootstrap.min.css"><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/refinements.css"></head><body class="login-page"><main class="login-card">
<img class="official-logo" src="assets/demo-mark.svg" alt="Demo workspace"><h1>Первый запуск</h1>
<p class="muted">Откройте файл storage/install-token.txt через файловый менеджер хостинга и введите ключ. Задайте личные пароли сотрудников.</p>
<?php if($installError):?><div class="alert danger"><?=e($installError)?></div><?php endif?>
<form method="post" class="form-grid one"><input type="hidden" name="csrf" value="<?=csrf()?>">
<label>Ключ установки<input name="token" required autocomplete="off"></label>
<label>Пароль руководителя (manager)<input type="password" name="manager_password" required minlength="12" autocomplete="new-password"></label>
<label>Пароль администратора (admin)<input type="password" name="admin_password" required minlength="12" autocomplete="new-password"></label>
<button class="btn primary">Создать учетные записи</button></form></main></body></html>


