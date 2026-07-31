<?php

declare(strict_types=1);

// =============================================================================
// api/articles_create.php v2 — Alta de noticias (Editor "Una Sola Pantalla")
// Endpoint: POST /api/articles_create.php (multipart/form-data)
// Auth: Bearer JWT + Rol (Admin | Autor | Editor) — Mandamiento #14
//
// v2 (2026-07-21) — hallazgos reales de la v1 corregidos:
//   1. NO subía imágenes en absoluto — no había ningún $_FILES, el editor no
//      podía adjuntar "Imagen Principal". Ahora sí, con validación militar.
//   2. `content` se guardaba SIN sanitizar ("se guarda tal cual" decía el
//      comentario v1) — riesgo XSS real, ya que articulo.php lo imprime sin
//      escapar (es HTML editorial por diseño). Ahora pasa por
//      sanitize_article_html() (whitelist estricta, consolidada en
//      helpers/input_sanitizer.php).
//   3. El body era JSON puro — no puede llevar un archivo. Se cambia a
//      multipart/form-data (texto en $_POST, imagen en $_FILES), único
//      cambio de contrato — documentado aquí y en knowledge/03.
//
// Columnas nativas usadas (knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md): title,
// alias, content, extract, thumbnail, category_id, status_id, user_id.
// NINGUNA columna nueva, NINGÚN cambio de schema.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php'; // expone $authPayload, 401 si no hay token válido
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';

const STATUS_PENDIENTE   = 0;
const MEDIA_TENANT_ID    = 1002; // Misma constante que ColdStorageClient/link_articles_media.php (Mandamiento #10)
const MAX_IMAGE_BYTES    = 5 * 1024 * 1024; // 5 MB
const ALLOWED_IMAGE_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

requireRole(['Admin', 'Autor', 'Editor'], $authPayload);

asfl_log('REQUEST', ['endpoint' => 'articles_create.php', 'method' => $_SERVER['REQUEST_METHOD']]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Método no permitido.', 405);
}

$title      = sanitize_string((string) ($_POST['title'] ?? ''), 400);
$rawContent = trim((string) ($_POST['content'] ?? ''));
$categoryId = sanitize_int($_POST['category_id'] ?? null, 0);
$extract    = sanitize_string((string) ($_POST['extract'] ?? ''), 255);
$slugInput  = sanitize_string((string) ($_POST['slug'] ?? ''), 400);
$videoUrl   = sanitize_string((string) ($_POST['video_url'] ?? ''), 500);

/**
 * Video embebido (2026-07-23) — "Editor Una Sola Pantalla para noticias Y
 * VIDEOS" sin agregar ninguna columna nueva (Mandamiento #9): el <iframe> se
 * arma AQUÍ, en el servidor, a partir únicamente del ID extraído por regex —
 * nunca a partir de HTML que mande el cliente. Por eso NO hace falta
 * blanquear <iframe> en sanitize_article_html() (que seguiría sin permitirlo
 * para cualquier otro origen): este embed no pasa por esa whitelist, se
 * concatena después, porque ya es HTML de confianza generado por nosotros.
 */
function buildVideoEmbed(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/))([A-Za-z0-9_-]{11})#', $url, $m) === 1) {
        $id = $m[1];
        return '<div class="video-embed"><iframe src="https://www.youtube-nocookie.com/embed/' . $id
            . '" title="Video" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
    }

    if (preg_match('#vimeo\.com/(\d+)#', $url, $m) === 1) {
        $id = $m[1];
        return '<div class="video-embed"><iframe src="https://player.vimeo.com/video/' . $id
            . '" title="Video" loading="lazy" allowfullscreen></iframe></div>';
    }

    send_error('El enlace de video no es válido. Usa una URL de YouTube o Vimeo.', 422);
}

if ($title === '') {
    send_error('El título es requerido.', 422);
}
if ($rawContent === '') {
    send_error('El contenido es requerido.', 422);
}
if ($categoryId <= 0) {
    send_error('La categoría es requerida.', 422);
}

// Whitelist estricta de HTML — nunca se guarda el HTML del editor "tal cual" (v1 lo hacía).
$content = sanitize_article_html($rawContent);
if (trim(strip_tags($content)) === '') {
    send_error('El contenido no puede quedar vacío tras la sanitización.', 422);
}

$videoEmbed = buildVideoEmbed($videoUrl);
if ($videoEmbed !== null) {
    $content = $videoEmbed . $content; // HTML de confianza, generado por el servidor — se antepone sin pasar por la whitelist
}

/**
 * Validación militar de imagen — finfo_file() sobre los BYTES reales (nunca
 * el Content-Type declarado por el cliente) + getimagesize() para rechazar
 * políglotas (imagen válida + payload PHP anexado, patrón real del
 * toggige-arrow.jpg purgado en Fase 1). Nombre de archivo generado por
 * random_bytes() — el nombre original del usuario NUNCA toca el filesystem
 * (bloquea path traversal por diseño, no por sanitización de string).
 *
 * @return string|null relative_path si se subió una imagen válida, null si no se adjuntó ninguna.
 */
