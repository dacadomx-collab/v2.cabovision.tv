<?php

declare(strict_types=1);

// =============================================================================
// rss.php — Feed RSS 2.0 de las últimas noticias publicadas. Cierra el gap
// del legacy Route::feeds() (paquete spatie/laravel-feed, apuntaba a
// /feed). No requiere tabla ni columna nueva (Mandamiento #9) — misma fuente
// que articles_list.php/sitemap.php.
//
// Cache en disco con el mismo criterio que sitemap.php (logs/ ya bloqueado a
// HTTP público en .htaccess) — un feed RSS es releído con frecuencia por
// lectores/agregadores, no vale la pena recorrer la tabla en cada hit.
// =============================================================================

require_once __DIR__ . '/api/conexion.php';
require_once __DIR__ . '/helpers/base_path.php';

const STATUS_PUBLICADO = 1;
const RSS_LIMIT = 30;
const RSS_CACHE_TTL = 900; // 15 min — más fresco que el sitemap, es contenido editorial reciente
const RSS_CACHE_PATH = __DIR__ . '/logs/rss_cache.xml';

header('Content-Type: application/rss+xml; charset=UTF-8');

if (is_file(RSS_CACHE_PATH) && (time() - filemtime(RSS_CACHE_PATH)) < RSS_CACHE_TTL) {
    readfile(RSS_CACHE_PATH);
    exit;
}

ob_start();

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host . base_path();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
echo '<channel>' . "\n";
echo '<title>CaboVision.tv</title>' . "\n";
echo '<link>' . htmlspecialchars($baseUrl . '/', ENT_QUOTES, 'UTF-8') . '</link>' . "\n";
echo '<atom:link href="' . htmlspecialchars($baseUrl . '/rss.php', ENT_QUOTES, 'UTF-8') . '" rel="self" type="application/rss+xml" />' . "\n";
echo '<description>Últimas noticias de Los Cabos, Baja California Sur</description>' . "\n";
echo '<language>es-MX</language>' . "\n";

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare(
        'SELECT `title`, `alias`, `extract`, `published_at`
         FROM `articles`
         WHERE `status_id` = :status_id
         ORDER BY `published_at` DESC
         LIMIT ' . RSS_LIMIT
    );
    $stmt->execute([':status_id' => STATUS_PUBLICADO]);

    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $link   = $baseUrl . '/articulo.php?alias=' . urlencode((string) $row['alias']);
        $pubRaw = $row['published_at'] !== null ? strtotime((string) $row['published_at']) : false;

        echo '<item>' . "\n";
        echo '<title>' . htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') . '</title>' . "\n";
        echo '<link>' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</guid>' . "\n";
        if (!empty($row['extract'])) {
            echo '<description>' . htmlspecialchars((string) $row['extract'], ENT_QUOTES, 'UTF-8') . '</description>' . "\n";
        }
        if ($pubRaw !== false) {
            echo '<pubDate>' . date(DATE_RSS, $pubRaw) . '</pubDate>' . "\n";
        }
        echo '</item>' . "\n";
    }
} catch (\PDOException $e) {
    // Nunca exponer el error de PDO en un feed público consumido por lectores/bots.
    error_log('[' . date('Y-m-d H:i:s') . '] [rss.php] ' . $e->getMessage());
}

echo '</channel>' . "\n";
echo '</rss>';

$output = ob_get_clean();
@file_put_contents(RSS_CACHE_PATH, $output);
echo $output;
