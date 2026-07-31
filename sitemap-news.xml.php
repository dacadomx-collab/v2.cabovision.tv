<?php

declare(strict_types=1);

// =============================================================================
// sitemap-news.xml.php — Sitemap de Google News (esquema news:, ventana de 48h)
// Enmascarado como /sitemap-news.xml vía .htaccess (misma convención pendiente
// que sitemap.php — .htaccess protegido, no se edita sin autorización
// explícita del Arquitecto, ver cabecera de ese archivo).
//
// Google News exige un feed de SOLO las últimas 48 horas (spec real:
// https://www.google.com/schemas/sitemap-news/0.9) — un artículo fuera de esa
// ventana NO debe aparecer aquí, aunque siga publicado y sí viva en
// sitemap.php (sitemap general, sin ventana de tiempo).
//
// Cache-Control estricto (Directiva del Arquitecto, Go-Live): este feed debe
// reflejar contenido nuevo casi de inmediato — TTL de archivo mucho más corto
// que sitemap.php (300s, no 3600s) + cabeceras HTTP que impiden que un CDN/
// proxy intermedio sirva una copia vieja más allá de eso.
// =============================================================================

require_once __DIR__ . '/api/conexion.php';
require_once __DIR__ . '/helpers/input_sanitizer.php';

const STATUS_PUBLICADO = 1;
const NEWS_SITEMAP_WINDOW_HOURS = 48;
const NEWS_SITEMAP_CACHE_TTL = 300; // 5 minutos — mucho más corto que sitemap.php (contenido de noticias, no el catálogo completo)
const NEWS_SITEMAP_CACHE_PATH = __DIR__ . '/logs/sitemap_news_cache.xml';
const NEWS_PUBLICATION_NAME = 'CaboVision.tv';

header('Content-Type: application/xml; charset=UTF-8');
// Estricto: nunca cacheado por un intermediario más allá del TTL de archivo,
// y ese TTL ya es corto (5 min) — evita que Google News vea contenido viejo
// durante horas, como sí es aceptable en el sitemap general.
header('Cache-Control: public, max-age=' . NEWS_SITEMAP_CACHE_TTL . ', must-revalidate');

if (is_file(NEWS_SITEMAP_CACHE_PATH) && (time() - filemtime(NEWS_SITEMAP_CACHE_PATH)) < NEWS_SITEMAP_CACHE_TTL) {
    readfile(NEWS_SITEMAP_CACHE_PATH);
    exit;
}

ob_start();

$_baseScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_baseHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl     = $_baseScheme . '://' . $_baseHost . '/CaboVision.tv';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
    . 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    // Ventana real de 48h calculada en el servidor de BD (NOW() - INTERVAL),
    // no en PHP — evita desalineación de zona horaria entre el proceso PHP y
    // MySQL. Google News además exige tope de 1000 URLs por sitemap — no se
    // acerca ni de lejos con una ventana de 48h en este volumen editorial,
    // pero el LIMIT queda como blindaje explícito del esquema, no adorno.
    $stmt = $pdo->prepare(
        'SELECT a.`alias`, a.`title`, a.`published_at`, c.`name` AS category_name
         FROM `articles` a
         INNER JOIN `categories` c ON c.`id` = a.`category_id`
         WHERE a.`status_id` = :status_id
           AND a.`published_at` >= (NOW() - INTERVAL ' . NEWS_SITEMAP_WINDOW_HOURS . ' HOUR)
         ORDER BY a.`published_at` DESC
         LIMIT 1000'
    );
    $stmt->execute([':status_id' => STATUS_PUBLICADO]);

    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $title = repair_known_mojibake((string) $row['title']);
        $pubDate = date('c', strtotime((string) $row['published_at']));

        echo '<url>';
        echo '<loc>' . htmlspecialchars($baseUrl . '/articulo.php?alias=' . urlencode((string) $row['alias']), ENT_QUOTES, 'UTF-8') . '</loc>';
        echo '<news:news>';
        echo '<news:publication>';
        echo '<news:name>' . htmlspecialchars(NEWS_PUBLICATION_NAME, ENT_QUOTES, 'UTF-8') . '</news:name>';
        echo '<news:language>es</news:language>';
        echo '</news:publication>';
        echo '<news:publication_date>' . htmlspecialchars($pubDate, ENT_QUOTES, 'UTF-8') . '</news:publication_date>';
        echo '<news:title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</news:title>';
        if (!empty($row['category_name'])) {
            echo '<news:keywords>' . htmlspecialchars(repair_known_mojibake((string) $row['category_name']), ENT_QUOTES, 'UTF-8') . '</news:keywords>';
        }
        echo '</news:news>';
        echo '</url>' . "\n";
    }
} catch (\PDOException $e) {
    // Nunca exponer el error de PDO en un feed público consumido por bots.
    error_log('[' . date('Y-m-d H:i:s') . '] [sitemap-news.xml] ' . $e->getMessage());
}

echo '</urlset>';

$output = ob_get_clean();
@file_put_contents(NEWS_SITEMAP_CACHE_PATH, $output);
echo $output;
