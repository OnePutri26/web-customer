<?php
/*
|--------------------------------------------------------------------------
| YESNET - HELPER ALUR PUBLIK
|--------------------------------------------------------------------------
| Dipakai oleh: index, coverage, paket, detail_paket, verifikasi, order,
| pembayaran, hasil_pembayaran, status_order.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/database.php';

/*
| PAYMENT_DEMO = true  -> pembayaran disimulasikan (ada pilihan Berhasil/Gagal)
|                         dan tahap instalasi bisa disimulasikan di status_order.php.
| Ubah ke false saat Midtrans sudah dipasang (lihat config/midtrans.php).
*/
const PAYMENT_DEMO = true;

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function rupiah($v): string
{
    return 'Rp ' . number_format((float) $v, 0, ',', '.');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function nomor(string $prefix): string
{
    return $prefix . date('ymdHis') . random_int(10, 99);
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_ok(): bool
{
    return hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''));
}

/* ---------- SESSION FLOW (data sementara selama alur berlangganan) ---------- */
function flow(?string $key = null, $default = null)
{
    $f = $_SESSION['flow'] ?? [];
    return $key === null ? $f : ($f[$key] ?? $default);
}

function flowSet(array $data): void
{
    $_SESSION['flow'] = array_merge($_SESSION['flow'] ?? [], $data);
}

function isCustomer(): bool
{
    return !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'customer';
}

function isAktif(?string $s): bool
{
    return in_array(strtolower((string) $s), ['active', 'aktif'], true);
}

