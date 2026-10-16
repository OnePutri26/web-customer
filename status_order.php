
<?php

/*
|--------------------------------------------------------------------------
| YESNET - STATUS ORDER CUSTOMER
|--------------------------------------------------------------------------
| Alur:
| Pembayaran -> Survey -> Jadwal -> Teknisi -> Instalasi selesai
| -> Aktivasi -> Customer aktif.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config/app.php';

/* ---------- VALIDASI LOGIN DAN ROLE ---------- */

requireRole('customer');

if (!isCustomer()) {
    redirect('login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('[YesNet status_order] Koneksi database tidak tersedia.');
    http_response_code(500);
    exit('Koneksi database belum tersedia. Silakan hubungi administrator.');
}

$cust = currentCustomer($conn);

if (!$cust || empty($cust['id'])) {
    redirect('login.php');
    exit;
}

$cid = (int) $cust['id'];

$pageTitle = 'Status Order';
$step = 4;

$error = '';
$dbError = false;

$sub = null;
$tg = null;
$pg = null;
$ins = null;
$paket = null;

$done = 0;
$dibayar = false;

$paymentDemo = defined('PAYMENT_DEMO') && PAYMENT_DEMO;

/*
|--------------------------------------------------------------------------
| HELPER QUERY
|--------------------------------------------------------------------------
*/

function soOne(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): ?array {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log(
            '[YesNet status_order] Prepare gagal: ' . $conn->error
        );

        throw new RuntimeException('Query database gagal disiapkan.');
    }

    try {
        if ($types !== '' && count($params) > 0) {
            $bind = [$types];

            foreach ($params as $key => &$value) {
                $bind[] = &$value;
            }

            if (!call_user_func_array(
                [$stmt, 'bind_param'],
                $bind
            )) {
                throw new RuntimeException(
                    'Parameter query gagal dipasang.'
                );
            }
        }

        if (!$stmt->execute()) {
            error_log(
                '[YesNet status_order] Execute gagal: ' . $stmt->error
            );

            throw new RuntimeException(
                'Query database gagal dijalankan.'
            );
        }

        $result = $stmt->get_result();

        if (!$result) {
            throw new RuntimeException(
                'Hasil query tidak tersedia. Periksa dukungan mysqlnd.'
            );
        }

        $row = $result->fetch_assoc();

        return $row ?: null;
    } finally {
        $stmt->close();
    }
}

function soExec(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): void {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log(
            '[YesNet status_order] Prepare perubahan gagal: '
            . $conn->error
        );

        throw new RuntimeException(
            'Perubahan database gagal disiapkan.'
        );
    }

    try {
        if ($types !== '' && count($params) > 0) {
            $bind = [$types];

            foreach ($params as $key => &$value) {
                $bind[] = &$value;
            }

            if (!call_user_func_array(
                [$stmt, 'bind_param'],
                $bind
            )) {
                throw new RuntimeException(
                    'Parameter perubahan gagal dipasang.'
                );
            }
        }

        if (!$stmt->execute()) {
            error_log(
                '[YesNet status_order] Perubahan gagal: ' . $stmt->error
            );

            throw new RuntimeException(
                'Perubahan database gagal dijalankan.'
            );
        }
    } finally {
        $stmt->close();
    }
}

function soText($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return function_exists('e')
        ? e((string) $value)
        : htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
}

