<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($value)
{
    return 'Rp ' . number_format(
        (float)$value,
        0,
        ',',
        '.'
    );
}


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
|
| Untuk sementara kita hanya memastikan ada user_id.
|
*/

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| DATA DUMMY CUSTOMER
|--------------------------------------------------------------------------
|
| DATA INI HANYA UNTUK TESTING DASHBOARD
|
*/

$dummyCustomer = [

    'id' => 1,

    'user_id' => $userId,

    'paket_id' => 1,

    'nama' => $_SESSION['nama']
        ?? $_SESSION['username']
        ?? 'Customer Dummy',

    'email' => $_SESSION['email']
        ?? 'customer@example.com',

    'telephone' => $_SESSION['telephone']
        ?? $_SESSION['no_hp']
        ?? '081234567890',

    'nik' => '3500000000000001',

    'alamat' => 'Jl. Contoh No. 123, Indonesia',

    /*
    |--------------------------------------------------------------------------
    | PENTING
    |--------------------------------------------------------------------------
    |
    | pending = dashboard customer bisa dibuka
    | active  = layanan dianggap aktif
    |
    */

    'status_langganan' => 'pending'

];


/*
|--------------------------------------------------------------------------
| DATA DUMMY PAKET
|--------------------------------------------------------------------------
*/

$dummyPackage = [

    'id' => 1,

    'nama_paket' => 'WiFi Home 50 Mbps',

    'speed_mbps' => 50,

    'harga' => 250000,

    'deskripsi' =>
        'Internet rumah cepat dan stabil ' .
        'untuk kebutuhan keluarga.',

    'status' => 'aktif'

];


/*
|--------------------------------------------------------------------------
| AMBIL PAKET DARI URL
|--------------------------------------------------------------------------
|
| Contoh:
|
| payment.php?paket_id=1
|
*/

$packageId = (int)(
    $_GET['paket_id']
    ?? $_POST['paket_id']
    ?? 1
);


/*
|--------------------------------------------------------------------------
| UNTUK TESTING
|--------------------------------------------------------------------------
|
| Apapun paket_id yang dikirim, sementara kita
| tetap menggunakan paket dummy.
|
*/

$dummyPackage['id'] = $packageId;


/*
|--------------------------------------------------------------------------
| SIMPAN DATA DUMMY KE SESSION
|--------------------------------------------------------------------------
|
| Dashboard dapat menggunakan session ini jika
| membutuhkan data customer.
|
*/

$_SESSION['dummy_customer'] = $dummyCustomer;

$_SESSION['dummy_package'] = $dummyPackage;

$_SESSION['pengajuan_paket_id'] =
    $dummyPackage['id'];

$_SESSION['payment_package_id'] =
    $dummyPackage['id'];

$_SESSION['payment_customer_id'] =
    $dummyCustomer['id'];


/*
|--------------------------------------------------------------------------
| SIMPAN KE DATABASE
|--------------------------------------------------------------------------
|
| Bagian ini mencoba menyimpan paket dan status
| ke customer yang sedang login.
|
| Kalau database/schema belum cocok, dashboard
| tetap bisa dicoba menggunakan session dummy.
|
*/

try {

    /*
    |--------------------------------------------------------------------------
    | CEK APAKAH CUSTOMER ADA
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT id
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $realCustomer = $result->fetch_assoc();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | JIKA CUSTOMER ADA
    |--------------------------------------------------------------------------
    */

    if ($realCustomer) {

        $realCustomerId =
            (int)$realCustomer['id'];


        /*
        |--------------------------------------------------------------------------
        | SIMPAN PAKET + STATUS
        |--------------------------------------------------------------------------
        */

        $stmtUpdate = $conn->prepare("
            UPDATE customers
            SET
                paket_id = ?,
                status_langganan = 'pending'
            WHERE id = ?
            LIMIT 1
        ");

        $stmtUpdate->bind_param(
            "ii",
            $dummyPackage['id'],
            $realCustomerId
        );

        $stmtUpdate->execute();

        $stmtUpdate->close();


        /*
        |--------------------------------------------------------------------------
        | UPDATE SESSION CUSTOMER ID
        |--------------------------------------------------------------------------
        */

        $_SESSION['payment_customer_id'] =
            $realCustomerId;

    }


} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | JANGAN HENTIKAN TESTING
    |--------------------------------------------------------------------------
    |
    | Kalau database belum cocok, kita tetap
    | lanjut ke dashboard menggunakan dummy session.
    |
    */

}


/*
|--------------------------------------------------------------------------
| STATUS DUMMY
|--------------------------------------------------------------------------
*/

$_SESSION['subscription_status'] = 'pending';


/*
|--------------------------------------------------------------------------
| TANDA TESTING
|--------------------------------------------------------------------------
*/

$_SESSION['dummy_mode'] = true;


/*
|--------------------------------------------------------------------------
| LANGSUNG KE DASHBOARD
|--------------------------------------------------------------------------
*/

header("Location: dashboard.php");

exit;

?>
