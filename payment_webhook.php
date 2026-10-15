<?php
/*
| Endpoint notifikasi pembayaran Midtrans.
| Daftarkan di Dashboard Midtrans: Settings > Configuration > Payment Notification URL
|   https://DOMAIN-ANDA/customer/payment_webhook.php
*/
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../inc/midtrans.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$n = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($n)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'pesan' => 'Body tidak valid']);
    exit;
}

if (MIDTRANS_SERVER_KEY === '' || !midtransSignatureOk($n)) {
    error_log('Midtrans webhook: signature tidak valid untuk order ' . (string) ($n['order_id'] ?? '-'));
    http_response_code(403);
    echo json_encode(['ok' => false, 'pesan' => 'Signature tidak valid']);
    exit;
}

try {
    $hasil = midtransApply($conn, $n);
    echo json_encode(['ok' => true, 'status' => $hasil]);
} catch (Throwable $e) {
    error_log('Midtrans webhook gagal: ' . $e->getMessage());
    http_response_code(500); // 5xx membuat Midtrans mencoba kirim ulang
    echo json_encode(['ok' => false]);
}