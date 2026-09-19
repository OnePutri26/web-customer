<?php

session_start();

/*
|--------------------------------------------------------------------------
| DEBUG DATABASE
|--------------------------------------------------------------------------
*/

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "config/database.php";


/*
|--------------------------------------------------------------------------
| CEK DATABASE YANG AKTIF
|--------------------------------------------------------------------------
*/

$currentDatabase = $conn->query("SELECT DATABASE()")->fetch_row()[0];

if ($currentDatabase !== 'wifi_management') {
    die(
        "ERROR: Aplikasi belum menggunakan database wifi_management.<br>" .
        "Database aktif: " . htmlspecialchars($currentDatabase ?? 'NULL')
    );
}


/*
|--------------------------------------------------------------------------
| VARIABEL
|--------------------------------------------------------------------------
*/

$error = "";

$nama      = "";
$username  = "";
$email     = "";
$telephone = "";
$nik       = "";
$alamat    = "";


/*
|--------------------------------------------------------------------------
| PROSES REGISTRASI
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | AMBIL DATA FORM
    |--------------------------------------------------------------------------
    */

    $nama      = trim($_POST['nama'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $nik       = trim($_POST['nik'] ?? '');
    $alamat    = trim($_POST['alamat'] ?? '');


    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */

    if (
        $nama === '' ||
        $username === '' ||
        $email === '' ||
        $telephone === '' ||
        $password === '' ||
        $nik === '' ||
        $alamat === ''
    ) {

        $error = "Semua field wajib diisi.";

    } elseif (strlen($nama) < 3) {

        $error = "Nama minimal 3 karakter.";

    } elseif (strlen($username) < 4) {

        $error = "Username minimal 4 karakter.";

    } elseif (strlen($username) > 50) {

        $error = "Username maksimal 50 karakter.";

    } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {

        $error = "Username hanya boleh menggunakan huruf, angka, titik (.) dan underscore (_).";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Format email tidak valid.";

    } elseif (strlen($password) < 6) {

        $error = "Password minimal 6 karakter.";

    } elseif (!preg_match('/^[0-9]+$/', $nik)) {

        $error = "NIK hanya boleh berisi angka.";

    } elseif (strlen($nik) < 10) {

        $error = "NIK tidak valid.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | CEK USERNAME
        |--------------------------------------------------------------------------
        */

        try {

            $checkUsername = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $checkUsername->bind_param(
                "s",
                $username
            );

            $checkUsername->execute();

            $resultUsername = $checkUsername->get_result();

            if ($resultUsername->num_rows > 0) {

                $error = "Username sudah digunakan. Silakan pilih username lain.";
            }

            $checkUsername->close();


            /*
            |--------------------------------------------------------------------------
            | CEK EMAIL
            |--------------------------------------------------------------------------
            */

            if ($error === "") {

                $checkEmail = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                ");

                $checkEmail->bind_param(
                    "s",
                    $email
                );

                $checkEmail->execute();

                $resultEmail = $checkEmail->get_result();

                if ($resultEmail->num_rows > 0) {

                    $error = "Email sudah terdaftar.";
                }

                $checkEmail->close();
            }


            /*
            |--------------------------------------------------------------------------
            | CEK NIK
            |--------------------------------------------------------------------------
            */

            if ($error === "") {

                $checkNik = $conn->prepare("
                    SELECT id
                    FROM customers
                    WHERE nik = ?
                    LIMIT 1
                ");

                $checkNik->bind_param(
                    "s",
                    $nik
                );

                $checkNik->execute();

                $resultNik = $checkNik->get_result();

                if ($resultNik->num_rows > 0) {

                    $error = "NIK sudah terdaftar.";
                }

                $checkNik->close();
            }


            /*
            |--------------------------------------------------------------------------
            | SIMPAN DATA
            |--------------------------------------------------------------------------
            */

            if ($error === "") {

                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $conn->begin_transaction();


                /*
                |--------------------------------------------------------------------------
                | INSERT USERS
                |--------------------------------------------------------------------------
                */

                $userStmt = $conn->prepare("
                    INSERT INTO users
                    (
                        username,
                        nama,
                        email,
                        telephone,
                        password,
                        role,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'customer',
                        1
                    )
                ");

                $userStmt->bind_param(
                    "sssss",
                    $username,
                    $nama,
                    $email,
                    $telephone,
                    $passwordHash
                );

                $userStmt->execute();

                $userId = $userStmt->insert_id;

                $userStmt->close();


                /*
                |--------------------------------------------------------------------------
                | INSERT CUSTOMERS
                |--------------------------------------------------------------------------
                */

                $paketId = null;

                $customerStmt = $conn->prepare("
                    INSERT INTO customers
                    (
                        user_id,
                        paket_id,
                        nama,
                        telephone,
                        email,
                        nik,
                        alamat,
                        status_langganan
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'belum_berlangganan'
                    )
                ");

                $customerStmt->bind_param(
                    "iisssss",
                    $userId,
                    $paketId,
                    $nama,
                    $telephone,
                    $email,
                    $nik,
                    $alamat
                );

                $customerStmt->execute();

                $customerStmt->close();


                /*
                |--------------------------------------------------------------------------
                | COMMIT
                |--------------------------------------------------------------------------
                */

                $conn->commit();


                /*
                |--------------------------------------------------------------------------
                | SESSION
                |--------------------------------------------------------------------------
                */

                session_regenerate_id(true);

                $_SESSION['user_id']     = (int) $userId;
                $_SESSION['username']    = $username;
                $_SESSION['nama']        = $nama;
                $_SESSION['email']       = $email;
                $_SESSION['role']        = 'customer';
                $_SESSION['user_status'] = 1;


                /*
                |--------------------------------------------------------------------------
                | REDIRECT
                |--------------------------------------------------------------------------
                */

                header("Location: customer/langganan.php");
                exit;
            }

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | ROLLBACK
            |--------------------------------------------------------------------------
            */

            if ($conn->connect_errno === 0) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                    // Abaikan error rollback
                }
            }

            /*
            |--------------------------------------------------------------------------
            | TAMPILKAN ERROR SEBENARNYA
            |--------------------------------------------------------------------------
            */

            $error =
                "REGISTRASI GAGAL:<br><br>" .
                htmlspecialchars($e->getMessage()) .
                "<br><br>" .
                "File: " .
                htmlspecialchars($e->getFile()) .
                "<br>" .
                "Line: " .
                (int) $e->getLine();
        }
    }
}

?>
