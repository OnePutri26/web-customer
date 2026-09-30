"use strict";

/*
|--------------------------------------------------------------------------
| YESNET COVERAGE JAVASCRIPT
|--------------------------------------------------------------------------
*/

document.addEventListener("DOMContentLoaded", function () {

    const mapElement =
        document.getElementById("coverageMap");

    const latitudeInput =
        document.getElementById("latitude");

    const longitudeInput =
        document.getElementById("longitude");

    const latitudeDisplay =
        document.getElementById("latitudeDisplay");

    const longitudeDisplay =
        document.getElementById("longitudeDisplay");

    const getLocationBtn =
        document.getElementById("getLocationBtn");

    const coverageForm =
        document.getElementById("coverageForm");

    const checkCoverageBtn =
        document.getElementById("checkCoverageBtn");

    const openMapsBtn =
        document.getElementById("openMapsBtn");


    /*
    |--------------------------------------------------------------------------
    | DEFAULT LOCATION
    |--------------------------------------------------------------------------
    |
    | Jika belum ada koordinat, gunakan pusat Indonesia.
    | Nantinya user dapat klik peta atau gunakan GPS.
    |
    */

    const defaultLatitude = -2.5489;
    const defaultLongitude = 118.0149;


    /*
    |--------------------------------------------------------------------------
    | CEK LEAFLET
    |--------------------------------------------------------------------------
    */

    if (
        typeof L === "undefined"
    ) {

        console.error(
            "Leaflet gagal dimuat."
        );

        if (mapElement) {

            mapElement.innerHTML = `
                <div style="
                    height:100%;
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    padding:20px;
                    text-align:center;
                    color:#64748b;
                    background:#f8fafc;
                    font-size:13px;
                ">
                    Peta gagal dimuat.
                    Silakan refresh halaman.
                </div>
            `;
        }

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | KOORDINAT AWAL
    |--------------------------------------------------------------------------
    */

    let initialLatitude =
        parseFloat(
            latitudeInput?.value
        );

    let initialLongitude =
        parseFloat(
            longitudeInput?.value
        );


    if (
        Number.isNaN(initialLatitude) ||
        Number.isNaN(initialLongitude)
    ) {

        initialLatitude =
            defaultLatitude;

        initialLongitude =
            defaultLongitude;
    }


    /*
    |--------------------------------------------------------------------------
    | INIT MAP
    |--------------------------------------------------------------------------
    */

    const map =
        L.map(
            mapElement,
            {
                center: [
                    initialLatitude,
                    initialLongitude
                ],

                zoom:
                    latitudeInput?.value &&
                    longitudeInput?.value
                        ? 17
                        : 5,

                zoomControl: true,

                attributionControl: true
            }
        );


    /*
    |--------------------------------------------------------------------------
    | TILE
    |--------------------------------------------------------------------------
    */

    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            maxZoom: 20,

            attribution:
                '&copy; OpenStreetMap contributors'
        }
    ).addTo(map);


    /*
    |--------------------------------------------------------------------------
    | MARKER
    |--------------------------------------------------------------------------
    */

    let marker = null;


    /*
    |--------------------------------------------------------------------------
    | UPDATE COORDINATE
    |--------------------------------------------------------------------------
    */

    function updateCoordinates(
        latitude,
        longitude,
        moveMap = true
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


        const latFixed =
            lat.toFixed(7);

        const lngFixed =
            lng.toFixed(7);


        /*
        | Hidden input
        */

        if (latitudeInput) {
            latitudeInput.value =
                latFixed;
        }

        if (longitudeInput) {
            longitudeInput.value =
                lngFixed;
        }


        /*
        | Display
        */

        if (latitudeDisplay) {

            latitudeDisplay.textContent =
                latFixed;
        }

        if (longitudeDisplay) {

            longitudeDisplay.textContent =
                lngFixed;
        }


        /*
        | Marker
        */

        if (!marker) {

            marker =
                L.marker(
                    [lat, lng],
                    {
                        draggable: true
                    }
                ).addTo(map);


            /*
            | Marker drag
            */

            marker.on(
                "dragend",
                function (event) {

                    const position =
                        event.target.getLatLng();

                    updateCoordinates(
                        position.lat,
                        position.lng,
                        false
                    );

                    updateGoogleMapsLink();
                }
            );

        } else {

            marker.setLatLng(
                [lat, lng]
            );
        }


        /*
        | Pindahkan map
        */

        if (moveMap) {

            map.setView(
                [lat, lng],
                17,
                {
                    animate: true
                }
            );
        }


        updateGoogleMapsLink();
    }


    /*
    |--------------------------------------------------------------------------
    | KLIK MAP
    |--------------------------------------------------------------------------
    */

    map.on(
        "click",
        function (event) {

            updateCoordinates(
                event.latlng.lat,
                event.latlng.lng,
                true
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | INIT MARKER JIKA SUDAH ADA
    |--------------------------------------------------------------------------
    */

    if (
        latitudeInput &&
        longitudeInput &&
        latitudeInput.value !== "" &&
        longitudeInput.value !== ""
    ) {

        updateCoordinates(
            parseFloat(
                latitudeInput.value
            ),

            parseFloat(
                longitudeInput.value
            ),

            true
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GPS
    |--------------------------------------------------------------------------
    */

    if (getLocationBtn) {

        getLocationBtn.addEventListener(
            "click",
            function () {

                if (
                    !navigator.geolocation
                ) {

                    alert(
                        "Browser Anda tidak mendukung GPS."
                    );

                    return;
                }


                getLocationBtn.disabled =
                    true;


                const originalHTML =
                    getLocationBtn.innerHTML;


                getLocationBtn.innerHTML = `
                    <i class="bi bi-arrow-repeat"></i>
                    <span>Mengambil lokasi...</span>
                `;


                navigator.geolocation.getCurrentPosition(

                    function (position) {

                        const latitude =
                            position.coords.latitude;

                        const longitude =
                            position.coords.longitude;


                        updateCoordinates(
                            latitude,
                            longitude,
                            true
                        );


                        getLocationBtn.disabled =
                            false;


                        getLocationBtn.innerHTML =
                            originalHTML;
                    },


                    function (error) {

                        let message =
                            "Tidak dapat mengambil lokasi.";

                        switch (
                            error.code
                        ) {

                            case error.PERMISSION_DENIED:

                                message =
                                    "Izin lokasi ditolak. "
                                    +
                                    "Silakan aktifkan izin lokasi "
                                    +
                                    "pada browser.";

                                break;


                            case error.POSITION_UNAVAILABLE:

                                message =
                                    "Informasi lokasi tidak tersedia.";

                                break;


                            case error.TIMEOUT:

                                message =
                                    "Pengambilan lokasi terlalu lama.";

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
    | GOOGLE MAPS
    |--------------------------------------------------------------------------
    */

    function updateGoogleMapsLink() {

        if (!openMapsBtn) {
            return;
        }


        const latitude =
            latitudeInput?.value;

        const longitude =
            longitudeInput?.value;


        if (
            latitude === "" ||
            longitude === "" ||
            !Number.isFinite(
                parseFloat(latitude)
            ) ||
            !Number.isFinite(
                parseFloat(longitude)
            )
        ) {

            openMapsBtn.href = "#";

            openMapsBtn.classList.add(
                "is-disabled"
            );

            openMapsBtn.setAttribute(
                "aria-disabled",
                "true"
            );

            openMapsBtn.setAttribute(
                "tabindex",
                "-1"
            );

            return;
        }


        const url =
            "https://www.google.com/maps/search/?api=1&query="
            +
            encodeURIComponent(
                latitude + "," + longitude
            );


        openMapsBtn.href =
            url;


        openMapsBtn.classList.remove(
            "is-disabled"
        );


        openMapsBtn.setAttribute(
            "aria-disabled",
            "false"
        );


        openMapsBtn.removeAttribute(
            "tabindex"
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PREVENT GOOGLE MAPS DISABLED CLICK
    |--------------------------------------------------------------------------
    */

    if (openMapsBtn) {

        openMapsBtn.addEventListener(
            "click",
            function (event) {

                if (
                    openMapsBtn.classList.contains(
                        "is-disabled"
                    )
                ) {

                    event.preventDefault();

                    alert(
                        "Tentukan lokasi terlebih dahulu."
                    );
                }
            }
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

                const alamat =
                    document
                        .getElementById("alamat")
                        ?.value
                        .trim();


                const latitude =
                    latitudeInput?.value
                    .trim();


                const longitude =
                    longitudeInput?.value
                    .trim();


                if (!alamat) {

                    event.preventDefault();

                    alert(
                        "Alamat pemasangan wajib diisi."
                    );

                    return;
                }


                if (
                    !latitude ||
                    !longitude
                ) {

                    event.preventDefault();

                    alert(
                        "Silakan tentukan lokasi "
                        +
                        "pemasangan terlebih dahulu."
                    );

                    return;
                }


                if (
                    checkCoverageBtn
                ) {

                    checkCoverageBtn.disabled =
                        true;

                    checkCoverageBtn.classList.add(
                        "loading"
                    );

                    checkCoverageBtn.dataset.original =
                        checkCoverageBtn.innerHTML;


                    checkCoverageBtn.innerHTML = `
                        <span>
                            <i class="bi bi-search"></i>
                            Mengecek Coverage...
                        </span>
                        <i class="bi bi-arrow-repeat"></i>
                    `;
                }
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FIX MAP SIZE
    |--------------------------------------------------------------------------
    */

    setTimeout(
        function () {

            map.invalidateSize();

        },
        300
    );


    window.addEventListener(
        "resize",
        function () {

            map.invalidateSize();

        }
    );

});
