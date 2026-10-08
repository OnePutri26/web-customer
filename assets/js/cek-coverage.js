document.addEventListener("DOMContentLoaded", function () {

    const form = document.getElementById("covForm");
    const alamat = document.getElementById("alamat");
    const latitude = document.getElementById("latitude");
    const longitude = document.getElementById("longitude");
    const info = document.getElementById("covInfo");
    const gpsButton = document.getElementById("covGps");

    if (!form || !alamat) {
        return;
    }


    /*
     * Validasi alamat
     */
    form.addEventListener("submit", function (event) {

        const value = alamat.value.trim();

        if (value.length < 10) {

            event.preventDefault();

            info.textContent =
                "Alamat terlalu pendek. Masukkan alamat lengkap minimal 10 karakter.";

            alamat.focus();

            return;
        }

        info.textContent =
            "Sedang mengecek ketersediaan jaringan YesNet...";

    });


    /*
     * GPS
     */
    if (
        gpsButton &&
        navigator.geolocation
    ) {

        gpsButton.hidden = false;

        gpsButton.addEventListener("click", function () {

            info.textContent =
                "Mengambil lokasi Anda...";

            navigator.geolocation.getCurrentPosition(

                function (position) {

                    latitude.value =
                        position.coords.latitude;

                    longitude.value =
                        position.coords.longitude;

                    info.textContent =
                        "Lokasi berhasil didapatkan. Anda tetap dapat memperbaiki alamat secara manual.";

                },

                function () {

                    info.textContent =
                        "Lokasi tidak dapat digunakan. Silakan masukkan alamat secara manual.";

                },

                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }

            );

        });

    }

});
