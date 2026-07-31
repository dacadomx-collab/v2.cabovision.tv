<?php

declare(strict_types=1);

// =============================================================================
// api/og_image.php — Pipeline Open Graph: sirve cualquier media_asset ya
// migrado recortado a EXACTAMENTE 1200x630 (og:image/twitter:image), en
// WebP/AVIF por negociación de contenido — mismo criterio ya validado en
// api/media_bridge.php (Accept real, AVIF > WebP > JPEG), pero sobre un
// DERIVADO recortado, no el original completo.
//
// Endpoint: GET /api/og_image.php?path={relative_path de media_assets}
// Reutiliza fetch_media_bytes() (helpers/media.php) para el origen Hot/Cold
// — cero lógica de proxy duplicada (Mandamiento #10).
//
// Recorte "cover" sin distorsión (GD puro, sin librerías): se calcula el
// rectángulo fuente que YA tiene la proporción 1200:630 (recortando el
// sobrante del lado más largo, nunca estirando), y se resamplea directo a
// 1200x630 en una sola llamada — nunca hay un paso intermedio con la imagen
// deformada.
// =============================================================================

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/media.php';

const OG_WIDTH  = 1200;
const OG_HEIGHT = 630;
const OG_CACHE_DIR = __DIR__ . '/../assets/cache/og';

$path = $_GET['path'] ?? '';
if (!preg_match('#^\d+/\d{4}/\d{2}/\d{2}/[\w\-.]+\.(jpg|jpeg|png|webp)$#i', $path)) {
    http_response_code(400);
    exit;
}

/** Recorta $bytes a exactamente OG_WIDTHxOG_HEIGHT ("cover", sin distorsión) y lo codifica en $format. */
function build_og_crop(string $bytes, string $format): string|false
{
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $targetRatio = OG_WIDTH / OG_HEIGHT;
    $srcRatio    = $srcW / $srcH;

    if ($srcRatio > $targetRatio) {
        // Fuente más ancha que el objetivo — se recorta el ancho, se conserva el alto completo.
        $cropH = $srcH;
        $cropW = (int) round($srcH * $targetRatio);
        $cropX = (int) round(($srcW - $cropW) / 2);
        $cropY = 0;
    } else {
        // Fuente más alta (o igual) que el objetivo — se recorta el alto, se conserva el ancho completo.
        $cropW = $srcW;
        $cropH = (int) round($srcW / $targetRatio);
        $cropX = 0;
        $cropY = (int) round(($srcH - $cropH) / 2);
    }

    $dst = imagecreatetruecolor(OG_WIDTH, OG_HEIGHT);
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, OG_WIDTH, OG_HEIGHT, $cropW, $cropH);
    imagedestroy($src);

    ob_start();
    $ok = $format === 'avif' ? @imageavif($dst, null, 60) : ($format === 'webp' ? @imagewebp($dst, null, 82) : @imagejpeg($dst, null, 85));
    $encoded = ob_get_clean();
    imagedestroy($dst);

    return $ok !== false && $encoded !== '' ? $encoded : false;
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

if (!is_dir(OG_CACHE_DIR)) {
    @mkdir(OG_CACHE_DIR, 0755, true);
}
$cacheKey  = hash('sha256', $path); // determinístico por ruta — mismo archivo fuente = mismo recorte siempre
$cacheFile = OG_CACHE_DIR . '/' . $cacheKey . '.' . $ext;

header('Cache-Control: public, max-age=31536000, immutable'); // derivado determinístico — nunca cambia para la misma ruta fuente
header('Vary: Accept');

if (is_file($cacheFile)) {
    header('Content-Type: image/' . $format);
    readfile($cacheFile);
    exit;
}

$encoded = build_og_crop($bytes, $format);
if ($encoded === false) {
    http_response_code(302);
    header('Location: ' . media_placeholder_path());
    exit;
}

@file_put_contents($cacheFile, $encoded);
header('Content-Type: image/' . $format);
echo $encoded;
