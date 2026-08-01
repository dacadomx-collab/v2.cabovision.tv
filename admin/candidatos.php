<?php

declare(strict_types=1);

// =============================================================================
// admin/candidatos.php — Gestión de Candidatos (crear/editar), portado desde
// el sistema legacy (Admin\CandidateController). v2 ya tenía la tabla
// `candidates` migrada (73 registros) pero sin ninguna interfaz — este panel
// cierra ese gap real.
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
<title>Candidatos — CaboVision.tv</title>
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
    .editor-actions button {
        padding: 0.65rem 1.5rem; border: none; border-radius: var(--radius); background: var(--color-accent);
        color: var(--color-accent-contrast); font-weight: 700; cursor: pointer;
    }
    #candidate-feedback { margin-top: var(--space-sm); font-size: 0.9rem; }
    .image-drop {
        border: 1px dashed var(--color-border); border-radius: var(--radius); padding: var(--space-md);
        text-align: center; cursor: pointer; background: var(--color-bg-alt);
    }
    .image-preview { max-width: 100%; max-height: 160px; margin-top: var(--space-sm); border-radius: var(--radius); display: none; }

    table.admin-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-top: var(--space-md); }
    table.admin-table th, table.admin-table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--color-border); }
    table.admin-table th { color: var(--color-text-muted); text-transform: uppercase; font-size: 0.7rem; }
    table.admin-table a.edit-link { color: var(--color-accent); font-weight: 600; text-decoration: none; }
    .search-row { display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm); }
    .search-row input { flex: 1; padding: 0.5rem 0.75rem; border: 1px solid var(--color-border); border-radius: var(--radius); background: var(--color-bg-alt); color: var(--color-text); }
</style>
</head>
<body>
<div class="admin-shell">
    <div class="admin-topbar">
        <h1>Candidatos</h1>
        <nav>
            <a href="<?= base_path() ?>/admin/editor.php">Editor</a>
            <a href="<?= base_path() ?>/admin/dashboard.php">Patrocinadores</a>
            <a href="<?= base_path() ?>/admin/sponsors_dashboard.php">Dashboard B2B</a>
            <a href="<?= base_path() ?>/admin/categorias.php">Categorías</a>
            <a href="<?= base_path() ?>/admin/candidatos.php" class="is-active">Candidatos</a>
            <a href="<?= base_path() ?>/admin/users.php">Usuarios</a>
        </nav>
        <button type="button" id="logout-btn">Cerrar sesión</button>
    </div>

    <section>
        <h2 id="form-title">Crear Candidato</h2>
        <form id="candidate-form" enctype="multipart/form-data">
            <input type="hidden" id="candidate_id" value="">
            <div class="editor-grid">
                <div class="editor-field">
                    <label for="name">Nombre completo</label>
                    <input type="text" id="name" required maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="parties">Partido / Agrupación</label>
                    <input type="text" id="parties" required maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="age">Edad</label>
                    <input type="number" id="age" min="18" max="120">
                </div>
                <div class="editor-field">
                    <label for="gender">Género (0=No especificado, 1=Hombre, 2=Mujer)</label>
                    <input type="number" id="gender" min="0" max="2" value="0">
                </div>
                <div class="editor-field">
                    <label for="position">Posición en boleta</label>
                    <input type="number" id="position" min="0">
                </div>
                <div class="editor-field">
                    <label for="district">Distrito</label>
                    <input type="number" id="district" min="0">
                </div>
                <div class="editor-field">
                    <label for="email">Correo</label>
                    <input type="email" id="email" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="public_phone">Teléfono público</label>
                    <input type="text" id="public_phone" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="house_address">Dirección</label>
                    <input type="text" id="house_address" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="web_page">Sitio web</label>
                    <input type="url" id="web_page" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="facebook">Facebook</label>
                    <input type="text" id="facebook" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="twitter">Twitter</label>
                    <input type="text" id="twitter" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="instagram">Instagram</label>
                    <input type="text" id="instagram" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="youtube">YouTube</label>
                    <input type="text" id="youtube" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="entrevista">Enlace a entrevista</label>
                    <input type="text" id="entrevista" maxlength="255">
                </div>
                <div class="editor-field">
                    <label for="photo">Foto</label>
                    <label class="image-drop" for="photo">
                        Haz clic para elegir una foto (JPEG/PNG/WebP, máx. 5 MB)
                        <img id="image-preview" class="image-preview" alt="Vista previa">
                    </label>
                    <input type="file" id="photo" accept="image/jpeg,image/png,image/webp" style="display:none">
                </div>
                <div class="editor-field" style="grid-column: 1 / -1">
                    <label for="description">Descripción / Biografía</label>
                    <textarea id="description" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="editor-actions">
                <button type="submit" id="submit-btn">Crear candidato</button>
                <button type="button" id="cancel-edit-btn" hidden>Cancelar edición</button>
            </div>
            <p id="candidate-feedback" hidden></p>
        </form>
    </section>

    <section>
        <h2>Candidatos existentes</h2>
        <div class="search-row">
            <input type="text" id="search-input" placeholder="Buscar por nombre o partido…">
        </div>
        <table class="admin-table">
            <thead><tr><th>Nombre</th><th>Partido</th><th>Distrito</th><th></th></tr></thead>
            <tbody id="candidates-table-body"><tr><td colspan="4">Cargando…</td></tr></tbody>
        </table>
    </section>
</div>
<script src="<?= base_path() ?>/assets/js/admin.js"></script>
<script src="<?= base_path() ?>/assets/js/candidatos.js" defer></script>
</body>
</html>