function handleImageUpload(): ?string
{
    if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // "Imagen Principal" es opcional
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        send_error('Error al recibir la imagen.', 422);
    }
    if ($_FILES['image']['size'] > MAX_IMAGE_BYTES) {
        send_error('La imagen excede el límite de 5 MB.', 422);
    }

    $tmpPath = $_FILES['image']['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        send_error('Archivo inválido.', 422); // defensa contra manipulación directa de $_FILES
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpPath) ?: '';
    finfo_close($finfo);

    if (!isset(ALLOWED_IMAGE_MIME[$mime])) {
        send_error('Formato de imagen no permitido. Usa JPEG, PNG o WebP.', 422);
    }

    // getimagesize() decodifica la estructura real de la imagen — un polígloto
    // (JPEG válido con PHP anexado) puede pasar finfo pero esto lo detecta
    // porque exige dimensiones reales y una estructura de imagen coherente.
    if (@getimagesize($tmpPath) === false) {
        send_error('El archivo no es una imagen válida o está corrupto.', 422);
    }

    $extension    = ALLOWED_IMAGE_MIME[$mime];
    $randomName   = bin2hex(random_bytes(16)) . '.' . $extension; // nombre del usuario NUNCA se usa
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
        error_log('[' . date('Y-m-d H:i:s') . '] [articles_create] No se pudo crear el directorio: ' . $absoluteDir);
        send_error('Error interno al procesar la imagen.', 500);
    }

    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        error_log('[' . date('Y-m-d H:i:s') . '] [articles_create] move_uploaded_file falló: ' . $absolutePath);
        send_error('Error interno al guardar la imagen.', 500);
    }

    return $relativePath; // Hot tier: recién publicada, vive en el hosting hasta que cold_storage_migration.php la archive en >2 años
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $categoryCheck = $pdo->prepare('SELECT `id` FROM `categories` WHERE `id` = :id LIMIT 1');
    $categoryCheck->execute([':id' => $categoryId]);
    if ($categoryCheck->fetch(\PDO::FETCH_ASSOC) === false) {
        send_error('La categoría indicada no existe.', 422);
    }

    $thumbnailPath = handleImageUpload();

    $baseAlias = $slugInput !== '' ? slugify_text($slugInput) : slugify_text($title);
    if ($baseAlias === '') {
        send_error('No fue posible generar un alias válido a partir del título.', 422);
    }

    $alias  = $baseAlias;
    $suffix = 2;
    $aliasCheck = $pdo->prepare('SELECT `id` FROM `articles` WHERE `alias` = :alias LIMIT 1');
    while (true) {
        $aliasCheck->execute([':alias' => $alias]);
        if ($aliasCheck->fetch(\PDO::FETCH_ASSOC) === false) {
            break;
        }
        $alias = "{$baseAlias}-{$suffix}";
        $suffix++;
    }

    $userId = (int) ($authPayload['sub'] ?? 0);

    // Prepared statement puro — cero concatenación SQL (Mandamiento #2/#5).
    $insert = $pdo->prepare(
        'INSERT INTO `articles`
            (`title`, `alias`, `content`, `extract`, `thumbnail`, `category_id`, `status_id`, `user_id`, `published_at`, `created_at`, `updated_at`)
         VALUES
            (:title, :alias, :content, :extract, :thumbnail, :category_id, :status_id, :user_id, NOW(), NOW(), NOW())'
    );
    $insert->execute([
        ':title'       => $title,
        ':alias'       => $alias,
        ':content'     => $content,
        ':extract'     => $extract !== '' ? $extract : null,
        ':thumbnail'   => $thumbnailPath,
        ':category_id' => $categoryId,
        ':status_id'   => STATUS_PENDIENTE,
        ':user_id'     => $userId,
    ]);

    $newId = (int) $pdo->lastInsertId();

    // Automatización SEO (2026-07-21): el JSON-LD/OG/Twitter Cards NO se
    // pre-calculan ni se guardan aquí — articulo.php ya los genera en tiempo
    // real desde estas mismas columnas (title/extract/thumbnail/published_at/
    // categoría) cada vez que se sirve la página. Guardar una copia estática
    // en el INSERT la dejaría desactualizada si el artículo se edita después
    // — se automatiza en el lugar correcto (al servir), no al ingerir.
    asfl_log('RESPONSE', ['endpoint' => 'articles_create.php', 'status' => 'success', 'id' => $newId]);

    send_success('Noticia creada como borrador (pendiente de publicación).', [
        'id'    => $newId,
        'alias' => $alias,
        'thumbnail' => $thumbnailPath,
    ], 201);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [articles_create] ' . $e->getMessage());
    send_error('Error interno al crear la noticia.', 500);
}
