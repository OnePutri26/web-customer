<?php

require_once "../auth_check.php";
require_once "../../config/database.php";

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $areaId = (int)($_POST['area_id'] ?? 0);
    $judul = trim($_POST['judul'] ?? '');
    $deskripsi = trim($_POST['deskripsi'] ?? '');
    $estimasi = $_POST['estimasi_selesai'] ?? null;

    if ($areaId <= 0) {

        $error = "Area wajib dipilih.";

    } elseif ($judul === '') {

        $error = "Judul gangguan wajib diisi.";

    } else {

        $stmt = $conn->prepare("
            INSERT INTO network_incidents
            (
                area_id,
                judul,
                deskripsi,
                mulai,
                estimasi_selesai
            )
            VALUES (?, ?, ?, NOW(), ?)
        ");

        $stmt->bind_param(
            "isss",
            $areaId,
            $judul,
            $deskripsi,
            $estimasi
        );

        $stmt->execute();

        $stmt = $conn->prepare("
            UPDATE network_areas
            SET status = 'gangguan'
            WHERE id = ?
        ");

        $stmt->bind_param("i", $areaId);
        $stmt->execute();

        header("Location: area.php");
        exit;
    }
}

$areas = $conn->query("
    SELECT id, nama_area
    FROM network_areas
    ORDER BY nama_area
");