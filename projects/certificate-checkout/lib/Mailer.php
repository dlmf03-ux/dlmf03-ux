<?php
/**
 * Mailer — отправка email через SMTP VK WorkSpace (Mail.ru)
 * Использует встроенный PHP socket без сторонних библиотек,
 * либо PHPMailer если установлен через Composer.
 *
 * Реализация: нативный SMTP с поддержкой SSL/TLS + вложения.
 */
class Mailer
{
    /**
     * Отправить письмо клиенту с двумя PDF-вложениями
     *
     * @param string $toEmail   Email получателя
     * @param string $toName    Имя получателя
     * @param array  $payment   Данные платежа (из БД)
     */
    public static function sendDocuments(string $toEmail, string $toName, array $payment): bool
    {
        $subject = 'Demo checkout в Demo Service — документы';

        $body = self::buildHtmlBody($payment);

        return self::send($toEmail, $toName, $subject, $body, [
            DOC_DOGOVOR  => 'terms-demo.html',
            DOC_SOGLASIE => 'privacy-demo.html',
        ]);
    }

    // Отправить письмо об ошибке (для дублирующего уведомления администратору)
    public static function sendAdminAlert(string $message): void
    {
        try {
            self::send(
                SMTP_FROM,
                'Администратор',
                '[Demo Service] Ошибка платёжного сервиса',
                '<pre>' . htmlspecialchars($message) . '</pre>',
                []
            );
        } catch (Exception $e) {
            error_log('Admin alert failed: ' . $e->getMessage());
        }
    }

    // HTML-тело письма
    private static function buildHtmlBody(array $p): string
    {
        $amount      = number_format((float)$p['amount_total'], 2, '.', ' ') . ' ₽';
        $certAmount  = $p['amount_cert'] ? number_format((float)$p['amount_cert'], 2, '.', ' ') . ' ₽' : '—';
        $cardAmount  = $p['amount_card'] ? number_format((float)$p['amount_card'], 2, '.', ' ') . ' ₽' : '—';
        $paidAt      = $p['paid_at'] ? date('d.m.Y H:i', strtotime($p['paid_at'])) : date('d.m.Y H:i');
        $childName   = htmlspecialchars($p['child_name']);
        $parentName  = htmlspecialchars($p['parent_name']);

        $typeLabels  = [
            'certificate' => 'Электронный сертификат',
            'mixed'       => 'Электронный сертификат + карта «Мир»',
            'card'        => 'Банковская карта',
        ];
        $typeLabel = $typeLabels[$p['payment_type'] ?? 'card'] ?? 'Банковская карта';

        ob_start();
        include __DIR__ . '/../templates/email.html';
        return ob_get_clean();
    }

    /**
     * Низкоуровневая отправка через SMTP (SSL, порт 465)
     * Поддерживает HTML и вложения (multipart/mixed)
     */
    private static function send(string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments): bool
    {
        $boundary  = '----=_Part_' . md5(uniqid());
        $boundary2 = '----=_Alt_' . md5(uniqid());

        // Собираем MIME-сообщение
        $mime  = "MIME-Version: 1.0\r\n";
        $mime .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $mime .= "\r\n";

        // HTML-часть
        $mime .= "--{$boundary}\r\n";
        $mime .= "Content-Type: multipart/alternative; boundary=\"{$boundary2}\"\r\n";
        $mime .= "\r\n";

        // Plaintext-версия
        $plain = strip_tags($htmlBody);
        $mime .= "--{$boundary2}\r\n";
        $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n";
        $mime .= "\r\n";
        $mime .= chunk_split(base64_encode($plain)) . "\r\n";

        // HTML-версия
        $mime .= "--{$boundary2}\r\n";
        $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n";
        $mime .= "\r\n";
        $mime .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $mime .= "--{$boundary2}--\r\n";

        // Вложения
        foreach ($attachments as $filePath => $fileName) {
            if (!file_exists($filePath)) {
                error_log("Mailer: файл не найден: {$filePath}");
                continue;
            }
            $fileData = file_get_contents($filePath);
            $mime .= "--{$boundary}\r\n";
            $mime .= "Content-Type: application/pdf; name=\"" . $fileName . "\"\r\n";
            $mime .= "Content-Transfer-Encoding: base64\r\n";
            $mime .= "Content-Disposition: attachment; filename=\"" . $fileName . "\"\r\n";
            $mime .= "\r\n";
            $mime .= chunk_split(base64_encode($fileData)) . "\r\n";
        }
        $mime .= "--{$boundary}--\r\n";

        // SMTP-соединение (SSL)
        $socket = @fsockopen('ssl://' . SMTP_HOST, SMTP_PORT, $errno, $errstr, 15);
        if (!$socket) {
            throw new RuntimeException("SMTP connect failed [{$errno}]: {$errstr}");
        }

        $toEncoded      = '=?UTF-8?B?' . base64_encode($toName) . '?=';
        $fromEncoded    = '=?UTF-8?B?' . base64_encode(SMTP_FROM_NAME) . '?=';
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $cmds = [
            null,                                            // приветствие сервера
            "EHLO localhost",
            "AUTH LOGIN",
            base64_encode(SMTP_USER),
            base64_encode(SMTP_PASS),
            "MAIL FROM:<" . SMTP_FROM . ">",
            "RCPT TO:<{$toEmail}>",
            "DATA",
            "From: {$fromEncoded} <" . SMTP_FROM . ">\r\n"
                . "To: {$toEncoded} <{$toEmail}>\r\n"
                . "Subject: {$subjectEncoded}\r\n"
                . "Date: " . date('r') . "\r\n"
                . $mime
                . "\r\n.",
            "QUIT",
        ];

        foreach ($cmds as $cmd) {
            if ($cmd !== null) {
                fwrite($socket, $cmd . "\r\n");
            }
            $response = '';
            while (($line = fgets($socket, 512)) !== false) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') break; // последняя строка ответа
            }
            if (DEBUG_MODE) {
                error_log("SMTP > {$cmd} | < {$response}");
            }
        }

        fclose($socket);
        return true;
    }
}
