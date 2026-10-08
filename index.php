<?php
session_start();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="YesNet - Internet cepat, stabil dan terjangkau untuk rumah, keluarga dan bisnis."
    >

    <title>YesNet - Internet Cepat & Stabil</title>

    <link
        rel="stylesheet"
        href="landing.css?v=<?= time() ?>"
    >
</head>

<body>


<!-- =====================================================
     NAVBAR
===================================================== -->

<header class="navbar">

    <a
        href="#home"
        class="brand"
    >

        <div class="brand-wifi">
            <span></span>
            <span></span>
            <span></span>
        </div>

        <strong>
            YES<span>NET</span>
        </strong>

    </a>


    <nav class="nav-center">

        <a
            href="#home"
            class="active"
        >
            Home
        </a>

        <a href="#keunggulan">
            Keunggulan
        </a>

        <a href="#paket">
            Paket
        </a>

        <a href="#kontak">
            Kontak
        </a>

    </nav>


    <a
        href="login.php"
        class="nav-login"
    >

        <span class="login-icon">
            ♙
        </span>

        Login Customer

    </a>

</header>



<!-- =====================================================
     HERO
===================================================== -->

<section
    id="home"
    class="hero"
>

    <!-- Background decoration -->

    <div class="hero-glow hero-glow-one"></div>

    <div class="hero-glow hero-glow-two"></div>

    <div class="hero-grid"></div>


    <div class="hero-content">


        <!-- HERO LEFT -->

        <div class="hero-text">

            <div class="eyebrow">
                Internet untuk Hidup yang Lebih Baik
            </div>


            <h1>

                YesNet -
                Internet

                <span>
                    Cepat &amp; Stabil
                </span>

            </h1>


            <p class="hero-description">

                Nikmati koneksi internet fiber optic
                dengan kecepatan tinggi, stabil,
                dan harga terjangkau.

                Untuk rumah, keluarga, dan bisnis Anda.

            </p>


            <div class="hero-actions">

                <a
                    href="coverage.php"
                    class="btn btn-primary"
                >

                    <span>⌖</span>

                    Cek Coverage

                </a>


                <a
                    href="#paket"
                    class="btn btn-outline"
                >

                    <span>▱</span>

                    Lihat Paket

                </a>


                <a
                    href="login.php"
                    class="btn btn-outline btn-login-hero"
                >

                    <span>♙</span>

                    Login Customer

                </a>

            </div>


            <!-- HERO STATS -->

            <div class="hero-stats">

                <div class="hero-stat">

                    <div class="hero-stat-icon">
                        ◉
                    </div>

                    <div>
                        <strong>
                            99,9%
                        </strong>

                        <small>
                            Uptime Jaringan
                        </small>
                    </div>

                </div>


                <div class="hero-stat">

                    <div class="hero-stat-icon">
                        ⚡
                    </div>

                    <div>
                        <strong>
                            24/7
                        </strong>

                        <small>
                            Layanan
                        </small>
                    </div>

                </div>


                <div class="hero-stat">

                    <div class="hero-stat-icon">
                        ◈
                    </div>

                    <div>
                        <strong>
                            Profesional
                        </strong>

                        <small>
                            Tim Teknisi
                        </small>
                    </div>

                </div>

            </div>

        </div>



        <!-- =================================================
             HERO VISUAL
        ================================================= -->

        <div class="hero-visual">


            <!-- floating WiFi -->

            <div class="wifi-signal">

                <div class="wifi-arc arc-one"></div>

                <div class="wifi-arc arc-two"></div>

                <div class="wifi-arc arc-three"></div>

                <div class="wifi-dot"></div>

            </div>


            <!-- House illustration -->

            <div class="house-scene">


                <!-- sky -->

                <div class="scene-sky"></div>


                <!-- city -->

                <div class="city city-one"></div>
                <div class="city city-two"></div>
                <div class="city city-three"></div>


                <!-- ground -->

                <div class="ground"></div>


                <!-- house -->

                <div class="house">

                    <div class="roof-main"></div>

                    <div class="roof-side"></div>


                    <div class="house-wall">

                        <div class="window window-one">

                            <span></span>
                            <span></span>

                        </div>


                        <div class="window window-two">

                            <span></span>
                            <span></span>

                        </div>


                        <div class="door">

                            <div class="door-handle"></div>

                        </div>

                    </div>


                    <!-- balcony -->

                    <div class="balcony">

                        <div></div>
                        <div></div>
                        <div></div>
                        <div></div>
                        <div></div>

                    </div>

                </div>


                <!-- trees -->

                <div class="tree tree-one">
                    <span></span>
                </div>

                <div class="tree tree-two">
                    <span></span>
                </div>


                <!-- glowing network -->

                <div class="network-line line-one"></div>

                <div class="network-line line-two"></div>

                <div class="network-line line-three"></div>


                <div class="network-node node-one"></div>

                <div class="network-node node-two"></div>

                <div class="network-node node-three"></div>

            </div>


            <!-- handwritten message -->

            <div class="hero-note">

                Koneksi Stabil
                <br>

                untuk Masa Depan
                <br>

                Lebih Baik

                <span>
                    ↙
                </span>

            </div>

        </div>

    </div>

