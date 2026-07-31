// assets/js/admin.js — CaboVision.tv · Panel del Operador (login + alta de noticias)
// Mandamiento #14: CORS ≠ Auth — toda mutación viaja con Authorization: Bearer <token>.

const AUTH_STORAGE_KEY = 'cabovision_auth';

function getAuthSession() {
    try {
        return JSON.parse(sessionStorage.getItem(AUTH_STORAGE_KEY) || 'null');
    } catch {
        return null;
    }
}

function setAuthSession(session) {
    sessionStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify(session));
}

function clearAuthSession() {
    sessionStorage.removeItem(AUTH_STORAGE_KEY);
}

function redirectToLogin() {
    clearAuthSession();
    window.location.href = '/CaboVision.tv/admin/login.php';
}

document.addEventListener('DOMContentLoaded', () => {
    initLoginForm();
    initDashboard();
    initPasswordToggles();
});

// ── PASSWORD VISIBILITY TOGGLE (MODULO_01_LOGIN_Y_ACCESO.md §4.6) — botón
// "ojito" reutilizable en cualquier <input type="password"> marcado con
// data-password-toggle="{id-del-input}". Nunca dispara submit (type="button"
// ya fijado en el HTML) ni reformatea el valor del input. ─────────────────────
function initPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
        const input = document.getElementById(btn.dataset.passwordToggle);
        if (!input) {
            return;
        }

        btn.addEventListener('click', () => {
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            btn.setAttribute('aria-pressed', String(!visible));
            btn.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
        });
    });
}

// ── LOGIN ────────────────────────────────────────────────────────────────────
function initLoginForm() {
    const form = document.getElementById('login-form');
    if (!form) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const errorEl = document.getElementById('login-error');
        errorEl.hidden = true;

        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;
        const recordarme = document.getElementById('recordarme')?.checked ?? false;

        try {
            const response = await fetch('/CaboVision.tv/api/auth_login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password, recordarme }),
            });
            const result = await response.json();

            if (result.status !== 'success') {
                errorEl.textContent = result.message || 'Credenciales inválidas.';
                errorEl.hidden = false;
                return;
            }

            setAuthSession({
                accessToken: result.data.access_token,
                refreshToken: result.data.refresh_token,
                deviceId: result.data.device_id,
                role: result.data.role,
            });

            window.location.href = '/CaboVision.tv/admin/dashboard.php';
        } catch {
            errorEl.textContent = 'No se pudo contactar al servidor.';
            errorEl.hidden = false;
        }
    });
}

// ── DASHBOARD (panel de patrocinadores — el editor de artículos vive en
// admin/editor.php + assets/js/editor.js desde 2026-07-21, con su propio
// envío multipart/form-data para poder adjuntar la Imagen Principal; el
// endpoint v2 ya no acepta el JSON plano que usaba esta función) ──────────
function initDashboard() {
    const form = document.getElementById('sponsor-form');
    if (!form) {
        return; // esta página no tiene el panel de patrocinadores (ej. editor.php)
    }

    const session = getAuthSession();
    if (!session || !session.accessToken) {
        redirectToLogin();
        return;
    }

    document.getElementById('logout-btn')?.addEventListener('click', redirectToLogin);
    initSponsorPanel(session);
}

/** Panel de campañas del AdServer interno: listado + alta contra `sponsor_banners`. */
function initSponsorPanel(session) {
    const tableBody = document.getElementById('sponsor-table-body');
    const form = document.getElementById('sponsor-form');
    if (!tableBody || !form) {
        return;
    }

    const esc = (value) => String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;');

    async function loadSponsors() {
        try {
            const response = await fetch('/CaboVision.tv/api/sponsor_banners_list.php', {
                headers: { 'Authorization': `Bearer ${session.accessToken}` },
            });
            if (response.status === 401) {
                redirectToLogin();
                return;
            }
            const result = await response.json();
            if (result.status !== 'success' || result.data.banners.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="5">Sin campañas registradas.</td></tr>';
                return;
            }

            tableBody.innerHTML = result.data.banners.map((b) => `
                <tr>
                    <td>${esc(b.sponsor_name)}</td>
                    <td>${esc(b.placement_type)}</td>
                    <td>${esc(b.status)}</td>
                    <td>${esc(b.start_date)} → ${esc(b.end_date)}</td>
                    <td>${esc(b.accumulated_clicks)}</td>
                </tr>
            `).join('');
        } catch {
            tableBody.innerHTML = '<tr><td colspan="5">No se pudo contactar al servidor.</td></tr>';
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const feedbackEl = document.getElementById('sponsor-feedback');
        feedbackEl.hidden = true;

        const body = {
            sponsor_name: document.getElementById('sponsor_name').value.trim(),
            image_path: document.getElementById('image_path').value.trim(),
            redirect_url: document.getElementById('redirect_url').value.trim(),
            placement_type: document.getElementById('placement_type').value,
            purchased_impressions: Number(document.getElementById('purchased_impressions').value || 0),
            start_date: document.getElementById('start_date').value,
            end_date: document.getElementById('end_date').value,
        };

        try {
            const response = await fetch('/CaboVision.tv/api/sponsor_banners_create.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${session.accessToken}`,
                },
                body: JSON.stringify(body),
            });

            if (response.status === 401) {
                redirectToLogin();
                return;
            }

            const result = await response.json();
            feedbackEl.textContent = result.message;
            feedbackEl.hidden = false;

            if (result.status === 'success') {
                form.reset();
                await loadSponsors();
            }
        } catch {
            feedbackEl.textContent = 'No se pudo contactar al servidor.';
            feedbackEl.hidden = false;
        }
    });

    loadSponsors();
}

/** Reutiliza el endpoint público de categorías para poblar el <select>. */
async function loadCategoriesSelect() {
    const select = document.getElementById('category_id');
    if (!select) {
        return;
    }

    try {
        const response = await fetch('/CaboVision.tv/api/categories_list.php');
        const result = await response.json();
        if (result.status !== 'success') {
            return;
        }

        for (const category of result.data.categories) {
            const option = document.createElement('option');
            option.value = String(category.id);
            option.textContent = category.name;
            select.appendChild(option);
        }
    } catch {
        // Silencioso: el operador puede reintentar recargando la página.
    }
}
