<?php
/**
 * YooKassa — обёртка над API Payment Provider
 */

class YooKassa
{
    public static function createPayment(array $params): array
    {
        $idempotenceKey = uniqid('pay_', true);

        self::logRequestPayload($params);

        $response = self::request('POST', 'payments', $params, $idempotenceKey);

        if (!isset($response['id'])) {
            throw new RuntimeException(
                'Payment Provider: не получен ID платежа. Ответ: ' .
                json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }

        return $response;
    }

    public static function getPayment(string $paymentId): array
    {
        return self::request('GET', 'payments/' . $paymentId);
    }

    public static function buildCertPayload(array $voucher, string $paymentType, string $amount = VOUCHER_AMOUNT): array
    {
        $description = mb_substr(VOUCHER_NAME . ' | ' . $voucher['child_name'], 0, 128);
        $itemQuantity = (int)VOUCHER_DAYS;
        $itemPrice = self::calculateUnitAmount($amount, $itemQuantity);

        $payload = [
            'amount' => [
                'value'    => self::formatAmount($amount),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type'       => 'redirect',
                'return_url' => SUCCESS_URL . '?type=' . urlencode($paymentType),
            ],
            'capture'     => true,
            'description' => $description,
            'metadata'    => [
                'voucher_id'   => (string)$voucher['id'],
                'payment_type' => $paymentType,
                'child_name'   => mb_substr($voucher['child_name'], 0, 100),
            ],
            'receipt' => [
                'customer' => [
                    'email' => $voucher['email'],
                ],
                'items' => [
                    [
                        'description' => mb_substr(VOUCHER_NAME, 0, 128),
                        'quantity'    => number_format($itemQuantity, 2, '.', ''),
                        'amount'      => [
                            'value'    => $itemPrice,
                            'currency' => 'RUB',
                        ],
                        'vat_code'        => (int)VAT_CODE,
                        'payment_mode'    => 'full_payment',
                        'payment_subject' => 'service',
                    ],
                ],
            ],
        ];

        if ($paymentType === 'certificate' || $paymentType === 'mixed') {
            $payload['payment_method_data'] = [
                'type'     => 'electronic_certificate',
                'articles' => [
                    [
                        'article_number' => 1,
                        'tru_code'       => (string)TRU_CODE,
                        'article_code'   => defined('ARTICLE_CODE') ? ARTICLE_CODE : 'LUKOMORIE-PUTEVKA-14',
                        'article_name'   => mb_substr(VOUCHER_NAME, 0, 128),
                        'quantity'       => $itemQuantity,
                        'price'          => [
                            'value'    => $itemPrice,
                            'currency' => 'RUB',
                        ],
                    ],
                ],
            ];
        }

        return $payload;
    }

    private static function request(string $method, string $endpoint, array $body = [], string $idempotenceKey = ''): array
    {
        $url = YK_API_URL . $endpoint;

        $headers = [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(YK_SHOP_ID . ':' . YK_SECRET_KEY),
        ];

        if ($idempotenceKey !== '') {
            $headers[] = 'Idempotence-Key: ' . $idempotenceKey;
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

            error_log('[YooKassa REQUEST JSON] ' . json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ));

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('cURL ошибка: ' . $curlErr);
        }

        $decoded = json_decode($result, true);

        error_log('[YooKassa RESPONSE HTTP ' . $httpCode . '] ' . $result);

        if ($httpCode >= 400) {
            $code      = $decoded['code']        ?? '—';
            $desc      = $decoded['description'] ?? $result;
            $parameter = $decoded['parameter']   ?? '—';

            $fullMsg = "Payment Provider API ошибка [{$httpCode}]: {$desc} | code: {$code} | parameter: {$parameter}";

            error_log('[YooKassa] ' . $fullMsg);

            throw new RuntimeException($fullMsg);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function formatAmount(string $amount): string
    {
        return number_format((float)str_replace(',', '.', $amount), 2, '.', '');
    }

    private static function calculateUnitAmount(string $amount, int $quantity): string
    {
        $amountKopecks = (int)round((float)self::formatAmount($amount) * 100);

        if ($quantity < 1 || $amountKopecks % $quantity !== 0) {
            throw new RuntimeException(
                'Сумма оплаты должна без остатка делиться на количество единиц сертификата: ' . $quantity
            );
        }

        return number_format(($amountKopecks / $quantity) / 100, 2, '.', '');
    }

    private static function logRequestPayload(array $payload): void
    {
        error_log('[YooKassa PAYMENT PAYLOAD] ' . json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }

    public static function isValidWebhookIp(string $ip): bool
    {
        $allowedRanges = [
            '185.71.76.0/27',
            '185.71.77.0/27',
            '77.75.153.0/25',
            '77.75.156.11/32',
            '77.75.156.35/32',
        ];

        foreach ($allowedRanges as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int)$bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
