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

    // Benutzermenü (Initialen oben rechts): siehe main.js

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

// ============================================================
// Navigation: Gruppen merken (die Gruppe der aktuellen Seite bleibt immer offen)
// ============================================================
(function () {
    'use strict';
    var gruppen = document.querySelectorAll('.nav-gruppe');
    if (!gruppen.length) return;
    var zustand = {};
    try { zustand = JSON.parse(localStorage.getItem('aci-nav') || '{}') || {}; } catch (e) {}
    gruppen.forEach(function (g) {
        var key = g.getAttribute('data-gruppe');
        if (!g.hasAttribute('data-aktiv') && Object.prototype.hasOwnProperty.call(zustand, key)) g.open = !!zustand[key];
        g.addEventListener('toggle', function () {
            zustand[key] = g.open;
            try { localStorage.setItem('aci-nav', JSON.stringify(zustand)); } catch (e) {}
        });
    });
})();

// ============================================================
// Globale Suche: Vorschläge beim Tippen (Enter öffnet die Ergebnisseite)
// ============================================================
(function () {
    'use strict';
    var form = document.querySelector('.dash-suche');
    if (!form || !window.fetch) return;
    var input = form.querySelector('input');
    var liste = form.querySelector('.dash-suche-liste');
    var timer = null, letzte = '', auswahl = -1;

    function schliessen() { liste.hidden = true; auswahl = -1; }
    function links() { return liste.querySelectorAll('a'); }
    function markieren(i) {
        links().forEach(function (a, j) { a.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
        auswahl = i;
    }
    function element(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text) el.textContent = text;
        return el;
    }
    function zeigen(daten) {
        liste.innerHTML = '';
        if (!daten.gruppen.length) liste.appendChild(element('div', 'dash-suche-leer', 'Keine Treffer'));
        daten.gruppen.forEach(function (g) {
            liste.appendChild(element('div', 'dash-suche-gruppe', g.label));
            g.treffer.forEach(function (t) {
                var a = element('a', '', t.titel);
                a.href = t.link;
                a.setAttribute('role', 'option');
                if (t.info) a.appendChild(element('small', '', t.info));
                liste.appendChild(a);
            });
        });
        var alle = element('a', 'dash-suche-alle', 'Alle Ergebnisse anzeigen →');
        alle.href = form.action + '?q=' + encodeURIComponent(daten.q);
        liste.appendChild(alle);
        liste.hidden = false;
        auswahl = -1;
    }
    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { schliessen(); return; }
        timer = setTimeout(function () {
            if (q === letzte) { liste.hidden = false; return; }
            letzte = q;
            fetch(form.action + '?format=json&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (d && d.q === input.value.trim()) zeigen(d); })
                .catch(function () {});
        }, 220);
    });
    input.addEventListener('keydown', function (e) {
        var l = links();
        if (liste.hidden || !l.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); markieren(Math.min(auswahl + 1, l.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); markieren(Math.max(auswahl - 1, 0)); }
        else if (e.key === 'Enter' && auswahl >= 0) { e.preventDefault(); l[auswahl].click(); }
        else if (e.key === 'Escape') { schliessen(); }
    });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) schliessen(); });
    // Tastenkürzel „/“ fokussiert die Suche (außer beim Tippen in Feldern)
    document.addEventListener('keydown', function (e) {
        var el = document.activeElement;
        if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(el.tagName) && !el.isContentEditable) {
            e.preventDefault();
            input.focus();
        }
    });
})();
