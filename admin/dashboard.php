<?php

declare(strict_types=1);

// =============================================================================
// admin/dashboard.php — Panel de campañas de patrocinio. El editor de
// artículos "Una Sola Pantalla" se movió a admin/editor.php (2026-07-21) —
// antes vivía duplicado aquí mismo, con un formulario que enviaba JSON plano
// incompatible con api/articles_create.php v2 (que ahora exige
// multipart/form-data para poder recibir la Imagen Principal).
// =============================================================================

require_once __DIR__ . '/../helpers/security_shield.php';

if (is_ip_banned()) {
    http_response_code(403);
    exit('Acceso denegado.');
}
waf_block_if_malicious();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campañas de Patrocinio — CaboVision.tv</title>
<link rel="icon" href="/CaboVision.tv/favicon.ico">
<style><?= file_get_contents(__DIR__ . '/../assets/css/main.css') ?></style>
<style>
    .admin-shell { max-width: 1100px; margin-inline: auto; padding: var(--space-md); }
    .admin-topbar { display: flex; flex-wrap: wrap; row-gap: var(--space-xs); justify-content: space-between; align-items: center; border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-sm); margin-bottom: var(--space-md); }
    .admin-topbar h1 { font-family: var(--font-headline); font-size: 1.4rem; margin: 0; }
    .admin-topbar nav { display: flex; flex-wrap: wrap; gap: var(--space-sm); }
    .admin-topbar nav a { margin-right: 0; white-space: nowrap; text-decoration: none; color: var(--color-text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }
    .admin-topbar nav a.is-active { color: var(--color-accent); }
    /* Móvil (2026-07-23): antes el <h1> competía por ancho con el nav y se
       partía en 3 líneas, y "Dashboard B2B" se cortaba a la mitad dentro del
       mismo enlace (bug real visto con Playwright a 390px). El título toma
       su propia fila completa y los enlaces ya no parten palabras (nowrap +
       flex-wrap en el contenedor, nunca en el texto de un solo link). */
    @media (max-width: 640px) {
        .admin-topbar h1 { flex: 1 1 100%; font-size: 1.15rem; }
    }
    #logout-btn { background: transparent; border: 1px solid var(--color-border); border-radius: var(--radius); padding: 0.4rem 0.9rem; cursor: pointer; color: var(--color-text); }

    .editor-grid { display: grid; grid-template-columns: 1fr; gap: var(--space-sm); }
    @media (min-width: 768px) {
        .editor-grid { grid-template-columns: 2fr 1fr; }
    }
    .editor-field { margin-bottom: var(--space-sm); }
    .editor-field label { display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .editor-field input, .editor-field select {
        width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--color-border); border-radius: var(--radius);
        background: var(--color-bg-alt); color: var(--color-text); font-size: 0.95rem; font-family: var(--font-body);
    }
    .editor-actions button {
        padding: 0.65rem 1.5rem; border: none; border-radius: var(--radius); background: var(--color-accent);
        color: var(--color-accent-contrast); font-weight: 700; cursor: pointer;
    }
    #sponsor-feedback { margin-top: var(--space-sm); font-size: 0.9rem; }

    table.admin-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-top: var(--space-sm); }
    table.admin-table th, table.admin-table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--color-border); }
    table.admin-table th { color: var(--color-text-muted); text-transform: uppercase; font-size: 0.7rem; }
</style>
</head>
<body>
<div class="admin-shell">
    <div class="admin-topbar">
        <h1>Panel Operativo</h1>
        <nav>
            <a href="/CaboVision.tv/admin/editor.php">Editor</a>
            <a href="/CaboVision.tv/admin/dashboard.php" class="is-active">Patrocinadores</a>
            <a href="/CaboVision.tv/admin/sponsors_dashboard.php">Dashboard B2B</a>
            <a href="/CaboVision.tv/admin/users.php">Usuarios</a>
        </nav>
        <button type="button" id="logout-btn">Cerrar sesión</button>
    </div>

    <section class="sponsor-panel">
        <h2>Campañas de Patrocinio</h2>
        <form id="sponsor-form">
            <div class="editor-grid">
                <div>
                    <div class="editor-field"><label for="sponsor_name">Patrocinador</label><input type="text" id="sponsor_name" required></div>
                    <div class="editor-field"><label for="image_path">Ruta de imagen</label><input type="text" id="image_path" required></div>
                    <div class="editor-field"><label for="redirect_url">URL de destino</label><input type="url" id="redirect_url" required></div>
                </div>
                <div>
                    <div class="editor-field">
                        <label for="placement_type">Posición</label>
                        <select id="placement_type" required>
                            <option value="superior">Superior</option>
                            <option value="lateral">Lateral</option>
                            <option value="intercalado">Intercalado</option>
                        </select>
                    </div>
                    <div class="editor-field"><label for="purchased_impressions">Impresiones compradas</label><input type="number" id="purchased_impressions" min="0" value="0"></div>
                    <div class="editor-field"><label for="start_date">Inicio</label><input type="date" id="start_date" required></div>
                    <div class="editor-field"><label for="end_date">Fin</label><input type="date" id="end_date" required></div>
                </div>
            </div>
            <div class="editor-actions"><button type="submit">Crear campaña</button></div>
            <p id="sponsor-feedback" hidden></p>
        </form>

        <table class="admin-table">
            <thead><tr><th>Patrocinador</th><th>Posición</th><th>Estado</th><th>Vigencia</th><th>Clics</th></tr></thead>
            <tbody id="sponsor-table-body"><tr><td colspan="5">Cargando…</td></tr></tbody>
        </table>
    </section>
</div>
<script src="/CaboVision.tv/assets/js/admin.js"></script>
</body>
</html>
