<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$eventType = substr(trim((string)($data['event_type'] ?? '')), 0, 64);
$allowedEvents = ['app_install', 'app_start', 'check_updates', 'library_download', 'library_operation'];
if (!in_array($eventType, $allowedEvents, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_event_type']);
    exit;
}

$text = static fn(string $key, int $max): ?string => isset($data[$key]) && $data[$key] !== ''
    ? substr((string)$data[$key], 0, $max)
    : null;

$bytesDownloaded = isset($data['bytes_downloaded']) && is_numeric($data['bytes_downloaded'])
    ? max(0, (int)$data['bytes_downloaded'])
    : null;
$metadataJson = isset($data['metadata']) && is_array($data['metadata'])
    ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;

$countryCode = $_SERVER['HTTP_CF_IPCOUNTRY']
    ?? $_SERVER['HTTP_X_COUNTRY_CODE']
    ?? null;
$countryCode = is_string($countryCode) ? strtoupper(substr($countryCode, 0, 2)) : null;
$region = $_SERVER['HTTP_X_REGION'] ?? $_SERVER['HTTP_X_GEO_REGION'] ?? null;
$city = $_SERVER['HTTP_X_CITY'] ?? $_SERVER['HTTP_X_GEO_CITY'] ?? null;

$pdo = demo_pdo();
$stmt = $pdo->prepare(
    'INSERT INTO demo_update_events (
        event_type, client_id, app_version, library_name, library_version,
        supplier, category, subcategory, operation, status, error_message,
        bytes_downloaded, download_url, metadata_json, ip_address,
        country_code, region, city, user_agent
    ) VALUES (
        :event_type, :client_id, :app_version, :library_name, :library_version,
        :supplier, :category, :subcategory, :operation, :status, :error_message,
        :bytes_downloaded, :download_url, :metadata_json, :ip_address,
        :country_code, :region, :city, :user_agent
    )'
);

$stmt->execute([
    ':event_type' => $eventType,
    ':client_id' => $text('client_id', 32),
    ':app_version' => $text('app_version', 32),
    ':library_name' => $text('library_name', 255),
    ':library_version' => $text('library_version', 255),
    ':supplier' => $text('supplier', 255),
    ':category' => $text('category', 255),
    ':subcategory' => $text('subcategory', 255),
    ':operation' => $text('operation', 32),
    ':status' => $text('status', 32),
    ':error_message' => $text('error_message', 2000),
    ':bytes_downloaded' => $bytesDownloaded,
    ':download_url' => $text('download_url', 4096),
    ':metadata_json' => $metadataJson,
    ':ip_address' => demo_client_ip(),
    ':country_code' => $countryCode,
    ':region' => is_string($region) ? substr($region, 0, 128) : null,
    ':city' => is_string($city) ? substr($city, 0, 128) : null,
    ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
]);

echo json_encode(['ok' => true]);
