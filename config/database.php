<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "103.209.250.78";
$user = "wifi_app";
$pass = "PasswordKuat123!";
$db   = "wifi_management";

$conn = new mysqli(
    $host,
    $user,
    $pass,
    $db
);

$conn->set_charset("utf8mb4");