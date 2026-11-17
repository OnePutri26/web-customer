<?php
declare(strict_types=1);

/**
 * YESNET - CUSTOMER SPEEDTEST HISTORY API
 *
 * GET  : Mengambil maksimal 10 riwayat speed test milik pelanggan login.
 * POST : Menyimpan hasil speed test pelanggan login.
 *
 * Endpoint ini menerima hasil dari customer/speedtest.php dengan header:
 * X-YESNET-CSRF: token sesi
 *
 * Tabel yang digunakan:
 * customer_speedtest_history
 * - id
 * - customer_id
 * - download_mbps
 * - upload_mbps
 * - ping_ms
 * - jitter_ms (boleh NULL)
 * - tested_at
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('customer');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Mengirim respons JSON dan menghentikan eksekusi.
 */
function speedtestHistoryResponse(array $data, int $status = 200): never
{
    http_response_code($status);

    try {
        echo json_encode(
            $data,
            JSON_THROW_ON_ERROR
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_UNESCAPED_SLASHES
        );
    } catch (JsonException $e) {
        http_response_code(500);
        echo '{"error":"Gagal membuat respons JSON."}';
    }

    exit;
}

/**
 * Mengambil ID pelanggan berdasarkan ID user yang sedang login.
 * Menggunakan bind_result agar tidak bergantung pada mysqlnd/get_result().
 */
function speedtestHistoryCustomerId(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare(
        'SELECT id
         FROM customers
         WHERE user_id = ?
         LIMIT 1'
    );

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($customerId);

    $found = $stmt->fetch();
    $stmt->close();

    return $found ? (int) $customerId : 0;
}

/**
 * Memvalidasi angka yang diterima dari frontend.
 */
function speedtestHistoryNumber(
    mixed $value,
    string $field,
    float $maximum
): float {
    if (
        $value === null ||
        $value === '' ||
        !is_numeric($value)
    ) {
        throw new InvalidArgumentException(
            'Nilai ' . $field . ' tidak valid.'
        );
    }

    $number = (float) $value;

    if (
        !is_finite($number) ||
        $number < 0 ||
        $number > $maximum
    ) {
        throw new InvalidArgumentException(
            'Nilai ' . $field . ' berada di luar batas.'
        );
    }

    return $number;
}

/**
 * Mengambil body JSON dengan batas ukuran.
 */
function speedtestHistoryReadJsonBody(): array
{
    $contentType = strtolower(
        trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])
    );

    if ($contentType !== 'application/json') {
        speedtestHistoryResponse(
            ['error' => 'Content-Type harus application/json.'],
            415
        );
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength <= 0) {
        speedtestHistoryResponse(
            ['error' => 'Data request kosong.'],
            400
        );
    }

    if ($contentLength > 2048) {
        speedtestHistoryResponse(
            ['error' => 'Ukuran request terlalu besar.'],
            413
        );
    }

    $rawBody = file_get_contents('php://input');

    if (!is_string($rawBody) || trim($rawBody) === '') {
        speedtestHistoryResponse(
            ['error' => 'Data request tidak dapat dibaca.'],
            400
        );
    }

    try {
        $payload = json_decode(
            $rawBody,
            true,
            16,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        speedtestHistoryResponse(
            ['error' => 'Format JSON tidak valid.'],
            400
        );
    }

    if (!is_array($payload) || array_is_list($payload)) {
        speedtestHistoryResponse(
            ['error' => 'Data hasil speed test tidak valid.'],
            400
        );
    }

    return $payload;
}

/**
 * Memeriksa token CSRF dari sesi pelanggan.
 */
function speedtestHistoryVerifyCsrf(): void
{
    $csrf = (string) ($_SERVER['HTTP_X_YESNET_CSRF'] ?? '');
    $sessionCsrf = $_SESSION['speedtest_csrf'] ?? '';

    if (
        !is_string($sessionCsrf) ||
        $sessionCsrf === '' ||
        $csrf === '' ||
        !hash_equals($sessionCsrf, $csrf)
    ) {
        speedtestHistoryResponse(
            ['error' => 'Token keamanan tidak valid. Muat ulang halaman.'],
            403
        );
    }
}

/**
 * Mengambil riwayat terbaru pelanggan.
 */
