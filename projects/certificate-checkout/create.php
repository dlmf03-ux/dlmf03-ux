<?php
/**
 * create.php — создаёт платёж в Payment Provider и редиректит клиента на страницу оплаты
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/YooKassa.php';

// Проверяем наличие данных из формы
if (empty($_SESSION['payment_data'])) {
    header('Location: index.php');
    exit;
}

$data = $_SESSION['payment_data'];
unset($_SESSION['payment_data']); // Сразу очищаем

try {
    // 1. Сохраняем заявку в БД
    $voucherId = Database::createVoucher([
        'child_name'   => $data['child_name'],
        'birth_date'   => $data['birth_date'],
        'parent_name'  => $data['parent_name'],
        'phone'        => $data['phone'],
        'email'        => $data['email'],
        'voucher_type' => VOUCHER_NAME,
        'voucher_date' => $data['voucher_date'],
        'amount'       => VOUCHER_AMOUNT,
    ]);

    // 2. Формируем тело запроса для Payment Provider
    $payload = YooKassa::buildCertPayload(
        array_merge($data, ['id' => $voucherId]),
        $data['payment_type'],
        $data['amount']          // динамическая сумма
    );

    // 3. Создаём платёж в Payment Provider
    $response = YooKassa::createPayment($payload);

    $confirmationUrl = $response['confirmation']['confirmation_url'] ?? null;
    if (!$confirmationUrl) {
        throw new RuntimeException('Не получен URL подтверждения от Payment Provider');
    }

    // 4. Сохраняем платёж в БД
    Database::createPayment([
        'voucher_id'       => $voucherId,
        'yookassa_id'      => $response['id'],
        'amount_total'     => $data['amount'],
        'payment_type'     => $data['payment_type'],
        'article_code'     => in_array($data['payment_type'], ['certificate','mixed']) ? TRU_CODE : null,
        'confirmation_url' => $confirmationUrl,
        'raw_response'     => json_encode($response, JSON_UNESCAPED_UNICODE),
    ]);

    // 5. Сохраняем yookassa_id в сессии (нужен на success.php)
    $_SESSION['last_payment_id'] = $response['id'];

    // 6. Редирект на страницу оплаты Payment Provider
    header('Location: ' . $confirmationUrl);
    exit;

} catch (Exception $e) {
    $errMsg = $e->getMessage();
    error_log('[payment/create] ' . $errMsg);

    // В режиме отладки показываем точную ошибку, иначе — общее сообщение
    if (DEBUG_MODE) {
        $_SESSION['payment_error'] = $errMsg;
    } else {
        $_SESSION['payment_error'] = 'Не удалось создать платёж. Попробуйте позже или обратитесь по телефону +1-555-0100';
    }
    header('Location: index.php');
    exit;
}
