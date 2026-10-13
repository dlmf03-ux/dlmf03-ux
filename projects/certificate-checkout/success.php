<?php
/**
 * success.php — страница возврата после оплаты (redirect от Payment Provider)
 * Статус платежа уточняем через API (webhook может прийти чуть позже)
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/YooKassa.php';

$paymentId = $_SESSION['last_payment_id'] ?? null;
$payment   = null;
$status    = 'pending';

if ($paymentId) {
    try {
        $ykData = YooKassa::getPayment($paymentId);
        $status = $ykData['status'] ?? 'pending';

        // При отмене — сразу редирект на cancel.php, не показываем success вообще
        if ($status === 'canceled') {
            unset($_SESSION['last_payment_id']);
            header('Location: cancel.php');
            exit;
        }

        // Если succeeded — обновляем БД
        if ($status === 'succeeded') {
            Database::updatePaymentStatus($paymentId, 'succeeded', [
                'raw_response' => json_encode($ykData, JSON_UNESCAPED_UNICODE),
            ]);
            $payment = Database::getPaymentWithVoucher($paymentId);
            unset($_SESSION['last_payment_id']); // сбрасываем только при финальном статусе
        }
        // При pending — НЕ сбрасываем сессию, чтобы после перезагрузки ID сохранился

    } catch (Exception $e) {
        error_log('[success] ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Результат оплаты — Demo Service</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root {
    --luk-green:     #1d5c36;
    --luk-green-mid: #2e7d4f;
    --luk-green-lt:  #edf7ef;
  }
  body { background:#f3f7f4; }
  .result-card {
    background:#fff;
    border-radius:16px;
    box-shadow:0 4px 24px rgba(0,60,20,.09);
    overflow:hidden;
  }
  .status-icon { font-size:4rem; }
  .detail-row { border-bottom:1px solid #f0f0f0; padding:9px 0; font-size:.93rem; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { color:#888; width:50%; }
  .detail-value { color:#222; font-weight:500; }
  .btn-home {
    background: linear-gradient(135deg, var(--luk-green), var(--luk-green-mid));
    color:#fff; border:none; border-radius:10px;
    padding:12px 28px; font-weight:600;
    text-decoration:none; display:inline-block;
    transition:opacity .18s;
  }
  .btn-home:hover { opacity:.9; color:#fff; }

  /* Анимация ожидания */
  .spinner-border-luk { color: var(--luk-green-mid); }

  /* Плашка pending */
  .pending-box {
    background:#fff8e1; border:1px solid #ffe082;
    border-radius:10px; padding:16px 20px;
  }
</style>
</head>
<body>

