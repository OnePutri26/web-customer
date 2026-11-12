
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h2>YESNET ADMIN DIAGNOSTIC</h2>";

echo "<p>PHP Version: " . PHP_VERSION . "</p>";

echo "<hr><h3>1. Pemeriksaan session.php</h3>";

$sessionFile = __DIR__ . '/session.php';

if (file_exists($sessionFile)) {
    echo "File session.php ditemukan.<br>";

    try {
        require_once $sessionFile;
        echo "Session.php berhasil dimuat.<br>";
    } catch (Throwable $e) {
        echo "ERROR SESSION: " .
            htmlspecialchars($e->getMessage()) . "<br>";
    }
} else {
    echo "ERROR: session.php tidak ditemukan.<br>";
}

echo "<hr><h3>2. Pemeriksaan database.php</h3>";

$dbFile = __DIR__ . '/../config/database.php';

if (file_exists($dbFile)) {
    echo "File database.php ditemukan.<br>";

    try {
        require_once $dbFile;

        if (isset($conn) && $conn instanceof mysqli) {
            echo "Koneksi database tersedia.<br>";
        } else {
            echo "ERROR: Variabel conn tidak tersedia.<br>";
        }
    } catch (Throwable $e) {
        echo "ERROR DATABASE: " .
            htmlspecialchars($e->getMessage()) . "<br>";
    }
} else {
    echo "ERROR: database.php tidak ditemukan.<br>";
}

echo "<hr><h3>3. Pemeriksaan fungsi Admin</h3>";

$functions = [
    'adminClearSession',
    'adminVerifyCsrf',
    'adminPermissionsForRole',
    'adminCsrfToken'
];

foreach ($functions as $function) {
    echo htmlspecialchars($function) . ": ";

    echo function_exists($function)
        ? "TERSEDIA"
        : "TIDAK DITEMUKAN";

    echo "<br>";
}

echo "<hr><h3>4. Pemeriksaan MySQL</h3>";

if (isset($conn) && $conn instanceof mysqli) {
    try {
        $result = $conn->query("SHOW TABLES LIKE 'users'");

        echo $result->num_rows > 0
            ? "Tabel users ditemukan."
            : "Tabel users tidak ditemukan.";
    } catch (Throwable $e) {
        echo "ERROR MYSQL: " .
            htmlspecialchars($e->getMessage());
    }
}
?>
