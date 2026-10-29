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

// This Xendit account may serve other integrations. Acknowledge, but never
// query or mutate invoices outside the YESNET external-ID namespace.
$externalId = trim((string) ($notification['external_id'] ?? ''));
if (!xenditIsYesnetExternalId($externalId)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'status' => 'ignored']);
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $stmt = $conn->prepare(
        "SELECT p.xendit_invoice_id, p.id_customer, t.id_customer AS tagihan_customer_id
         FROM pembayaran p
         JOIN tagihan t ON t.id = p.id_tagihan
         WHERE p.xendit_external_id = ? AND p.payment_provider = 'xendit'
         LIMIT 1"
    );
    $stmt->bind_param('s', $externalId);
    $stmt->execute();
    $localPayment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$localPayment) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'status' => 'ignored']);
        exit;
    }

    if (
        (int) $localPayment['id_customer'] <= 0
        || (int) $localPayment['id_customer'] !== (int) $localPayment['tagihan_customer_id']
        || (
            xenditExternalIdCustomerId($externalId) !== null
            && xenditExternalIdCustomerId($externalId) !== (int) $localPayment['id_customer']
        )
        || (
            !empty($localPayment['xendit_invoice_id'])
            && !hash_equals((string) $localPayment['xendit_invoice_id'], $invoiceId)
        )
    ) {
        error_log('Xendit webhook ignored: invoice identity does not match the YESNET payment record.');
        http_response_code(200);
        echo json_encode(['ok' => true, 'status' => 'ignored']);
        exit;
    }

    $invoice = xenditGetInvoice($invoiceId);
    if (
        (string) ($invoice['id'] ?? '') !== $invoiceId
        || (string) ($invoice['external_id'] ?? '') !== $externalId
    ) {
        throw new RuntimeException('Invoice identity from Xendit does not match the notification.');
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