<div class="container py-4">
<div class="row justify-content-center">
<div class="col-12 col-lg-7 col-xl-6">

  <div class="result-card">

    <?php if ($status === 'succeeded'): ?>
    <!-- ✅ УСПЕХ -->
    <div class="p-4 text-center" style="border-bottom:4px solid #2e7d4f;">
      <div class="status-icon">✅</div>
      <h2 class="mt-3 mb-1" style="color:#1d5c36;font-weight:700;">Оплата прошла успешно!</h2>
      <p class="text-muted mb-0">Документы отправлены на ваш email</p>
    </div>
    <div class="p-4">
      <?php if ($payment): ?>
      <div class="mb-4">
        <div class="d-flex detail-row">
          <div class="detail-label">Участник</div>
          <div class="detail-value"><?= htmlspecialchars($payment['child_name']) ?></div>
        </div>
        <div class="d-flex detail-row">
          <div class="detail-label">Смена</div>
          <div class="detail-value"><?= htmlspecialchars($payment['voucher_date']) ?></div>
        </div>
        <div class="d-flex detail-row">
          <div class="detail-label">Сумма</div>
          <div class="detail-value" style="color:#1d5c36;font-weight:700;">
            <?= number_format((float)$payment['amount_total'], 0, '.', ' ') ?> ₽
          </div>
        </div>
        <?php if ($payment['amount_cert']): ?>
        <div class="d-flex detail-row">
          <div class="detail-label" style="font-size:.85rem;">— сертификат</div>
          <div class="detail-value" style="font-size:.85rem;"><?= number_format((float)$payment['amount_cert'], 0, '.', ' ') ?> ₽</div>
        </div>
        <?php endif; ?>
        <?php if ($payment['amount_card']): ?>
        <div class="d-flex detail-row">
          <div class="detail-label" style="font-size:.85rem;">— карта</div>
          <div class="detail-value" style="font-size:.85rem;"><?= number_format((float)$payment['amount_card'], 0, '.', ' ') ?> ₽</div>
        </div>
        <?php endif; ?>
        <div class="d-flex detail-row">
          <div class="detail-label">Номер платежа</div>
          <div class="detail-value" style="font-size:.82rem;word-break:break-all;"><?= htmlspecialchars($payment['yookassa_id']) ?></div>
        </div>
      </div>

      <div class="alert alert-warning" style="border-radius:10px;font-size:.88rem;">
        <i class="bi bi-envelope-check me-2"></i>
        На <strong><?= htmlspecialchars($payment['email']) ?></strong> отправлены:
        <ul class="mb-0 mt-1">
          <li>Договор об оказании услуг (для заполнения)</li>
          <li>Согласие на обработку персональных данных</li>
        </ul>
        <div class="mt-2 text-muted">Распечатайте оба документа и привезите при заезде.</div>
      </div>
      <?php endif; ?>

      <div class="text-center mt-3">
        <a href="https://example.com" class="btn-home">
          <i class="bi bi-house-fill me-2"></i>На главную сайта
        </a>
      </div>
    </div>

    <?php elseif ($status === 'canceled'): ?>
    <!-- ❌ ОТМЕНА -->
    <div class="p-4 text-center" style="border-bottom:4px solid #dc3545;">
      <div class="status-icon">❌</div>
      <h2 class="mt-3 mb-1" style="color:#c0392b;font-weight:700;">Оплата отменена</h2>
      <p class="text-muted mb-0">Средства не списаны</p>
    </div>
    <div class="p-4 text-center">
      <p>Вы можете попробовать снова или обратиться к нам по телефону.</p>
      <a href="index.php" class="btn-home me-2"><i class="bi bi-arrow-repeat me-1"></i>Попробовать снова</a>
    </div>

    <?php else: ?>
    <!-- ⏳ ОЖИДАНИЕ (pending) -->
    <div class="p-4 text-center" style="border-bottom:4px solid #ffc107;">
      <div class="status-icon" id="statusIcon">⏳</div>
      <h2 class="mt-3 mb-1" style="color:#856404;font-weight:700;">Обрабатываем платёж...</h2>
      <p class="text-muted mb-0" id="statusText">Ожидаем подтверждение от банка</p>
    </div>
    <div class="p-4">
      <div class="pending-box text-center mb-4">
        <div class="spinner-border spinner-border-luk mb-3" role="status"></div>
        <p class="mb-0" style="font-size:.9rem;">Это займёт несколько секунд. Не закрывайте страницу.</p>
      </div>
      <div class="text-center">
        <p class="text-muted" style="font-size:.85rem;">
          Если страница не обновилась через 30 секунд — проверьте email или позвоните нам:<br>
          <strong>+1-555-0100</strong>
        </p>
      </div>
    </div>

    <script>
    // Автоматически проверяем статус каждые 3 секунды
    <?php if ($paymentId): ?>
    let attempts = 0;
    const maxAttempts = 10;
    const interval = setInterval(async () => {
      attempts++;
      try {
        const res  = await fetch('status.php?id=<?= urlencode($paymentId) ?>');
        const data = await res.json();
        if (data.status === 'succeeded') {
          clearInterval(interval);
          document.getElementById('statusIcon').textContent = '✅';
          document.getElementById('statusText').textContent = 'Оплата успешно прошла!';
          setTimeout(() => location.reload(), 800);
        } else if (data.status === 'canceled') {
          clearInterval(interval);
          window.location.href = 'cancel.php'; // редирект, не перезагрузка
        }
      } catch(e) {}
      if (attempts >= maxAttempts) {
        clearInterval(interval);
        // Показываем сообщение вместо вечного спиннера
        document.getElementById('statusIcon').textContent = '❓';
        document.getElementById('statusText').innerHTML =
          'Не удалось получить статус.<br>Проверьте email или позвоните нам: <strong>+1-555-0100</strong>';
        document.querySelector('.spinner-border')?.remove();
      }
    }, 3000);
    <?php endif; ?>
    </script>
    <?php endif; ?>

  </div>

  <div class="text-center mt-3" style="color:#aaa;font-size:.8rem;">
    Demo Service | +1-555-0100 | contact@example.com
  </div>

</div>
</div>
</div>
</body>
</html>
