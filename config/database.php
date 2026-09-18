<?php

$host = "103.209.250.42";
$user = "root";
$password = "";
$database = "wifi_management";

$conn = new mysqli(
    $host,
    $user,
    $password,
    $database
);

if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");