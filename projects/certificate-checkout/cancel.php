<?php
session_start();
require_once __DIR__ . '/config.php';
unset($_SESSION['last_payment_id']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Оплата отменена — Demo Service</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background:#f3f7f4; }
  .result-card { background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,60,20,.09); }
  .btn-retry {
    background:linear-gradient(135deg,#1d5c36,#2e7d4f);
    color:#fff; border:none; border-radius:10px;
    padding:12px 28px; font-weight:600;
    text-decoration:none; display:inline-block;
  }
  .btn-retry:hover { opacity:.9; color:#fff; }
</style>
</head>
<body>
<div class="container py-5">
<div class="row justify-content-center">
<div class="col-12 col-lg-6">
  <div class="result-card p-4 text-center">
    <div style="font-size:3.5rem;">🚫</div>
    <h2 class="mt-3" style="color:#c0392b;">Оплата отменена</h2>
    <p class="text-muted">Вы закрыли страницу оплаты или отменили транзакцию. Средства не списаны.</p>
    <div class="mt-4">
      <a href="index.php" class="btn-retry me-3">
        <i class="bi bi-arrow-repeat me-1"></i>Попробовать снова
      </a>
      <a href="https://example.com" class="btn btn-outline-secondary">
        На главную
      </a>
    </div>
    <p class="mt-4 text-muted" style="font-size:.85rem;">
      Если возникли проблемы с оплатой — позвоните нам:<br>
      <strong>+1-555-0100</strong> &nbsp;|&nbsp; <strong>+7 (800) 500-28-40</strong>
    </p>
  </div>
</div>
</div>
</div>
</body>
</html>
