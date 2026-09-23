"use strict";

/*
|--------------------------------------------------------------------------
| COVERAGE MAP
|--------------------------------------------------------------------------
*/

document.addEventListener("DOMContentLoaded", function () {

    const mapElement = document.getElementById("coverageMap");

    const latitudeInput = document.getElementById("latitude");
    const longitudeInput = document.getElementById("longitude");

    const latitudeDisplay = document.getElementById("latitudeDisplay");
    const longitudeDisplay = document.getElementById("longitudeDisplay");

    const getLocationBtn = document.getElementById("getLocationBtn");

    const openMapsBtn = document.getElementById("openMapsBtn");

    const coverageForm = document.getElementById("coverageForm");

    const checkCoverageBtn =
        document.getElementById("checkCoverageBtn");


    /*
    |--------------------------------------------------------------------------
    | CEK ELEMENT
    |--------------------------------------------------------------------------
    */

    if (!mapElement) {
        return;
    }


    /*
    |--------------------------------------------------------------------------
    | DEFAULT LOCATION
    |--------------------------------------------------------------------------
    |
    | Default hanya untuk tampilan awal.
    | User tetap bisa memilih titik sendiri.
    |
    */

    const DEFAULT_LATITUDE = -7.2575;
    const DEFAULT_LONGITUDE = 112.7521;


    /*
    |--------------------------------------------------------------------------
    | DATA AWAL
    |--------------------------------------------------------------------------
    */

    let initialLatitude =
        parseFloat(latitudeInput?.value);

    let initialLongitude =
        parseFloat(longitudeInput?.value);


    /*
    |--------------------------------------------------------------------------
    | VALIDASI KOORDINAT AWAL
    |--------------------------------------------------------------------------
    */

    if (
        !Number.isFinite(initialLatitude) ||
        !Number.isFinite(initialLongitude)
    ) {

        initialLatitude = DEFAULT_LATITUDE;
        initialLongitude = DEFAULT_LONGITUDE;
    }


    /*
    |--------------------------------------------------------------------------
    | INIT MAP
    |--------------------------------------------------------------------------
    */

    const map = L.map(
        mapElement,
        {
            zoomControl: true,
            scrollWheelZoom: true
        }
    ).setView(
        [
            initialLatitude,
            initialLongitude
        ],
        15
    );


    /*
    |--------------------------------------------------------------------------
    | OPEN STREET MAP
    |--------------------------------------------------------------------------
    */

    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            maxZoom: 20,
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>'
        }
    ).addTo(map);


    /*
    |--------------------------------------------------------------------------
    | MARKER
    |--------------------------------------------------------------------------
    */

    const marker = L.marker(
        [
            initialLatitude,
            initialLongitude
        ],
        {
            draggable: true
        }
    ).addTo(map);


    /*
    |--------------------------------------------------------------------------
    | POPUP
    |--------------------------------------------------------------------------
    */

    marker.bindPopup(
        `
        <div class="map-popup">
            <strong>Lokasi Pemasangan</strong>
            <br>
            Geser marker atau klik peta
            untuk mengubah lokasi.
        </div>
        `
    );


    /*
    |--------------------------------------------------------------------------
    | UPDATE COORDINATES
    |--------------------------------------------------------------------------
    */

    function updateCoordinates(latitude, longitude) {

        const lat =
            Number(latitude);

        const lng =
            Number(longitude);


        if (
            !Number.isFinite(lat) ||
            !Number.isFinite(lng)
        ) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | INPUT
        |--------------------------------------------------------------------------
        */

        if (latitudeInput) {
            latitudeInput.value =
                lat.toFixed(7);
        }

        if (longitudeInput) {
            longitudeInput.value =
                lng.toFixed(7);
        }


        /*
        |--------------------------------------------------------------------------
        | DISPLAY
        |--------------------------------------------------------------------------
        */

        if (latitudeDisplay) {

            latitudeDisplay.textContent =
                lat.toFixed(7);
        }

        if (longitudeDisplay) {

            longitudeDisplay.textContent =
                lng.toFixed(7);
        }


        /*
        |--------------------------------------------------------------------------
        | GOOGLE MAPS
        |--------------------------------------------------------------------------
        */

        if (openMapsBtn) {

            openMapsBtn.href =
                "https://www.google.com/maps/search/?api=1&query="
                + encodeURIComponent(
                    lat + "," + lng
                );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SET LOCATION
    |--------------------------------------------------------------------------
    */

    function setLocation(
        latitude,
        longitude,
        zoom = 17
    ) {

        const lat =
            Number(latitude);

        const lng =
            Number(longitude);


        if (
            !Number.isFinite(lat) ||
            !Number.isFinite(lng)
        ) {
            return;
        }


        marker.setLatLng(
            [
                lat,
                lng
            ]
        );


        map.setView(
            [
                lat,
                lng
            ],
            zoom
        );


        updateCoordinates(
            lat,
            lng
        );


        marker.openPopup();
    }


    /*
    |--------------------------------------------------------------------------
    | MARKER DRAG
    |--------------------------------------------------------------------------
    */

    marker.on(
        "dragend",
        function () {

            const position =
                marker.getLatLng();


            updateCoordinates(
                position.lat,
                position.lng
            );

        }
    );


    /*
    |--------------------------------------------------------------------------
    | MAP CLICK
    |--------------------------------------------------------------------------
    */

    map.on(
        "click",
        function (event) {

            setLocation(
                event.latlng.lat,
                event.latlng.lng,
                map.getZoom()
            );

        }
    );


    /*
    |--------------------------------------------------------------------------
    | GPS BUTTON
    |--------------------------------------------------------------------------
    */

    if (getLocationBtn) {

        getLocationBtn.addEventListener(
            "click",
            function () {

                if (!navigator.geolocation) {

                    alert(
                        "Browser Anda tidak mendukung GPS."
                    );

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | LOADING
                |--------------------------------------------------------------------------
                */

                const originalHTML =
                    getLocationBtn.innerHTML;


                getLocationBtn.disabled =
                    true;


                getLocationBtn.innerHTML =
                    `
                    <span class="button-spinner"></span>
                    Mengambil lokasi...
                    `;


                /*
                |--------------------------------------------------------------------------
                | GET LOCATION
                |--------------------------------------------------------------------------
                */

                navigator.geolocation.getCurrentPosition(

                    function (position) {

                        const latitude =
                            position.coords.latitude;

                        const longitude =
                            position.coords.longitude;


                        setLocation(
                            latitude,
                            longitude,
                            18
                        );


                        getLocationBtn.disabled =
                            false;


                        getLocationBtn.innerHTML =
                            originalHTML;
                    },


                    function (error) {

                        let message =
                            "Tidak dapat mengambil lokasi.";

                        switch (error.code) {

                            case error.PERMISSION_DENIED:

                                message =
                                    "Izin lokasi ditolak. Aktifkan izin lokasi pada browser.";

                                break;


                            case error.POSITION_UNAVAILABLE:

                                message =
                                    "Lokasi perangkat tidak tersedia.";

                                break;


                            case error.TIMEOUT:

                                message =
                                    "Pengambilan lokasi terlalu lama. Silakan coba lagi.";

                                break;
                        }


                        alert(message);


                        getLocationBtn.disabled =
                            false;


                        getLocationBtn.innerHTML =
                            originalHTML;

                    },

                    {
                        enableHighAccuracy: true,
                        timeout: 15000,
                        maximumAge: 0
                    }
                );

            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INITIAL COORDINATE
    |--------------------------------------------------------------------------
    */

    const existingLat =
        parseFloat(latitudeInput?.value);

    const existingLng =
        parseFloat(longitudeInput?.value);


    if (
        Number.isFinite(existingLat) &&
        Number.isFinite(existingLng)
    ) {

        setLocation(
            existingLat,
            existingLng,
            17
        );

    } else {

        /*
        |--------------------------------------------------------------------------
        | DEFAULT MAP ONLY
        |--------------------------------------------------------------------------
        */

        updateCoordinates(
            "",
            ""
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FORM VALIDATION
    |--------------------------------------------------------------------------
    */

    if (coverageForm) {

        coverageForm.addEventListener(
            "submit",
            function (event) {

                const address =
                    document
                        .getElementById("alamat")
                        ?.value
                        .trim();


                const latitude =
                    parseFloat(
                        latitudeInput?.value
                    );


                const longitude =
                    parseFloat(
                        longitudeInput?.value
                    );


                /*
                |--------------------------------------------------------------------------
                | ADDRESS
                |--------------------------------------------------------------------------
                */

                if (!address) {

                    event.preventDefault();

                    alert(
                        "Alamat pemasangan wajib diisi."
                    );

                    document
                        .getElementById("alamat")
                        ?.focus();

                    return;
                }


                if (address.length < 10) {

                    event.preventDefault();

                    alert(
                        "Masukkan alamat yang lebih lengkap."
                    );

                    document
                        .getElementById("alamat")
                        ?.focus();

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | COORDINATE
                |--------------------------------------------------------------------------
                */

                if (
                    !Number.isFinite(latitude) ||
                    !Number.isFinite(longitude)
                ) {

                    event.preventDefault();

                    alert(
                        "Lokasi belum dipilih. Gunakan GPS atau klik lokasi pada peta."
                    );

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | LOADING BUTTON
                |--------------------------------------------------------------------------
                */

                if (checkCoverageBtn) {

                    checkCoverageBtn.disabled =
                        true;

                    checkCoverageBtn.innerHTML =
                        `
                        <span>
                            <span class="button-spinner"></span>
                            Memeriksa coverage...
                        </span>
                        `;
                }

            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FIX LEAFLET SIZE
    |--------------------------------------------------------------------------
    */

    setTimeout(
        function () {

            map.invalidateSize();

        },
        300
    );

});