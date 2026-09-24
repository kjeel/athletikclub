/**
 * Athletikclub Steiermark – Dashboard JavaScript
 */

(function () {
    'use strict';

    // ============================================================
    // Sidebar Mobile Toggle
    // ============================================================
    // Sichtbarkeit des Menü-Buttons steuert das CSS (ab 1024px abwärts)
    const sidebarToggle   = document.getElementById('sidebar-toggle');
    const sidebar         = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebar-backdrop');

    function setSidebar(offen) {
        sidebar.classList.toggle('open', offen);
        sidebarToggle.classList.toggle('active', offen);
        sidebarToggle.setAttribute('aria-expanded', offen ? 'true' : 'false');
        sidebarToggle.setAttribute('aria-label', offen ? 'Menü schließen' : 'Menü öffnen');
        document.body.classList.toggle('sidebar-offen', offen);
        if (sidebarBackdrop) sidebarBackdrop.hidden = !offen;
    }

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function () {
            setSidebar(!sidebar.classList.contains('open'));
        });
        if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', function () { setSidebar(false); });

        // Nach Klick auf einen Menüpunkt schließen
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () { setSidebar(false); });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) setSidebar(false);
        });

        // Beim Wechsel auf Desktop-Breite Menü-Zustand zurücksetzen
        window.matchMedia('(min-width: 1025px)').addEventListener('change', function (mq) {
            if (mq.matches) setSidebar(false);
        });
    }

    // ============================================================
    // Benutzermenü (JK oben rechts) – per Tipp/Klick öffnen, da Hover am Handy fehlt
    // ============================================================
    const userMenu = document.getElementById('user-menu');

    function setUserMenu(offen) {
        userMenu.classList.toggle('open', offen);
        userMenu.setAttribute('aria-expanded', offen ? 'true' : 'false');
    }

    if (userMenu) {
        userMenu.addEventListener('click', function (e) {
            if (e.target.closest('.dropdown-menu a')) return; // Link normal öffnen
            const offen = !userMenu.classList.contains('open');
            setUserMenu(offen);
            if (!offen) userMenu.blur(); // sonst hält :focus-within das Menü offen
        });
        userMenu.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target === userMenu) {
                e.preventDefault();
                setUserMenu(!userMenu.classList.contains('open'));
            }
            if (e.key === 'Escape') { setUserMenu(false); userMenu.blur(); }
        });
        document.addEventListener('click', function (e) {
            if (!userMenu.contains(e.target)) {
                setUserMenu(false);
                if (userMenu.contains(document.activeElement)) document.activeElement.blur();
            }
        });
    }

    // ============================================================
    // Drag & Drop für Upload-Zone
    // ============================================================
    const uploadZones = document.querySelectorAll('.upload-zone');
    uploadZones.forEach(function (zone) {
        const input = zone.querySelector('input[type="file"]');

        zone.addEventListener('dragover', function (e) {
            e.preventDefault();
            zone.classList.add('drag-over');
        });

        zone.addEventListener('dragleave', function () {
            zone.classList.remove('drag-over');
        });

        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('drag-over');
            if (e.dataTransfer.files.length && input) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    });

    // ============================================================
    // Table Row Click (gesamte Zeile klickbar)
    // ============================================================
    document.querySelectorAll('[data-row-href]').forEach(function (row) {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function (e) {
            if (!e.target.closest('a, button, input, select')) {
                window.location.href = row.getAttribute('data-row-href');
            }
        });
    });

    // ============================================================
    // Confirm Delete (data-confirm Attribut)
    // ============================================================
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            const msg = el.getAttribute('data-confirm');
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // ============================================================
    // Feather Icons für Dashboard
    // ============================================================
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof feather !== 'undefined') {
            feather.replace({ 'stroke-width': 2, 'width': 16, 'height': 16 });
        }
    });

    // ============================================================
    // Auto-dismiss Flash nach 6 Sekunden
    // ============================================================
    const flash = document.getElementById('flash-msg');
    if (flash) {
        setTimeout(function () {
            flash.style.transition = 'opacity 0.4s';
            flash.style.opacity = '0';
            setTimeout(() => flash.remove(), 400);
        }, 6000);
    }

})();
