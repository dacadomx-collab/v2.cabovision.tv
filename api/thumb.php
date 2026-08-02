<?php

declare(strict_types=1);

// =============================================================================
// api/thumb.php — Miniatura on-demand para tarjetas de artículo (article_card),
// recortada "cover" sin distorsión + AVIF/WebP por negociación de contenido +
// caché en disco — mismo patrón ya validado en api/og_image.php, reutilizando
// build_cover_crop() (helpers/media.php) en vez de duplicar la lógica
// (Mandamiento #10).
//
// Por qué on-demand y no al subir la imagen (como hacía el sistema legacy,
// que generaba thumbnail 400x300 + hero 1000x500 fijos en el momento del
// alta): un derivado cacheado por (ruta, tamaño, formato) es más flexible —
// cambiar el layout de las tarjetas no exige re-subir ni re-procesar nada
// histórico — y evita gastar CPU/disco en tamaños que quizá nunca se sirvan.
//
// Endpoint: GET /api/thumb.php?path={relative_path}&w={width}&h={height}
// Tamaños permitidos: whitelist fija (evita que cualquiera pida recortes
// arbitrarios y llene el caché de basura).
// =============================================================================

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/media.php';

const THUMB_CACHE_DIR = __DIR__ . '/../assets/cache/thumb';
const THUMB_ALLOWED_SIZES = [
    '600x400',  // article-card estándar
    '800x450',  // article-card destacado / 16:9
    '300x300',  // miniatura cuadrada (candidatos, autores)
];

$path = $_GET['path'] ?? '';
if (!preg_match('#^\d+/\d{4}/\d{2}/\d{2}/[\w\-.]+\.(jpg|jpeg|png|webp)$#i', $path)) {
    http_response_code(400);
    exit;
}

$width  = (int) ($_GET['w'] ?? 600);
$height = (int) ($_GET['h'] ?? 400);
if (!in_array("{$width}x{$height}", THUMB_ALLOWED_SIZES, true)) {
    http_response_code(400);
    exit;
}

$database = new Database();
$pdo      = $database->getConnection();

$bytes = fetch_media_bytes($pdo, $path);
if ($bytes === null) {
    http_response_code(302);
    header('Location: ' . media_placeholder_path());
    exit;
}

$accept    = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
$wantsAvif = function_exists('imageavif') && str_contains($accept, 'image/avif');
$wantsWebp = function_exists('imagewebp') && str_contains($accept, 'image/webp');
$format    = $wantsAvif ? 'avif' : ($wantsWebp ? 'webp' : 'jpeg');
$ext       = $format === 'jpeg' ? 'jpg' : $format;

if (!is_dir(THUMB_CACHE_DIR)) {
    @mkdir(THUMB_CACHE_DIR, 0755, true);
}
$cacheKey  = hash('sha256', "{$path}|{$width}x{$height}");
$cacheFile = THUMB_CACHE_DIR . '/' . $cacheKey . '.' . $ext;

header('Cache-Control: public, max-age=31536000, immutable');
header('Vary: Accept');

if (is_file($cacheFile)) {
    header('Content-Type: image/' . $format);
    readfile($cacheFile);
    exit;
}

$encoded = build_cover_crop($bytes, $width, $height, $format);
if ($encoded === false) {
    http_response_code(302);
    header('Location: ' . media_placeholder_path());
    exit;
}

@file_put_contents($cacheFile, $encoded);
header('Content-Type: image/' . $format);
echo $encoded;
