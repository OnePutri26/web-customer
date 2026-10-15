<?php
/*
|--------------------------------------------------------------------------
| Integrasi Midtrans Snap
| Dipakai oleh pembayaran.php dan customer/payment_webhook.php.
|
| Isi kunci di config/app.php (atau lewat environment variable):
|   define('MIDTRANS_SERVER_KEY', 'SB-Mid-server-xxxx');
|   define('MIDTRANS_CLIENT_KEY', 'SB-Mid-client-xxxx');
|   define('MIDTRANS_IS_PRODUCTION', false);   // true untuk akun live
|--------------------------------------------------------------------------
*/

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!defined('MIDTRANS_SERVER_KEY')) {
    define('MIDTRANS_SERVER_KEY', trim((string) getenv('MIDTRANS_SERVER_KEY')));
}
if (!defined('MIDTRANS_CLIENT_KEY')) {
    define('MIDTRANS_CLIENT_KEY', trim((string) getenv('MIDTRANS_CLIENT_KEY')));
}
if (!defined('MIDTRANS_IS_PRODUCTION')) {
    define('MIDTRANS_IS_PRODUCTION', filter_var(getenv('MIDTRANS_IS_PRODUCTION'), FILTER_VALIDATE_BOOLEAN));
}

function midtransReady(): bool
{
    return MIDTRANS_SERVER_KEY !== '' && MIDTRANS_CLIENT_KEY !== '' && function_exists('curl_init');
}

function midtransSnapJsUrl(): string
{
    return MIDTRANS_IS_PRODUCTION
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';
}

function midtransSnapApi(): string
{
    return MIDTRANS_IS_PRODUCTION
        ? 'https://app.midtrans.com/snap/v1/transactions'
        : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
}

function midtransCoreApi(): string
{
    return MIDTRANS_IS_PRODUCTION
        ? 'https://api.midtrans.com/v2'
        : 'https://api.sandbox.midtrans.com/v2';
}

/** Panggilan HTTP ke Midtrans. Mengembalikan array respons (+ kunci _http). */
function midtransRequest(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(MIDTRANS_SERVER_KEY . ':'),
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($raw === false) {
        throw new RuntimeException('Midtrans tidak dapat dihubungi: ' . $err);
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Respons Midtrans tidak valid (HTTP ' . $http . ').');
    }
    $data['_http'] = $http;
    return $data;
}

/** Buat transaksi Snap, kembalikan token. */
function midtransCreateSnapToken(array $payload): string
{
    $res = midtransRequest('POST', midtransSnapApi(), $payload);
    if (($res['_http'] ?? 500) >= 400 || empty($res['token'])) {
        $msg = isset($res['error_messages']) ? implode('; ', (array) $res['error_messages']) : 'HTTP ' . ($res['_http'] ?? '?');
        throw new RuntimeException('Snap gagal dibuat: ' . $msg);
    }
    return (string) $res['token'];
}

/** Ambil status transaksi langsung dari Midtrans (dipakai sebagai cadangan webhook). */
function midtransFetchStatus(string $orderId): array
{
    return midtransRequest('GET', midtransCoreApi() . '/' . rawurlencode($orderId) . '/status');
}

/** signature_key = sha512(order_id + status_code + gross_amount + server_key) */
function midtransSignatureOk(array $n): bool
{
    foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $k) {
        if (!isset($n[$k]) || !is_scalar($n[$k])) {
            return false;
        }
    }
    $expected = hash('sha512', $n['order_id'] . $n['status_code'] . $n['gross_amount'] . MIDTRANS_SERVER_KEY);
    return hash_equals($expected, strtolower((string) $n['signature_key']));
}

function midtransMethodLabel(string $type): string
{
    return match ($type) {
        'qris'                         => 'QRIS',
        'gopay', 'shopeepay'           => 'E-Wallet',
        'bank_transfer', 'echannel'    => 'Virtual Account',
        'credit_card'                  => 'Kartu Kredit',
        'cstore'                       => 'Gerai Retail',
        default                        => 'Midtrans',
    };
}

/**
 * Terapkan status dari notifikasi / status API ke tabel pembayaran & tagihan.
 * Aman dipanggil berulang (idempotent). Mengembalikan: success | pending | failed | unknown.
 */
function midtransApply(mysqli $conn, array $n): string
{
    $orderId = (string) ($n['order_id'] ?? '');
    $ts      = (string) ($n['transaction_status'] ?? '');
    $fraud   = (string) ($n['fraud_status'] ?? '');
    $type    = (string) ($n['payment_type'] ?? '');
    if ($orderId === '' || $ts === '') {
        throw new InvalidArgumentException('Notifikasi tidak lengkap.');
    }

    if ($ts === 'settlement' || ($ts === 'capture' && $fraud === 'accept') || ($ts === 'capture' && $fraud === '')) {
        $status = 'success';
    } elseif ($ts === 'pending' || ($ts === 'capture' && $fraud === 'challenge')) {
        $status = 'pending';
    } elseif (in_array($ts, ['deny', 'cancel', 'expire', 'failure'], true)) {
        $status = 'failed';
    } else {
        $status = null; // refund/chargeback dll: hanya dicatat, status tidak diubah
    }

    $conn->begin_transaction();
    try {
        $st = $conn->prepare('SELECT id, id_tagihan, jumlah, status, metode_pembayaran FROM pembayaran WHERE midtrans_order_id = ? LIMIT 1 FOR UPDATE');
        $st->bind_param('s', $orderId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row) {
            $conn->rollback();
            return 'unknown';
        }

        if (isset($n['gross_amount'])
            && (int) round((float) $n['gross_amount']) !== (int) round((float) $row['jumlah'])) {
            throw new RuntimeException('Nominal tidak cocok untuk order ' . $orderId);
        }

        // Pembayaran yang sudah sukses tidak boleh diturunkan oleh notifikasi susulan.
        if ($row['status'] === 'success') {
            $conn->commit();
            return 'success';
        }

        $pid     = (int) $row['id'];
        $tid     = (int) $row['id_tagihan'];
        $newStat = $status ?? (string) $row['status'];
        $metode  = $type !== '' ? midtransMethodLabel($type) : (string) $row['metode_pembayaran'];

        if ($newStat === 'success') {
            $settle = (string) ($n['settlement_time'] ?? '');
            $paidAt = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $settle) ? $settle : date('Y-m-d H:i:s');

            $st = $conn->prepare('UPDATE pembayaran SET status = ?, transaction_status = ?, metode_pembayaran = ?, tanggal_bayar = ? WHERE id = ? LIMIT 1');
            $st->bind_param('ssssi', $newStat, $ts, $metode, $paidAt, $pid);
            $st->execute();
            $st->close();

            $st = $conn->prepare("UPDATE tagihan SET status = 'paid', paid_at = NOW() WHERE id = ? AND status <> 'paid' LIMIT 1");
            $st->bind_param('i', $tid);
            $st->execute();
            $st->close();
        } else {
            $st = $conn->prepare('UPDATE pembayaran SET status = ?, transaction_status = ?, metode_pembayaran = ? WHERE id = ? LIMIT 1');
            $st->bind_param('sssi', $newStat, $ts, $metode, $pid);
            $st->execute();
            $st->close();
        }

        $conn->commit();
        return $newStat;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}