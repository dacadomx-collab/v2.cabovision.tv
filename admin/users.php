<?php

declare(strict_types=1);

// =============================================================================
// admin/users.php — Panel de Usuarios, exclusivo del Super Admin (rol
// "Admin"). Alta de nuevas cuentas con rol asignado — assets/js/users.js hace
// la verificación real de rol vía requireRole(['Admin']) del lado servidor
// (api/users_create.php); este panel puede mostrarse a cualquier sesión
// válida, pero el formulario simplemente fallará con 403 si el rol no
// califica — la autoridad real vive siempre en el backend (MODULO_01 §2,
// nunca confiar en ocultar un botón como control de acceso).
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
<title>Usuarios — CaboVision.tv</title>
<link rel="icon" href="/CaboVision.tv/favicon.ico">
<style><?= file_get_contents(__DIR__ . '/../assets/css/main.css') ?></style>
<style>
    .admin-shell { max-width: 1100px; margin-inline: auto; padding: var(--space-md); }
    .admin-topbar { display: flex; flex-wrap: wrap; row-gap: var(--space-xs); justify-content: space-between; align-items: center; border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-sm); margin-bottom: var(--space-md); }
    .admin-topbar h1 { font-family: var(--font-headline); font-size: 1.4rem; margin: 0; }
    .admin-topbar nav { display: flex; flex-wrap: wrap; gap: var(--space-sm); }
    .admin-topbar nav a { margin-right: 0; white-space: nowrap; text-decoration: none; color: var(--color-text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }
    .admin-topbar nav a.is-active { color: var(--color-accent); }
    @media (max-width: 640px) {
        .admin-topbar h1 { flex: 1 1 100%; font-size: 1.15rem; }
    }
    #logout-btn { background: transparent; border: 1px solid var(--color-border); border-radius: var(--radius); padding: 0.4rem 0.9rem; cursor: pointer; color: var(--color-text); }

    .editor-grid { display: grid; grid-template-columns: 1fr; gap: var(--space-sm); }
    @media (min-width: 768px) {
        .editor-grid { grid-template-columns: 1fr 1fr; }
    }
    .editor-field { margin-bottom: var(--space-sm); }
    .editor-field label { display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .editor-field input, .editor-field select {
        width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--color-border); border-radius: var(--radius);
        background: var(--color-bg-alt); color: var(--color-text); font-size: 0.95rem; font-family: var(--font-body);
    }
    .editor-field label.checkbox-label { display: flex; align-items: center; gap: 0.4rem; text-transform: none; font-size: 0.9rem; color: var(--color-text); }
    .editor-actions button {
        padding: 0.65rem 1.5rem; border: none; border-radius: var(--radius); background: var(--color-accent);
        color: var(--color-accent-contrast); font-weight: 700; cursor: pointer;
    }
    #user-feedback { margin-top: var(--space-sm); font-size: 0.9rem; }
    .temp-password-box {
        margin-top: var(--space-sm); padding: 0.85rem 1rem; border-radius: var(--radius);
        background: var(--color-bg-alt); border-left: 4px solid var(--color-accent); font-size: 0.9rem;
    }
    .temp-password-box code { font-size: 1rem; font-weight: 700; }

    table.admin-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-top: var(--space-md); }
    table.admin-table th, table.admin-table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--color-border); }
    table.admin-table th { color: var(--color-text-muted); text-transform: uppercase; font-size: 0.7rem; }
</style>
</head>
<body>
<div class="admin-shell">
    <div class="admin-topbar">
        <h1>Usuarios</h1>
        <nav>
            <a href="/CaboVision.tv/admin/editor.php">Editor</a>
            <a href="/CaboVision.tv/admin/dashboard.php">Patrocinadores</a>
            <a href="/CaboVision.tv/admin/sponsors_dashboard.php">Dashboard B2B</a>
            <a href="/CaboVision.tv/admin/users.php" class="is-active">Usuarios</a>
        </nav>
        <button type="button" id="logout-btn">Cerrar sesión</button>
    </div>

    <section>
        <h2>Crear Usuario</h2>
        <form id="user-form">
            <div class="editor-grid">
                <div class="editor-field">
                    <label for="name">Nombre completo</label>
                    <input type="text" id="name" required maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" required maxlength="150">
                </div>
                <div class="editor-field">
                    <label for="role_id">Rol</label>
                    <select id="role_id" required></select>
                </div>
                <div class="editor-field">
                    <label class="checkbox-label"><input type="checkbox" id="send_welcome_email" checked> Enviar correo de bienvenida</label>
                </div>
            </div>
            <div class="editor-actions">
                <button type="submit">Crear usuario</button>
            </div>
            <p id="user-feedback" hidden></p>
        </form>
    </section>

    <section>
        <h2>Usuarios existentes</h2>
        <table class="admin-table">
            <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Alta</th></tr></thead>
            <tbody id="users-table-body"><tr><td colspan="5">Cargando…</td></tr></tbody>
        </table>
    </section>
</div>
<script src="/CaboVision.tv/assets/js/admin.js"></script>
<script src="/CaboVision.tv/assets/js/users.js" defer></script>
</body>
</html>
