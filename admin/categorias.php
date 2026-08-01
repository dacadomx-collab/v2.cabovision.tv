<?php

declare(strict_types=1);

// =============================================================================
// admin/categorias.php — Gestión de Categorías (crear/editar), exclusivo del
// rol Admin — portado desde el sistema legacy (Admin\CategoryController,
// routes/web.php bajo middleware ['admin']). Antes v2 solo LEÍA categorías
// (api/categories_list.php); este panel cierra ese gap real.
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
<title>Categorías — CaboVision.tv</title>
<link rel="icon" href="<?= base_path() ?>/favicon.ico">
<script>window.BASE_PATH = "<?= htmlspecialchars(base_path(), ENT_QUOTES, 'UTF-8') ?>";</script>
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
    .editor-field input, .editor-field select, .editor-field textarea {
        width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--color-border); border-radius: var(--radius);
        background: var(--color-bg-alt); color: var(--color-text); font-size: 0.95rem; font-family: var(--font-body);
    }
    .editor-field textarea { min-height: 90px; resize: vertical; }
    .editor-field label.checkbox-label { display: flex; align-items: center; gap: 0.4rem; text-transform: none; font-size: 0.9rem; color: var(--color-text); }
    .editor-actions button {
        padding: 0.65rem 1.5rem; border: none; border-radius: var(--radius); background: var(--color-accent);
        color: var(--color-accent-contrast); font-weight: 700; cursor: pointer;
    }
    #category-feedback { margin-top: var(--space-sm); font-size: 0.9rem; }

    table.admin-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-top: var(--space-md); }
    table.admin-table th, table.admin-table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--color-border); }
    table.admin-table th { color: var(--color-text-muted); text-transform: uppercase; font-size: 0.7rem; }
    table.admin-table a.edit-link { color: var(--color-accent); font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
<div class="admin-shell">
    <div class="admin-topbar">
        <h1>Categorías</h1>
        <nav>
            <a href="<?= base_path() ?>/admin/editor.php">Editor</a>
            <a href="<?= base_path() ?>/admin/dashboard.php">Patrocinadores</a>
            <a href="<?= base_path() ?>/admin/sponsors_dashboard.php">Dashboard B2B</a>
            <a href="<?= base_path() ?>/admin/categorias.php" class="is-active">Categorías</a>
            <a href="<?= base_path() ?>/admin/candidatos.php">Candidatos</a>
            <a href="<?= base_path() ?>/admin/users.php">Usuarios</a>
        </nav>
        <button type="button" id="logout-btn">Cerrar sesión</button>
    </div>

    <section>
        <h2 id="form-title">Crear Categoría</h2>
        <form id="category-form">
            <input type="hidden" id="category_id" value="">
            <div class="editor-grid">
                <div class="editor-field">
                    <label for="name">Nombre</label>
                    <input type="text" id="name" required maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="parent_id">Categoría padre (opcional)</label>
                    <select id="parent_id">
                        <option value="">— Ninguna (categoría de primer nivel) —</option>
                    </select>
                </div>
                <div class="editor-field" style="grid-column: 1 / -1">
                    <label for="description">Descripción</label>
                    <textarea id="description" maxlength="2000"></textarea>
                </div>
                <div class="editor-field">
                    <label class="checkbox-label"><input type="checkbox" id="publish" checked> Publicada (visible en el menú)</label>
                </div>
            </div>
            <div class="editor-actions">
                <button type="submit" id="submit-btn">Crear categoría</button>
                <button type="button" id="cancel-edit-btn" hidden>Cancelar edición</button>
            </div>
            <p id="category-feedback" hidden></p>
        </form>
    </section>

    <section>
        <h2>Categorías existentes</h2>
        <table class="admin-table">
            <thead><tr><th>Nombre</th><th>Padre</th><th>Estado</th><th></th></tr></thead>
            <tbody id="categories-table-body"><tr><td colspan="4">Cargando…</td></tr></tbody>
        </table>
    </section>
</div>
<script src="<?= base_path() ?>/assets/js/admin.js"></script>
<script src="<?= base_path() ?>/assets/js/categorias.js" defer></script>
</body>
</html>