</section>



<!-- =====================================================
     KEUNGGULAN
===================================================== -->

<section
    id="keunggulan"
    class="advantages"
>

    <div class="advantage-grid">


        <div class="advantage-card">

            <div class="advantage-icon">
                ⚡
            </div>

            <h3>
                Kecepatan Tinggi
            </h3>

            <p>
                Streaming, gaming, dan bekerja
                lebih lancar tanpa hambatan.
            </p>

        </div>


        <div class="advantage-card">

            <div class="advantage-icon">
                ◈
            </div>

            <h3>
                Jaringan Stabil
            </h3>

            <p>
                Koneksi tetap stabil di segala
                aktivitas Anda.
            </p>

        </div>


        <div class="advantage-card">

            <div class="advantage-icon">
                ◉
            </div>

            <h3>
                Fiber Optic
            </h3>

            <p>
                Teknologi modern untuk internet
                terbaik.
            </p>

        </div>


        <div class="advantage-card">

            <div class="advantage-icon">
                ♢
            </div>

            <h3>
                Harga Terjangkau
            </h3>

            <p>
                Paket lengkap dengan harga
                bersahabat.
            </p>

        </div>


        <div class="advantage-card">

            <div class="advantage-icon">
                ♧
            </div>

            <h3>
                Layanan Pelanggan
            </h3>

            <p>
                Siap membantu kapan saja saat
                Anda membutuhkan.
            </p>

        </div>

    </div>

</section>



<!-- =====================================================
     COVERAGE
===================================================== -->

<section class="coverage-section">

    <div class="coverage-box">


        <div class="coverage-content">

            <div class="coverage-heading">

                <div class="coverage-icon">
                    ⌖
                </div>

                <div>

                    <h2>
                        Cek Coverage
                    </h2>

                    <p>
                        Pastikan alamat Anda sudah
                        terjangkau jaringan YesNet.
                    </p>

                </div>

            </div>


            <form
                action="coverage.php"
                method="get"
                class="coverage-form"
            >

                <div class="coverage-input">

                    <span>
                        ⌕
                    </span>

                    <input
                        type="text"
                        name="alamat"
                        placeholder="Masukkan alamat lengkap Anda..."
                    >

                </div>


                <button
                    type="submit"
                    class="coverage-button"
                >

                    ⌕
                    Cek Sekarang

                </button>

            </form>


            <!-- COVERAGE STEPS -->

            <div class="coverage-steps">

                <div class="coverage-step">

                    <span>
                        1
                    </span>

                    <div>

                        <strong>
                            Masukkan Alamat
                        </strong>

                        <small>
                            Ketik alamat lengkap rumah Anda
                        </small>

                    </div>

                </div>


                <div class="step-arrow">
                    →
                </div>


                <div class="coverage-step">

                    <span>
                        2
                    </span>

                    <div>

                        <strong>
                            Cek Ketersediaan
                        </strong>

                        <small>
                            Kami akan mengecek jaringan
                        </small>

                    </div>

                </div>


                <div class="step-arrow">
                    →
                </div>


                <div class="coverage-step">

                    <span>
                        3
                    </span>

                    <div>

                        <strong>
                            Pilih Paket
                        </strong>

                        <small>
                            Dapatkan rekomendasi paket
                        </small>

                    </div>

                </div>

            </div>

        </div>



        <!-- COVERAGE MAP -->

        <div class="coverage-map">

            <div class="map-road road-one"></div>

            <div class="map-road road-two"></div>

            <div class="map-road road-three"></div>


            <div class="map-house house-a"></div>

            <div class="map-house house-b"></div>

            <div class="map-house house-c"></div>

            <div class="map-house house-d"></div>

            <div class="map-house house-e"></div>


            <div class="map-pin">

                <div>
                    ⌖
                </div>

            </div>


            <div class="coverage-status">

                <span></span>

                <div>

                    <strong>
                        Jaringan YesNet
                    </strong>

                    <small>
                        Tersedia di Lokasi Anda
                    </small>

                </div>

            </div>

        </div>

    </div>

