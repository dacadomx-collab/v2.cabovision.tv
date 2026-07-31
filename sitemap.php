<?php

declare(strict_types=1);

// =============================================================================
// sitemap.php — Motor de Rastreabilidad SEO (mapa XML en tiempo real)
// Enmascarado como /sitemap.xml vía .htaccess (regla pendiente, ver
// PROPUESTA_SEO_ANALYTICS_PATROCINADORES.md — .htaccess es archivo protegido,
// Mandamiento de Blindaje: no se edita sin autorización explícita del Arquitecto).
//
// Consulta transaccional de solo lectura sobre `articles` (status_id = 1,
// único estado publicado). Cursor no bufferizado (fetch en loop) para no
// materializar en memoria las ~10,713 filas históricas de una sola vez.
// =============================================================================

require_once __DIR__ . '/api/conexion.php';

const STATUS_PUBLICADO = 1;
const SITEMAP_CACHE_TTL = 3600; // 1 hora — evita recorrer 10,562 filas en cada visita de un crawler
const SITEMAP_CACHE_PATH = __DIR__ . '/logs/sitemap_cache.xml';

header('Content-Type: application/xml; charset=UTF-8');

// Caché de rendimiento: logs/ ya está bloqueado a HTTP público en .htaccess,
// así que el archivo de caché nunca es accesible directo, solo vía este script.
if (is_file(SITEMAP_CACHE_PATH) && (time() - filemtime(SITEMAP_CACHE_PATH)) < SITEMAP_CACHE_TTL) {
    readfile(SITEMAP_CACHE_PATH);
    exit;
}

ob_start();

$_baseScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_baseHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl     = $_baseScheme . '://' . $_baseHost . '/CaboVision.tv';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

echo '<url><loc>' . htmlspecialchars($baseUrl . '/index.php', ENT_QUOTES, 'UTF-8')
    . '</loc><changefreq>hourly</changefreq><priority>1.0</priority></url>' . "\n";

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare(
        'SELECT `alias`, `updated_at`, `published_at`
         FROM `articles`
         WHERE `status_id` = :status_id
         ORDER BY `published_at` DESC'
    );
    $stmt->execute([':status_id' => STATUS_PUBLICADO]);

    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $lastmodSource = $row['updated_at'] ?? $row['published_at'];
        $lastmod       = $lastmodSource !== null ? date('c', strtotime((string) $lastmodSource)) : null;

        echo '<url>';
        echo '<loc>' . htmlspecialchars($baseUrl . '/articulo.php?alias=' . urlencode((string) $row['alias']), ENT_QUOTES, 'UTF-8') . '</loc>';
        if ($lastmod !== null) {
            echo '<lastmod>' . htmlspecialchars($lastmod, ENT_QUOTES, 'UTF-8') . '</lastmod>';
        }
        echo '<changefreq>daily</changefreq><priority>0.7</priority>';
        echo '</url>' . "\n";
    }
} catch (\PDOException $e) {
    // Nunca exponer el error de PDO en un feed público consumido por bots.
    error_log('[' . date('Y-m-d H:i:s') . '] [sitemap.php] ' . $e->getMessage());
}

echo '</urlset>';

$output = ob_get_clean();
@file_put_contents(SITEMAP_CACHE_PATH, $output);
echo $output;
