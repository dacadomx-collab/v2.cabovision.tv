<?php

declare(strict_types=1);

// =============================================================================
// api/media_bridge.php — "CDN Inversa": sirve Hot directo, proxea Cold vía
// túnel hacia el servidor local ACADEP. Único punto de entrada público para
// imágenes fuera de la ruta directa /images/... Ver helpers/media.php.
// =============================================================================

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/media.php';

// =============================================================================
// Fallback en cascada para rutas legacy (2026-07-31) — ?legacy_path=images/
// stories/... (formato pre-migración, nunca paso por el pipeline Hot/Cold,
// sin fila en media_assets). A diferencia de ?path= (que exige un registro
// verificado en media_assets), esto es "mejor esfuerzo": Hot local -> Cold
// bridge directo -> placeholder real con 200 OK, nunca un 404 roto en pantalla.
// =============================================================================
function serve_legacy_path(string $legacyPath): never
{
    if (preg_match('#^images/stories/[\w\-./]+\.(jpg|jpeg|png|gif)$#i', $legacyPath) !== 1) {
        http_response_code(400);
        exit;
    }

    // Capa 1: Hot local — por si algún día se llega a poblar ese árbol legacy.
    $hotFile = dirname(__DIR__) . '/' . $legacyPath;
    if (is_file($hotFile) && str_starts_with(realpath($hotFile) ?: '', dirname(__DIR__))) {
        $mime = (string) mime_content_type($hotFile);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Type: ' . $mime);
        readfile($hotFile);
        exit;
    }

    // Capa 2: Cold bridge directo — intento de mejor esfuerzo, sin fila en
    // media_assets que lo respalde (nunca se migró formalmente esta ruta).
    $envPath = dirname(__DIR__) . '/.env';
    $env     = is_readable($envPath) ? (parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: []) : [];
    $bridgeUrl = rtrim((string) ($env['ACADEP_BRIDGE_URL'] ?? ''), '/');
    $bridgeKey = (string) ($env['ACADEP_BRIDGE_KEY'] ?? '');

    if ($bridgeUrl !== '' && $bridgeKey !== '') {
        $ch = curl_init($bridgeUrl . '/view?asset=' . urlencode($legacyPath));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['X-ACADEP-Bridge-Key: ' . $bridgeKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $body !== false && $body !== '') {
            header('Cache-Control: public, max-age=86400');
            header('Content-Type: image/jpeg');
            echo $body;
            exit;
        }
    }

    // Capa 3: fallback seguro — placeholder real, 200 OK, nunca un ícono roto.
    // Ruta de FILESYSTEM directa (media_placeholder_path() devuelve una URL
    // con base_path() ya aplicado — concatenarla aquí duplicaría el prefijo).
    $placeholderFile = dirname(__DIR__) . '/assets/img/logocabovis_glow.png';
    header('Cache-Control: public, max-age=300'); // corto: no cachea el "no encontrado" a largo plazo
    header('Content-Type: image/png');
    if (is_file($placeholderFile)) {
        readfile($placeholderFile);
    }
    exit;
}

$legacyPath = $_GET['legacy_path'] ?? '';
if ($legacyPath !== '') {
    serve_legacy_path($legacyPath);
}

$path = $_GET['path'] ?? '';
if (!preg_match('#^\d+/\d{4}/\d{2}/\d{2}/[\w\-.]+\.(jpg|jpeg|png|webp)$#i', $path)) {
    http_response_code(400);
    exit;
}

// =============================================================================
// Formatos Next-Gen automáticos (2026-07-21): negociación de contenido real
// por header Accept — AVIF > WebP > original, según lo que el navegador del
// cliente soporte. Ambos formatos verificados como funcionales en este GD
// (imagewebp()/imageavif() producen bytes reales, no solo "function_exists").
// Convertido UNA vez por archivo+formato, cacheado en disco — nunca se
// recodifica en cada request.
// =============================================================================
const NEXTGEN_CACHE_DIR = __DIR__ . '/../assets/cache/nextgen';

