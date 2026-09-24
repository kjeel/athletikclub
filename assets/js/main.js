/**
 * Athletikclub Steiermark – Main JavaScript
 * Vanilla JS – Hetzner Webhosting L kompatibel (kein Node.js)
 */

(function () {
    'use strict';

    // ============================================================
    // Feather Icons initialisieren
    // ============================================================
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof feather !== 'undefined') {
            feather.replace({ 'stroke-width': 2, 'width': 16, 'height': 16 });
        }
    });

    // ============================================================
    // Dark-Modus umschalten
    // ============================================================
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            const root = document.documentElement;
            const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('aci-theme', next); } catch (e) {}
        });
    }

    // ============================================================
    // Sticky Header: Klasse bei Scroll hinzufügen
    // ============================================================
    const header = document.getElementById('site-header');
    if (header) {
        window.addEventListener('scroll', function () {
            if (window.scrollY > 20) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }, { passive: true });
    }

    // ============================================================
    // Mobile Navigation
    // ============================================================
    const navToggle  = document.getElementById('nav-toggle');
    const mobileNav  = document.getElementById('mobile-nav');
    const mobileOverlay = document.getElementById('mobile-nav-overlay');
    const mobileClose = document.getElementById('mobile-nav-close');

    function openMobileNav() {
        if (!mobileNav) return;
        mobileNav.classList.add('active');
        mobileOverlay.classList.add('active');
        navToggle.classList.add('active');
        navToggle.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeMobileNav() {
        if (!mobileNav) return;
        mobileNav.classList.remove('active');
        mobileOverlay.classList.remove('active');
        navToggle.classList.remove('active');
        navToggle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    if (navToggle)     navToggle.addEventListener('click', openMobileNav);
    if (mobileClose)   mobileClose.addEventListener('click', closeMobileNav);
    if (mobileOverlay) mobileOverlay.addEventListener('click', closeMobileNav);

    // Escape key closes nav
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobileNav();
    });

    // ============================================================
    // Flash Message Auto-Dismiss (5 Sekunden)
    // ============================================================
    const flashMsg = document.getElementById('flash-msg');
    if (flashMsg) {
        setTimeout(function () {
            flashMsg.style.transition = 'opacity 0.4s ease';
            flashMsg.style.opacity = '0';
            setTimeout(() => flashMsg.remove(), 400);
        }, 5000);
    }

    // ============================================================
    // Scroll Reveal Animation
    // ============================================================
    function initReveal() {
        const revealEls = document.querySelectorAll('.reveal');
        if (!revealEls.length) return;

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        revealEls.forEach(el => observer.observe(el));
    }

    // ============================================================
    // Counter Animation (für Statistik-Zahlen)
    // ============================================================
    function animateCounter(el) {
        const target = parseInt(el.getAttribute('data-count'), 10);
        const duration = 1800;
        const start = performance.now();

        function update(now) {
            const elapsed = now - start;
            const progress = Math.min(elapsed / duration, 1);
            // Ease-out cubic
            const ease = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.round(ease * target).toLocaleString('de-AT');
            if (progress < 1) requestAnimationFrame(update);
        }

        requestAnimationFrame(update);
    }

    function initCounters() {
        const counters = document.querySelectorAll('[data-count]');
        if (!counters.length) return;

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        counters.forEach(el => observer.observe(el));
    }

    // ============================================================
    // Password Toggle (Passwort anzeigen/verbergen)
    // ============================================================
    function initPasswordToggles() {
        document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const targetId = btn.getAttribute('data-password-toggle');
                const input = document.getElementById(targetId);
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';

                // Icon tauschen
                const icon = btn.querySelector('svg, i');
                if (icon) {
                    icon.setAttribute('data-feather', isPassword ? 'eye-off' : 'eye');
                    if (typeof feather !== 'undefined') feather.replace();
                }
            });
        });
    }

    // ============================================================
    // Form Validation (Client-Side)
    // ============================================================
    function initFormValidation() {
        document.querySelectorAll('form[data-validate]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                let valid = true;

                // Pflichtfelder
                form.querySelectorAll('[required]').forEach(function (field) {
                    const errorEl = form.querySelector('[data-error-for="' + field.id + '"]');
                    if (!field.value.trim()) {
                        field.classList.add('error');
                        if (errorEl) errorEl.textContent = 'Dieses Feld ist Pflicht.';
                        valid = false;
                    } else {
                        field.classList.remove('error');
                        if (errorEl) errorEl.textContent = '';
                    }
                });

                // E-Mail
                form.querySelectorAll('input[type="email"]').forEach(function (field) {
                    const errorEl = form.querySelector('[data-error-for="' + field.id + '"]');
                    if (field.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
                        field.classList.add('error');
                        if (errorEl) errorEl.textContent = 'Bitte gib eine gültige E-Mail-Adresse ein.';
                        valid = false;
                    }
                });

                // Passwort-Bestätigung
                const pwd    = form.querySelector('#password');
                const pwdCfm = form.querySelector('#password_confirm');
                if (pwd && pwdCfm) {
                    const errorEl = form.querySelector('[data-error-for="password_confirm"]');
                    if (pwd.value !== pwdCfm.value) {
                        pwdCfm.classList.add('error');
                        if (errorEl) errorEl.textContent = 'Die Passwörter stimmen nicht überein.';
                        valid = false;
                    }
                }

                if (!valid) e.preventDefault();
            });

            // Live-Fehler entfernen
            form.querySelectorAll('input, textarea, select').forEach(function (field) {
                field.addEventListener('input', function () {
                    field.classList.remove('error');
                    const errorEl = form.querySelector('[data-error-for="' + field.id + '"]');
                    if (errorEl) errorEl.textContent = '';
                });
            });
        });
    }

    // ============================================================
    // Smooth Scroll für Anker
    // ============================================================
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                const target = document.querySelector(link.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    const offset = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header-height'), 10) || 72;
                    window.scrollTo({
                        top: target.getBoundingClientRect().top + window.scrollY - offset,
                        behavior: 'smooth'
                    });
                }
            });
        });
    }

    // ============================================================
    // Tabs
    // ============================================================
    function initTabs() {
        document.querySelectorAll('[data-tabs]').forEach(function (container) {
            const tabs    = container.querySelectorAll('[data-tab]');
            const panels  = container.querySelectorAll('[data-tab-panel]');

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    const target = tab.getAttribute('data-tab');

                    tabs.forEach(t => t.classList.remove('active'));
                    panels.forEach(p => p.classList.remove('active'));

                    tab.classList.add('active');
                    const panel = container.querySelector('[data-tab-panel="' + target + '"]');
                    if (panel) panel.classList.add('active');
                });
            });
        });
    }

    // ============================================================
    // Benutzermenü (Initialen oben rechts) – per Tipp/Klick öffnen,
    // weil es am Handy kein Hover gibt. Klick außerhalb schließt.
    // ============================================================
    document.querySelectorAll('.user-chip.has-dropdown').forEach(function (chip) {
        function setOffen(offen) {
            chip.classList.toggle('open', offen);
            chip.setAttribute('aria-expanded', offen ? 'true' : 'false');
            if (!offen && chip.contains(document.activeElement)) document.activeElement.blur(); // sonst hält :focus-within es offen
        }
        chip.addEventListener('click', function (e) {
            if (e.target.closest('.dropdown-menu a')) return; // Link normal öffnen
            setOffen(!chip.classList.contains('open'));
        });
        chip.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target === chip) {
                e.preventDefault();
                setOffen(!chip.classList.contains('open'));
            }
            if (e.key === 'Escape') setOffen(false);
        });
        document.addEventListener('click', function (e) {
            if (!chip.contains(e.target)) setOffen(false);
        });
    });

    // ============================================================
    // Modal
    // ============================================================
    function initModals() {
        document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const modal = document.getElementById(btn.getAttribute('data-modal-open'));
                if (modal) {
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            });
        });

        document.querySelectorAll('[data-modal-close], .modal-overlay').forEach(function (el) {
            el.addEventListener('click', function () {
                const modal = el.closest('.modal');
                if (modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(function (modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }
        });
    }

    // ============================================================
    // Initialisierung
    // ============================================================
    document.addEventListener('DOMContentLoaded', function () {
        initReveal();
        initCounters();
        initPasswordToggles();
        initFormValidation();
        initSmoothScroll();
        initTabs();
        initModals();
    });

})();
