<?php
require_once __DIR__ . '/config/xendit.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!xenditWebhookTokenValid()) {
    error_log('Xendit webhook rejected: invalid callback token.');
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid callback token']);
    exit;
}

$notification = json_decode((string) file_get_contents('php://input'), true);
$invoiceId = is_array($notification) ? trim((string) ($notification['id'] ?? '')) : '';
if (!is_array($notification) || $invoiceId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid invoice notification']);
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $invoice = xenditGetInvoice($invoiceId);
    if ((string) ($invoice['id'] ?? '') !== $invoiceId) {
        throw new RuntimeException('Invoice ID from Xendit does not match the notification.');
    }

    $status = xenditApplyInvoiceStatus($conn, $invoice);
    if ($status === 'unknown') {
        error_log('Xendit webhook ignored: invoice is not associated with a local payment.');
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'status' => $status]);
} catch (Throwable $e) {
    error_log('Xendit webhook processing failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Webhook processing failed']);
}
