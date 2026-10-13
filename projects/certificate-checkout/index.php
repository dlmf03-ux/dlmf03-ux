<?php
// session_start() — ОБЯЗАТЕЛЬНО первым, до любого вывода
session_start();
require_once __DIR__ . '/config.php';

$errors = [];

// Ошибка из create.php (если платёж не создался)
$sessionError = null;
if (!empty($_SESSION['payment_error'])) {
    $sessionError = $_SESSION['payment_error'];
    unset($_SESSION['payment_error']);
}

// POST — валидация формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['child_name','birth_date','parent_name','phone','email','payment_type','voucher_date','amount_type','custom_amount'];
    $data   = [];
    foreach ($fields as $f) {
        $data[$f] = trim($_POST[$f] ?? '');
    }

    if (empty($data['child_name']))    $errors[] = 'Укажите Имя участника';
    if (empty($data['birth_date']))    $errors[] = 'Укажите дату рождения участника';
    if (empty($data['parent_name']))   $errors[] = 'Укажите Имя контактного лица';
    if (empty($data['phone']))         $errors[] = 'Укажите номер телефона';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Укажите корректный email';
    if (empty($data['voucher_date']))  $errors[] = 'Выберите смену / период заезда';
    if (!in_array($data['payment_type'], ['certificate','mixed','card']))
        $errors[] = 'Выберите способ оплаты';

    // Определяем сумму
    if ($data['amount_type'] === 'custom') {
        $rawAmt = str_replace([' ', ','], ['', '.'], $data['custom_amount']);
        if (!is_numeric($rawAmt) || (float)$rawAmt < 1) {
            $errors[] = 'Укажите корректную сумму (минимум 1 ₽)';
        } elseif ((float)$rawAmt > 5000) {
            $errors[] = 'Максимальная сумма оплаты — 5 000 ₽ (лимит Payment Provider)';
        } else {
            $data['amount'] = number_format((float)$rawAmt, 2, '.', '');
        }
    } elseif ($data['amount_type'] === 'certificate_50') {
        $data['amount'] = '500.00';
    } elseif ($data['amount_type'] === 'certificate_100') {
        $data['amount'] = '1000.00';
    } elseif ($data['amount_type'] === 'fixed') {
        $data['amount'] = VOUCHER_AMOUNT;
    } else {
        $errors[] = 'Выберите сумму оплаты';
    }

    if (in_array($data['amount_type'], ['certificate_50', 'certificate_100'], true)
        && $data['payment_type'] !== 'certificate') {
        $errors[] = 'Для выбранной суммы доступна только оплата электронным сертификатом';
    }

    if (empty($errors)) {
        $_SESSION['payment_data'] = $data;
        header('Location: create.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demo checkout — Demo Service</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root {
    --luk-green:      #1d5c36;
    --luk-green-mid:  #2e7d4f;
    --luk-green-lt:   #edf7ef;
    --luk-green-bdr:  #a8d5b8;
    --luk-gold:       #c9a22e;
    --luk-text:       #1a2e22;
  }

  body { background:#f3f7f4; color:var(--luk-text); }

  /* Карточка */
  .pay-card {
    background:#fff;
    border-radius:16px;
    box-shadow:0 4px 24px rgba(0,60,20,.09);
    overflow:hidden;
  }

  /* Плашка стоимости */
  .price-badge {
    background: linear-gradient(135deg, var(--luk-green-lt), #d4edda);
    border: 1px solid var(--luk-green-bdr);
    border-radius:12px;
    padding:18px 24px;
  }
  .price-badge .price-main { font-size:2.2rem; font-weight:800; color:var(--luk-green); line-height:1; }
  .price-badge .price-sub  { color:#5a8a6a; font-size:0.88rem; margin-top:4px; }

  /* Выбор способа оплаты */
  .pay-option { display:none; }
  .pay-option + label {
    display:block;
    border: 2px solid #dee2e6;
    border-radius:12px;
    padding:14px 18px;
    cursor:pointer;
    transition: all .18s ease;
    background:#fff;
  }
  .pay-option:checked + label {
    border-color: var(--luk-green-mid);
    background: var(--luk-green-lt);
    box-shadow: 0 0 0 3px rgba(46,125,79,.15);
  }
  .pay-option + label .opt-icon { font-size:1.5rem; }
  .pay-option + label .opt-title { font-weight:600; color:var(--luk-text); font-size:1rem; }
  .pay-option + label .opt-desc  { color:#6c757d; font-size:0.83rem; margin-top:2px; }
  .badge-recommended {
    background: var(--luk-gold);
    color:#fff;
    font-size:0.7rem;
    padding:2px 8px;
    border-radius:20px;
    vertical-align:middle;
    margin-left:6px;
  }

  /* Кнопка */
  .btn-pay {
    background: linear-gradient(135deg, var(--luk-green), var(--luk-green-mid));
    color:#fff;
    border:none;
    border-radius:10px;
    padding:14px 32px;
    font-size:1.05rem;
    font-weight:600;
    letter-spacing:.3px;
    width:100%;
    transition: opacity .18s;
  }
  .btn-pay:hover { opacity:.9; color:#fff; }
  .btn-pay:disabled { opacity:.6; }

  /* Форма */
  .form-label { font-weight:500; color:#2d4a35; font-size:.92rem; }
  .form-control:focus, .form-select:focus {
    border-color: var(--luk-green-mid);
    box-shadow: 0 0 0 3px rgba(46,125,79,.15);
  }
  .section-title {
    font-size:1rem;
    font-weight:700;
    color: var(--luk-green);
    border-left: 4px solid var(--luk-green-mid);
    padding-left:10px;
    margin-bottom:16px;
  }

  /* Безопасность */
  .security-bar {
    background:#f8f9fa;
    border-top:1px solid #e9ecef;
    font-size:.8rem;
    color:#6c757d;
    padding:10px 20px;
  }

  /* Ошибки */
  .alert-error { border-radius:10px; }

  /* Подвал */
  .pay-footer { color:#aaa; font-size:.78rem; text-align:center; padding:12px 0 20px; }
</style>
</head>
<body>

<div class="container py-4">
<div class="row justify-content-center">
<div class="col-12 col-lg-8 col-xl-7">

  <!-- Ошибка из create.php -->
  <?php if ($sessionError): ?>
  <div class="alert alert-danger alert-error mb-4">
    <i class="bi bi-x-circle-fill me-2"></i>
    <strong>Ошибка создания платежа:</strong> <?= htmlspecialchars($sessionError) ?>
  </div>
  <?php endif; ?>

  <!-- Ошибки валидации -->
  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger alert-error mb-4">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Исправьте ошибки:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="POST" action="" id="payForm" novalidate>

    <!-- Стоимость -->
    <div class="pay-card mb-4">
      <div class="p-4">

        <!-- Блок выбора суммы -->
        <div class="d-flex align-items-center gap-4 flex-nowrap">

          <!-- Зелёный блок: выбор суммы + безопасность -->
          <div class="price-badge flex-grow-1">

            <!-- Переключатель суммы -->
            <div class="d-flex flex-column gap-2 mb-3">

              <div style="font-size:.92rem;font-weight:600;color:var(--luk-green);">
                Demo service reservation
              </div>

              <!-- Фиксированная -->
              <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                <input type="radio" name="amount_type" value="fixed" id="amt_fixed"
                  <?= (($_POST['amount_type'] ?? 'fixed') === 'fixed') ? 'checked' : '' ?>>
                <span style="font-size:.92rem;">Стандартная стоимость (<?= VOUCHER_DAYS ?> дней × <?= number_format((float)VOUCHER_DAY_PRICE, 0, '.', ' ') ?> ₽/день):
                  <strong style="color:var(--luk-green);"><?= number_format((float)VOUCHER_AMOUNT, 0, '.', ' ') ?> ₽</strong>
                </span>
              </label>

              <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                <input type="radio" name="amount_type" value="certificate_50" id="amt_certificate_50"
                  <?= (($_POST['amount_type'] ?? '') === 'certificate_50') ? 'checked' : '' ?>>
                <span style="font-size:.92rem;">500 ₽ (Сертификат 50%)</span>
              </label>

              <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                <input type="radio" name="amount_type" value="certificate_100" id="amt_certificate_100"
                  <?= (($_POST['amount_type'] ?? '') === 'certificate_100') ? 'checked' : '' ?>>
                <span style="font-size:.92rem;">1 000 ₽ (Сертификат 100%)</span>
              </label>

              <!-- Своя сумма -->
              <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                <input type="radio" name="amount_type" value="custom" id="amt_custom"
                  <?= (($_POST['amount_type'] ?? '') === 'custom') ? 'checked' : '' ?>>
                <span style="font-size:.92rem;">Другая сумма</span>
              </label>

              <!-- Поле ввода суммы (показывается при "Другая сумма") -->
              <div id="customAmountWrap" style="display:none; padding-left:1.5rem;">
                <div class="input-group" style="max-width:220px;">
                  <input type="number" class="form-control" id="custom_amount" name="custom_amount"
                    min="1" max="5000" step="1" placeholder="Сумма"
                    value="<?= htmlspecialchars($_POST['custom_amount'] ?? '') ?>">
                  <span class="input-group-text">₽</span>
                </div>
                <div style="font-size:.78rem;color:#888;margin-top:4px;">от 1 до 5 000 ₽</div>
              </div>

            </div>

            <!-- Описание + значок безопасности -->
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
              <div class="flex-shrink-0">
                <div class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2" style="font-size:.82rem;">
                  <i class="bi bi-shield-check me-1"></i>Безопасная оплата
                </div>
              </div>
            </div>

          </div>

        </div>
      </div>
    </div>

    <!-- Способ оплаты -->
    <div class="pay-card mb-4">
      <div class="p-4">
        <div class="section-title"><i class="bi bi-credit-card me-2"></i>Способ оплаты</div>
        <div class="d-flex flex-column gap-3">

          <!-- Сертификат -->
          <div id="paymentCertificate">
            <input type="radio" name="payment_type" value="certificate" id="pt_cert" class="pay-option"
              <?= (($_POST['payment_type'] ?? '') === 'certificate') ? 'checked' : '' ?>>
            <label for="pt_cert">
              <div class="d-flex align-items-start gap-3">
                <div class="opt-icon">🎫</div>
                <div>
                  <div class="opt-title">Только электронный сертификат</div>
                  <div class="opt-desc">Полная оплата средствами сертификата (карта «Мир» с привязанным сертификатом). Доплата не требуется, если баланс достаточен.</div>
                </div>
              </div>
            </label>
          </div>

          <!-- Сертификат + карта -->
          <div id="paymentMixed">
            <input type="radio" name="payment_type" value="mixed" id="pt_mixed" class="pay-option"
              <?= (($_POST['payment_type'] ?? '') === 'mixed') ? 'checked' : '' ?>>
            <label for="pt_mixed">
              <div class="d-flex align-items-start gap-3">
                <div class="opt-icon">🎫➕💳</div>
                <div>
                  <div class="opt-title">
                    Частичная оплата
                    <span class="badge-recommended">Рекомендуем</span>
                  </div>
                  <div class="opt-desc">Электронный сертификат + доплата картой «Мир». Payment Provider автоматически спишет доступный остаток с сертификата, а разницу — с карты.</div>
                </div>
              </div>
            </label>
          </div>

          <!-- Карта -->
          <div id="paymentCard">
            <input type="radio" name="payment_type" value="card" id="pt_card" class="pay-option"
              <?= (($_POST['payment_type'] ?? '') === 'card') ? 'checked' : '' ?>>
            <label for="pt_card">
              <div class="d-flex align-items-start gap-3">
                <div class="opt-icon">💳</div>
                <div>
                  <div class="opt-title">Банковская карта</div>
                  <div class="opt-desc">Оплата любой банковской картой (Мир, Visa, Mastercard) без использования электронного сертификата.</div>
                </div>
              </div>
            </label>
          </div>

        </div>
      </div>
    </div>

    <!-- Данные участника -->
    <div class="pay-card mb-4">
      <div class="p-4">
        <div class="section-title"><i class="bi bi-person-hearts me-2"></i>Данные участника</div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="child_name">Имя участника <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="child_name" name="child_name"
              value="<?= htmlspecialchars($_POST['child_name'] ?? '') ?>"
              placeholder="Иванов Иван Иванович" required>
          </div>
          <div class="col-12 col-sm-6">
            <label class="form-label" for="birth_date">Дата рождения <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="birth_date" name="birth_date"
              value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>"
              max="<?= date('Y-m-d', strtotime('-6 years')) ?>"
              min="<?= date('Y-m-d', strtotime('-17 years')) ?>" required>
          </div>
          <div class="col-12 col-sm-6">
            <label class="form-label" for="voucher_date">Смена / период заезда <span class="text-danger">*</span></label>
            <select class="form-select" id="voucher_date" name="voucher_date" required>
              <option value="">— выберите смену —</option>
              <option value="1 смена (01.07.2026 – 14.07.2026)" <?= (($_POST['voucher_date'] ?? '') === '1 смена (01.07.2026 – 14.07.2026)') ? 'selected' : '' ?>>1 смена (01.07.2026 – 14.07.2026)</option>
              <option value="2 смена (17.07.2026 – 30.07.2026)" <?= (($_POST['voucher_date'] ?? '') === '2 смена (17.07.2026 – 30.07.2026)') ? 'selected' : '' ?>>2 смена (17.07.2026 – 30.07.2026)</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Данные контактного лица -->
    <div class="pay-card mb-4">
      <div class="p-4">
        <div class="section-title"><i class="bi bi-person me-2"></i>Данные контактного лица / законного представителя</div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="parent_name">Имя <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="parent_name" name="parent_name"
              value="<?= htmlspecialchars($_POST['parent_name'] ?? '') ?>"
              placeholder="Иванова Мария Петровна" required>
          </div>
          <div class="col-12 col-sm-6">
            <label class="form-label" for="phone">Телефон <span class="text-danger">*</span></label>
            <input type="tel" class="form-control" id="phone" name="phone"
              value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
              placeholder="+7 (900) 000-00-00" required>
          </div>
          <div class="col-12 col-sm-6">
            <label class="form-label" for="email">Email для получения документов <span class="text-danger">*</span></label>
            <input type="email" class="form-control" id="email" name="email"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              placeholder="customer@example.com" required>
          </div>
        </div>
      </div>
    </div>

    <!-- Документы -->
    <div class="pay-card mb-4">
      <div class="p-4">
        <div class="section-title"><i class="bi bi-file-earmark-text me-2"></i>Документы</div>
        <div class="alert alert-warning mb-3" style="font-size:.9rem;">
          Перед заездом необходимо скачать, распечатать и заполнить документы. Подписанный договор и заполненные согласия нужно привезти с собой в лагерь.
        </div>
        <div class="mb-3" style="font-size:.9rem;color:#555;">
          В файле согласий нужно заполнить:
          <ul class="mb-0 mt-2">
            <li>информированное добровольное согласие на медицинское вмешательство;</li>
            <li>согласие на фото- и видеосъёмку;</li>
            <li>согласие на обработку персональных данных, разрешённых для распространения;</li>
            <li>согласие-доверенность на участие несовершеннолетнего в отдыхе и оздоровлении;</li>
            <li>согласие субъекта на обработку персональных данных.</li>
          </ul>
        </div>
        <div class="d-flex flex-column flex-sm-row gap-2">
          <a href="documents/terms-demo.html" target="_blank" class="btn btn-outline-success">
            <i class="bi bi-download me-1"></i>Скачать договор
          </a>
          <a href="documents/privacy-demo.html" target="_blank" class="btn btn-outline-success">
            <i class="bi bi-download me-1"></i>Скачать согласия
          </a>
        </div>
      </div>
    </div>

    <!-- Согласие + кнопка -->
    <div class="pay-card mb-4">
      <div class="p-4">
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="agree" name="agree" required>
          <label class="form-check-label" for="agree" style="font-size:.88rem;color:#555;">
            Я даю согласие на
            <a href="documents/privacy-demo.html" target="_blank" class="text-success">обработку персональных данных</a>
            и принимаю условия
            <a href="documents/terms-demo.html" target="_blank" class="text-success">договора об оказании услуг</a>
          </label>
        </div>

        <button type="submit" class="btn btn-pay" id="submitBtn" disabled>
          <i class="bi bi-lock-fill me-2"></i>Перейти к оплате
          <span class="ms-2 fw-normal" style="font-size:.95rem;" id="btnAmount">— <?= number_format((float)VOUCHER_AMOUNT, 0, '.', ' ') ?> ₽</span>
        </button>

        <div class="security-bar mt-3 rounded-3 text-center">
          <i class="bi bi-shield-lock me-1"></i>Оплата через Payment Provider &nbsp;|&nbsp;
          <i class="bi bi-lock me-1"></i>SSL-шифрование &nbsp;|&nbsp;
          <i class="bi bi-patch-check me-1"></i>PCI DSS
        </div>
      </div>
    </div>

  </form>

  <div class="pay-footer">
    Demo Service | +1-555-0100 | contact@example.com
    <br><a href="https://example.com" class="text-muted">← На главную сайта</a>
  </div>

</div>
</div>
</div>

<script>
const submitBtn       = document.getElementById('submitBtn');
const btnAmount       = document.getElementById('btnAmount');
const agree           = document.getElementById('agree');
const radios          = document.querySelectorAll('input[name="payment_type"]');
const amtCustom       = document.getElementById('amt_custom');
const amountRadios    = document.querySelectorAll('input[name="amount_type"]');
const customWrap      = document.getElementById('customAmountWrap');
const customInput     = document.getElementById('custom_amount');
const paymentMixed    = document.getElementById('paymentMixed');
const paymentCard     = document.getElementById('paymentCard');
const certificateOnlyAmounts = ['certificate_50', 'certificate_100'];
const fixedAmount     = <?= (float)VOUCHER_AMOUNT ?>;

// Форматирование числа с пробелами
function fmtNum(n) {
  return Math.round(n).toLocaleString('ru-RU');
}

// Обновить текст суммы в кнопке
function updateBtnAmount() {
  const amountType = document.querySelector('input[name="amount_type"]:checked')?.value;

  if (amountType === 'custom') {
    const v = parseFloat(customInput.value);
    btnAmount.textContent = (v > 0) ? '— ' + fmtNum(v) + ' ₽' : '';
  } else if (amountType === 'certificate_50') {
    btnAmount.textContent = '— ' + fmtNum(500) + ' ₽';
  } else if (amountType === 'certificate_100') {
    btnAmount.textContent = '— ' + fmtNum(1000) + ' ₽';
  } else {
    btnAmount.textContent = '— ' + fmtNum(fixedAmount) + ' ₽';
  }
}

function updatePaymentMethods() {
  const amountType = document.querySelector('input[name="amount_type"]:checked')?.value;
  const certificateOnly = certificateOnlyAmounts.includes(amountType);

  paymentMixed.style.display = certificateOnly ? 'none' : '';
  paymentCard.style.display = certificateOnly ? 'none' : '';

  if (certificateOnly) {
    document.getElementById('pt_cert').checked = true;
  }
}

// Проверить готовность формы
function checkReady() {
  const hasType  = [...radios].some(r => r.checked);
  const hasAgree = agree.checked;
  let   hasAmt   = true;

  if (amtCustom.checked) {
    const v = parseFloat(customInput.value);
    hasAmt = (v >= 1 && v <= 5000);
  }

  submitBtn.disabled = !(hasType && hasAgree && hasAmt);
  updateBtnAmount();
}

// Переключатель суммы
amountRadios.forEach(radio => radio.addEventListener('change', function() {
  const isCustom = amtCustom.checked;
  customWrap.style.display = isCustom ? 'block' : 'none';
  customInput.toggleAttribute('required', isCustom);
  if (isCustom) customInput.focus();
  updatePaymentMethods();
  checkReady();
}));

customInput.addEventListener('input', checkReady);
radios.forEach(r => r.addEventListener('change', checkReady));
agree.addEventListener('change', checkReady);

// Маска телефона
document.getElementById('phone').addEventListener('input', function(e) {
  let v = e.target.value.replace(/\D/g, '');
  if (v.startsWith('8')) v = '7' + v.slice(1);
  if (v.startsWith('7')) {
    v = '+7 (' + v.slice(1,4) + ') ' + v.slice(4,7) + '-' + v.slice(7,9) + '-' + v.slice(9,11);
  }
  e.target.value = v;
});

// Инициализация
if (amtCustom.checked) customWrap.style.display = 'block';
updatePaymentMethods();
checkReady();
</script>

</body>
</html>
