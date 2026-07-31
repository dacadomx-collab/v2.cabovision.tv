<?php

declare(strict_types=1);

// =============================================================================
// admin/sponsors_dashboard.php — Panel Ejecutivo B2B. Consume api/sponsors_report.php
// (construido y probado end-to-end previamente: 401 sin token, 403 sin rol
// Admin, 200 real). Vanilla JS puro, sin librerías de gráficos — barras de
// CTR renderizadas con <div> + CSS, no canvas/SVG de terceros.
// =============================================================================

require_once __DIR__ . '/../helpers/security_shield.php';
require_once __DIR__ . '/../helpers/base_path.php';

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
<title>Dashboard B2B — Patrocinadores | CaboVision.tv</title>
<link rel="icon" href="<?= base_path() ?>/favicon.ico">
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

    .range-form { display: flex; gap: var(--space-sm); align-items: end; margin-bottom: var(--space-md); flex-wrap: wrap; }
    .range-form .editor-field label { display: block; font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; margin-bottom: 0.2rem; }
    .range-form input { padding: 0.5rem 0.7rem; border: 1px solid var(--color-border); border-radius: var(--radius); background: var(--color-bg-alt); color: var(--color-text); }
    .range-form button { padding: 0.55rem 1.2rem; border: none; border-radius: var(--radius); background: var(--color-accent); color: var(--color-accent-contrast); font-weight: 700; cursor: pointer; }

    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--space-sm); margin-bottom: var(--space-lg); }
    .stat-card { background: var(--color-bg-alt); border: 1px solid var(--color-border); border-radius: var(--radius); padding: var(--space-md); text-align: center; }
    .stat-card__value { font-family: var(--font-headline); font-size: 2rem; font-weight: 700; }
    .stat-card__label { font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 0.25rem; }

    table.admin-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    table.admin-table th, table.admin-table td { padding: 0.6rem 0.75rem; text-align: left; border-bottom: 1px solid var(--color-border); }
    table.admin-table th { color: var(--color-text-muted); text-transform: uppercase; font-size: 0.7rem; }
    .ctr-bar-track { background: var(--color-border); border-radius: 999px; height: 0.5rem; width: 100%; overflow: hidden; }
    .ctr-bar-fill { background: var(--color-accent); height: 100%; }
    .note { background: var(--color-bg-alt); border-left: 4px solid var(--color-text-muted); border-radius: 0.25rem; padding: 0.85rem 1rem; font-size: 0.85rem; color: var(--color-text-muted); margin-top: var(--space-md); }
</style>
</head>
<body>
<div class="admin-shell">
    <div class="admin-topbar">
        <h1>Dashboard B2B — Patrocinadores</h1>
        <nav>
            <a href="<?= base_path() ?>/admin/editor.php">Editor</a>
            <a href="<?= base_path() ?>/admin/dashboard.php">Patrocinadores</a>
            <a href="<?= base_path() ?>/admin/sponsors_dashboard.php" class="is-active">Dashboard B2B</a>
            <a href="<?= base_path() ?>/admin/users.php">Usuarios</a>
        </nav>
        <button type="button" id="logout-btn">Cerrar sesión</button>
    </div>

    <form class="range-form" id="range-form">
        <div class="editor-field"><label for="desde">Desde</label><input type="date" id="desde"></div>
        <div class="editor-field"><label for="hasta">Hasta</label><input type="date" id="hasta"></div>
        <button type="submit">Actualizar</button>
    </form>

    <div class="stat-grid" id="stat-grid">
        <div class="stat-card"><div class="stat-card__value">—</div><div class="stat-card__label">Impresiones (Viewability)</div></div>
        <div class="stat-card"><div class="stat-card__value">—</div><div class="stat-card__label">Clics</div></div>
        <div class="stat-card"><div class="stat-card__value">—</div><div class="stat-card__label">Clics Únicos</div></div>
        <div class="stat-card"><div class="stat-card__value">—</div><div class="stat-card__label">CTR</div></div>
    </div>

    <table class="admin-table">
        <thead><tr><th>Patrocinador</th><th>Posición</th><th>Estado</th><th>Impresiones</th><th>Clics</th><th>Clics Únicos</th><th>CTR</th></tr></thead>
        <tbody id="report-table-body"><tr><td colspan="7">Cargando…</td></tr></tbody>
    </table>

    <p class="note" id="report-note"></p>
</div>
<script src="<?= base_path() ?>/assets/js/admin.js"></script>
<script src="<?= base_path() ?>/assets/js/sponsors_dashboard.js" defer></script>
</body>
</html>