</section>



<!-- =====================================================
     PAKET
===================================================== -->

<section
    id="paket"
    class="packages-section"
>

    <div class="section-heading">

        <span class="section-label">
            Paket Internet
        </span>

        <h2>
            Pilih Paket yang Sesuai
            <br>
            dengan Kebutuhan Anda
        </h2>

        <p>
            Berbagai pilihan paket dengan
            kecepatan terbaik untuk rumah,
            keluarga, dan bisnis.
        </p>

    </div>


    <div class="packages-grid">


        <!-- BASIC -->

        <div class="package-card">

            <div class="package-top">

                <div class="package-icon">
                    ▣
                </div>

                <div>

                    <strong>
                        Basic
                    </strong>

                    <small>
                        Cocok untuk browsing dan sosmed
                    </small>

                </div>

            </div>


            <div class="package-speed">

                <span>
                    ◉
                </span>

                20 Mbps

            </div>


            <div class="package-price">

                Rp 200.000

                <small>
                    /bulan
                </small>

            </div>


            <ul>

                <li>
                    ✓ Internet Unlimited
                </li>

                <li>
                    ✓ WiFi Router
                </li>

                <li>
                    ✓ Instalasi Gratis
                </li>

            </ul>


            <a
                href="paket.php"
                class="package-button"
            >
                Pilih Paket
            </a>

        </div>



        <!-- FAMILY -->

        <div class="package-card popular">

            <div class="popular-label">
                Paling Popular
            </div>


            <div class="package-top">

                <div class="package-icon">
                    ▣
                </div>

                <div>

                    <strong>
                        Family
                    </strong>

                    <small>
                        Ideal untuk keluarga modern
                    </small>

                </div>

            </div>


            <div class="package-speed">

                <span>
                    ◉
                </span>

                50 Mbps

            </div>


            <div class="package-price">

                Rp 350.000

                <small>
                    /bulan
                </small>

            </div>


            <ul>

                <li>
                    ✓ Internet Unlimited
                </li>

                <li>
                    ✓ WiFi Router
                </li>

                <li>
                    ✓ Instalasi Gratis
                </li>

            </ul>


            <a
                href="paket.php"
                class="package-button"
            >
                Pilih Paket
            </a>

        </div>



        <!-- PREMIUM -->

        <div class="package-card premium">

            <div class="package-top">

                <div class="package-icon">
                    ◇
                </div>

                <div>

                    <strong>
                        Premium
                    </strong>

                    <small>
                        Untuk streaming & gaming
                    </small>

                </div>

            </div>


            <div class="package-speed">

                <span>
                    ◉
                </span>

                100 Mbps

            </div>


            <div class="package-price">

                Rp 500.000

                <small>
                    /bulan
                </small>

            </div>


            <ul>

                <li>
                    ✓ Internet Unlimited
                </li>

                <li>
                    ✓ WiFi Router
                </li>

                <li>
                    ✓ Instalasi Gratis
                </li>

            </ul>


            <a
                href="paket.php"
                class="package-button"
            >
                Pilih Paket
            </a>

        </div>



        <!-- BUSINESS -->

        <div class="package-card business">

            <div class="package-top">

                <div class="package-icon">
                    ⚒
                </div>

                <div>

                    <strong>
                        Business
                    </strong>

                    <small>
                        Solusi terbaik untuk bisnis Anda
                    </small>

                </div>

            </div>


            <div class="package-speed">

                <span>
                    ◉
                </span>

                200 Mbps

            </div>


            <div class="package-price">

                Rp 750.000

                <small>
                    /bulan
                </small>

            </div>


            <ul>

                <li>
                    ✓ Internet Unlimited
                </li>

                <li>
                    ✓ WiFi Router
                </li>

                <li>
                    ✓ Instalasi Gratis
                </li>

                <li>
                    ✓ IP Publik (Opsional)
                </li>

            </ul>


            <a
                href="paket.php"
                class="package-button"
            >
                Pilih Paket
            </a>

        </div>

    </div>

