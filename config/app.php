
<?php
/*
|--------------------------------------------------------------------------
| YESNET - HELPER ALUR PUBLIK DAN PELANGGAN
|--------------------------------------------------------------------------
| File: config/app.php
|
| Digunakan oleh:
| index.php, coverage.php, paket.php, detail_paket.php,
| verifikasi.php, order.php, pembayaran.php,
| hasil_pembayaran.php, status_order.php
|
| File pendukung:
| config/database.php
| config/settings.php
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/settings.php';

/* ========================================================================
   OUTPUT DAN FORMAT
   ======================================================================== */

if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars(
            (string) $v,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('rupiah')) {
    function rupiah($v): string
    {
        return 'Rp ' . number_format(
            (float) $v,
            0,
            ',',
            '.'
        );
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }

        // Jangan mengirim header redirect setelah HTML terkirim.
        error_log(
            'YesNet: redirect gagal karena header sudah terkirim: ' . $url
        );

        exit('Terjadi kesalahan navigasi. Silakan kembali dan coba lagi.');
    }
}

if (!function_exists('nomor')) {
    function nomor(string $prefix): string
    {
        return $prefix . date('ymdHis') . random_int(10, 99);
    }
}

/* ========================================================================
   AUTENTIKASI
   ======================================================================== */

/*
 * Memastikan pengguna sudah login dan memiliki role yang diminta.
 * Sesi yang digunakan mengikuti struktur asli YesNet:
 * $_SESSION['user_id']
 * $_SESSION['role']
 */
if (!function_exists('requireRole')) {
    function requireRole(string $role): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $userRole = strtolower(
            trim((string) ($_SESSION['role'] ?? ''))
        );

        $requiredRole = strtolower(trim($role));

        if ($userId <= 0 || $userRole !== $requiredRole) {
            redirect('login.php');
        }
    }
}

if (!function_exists('isCustomer')) {
    function isCustomer(): bool
    {
        return (int) ($_SESSION['user_id'] ?? 0) > 0
            && strtolower(
                trim((string) ($_SESSION['role'] ?? ''))
            ) === 'customer';
    }
}

if (!function_exists('isAktif')) {
    function isAktif(?string $s): bool
    {
        return in_array(
            strtolower(trim((string) $s)),
            ['active', 'aktif'],
            true
        );
    }
}

/* ========================================================================
   CSRF
   ======================================================================== */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (
            !isset($_SESSION['csrf'])
            || !is_string($_SESSION['csrf'])
            || $_SESSION['csrf'] === ''
        ) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf" value="'
            . e(csrf_token())
            . '">';
    }
}

if (!function_exists('csrf_ok')) {
    function csrf_ok(): bool
    {
        $sessionToken = $_SESSION['csrf'] ?? null;
        $postToken = $_POST['csrf'] ?? null;

        if (
            !is_string($sessionToken)
            || !is_string($postToken)
            || $sessionToken === ''
            || $postToken === ''
        ) {
            return false;
        }

        return hash_equals($sessionToken, $postToken);
    }
}

/* ========================================================================
   SESSION FLOW
   Data sementara untuk alur coverage, paket, verifikasi, dan order.
   ======================================================================== */

if (!function_exists('flow')) {
    function flow(?string $key = null, $default = null)
    {
        $f = $_SESSION['flow'] ?? [];

        if (!is_array($f)) {
            $f = [];
        }

        return $key === null
            ? $f
            : ($f[$key] ?? $default);
    }
}

if (!function_exists('flowSet')) {
    function flowSet(array $data): void
    {
        if (
            !isset($_SESSION['flow'])
            || !is_array($_SESSION['flow'])
        ) {
            $_SESSION['flow'] = [];
        }

        $_SESSION['flow'] = array_merge(
            $_SESSION['flow'],
            $data
        );
    }
}

/* ========================================================================
   DATA PELANGGAN
   ======================================================================== */

if (!function_exists('currentCustomer')) {
    function currentCustomer(mysqli $conn): ?array
    {
        $uid = (int) ($_SESSION['user_id'] ?? 0);

        if ($uid <= 0) {
            return null;
        }

        $st = $conn->prepare(
            'SELECT *
             FROM customers
             WHERE user_id = ?
             LIMIT 1'
        );

        $st->bind_param('i', $uid);
        $st->execute();

        $row = $st->get_result()->fetch_assoc();

        $st->close();

        return $row ?: null;
    }
}