function speedtestHistoryGet(mysqli $conn, int $customerId): never
{
    $stmt = $conn->prepare(
        'SELECT
            download_mbps,
            upload_mbps,
            ping_ms,
            jitter_ms,
            tested_at
         FROM customer_speedtest_history
         WHERE customer_id = ?
         ORDER BY tested_at DESC, id DESC
         LIMIT 10'
    );

    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $stmt->bind_result(
        $download,
        $upload,
        $ping,
        $jitter,
        $testedAt
    );

    $history = [];

    while ($stmt->fetch()) {
        $history[] = [
            'download_mbps' => (float) $download,
            'upload_mbps' => (float) $upload,
            'ping_ms' => (float) $ping,
            'jitter_ms' => $jitter === null ? null : (float) $jitter,
            'tested_at' => (string) $testedAt,
        ];
    }

    $stmt->close();

    speedtestHistoryResponse([
        'success' => true,
        'history' => $history,
    ]);
}

/**
 * Menyimpan hasil speed test.
 */
function speedtestHistorySave(mysqli $conn, int $customerId): never
{
    speedtestHistoryVerifyCsrf();

    $payload = speedtestHistoryReadJsonBody();

    $download = speedtestHistoryNumber(
        $payload['download_mbps'] ?? null,
        'download',
        10000
    );

    $upload = speedtestHistoryNumber(
        $payload['upload_mbps'] ?? null,
        'upload',
        10000
    );

    $ping = speedtestHistoryNumber(
        $payload['ping_ms'] ?? null,
        'ping',
        300000
    );

    $jitter = null;

    if (
        array_key_exists('jitter_ms', $payload) &&
        $payload['jitter_ms'] !== null &&
        $payload['jitter_ms'] !== ''
    ) {
        $jitter = speedtestHistoryNumber(
            $payload['jitter_ms'],
            'jitter',
            300000
        );
    }

    $stmt = $conn->prepare(
        'INSERT INTO customer_speedtest_history
            (
                customer_id,
                download_mbps,
                upload_mbps,
                ping_ms,
                jitter_ms
            )
         VALUES (?, ?, ?, ?, ?)'
    );

    $stmt->bind_param(
        'idddd',
        $customerId,
        $download,
        $upload,
        $ping,
        $jitter
    );

    $stmt->execute();
    $insertId = (int) $stmt->insert_id;
    $stmt->close();

    speedtestHistoryResponse([
        'success' => true,
        'saved' => true,
        'id' => $insertId,
    ], 201);
}

/* --------------------------------------------------------------------------
 | VALIDASI SESI PELANGGAN
 * -------------------------------------------------------------------------- */

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    speedtestHistoryResponse(
        ['error' => 'Sesi pelanggan tidak valid. Silakan login kembali.'],
        401
    );
}

/* --------------------------------------------------------------------------
 | VALIDASI METODE REQUEST
 * -------------------------------------------------------------------------- */

$method = strtoupper(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');

    speedtestHistoryResponse(
        ['error' => 'Metode request tidak didukung.'],
        405
    );
}

/* --------------------------------------------------------------------------
 | PROSES API
 * -------------------------------------------------------------------------- */

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            'Koneksi database tidak tersedia.'
        );
    }

    $customerId = speedtestHistoryCustomerId($conn, $userId);

    if ($customerId <= 0) {
        speedtestHistoryResponse(
            ['error' => 'Data pelanggan tidak ditemukan.'],
            404
        );
    }

    if ($method === 'GET') {
        speedtestHistoryGet($conn, $customerId);
    }

    speedtestHistorySave($conn, $customerId);

} catch (InvalidArgumentException $e) {
    speedtestHistoryResponse([
        'error' => $e->getMessage(),
    ], 422);

} catch (mysqli_sql_exception $e) {
    error_log(
        'YESNET speedtest history database error: ' . $e->getMessage()
    );

    speedtestHistoryResponse([
        'error' => 'Riwayat speed test gagal diproses karena masalah database.',
    ], 500);

} catch (Throwable $e) {
    error_log(
        'YESNET speedtest history request failed: ' . $e->getMessage()
    );

    speedtestHistoryResponse([
        'error' => 'Riwayat speed test gagal diproses.',
    ], 500);
}
