<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_auth();

$forms = all_forms();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$host = $_SERVER['HTTP_HOST'] ?? 'example.com';
$baseUrl = $scheme . '://' . $host . base_path();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ссылки на анкеты | Demo organization</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(url_for('/assets/css/app.css')) ?>">
</head>
<body>
  <?php include APP_ROOT . '/app/admin_header.php'; ?>

  <main class="admin-layout">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
      <div>
        <h1 class="admin-title">Ссылки и QR-коды анкет</h1>
        <p class="text-muted mb-0">Эти ссылки можно разместить на QR-кодах в санатории.</p>
      </div>
      <button class="btn btn-outline-brand" onclick="window.print()">Печать QR</button>
    </div>

    <div class="row g-4">
      <?php foreach ($forms as $form): ?>
        <?php $url = $baseUrl . '/s/' . $form['slug']; ?>
        <div class="col-md-6 col-xl-4">
          <article class="card survey-link-card h-100">
            <div class="card-body">
              <h2><?= h($form['department']) ?></h2>
              <p><?= h($form['title']) ?></p>
              <a href="<?= h($url) ?>" target="_blank"><?= h($url) ?></a>
              <div class="qr-box mt-3" data-url="<?= h($url) ?>"></div>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
  <script src="<?= h(url_for('/assets/js/qr.js')) ?>"></script>
</body>
</html>

