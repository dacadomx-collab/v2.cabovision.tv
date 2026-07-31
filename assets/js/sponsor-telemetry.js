// assets/js/sponsor-telemetry.js — Telemetría de viewability y clic del AdServer
// (Módulo 04, ver modulos/MODULO_04_MARKETING_ORGANICO.md §2).
//
// Marca cada banner patrocinado con: <div data-sponsor-banner-id="123"
// data-viewability-threshold="0.5"> ... </div>. threshold es opcional
// (default 0.5 = 50% del área visible; usar 0.3 para megabanners).
//
// IMPORTANTE: el hash de sesión anti-fraude (sponsor_events.session_hash) NO
// se calcula aquí — el salt (ADS_TELEMETRY_SALT) es un secreto de servidor y
// jamás debe llegar al cliente. Este script solo envía banner_id + event_type;
// el endpoint de registro computa el hash desde el User-Agent/IP reales de la
// petición HTTP.
//
// Endpoint receptor: api/sponsors_track.php (construido y en uso). Ver
// knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md §Módulo 04.

(function () {
    'use strict';

    const TRACK_ENDPOINT = '/CaboVision.tv/api/sponsors_track.php';
    const VIEWABILITY_MS = 1000; // 1 segundo continuo, MRC estándar
    const DEFAULT_THRESHOLD = 0.5;

    function sendEvent(bannerId, eventType) {
        const payload = JSON.stringify({
            banner_id: bannerId,
            event_type: eventType,
            page_url: window.location.href,
        });

        // sendBeacon: transmisión asíncrona sin bloquear el hilo principal ni
        // retrasar la navegación (crítico en 'click', donde el usuario ya está
        // siendo redirigido por api/sponsors_redirect.php).
        if (navigator.sendBeacon) {
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(TRACK_ENDPOINT, blob);
        } else {
            // Fallback silencioso para navegadores sin sendBeacon: fetch en
            // background, sin esperar la respuesta ni bloquear nada.
            fetch(TRACK_ENDPOINT, { method: 'POST', body: payload, keepalive: true }).catch(() => {});
        }
    }

    function watchBanner(el) {
        const bannerId = el.getAttribute('data-sponsor-banner-id');
        if (!bannerId) {
            return;
        }

        const threshold = parseFloat(el.getAttribute('data-viewability-threshold') || '') || DEFAULT_THRESHOLD;

        let visibilityTimer = null;
        let impressionSent = false;

        function clearTimer() {
            if (visibilityTimer !== null) {
                clearTimeout(visibilityTimer);
                visibilityTimer = null;
            }
        }

        function startTimer() {
            if (impressionSent || visibilityTimer !== null) {
                return;
            }
            visibilityTimer = setTimeout(() => {
                impressionSent = true;
                sendEvent(bannerId, 'impression');
                observer.unobserve(el); // ya se contó, no necesita seguir observado
            }, VIEWABILITY_MS);
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const isVisibleEnough = entry.isIntersecting && entry.intersectionRatio >= threshold;
                if (isVisibleEnough && document.visibilityState === 'visible') {
                    startTimer();
                } else {
                    clearTimer(); // se pierde visibilidad antes de 1s continuo — se reinicia el conteo
                }
            });
        }, { threshold: [0, threshold] });

        observer.observe(el);

        // Pausa/reinicio si el usuario cambia de pestaña o minimiza — el
        // temporizador de exposición no debe avanzar sin foco real (§2 del
        // diseño). Se re-evalúa contra el estado actual del IntersectionObserver
        // dejando que el próximo callback de intersección decida si reanuda.
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearTimer();
            }
        });

        el.addEventListener('click', () => sendEvent(bannerId, 'click'), { passive: true });
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (!('IntersectionObserver' in window)) {
            return; // degradación limpia: sin soporte nativo, no se rompe la página, solo no hay telemetría
        }
        document.querySelectorAll('[data-sponsor-banner-id]').forEach(watchBanner);
    });
})();
