<?php
declare(strict_types=1);

require_once __DIR__ . '/speedtest-common.php';

stGuard(['POST']);

$maximumBytes = 6 * 1024 * 1024;

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/octet-stream') {
    stFail(415);
}

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($contentLength !== false && $contentLength !== null && $contentLength > $maximumBytes) {
    stFail(413);
}

$input = fopen('php://input', 'rb');
if ($input === false) stFail(400);

$received = 0;
while (!feof($input)) {
    $chunk = fread($input, 65536);
    if ($chunk === false) {
        fclose($input);
        stFail(400);
    }
    $received += strlen($chunk);
    if ($received > $maximumBytes) {
        fclose($input);
        stFail(413);
    }
}
fclose($input);

if ($received === 0) stFail(400);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo json_encode(['bytes_received' => $received], JSON_THROW_ON_ERROR);