/** Sirve $bytes en el mejor formato next-gen soportado por el cliente, con caché real en disco. */
function serve_best_format(string $bytes, string $cacheKey): void
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $wantsAvif = function_exists('imageavif') && str_contains($accept, 'image/avif');
    $wantsWebp = function_exists('imagewebp') && str_contains($accept, 'image/webp');

    $format = $wantsAvif ? 'avif' : ($wantsWebp ? 'webp' : null);

    if ($format === null) {
        header('Content-Type: image/jpeg');
        header('Vary: Accept');
        echo $bytes;
        return;
    }

    if (!is_dir(NEXTGEN_CACHE_DIR)) {
        @mkdir(NEXTGEN_CACHE_DIR, 0755, true);
    }
    $cacheFile = NEXTGEN_CACHE_DIR . '/' . $cacheKey . '.' . $format;

    if (is_file($cacheFile)) {
        header('Content-Type: image/' . $format);
        header('Vary: Accept');
        readfile($cacheFile);
        return;
    }

    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        // Bytes no decodificables por GD (formato exótico) — servir original, no romper la imagen.
        header('Content-Type: image/jpeg');
        header('Vary: Accept');
        echo $bytes;
        return;
    }

    ob_start();
    $encoded = $format === 'avif' ? @imageavif($image, null, 60) : @imagewebp($image, null, 82);
    $converted = ob_get_clean();
    imagedestroy($image);

    if ($encoded === false || $converted === '' || $converted === false) {
        header('Content-Type: image/jpeg');
        header('Vary: Accept');
        echo $bytes;
        return;
    }

    @file_put_contents($cacheFile, $converted);
    header('Content-Type: image/' . $format);
    header('Vary: Accept');
    echo $converted;
}

$database = new Database();
$pdo      = $database->getConnection();

$stmt = $pdo->prepare('SELECT storage_tier, file_hash FROM media_assets WHERE relative_path = :p LIMIT 1');
$stmt->execute([':p' => $path]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$hotFile = dirname(__DIR__) . '/' . $path;

if (($row['storage_tier'] ?? null) === 'hot' && is_file($hotFile)) {
    header('Cache-Control: public, max-age=31536000, immutable');
    $mime = (string) mime_content_type($hotFile);
    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        serve_best_format((string) file_get_contents($hotFile), (string) ($row['file_hash'] ?? sha1($path)));
    } else {
        header('Content-Type: ' . $mime);
        readfile($hotFile);
    }
    exit;
}

// fetch_media_bytes() (helpers/media.php, 2026-07-24) concentra la lógica de
// origen Hot/Cold que antes vivía duplicada aquí — reutilizada también por
// api/og_image.php (pipeline Open Graph 1200x630), Mandamiento #10. Ya
// incluye la validación real de que el archivo se subió de verdad
// (file_hash/migrated_to_cold_at poblados) antes de golpear el bridge.
$body = fetch_media_bytes($pdo, $path);
if ($body !== null) {
    header('Cache-Control: public, max-age=86400'); // corto: no promueve a Hot automáticamente
    serve_best_format($body, (string) ($row['file_hash'] ?? sha1($path)));
    exit;
}

// Fallback seguro (2026-08-01) — antes redirigia 302 al placeholder; ahora
// sirve el archivo real con 200 OK directo, igual que serve_legacy_path()
// Capa 3. Cualquier fallo del bridge Cold (400/404/500/timeout, ya
// capturado silenciosamente dentro de fetch_media_bytes()) cae aqui.
$placeholderFile = dirname(__DIR__) . '/assets/img/logocabovis_glow.png';
header('Cache-Control: public, max-age=300');
header('Content-Type: image/png');
if (is_file($placeholderFile)) {
    readfile($placeholderFile);
}
exit;
