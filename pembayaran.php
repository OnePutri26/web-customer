<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/xendit.php';

if (!isCustomer()) {
    redirect('login.php');
}

$cust = currentCustomer($conn);
if (!$cust) {
    redirect('login.php');
}
$customerId = (int) $cust['id'];
$tagihanId = filter_input(INPUT_GET, 'tagihan', FILTER_VALIDATE_INT);
$error = '';
$payment = null;
$tagihan = null;

if (!$tagihanId && isset($_GET['paket'])) {
    $packageId = filter_input(INPUT_GET, 'paket', FILTER_VALIDATE_INT);
    $package = $packageId ? getPaket($conn, (int) $packageId) : null;
    $address = trim((string) flow('alamat', $cust['alamat'] ?? ''));
    if (!$package || $address === '' || isAktif((string) ($cust['status_langganan'] ?? ''))) {
        redirect('paket.php');
    }

    try {
        $tagihanId = ensureOrder($conn, $cust, $package, $address);
    } catch (Throwable $e) {
        error_log('Gagal membuat tagihan dari pembayaran: ' . $e->getMessage());
        $error = 'Tagihan tidak dapat dibuat saat ini. Silakan coba kembali.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tagihanId) {
    // Metode pembayaran yang dipilih pelanggan menentukan biaya layanan.
    $feeOption = xenditFeeOption((string) ($_POST['metode'] ?? ''));

    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman sebelum melanjutkan.';
    } elseif ($feeOption === null) {
        $error = 'Silakan pilih metode pembayaran terlebih dahulu.';
    } elseif (!xenditReady()) {
        error_log('Xendit payment unavailable: payment credentials, webhook token, HTTPS app URL, or cURL are missing.');
        $error = 'Pembayaran online belum dikonfigurasi. Silakan hubungi administrator.';
    } else {
        $transactionOpen = false;
        $paymentId = 0;
        try {
            $conn->begin_transaction();
            $transactionOpen = true;
            $stmt = $conn->prepare(
                'SELECT id, id_customer, nomor_tagihan, jumlah, status
                 FROM tagihan
                 WHERE id = ? AND id_customer = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->bind_param('ii', $tagihanId, $customerId);
            $stmt->execute();
            $tagihan = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$tagihan) {
                throw new RuntimeException('Tagihan tidak ditemukan.');
            }
            if (!in_array(strtolower((string) $tagihan['status']), ['unpaid', 'pending'], true)) {
                throw new RuntimeException('Tagihan ini tidak lagi dapat dibayar.');
            }

            // Jika sudah ada invoice Xendit yang masih pending, arahkan ke invoice itu
            $stmt = $conn->prepare(
                "SELECT id, xendit_external_id, xendit_invoice_id, xendit_invoice_url, status
                 FROM pembayaran
                 WHERE id_tagihan = ? AND payment_provider = 'xendit'
                   AND status = 'pending'
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->bind_param('i', $tagihanId);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($payment) {
                $conn->commit();
                $transactionOpen = false;
                if (!empty($payment['xendit_invoice_url'])) {
                    redirect((string) $payment['xendit_invoice_url']);
                }
                throw new RuntimeException('Invoice Xendit untuk tagihan ini sedang diproses.');
            }

            $amount = (float) $tagihan['jumlah'];   // nominal tagihan (tanpa biaya layanan)
            if (
                !is_finite($amount)
                || $amount <= 0
                || $amount > PHP_INT_MAX / 100
                || $amount !== (float) round($amount)
            ) {
                throw new RuntimeException('Nominal tagihan tidak valid.');
            }

            // Biaya layanan dihitung dari metode pembayaran yang dipilih:
            // persen untuk 7 metode tertentu, flat Rp 5.000 untuk metode lainnya.
            // Aturannya ada di xenditFeeOptions() pada config/xendit.php.
            $fee = xenditCustomerFee((int) round($amount), $feeOption);
            $total = $amount + $fee;

            $externalId = 'YESNET-C' . $customerId . '-' . strtoupper(bin2hex(random_bytes(16)));
            $paymentNumber = 'PAY-' . strtoupper(bin2hex(random_bytes(8)));
            $method = 'Xendit ' . $feeOption['label'];
            $pending = 'PENDING';

            // jumlah = tagihan + biaya layanan; biaya_layanan disimpan terpisah untuk pembukuan
            $stmt = $conn->prepare(
                "INSERT INTO pembayaran
                    (id_tagihan, id_customer, nomor_pembayaran, jumlah, biaya_layanan,
                     metode_pembayaran, status, transaction_status,
                     payment_provider, payment_gateway, xendit_external_id)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 'xendit', 'xendit', ?)"
            );
            $stmt->bind_param(
                'iisddsss',
                $tagihanId,
                $customerId,
                $paymentNumber,
                $total,
                $fee,
                $method,
                $pending,
                $externalId
            );
            $stmt->execute();
            $paymentId = (int) $conn->insert_id;
            $stmt->close();

            $baseUrl = xenditConfig()['app_url'];
            $invoicePayload = [
                'external_id' => $externalId,
                'amount' => (int) round($total),
                'currency' => 'IDR',
                'description' => 'Pembayaran tagihan ' . (string) $tagihan['nomor_tagihan'],
                'invoice_duration' => 259200,
                // Kunci invoice ke metode yang dipilih agar fee sesuai dengan metode yang dipakai.
                'payment_methods' => $feeOption['channels'],
                'success_redirect_url' => $baseUrl . '/hasil_pembayaran.php?id=' . $paymentId,
                'failure_redirect_url' => $baseUrl . '/hasil_pembayaran.php?id=' . $paymentId,
                'items' => [[
                    'name' => 'Tagihan ' . (string) $tagihan['nomor_tagihan'],
                    'quantity' => 1,
                    'price' => (int) round($amount),
                ]],
            ];
            if ($fee > 0) {
                $invoicePayload['fees'] = [[
                    'type' => 'Biaya Layanan',
                    'value' => (int) $fee,
                ]];
            }
            $payerEmail = filter_var((string) ($cust['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            if (is_string($payerEmail)) {
                $invoicePayload['payer_email'] = $payerEmail;
            }
            $invoice = xenditCreateInvoice($invoicePayload);

            $invoiceId = trim((string) ($invoice['id'] ?? ''));
            $invoiceUrl = trim((string) ($invoice['invoice_url'] ?? ''));
            if (
                $invoiceId === ''
                || (string) ($invoice['external_id'] ?? '') !== $externalId
                || filter_var($invoiceUrl, FILTER_VALIDATE_URL) === false
                || strtolower((string) parse_url($invoiceUrl, PHP_URL_SCHEME)) !== 'https'
            ) {
                throw new RuntimeException('Respons invoice Xendit tidak lengkap atau tidak valid.');
            }

            $stmt = $conn->prepare(
                "UPDATE pembayaran
                 SET xendit_invoice_id = ?, xendit_invoice_url = ?,
                     transaction_status = 'PENDING'
                 WHERE id = ? AND xendit_external_id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('ssis', $invoiceId, $invoiceUrl, $paymentId, $externalId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $transactionOpen = false;
            redirect($invoiceUrl);
        } catch (Throwable $e) {
            if ($transactionOpen) {
                try {
                    if ($paymentId > 0) {
                        $stmt = $conn->prepare(
                            "UPDATE pembayaran
                             SET status = 'failed', transaction_status = 'INVOICE_ERROR'
                             WHERE id = ? AND status = 'pending'
                             LIMIT 1"
                        );
                        $stmt->bind_param('i', $paymentId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $conn->commit();
                } catch (Throwable $persistError) {
                    $conn->rollback();
                    error_log('Gagal menyimpan status pembuatan invoice: ' . $persistError->getMessage());
                }
            }
            error_log('Gagal membuat invoice Xendit: ' . $e->getMessage());
            $error = 'Invoice pembayaran belum berhasil dibuat. Silakan coba kembali.';
        }
    }
}

if ($tagihanId && !$tagihan) {
    $stmt = $conn->prepare(
        'SELECT id, id_customer, nomor_tagihan, jumlah, status
         FROM tagihan
         WHERE id = ? AND id_customer = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $tagihanId, $customerId);
    $stmt->execute();
    $tagihan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$tagihan && $error === '') {
    redirect('paket.php?mode=direct');
}

if ($tagihan) {
    $stmt = $conn->prepare(
        'SELECT id, status, payment_provider, xendit_invoice_url, jumlah, biaya_layanan
         FROM pembayaran
         WHERE id_tagihan = ? AND id_customer = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->bind_param('ii', $tagihanId, $customerId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (strtolower((string) $tagihan['status']) === 'paid' && $payment) {
        redirect('hasil_pembayaran.php?id=' . (int) $payment['id']);
    }
}

// Tombol "Lanjutkan pembayaran" hanya muncul jika invoice terakhir masih pending.
// Jika sudah expired/failed, pelanggan bisa membuat invoice baru.
$hasPendingInvoice = $payment
    && ($payment['payment_provider'] ?? '') === 'xendit'
    && strtolower((string) ($payment['status'] ?? '')) === 'pending'
    && !empty($payment['xendit_invoice_url']);

// Rincian yang ditampilkan ke pelanggan.
// Jika sudah ada invoice pending, pakai angka yang tersimpan di invoice tersebut.
// Jika belum, biaya layanan dihitung di sisi browser sesuai metode yang dipilih.
$tagihanTampil = $tagihan ? (float) $tagihan['jumlah'] : 0.0;
$feeOptions = xenditFeeOptions();
if ($hasPendingInvoice) {
    $feeTampil = (float) ($payment['biaya_layanan'] ?? 0);
    $totalTampil = (float) ($payment['jumlah'] ?? ($tagihanTampil + $feeTampil));
} else {
    $feeTampil = 0.0;
    $totalTampil = $tagihanTampil;
}

// Pertahankan pilihan metode jika halaman dimuat ulang setelah error.
$selectedMethod = (string) ($_POST['metode'] ?? '');
if (!isset($feeOptions[$selectedMethod])) {
    $selectedMethod = (string) array_key_first($feeOptions);
}

$pageTitle = 'Pembayaran';
$step = 3;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter">
    <h1>Pembayaran</h1>
    <p>Pilih metode pembayaran, lalu bayar tagihan secara aman melalui halaman pembayaran Xendit.</p>
</div>
<div class="card enter">
    <?php if ($error !== ''): ?>
        <div class="alert err" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($tagihan): ?>
        <div class="sum"><span>No. tagihan</span><b><?= e($tagihan['nomor_tagihan']) ?></b></div>
        <div class="sum"><span>Tagihan</span><b><?= rupiah($tagihanTampil) ?></b></div>

        <?php if ($hasPendingInvoice): ?>
            <?php if ($feeTampil > 0): ?>
                <div class="sum"><span>Biaya layanan pembayaran</span><b><?= rupiah($feeTampil) ?></b></div>
            <?php endif; ?>
            <div class="sum"><span>Total pembayaran</span><b><?= rupiah($totalTampil) ?></b></div>
            <div class="actions">
                <a class="btn btn-primary" href="<?= e($payment['xendit_invoice_url']) ?>">Lanjutkan pembayaran di Xendit</a>
            </div>

        <?php elseif (strtolower((string) $tagihan['status']) !== 'paid'): ?>
            <form method="post" action="pembayaran.php?tagihan=<?= (int) $tagihanId ?>">
                <?= csrf_field() ?>
                <p style="margin:12px 0 6px"><b>Pilih metode pembayaran</b></p>
                <?php foreach ($feeOptions as $key => $opt):
                    $optFee = xenditCustomerFee((int) round($tagihanTampil), $opt); ?>
                    <label class="sum" style="cursor:pointer">
                        <span>
                            <input type="radio" name="metode" value="<?= e($key) ?>"
                                   data-fee="<?= (int) $optFee ?>"
                                   <?= $key === $selectedMethod ? 'checked' : '' ?>>
                            <?= e($opt['label']) ?>
                        </span>
                        <b>+ <?= rupiah($optFee) ?></b>
                    </label>
                <?php endforeach; ?>

                <div class="sum"><span>Biaya layanan pembayaran</span><b id="fee-view"></b></div>
                <div class="sum"><span>Total pembayaran</span><b id="total-view"></b></div>
                <p class="muted" style="font-size:13px;margin:8px 0 16px">Biaya layanan mengikuti metode pembayaran yang dipilih. Invoice hanya akan menampilkan metode tersebut.</p>
                <button class="btn btn-primary btn-block" type="submit">Buat invoice Xendit</button>
            </form>
            <script>
            (function () {
                var bill = <?= (int) round($tagihanTampil) ?>;
                var fmt = function (n) { return 'Rp ' + n.toLocaleString('id-ID'); };
                function update() {
                    var r = document.querySelector('input[name="metode"]:checked');
                    var fee = r ? parseInt(r.dataset.fee, 10) : 0;
                    document.getElementById('fee-view').textContent = fmt(fee);
                    document.getElementById('total-view').textContent = fmt(bill + fee);
                }
                document.querySelectorAll('input[name="metode"]').forEach(function (el) {
                    el.addEventListener('change', update);
                });
                update();
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>