<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (attempt_login((string)($_POST['password'] ?? ''))) {
        redirect('/index.php');
    }
    $error = 'Неверный пароль';
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Вход | <?= h(config('APP_NAME', 'Система анкетирования')) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(url_for('/assets/css/app.css')) ?>">
</head>
<body class="login-page">
  <main class="login-box">
    <img src="<?= h(url_for('/assets/img/demo-logo.svg')) ?>" alt="Survey demo" class="login-logo">
    <h1>Аналитика анкет</h1>
    <p>Введите пароль администратора.</p>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <label class="form-label">
        Пароль
        <input class="form-control" type="password" name="password" autocomplete="current-password" required autofocus>
      </label>
      <button class="btn btn-brand w-100" type="submit">Войти</button>
    </form>
  </main>
</body>
</html>

