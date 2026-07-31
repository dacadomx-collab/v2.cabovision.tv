<?php

declare(strict_types=1);

// =============================================================================
// articulo.php — Página de detalle de artículo (front controller ausente
// hasta hoy: article_card.php ya enlazaba aquí, api/article_detail.php ya
// tenía el contrato de datos, pero no existía ninguna vista que los uniera —
// causa raíz real del 404 en articulo.php?alias=..., no un problema de
// mod_rewrite. Mismo patrón de consulta directa que ya usa header.php para
// el menú (Mandamiento #6: no se introduce una capa nueva de fetch a la
// propia API interna cuando el resto del sitio ya resuelve por PDO directo
// en la vista).
// =============================================================================

require_once __DIR__ . '/api/conexion.php';
require_once __DIR__ . '/helpers/media.php';
require_once __DIR__ . '/helpers/input_sanitizer.php';
require_once __DIR__ . '/helpers/security_shield.php';

if (is_ip_banned()) {
    http_response_code(403);
    exit('Acceso denegado.');
}

const STATUS_PUBLICADO = 1;

$alias = trim((string) ($_GET['alias'] ?? ''));

if ($alias === '') {
    http_response_code(404);
    exit('Noticia no encontrada.');
}

$database = new Database();
$pdo      = $database->getConnection();

$stmt = $pdo->prepare(
    'SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`content`,
            `articles`.`extract`, `articles`.`image`, `articles`.`thumbnail`, `articles`.`published_at`,
            `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
     FROM `articles`
     INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
     WHERE `articles`.`alias` = :alias AND `articles`.`status_id` = :status_id
     LIMIT 1'
);
$stmt->execute([':alias' => $alias, ':status_id' => STATUS_PUBLICADO]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if ($article === false) {
    http_response_code(404);
    exit('Noticia no encontrada.');
}

$updateHits = $pdo->prepare('UPDATE `articles` SET `hits` = `hits` + 1 WHERE `id` = :id');
$updateHits->execute([':id' => $article['id']]);

// "Noticias Relacionadas" — motor de retención (Growth Marketing doc, mismo
// criterio simple y real: misma categoría, publicadas, excluye la actual).
// No es un motor de similitud semántica/embeddings — no existe esa
// infraestructura en este proyecto (Mandamiento #4, no inventar lo que no hay).
$relatedStmt = $pdo->prepare(
    'SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`extract`,
            `articles`.`thumbnail`, `articles`.`image`, `articles`.`published_at`, `articles`.`featured`,
            `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
     FROM `articles`
     INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
     WHERE `articles`.`category_id` = (SELECT `category_id` FROM `articles` WHERE `id` = :current_id)
       AND `articles`.`id` != :current_id2
       AND `articles`.`status_id` = :status_id
     ORDER BY `articles`.`published_at` DESC
     LIMIT 4'
);
$relatedStmt->execute([':current_id' => $article['id'], ':current_id2' => $article['id'], ':status_id' => STATUS_PUBLICADO]);
$relatedArticles = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($relatedArticles as &$relatedRow) {
    $relatedRow['title'] = repair_known_mojibake($relatedRow['title']);
}
unset($relatedRow);

$article['title']   = repair_known_mojibake($article['title']);
$article['content'] = repair_known_mojibake((string) $article['content']);

$mediaPlaceholder = media_placeholder_path();
$heroRawPath = resolve_media_path($article['thumbnail'] ?? null) !== $mediaPlaceholder
    ? $article['thumbnail']
    : $article['image'];
$heroImage = resolve_media_path($article['thumbnail'] ?? null) !== $mediaPlaceholder
    ? resolve_media_path($article['thumbnail'] ?? null)
    : resolve_media_path($article['image'] ?? null);
$isFallback = $heroImage === $mediaPlaceholder;

// Variables SEO que header.php lee si están definidas (ver comentario en el
// propio partial) — deben declararse ANTES de requerir header.php.
$baseScheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl         = $baseScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$canonicalUrl    = $baseUrl . '/CaboVision.tv/articulo.php?alias=' . urlencode($alias);