/* ========================================================================
   DATA PAKET WIFI
   ======================================================================== */

if (!function_exists('listPaket')) {
    function listPaket(mysqli $conn, int $limit = 12): array
    {
        $limit = max(1, min($limit, 100));

        $sql = "
            SELECT *
            FROM paket_wifi
            WHERE LOWER(TRIM(COALESCE(status, '')))
                IN ('aktif', 'active')
            ORDER BY harga ASC
            LIMIT " . $limit;

        $res = $conn->query($sql);

        return $res->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('getPaket')) {
    function getPaket(mysqli $conn, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $st = $conn->prepare("
            SELECT *
            FROM paket_wifi
            WHERE id = ?
              AND (
                  status IS NULL
                  OR TRIM(status) = ''
                  OR LOWER(TRIM(status))
                     IN (
                         'aktif',
                         'active',
                         '1',
                         'tersedia',
                         'available'
                     )
              )
            LIMIT 1
        ");

        $st->bind_param('i', $id);
        $st->execute();

        $row = $st->get_result()->fetch_assoc();

        $st->close();

        return $row ?: null;
    }
}

/* ========================================================================
   COVERAGE DAN JARAK ODP
   Radius ODP diasumsikan menggunakan kilometer.
   ======================================================================== */

if (!function_exists('hitungJarakKm')) {
    function hitungJarakKm(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1))
            * cos(deg2rad($lat2))
            * sin($dLon / 2) ** 2;

        return 2 * 6371 * asin(
            min(1, sqrt($a))
        );
    }
}

if (!function_exists('cekCoverage')) {
    function cekCoverage(
        mysqli $conn,
        string $alamat,
        ?float $lat,
        ?float $lng
    ): array {
        $hasil = [
            'tersedia' => false,
            'odp_id' => null,
            'odp' => null,
            'jarak' => null,
        ];

        if (
            $lat === null
            || $lng === null
            || !is_finite($lat)
            || !is_finite($lng)
            || $lat < -90
            || $lat > 90
            || $lng < -180
            || $lng > 180
        ) {
            return $hasil;
        }

        try {
            $res = $conn->query(
                'SELECT id, nama_odp, latitude, longitude, radius
                 FROM odp'
            );

            while ($o = $res->fetch_assoc()) {
                if (
                    !isset(
                        $o['id'],
                        $o['nama_odp'],
                        $o['latitude'],
                        $o['longitude'],
                        $o['radius']
                    )
                    || !is_numeric($o['latitude'])
                    || !is_numeric($o['longitude'])
                    || !is_numeric($o['radius'])
                ) {
                    continue;
                }

                $odpLat = (float) $o['latitude'];
                $odpLng = (float) $o['longitude'];
                $radius = (float) $o['radius'];

                if (
                    $odpLat < -90
                    || $odpLat > 90
                    || $odpLng < -180
                    || $odpLng > 180
                    || $radius <= 0
                ) {
                    continue;
                }

                $jarak = hitungJarakKm(
                    $lat,
                    $lng,
                    $odpLat,
                    $odpLng
                );

                if (
                    $jarak <= $radius
                    && (
                        $hasil['jarak'] === null
                        || $jarak < $hasil['jarak']
                    )
                ) {
                    $hasil = [
                        'tersedia' => true,
                        'odp_id' => (int) $o['id'],
                        'odp' => (string) $o['nama_odp'],
                        'jarak' => round($jarak, 3),
                    ];
                }
            }

            $res->free();
        } catch (Throwable $e) {
            error_log(
                'YesNet cekCoverage gagal: ' . $e->getMessage()
            );

            return [
                'tersedia' => false,
                'odp_id' => null,
                'odp' => null,
                'jarak' => null,
                'error' => true,
            ];
        }

        return $hasil;
    }
}

/* ========================================================================
   ORDER
   Membuat subscription, pengajuan pemasangan, dan tagihan.
   ======================================================================== */

