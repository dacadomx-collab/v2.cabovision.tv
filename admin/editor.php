<?php

declare(strict_types=1);

// =============================================================================
// admin/editor.php — Editor "Una Sola Pantalla" (2026-07-21). Título, Slug,
// Imagen Principal, Extracto y Categoría, todos visibles a la vez, sin
// pestañas ni pasos — Fricción Cero real. Envía multipart/form-data a
// api/articles_create.php v2 (assets/js/editor.js).
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
<title>Editor — CaboVision.tv</title>
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
    /* Móvil (2026-07-23): antes el <h1> competía por ancho con el nav y se
       partía en 3 líneas, y "Dashboard B2B" se cortaba a la mitad dentro del
       mismo enlace (bug real visto con Playwright a 390px). El título toma
       su propia fila completa y los enlaces ya no parten palabras (nowrap +
       flex-wrap en el contenedor, nunca en el texto de un solo link). */
    @media (max-width: 640px) {
        .admin-topbar h1 { flex: 1 1 100%; font-size: 1.15rem; }
    }
    #logout-btn { background: transparent; border: 1px solid var(--color-border); border-radius: var(--radius); padding: 0.4rem 0.9rem; cursor: pointer; color: var(--color-text); }

    /* Editor "Una Sola Pantalla" — todos los campos visibles a la vez, sin pestañas ni pasos */
    .editor-grid { display: grid; grid-template-columns: 1fr; gap: var(--space-sm); }
    @media (min-width: 900px) {
        .editor-grid { grid-template-columns: 2fr 1fr; }
    }
    .editor-field { margin-bottom: var(--space-sm); }
    .editor-field label { display: block; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .editor-field input[type="text"], .editor-field input[type="url"], .editor-field select, .editor-field textarea {
        width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--color-border); border-radius: var(--radius);
        background: var(--color-bg-alt); color: var(--color-text); font-size: 0.95rem; font-family: var(--font-body);
    }
    .editor-field textarea { min-height: 260px; resize: vertical; }
    .slug-preview { font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.25rem; font-family: monospace; }
    .image-drop {
        border: 1px dashed var(--color-border); border-radius: var(--radius); padding: var(--space-md);
        text-align: center; cursor: pointer; background: var(--color-bg-alt);
    }
    .image-preview { max-width: 100%; max-height: 160px; margin-top: var(--space-sm); border-radius: var(--radius); display: none; }
    .editor-actions button {
        padding: 0.65rem 1.5rem; border: none; border-radius: var(--radius); background: var(--color-accent);
        color: var(--color-accent-contrast); font-weight: 700; cursor: pointer;
    }
    #article-feedback { margin-top: var(--space-sm); font-size: 0.9rem; }
    .publish-now-btn {
        margin-top: var(--space-sm); padding: 0.5rem 1.1rem; border: 1px solid var(--color-accent);
        border-radius: var(--radius); background: transparent; color: var(--color-accent);
        font-weight: 700; cursor: pointer;
    }
    .seo-note { font-size: 0.8rem; color: var(--color-text-muted); background: var(--color-bg-alt); border-left: 4px solid var(--color-text-muted); border-radius: 0.25rem; padding: 0.75rem 1rem; margin-top: var(--space-md); }

    .recent-articles { margin-top: var(--space-lg); }
    table.admin-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-top: var(--space-sm); }
    table.admin-table th, table.admin-table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--color-border); }
    table.admin-table th { color: var(--color-text-muted); text-transform: uppercase; font-size: 0.7rem; }
    table.admin-table a.edit-link { color: var(--color-accent); font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
<div class="admin-shell">
    <div class="admin-topbar">
        <h1>Editor — Una Sola Pantalla</h1>
        <nav>
            <a href="<?= base_path() ?>/admin/editor.php" class="is-active">Editor</a>
            <a href="<?= base_path() ?>/admin/dashboard.php">Patrocinadores</a>
            <a href="<?= base_path() ?>/admin/sponsors_dashboard.php">Dashboard B2B</a>
            <a href="<?= base_path() ?>/admin/categorias.php">Categorías</a>
            <a href="<?= base_path() ?>/admin/candidatos.php">Candidatos</a>
            <a href="<?= base_path() ?>/admin/users.php">Usuarios</a>
        </nav>
        <button type="button" id="logout-btn">Cerrar sesión</button>
    </div>

    <form id="article-form" enctype="multipart/form-data">
        <div class="editor-grid">
            <div>
                <div class="editor-field">
                    <label for="title">Título</label>
                    <input type="text" id="title" name="title" required maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="slug">Slug (URL)</label>
                    <input type="text" id="slug" name="slug" maxlength="255" placeholder="Se genera automáticamente del título">
                    <div class="slug-preview"><?= base_path() ?>/articulo.php?alias=<span id="slug-preview-text">…</span></div>
                </div>
                <div class="editor-field">
                    <label for="extract">Extracto (SEO / resumen)</label>
                    <input type="text" id="extract" name="extract" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="video_url">Video (opcional — YouTube o Vimeo)</label>
                    <input type="url" id="video_url" name="video_url" maxlength="500" placeholder="https://www.youtube.com/watch?v=... o https://vimeo.com/...">
                </div>
                <div class="editor-field">
                    <label for="content">Contenido</label>
                    <textarea id="content" name="content" required></textarea>
                </div>
            </div>
            <div>
                <div class="editor-field">
                    <label for="category_id">Categoría</label>
                    <select id="category_id" name="category_id" required></select>
                </div>
                <div class="editor-field">
                    <label for="image">Imagen Principal</label>
                    <label class="image-drop" for="image">
                        Haz clic para elegir una imagen (JPEG/PNG/WebP, máx. 5 MB)
                        <img id="image-preview" class="image-preview" alt="Vista previa">
                    </label>
                    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" style="display:none">
                </div>
                <div class="seo-note">
                    SEO automático: al publicar, <code>articulo.php</code> genera en tiempo real el JSON-LD (NewsArticle + BreadcrumbList), Open Graph y Twitter Cards desde estos mismos campos — no necesitas capturarlos aparte.
                </div>
            </div>
        </div>
        <div class="editor-actions">
            <button type="submit">Publicar</button>
        </div>
        <p id="article-feedback" hidden></p>
    </form>

    <section class="recent-articles">
        <h2>Notas recientes</h2>
        <p style="font-size:0.85rem;color:var(--color-text-muted)">Para reemplazar la imagen de una nota ya publicada (o corregir texto), haz clic en "Editar".</p>
        <table class="admin-table">
            <thead><tr><th>Título</th><th>Categoría</th><th>Publicado</th><th></th></tr></thead>
            <tbody id="recent-articles-body"><tr><td colspan="4">Cargando…</td></tr></tbody>
        </table>
    </section>
</div>
<script src="<?= base_path() ?>/assets/js/admin.js"></script>
<script src="<?= base_path() ?>/assets/js/editor.js" defer></script>
</body>
</html>
