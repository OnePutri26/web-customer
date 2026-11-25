<?php
/*
 * MODE DEBUG
 * true  = error PHP dan pesan error asli dari ensureOrder() ditampilkan di halaman.
 * false = pelanggan hanya melihat pesan umum (WAJIB false di server produksi).
 * Isi error lengkap selalu dicatat ke log error PHP, apa pun nilainya.
 * Set false setelah order berhasil dan sebelum dipakai pelanggan sungguhan.
 */
$orderDebug = true;

if ($orderDebug) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);

    // Ubah error fatal (penyebab HTTP 500) menjadi pesan yang terbaca.
    register_shutdown_function(static function () {
        $err = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR];
        if ($err && in_array($err['type'], $fatal, true)) {
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<pre style="background:#fee;padding:12px;white-space:pre-wrap">'
                . htmlspecialchars('FATAL: ' . $err['message']
                . ' @ ' . basename($err['file']) . ':' . $err['line'])
                . '</pre>';
        }
    });
}

require_once __DIR__ . '/config/app.php';

/*
 * Paket hanya boleh dipesan jika statusnya aktif (aturan yang sama dengan paket.php).
 */
if (!function_exists('paketBisaDibeli')) {
    function paketBisaDibeli(array $paket): bool
    {
        if (!array_key_exists('status', $paket)) {
            return true;
        }
        $status = strtolower(trim((string) ($paket['status'] ?? '')));
        return in_array($status, ['aktif', 'active', 'tersedia', 'available', '1'], true);
    }
}

if (!isCustomer()) {
    redirect('login.php');
}
if (!flow('verified') || !flow('paket_id') || !flow('alamat')) {
    redirect('verifikasi.php');
}
$cust = currentCustomer($conn);
$p = getPaket($conn, (int) flow('paket_id'));
if (!$cust || !$p) {
    redirect('paket.php?mode=coverage');
}
if (!paketBisaDibeli($p)) {
    redirect('paket.php?mode=coverage');
}
if (isAktif($cust['status_langganan'])) {
    redirect('customer/dashboard.php');
}

$pricing = packagePriceDetails($p);

/* Kode promo dari session flow (kunci "promo", cadangan "promo_code"). */
$promo = flowPromo();

$promoNotice = '';
$monthlyRate = 0.0;
$promoDetails = [];

for ($attempt = 0; $attempt < 2; $attempt++) {
    $monthlyRate = (float) (
        $promo !== ''
            ? $pricing['harga_normal']
            : $pricing['harga_akhir']
    );

    try {
        $promoDetails = subscriptionPromoDetails($monthlyRate, $promo);
        break;
    } catch (Throwable $e) {
        error_log('order.php promo tidak valid [' . $promo . ']: ' . $e->getMessage());

        if ($promo === '') {
            // Harga paket sendiri tidak valid: kembali ke pilihan paket.
            redirect('paket.php?mode=coverage');
        }

        // Kode promo tidak dikenal: pakai harga bulanan biasa.
        $promoNotice = 'Kode promo tidak dikenali, order dihitung dengan harga bulanan biasa.';
        flowSet(['promo' => '', 'promo_code' => '']);
        $promo = '';
    }
}

$totalBayar = (float) ($promoDetails['total_bayar'] ?? $monthlyRate);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman.';
    } else {
        try {
            $tagihanId = ensureOrder($conn, $cust, $p, (string) flow('alamat'));
            redirect('pembayaran.php?tagihan=' . $tagihanId);
        } catch (Throwable $e) {
            error_log(
                'Gagal membuat order: ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine()
                . "\n" . $e->getTraceAsString()
            );
            $error = 'Order gagal dibuat. Silakan coba lagi.';
            if ($orderDebug) {
                $error .= ' [DEBUG] ' . get_class($e) . ': ' . $e->getMessage()
                    . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
        }
    }
}

$pageTitle = 'Order Berlangganan';
$step = 3;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter"><h1>Order Berlangganan</h1><p>Periksa ringkasan order Anda.</p></div>
<div class="card enter">
    <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($promoNotice !== ''): ?><div class="alert" role="status"><?= e($promoNotice) ?></div><?php endif; ?>
    <div class="sum"><span>Paket</span><b><?= e($p['nama_paket']) ?> · <?= (int) $p['speed_mbps'] ?> Mbps</b></div>
    <?php if ($promo !== ''): ?>
    <div class="sum"><span>Harga paket per bulan</span><b><?= rupiah($monthlyRate) ?></b></div>
    <div class="sum"><span>Promo</span><b><?= e($promoDetails['nama'] ?? $promo) ?></b></div>
    <div class="sum"><span>Masa berlangganan</span><b><?= (int) ($promoDetails['total_bulan'] ?? 0) ?> bulan</b></div>
    <div class="sum"><span>Bulan gratis</span><b><?= (int) ($promoDetails['bonus_bulan'] ?? 0) ?> bulan</b></div>
    <div class="sum"><span>Harga normal</span><b><?= rupiah($promoDetails['harga_normal'] ?? $monthlyRate) ?></b></div>
    <div class="sum"><span>Total hemat (<?= e(number_format((float) ($promoDetails['persentase_hemat'] ?? 0), 2, ',', '.')) ?>%)</span><b>-<?= rupiah($promoDetails['hemat'] ?? 0) ?></b></div>
    <?php else: ?>
    <?php if (!empty($pricing['promo_aktif'])): ?>
    <div class="sum"><span>Harga normal</span><b><del><?= rupiah($pricing['harga_normal']) ?></del></b></div>
    <div class="sum"><span>Diskon promo</span><b>-<?= rupiah($pricing['diskon']) ?></b></div>
    <?php endif; ?>
    <div class="sum"><span>Biaya bulanan</span><b><?= rupiah($monthlyRate) ?></b></div>
    <?php endif; ?>
    <div class="sum"><span>Biaya instalasi</span><b>Gratis</b></div>
    <div class="sum"><span>Alamat pemasangan</span><b><?= e((string) flow('alamat')) ?></b></div>
    <div class="sum"><span><?= $promo !== '' ? 'Total pembayaran ' . (int) ($promoDetails['bayar_bulan'] ?? 0) . ' bulan' : 'Total pembayaran' ?></span><b style="font-size:18px;color:var(--blue)"><?= rupiah($totalBayar) ?></b></div>
    <form method="post" id="orderForm">
        <?= csrf_field() ?>
        <button class="btn btn-primary btn-block" type="submit" id="orderBtn" style="margin-top:18px">Buat Order &amp; Bayar</button>
    </form>
    <script>
    /* Cegah klik ganda: tombol dikunci setelah form dikirim sekali. */
    (function () {
        var form = document.getElementById('orderForm');
        var btn = document.getElementById('orderBtn');
        var sent = false;
        form.addEventListener('submit', function (ev) {
            if (sent) { ev.preventDefault(); return; }
            sent = true;
            btn.disabled = true;
            btn.textContent = 'Memproses…';
        });
    })();
    </script>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>