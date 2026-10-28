<?php
declare(strict_types=1);

function xenditConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    // Fallback: baca dari file config/xendit.local.php jika env var kosong
    $local = [];
    $file = __DIR__ . '/xendit.local.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $local = $loaded;
        }
    }

    $pick = static function (string $envName, string $key) use ($local): string {
        $value = getenv($envName);
        if ($value === false || trim($value) === '') {
            $value = $local[$key] ?? '';
        }
        return trim((string) $value);
    };

    return $config = [
        'secret_key'     => $pick('XENDIT_SECRET_KEY', 'secret_key'),
        'callback_token' => $pick('XENDIT_WEBHOOK_TOKEN', 'wALQtlkNUnBpnW4AzVlIkEXdItme9o4CkV76awc0CUWr5DGd'),
        'app_url'        => rtrim($pick('YESNET_APP_URL', 'app_url'), '/'),
    ];
}

function xenditReady(): bool
{
    $config = xenditConfig();
    $appUrl = filter_var($config['app_url'], FILTER_VALIDATE_URL);
    $scheme = is_string($appUrl) ? strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) : '';

    $missing = [];
    if ($config['secret_key'] === '') $missing[] = 'secret_key';
    if ($config['callback_token'] === '') $missing[] = 'callback_token';
    if ($scheme !== 'https') $missing[] = 'app_url(harus https)';
    if (!function_exists('curl_init')) $missing[] = 'ekstensi curl';

    if ($missing) {
        error_log('Xendit belum siap, kurang: ' . implode(', ', $missing));
        return false;
    }
    return true;
}

function xenditRequest(string $method, string $path, ?array $body = null): array
{
    $secretKey = xenditConfig()['secret_key'];
    if ($secretKey === '') {
        throw new RuntimeException('Xendit Secret API Key belum dikonfigurasi.');
    }

    $ch = curl_init('https://api.xendit.co' . $path);
    if ($ch === false) {
        throw new RuntimeException('Koneksi ke Xendit tidak dapat dimulai.');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_USERPWD => $secretKey . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    ];

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            curl_close($ch);
            throw new RuntimeException('Data invoice tidak dapat disiapkan.');
        }
        $options[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Koneksi ke Xendit gagal: ' . $curlError);
    }

    $response = json_decode((string) $raw, true);
    if (!is_array($response)) {
        throw new RuntimeException('Respons Xendit tidak valid (HTTP ' . $httpStatus . ').');
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $message = (string) ($response['message'] ?? $response['error_code'] ?? 'permintaan ditolak');
        throw new RuntimeException('Xendit menolak permintaan (HTTP ' . $httpStatus . '): ' . $message);
    }

    return $response;
}

function xenditCreateInvoice(array $payload): array
{
    return xenditRequest('POST', '/v2/invoices', $payload);
}

function xenditGetInvoice(string $invoiceId): array
{
    if ($invoiceId === '') {
        throw new InvalidArgumentException('ID invoice Xendit tidak valid.');
    }

    return xenditRequest('GET', '/v2/invoices/' . rawurlencode($invoiceId));
}

function xenditWebhookTokenValid(): bool
{
    $expected = xenditConfig()['callback_token'];
    $received = trim((string) ($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? ''));

    return $expected !== ''
        && $received !== ''
        && hash_equals($expected, $received);
}

function xenditMoneyToMinorUnits(mixed $amount): ?int
{
    if (!is_numeric($amount)) {
        return null;
    }

    $value = (float) $amount;
    if (!is_finite($value) || $value < 0 || $value > PHP_INT_MAX / 100) {
        return null;
    }

    return (int) round($value * 100);
}

function xenditLocalStatus(string $providerStatus): string
{
    return match (strtoupper(trim($providerStatus))) {
        'PAID' => 'success',
        'PENDING' => 'pending',
        'EXPIRED' => 'expired',
        'FAILED' => 'failed',
        'VOIDED' => 'cancelled',
        default => 'unknown',
    };
}

