<?php
require_once __DIR__ . '/../config/app.php';

if (!isCustomer()) {
    redirect('../login.php');
}

$customer = currentCustomer($conn);
if (!$customer) {
    redirect('../login.php');
}
$customerId = (int) $customer['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        http_response_code(403);
        exit('Sesi kedaluwarsa. Kembali ke halaman sebelumnya dan coba lagi.');
    }

    $packageId = filter_var(
        $_POST['paket_id'] ?? $_POST['package_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $package = $packageId ? getPaket($conn, (int) $packageId) : null;
    $address = trim((string) ($customer['alamat'] ?? ''));
    if (!$package || $address === '') {
        http_response_code(422);
        exit('Paket atau alamat pelanggan tidak valid. Periksa profil pelanggan Anda.');
    }

    try {
        $tagihanId = ensureOrder($conn, $customer, $package, $address);
    } catch (Throwable $e) {
        error_log('Gagal menyiapkan pembayaran pelanggan: ' . $e->getMessage());
        http_response_code(500);
        exit('Tagihan belum dapat dibuat. Silakan coba kembali.');
    }

    redirect('../pembayaran.php?tagihan=' . $tagihanId);
}

$tagihanId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$tagihanId) {
    $stmt = $conn->prepare(
        "SELECT id
         FROM tagihan
         WHERE id_customer = ? AND status IN ('unpaid', 'pending')
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $tagihan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $tagihanId = $tagihan ? (int) $tagihan['id'] : 0;
}

if ($tagihanId > 0) {
    redirect('../pembayaran.php?tagihan=' . $tagihanId);
}

redirect('packages.php');
