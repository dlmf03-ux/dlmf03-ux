<?php
/**
 * Database — PDO-обёртка для MySQL
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }

    // Создать заявку на путёвку, вернуть voucher_id
    public static function createVoucher(array $data): int
    {
        $db = self::getInstance();
        $sql = "INSERT INTO vouchers
                    (child_name, birth_date, parent_name, phone, email, voucher_type, voucher_date, amount)
                VALUES
                    (:child_name, :birth_date, :parent_name, :phone, :email, :voucher_type, :voucher_date, :amount)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':child_name'   => $data['child_name'],
            ':birth_date'   => $data['birth_date'],
            ':parent_name'  => $data['parent_name'],
            ':phone'        => $data['phone'],
            ':email'        => $data['email'],
            ':voucher_type' => $data['voucher_type'],
            ':voucher_date' => $data['voucher_date'],
            ':amount'       => $data['amount'],
        ]);
        return (int)$db->lastInsertId();
    }

    // Создать запись платежа, вернуть payment_id
    public static function createPayment(array $data): int
    {
        $db = self::getInstance();
        $sql = "INSERT INTO payments
                    (voucher_id, yookassa_id, amount_total, status, payment_type, article_code, confirmation_url, raw_response)
                VALUES
                    (:voucher_id, :yookassa_id, :amount_total, 'pending', :payment_type, :article_code, :confirmation_url, :raw_response)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':voucher_id'        => $data['voucher_id'],
            ':yookassa_id'       => $data['yookassa_id'],
            ':amount_total'      => $data['amount_total'],
            ':payment_type'      => $data['payment_type'],
            ':article_code'      => $data['article_code'] ?? null,
            ':confirmation_url'  => $data['confirmation_url'],
            ':raw_response'      => $data['raw_response'],
        ]);
        return (int)$db->lastInsertId();
    }

    // Обновить статус платежа по yookassa_id
    public static function updatePaymentStatus(string $yookassaId, string $status, array $extra = []): bool
    {
        $db = self::getInstance();

        $setParts = ['status = :status'];
        $params   = [':status' => $status, ':yookassa_id' => $yookassaId];

        if ($status === 'succeeded') {
            $setParts[] = 'paid_at = NOW()';
        }
        if (isset($extra['amount_cert'])) {
            $setParts[] = 'amount_cert = :amount_cert';
            $params[':amount_cert'] = $extra['amount_cert'];
        }
        if (isset($extra['amount_card'])) {
            $setParts[] = 'amount_card = :amount_card';
            $params[':amount_card'] = $extra['amount_card'];
        }
        if (isset($extra['raw_response'])) {
            $setParts[] = 'raw_response = :raw_response';
            $params[':raw_response'] = $extra['raw_response'];
        }

        $sql  = 'UPDATE payments SET ' . implode(', ', $setParts) . ' WHERE yookassa_id = :yookassa_id';
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }

    // Пометить, что email отправлен
    public static function markEmailSent(string $yookassaId): void
    {
        $db   = self::getInstance();
        $stmt = $db->prepare('UPDATE payments SET email_sent = 1 WHERE yookassa_id = :id');
        $stmt->execute([':id' => $yookassaId]);
    }

    // Получить платёж + данные клиента по yookassa_id
    public static function getPaymentWithVoucher(string $yookassaId): ?array
    {
        $db   = self::getInstance();
        $stmt = $db->prepare("
            SELECT p.*, v.child_name, v.birth_date, v.parent_name, v.phone, v.email,
                   v.voucher_type, v.voucher_date
            FROM payments p
            JOIN vouchers v ON v.id = p.voucher_id
            WHERE p.yookassa_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $yookassaId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Логировать webhook
    public static function logWebhook(string $event, string $paymentId, string $payload): void
    {
        try {
            $db   = self::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO webhook_log (event, payment_id, payload) VALUES (:event, :pid, :payload)'
            );
            $stmt->execute([':event' => $event, ':pid' => $paymentId, ':payload' => $payload]);
        } catch (Exception $e) {
            error_log('webhook_log insert failed: ' . $e->getMessage());
        }
    }
}