/* ---------- DATA ---------- */
function currentCustomer(mysqli $conn): ?array
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $st = $conn->prepare('SELECT * FROM customers WHERE user_id = ? LIMIT 1');
    $st->bind_param('i', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function listPaket(mysqli $conn, int $limit = 12): array
{
    $res = $conn->query("SELECT * FROM paket_wifi WHERE status IN ('aktif','active') ORDER BY harga ASC LIMIT " . (int) $limit);
    return $res->fetch_all(MYSQLI_ASSOC);
}

function getPaket(mysqli $conn, int $id): ?array
{
    $st = $conn->prepare("SELECT * FROM paket_wifi WHERE id = ? AND status IN ('aktif','active') LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/* ---------- COVERAGE (tabel odp, radius dalam km; cadangan: network_areas) ---------- */
function hitungJarakKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * 6371 * asin(min(1, sqrt($a)));
}

function cekCoverage(mysqli $conn, string $alamat, ?float $lat, ?float $lng): array
{
    $hasil = ['tersedia' => false, 'odp_id' => null, 'odp' => null, 'jarak' => null];

    if ($lat !== null && $lng !== null) {
        $best = null;
        $res = $conn->query('SELECT id, nama_odp, latitude, longitude, radius FROM odp');
        while ($o = $res->fetch_assoc()) {
            $j = hitungJarakKm($lat, $lng, (float) $o['latitude'], (float) $o['longitude']);
            if ($j <= (float) $o['radius'] && ($best === null || $j < $best['jarak'])) {
                $best = ['odp_id' => (int) $o['id'], 'odp' => $o['nama_odp'], 'jarak' => round($j, 3)];
            }
        }
        if ($best) {
            return array_merge($hasil, $best, ['tersedia' => true]);
        }
    }

    // Cadangan: alamat memuat nama area jaringan yang aktif
    $st = $conn->prepare("SELECT nama_area FROM network_areas WHERE status <> 'maintenance' AND ? LIKE CONCAT('%', nama_area, '%') LIMIT 1");
    $st->bind_param('s', $alamat);
    $st->execute();
    $area = $st->get_result()->fetch_assoc();
    $st->close();
    if ($area) {
        $hasil['tersedia'] = true;
        $hasil['odp'] = 'Area ' . $area['nama_area'];
    }
    return $hasil;
}

/* ---------- ORDER: subscription + pengajuan_pemasangan + tagihan (idempotent) ---------- */
function ensureOrder(mysqli $conn, array $cust, array $paket, string $alamat): int
{
    $cid = (int) $cust['id'];
    $pid = (int) $paket['id'];

    $st = $conn->prepare("
        SELECT t.id FROM tagihan t
        JOIN subscription s ON s.id = t.id_subscription
        WHERE t.id_customer = ? AND s.paket_id = ? AND s.status = 'pending'
          AND t.status IN ('unpaid','pending')
        ORDER BY t.id DESC LIMIT 1
    ");
    $st->bind_param('ii', $cid, $pid);
    $st->execute();
    $ada = $st->get_result()->fetch_assoc();
    $st->close();
    if ($ada) {
        return (int) $ada['id'];
    }

    $harga = (float) $paket['harga'];
    $conn->begin_transaction();
    try {
        $st = $conn->prepare("INSERT INTO subscription (id_customer, paket_id, harga, status) VALUES (?, ?, ?, 'pending')");
        $st->bind_param('iid', $cid, $pid, $harga);
        $st->execute();
        $subId = (int) $conn->insert_id;
        $st->close();

        $noPengajuan = nomor('PNG');
        $nama = (string) $cust['nama'];
        $tel = (string) $cust['telephone'];
        $st = $conn->prepare("
            INSERT INTO pengajuan_pemasangan
            (id_customer, paket_id, nomor_pengajuan, nama_pelanggan, telephone, alamat_pemasangan, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $st->bind_param('iissss', $cid, $pid, $noPengajuan, $nama, $tel, $alamat);
        $st->execute();
        $st->close();

        // Catat hasil cek coverage bila ada koordinat
        $lat = flow('lat');
        $lng = flow('lng');
        if ($lat !== null && $lng !== null) {
            $lat = (float) $lat;
            $lng = (float) $lng;
            $odpId = flow('odp_id');
            $jarak = flow('jarak');
            $st = $conn->prepare("
                INSERT INTO installation_requests
                (customer_id, paket_id, alamat_pemasangan, latitude, longitude, odp_id, jarak_odp, coverage_status, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'tercover', 'pending')
            ");
            $st->bind_param('iisddid', $cid, $pid, $alamat, $lat, $lng, $odpId, $jarak);
            $st->execute();
            $st->close();
        }

        $noTagihan = nomor('INV');
        $bln = (int) date('n');
        $thn = (int) date('Y');
        $st = $conn->prepare("
            INSERT INTO tagihan
            (id_customer, id_subscription, nomor_tagihan, periode_bulan, periode_tahun, jumlah, tanggal_terbit, jatuh_tempo, status)
            VALUES (?, ?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'unpaid')
        ");
        $st->bind_param('iisiid', $cid, $subId, $noTagihan, $bln, $thn, $harga);
        $st->execute();
        $tagihanId = (int) $conn->insert_id;
        $st->close();

        $st = $conn->prepare("UPDATE customers SET paket_id = ?, status_langganan = 'pending' WHERE id = ? LIMIT 1");
        $st->bind_param('ii', $pid, $cid);
        $st->execute();
        $st->close();

        $conn->commit();
        return $tagihanId;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/* ---------- TAMPILAN ---------- */
function logoHtml(): string
{
    return '<svg class="logo-mark" width="34" height="34" viewBox="0 0 34 34" fill="none" stroke="#1f62ff" stroke-width="4" stroke-linecap="round" aria-hidden="true"><path d="M3 12a20 20 0 0128 0"/><path d="M8 18a13 13 0 0118 0"/><path d="M13 24a6 6 0 018 0"/><circle cx="17" cy="29" r="1.5" fill="#1f62ff" stroke="none"/></svg><span>YESNET</span>';
}

function stateIcon(string $type): string
{
    $path = $type === 'ok' ? 'M14 27l8 8 16-17' : ($type === 'err' ? 'M18 18l16 16M34 18L18 34' : 'M26 15v14M26 36v1');
    return '<svg class="state ' . $type . '" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="' . $path . '"/></svg>';
}

function renderPaketCards(array $pakets, string $action): string
{
    $out = '<div class="paket-grid">';
    $ikon = ['📶', '👨‍👩‍👧', '🎮', '💼'];
    foreach ($pakets as $i => $p) {
        $pop = ($i === 1);
        $out .= '<form method="post" action="' . e($action) . '" class="paket-card' . ($pop ? ' popular' : '') . '">'
            . csrf_field()
            . '<input type="hidden" name="paket_id" value="' . (int) $p['id'] . '">'
            . ($pop ? '<span class="paket-tag">Paling Populer</span>' : '')
            . '<div class="paket-head"><span class="paket-ic">' . $ikon[$i % 4] . '</span><div><h3>' . e(preg_replace('/^Paket\s+/i', '', $p['nama_paket'])) . '</h3><small>' . e($p['deskripsi'] ?: 'Internet fiber optic') . '</small></div></div>'
            . '<div class="paket-speed">' . (int) $p['speed_mbps'] . ' Mbps</div>'
            . '<div class="paket-price">' . rupiah($p['harga']) . ' <small>/bulan</small></div>'
            . '<ul><li>Internet Unlimited</li><li>WiFi Router</li><li>Instalasi Gratis</li></ul>'
            . '<button class="btn ' . ($pop ? 'btn-primary' : 'btn-outline') . ' btn-block" type="submit">Pilih Paket</button>'
            . '</form>';
    }
    return $out . '</div>';
}
