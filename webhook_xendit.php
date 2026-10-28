<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/xendit.php';

header('Content-Type: application/json');

// Hanya terima POST dari Xendit
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed']);
    exit;
}

// 1. Validasi token (header x-callback-token)
if (!xenditWebhookTokenValid()) {
    error_log('Webhook Xendit ditolak: x-callback-token tidak valid.');
    http_response_code(401);
    echo json_encode(['message' => 'Invalid callback token']);
    exit;
}

// 2. Baca isi JSON
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['id'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Payload tidak valid']);
    exit;
}

try {
    // 3. Ambil data invoice langsung dari Xendit (lebih aman daripada percaya isi webhook)
    $invoice = xenditGetInvoice((string) $payload['id']);

    // 4. Update tabel pembayaran & tagihan
    $result = xenditApplyInvoiceStatus($conn, $invoice);

    http_response_code(200);
    echo json_encode(['message' => 'OK', 'status' => $result]);
} catch (Throwable $e) {
    error_log('Webhook Xendit gagal diproses: ' . $e->getMessage());
    // 500 membuat Xendit mencoba kirim ulang otomatis
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memproses webhook']);
}