function xenditApplyInvoiceStatus(mysqli $conn, array $invoice): string
{
    $invoiceId = trim((string) ($invoice['id'] ?? ''));
    $externalId = trim((string) ($invoice['external_id'] ?? ''));
    $providerStatus = strtoupper(trim((string) ($invoice['status'] ?? '')));

    if ($invoiceId === '' || $externalId === '' || $providerStatus === '') {
        throw new InvalidArgumentException('Data invoice Xendit tidak lengkap.');
    }

    $status = xenditLocalStatus($providerStatus);

    if ($status === 'unknown') {
        return 'unknown';
    }

    $amount = xenditMoneyToMinorUnits($invoice['amount'] ?? null);
    if ($amount === null) {
        throw new RuntimeException('Nominal invoice Xendit tidak valid.');
    }
    if (array_key_exists('paid_amount', $invoice) && $invoice['paid_amount'] !== null) {
        $paidAmount = xenditMoneyToMinorUnits($invoice['paid_amount']);
        if ($status === 'success' && ($paidAmount === null || $paidAmount !== $amount)) {
            throw new RuntimeException('Nominal pembayaran Xendit tidak sesuai dengan invoice.');
        }
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT p.id, p.id_tagihan, p.jumlah, p.status, p.payment_provider,
                    p.xendit_external_id, p.xendit_invoice_id, t.jumlah AS tagihan_jumlah
             FROM pembayaran p
             JOIN tagihan t ON t.id = p.id_tagihan
             WHERE p.xendit_invoice_id = ? OR p.xendit_external_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->bind_param('ss', $invoiceId, $externalId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payment) {
            $conn->rollback();
            return 'unknown';
        }
        if (
            (string) $payment['payment_provider'] !== 'xendit'
            || (string) $payment['xendit_external_id'] !== $externalId
            || (
                !empty($payment['xendit_invoice_id'])
                && (string) $payment['xendit_invoice_id'] !== $invoiceId
            )
        ) {
            throw new RuntimeException('Identitas invoice Xendit tidak cocok dengan transaksi.');
        }

        $storedAmount = xenditMoneyToMinorUnits($payment['jumlah']);
        $billAmount = xenditMoneyToMinorUnits($payment['tagihan_jumlah']);
        if (
            $storedAmount === null
            || $billAmount === null
            || $storedAmount !== $amount
            || $billAmount !== $amount
        ) {
            throw new RuntimeException('Nominal invoice Xendit tidak cocok dengan tagihan.');
        }

        $currentStatus = strtolower((string) $payment['status']);
        if ($currentStatus === 'success') {
            $conn->commit();
            return 'success';
        }

        if (
            in_array($currentStatus, ['expired', 'failed', 'cancelled'], true)
            && $status !== 'success'
        ) {
            $conn->commit();
            return $currentStatus;
        }

        $paymentId = (int) $payment['id'];
        $tagihanId = (int) $payment['id_tagihan'];
        $method = 'Xendit Invoice';

        if ($status === 'success') {
            $paidAt = date('Y-m-d H:i:s');
            foreach (['paid_at', 'updated'] as $dateField) {
                if (!empty($invoice[$dateField])) {
                    $timestamp = strtotime((string) $invoice[$dateField]);
                    if ($timestamp !== false) {
                        $paidAt = date('Y-m-d H:i:s', $timestamp);
                        break;
                    }
                }
            }

            $xenditPaymentId = trim((string) ($invoice['payment_id'] ?? ''));
            if ($xenditPaymentId === '' && isset($invoice['payments'][0]['id'])) {
                $xenditPaymentId = trim((string) $invoice['payments'][0]['id']);
            }

            $stmt = $conn->prepare(
                "UPDATE pembayaran
                 SET status = 'success', payment_provider = 'xendit',
                     transaction_status = ?, metode_pembayaran = ?,
                     xendit_invoice_id = ?,
                     xendit_invoice_url = COALESCE(NULLIF(?, ''), xendit_invoice_url),
                     xendit_payment_id = COALESCE(NULLIF(?, ''), xendit_payment_id),
                     tanggal_bayar = ?
                 WHERE id = ? AND status <> 'success'
                 LIMIT 1"
            );
            $invoiceUrl = trim((string) ($invoice['invoice_url'] ?? ''));
            $stmt->bind_param(
                'ssssssi',
                $providerStatus,
                $method,
                $invoiceId,
                $invoiceUrl,
                $xenditPaymentId,
                $paidAt,
                $paymentId
            );
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "UPDATE tagihan SET status = 'paid', paid_at = NOW()
                 WHERE id = ? AND status <> 'paid'
                 LIMIT 1"
            );
            $stmt->bind_param('i', $tagihanId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                'UPDATE pembayaran
                 SET status = ?, payment_provider = \'xendit\', transaction_status = ?,
                     metode_pembayaran = ?, xendit_invoice_id = ?,
                     xendit_invoice_url = COALESCE(NULLIF(?, \'\'), xendit_invoice_url)
                 WHERE id = ? AND status <> \'success\'
                 LIMIT 1'
            );
            $invoiceUrl = trim((string) ($invoice['invoice_url'] ?? ''));
            $stmt->bind_param(
                'sssssi',
                $status,
                $providerStatus,
                $method,
                $invoiceId,
                $invoiceUrl,
                $paymentId
            );
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        return $status;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
