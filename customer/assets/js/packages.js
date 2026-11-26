document.addEventListener('DOMContentLoaded', function () {

    /* ---------------- SIDEBAR MOBILE ---------------- */

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileButton = document.getElementById('mobileMenuButton');
    const closeButton = document.getElementById('sidebarClose');

    function isMobile() {
        return window.matchMedia
            ? window.matchMedia('(max-width: 900px)').matches
            : window.innerWidth <= 900;
    }

    function toggleSidebar(open) {
        if (!sidebar) { return; }
        sidebar.classList.toggle('show', open);
        if (overlay) {
            overlay.classList.toggle('show', open);
            overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        }
        document.body.classList.toggle('sidebar-open', open);
        if (mobileButton) { mobileButton.setAttribute('aria-expanded', open ? 'true' : 'false'); }

        if (isMobile()) {
            sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
            if (open) { sidebar.removeAttribute('inert'); } else { sidebar.setAttribute('inert', ''); }
        } else {
            sidebar.removeAttribute('aria-hidden');
            sidebar.removeAttribute('inert');
        }

        if (!open && document.activeElement === closeButton && mobileButton) {
            mobileButton.focus();
        }
    }

    if (mobileButton) { mobileButton.addEventListener('click', function () { toggleSidebar(true); }); }
    if (closeButton) { closeButton.addEventListener('click', function () { toggleSidebar(false); }); }
    if (overlay) { overlay.addEventListener('click', function () { toggleSidebar(false); }); }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { toggleSidebar(false); }
    });
    window.addEventListener('resize', function () {
        if (!isMobile()) { toggleSidebar(false); }
    });
    toggleSidebar(false);

    /* ---------------- TEMA ---------------- */

    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        document.body.dataset.theme = isDark ? 'dark' : 'light';
        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeToggle.setAttribute('aria-label', isDark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap');
        }
        if (themeToggleIcon) {
            themeToggleIcon.className = isDark ? 'bi bi-sun' : 'bi bi-moon-stars';
        }
    }

    try {
        applyTheme(localStorage.getItem('yesnet-customer-theme') || 'light');
    } catch (error) {
        applyTheme('light');
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            const next = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem('yesnet-customer-theme', next); } catch (error) { /* abaikan */ }
        });
    }

    /* ---------------- FILTER PAKET ---------------- */

    const chips = document.querySelectorAll('.plan-chip');
    const cards = document.querySelectorAll('.plan-card');
    const emptyState = document.getElementById('planFilterEmpty');

    function applyFilter(filter) {
        let visible = 0;
        cards.forEach(function (card) {
            const show = filter === 'all' || card.dataset.group === filter;
            card.hidden = !show;
            if (show) { visible += 1; }
        });
        chips.forEach(function (chip) {
            const active = chip.dataset.filter === filter;
            chip.classList.toggle('is-active', active);
            chip.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (emptyState) { emptyState.hidden = visible > 0; }
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () { applyFilter(chip.dataset.filter || 'all'); });
    });
});