<?php
require_once __DIR__ . '/config/app.php';

/*
| Masuk lewat:
|   pembayaran.php?tagihan=ID  -> alur coverage (order sudah dibuat) atau "Bayar Lagi"
|   pembayaran.php?paket=ID    -> alur "Lihat Paket" (order dibuat otomatis di sini)
*/
if (!isCustomer()) {
    if (isset($_GET['paket'])) {
        flowSet(['mode' => 'direct', 'paket_id' => (int) $_GET['paket']]);
    }
    redirect('login.php');
}
$cust = currentCustomer($conn);
if (!$cust) {
    redirect('login.php');
}
if (isAktif($cust['status_langganan'])) {
    redirect('customer/dashboard.php');
}

if (isset($_GET['paket'])) {
    $p = getPaket($conn, (int) $_GET['paket']);
    if (!$p) {
        redirect('paket.php?mode=direct');
    }
    $alamat = (string) (flow('alamat') ?: $cust['alamat']);
    try {
        $tid = ensureOrder($conn, $cust, $p, $alamat);
    } catch (Throwable $e) {
        redirect('paket.php?mode=direct');
    }
    redirect('pembayaran.php?tagihan=' . $tid);
}

$tid = (int) ($_GET['tagihan'] ?? 0);
$cid = (int) $cust['id'];
$st = $conn->prepare("
    SELECT t.*, p.nama_paket, p.speed_mbps
    FROM tagihan t
    LEFT JOIN subscription s ON s.id = t.id_subscription
    LEFT JOIN paket_wifi p ON p.id = s.paket_id
    WHERE t.id = ? AND t.id_customer = ? LIMIT 1
");
$st->bind_param('ii', $tid, $cid);
$st->execute();
$tg = $st->get_result()->fetch_assoc();
$st->close();
if (!$tg) {
    redirect('paket.php?mode=direct');
}
if ($tg['status'] === 'paid') {
    redirect('status_order.php');
}

$metode = ['QRIS', 'Virtual Account', 'E-Wallet', 'Transfer Bank'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pilih = (string) ($_POST['metode'] ?? '');
    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman.';
    } elseif (!in_array($pilih, $metode, true)) {
        $error = 'Pilih metode pembayaran.';
    } else {
        /*
        | MODE DEMO: hasil ditentukan dari pilihan "Simulasi hasil".
        | Untuk Midtrans: buat Snap token di sini, simpan midtrans_order_id,
        | dan biarkan customer/payment_webhook.php yang mengubah status.
        */
        $berhasil = !PAYMENT_DEMO || (($_POST['hasil'] ?? 'berhasil') === 'berhasil');
        $statusBayar = $berhasil ? 'success' : 'failed';
        $trx = $berhasil ? 'settlement' : 'deny';
        $noBayar = nomor('PAY');
        $jumlah = (float) $tg['jumlah'];
        $tglBayar = $berhasil ? date('Y-m-d H:i:s') : null;

        try {
            $conn->begin_transaction();
            $st = $conn->prepare("
                INSERT INTO pembayaran
                (id_customer, id_tagihan, nomor_pembayaran, jumlah, metode_pembayaran, status, transaction_status, tanggal_bayar)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->bind_param('iisdssss', $cid, $tid, $noBayar, $jumlah, $pilih, $statusBayar, $trx, $tglBayar);
            $st->execute();
            $bayarId = (int) $conn->insert_id;
            $st->close();

            if ($berhasil) {
                $st = $conn->prepare("UPDATE tagihan SET status = 'paid', paid_at = NOW() WHERE id = ? LIMIT 1");
                $st->bind_param('i', $tid);
                $st->execute();
                $st->close();
            }
            $conn->commit();
            redirect('hasil_pembayaran.php?id=' . $bayarId);
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Pembayaran gagal diproses. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Pembayaran';
$step = 3;
$narrow = true;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter"><h1>Pembayaran</h1><p>Selesaikan pembayaran untuk memulai proses pemasangan.</p></div>
<div class="card enter">
    <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="sum"><span>No. tagihan</span><b><?= e($tg['nomor_tagihan']) ?></b></div>
    <div class="sum"><span>Paket</span><b><?= e($tg['nama_paket']) ?> · <?= (int) $tg['speed_mbps'] ?> Mbps</b></div>
    <div class="sum"><span>Jatuh tempo</span><b><?= e(date('d M Y', strtotime($tg['jatuh_tempo']))) ?></b></div>
    <div class="sum"><span>Total</span><b style="font-size:18px;color:var(--blue)"><?= rupiah($tg['jumlah']) ?></b></div>

    <form method="post" id="payForm">
        <?= csrf_field() ?>
        <label>Metode pembayaran</label>
        <div class="pay-grid">
            <?php foreach ($metode as $i => $m): ?>
                <label class="pay-opt"><input type="radio" name="metode" value="<?= e($m) ?>" <?= $i === 0 ? 'checked' : '' ?>><?= e($m) ?></label>
            <?php endforeach; ?>
        </div>
        <?php if (PAYMENT_DEMO): ?>
            <label>Simulasi hasil (mode demo)</label>
            <div class="pay-grid">
                <label class="pay-opt"><input type="radio" name="hasil" value="berhasil" checked>Berhasil</label>
                <label class="pay-opt"><input type="radio" name="hasil" value="gagal">Gagal</label>
            </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-block" type="submit" style="margin-top:22px">Bayar <?= rupiah($tg['jumlah']) ?></button>
    </form>
</div>
<script>
document.getElementById('payForm').addEventListener('submit',function(){
  var b=this.querySelector('button[type=submit]');
  setTimeout(function(){b.disabled=true;b.classList.add('loading');},0);
});
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
