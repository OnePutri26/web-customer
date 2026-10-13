document.addEventListener("DOMContentLoaded", function () {

    const form = document.getElementById("covForm");
    const alamat = document.getElementById("alamat");
    const latitude = document.getElementById("latitude");
    const longitude = document.getElementById("longitude");
    const info = document.getElementById("covInfo");
    const gpsButton = document.getElementById("covGps");
    const submitButton = document.getElementById("covSubmit");

    if (!form || !alamat || !info) {
        return;
    }

    const submitLabel = submitButton ? submitButton.textContent : "";
    let submitting = false;

    function resetSubmitState() {
        submitting = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = submitLabel;
        }
    }

    /*
     * Validasi alamat + cegah submit ganda
     */
    form.addEventListener("submit", function (event) {

        if (submitting) {
            event.preventDefault();
            return;
        }

        // Array.from menghitung karakter (bukan unit UTF-16), sama seperti PHP.
        const length = Array.from(alamat.value.trim()).length;

        if (length < 10) {
            event.preventDefault();
            info.textContent =
                "Alamat terlalu pendek. Masukkan alamat lengkap minimal 10 karakter.";
            alamat.focus();
            return;
        }

        submitting = true;

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = "Mengecek...";
        }

        info.textContent = "Sedang mengecek ketersediaan jaringan YesNet...";
    });

    // Tombol Back browser bisa memulihkan halaman dalam keadaan tombol nonaktif.
    window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
            resetSubmitState();
        }
    });

    /*
     * GPS
     */
    if (gpsButton && navigator.geolocation) {

        gpsButton.hidden = false;

        gpsButton.addEventListener("click", function () {

            info.textContent = "Mengambil lokasi Anda...";

            navigator.geolocation.getCurrentPosition(

                function (position) {
                    latitude.value = position.coords.latitude.toFixed(7);
                    longitude.value = position.coords.longitude.toFixed(7);

                    info.textContent =
                        "Lokasi berhasil didapatkan. Anda tetap dapat memperbaiki alamat secara manual.";
                },

                function (error) {
                    latitude.value = "";
                    longitude.value = "";

                    info.textContent = error && error.code === 1
                        ? "Izin lokasi ditolak. Silakan masukkan alamat secara manual."
                        : "Lokasi tidak dapat digunakan. Silakan masukkan alamat secara manual.";
                },

                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        });
    }

    /*
     * Auto-cek: dipakai saat datang dari halaman lain dengan ?alamat=...&auto=1
     * (atribut data-auto sebelumnya diisi PHP tetapi tidak pernah dibaca).
     */
    if (form.dataset.auto === "1") {
        if (typeof form.requestSubmit === "function") {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

});