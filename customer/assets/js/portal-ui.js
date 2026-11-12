(function () {
    'use strict';

    const navigationItems = [
        ['Dashboard', 'bi-grid-fill', 'dashboard.php'],
        ['Internet Saya', 'bi-router-fill', 'usage.php'],
        ['Tagihan Saya', 'bi-receipt-cutoff', 'billing.php'],
        ['Riwayat Pembayaran', 'bi-clock-history', 'billing.php#riwayat-pembayaran'],
        ['Speed Test', 'bi-speedometer2', 'speedtest.php'],
        ['Laporan Gangguan', 'bi-tools', 'complaint.php'],
        ['Status Jaringan', 'bi-globe2', 'network_status.php'],
        ['Upgrade Paket', 'bi-arrow-up-circle-fill', 'upgrade.php'],
        ['Bantuan / Live Chat', 'bi-chat-dots-fill', 'chat.php'],
        ['Notifikasi', 'bi-bell-fill', 'notifikasi.php'],
        ['Profile Saya', 'bi-person-circle', 'profile.php']
    ];

    const pageLabels = {
        'dashboard.php': 'Dashboard',
        'usage.php': 'Internet Saya',
        'billing.php': 'Tagihan Saya',
        'speedtest.php': 'Speed Test',
        'complaint.php': 'Laporan Gangguan',
        'complaint_create.php': 'Buat Laporan Gangguan',
        'network_status.php': 'Status Jaringan',
        'upgrade.php': 'Upgrade Paket',
        'chat.php': 'Bantuan / Live Chat',
        'notifikasi.php': 'Notifikasi',
        'profile.php': 'Profile Saya',
        'profile_edit.php': 'Edit Profile',
        'service_request.php': 'Permintaan Layanan',
        'paket.php': 'Pilih Paket'
    };

    function currentPage() {
        return window.location.pathname.split('/').pop().toLowerCase();
    }

    function applyStoredTheme() {
        let theme = 'light';
        try {
            theme = window.localStorage.getItem('yesnet-customer-theme') || 'light';
        } catch (error) {
            console.warn('Preferensi tema tidak dapat dibaca.', error);
        }
        document.body.dataset.theme = theme === 'dark' ? 'dark' : 'light';
    }

    function updateThemeButton(button, icon) {
        const isDark = document.body.dataset.theme === 'dark';
        button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        button.setAttribute(
            'aria-label',
            isDark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap'
        );
        button.title = isDark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap';
        icon.className = isDark ? 'bi bi-sun' : 'bi bi-moon-stars';
    }

    function addThemeControl() {
        if (document.getElementById('themeToggle')) {
            return;
        }

        const host = document.querySelector(
            '.topbar-right, .topbar, .profile-navbar, .edit-navbar, .navbar'
        );
        if (!host) {
            return;
        }

        const button = document.createElement('button');
        const icon = document.createElement('i');
        button.type = 'button';
        button.className = 'portal-theme-toggle';
        button.appendChild(icon);
        updateThemeButton(button, icon);

        button.addEventListener('click', function () {
            document.body.dataset.theme =
                document.body.dataset.theme === 'dark' ? 'light' : 'dark';
            updateThemeButton(button, icon);
            try {
                window.localStorage.setItem(
                    'yesnet-customer-theme',
                    document.body.dataset.theme
                );
            } catch (error) {
                console.warn('Preferensi tema tidak dapat disimpan.', error);
            }
        });

        if (currentPage() === 'paket.php' && host.classList.contains('navbar')) {
            host.appendChild(button);
        } else {
            host.prepend(button);
        }
    }

    function createSidebar() {
        const sidebar = document.createElement('aside');
        sidebar.className = 'sidebar portal-profile-sidebar';
        sidebar.setAttribute('aria-label', 'Navigasi Customer Portal');
        sidebar.innerHTML =
            '<div class="portal-sidebar-brand">' +
                '<i class="bi bi-wifi"></i>' +
                '<div><strong>YESNET</strong><span>Customer Portal</span></div>' +
            '</div>' +
            '<nav class="sidebar-menu" aria-label="Menu Customer Portal"></nav>';

        const nav = sidebar.querySelector('nav');
        appendNavigationItems(nav);
        const logout = document.createElement('a');
        logout.href = '../logout.php';
        logout.className = 'menu-item portal-logout';
        logout.innerHTML = '<i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Keluar</span>';
        sidebar.appendChild(logout);
        document.body.prepend(sidebar);
        document.body.classList.add('portal-profile-shell');
    }

    function appendNavigationItems(nav) {
        if (!nav) {
            return;
        }

        const items = navigationItems;
        const current = currentPage();
        const currentHash = window.location.hash;

        const missingLinks = [];
        items.forEach(function (item) {
            const label = item[0];
            const icon = item[1];
            const href = item[2];
            const url = new URL(href, window.location.href);
            const route = url.pathname.split('/').pop().toLowerCase();
            const isHistory = href.indexOf('#riwayat-pembayaran') !== -1;
            const duplicate = Array.from(nav.querySelectorAll('a[href]')).some(function (link) {
                const linkUrl = new URL(link.getAttribute('href'), window.location.href);
                const linkRoute = linkUrl.pathname.split('/').pop().toLowerCase();
                return linkRoute === route &&
                    (linkUrl.hash === url.hash || (!linkUrl.hash && !url.hash));
            });

            if (duplicate) {
                return;
            }

            const link = document.createElement('a');
            link.href = href;
            link.className = 'menu-item';
            link.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i><span></span>';
            link.querySelector('span').textContent = label;
            if (route === current && (!isHistory || currentHash === '#riwayat-pembayaran')) {
                link.classList.add('active');
                link.setAttribute('aria-current', 'page');
            }
            missingLinks.push(link);
        });

        const profileLink = Array.from(nav.querySelectorAll('a[href]')).find(function (link) {
            return new URL(link.getAttribute('href'), window.location.href)
                .pathname.split('/').pop().toLowerCase() === 'profile.php';
        });
        missingLinks.forEach(function (link) {
            if (profileLink) {
                nav.insertBefore(link, profileLink);
            } else {
                nav.appendChild(link);
            }
        });
    }

    function normalizeExistingNavigation() {
        const page = currentPage();
        document.querySelectorAll('.sidebar-menu').forEach(function (nav) {
            appendNavigationItems(nav);
            nav.querySelectorAll('a[href]').forEach(function (link) {
                const url = new URL(link.getAttribute('href'), window.location.href);
                const samePage = url.pathname.split('/').pop().toLowerCase() === page;
                const historyPage = page === 'billing.php' &&
                    window.location.hash === '#riwayat-pembayaran';
                if (samePage && historyPage && !url.hash) {
                    link.classList.remove('active');
                    link.removeAttribute('aria-current');
                } else if (samePage &&
                    (!url.hash || url.hash === window.location.hash)) {
                    link.classList.add('active');
                    link.setAttribute('aria-current', 'page');
                }
            });
        });
    }

    function addBreadcrumb() {
        const page = currentPage();
        const label = pageLabels[page];
        if (!label || page === 'dashboard.php' ||
            document.querySelector('.main-content .breadcrumb, .main-content .portal-breadcrumb')) {
            return;
        }

        const breadcrumb = document.createElement('nav');
        breadcrumb.className = 'portal-breadcrumb';
        breadcrumb.setAttribute('aria-label', 'Breadcrumb');

        const home = document.createElement('a');
        home.href = page === 'paket.php' ? 'customer/dashboard.php' : 'dashboard.php';
        home.textContent = 'Dashboard';
        const separator = document.createElement('span');
        separator.setAttribute('aria-hidden', 'true');
        separator.textContent = '/';
        const current = document.createElement('span');
        current.textContent = label;
        current.setAttribute('aria-current', 'page');
        breadcrumb.append(home, separator, current);

        const main = document.querySelector('.main-content');
        const topbar = main && main.querySelector(':scope > .topbar');
        if (topbar && topbar.nextSibling) {
            main.insertBefore(breadcrumb, topbar.nextSibling);
        } else if (main) {
            main.prepend(breadcrumb);
        } else {
            const pageMain = document.querySelector('main, .hero, .package-section');
            if (pageMain) {
                pageMain.parentNode.insertBefore(breadcrumb, pageMain);
            }
        }
    }

    function addMobileNavigation() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar || document.getElementById('sidebarToggle') ||
            document.querySelector('.mobile-menu-button')) {
            return;
        }

        const host = document.querySelector('.topbar-right, .topbar, .profile-navbar, .edit-navbar');
        if (!host) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'portal-sidebar-toggle';
        button.setAttribute('aria-label', 'Buka menu navigasi');
        button.setAttribute('aria-expanded', 'false');
        button.innerHTML = '<i class="bi bi-list" aria-hidden="true"></i>';

        const overlay = document.createElement('div');
        overlay.className = 'portal-sidebar-overlay';
        overlay.setAttribute('aria-hidden', 'true');

        const close = function () {
            document.body.classList.remove('portal-menu-open');
            button.setAttribute('aria-expanded', 'false');
            button.setAttribute('aria-label', 'Buka menu navigasi');
        };
        button.addEventListener('click', function () {
            const open = document.body.classList.toggle('portal-menu-open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.setAttribute('aria-label', open ? 'Tutup menu navigasi' : 'Buka menu navigasi');
        });
        overlay.addEventListener('click', close);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });

        host.prepend(button);
        document.body.appendChild(overlay);
    }

    function initialize() {
        applyStoredTheme();
        if (currentPage() === 'profile.php' || currentPage() === 'profile_edit.php') {
            createSidebar();
        }
        normalizeExistingNavigation();
        addThemeControl();
        addBreadcrumb();
        addMobileNavigation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
