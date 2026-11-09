<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

if (
    (int) ($_SESSION['user_id'] ?? 0) <= 0 ||
    normalizeAuthRole($_SESSION['role'] ?? '') !== 'customer'
) {
    http_response_code(401);
    exit;
}

$csrf = $_SERVER['HTTP_X_YESNET_CSRF'] ?? '';
$sessionCsrf = $_SESSION['speedtest_csrf'] ?? '';

if (
    !is_string($csrf) ||
    !is_string($sessionCsrf) ||
    $sessionCsrf === '' ||
    !hash_equals($sessionCsrf, $csrf)
) {
    http_response_code(403);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$now = time();
$lastUpload = (int) ($_SESSION['speedtest_upload_last_at'] ?? 0);

if ($now - $lastUpload < 3) {
    http_response_code(429);
    header('Retry-After: 3');
    exit;
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
$contentLength = filter_var(
    $_SERVER['CONTENT_LENGTH'] ?? null,
    FILTER_VALIDATE_INT
);
$maximumBytes = 6 * 1024 * 1024;

if (
    $contentType !== 'application/octet-stream' ||
    ($contentLength !== false && $contentLength !== null && $contentLength > $maximumBytes)
) {
    http_response_code(413);
    exit;
}

$_SESSION['speedtest_upload_last_at'] = $now;
session_write_close();

$input = fopen('php://input', 'rb');

if ($input === false) {
    http_response_code(400);
    exit;
}

$receivedBytes = 0;

while (!feof($input)) {
    $chunk = fread($input, 65536);

    if ($chunk === false) {
        fclose($input);
        http_response_code(400);
        exit;
    }

    $receivedBytes += strlen($chunk);

    if ($receivedBytes > $maximumBytes) {
        fclose($input);
        http_response_code(413);
        exit;
    }
}

fclose($input);

if ($receivedBytes === 0) {
    http_response_code(400);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo json_encode(['bytes_received' => $receivedBytes], JSON_THROW_ON_ERROR);
