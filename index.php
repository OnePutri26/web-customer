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

    <title>YesNet - Internet Cepat & Stabil</title>

    <link
        rel="stylesheet"
        href="landing.css"
    >
</head>

<body>

<header class="navbar">

    <div class="logo">
        YES<span>NET</span>
    </div>

    <nav>
        <a href="#home">Home</a>
        <a href="#keunggulan">Keunggulan</a>
        <a href="paket.php">Paket</a>
        <a href="#kontak">Kontak</a>
    </nav>

    <a
        href="login.php"
        class="nav-login"
    >
        Login
    </a>

</header>


<section
    id="home"
    class="hero"
>

    <div class="hero-content">

        <div class="hero-text">

            <span class="badge">
                INTERNET RUMAH YESNET
            </span>

            <h1>
                Internet Cepat,
                <span>Stabil & Terjangkau.</span>
            </h1>

            <p>
                Nikmati koneksi internet yang stabil
                untuk bekerja, belajar, streaming,
                gaming, dan kebutuhan keluarga.
            </p>

            <div class="hero-buttons">

                <a
                    href="coverage.php"
                    class="btn primary"
                >
                    Cek Coverage
                </a>

                <a
                    href="paket.php"
                    class="btn secondary"
                >
                    Lihat Paket
                </a>

            </div>

        </div>

        <div class="hero-card">

            <div class="wifi-icon">
                〽
            </div>

            <h3>
                Internet Rumah
            </h3>

            <p>
                Cepat. Stabil. Tanpa ribet.
            </p>

            <div class="speed">
                Hingga 100 Mbps
            </div>

        </div>

    </div>

</section>


<section
    id="keunggulan"
    class="section"
>

    <div class="section-title">

        <span>
            KENAPA YESNET?
        </span>

        <h2>
            Internet untuk kebutuhan sehari-hari
        </h2>

        <p>
            Dibuat sederhana supaya kamu bisa
            berlangganan tanpa harus melewati
            ritual administrasi yang panjang.
        </p>

    </div>


    <div class="features">

        <div class="feature">

            <div class="feature-icon">
                ⚡
            </div>

            <h3>
                Koneksi Cepat
            </h3>

            <p>
                Kecepatan internet sesuai
                kebutuhan rumah kamu.
            </p>

        </div>


        <div class="feature">

            <div class="feature-icon">
                📶
            </div>

            <h3>
                Stabil
            </h3>

            <p>
                Cocok untuk bekerja,
                belajar dan hiburan.
            </p>

        </div>


        <div class="feature">

            <div class="feature-icon">
                🛠
            </div>

            <h3>
                Teknisi Profesional
            </h3>

            <p>
                Instalasi dilakukan oleh
                teknisi YesNet.
            </p>

        </div>


        <div class="feature">

            <div class="feature-icon">
                💬
            </div>

            <h3>
                Customer Support
            </h3>

            <p>
                Bantuan pelanggan ketika
                kamu membutuhkan.
            </p>

        </div>

    </div>

</section>


<section class="cta">

    <div>

        <h2>
            Mau tahu apakah rumahmu
            sudah terjangkau YesNet?
        </h2>

        <p>
            Masukkan alamat dan cek coverage
            jaringan YesNet.
        </p>

    </div>

    <a
        href="coverage.php"
        class="btn white"
    >
        Cek Coverage Sekarang
    </a>

</section>


<footer id="kontak">

    <div class="logo">
        YES<span>NET</span>
    </div>

    <p>
        Internet cepat dan stabil
        untuk kebutuhanmu.
    </p>

    <div class="footer-links">

        <a href="coverage.php">
            Cek Coverage
        </a>

        <a href="paket.php">
            Lihat Paket
        </a>

        <a href="login.php">
            Login Customer
        </a>

    </div>

    <p class="copyright">
        © <?= date('Y') ?> YesNet.
        All Rights Reserved.
    </p>

</footer>

    <!-- ================================
         JAVASCRIPT ANIMASI
         ================================ -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // Navbar berubah saat scroll
            const navbar = document.querySelector('.navbar');

            window.addEventListener('scroll', function () {
                if (window.scrollY > 30) {
                    navbar?.classList.add('scrolled');
                } else {
                    navbar?.classList.remove('scrolled');
                }
            });


            // ================================
            // REVEAL ANIMATION SAAT SCROLL
            // ================================
            const revealElements = document.querySelectorAll(
                '.feature, .process-item, .stats, .cta'
            );

            const observer = new IntersectionObserver(
                function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            observer.unobserve(entry.target);
                        }
                    });
                },
                {
                    threshold: 0.15
                }
            );

            revealElements.forEach(function (element) {
                observer.observe(element);
            });


            // ================================
            // COUNTER 0 → 100
            // ================================
            const speedValue = document.querySelector('.speed-value');

            if (speedValue) {
                let started = false;

                const counterObserver = new IntersectionObserver(
                    function (entries) {
                        entries.forEach(function (entry) {

                            if (entry.isIntersecting && !started) {
                                started = true;

                                let current = 0;
                                const target = 100;
                                const duration = 1200;
                                const increment = target / (duration / 16);

                                const counter = setInterval(function () {

                                    current += increment;

                                    if (current >= target) {
                                        current = target;
                                        clearInterval(counter);
                                    }

                                    speedValue.textContent = Math.floor(current);

                                }, 16);

                                counterObserver.unobserve(entry.target);
                            }

                        });
                    },
                    {
                        threshold: 0.5
                    }
                );

                counterObserver.observe(speedValue);
            }


            // ================================
            // SMOOTH SCROLL
            // ================================
            document.querySelectorAll('a[href^="#"]').forEach(function (link) {

                link.addEventListener('click', function (e) {

                    const targetId = this.getAttribute('href');

                    if (!targetId || targetId === '#') {
                        return;
                    }

                    const target = document.querySelector(targetId);

                    if (target) {
                        e.preventDefault();

                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }

                });

            });

        });
    </script>

</body>
</html>

</body>
</html>
