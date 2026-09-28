<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "103.209.250.78";
$user = "wifi_app";
$pass = "PasswordKuat123!";
$db   = "wifi_management";
$port = 3306;

try {

    $conn = new mysqli(
        $host,
        $user,
        $pass,
        $db,
        $port
    );

    $conn->set_charset("utf8mb4");

} catch (mysqli_sql_exception $e) {

    die("Koneksi database gagal: " . $e->getMessage());

}