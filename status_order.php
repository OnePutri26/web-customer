<?php
require_once __DIR__ . '/config/app.php';

if (!isCustomer()) {
    redirect('login.php');
}
$cust = currentCustomer($conn);
if (!$cust) {
    redirect('login.php');
}
$cid = (int) $cust['id'];

function satuBaris(mysqli $conn, string $sql, int $id): ?array
{
    $st = $conn->prepare($sql);
    $st->bind_param('i', $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

$sub = satuBaris($conn, 'SELECT * FROM subscription WHERE id_customer = ? ORDER BY id DESC LIMIT 1', $cid);
if (!$sub) {
    redirect('paket.php?mode=direct');
}
$tg  = satuBaris($conn, 'SELECT * FROM tagihan WHERE id_subscription = ' . (int) $sub['id'] . ' AND id_customer = ? ORDER BY id DESC LIMIT 1', $cid);
$pg  = satuBaris($conn, 'SELECT * FROM pengajuan_pemasangan WHERE id_customer = ? ORDER BY id DESC LIMIT 1', $cid);
$ins = $pg ? satuBaris($conn, 'SELECT * FROM instalasi WHERE id_pengajuan = ' . (int) $pg['id'] . ' AND id_customer = ? ORDER BY id DESC LIMIT 1', $cid) : null;
$paket = $conn->query('SELECT * FROM paket_wifi WHERE id = ' . (int) $sub['paket_id'] . ' LIMIT 1')->fetch_assoc() ?: null;

/*
| Jumlah tahap yang sudah selesai (0-6), dibaca dari database:
| 1 survey/validasi   -> pengajuan_pemasangan.status = approved
| 2 jadwal instalasi  -> pengajuan_pemasangan.status = scheduled (+ baris instalasi)
| 3 teknisi datang    -> instalasi.status = proses
| 4 instalasi selesai -> pengajuan_pemasangan.status = installed
| 5 aktivasi          -> subscription.status = active
| 6 customer aktif    -> customers.status_langganan = active
*/
$dibayar = $tg && $tg['status'] === 'paid';
$done = 0;
if ($pg) {
    if ($pg['status'] === 'approved') {
        $done = 1;
    } elseif ($pg['status'] === 'scheduled') {
        $done = ($ins && $ins['status'] === 'proses') ? 3 : 2;
    } elseif ($pg['status'] === 'installed') {
        $done = 4;
    }
}
if ($done >= 4 && $sub['status'] === 'active') {
    $done = 5;
}
if ($done >= 5 && isAktif($cust['status_langganan'])) {
    $done = 6;
}

/* Simulasi tahap berikutnya (hanya MODE DEMO). Di produksi dilakukan admin & teknisi. */
$error = '';
if (PAYMENT_DEMO && $dibayar && $done < 6 && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    try {
        $pid = (int) $pg['id'];
        if ($done === 0) {
            $conn->query("UPDATE pengajuan_pemasangan SET status = 'approved' WHERE id = $pid");
        } elseif ($done === 1) {
            $jadwal = date('Y-m-d 09:00:00', strtotime('+1 day'));
            $tek = 'Teknisi YesNet';
            $r = $conn->query("SELECT nama FROM technicians WHERE status IN ('tersedia','bertugas') ORDER BY status = 'tersedia' DESC, id ASC LIMIT 1")->fetch_assoc();
            if ($r) {
                $tek = $r['nama'];
            }
            $alamat = (string) $pg['alamat_pemasangan'];
            $conn->begin_transaction();
            $st = $conn->prepare("UPDATE pengajuan_pemasangan SET status = 'scheduled', jadwal_pemasangan = ? WHERE id = ?");
            $st->bind_param('si', $jadwal, $pid);
            $st->execute();
            $st->close();
            $st = $conn->prepare("INSERT INTO instalasi (id_customer, id_pengajuan, teknisi, tanggal_jadwal, status, alamat) VALUES (?, ?, ?, ?, 'dijadwalkan', ?)");
            $st->bind_param('iisss', $cid, $pid, $tek, $jadwal, $alamat);
            $st->execute();
            $st->close();
            $conn->commit();
        } elseif ($done === 2) {
            $conn->query("UPDATE instalasi SET status = 'proses' WHERE id_pengajuan = $pid");
        } elseif ($done === 3) {
            $conn->begin_transaction();
            $conn->query("UPDATE instalasi SET status = 'selesai', tanggal_pemasangan = NOW() WHERE id_pengajuan = $pid");
            $conn->query("UPDATE pengajuan_pemasangan SET status = 'installed' WHERE id = $pid");
            $conn->commit();
        } elseif ($done === 4) {
            $sid = (int) $sub['id'];
            $conn->query("UPDATE subscription SET status = 'active', tanggal_mulai = CURDATE(), tanggal_berakhir = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = $sid");
        } elseif ($done === 5) {
            $conn->query("UPDATE customers SET status_langganan = 'active', tgl_mulai = NOW() WHERE id = $cid");
            $_SESSION['subscription_status'] = 'aktif';
        }
        redirect('status_order.php');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $x) {}
        $error = 'Tahap gagal diperbarui.';
    }
}

$tgl = fn($d) => $d ? date('d M Y, H.i', strtotime($d)) : null;
$tahap = [
    ['Survey & validasi', 'Tim memvalidasi lokasi dan data Anda' . ($pg ? ' · ' . $pg['nomor_pengajuan'] : '')],
    ['Jadwal instalasi', $pg && $pg['jadwal_pemasangan'] ? $tgl($pg['jadwal_pemasangan']) : 'Jadwal ditentukan setelah survey'],
    ['Teknisi datang', $ins && $ins['teknisi'] ? 'Teknisi: ' . $ins['teknisi'] : 'Teknisi tiba di lokasi Anda'],
    ['Instalasi selesai', $ins && $ins['tanggal_pemasangan'] ? $tgl($ins['tanggal_pemasangan']) : 'Kabel dan router terpasang'],
    ['Aktivasi customer', 'Akun dan paket diaktifkan'],
    ['Customer aktif', 'Internet siap digunakan'],
];

$pageTitle = 'Status Order';
$step = $done >= 6 ? 5 : 4;
require __DIR__ . '/inc/header.php';
?>
<div class="page-head enter">
    <h1>Status Order</h1>
    <p><?= e($paket['nama_paket'] ?? 'Paket') ?> · <?= (int) ($paket['speed_mbps'] ?? 0) ?> Mbps<?= $pg ? ' · ' . e($pg['alamat_pemasangan']) : '' ?></p>
</div>
<div class="card enter">
    <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$dibayar): ?>
        <div class="alert warn">Pembayaran belum selesai. Selesaikan pembayaran agar order diproses.</div>
        <div class="actions"><a class="btn btn-primary" href="pembayaran.php?tagihan=<?= (int) ($tg['id'] ?? 0) ?>">Bayar Sekarang</a></div>
    <?php else: ?>
        <ol class="timeline">
            <?php foreach ($tahap as $i => $t): ?>
                <li class="<?= $i < $done ? 'done' : ($i === $done ? 'now' : '') ?>">
                    <div><b><?= e($t[0]) ?></b><small><?= e($t[1]) ?></small></div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($done >= 6): ?>
            <div class="actions"><a class="btn btn-primary" href="customer/dashboard.php">Masuk Dashboard</a></div>
        <?php elseif (PAYMENT_DEMO): ?>
            <form method="post" class="actions">
                <?= csrf_field() ?>
                <button class="btn btn-outline" type="submit">Simulasi: lanjut ke tahap berikutnya</button>
            </form>
            <p class="muted center" style="font-size:12.5px;margin-top:10px">Mode demo. Pada produksi, tahap ini diperbarui oleh admin dan teknisi.</p>
        <?php else: ?>
            <p class="muted center" style="font-size:13px">Halaman ini diperbarui otomatis saat tim kami memproses order Anda.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($dibayar && $done < 6 && !PAYMENT_DEMO): ?>
<script>setTimeout(function(){location.reload()},30000);</script>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
