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

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'HEAD') {
    session_write_close();
    http_response_code(204);
    header('Cache-Control: no-store, private');
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$now = time();
$lastDownload = (int) ($_SESSION['speedtest_download_last_at'] ?? 0);

if ($now - $lastDownload < 3) {
    http_response_code(429);
    header('Retry-After: 3');
    exit;
}

$_SESSION['speedtest_download_last_at'] = $now;
session_write_close();

$size = 4 * 1024 * 1024;
header('Content-Type: application/octet-stream');
header('Content-Length: ' . $size);
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Encoding: identity');

while (ob_get_level() > 0) {
    ob_end_clean();
}

$remaining = $size;

while ($remaining > 0 && connection_aborted() === 0) {
    $chunkSize = min(16384, $remaining);
    echo random_bytes($chunkSize);
    $remaining -= $chunkSize;
    flush();
}
