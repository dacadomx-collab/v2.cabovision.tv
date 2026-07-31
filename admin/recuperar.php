<?php

declare(strict_types=1);

// =============================================================================
// admin/recuperar.php — Paso 1 del flujo de recuperación: solicitar el enlace.
// Consume api/auth_recover.php (Zero Enumeration — ver ese archivo). La vista
// misma nunca sabe ni pregunta si el correo existe, solo muestra el mensaje
// genérico que el backend siempre devuelve.
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
<title>Recuperar acceso — Panel CaboVision.tv</title>
<link rel="icon" href="/CaboVision.tv/favicon.ico">
<style><?= file_get_contents(__DIR__ . '/../assets/css/main.css') ?></style>
<style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .admin-login { width: 100%; max-width: 22rem; padding: var(--space-md); }
    .admin-login h1 { font-family: var(--font-headline); font-size: 1.4rem; margin-bottom: var(--space-sm); text-align: center; }
    .admin-login p.lead { font-size: 0.85rem; color: var(--color-text-muted); text-align: center; margin-bottom: var(--space-md); }
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
    #recover-feedback { font-size: 0.85rem; margin-top: var(--space-sm); text-align: center; color: var(--color-text); }
    .back-link { display: block; text-align: center; margin-top: var(--space-md); font-size: 0.8rem; color: var(--color-text-muted); text-decoration: none; }
    .back-link:hover { color: var(--color-accent); }
</style>
</head>
<body>
    <div class="card admin-login">
        <h1>Recuperar acceso</h1>
        <p class="lead">Escribe tu correo y te enviaremos un enlace para restablecer tu contraseña.</p>
        <form id="recover-form">
            <label for="email">Correo</label>
            <input type="email" id="email" name="email" required autocomplete="username">
            <button type="submit">Enviar enlace</button>
            <p id="recover-feedback" hidden></p>
        </form>
        <a class="back-link" href="/CaboVision.tv/admin/login.php">← Volver al inicio de sesión</a>
    </div>
    <script>
        document.getElementById('recover-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const feedbackEl = document.getElementById('recover-feedback');
            const button = event.target.querySelector('button');
            const email = document.getElementById('email').value.trim();

            button.disabled = true;
            try {
                const response = await fetch('/CaboVision.tv/api/auth_recover.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email }),
                });
                const result = await response.json();
                // Mismo mensaje sin importar el resultado — el propio backend
                // ya aplica Zero Enumeration, esta vista no añade ramas nuevas.
                feedbackEl.textContent = result.message;
                feedbackEl.hidden = false;
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
