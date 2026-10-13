<header class="admin-navbar">
  <a class="admin-brand" href="<?= h(url_for('/index.php')) ?>">
    <img src="<?= h(url_for('/assets/img/demo-logo.svg')) ?>" alt="Survey demo">
    <span><?= h(config('APP_NAME', 'Demo organization')) ?></span>
  </a>
  <nav>
    <a href="<?= h(url_for('/index.php')) ?>">Дашборд</a>
    <a href="<?= h(url_for('/surveys.php')) ?>">Анкеты / QR</a>
    <a href="<?= h(url_for('/help.php')) ?>">Справка</a>
    <a href="<?= h(url_for('/responses.php')) ?>">Ответы</a>
    <a href="<?= h(url_for('/import.php')) ?>">Импорт писем</a>
    <a href="<?= h(url_for('/logout.php')) ?>">Выход</a>
  </nav>
</header>

