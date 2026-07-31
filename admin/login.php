<?php

declare(strict_types=1);

// =============================================================================
// admin/login.php — Login del panel operativo. assets/js/admin.js ya existía
// con initLoginForm() completo (POST a api/auth_login.php, sessionStorage)
// pero esperaba este archivo en esta ruta exacta — nunca se había construido.
// =============================================================================

require_once __DIR__ . '/../helpers/security_shield.php';

if (is_ip_banned()) {
    http_response_code(403);
    exit('Acceso denegado.');
}
waf_block_if_malicious();
rate_limit_enforce('admin_page', 60, 60); // punto de entrada pre-autenticación, mas estricto que 'page'
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acceso — Panel CaboVision.tv</title>
<link rel="icon" href="/CaboVision.tv/favicon.ico">
<style><?= file_get_contents(__DIR__ . '/../assets/css/main.css') ?></style>
<style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .admin-login { width: 100%; max-width: 22rem; padding: var(--space-md); }
    .admin-login h1 { font-family: var(--font-headline); font-size: 1.5rem; margin-bottom: var(--space-md); text-align: center; }
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
    #login-error { color: var(--color-accent); font-size: 0.85rem; margin-top: var(--space-sm); text-align: center; }

    /* Password Visibility Toggle (MODULO_01_LOGIN_Y_ACCESO.md §4.6) — el
       input y el botón conviven en una fila, el botón nunca debe estirar el
       campo ni disparar submit (type="button" explícito en el HTML). */
    .password-field { position: relative; margin-bottom: var(--space-sm); }
    .password-field input { margin-bottom: 0; padding-right: 2.75rem; }
    /* .admin-login button (regla genérica del botón "Entrar") tiene más
       especificidad (0,1,1) que .password-field__toggle sola (0,1,0) y le
       imponía su fondo rojo/ancho completo — bug real visto con captura de
       navegador. .password-field .password-field__toggle (0,2,0) gana sin
       !important. */
    .password-field .password-field__toggle {
        position: absolute; top: 0; right: 0; width: 2.75rem; height: 100%;
        background: transparent; border: none; cursor: pointer; font-size: 1.1rem;
        color: var(--color-text-muted); display: flex; align-items: center; justify-content: center;
    }

    /* "Mantenerse Registrado" + enlace de recuperación (§3.5) — fila fluida,
       nunca ancho fijo en px (Regla de Oro §4.4). */
    .admin-login__aux {
        display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
        gap: var(--space-xs); font-size: 0.8rem; margin-bottom: var(--space-sm);
    }
    .admin-login__aux label { display: flex; align-items: center; gap: 0.4rem; margin: 0; color: var(--color-text); }
    .admin-login__aux a { color: var(--color-text-muted); text-decoration: none; }
    .admin-login__aux a:hover { color: var(--color-accent); }
</style>
</head>
<body>
    <div class="card admin-login">
        <h1>CaboVision.tv — Panel</h1>
        <form id="login-form">
            <label for="email">Correo</label>
            <input type="email" id="email" name="email" required autocomplete="username">
            <label for="password">Contraseña</label>
            <div class="password-field">
                <input class="auth-input" type="password" id="password" name="password" autocomplete="current-password" required>
                <button type="button" class="password-field__toggle" data-password-toggle="password" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
            </div>
            <div class="admin-login__aux">
                <label><input type="checkbox" id="recordarme" name="recordarme"> Mantenerse registrado</label>
                <a href="/CaboVision.tv/admin/recuperar.php">¿Olvidaste tu contraseña?</a>
            </div>
            <button type="submit">Entrar</button>
            <p id="login-error" hidden></p>
        </form>
    </div>
    <script src="/CaboVision.tv/assets/js/admin.js"></script>
</body>
</html>
