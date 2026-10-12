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

if (
    $host === false || $host === '' ||
    $user === false || $user === '' ||
    $pass === false ||
    $db === false || $db === '' ||
    $port === false
) {
    error_log('YesNet database configuration is incomplete or invalid.');
    http_response_code(503);
    exit('Layanan sementara tidak tersedia. Silakan coba kembali nanti.');
}

try {
    $conn = new mysqli($host, $user, $pass, $db, $port);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log('YesNet database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Layanan sementara tidak tersedia. Silakan coba kembali nanti.');
}