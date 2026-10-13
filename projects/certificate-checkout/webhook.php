<?php
/**
 * webhook.php — обработчик уведомлений Payment Provider
 * URL нужно прописать в ЛК Payment Provider → Настройки → HTTP-уведомления
 * URL: https://example.com/payment/webhook.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/YooKassa.php';
require_once __DIR__ . '/lib/Mailer.php';

// ─── Защита ──────────────────────────────────────────────────────────────────

// Принимаем только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Проверяем IP (только серверы Payment Provider)
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['HTTP_X_REAL_IP']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';

// Берём первый IP из возможного списка (proxy)
$clientIp = trim(explode(',', $clientIp)[0]);

if (!YooKassa::isValidWebhookIp($clientIp)) {
    // В тестовом окружении можно закомментировать эту проверку
    error_log("[webhook] Отклонён IP: {$clientIp}");
    http_response_code(403);
    exit('Forbidden');
}

// ─── Чтение тела запроса ─────────────────────────────────────────────────────

$body = file_get_contents('php://input');
if (empty($body)) {
    http_response_code(400);
    exit('Empty body');
}

$event = json_decode($body, true);
if (json_last_error() !== JSON_ERROR_NONE || empty($event['event'])) {
    http_response_code(400);
    exit('Invalid JSON');
}

// ─── Логируем webhook ────────────────────────────────────────────────────────

$eventType = $event['event'];
$ykPayment = $event['object'] ?? [];
$paymentId = $ykPayment['id'] ?? '';

Database::logWebhook($eventType, $paymentId, $body);

// ─── Обрабатываем события ─────────────────────────────────────────────────────

http_response_code(200); // Отвечаем Payment Provider сразу, потом обрабатываем

switch ($eventType) {

    case 'payment.succeeded':
        handlePaymentSucceeded($ykPayment);
        break;

    case 'payment.canceled':
        Database::updatePaymentStatus($paymentId, 'canceled', [
            'raw_response' => json_encode($ykPayment, JSON_UNESCAPED_UNICODE),
        ]);
        break;

    case 'payment.waiting_for_capture':
        // Для двустадийных платежей (у нас capture=true, поэтому маловероятно)
        break;

    default:
        error_log("[webhook] Неизвестное событие: {$eventType}");
}

exit;

// ─── Обработка успешного платежа ────────────────────────────────────────────

function handlePaymentSucceeded(array $yk): void
{
    $paymentId = $yk['id'] ?? '';
    if (!$paymentId) return;

    // Суммы по способам оплаты
    $amountCert = null;
    $amountCard = null;

    // В ответе Payment Provider для электронного сертификата есть breakdown по методам
    // Поле payment_method.articles говорит что использовался сертификат
    $method = $yk['payment_method'] ?? [];
    if (($method['type'] ?? '') === 'electronic_certificate') {
        // Сумма сертификата (если есть в ответе)
        $certValue = $yk['payment_method']['amount']['value']
            ?? $yk['income_amount']['value']
            ?? null;
        if ($certValue) {
            $total    = (float)($yk['amount']['value'] ?? 0);
            $cert     = (float)$certValue;
            $amountCert = number_format(min($cert, $total), 2, '.', '');
            $amountCard = number_format(max($total - $cert, 0), 2, '.', '');
        }
    }

    // Обновляем статус в БД
    Database::updatePaymentStatus($paymentId, 'succeeded', [
        'amount_cert'  => $amountCert,
        'amount_card'  => $amountCard,
        'raw_response' => json_encode($yk, JSON_UNESCAPED_UNICODE),
    ]);

    // Получаем данные клиента
    $payment = Database::getPaymentWithVoucher($paymentId);
    if (!$payment) {
        error_log("[webhook] Платёж не найден в БД: {$paymentId}");
        return;
    }

    // Отправляем документы на email (только один раз)
    if (!$payment['email_sent']) {
        try {
            Mailer::sendDocuments(
                $payment['email'],
                $payment['parent_name'],
                array_merge($payment, [
                    'amount_cert' => $amountCert,
                    'amount_card' => $amountCard,
                ])
            );
            Database::markEmailSent($paymentId);
        } catch (Exception $e) {
            error_log("[webhook] Ошибка отправки email: " . $e->getMessage());
            // Не прерываем — платёж уже успешен, email можно отправить повторно вручную
            Mailer::sendAdminAlert(
                "Не удалось отправить документы клиенту {$payment['email']} по платежу {$paymentId}.\n" .
                "Ошибка: " . $e->getMessage()
            );
        }
    }
}
