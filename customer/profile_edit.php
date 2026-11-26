<?php

/*
|--------------------------------------------------------------------------
| FITUR EDIT PROFILE DINONAKTIFKAN
|--------------------------------------------------------------------------
| Halaman ini tidak lagi menampilkan form maupun memproses perubahan data.
| Semua akses (GET maupun POST) langsung dialihkan ke profile.php beserta
| pesan pemberitahuan. Tidak ada query UPDATE yang dijalankan.
*/

session_start();

$_SESSION['profile_notice'] = "Fitur edit profil sedang dinonaktifkan. Silakan hubungi admin jika data Anda perlu diubah.";

// 303 memastikan request POST tidak diproses ulang
header("Location: profile.php", true, 303);
exit;