-- ============================================================
-- Установочный SQL-скрипт платёжного сервиса
-- Demo Service — оплата детских путёвок
-- Выполнить ОДИН РАЗ в phpMyAdmin или через mysql CLI
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:00'; -- UTC

-- ─── Заявки на услуги ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vouchers` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `child_name`   VARCHAR(255) NOT NULL COMMENT 'Имя участника',
  `birth_date`   DATE NOT NULL COMMENT 'Дата рождения',
  `parent_name`  VARCHAR(255) NOT NULL COMMENT 'Имя контактного лица',
  `phone`        VARCHAR(30) NOT NULL,
  `email`        VARCHAR(255) NOT NULL,
  `voucher_type` VARCHAR(255) NOT NULL DEFAULT 'Demo service reservation',
  `voucher_date` VARCHAR(150) NOT NULL COMMENT 'Смена / период',
  `amount`       DECIMAL(10,2) NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (`email`),
  INDEX idx_created (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Платежи ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payments` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `voucher_id`       INT UNSIGNED NOT NULL,
  `yookassa_id`      VARCHAR(50) NOT NULL COMMENT 'ID платежа в Payment Provider',
  `amount_total`     DECIMAL(10,2) NOT NULL,
  `amount_cert`      DECIMAL(10,2) DEFAULT NULL COMMENT 'Списано с сертификата',
  `amount_card`      DECIMAL(10,2) DEFAULT NULL COMMENT 'Списано с карты',
  `status`           ENUM('pending','succeeded','canceled') NOT NULL DEFAULT 'pending',
  `payment_type`     ENUM('certificate','mixed','card') NOT NULL COMMENT 'Тип оплаты',
  `article_code`     VARCHAR(120) DEFAULT NULL COMMENT 'Код ТРУ',
  `confirmation_url` TEXT DEFAULT NULL,
  `email_sent`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 — документы отправлены',
  `raw_response`     JSON DEFAULT NULL COMMENT 'Полный ответ Payment Provider',
  `paid_at`          DATETIME DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_yookassa_id (`yookassa_id`),
  INDEX idx_voucher (`voucher_id`),
  INDEX idx_status (`status`),
  INDEX idx_created (`created_at`),
  CONSTRAINT fk_payments_voucher FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Лог webhook-событий ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `webhook_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event`       VARCHAR(60) NOT NULL,
  `payment_id`  VARCHAR(50) DEFAULT NULL,
  `payload`     MEDIUMTEXT DEFAULT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_payment_id (`payment_id`),
  INDEX idx_event (`event`),
  INDEX idx_received (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Просмотр — платежи с данными клиентов ──────────────────
CREATE OR REPLACE VIEW `v_payments_full` AS
SELECT
  p.id              AS payment_db_id,
  p.yookassa_id,
  p.status,
  p.payment_type,
  p.amount_total,
  p.amount_cert,
  p.amount_card,
  p.email_sent,
  p.paid_at,
  p.created_at      AS payment_created,
  v.child_name,
  v.birth_date,
  v.parent_name,
  v.phone,
  v.email,
  v.voucher_date
FROM payments p
JOIN vouchers v ON v.id = p.voucher_id
ORDER BY p.created_at DESC;