function soDate($value): ?string
{
    if (!$value) {
        return null;
    }

    $time = strtotime((string) $value);

    return $time !== false
        ? date('d M Y, H.i', $time)
        : null;
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA ORDER
|--------------------------------------------------------------------------
*/

try {
    $sub = soOne(
        $conn,
        'SELECT *
         FROM subscription
         WHERE id_customer = ?
         ORDER BY id DESC
         LIMIT 1',
        'i',
        [$cid]
    );

    if (!$sub) {
        redirect('paket.php?mode=direct');
        exit;
    }

    $sid = (int) $sub['id'];

    $tg = soOne(
        $conn,
        'SELECT *
         FROM tagihan
         WHERE id_subscription = ?
           AND id_customer = ?
         ORDER BY id DESC
         LIMIT 1',
        'ii',
        [$sid, $cid]
    );

    $pg = soOne(
        $conn,
        'SELECT *
         FROM pengajuan_pemasangan
         WHERE id_customer = ?
         ORDER BY id DESC
         LIMIT 1',
        'i',
        [$cid]
    );

    if ($pg) {
        $ins = soOne(
            $conn,
            'SELECT *
             FROM instalasi
             WHERE id_pengajuan = ?
               AND id_customer = ?
             ORDER BY id DESC
             LIMIT 1',
            'ii',
            [(int) $pg['id'], $cid]
        );
    }

    $paket = soOne(
        $conn,
        'SELECT *
         FROM paket_wifi
         WHERE id = ?
         LIMIT 1',
        'i',
        [(int) ($sub['paket_id'] ?? 0)]
    );

} catch (Throwable $e) {
    error_log(
        '[YesNet status_order] Gagal mengambil data: '
        . $e->getMessage()
    );

    $dbError = true;
    $error = 'Data status order belum dapat dimuat. Silakan coba kembali nanti.';
}

/*
|--------------------------------------------------------------------------
| HITUNG PROGRES ORDER
|--------------------------------------------------------------------------
*/

if (!$dbError && $sub) {
    $dibayar = $tg
        && strtolower((string) ($tg['status'] ?? '')) === 'paid';

    if ($pg) {
        $statusPengajuan = strtolower(
            (string) ($pg['status'] ?? '')
        );

        if ($statusPengajuan === 'approved') {
            $done = 1;

        } elseif ($statusPengajuan === 'scheduled') {
            $statusInstalasi = strtolower(
                (string) ($ins['status'] ?? '')
            );

            $done = $statusInstalasi === 'proses' ? 3 : 2;

        } elseif ($statusPengajuan === 'installed') {
            $done = 4;
        }
    }

    $statusSub = strtolower(
        (string) ($sub['status'] ?? '')
    );

    if ($done >= 4 && $statusSub === 'active') {
        $done = 5;
    }

    $statusCustomer = strtolower(
        trim((string) ($cust['status_langganan'] ?? ''))
    );

    if (
        $done >= 5
        && in_array($statusCustomer, ['active', 'aktif'], true)
    ) {
        $done = 6;
    }
}

/*
|--------------------------------------------------------------------------
| SIMULASI TAHAP BERIKUTNYA - MODE DEMO
|--------------------------------------------------------------------------
*/

if (
    !$dbError
    && $paymentDemo
    && $dibayar
    && $done < 6
    && $pg !== null
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && function_exists('csrf_ok')
    && csrf_ok()
) {
    $pid = (int) $pg['id'];
    $sid = (int) $sub['id'];

    try {
        if ($done === 0) {
            soExec(
                $conn,
                "UPDATE pengajuan_pemasangan
                 SET status = 'approved'
                 WHERE id = ? AND id_customer = ?",
                'ii',
                [$pid, $cid]
            );

        } elseif ($done === 1) {
            $jadwal = date(
                'Y-m-d 09:00:00',
                strtotime('+1 day')
            );

            $teknisi = 'Teknisi YesNet';

            /*
             * Tabel teknisi bersifat opsional.
             * Jika query gagal, gunakan nama teknisi default.
             */
            try {
                $r = $conn->query(
                    "SELECT nama
                     FROM technicians
                     WHERE status IN ('tersedia', 'bertugas')
                     ORDER BY
                       CASE
                           WHEN status = 'tersedia' THEN 0
                           ELSE 1
                       END,
                       id ASC
                     LIMIT 1"
                );

                if ($r) {
                    $rowTeknisi = $r->fetch_assoc();

                    if (!empty($rowTeknisi['nama'])) {
                        $teknisi = (string) $rowTeknisi['nama'];
                    }

                    $r->free();
                }

            } catch (Throwable $e) {
                error_log(
                    '[YesNet status_order] Lookup teknisi: '
                    . $e->getMessage()
                );
            }

            $alamat = trim(
                (string) ($pg['alamat_pemasangan'] ?? '')
            );

            if ($alamat === '') {
                throw new RuntimeException(
                    'Alamat pemasangan belum tersedia.'
                );
            }

            $conn->begin_transaction();

            try {
                soExec(
                    $conn,
                    "UPDATE pengajuan_pemasangan
                     SET status = 'scheduled',
                         jadwal_pemasangan = ?
                     WHERE id = ? AND id_customer = ?",
                    'sii',
                    [$jadwal, $pid, $cid]
                );

                soExec(
                    $conn,
                    "INSERT INTO instalasi
                     (
                         id_customer,
                         id_pengajuan,
                         teknisi,
                         tanggal_jadwal,
                         status,
                         alamat
                     )
                     VALUES (?, ?, ?, ?, 'dijadwalkan', ?)",
                    'iisss',
                    [$cid, $pid, $teknisi, $jadwal, $alamat]
                );

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

        } elseif ($done === 2) {
            soExec(
                $conn,
                "UPDATE instalasi
                 SET status = 'proses'
                 WHERE id_pengajuan = ?
                   AND id_customer = ?",
                'ii',
                [$pid, $cid]
            );

        } elseif ($done === 3) {
            $conn->begin_transaction();

            try {
                soExec(
                    $conn,
                    "UPDATE instalasi
                     SET status = 'selesai',
                         tanggal_pemasangan = NOW()
                     WHERE id_pengajuan = ?
                       AND id_customer = ?",
                    'ii',
                    [$pid, $cid]
                );

                soExec(
                    $conn,
                    "UPDATE pengajuan_pemasangan
                     SET status = 'installed'
                     WHERE id = ? AND id_customer = ?",
                    'ii',
                    [$pid, $cid]
                );

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

        } elseif ($done === 4) {
            soExec(
                $conn,
                "UPDATE subscription
                 SET status = 'active',
                     tanggal_mulai = CURDATE(),
                     tanggal_berakhir = DATE_ADD(
                         CURDATE(),
                         INTERVAL 30 DAY
                     )
                 WHERE id = ? AND id_customer = ?",
                'ii',
                [$sid, $cid]
            );

        } elseif ($done === 5) {
            soExec(
                $conn,
                "UPDATE customers
                 SET status_langganan = 'active',
                     tgl_mulai = NOW()
                 WHERE id = ?",
                'i',
                [$cid]
            );

            $_SESSION['subscription_status'] = 'aktif';
        }

        redirect('status_order.php');
        exit;

    } catch (Throwable $e) {
        if ($conn->errno) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
                // Abaikan kegagalan rollback tambahan.
            }
        }

        error_log(
            '[YesNet status_order] Simulasi gagal: '
            . $e->getMessage()
        );

        $error = 'Tahap order gagal diperbarui. Periksa log server atau hubungi admin.';
    }
}

/*
|--------------------------------------------------------------------------
| DATA TAMPILAN
|--------------------------------------------------------------------------
*/

$alamatTampil = (string) (
    $pg['alamat_pemasangan'] ?? ''
);

$tahap = [
    [
        'Survey & validasi',
        'Tim memvalidasi lokasi dan data Anda'
        . (!empty($pg['nomor_pengajuan'])
            ? ' · ' . (string) $pg['nomor_pengajuan']
            : '')
    ],
    [
        'Jadwal instalasi',
        !empty($pg['jadwal_pemasangan'])
            ? (soDate($pg['jadwal_pemasangan'])
                ?? 'Jadwal belum tersedia')
            : 'Jadwal ditentukan setelah survey'
    ],
    [
        'Teknisi datang',
        !empty($ins['teknisi'])
            ? 'Teknisi: ' . (string) $ins['teknisi']
            : 'Teknisi tiba di lokasi Anda'
    ],
    [
        'Instalasi selesai',
        !empty($ins['tanggal_pemasangan'])
            ? (soDate($ins['tanggal_pemasangan'])
                ?? 'Tanggal belum tersedia')
            : 'Kabel dan router terpasang'
    ],
    [
        'Aktivasi customer',
        'Akun dan paket diaktifkan'
    ],
    [
        'Customer aktif',
        'Internet siap digunakan'
    ],
];

require __DIR__ . '/inc/header.php';
?>

<div class="page-head enter">
    <h1>Status Order</h1>

    <p>
        <?= soText($paket['nama_paket'] ?? 'Paket') ?>
        · <?= (int) ($paket['speed_mbps'] ?? 0) ?> Mbps
        <?php if ($alamatTampil !== ''): ?>
            · <?= soText($alamatTampil) ?>
        <?php endif; ?>
    </p>
</div>

<div class="card enter">

    <?php if ($error !== ''): ?>
        <div class="alert err" role="alert">
            <?= soText($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($dbError): ?>

        <p class="muted">
            Data order belum bisa ditampilkan. Silakan muat ulang
            halaman beberapa saat lagi.
        </p>

        <div class="actions">
            <a class="btn btn-primary" href="status_order.php">
                Muat Ulang
            </a>
        </div>

    <?php elseif (!$tg): ?>

        <div class="alert warn">
            Tagihan untuk order ini belum ditemukan. Hubungi admin
            untuk memeriksa data pembayaran.
        </div>

    <?php elseif (!$dibayar): ?>

        <div class="alert warn">
            Pembayaran belum selesai. Selesaikan pembayaran agar
            order dapat diproses.
        </div>

        <div class="actions">
            <a
                class="btn btn-primary"
                href="pembayaran.php?tagihan=<?= (int) $tg['id'] ?>"
            >
                Bayar Sekarang
            </a>
        </div>

    <?php else: ?>

        <ol class="timeline">
            <?php foreach ($tahap as $i => $t): ?>
                <li class="<?= $i < $done
                    ? 'done'
                    : ($i === $done ? 'now' : '') ?>">
                    <div>
                        <b><?= soText($t[0]) ?></b>
                        <small><?= soText($t[1]) ?></small>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($done >= 6): ?>

            <div class="actions">
                <a
                    class="btn btn-primary"
                    href="customer/dashboard.php"
                >
                    Masuk Dashboard
                </a>
            </div>

        <?php elseif ($paymentDemo && $pg !== null): ?>

            <?php if (function_exists('csrf_field')): ?>
                <form method="post" class="actions">
                    <?= csrf_field() ?>

                    <button class="btn btn-outline" type="submit">
                        Simulasi: lanjut ke tahap berikutnya
                    </button>
                </form>
            <?php else: ?>
                <div class="alert warn">
                    Form simulasi tidak tersedia karena konfigurasi
                    keamanan CSRF belum ditemukan.
                </div>
            <?php endif; ?>

            <p
                class="muted center"
                style="font-size:12.5px;margin-top:10px"
            >
                Mode demo. Pada produksi, tahap ini diperbarui oleh
                admin dan teknisi.
            </p>

        <?php elseif (!$pg): ?>

            <div class="alert warn">
                Pengajuan pemasangan belum ditemukan. Hubungi admin
                agar order dapat diproses.
            </div>

        <?php else: ?>

            <p class="muted center" style="font-size:13px">
                Halaman ini diperbarui otomatis saat tim kami
                memproses order Anda.
            </p>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?php if (
    !$dbError
    && $dibayar
    && $done < 6
    && !$paymentDemo
): ?>
<script>
setTimeout(function () {
    window.location.reload();
}, 30000);
</script>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
