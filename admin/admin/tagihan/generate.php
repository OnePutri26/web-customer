<?php

require_once "../auth_check.php";
require_once "../../config/database.php";

$periodeMulai = date('Y-m-01');
$periodeSelesai = date(
    'Y-m-t',
    strtotime($periodeMulai)
);

$jatuhTempo = date(
    'Y-m-d',
    strtotime('+10 days', strtotime($periodeMulai))
);

$sql = "
    SELECT
        s.id AS subscription_id,
        s.customer_id,
        s.paket_id,
        s.harga
    FROM subscriptions s

    WHERE s.status = 'aktif'
";

$result = $conn->query($sql);

$jumlahDibuat = 0;

while ($row = $result->fetch_assoc()) {

    $customerId = (int)$row['customer_id'];
    $subscriptionId = (int)$row['subscription_id'];
    $harga = (float)$row['harga'];

    $stmt = $conn->prepare("
        SELECT id
        FROM invoices
        WHERE customer_id = ?
        AND periode_mulai = ?
        AND periode_selesai = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "iss",
        $customerId,
        $periodeMulai,
        $periodeSelesai
    );

    $stmt->execute();

    $existing = $stmt->get_result()->fetch_assoc();

    if ($existing) {
        continue;
    }

    $nomorInvoice =
        'INV-' .
        date('Ym') .
        '-' .
        str_pad(
            (string)$customerId,
            6,
            '0',
            STR_PAD_LEFT
        );

    $stmt = $conn->prepare("
        INSERT INTO invoices
        (
            customer_id,
            subscription_id,
            nomor_invoice,
            periode_mulai,
            periode_selesai,
            jumlah,
            jatuh_tempo,
            status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 'belum_bayar')
    ");

    $stmt->bind_param(
        "iisssds",
        $customerId,
        $subscriptionId,
        $nomorInvoice,
        $periodeMulai,
        $periodeSelesai,
        $harga,
        $jatuhTempo
    );

    $stmt->execute();

    $jumlahDibuat++;
}

echo "Berhasil membuat {$jumlahDibuat} invoice.";