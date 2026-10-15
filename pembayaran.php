<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/inc/midtrans.php';

/*
| Masuk lewat:
|   pembayaran.php?tagihan=ID  -> alur coverage (order sudah dibuat) atau "Bayar Lagi"
|   pembayaran.php?paket=ID    -> alur "Lihat Paket" (order dibuat otomatis di sini)
|
| Catatan: requireRole('customer') dihapus karena akan me-redirect tamu ke login
| sebelum flowSet() sempat menyimpan paket yang dipilih. Guard manual di bawah
| sudah menangani kasus tersebut.
|
| Mode pembayaran:
|   midtrans : kunci Midtrans terisi  -> pembayaran sungguhan lewat Snap
|   demo     : PAYMENT_DEMO = true    -> simulasi (tanpa kunci Midtrans)
|   off      : selain itu             -> pembayaran dinonaktifkan
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
$mode = midtransReady() ? 'midtrans' : (PAYMENT_DEMO ? 'demo' : 'off');

/*
|--------------------------------------------------------------------------
| Endpoint AJAX (Midtrans): aksi=snap -> buat/ambil token, aksi=sync -> cek status
|--------------------------------------------------------------------------
*/
$aksi = (string) ($_POST['aksi'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($aksi, ['snap', 'sync'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $json = static function (array $data, int $code = 200) {
        http_response_code($code);
        echo json_encode($data);
        exit;
    };

    if (!csrf_ok()) {
        $json(['ok' => false, 'pesan' => 'Sesi kedaluwarsa. Muat ulang halaman.'], 419);
    }
    if ($mode !== 'midtrans') {
        $json(['ok' => false, 'pesan' => 'Pembayaran online belum dikonfigurasi.'], 400);
    }

    try {
        if ($aksi === 'snap') {

            // Pakai ulang transaksi pending agar pelanggan tidak membayar dobel.
            if (empty($_POST['baru'])) {
                $st = $conn->prepare("
                    SELECT id, snap_token FROM pembayaran
                    WHERE id_tagihan = ? AND id_customer = ? AND status = 'pending'
                      AND snap_token IS NOT NULL AND snap_token <> ''
                    ORDER BY id DESC LIMIT 1
                ");
                $st->bind_param('ii', $tid, $cid);
                $st->execute();
                $old = $st->get_result()->fetch_assoc();
                $st->close();
                if ($old) {
                    $json(['ok' => true, 'token' => (string) $old['snap_token'], 'pembayaran_id' => (int) $old['id']]);
                }
            }

            $gross = (int) round((float) $tg['jumlah']);
            if ($gross < 1) {
                throw new RuntimeException('Nominal tagihan tidak valid.');
            }

            $noBayar = nomor('PAY');
            $jumlah = (float) $tg['jumlah'];
            $awal = 'pending';
            $metodeAwal = 'Midtrans';
            $tglBayar = null;

            $st = $conn->prepare("
                INSERT INTO pembayaran
                (id_customer, id_tagihan, nomor_pembayaran, jumlah, metode_pembayaran, status, transaction_status, tanggal_bayar, midtrans_order_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->bind_param('iisdsssss', $cid, $tid, $noBayar, $jumlah, $metodeAwal, $awal, $awal, $tglBayar, $noBayar);
            $st->execute();
            $bayarId = (int) $conn->insert_id;
            $st->close();

            $pick = static function (array $a, array $keys): string {
                foreach ($keys as $k) {
                    if (!empty($a[$k])) {
                        return trim((string) $a[$k]);
                    }
                }
                return '';
            };
            $customer = ['first_name' => mb_substr($pick($cust, ['nama', 'nama_lengkap', 'name']) ?: 'Pelanggan', 0, 50)];
            $email = $pick($cust, ['email']);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $customer['email'] = $email;
            }
            $phone = preg_replace('/[^0-9+]/', '', $pick($cust, ['telepon', 'telephone', 'no_hp', 'phone']));
            if ($phone !== '') {
                $customer['phone'] = $phone;
            }

            try {
                $token = midtransCreateSnapToken([
                    'transaction_details' => ['order_id' => $noBayar, 'gross_amount' => $gross],
                    'item_details' => [[
                        'id'       => 'TG' . $tid,
                        'price'    => $gross,
                        'quantity' => 1,
                        'name'     => mb_substr(trim(((string) ($tg['nama_paket'] ?: 'Paket Internet')) . ' - ' . (string) $tg['nomor_tagihan']), 0, 50),
                    ]],
                    'customer_details' => $customer,
                    'expiry' => ['unit' => 'hours', 'duration' => 24],
                ]);
            } catch (Throwable $e) {
                $st = $conn->prepare('DELETE FROM pembayaran WHERE id = ? LIMIT 1');
                $st->bind_param('i', $bayarId);
                $st->execute();
                $st->close();
                throw $e;
            }

            $st = $conn->prepare('UPDATE pembayaran SET snap_token = ? WHERE id = ? LIMIT 1');
            $st->bind_param('si', $token, $bayarId);
            $st->execute();
            $st->close();

            $json(['ok' => true, 'token' => $token, 'pembayaran_id' => $bayarId]);
        }

        // aksi === 'sync'
        $pid = (int) ($_POST['pembayaran_id'] ?? 0);
        $st = $conn->prepare('SELECT midtrans_order_id FROM pembayaran WHERE id = ? AND id_customer = ? LIMIT 1');
        $st->bind_param('ii', $pid, $cid);
        $st->execute();
        $own = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$own || empty($own['midtrans_order_id'])) {
            $json(['ok' => false, 'pesan' => 'Pembayaran tidak ditemukan.'], 404);
        }

        $res = midtransFetchStatus((string) $own['midtrans_order_id']);
        if ((string) ($res['status_code'] ?? '') === '404') {
            $json(['ok' => true, 'status' => 'pending']); // belum ada transaksi di sisi Midtrans
        }
        if (!midtransSignatureOk($res)) {
            throw new RuntimeException('Signature status Midtrans tidak valid.');
        }
        $json(['ok' => true, 'status' => midtransApply($conn, $res)]);

    } catch (Throwable $e) {
        error_log('Pembayaran ' . $aksi . ' gagal: ' . $e->getMessage());
        $json(['ok' => false, 'pesan' => 'Pembayaran belum dapat diproses. Silakan coba lagi.'], 500);
    }
}

/*
|--------------------------------------------------------------------------
| Mode demo (simulasi) — logika asli dipertahankan
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pilih = (string) ($_POST['metode'] ?? '');
    if (!csrf_ok()) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman.';
    } elseif ($mode !== 'demo') {
        $error = 'Pembayaran online belum dikonfigurasi. Silakan hubungi tim YesNet.';
    } elseif (!in_array($pilih, $metode, true)) {
        $error = 'Pilih metode pembayaran.';
    } elseif (!in_array($_POST['hasil'] ?? '', ['berhasil', 'gagal'], true)) {
        $error = 'Hasil pembayaran tidak valid.';
    } else {
        $berhasil = $_POST['hasil'] === 'berhasil';
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

/* Ada transaksi Midtrans yang belum selesai untuk tagihan ini? */
$pendingId = 0;
if ($mode === 'midtrans') {
    $st = $conn->prepare("SELECT id FROM pembayaran WHERE id_tagihan = ? AND id_customer = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $st->bind_param('ii', $tid, $cid);
    $st->execute();
    $pendingId = (int) ($st->get_result()->fetch_column() ?: 0);
    $st->close();
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

    <?php if ($mode === 'midtrans'): ?>
        <form id="snapForm" data-pending="<?= (int) $pendingId ?>" style="margin-top:22px">
            <?= csrf_field() ?>
            <?php if ($pendingId > 0): ?>
                <div class="alert warn" role="status">Anda punya pembayaran yang belum selesai. Lanjutkan di bawah, atau periksa status jika sudah membayar.</div>
            <?php endif; ?>
            <p style="margin:0 0 14px;color:#667085;font-size:14px">
                Metode pembayaran (QRIS, Virtual Account, e-wallet, atau kartu) dipilih di jendela berikutnya, sesuai yang diaktifkan pada akun pembayaran YesNet.
            </p>
            <div class="alert err" id="payMsg" role="alert" hidden></div>
            <button class="btn btn-primary btn-block" type="submit" id="payBtn">
                <?= $pendingId > 0 ? 'Lanjutkan pembayaran' : 'Bayar ' . rupiah($tg['jumlah']) ?>
            </button>
            <button class="btn btn-block" type="button" id="newBtn" hidden style="margin-top:10px">Buat transaksi pembayaran baru</button>
            <?php if ($pendingId > 0): ?>
                <button class="btn btn-block" type="button" id="checkBtn" style="margin-top:10px">Periksa status pembayaran</button>
            <?php endif; ?>
        </form>

    <?php elseif ($mode === 'demo'): ?>
        <form method="post" id="payForm">
            <?= csrf_field() ?>
            <label>Metode pembayaran</label>
            <div class="pay-grid">
                <?php foreach ($metode as $i => $m): ?>
                    <label class="pay-opt"><input type="radio" name="metode" value="<?= e($m) ?>" <?= $i === 0 ? 'checked' : '' ?>><?= e($m) ?></label>
                <?php endforeach; ?>
            </div>
            <label>Simulasi hasil (mode demo)</label>
            <div class="pay-grid">
                <label class="pay-opt"><input type="radio" name="hasil" value="berhasil" checked>Berhasil</label>
                <label class="pay-opt"><input type="radio" name="hasil" value="gagal">Gagal</label>
            </div>
            <button class="btn btn-primary btn-block" type="submit" style="margin-top:22px">Bayar <?= rupiah($tg['jumlah']) ?></button>
        </form>
    <?php else: ?>
        <div class="alert warn" role="status">Pembayaran online belum dikonfigurasi. Pembayaran tidak akan dicatat sebagai berhasil.</div>
    <?php endif; ?>
</div>

<?php if ($mode === 'midtrans'): ?>
<script src="<?= e(midtransSnapJsUrl()) ?>" data-client-key="<?= e(MIDTRANS_CLIENT_KEY) ?>"></script>
<script>
(function () {
  var form = document.getElementById('snapForm');
  if (!form) { return; }
  var btn = document.getElementById('payBtn'), newBtn = document.getElementById('newBtn'),
      checkBtn = document.getElementById('checkBtn'), msg = document.getElementById('payMsg');
  var label = btn.textContent.trim();

  function show(t) { msg.textContent = t; msg.hidden = false; }
  function reset() { btn.disabled = false; btn.textContent = label; if (checkBtn) { checkBtn.disabled = false; } }
  function post(data) {
    var fd = new FormData(form);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); });
  }
  function toResult(id) { window.location.href = 'hasil_pembayaran.php?id=' + encodeURIComponent(id); }
  function finish(id) {
    // Sinkronkan status lebih dulu supaya hasil langsung benar, walau webhook belum masuk.
    post({ aksi: 'sync', pembayaran_id: id }).catch(function () {}).then(function () { toResult(id); });
  }

  function pay(baru) {
    if (typeof window.snap === 'undefined') { show('Jendela pembayaran gagal dimuat. Periksa koneksi lalu muat ulang halaman.'); return; }
    btn.disabled = true; btn.textContent = 'Menyiapkan pembayaran...'; msg.hidden = true;
    post({ aksi: 'snap', baru: baru ? '1' : '' }).then(function (r) {
      if (!r.ok) { throw new Error(r.pesan || 'Pembayaran belum dapat diproses.'); }
      window.snap.pay(r.token, {
        onSuccess: function () { finish(r.pembayaran_id); },
        onPending: function () { finish(r.pembayaran_id); },
        onError:   function () { reset(); newBtn.hidden = false; show('Pembayaran gagal diproses. Coba lagi atau buat transaksi baru.'); },
        onClose:   function () { reset(); newBtn.hidden = false; show('Jendela pembayaran ditutup sebelum selesai. Anda dapat melanjutkannya kapan saja.'); }
      });
    }).catch(function (e) { reset(); show(e.message || 'Pembayaran belum dapat diproses.'); });
  }

  form.addEventListener('submit', function (ev) { ev.preventDefault(); pay(false); });
  newBtn.addEventListener('click', function () { pay(true); });

  if (checkBtn) {
    checkBtn.addEventListener('click', function () {
      checkBtn.disabled = true; msg.hidden = true;
      var id = form.getAttribute('data-pending');
      post({ aksi: 'sync', pembayaran_id: id }).then(function (r) {
        if (r.ok && r.status === 'success') { toResult(id); return; }
        checkBtn.disabled = false;
        show(r.ok ? 'Pembayaran belum kami terima. Selesaikan pembayaran lalu periksa lagi.' : (r.pesan || 'Status belum dapat diperiksa.'));
      }).catch(function () { checkBtn.disabled = false; show('Status belum dapat diperiksa.'); });
    });
  }

  window.addEventListener('pageshow', function (ev) { if (ev.persisted) { reset(); } });
})();
</script>
<?php elseif ($mode === 'demo'): ?>
<script>
var payForm = document.getElementById('payForm');
if (payForm) {
  payForm.addEventListener('submit', function () {
    var b = this.querySelector('button[type=submit]');
    setTimeout(function () { b.disabled = true; b.classList.add('loading'); }, 0);
  });
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>