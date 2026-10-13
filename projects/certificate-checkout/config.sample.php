<?php
// Demo configuration: use sandbox credentials from local environment only.
define('YK_SHOP_ID', getenv('YK_SHOP_ID') ?: 'test-shop-id');
define('YK_SECRET_KEY', getenv('YK_SECRET_KEY') ?: '');
define('YK_API_URL', getenv('YK_API_URL') ?: 'https://api.yookassa.ru/v3/');
define('VOUCHER_AMOUNT', '1000.00');
define('VOUCHER_DAYS', 1);
define('VOUCHER_DAY_PRICE', '1000.00');
define('VOUCHER_NAME', 'Demo service');
define('TRU_CODE', '000000000.00000000000000000000');
define('ARTICLE_CODE', 'DEMO-SERVICE-001');
define('VAT_CODE', 1);
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'checkout_demo');
define('DB_USER', getenv('DB_USER') ?: 'checkout_demo_user');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 465));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'checkout@example.com');
define('SMTP_FROM_NAME', 'Demo checkout');
define('DOC_DOGOVOR', __DIR__ . '/documents/terms-demo.html');
define('DOC_SOGLASIE', __DIR__ . '/documents/privacy-demo.html');
define('BASE_URL', getenv('BASE_URL') ?: 'http://127.0.0.1:8080');
define('SUCCESS_URL', BASE_URL . '/success.php');
define('CANCEL_URL', BASE_URL . '/cancel.php');
define('WEBHOOK_URL', BASE_URL . '/webhook.php');
define('DEBUG_MODE', true);
ini_set('display_errors', DEBUG_MODE ? '1' : '0');
if (!DEBUG_MODE) { ini_set('log_errors', '1'); ini_set('error_log', __DIR__ . '/logs/error.log'); }
date_default_timezone_set('UTC');