if (!function_exists('ensureOrder')) {
    function ensureOrder(
        mysqli $conn,
        array $cust,
        array $paket,
        string $alamat
    ): int {
        $cid = (int) ($cust['id'] ?? 0);
        $pid = (int) ($paket['id'] ?? 0);

        if ($cid <= 0 || $pid <= 0) {
            throw new InvalidArgumentException(
                'Data pelanggan atau paket tidak valid.'
            );
        }

        if (!isset($paket['harga']) || !is_numeric($paket['harga'])) {
            throw new InvalidArgumentException(
                'Harga paket tidak valid.'
            );
        }

        $harga = (float) $paket['harga'];

        /*
         * Cek order yang masih menunggu pembayaran.
         * Jangan membuat tagihan ganda untuk order pending yang sama.
         */
        $st = $conn->prepare("
            SELECT t.id
            FROM tagihan t
            JOIN subscription s
                ON s.id = t.id_subscription
            WHERE t.id_customer = ?
              AND s.paket_id = ?
              AND s.status = 'pending'
              AND t.status IN ('unpaid', 'pending')
            ORDER BY t.id DESC
            LIMIT 1
        ");

        $st->bind_param('ii', $cid, $pid);
        $st->execute();

        $ada = $st->get_result()->fetch_assoc();

        $st->close();

        if ($ada) {
            return (int) $ada['id'];
        }

        $conn->begin_transaction();

        try {
            /* Buat subscription. */
            $st = $conn->prepare("
                INSERT INTO subscription
                    (id_customer, paket_id, harga, status)
                VALUES (?, ?, ?, 'pending')
            ");

            $st->bind_param('iid', $cid, $pid, $harga);
            $st->execute();

            $subId = (int) $conn->insert_id;

            $st->close();

            /* Buat pengajuan pemasangan. */
            $noPengajuan = nomor('PNG');
            $nama = (string) ($cust['nama'] ?? '');
            $tel = (string) ($cust['telephone'] ?? '');

            $st = $conn->prepare("
                INSERT INTO pengajuan_pemasangan
                    (
                        id_customer,
                        paket_id,
                        nomor_pengajuan,
                        nama_pelanggan,
                        telephone,
                        alamat_pemasangan,
                        status
                    )
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");

            $st->bind_param(
                'iissss',
                $cid,
                $pid,
                $noPengajuan,
                $nama,
                $tel,
                $alamat
            );

            $st->execute();
            $st->close();

            /*
             * Simpan data coverage jika koordinat tersedia.
             * Tabel installation_requests harus tersedia di database
             * jika alur ini menggunakan koordinat.
             */
            $latFlow = flow('lat');
            $lngFlow = flow('lng');

            if ($latFlow !== null && $lngFlow !== null) {
                $lat = (float) $latFlow;
                $lng = (float) $lngFlow;

                $odpFlow = flow('odp_id');
                $jarakFlow = flow('jarak');

                $odpId = (
                    $odpFlow !== null
                    && is_numeric($odpFlow)
                ) ? (int) $odpFlow : null;

                $jarak = (
                    $jarakFlow !== null
                    && is_numeric($jarakFlow)
                ) ? (float) $jarakFlow : null;

                $st = $conn->prepare("
                    INSERT INTO installation_requests
                    (
                        customer_id,
                        paket_id,
                        alamat_pemasangan,
                        latitude,
                        longitude,
                        odp_id,
                        jarak_odp,
                        coverage_status,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'tercover', 'pending')
                ");

                $st->bind_param(
                    'iisddid',
                    $cid,
                    $pid,
                    $alamat,
                    $lat,
                    $lng,
                    $odpId,
                    $jarak
                );

                $st->execute();
                $st->close();
            }

            /* Buat tagihan. */
            $noTagihan = nomor('INV');
            $bln = (int) date('n');
            $thn = (int) date('Y');

            $st = $conn->prepare("
                INSERT INTO tagihan
                (
                    id_customer,
                    id_subscription,
                    nomor_tagihan,
                    periode_bulan,
                    periode_tahun,
                    jumlah,
                    tanggal_terbit,
                    jatuh_tempo,
                    status
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?,
                    CURDATE(),
                    DATE_ADD(CURDATE(), INTERVAL 3 DAY),
                    'unpaid'
                )
            ");

            $st->bind_param(
                'iisiid',
                $cid,
                $subId,
                $noTagihan,
                $bln,
                $thn,
                $harga
            );

            $st->execute();

            $tagihanId = (int) $conn->insert_id;

            $st->close();

            /* Perbarui paket dan status pelanggan. */
            $st = $conn->prepare("
                UPDATE customers
                SET paket_id = ?,
                    status_langganan = 'pending'
                WHERE id = ?
                LIMIT 1
            ");

            $st->bind_param('ii', $pid, $cid);
            $st->execute();
            $st->close();

            $conn->commit();

            return $tagihanId;
        } catch (Throwable $e) {
            $conn->rollback();

            error_log(
                'YesNet ensureOrder gagal: ' . $e->getMessage()
            );

            throw $e;
        }
    }
}

/* ========================================================================
   TAMPILAN
   ======================================================================== */

if (!function_exists('logoHtml')) {
    function logoHtml(): string
    {
        return '<svg class="logo-mark" width="34" height="34" '
            . 'viewBox="0 0 34 34" fill="none" stroke="#1f62ff" '
            . 'stroke-width="4" stroke-linecap="round" '
            . 'aria-hidden="true">'
            . '<path d="M3 12a20 20 0 0128 0"/>'
            . '<path d="M8 18a13 13 0 0118 0"/>'
            . '<path d="M13 24a6 6 0 018 0"/>'
            . '<circle cx="17" cy="29" r="1.5" fill="#1f62ff" stroke="none"/>'
            . '</svg><span>YESNET</span>';
    }
}

if (!function_exists('stateIcon')) {
    function stateIcon(string $type): string
    {
        $allowed = ['ok', 'err', 'wait'];

        if (!in_array($type, $allowed, true)) {
            $type = 'wait';
        }

        $path = $type === 'ok'
            ? 'M14 27l8 8 16-17'
            : (
                $type === 'err'
                    ? 'M18 18l16 16M34 18L18 34'
                    : 'M26 15v14M26 36v1'
            );

        return '<svg class="state ' . e($type)
            . '" viewBox="0 0 52 52" aria-hidden="true">'
            . '<circle cx="26" cy="26" r="24"/>'
            . '<path d="' . $path . '"/>'
            . '</svg>';
    }
}

if (!function_exists('renderPaketCards')) {
    function renderPaketCards(
        array $pakets,
        string $action
    ): string {
        $out = '<div class="paket-grid">';

        $ikon = ['📶', '👨‍👩‍👧', '🎮', '💼'];

        foreach ($pakets as $i => $p) {
            $pop = ($i === 1);

            $id = (int) ($p['id'] ?? 0);
            $namaPaket = (string) ($p['nama_paket'] ?? 'Paket WiFi');
            $deskripsi = (string) (
                $p['deskripsi'] ?? 'Internet fiber optic'
            );
            $speed = (int) ($p['speed_mbps'] ?? 0);
            $harga = (float) ($p['harga'] ?? 0);

            $namaTampilan = preg_replace(
                '/^Paket\s+/i',
                '',
                $namaPaket
            );

            $out .= '<form method="post" action="' . e($action)
                . '" class="paket-card'
                . ($pop ? ' popular' : '')
                . '">';

            $out .= csrf_field();

            $out .= '<input type="hidden" name="paket_id" value="'
                . $id . '">';

            if ($pop) {
                $out .= '<span class="paket-tag">Paling Populer</span>';
            }

            $out .= '<div class="paket-head">'
                . '<span class="paket-ic">'
                . $ikon[$i % count($ikon)]
                . '</span>'
                . '<div><h3>' . e($namaTampilan) . '</h3>'
                . '<small>' . e($deskripsi) . '</small>'
                . '</div></div>';

            $out .= '<div class="paket-speed">'
                . $speed . ' Mbps</div>';

            $out .= '<div class="paket-price">'
                . rupiah($harga)
                . ' <small>/bulan</small></div>';

            $out .= '<ul>'
                . '<li>Internet Unlimited</li>'
                . '<li>WiFi Router</li>'
                . '<li>Instalasi Gratis</li>'
                . '</ul>';

            $out .= '<button class="btn '
                . ($pop ? 'btn-primary' : 'btn-outline')
                . ' btn-block" type="submit">Pilih Paket</button>';

            $out .= '</form>';
        }

        return $out . '</div>';
    }
}

/* ========================================================================
   AKHIR CONFIG / HELPER
   ======================================================================== */
