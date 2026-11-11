<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('customer');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function speedtestHistoryResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    exit;
}

function speedtestHistoryCustomerId(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare(
        'SELECT id FROM customers WHERE user_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        $customer = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    } else {
        $stmt->bind_result($customerId);
        $customer = $stmt->fetch() ? ['id' => $customerId] : null;
    }

    $stmt->close();
    return (int) ($customer['id'] ?? 0);
}

function speedtestHistoryNumber(mixed $value, string $field, float $maximum): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Nilai ' . $field . ' tidak valid.');
    }

    $number = (float) $value;
    if (!is_finite($number) || $number < 0 || $number > $maximum) {
        throw new InvalidArgumentException('Nilai ' . $field . ' berada di luar batas.');
    }

    return $number;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    speedtestHistoryResponse(['error' => 'Sesi pelanggan tidak valid.'], 401);
}

try {
    $customerId = speedtestHistoryCustomerId($conn, $userId);
    if ($customerId <= 0) {
        speedtestHistoryResponse(['error' => 'Data pelanggan tidak ditemukan.'], 404);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $stmt = $conn->prepare(
            'SELECT download_mbps, upload_mbps, ping_ms, jitter_ms, tested_at
             FROM customer_speedtest_history
             WHERE customer_id = ?
             ORDER BY tested_at DESC, id DESC
             LIMIT 10'
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            $history = $result instanceof mysqli_result
                ? $result->fetch_all(MYSQLI_ASSOC)
                : [];
        } else {
            $stmt->bind_result($download, $upload, $ping, $jitter, $testedAt);
            $history = [];
            while ($stmt->fetch()) {
                $history[] = [
                    'download_mbps' => $download,
                    'upload_mbps' => $upload,
                    'ping_ms' => $ping,
                    'jitter_ms' => $jitter,
                    'tested_at' => $testedAt,
                ];
            }
        }
        $stmt->close();

        speedtestHistoryResponse(['history' => $history]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        speedtestHistoryResponse(['error' => 'Metode request tidak didukung.'], 405);
    }

    $csrf = (string) ($_SERVER['HTTP_X_YESNET_CSRF'] ?? '');
    $sessionCsrf = $_SESSION['speedtest_csrf'] ?? '';
    if (
        !is_string($sessionCsrf) ||
        $sessionCsrf === '' ||
        $csrf === '' ||
        !hash_equals($sessionCsrf, $csrf)
    ) {
        speedtestHistoryResponse(['error' => 'Token keamanan tidak valid. Muat ulang halaman.'], 403);
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0 || $contentLength > 2048) {
        speedtestHistoryResponse(['error' => 'Ukuran request tidak valid.'], 413);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        speedtestHistoryResponse(['error' => 'Data hasil speed test tidak valid.'], 400);
    }

    $download = speedtestHistoryNumber($payload['download_mbps'] ?? null, 'download', 10000);
    $upload = speedtestHistoryNumber($payload['upload_mbps'] ?? null, 'upload', 10000);
    $ping = speedtestHistoryNumber($payload['ping_ms'] ?? null, 'ping', 300000);
    $jitter = array_key_exists('jitter_ms', $payload)
        ? speedtestHistoryNumber($payload['jitter_ms'], 'jitter', 300000)
        : null;

    $stmt = $conn->prepare(
        'INSERT INTO customer_speedtest_history
         (customer_id, download_mbps, upload_mbps, ping_ms, jitter_ms)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('idddd', $customerId, $download, $upload, $ping, $jitter);
    $stmt->execute();
    $stmt->close();

    speedtestHistoryResponse(['saved' => true], 201);
} catch (InvalidArgumentException $e) {
    speedtestHistoryResponse(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Customer speed test history request failed: ' . $e->getMessage());
    speedtestHistoryResponse(['error' => 'Riwayat speed test gagal diproses.'], 500);
}
