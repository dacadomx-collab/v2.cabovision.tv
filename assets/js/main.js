// assets/js/main.js — CaboVision.tv

document.addEventListener('DOMContentLoaded', async () => {
    const statusEl = document.getElementById('status-check');
    if (statusEl) {
        try {
            const response = await fetch('api/status_check.php');
            const result = await response.json();
            statusEl.textContent = result.message;
        } catch {
            statusEl.textContent = 'No se pudo contactar al servidor.';
        }
    }

    // Toggle de modo oscuro/claro — el botón ya existía en header.php (con el
    // script anti-FOUC que lee la preferencia guardada), pero no tenía ningún
    // manejador de clic: no hacía nada al presionarlo. Vanilla JS, sin
    // librerías (Fricción Cero / Fast by Design).
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const root = document.documentElement;
            const current = root.getAttribute('data-theme')
                || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            const next = current === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            localStorage.setItem('cabovision_theme', next);
        });
    }

    // Dropdowns del menú principal ("Noticias▾", "Especiales▾") — bootstrap.css
    // fija display:none y solo lo revierte con .show (que agrega
    // bootstrap.bundle.js, nunca cargado aquí a propósito). main.css ya cubre
    // el :hover de mouse en escritorio sin JS; esto añade clic/teclado para
    // táctil y accesibilidad, cerrando cualquier otro dropdown abierto y
    // cerrándose al hacer clic afuera o presionar Escape.
    const dropdownToggles = document.querySelectorAll('.dropdown > .dropdown-toggle');
    dropdownToggles.forEach((toggle) => {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            const menu = toggle.nextElementSibling;
            const isOpen = menu && menu.classList.contains('show');

            document.querySelectorAll('.dropdown-menu.show').forEach((openMenu) => {
                openMenu.classList.remove('show');
                const relatedToggle = openMenu.previousElementSibling;
                if (relatedToggle) {
                    relatedToggle.setAttribute('aria-expanded', 'false');
                }
            });

            if (menu && !isOpen) {
                menu.classList.add('show');
                toggle.setAttribute('aria-expanded', 'true');
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show').forEach((openMenu) => {
                openMenu.classList.remove('show');
                const relatedToggle = openMenu.previousElementSibling;
                if (relatedToggle) {
                    relatedToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.dropdown-menu.show').forEach((openMenu) => {
                openMenu.classList.remove('show');
            });
        }
    });
});
