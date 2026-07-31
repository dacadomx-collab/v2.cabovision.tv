<?php

declare(strict_types=1);

// =============================================================================
// admin/restablecer.php — Paso 2 del flujo de recuperación: fija la nueva
// contraseña. Lee `token` de la URL (enlace del correo, ver
// helpers/mail_templates.php) — la vista NUNCA valida el token por su cuenta,
// solo lo reenvía tal cual a api/auth_reset.php, que es quien decide.
//
// Incluye Visibility Toggle + Medidor de Fuerza (MODULO_01 §4.6). Este
// proyecto no tiene tabla `configuracion_seguridad` (Mandamiento #9, no se
// crea aquí) — la política se fija en código (mínimo 8 caracteres, igual que
// el mínimo duro que ya exige api/auth_reset.php) en vez de leerse dinámica.
// =============================================================================

require_once __DIR__ . '/../helpers/security_shield.php';

if (is_ip_banned()) {
    http_response_code(403);
    exit('Acceso denegado.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Restablecer contraseña — Panel CaboVision.tv</title>
<link rel="icon" href="/CaboVision.tv/favicon.ico">
<style><?= file_get_contents(__DIR__ . '/../assets/css/main.css') ?></style>
<style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .admin-login { width: 100%; max-width: 22rem; padding: var(--space-md); }
    .admin-login h1 { font-family: var(--font-headline); font-size: 1.4rem; margin-bottom: var(--space-md); text-align: center; }
    .admin-login label { display: block; font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 0.25rem; }
    .admin-login input {
        width: 100%; padding: 0.6rem 0.75rem; margin-bottom: var(--space-sm);
        border: 1px solid var(--color-border); border-radius: var(--radius);
        background: var(--color-bg); color: var(--color-text); font-size: 1rem;
    }
    .admin-login button {
        width: 100%; padding: 0.7rem; border: none; border-radius: var(--radius);
        background: var(--color-accent); color: var(--color-accent-contrast);
        font-weight: 700; cursor: pointer; font-size: 1rem;
    }
    #reset-feedback { font-size: 0.85rem; margin-top: var(--space-sm); text-align: center; }

    .password-field { position: relative; margin-bottom: var(--space-sm); }
    .password-field input { margin-bottom: 0; padding-right: 2.75rem; }
    /* .admin-login button tiene más especificidad que .password-field__toggle
       sola y le imponía el fondo rojo del botón "Guardar" — mismo bug real
       que admin/login.php, mismo fix (especificidad .password-field .password-field__toggle). */
    .password-field .password-field__toggle {
        position: absolute; top: 0; right: 0; height: 100%; width: 2.75rem;
        background: transparent; border: none; cursor: pointer; font-size: 1.1rem;
        color: var(--color-text-muted); display: flex; align-items: center; justify-content: center;
    }

    .password-strength { margin-bottom: var(--space-sm); }
    .password-strength__track { background: var(--color-border); border-radius: 999px; height: 0.4rem; width: 100%; overflow: hidden; }
    .password-strength__fill { background: var(--color-accent); height: 100%; width: 0%; transition: width 0.15s ease, background-color 0.15s ease; }
    .password-strength__label { font-size: 0.75rem; color: var(--color-text-muted); margin-top: 0.2rem; }
</style>
</head>
<body>
    <div class="card admin-login">
        <h1>Restablecer contraseña</h1>
        <form id="reset-form">
            <label for="password">Nueva contraseña</label>
            <div class="password-field">
                <input class="auth-input" type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
                <button type="button" class="password-field__toggle" data-password-toggle="password" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
            </div>
            <div class="password-strength" data-password-strength-for="password">
                <div class="password-strength__track"><div class="password-strength__fill" data-password-strength-fill></div></div>
                <p class="password-strength__label" data-password-strength-label>Mínimo 8 caracteres.</p>
            </div>

            <label for="password_confirm">Confirmar contraseña</label>
            <div class="password-field">
                <input class="auth-input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="8" required>
                <button type="button" class="password-field__toggle" data-password-toggle="password_confirm" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
            </div>

            <button type="submit">Guardar nueva contraseña</button>
            <p id="reset-feedback" hidden></p>
        </form>
    </div>
    <script>
        function initPasswordToggles() {
            document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
                const input = document.getElementById(btn.dataset.passwordToggle);
                if (!input) return;
                btn.addEventListener('click', () => {
                    const visible = input.type === 'text';
                    input.type = visible ? 'password' : 'text';
                    btn.setAttribute('aria-pressed', String(!visible));
                    btn.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
                });
            });
        }

        // Medidor de fuerza (MODULO_01 §4.6) — política fija en código (ver
        // cabecera del archivo): 8+ caracteres, mayúscula, minúscula, número,
        // símbolo. Puramente orientativo: la barrera real vive en el backend
        // (api/auth_reset.php exige el mínimo de 8 caracteres sin excepción).
        function calcularFuerzaPassword(password) {
            const checks = [
                password.length >= 8,
                /[A-Z]/.test(password),
                /[a-z]/.test(password),
                /[0-9]/.test(password),
                /[^a-zA-Z0-9]/.test(password),
            ];
            const cumplidos = checks.filter(Boolean).length;
            return Math.round((cumplidos / checks.length) * 100);
        }

        function initPasswordStrength() {
            const input = document.getElementById('password');
            const fill = document.querySelector('[data-password-strength-fill]');
            const label = document.querySelector('[data-password-strength-label]');
            if (!input || !fill || !label) return;

            input.addEventListener('input', () => {
                const pct = calcularFuerzaPassword(input.value);
                fill.style.width = pct + '%';
                fill.style.backgroundColor = pct < 40 ? '#c0392b' : pct < 80 ? '#d4a017' : '#2e7d32';
                label.textContent = input.value.length === 0
                    ? 'Mínimo 8 caracteres.'
                    : pct < 40 ? 'Débil' : pct < 80 ? 'Media' : 'Fuerte';
            });
        }

        initPasswordToggles();
        initPasswordStrength();

        const params = new URLSearchParams(window.location.search);
        const token = params.get('token') || '';

        document.getElementById('reset-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const feedbackEl = document.getElementById('reset-feedback');
            const button = event.target.querySelector('button[type="submit"]');
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('password_confirm').value;

            if (password !== confirm) {
                feedbackEl.textContent = 'Las contraseñas no coinciden.';
                feedbackEl.hidden = false;
                return;
            }
            if (!token) {
                feedbackEl.textContent = 'Enlace inválido — falta el token. Solicita uno nuevo.';
                feedbackEl.hidden = false;
                return;
            }

            button.disabled = true;
            try {
                const response = await fetch('/CaboVision.tv/api/auth_reset.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token, password }),
                });
                const result = await response.json();
                feedbackEl.textContent = result.message;
                feedbackEl.hidden = false;

                if (result.status === 'success') {
                    event.target.reset();
                    setTimeout(() => { window.location.href = '/CaboVision.tv/admin/login.php'; }, 1800);
                }
            } catch {
                feedbackEl.textContent = 'No se pudo contactar al servidor. Intenta de nuevo.';
                feedbackEl.hidden = false;
            } finally {
                button.disabled = false;
            }
        });
    </script>
</body>
</html>