$pageTitle       = $article['title'] . ' — CaboVision.tv';
$pageDescription = $article['extract'] !== null ? mb_substr(strip_tags((string) $article['extract']), 0, 160) : null;
// Pipeline Open Graph 1200x630 (api/og_image.php, 2026-07-24): og:image/
// twitter:image usan el recorte "cover" exacto que exigen esas redes — la
// <img> visible en pantalla ($heroImage, sin tocar) sigue mostrando la
// imagen a su proporción real, nunca recortada.
$pageImage       = !$isFallback ? $baseUrl . '/CaboVision.tv/api/og_image.php?path=' . rawurlencode((string) $heroRawPath) : null;
$pageUrl         = $canonicalUrl;

// JSON-LD NewsArticle + BreadcrumbList en un solo @graph (Growth Marketing
// doc, Módulo 2 — "Zero-Render-Delay": compilado 100% server-side, sin
// microservicios de cliente que lo inyecten después de la carga inicial).
// Solo campos reales: no hay columna de autor público en el contrato de
// article_detail.php/esta query — se declara Organization como autor+editor
// en vez de inventar un "Person" que no existe en los datos.
$publishedAtIso = (new DateTimeImmutable((string) $article['published_at']))->format(DateTimeImmutable::ATOM);
$jsonLdGraph = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'          => 'NewsArticle',
            '@id'            => $canonicalUrl . '#article',
            'headline'       => mb_substr($article['title'], 0, 110),
            'datePublished'  => $publishedAtIso,
            'dateModified'   => $publishedAtIso,
            'author'         => ['@type' => 'Organization', 'name' => 'CaboVision.tv'],
            'publisher'      => [
                '@type' => 'Organization',
                'name'  => 'CaboVision.tv',
                'logo'  => ['@type' => 'ImageObject', 'url' => $baseUrl . '/CaboVision.tv/assets/img/logocabovis_glow.png'],
            ],
            'mainEntityOfPage' => $canonicalUrl,
            ...($pageImage !== null ? ['image' => [$pageImage]] : []),
            ...($pageDescription !== null ? ['description' => $pageDescription] : []),
        ],
        [
            '@type'          => 'BreadcrumbList',
            '@id'            => $canonicalUrl . '#breadcrumb',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => $baseUrl . '/CaboVision.tv/index.php'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => (string) $article['category_name'], 'item' => $baseUrl . '/CaboVision.tv/categoria.php?alias=' . urlencode((string) $article['category_alias'])],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $article['title']],
            ],
        ],
    ],
];
$pageJsonLd = json_encode($jsonLdGraph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

require __DIR__ . '/views/partials/header.php';
?>

<article class="arf-article">
    <p class="arf-article__category">
        <a href="/CaboVision.tv/categoria.php?alias=<?= urlencode((string) $article['category_alias']) ?>">
            <?= htmlspecialchars((string) $article['category_name'], ENT_QUOTES, 'UTF-8') ?>
        </a>
    </p>
    <h1 class="arf-article__title"><?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="arf-article__meta">
        <?= htmlspecialchars((new DateTimeImmutable((string) $article['published_at']))->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?>
    </p>

    <?php if (!$isFallback): ?>
        <div class="arf-article__media">
            <img src="<?= htmlspecialchars($heroImage, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?>"
                 loading="eager" fetchpriority="high">
        </div>
    <?php endif; ?>

    <div class="arf-article__body">
        <?= $article['content'] ?>
    </div>

    <div class="arf-article__sponsor">
        <?php $placement = 'lateral'; require __DIR__ . '/views/partials/sponsor_banner.php'; ?>
    </div>
</article>

<?php if (!empty($relatedArticles)): ?>
<section class="arf-related">
    <h2 class="arf-related__title">Noticias Relacionadas</h2>
    <div class="arf-grid">
        <?php /* Reutiliza $article a propósito — article_card.php exige ese nombre
                 exacto, y esta es la última vez que se usa en este archivo
                 (footer.php, incluido después, no la referencia). */ ?>
        <?php foreach ($relatedArticles as $article): ?>
            <?php $isPriorityCard = false; ?>
            <?php include __DIR__ . '/views/partials/article_card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