</section>



<!-- =====================================================
     PROSES BERLANGGANAN
===================================================== -->

<section class="process-section">

    <div class="section-heading">

        <span class="section-label">
            Cara Berlangganan
        </span>

        <h2>
            Proses Berlangganan
            <br>
            Mudah &amp; Cepat
        </h2>

        <p>
            Dari pendaftaran hingga instalasi,
            semua bisa dilakukan dengan mudah.
        </p>

    </div>


    <div class="process-grid">


        <div class="process-item">

            <div class="process-icon">
                ⌕
            </div>

            <div class="process-number">
                1
            </div>

            <h3>
                Cek Coverage
            </h3>

            <p>
                Pastikan alamat Anda
                terjangkau jaringan YesNet.
            </p>

        </div>


        <div class="process-arrow">
            →
        </div>


        <div class="process-item">

            <div class="process-icon">
                ▣
            </div>

            <div class="process-number">
                2
            </div>

            <h3>
                Daftar &amp; Pilih Paket
            </h3>

            <p>
                Isi data diri dan pilih
                paket internet yang diinginkan.
            </p>

        </div>


        <div class="process-arrow">
            →
        </div>


        <div class="process-item">

            <div class="process-icon">
                ▤
            </div>

            <div class="process-number">
                3
            </div>

            <h3>
                Lakukan Pembayaran
            </h3>

            <p>
                Pilih metode pembayaran
                yang tersedia.
            </p>

        </div>


        <div class="process-arrow">
            →
        </div>


        <div class="process-item">

            <div class="process-icon">
                ⚒
            </div>

            <div class="process-number">
                4
            </div>

            <h3>
                Instalasi &amp; Aktivasi
            </h3>

            <p>
                Teknisi kami akan datang
                ke lokasi Anda.
            </p>

        </div>


        <div class="process-arrow">
            →
        </div>


        <div class="process-item">

            <div class="process-icon">
                ◉
            </div>

            <div class="process-number">
                5
            </div>

            <h3>
                Nikmati Internet
            </h3>

            <p>
                Koneksi aktif dan siap
                digunakan sepanjang hari.
            </p>

        </div>

    </div>

</section>



<!-- =====================================================
     FOOTER
===================================================== -->

<footer id="kontak">

    <div class="footer-inner">


        <div class="footer-brand">

            <a
                href="#home"
                class="brand footer-logo"
            >

                <div class="brand-wifi">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>

                <strong>
                    YES<span>NET</span>
                </strong>

            </a>


            <p>
                Internet Cepat &amp; Stabil
            </p>

        </div>



        <div class="footer-column">

            <h4>
                Tautan Cepat
            </h4>

            <a href="#home">
                Home
            </a>

            <a href="#keunggulan">
                Keunggulan
            </a>

            <a href="#paket">
                Paket
            </a>

            <a href="#kontak">
                Kontak
            </a>

        </div>



        <div class="footer-column">

            <h4>
                Hubungi Kami
            </h4>

            <a href="#">
                ☎ &nbsp; 0812 3456 7890
            </a>

            <a href="#">
                ✉ &nbsp; cs@yesnet.my.id
            </a>

            <a href="#">
                ⌖ &nbsp; Jl. Merdeka No. 123
            </a>

        </div>



        <div class="footer-column">

            <h4>
                Ikuti Kami
            </h4>

            <div class="social-links">

                <a href="#">
                    f
                </a>

                <a href="#">
                    ◎
                </a>

                <a href="#">
                    ▶
                </a>

                <a href="#">
                    ◇
                </a>

            </div>

        </div>

    </div>


    <div class="footer-bottom">

        <span>
            © <?= date('Y') ?> YesNet.
            All rights reserved.
        </span>

        <span>
            Internet Cepat &amp; Stabil
        </span>

    </div>

