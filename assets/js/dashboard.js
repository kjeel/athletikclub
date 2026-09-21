/**
 * Athletikclub Steiermark – Dashboard JavaScript
 */

(function () {
    'use strict';

    // ============================================================
    // Sidebar Mobile Toggle
    // ============================================================
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar       = document.getElementById('sidebar');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });

        // Klick außerhalb schließt Sidebar
        document.addEventListener('click', function (e) {
            if (sidebar.classList.contains('open')
                && !sidebar.contains(e.target)
                && !sidebarToggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Hamburger bei kleinen Screens anzeigen
    function checkSidebarVisibility() {
        if (sidebarToggle) {
            sidebarToggle.style.display = window.innerWidth <= 1024 ? 'flex' : 'none';
        }
    }
    checkSidebarVisibility();
    window.addEventListener('resize', checkSidebarVisibility);

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
