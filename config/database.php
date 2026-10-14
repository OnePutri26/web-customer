<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('YESNET_DB_HOST');
$user = getenv('YESNET_DB_USER');
$pass = getenv('YESNET_DB_PASSWORD');
$db = getenv('YESNET_DB_NAME');
$portValue = getenv('YESNET_DB_PORT');
$port = $portValue === false || $portValue === '' ? 3306 : filter_var(
    $portValue,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);

function yesnetDatabaseUnavailable(): never
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $retryUrl = htmlspecialchars((string) ($_SERVER['SCRIPT_NAME'] ?? '/'), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">' .
        '<meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<title>Layanan terganggu | YesNet</title>' .
        '<style>body{font:16px Arial,sans-serif;color:#172b4d;background:#f5f8fc;display:grid;place-items:center;min-height:90vh;margin:0}' .
        'main{max-width:520px;background:#fff;padding:32px;border-radius:16px;text-align:center;box-shadow:0 8px 30px #172b4d18}' .
        'a{display:inline-block;margin-top:16px;padding:12px 20px;background:#1769ff;color:#fff;border-radius:8px;text-decoration:none}</style>' .
        '</head><body><main><h1>Layanan YesNet sedang terganggu</h1>' .
        '<p>Kami belum dapat terhubung ke layanan. Silakan coba kembali beberapa saat lagi.</p>' .
        '<a href="' . $retryUrl . '">Coba Lagi</a></main></body></html>';
    exit;
}

if (
    $host === false || $host === '' ||
    $user === false || $user === '' ||
    $pass === false ||
    $db === false || $db === '' ||
    $port === false
) {
    error_log('YesNet database configuration is incomplete or invalid.');
    yesnetDatabaseUnavailable();
}

try {
    $conn = new mysqli($host, $user, $pass, $db, $port);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log('YesNet database connection failed: ' . $e->getMessage());
    yesnetDatabaseUnavailable();
}