</footer>



<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /* =============================================
           NAVBAR SCROLL
        ============================================= */

        const navbar =
            document.querySelector('.navbar');

        function updateNavbar() {

            if (!navbar) {
                return;
            }

            if (window.scrollY > 30) {

                navbar.classList.add(
                    'scrolled'
                );

            } else {

                navbar.classList.remove(
                    'scrolled'
                );

            }

        }

        window.addEventListener(
            'scroll',
            updateNavbar,
            { passive: true }
        );

        updateNavbar();



        /* =============================================
           SCROLL REVEAL
        ============================================= */

        const revealElements =
            document.querySelectorAll(
                '.advantage-card, ' +
                '.coverage-box, ' +
                '.package-card, ' +
                '.process-item'
            );


        if (
            'IntersectionObserver'
            in window
        ) {

            const observer =
                new IntersectionObserver(

                    function (entries) {

                        entries.forEach(
                            function (entry) {

                                if (
                                    entry.isIntersecting
                                ) {

                                    entry.target
                                        .classList
                                        .add(
                                            'is-visible'
                                        );

                                    observer.unobserve(
                                        entry.target
                                    );

                                }

                            }
                        );

                    },

                    {
                        threshold: .12
                    }

                );


            revealElements.forEach(
                function (element) {

                    observer.observe(
                        element
                    );

                }
            );

        } else {

            revealElements.forEach(
                function (element) {

                    element.classList.add(
                        'is-visible'
                    );

                }
            );

        }



        /* =============================================
           SMOOTH SCROLL
        ============================================= */

        document
            .querySelectorAll(
                'a[href^="#"]'
            )
            .forEach(
                function (link) {

                    link.addEventListener(
                        'click',
                        function (event) {

                            const targetId =
                                this.getAttribute(
                                    'href'
                                );

                            if (
                                !targetId ||
                                targetId === '#'
                            ) {
                                return;
                            }


                            const target =
                                document.querySelector(
                                    targetId
                                );


                            if (target) {

                                event.preventDefault();


                                target.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });

                            }

                        }
                    );

                }
            );



        /* =============================================
           ACTIVE NAVIGATION
        ============================================= */

        const sections =
            document.querySelectorAll(
                'section[id]'
            );

        const navLinks =
            document.querySelectorAll(
                '.nav-center a'
            );


        if (
            'IntersectionObserver'
            in window
        ) {

            const sectionObserver =
                new IntersectionObserver(

                    function (entries) {

                        entries.forEach(
                            function (entry) {

                                if (
                                    entry.isIntersecting
                                ) {

                                    navLinks
                                        .forEach(
                                            function (link) {

                                                link.classList
                                                    .remove(
                                                        'active'
                                                    );

                                            }
                                        );


                                    const activeLink =
                                        document.querySelector(
                                            '.nav-center a[href="#' +
                                            entry.target.id +
                                            '"]'
                                        );


                                    if (
                                        activeLink
                                    ) {

                                        activeLink
                                            .classList
                                            .add(
                                                'active'
                                            );

                                    }

                                }

                            }
                        );

                    },

                    {
                        rootMargin:
                            '-30% 0px -60% 0px'
                    }

                );


            sections.forEach(
                function (section) {

                    sectionObserver.observe(
                        section
                    );

                }
            );

        }

    }

);

</script>


</body>
</html>