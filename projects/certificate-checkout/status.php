<?php
/**
 * status.php — AJAX-эндпоинт для проверки статуса платежа
 * Возвращает JSON: {"status": "pending|succeeded|canceled"}
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/YooKassa.php';

header('Content-Type: application/json');

$paymentId = trim($_GET['id'] ?? '');

// Базовая валидация ID (только буквы, цифры, дефисы)
if (!preg_match('/^[\w\-]{10,50}$/', $paymentId)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    exit;
}

try {
    // Сначала смотрим в БД (быстро)
    $payment = Database::getPaymentWithVoucher($paymentId);
    if ($payment && in_array($payment['status'], ['succeeded', 'canceled'])) {
        echo json_encode(['status' => $payment['status']]);
        exit;
    }

    // Если pending — спрашиваем Payment Provider напрямую
    $ykData = YooKassa::getPayment($paymentId);
    $status = $ykData['status'] ?? 'pending';

    // Если изменился — обновляем БД
    if ($status !== 'pending') {
        Database::updatePaymentStatus($paymentId, $status, [
            'raw_response' => json_encode($ykData, JSON_UNESCAPED_UNICODE),
        ]);
    }

    echo json_encode(['status' => $status]);

} catch (Exception $e) {
    error_log('[status] ' . $e->getMessage());
    echo json_encode(['status' => 'pending']); // graceful degradation
}
