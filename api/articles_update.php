<?php

declare(strict_types=1);

// =============================================================================
// api/articles_update.php — Edición de una noticia existente (título,
// contenido, extracto, categoría e Imagen Principal). Endpoint: POST
// /api/articles_update.php (multipart/form-data, requiere `id`).
// Auth: Bearer JWT + Rol (Admin | Autor | Editor) — mismo set que
// articles_create.php (Mandamiento #10: no inventar una jerarquía de roles
// paralela para la misma acción editorial).
//
// Reusa exactamente la misma validación militar de imagen que
// articles_create.php (finfo + getimagesize, nombre aleatorio, ruta
// {tenant}/{YYYY}/{MM}/{DD}/) — la imagen es opcional: si no se adjunta,
// el thumbnail existente no se toca.
//
// Protocolo de 6 Capas (Mandamiento #2):
//   Capa 1: CORS whitelist (cors.php)
//   Capa 2: RBAC — requireRole(['Admin','Autor','Editor'])
//   Capa 3: Restricción explícita de verbo HTTP (POST únicamente)
//   Capa 4: Sanitización estricta de cada campo recibido
//   Capa 5: PDO ATTR_EMULATE_PREPARES=false + prepared statement puro
//   Capa 6: Try/Catch global — error real a error_log, cliente recibe JSON genérico
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php'; // expone $authPayload, 401 si no hay token válido
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';

const MEDIA_TENANT_ID    = 1002; // Misma constante que articles_create.php (Mandamiento #10)
const MAX_IMAGE_BYTES    = 5 * 1024 * 1024; // 5 MB
const ALLOWED_IMAGE_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

requireRole(['Admin', 'Autor', 'Editor'], $authPayload);

asfl_log('REQUEST', ['endpoint' => 'articles_update.php', 'method' => $_SERVER['REQUEST_METHOD']]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Método no permitido.', 405);
}

$articleId  = sanitize_int($_POST['id'] ?? null, 0);
if ($articleId <= 0) {
    send_error('Falta el identificador del artículo.', 422);
}

$title      = sanitize_string((string) ($_POST['title'] ?? ''), 400);
$rawContent = trim((string) ($_POST['content'] ?? ''));
$categoryId = sanitize_int($_POST['category_id'] ?? null, 0);
$extract    = sanitize_string((string) ($_POST['extract'] ?? ''), 255);

if ($title === '') {
    send_error('El título es requerido.', 422);
}
if ($rawContent === '') {
    send_error('El contenido es requerido.', 422);
}
if ($categoryId <= 0) {
    send_error('La categoría es requerida.', 422);
}

// Whitelist estricta de HTML — mismo tratamiento que articles_create.php,
// nunca se guarda el HTML del editor "tal cual".
$content = sanitize_article_html($rawContent);
if (trim(strip_tags($content)) === '') {
    send_error('El contenido no puede quedar vacío tras la sanitización.', 422);
}

/**
 * Validación militar de imagen — idéntica a articles_create.php::handleImageUpload().
 * Opcional: si no se adjunta archivo, retorna null y el thumbnail existente
 * no se toca (no se borra la imagen anterior al editar solo el texto).
 */
function handleImageUpload(): ?string
{
    if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        send_error('Error al recibir la imagen.', 422);
    }
    if ($_FILES['image']['size'] > MAX_IMAGE_BYTES) {
        send_error('La imagen excede el límite de 5 MB.', 422);
    }

    $tmpPath = $_FILES['image']['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        send_error('Archivo inválido.', 422);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpPath) ?: '';
    finfo_close($finfo);

    if (!isset(ALLOWED_IMAGE_MIME[$mime])) {
        send_error('Formato de imagen no permitido. Usa JPEG, PNG o WebP.', 422);
    }

    if (@getimagesize($tmpPath) === false) {
        send_error('El archivo no es una imagen válida o está corrupto.', 422);
    }

    $extension    = ALLOWED_IMAGE_MIME[$mime];
    $randomName   = bin2hex(random_bytes(16)) . '.' . $extension;
    $today        = new DateTimeImmutable();
    $relativePath = sprintf(
        '%d/%s/%s/%s/%s',
        MEDIA_TENANT_ID,
        $today->format('Y'),
        $today->format('m'),
        $today->format('d'),
        $randomName
    );
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;
    $absoluteDir  = dirname($absolutePath);

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        error_log('[' . date('Y-m-d H:i:s') . '] [articles_update] No se pudo crear el directorio: ' . $absoluteDir);
        send_error('Error interno al procesar la imagen.', 500);
    }

    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        error_log('[' . date('Y-m-d H:i:s') . '] [articles_update] move_uploaded_file falló: ' . $absolutePath);
        send_error('Error interno al guardar la imagen.', 500);
    }

    return $relativePath;
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $articleCheck = $pdo->prepare('SELECT `id`, `thumbnail` FROM `articles` WHERE `id` = :id LIMIT 1');
    $articleCheck->execute([':id' => $articleId]);
    $existing = $articleCheck->fetch(\PDO::FETCH_ASSOC);
    if ($existing === false) {
        send_error('El artículo indicado no existe.', 404);
    }

    $categoryCheck = $pdo->prepare('SELECT `id` FROM `categories` WHERE `id` = :id LIMIT 1');
    $categoryCheck->execute([':id' => $categoryId]);
    if ($categoryCheck->fetch(\PDO::FETCH_ASSOC) === false) {
        send_error('La categoría indicada no existe.', 422);
    }

    $newThumbnail = handleImageUpload();
    $thumbnail    = $newThumbnail ?? $existing['thumbnail'];

    $update = $pdo->prepare(
        'UPDATE `articles`
         SET `title` = :title, `content` = :content, `extract` = :extract,
             `category_id` = :category_id, `thumbnail` = :thumbnail, `updated_at` = NOW()
         WHERE `id` = :id'
    );
    $update->execute([
        ':title'       => $title,
        ':content'     => $content,
        ':extract'     => $extract !== '' ? $extract : null,
        ':category_id' => $categoryId,
        ':thumbnail'   => $thumbnail,
        ':id'          => $articleId,
    ]);

    asfl_log('RESPONSE', ['endpoint' => 'articles_update.php', 'status' => 'success', 'id' => $articleId]);

    send_success('Noticia actualizada.', [
        'id'        => $articleId,
        'thumbnail' => $thumbnail,
    ]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [articles_update] ' . $e->getMessage());
    send_error('Error interno al actualizar la noticia.', 500);
}
