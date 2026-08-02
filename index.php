<?php

declare(strict_types=1);

// =============================================================================
// index.php — Portada (listado de últimas noticias publicadas)
// Mismo patrón ya usado en articulo.php: consulta directa por PDO (igual que
// api/articles_list.php), envuelta en header.php/footer.php, renderizando
// cada nota con views/partials/article_card.php — sin duplicar el query vía
// una llamada HTTP a la propia API interna.
// =============================================================================

require_once __DIR__ . '/api/conexion.php';
require_once __DIR__ . '/helpers/input_sanitizer.php';
require_once __DIR__ . '/helpers/security_shield.php';
require_once __DIR__ . '/helpers/base_path.php';
require_once __DIR__ . '/helpers/date_filter.php';
require_once __DIR__ . '/helpers/homepage_sections.php';

if (is_ip_banned()) {
    http_response_code(403);
    exit('Acceso denegado.');
}
waf_block_if_malicious();
rate_limit_enforce('page', 120, 60); // 120 vistas/min por IP — generoso, no bloquea navegación real

const STATUS_PUBLICADO = 1;
const PER_PAGE = 12;

$page   = max(1, sanitize_int($_GET['page'] ?? 1, 1));
$offset = ($page - 1) * PER_PAGE;

$dateFilter      = resolve_date_filter_range();
$dateFilterQuery = date_filter_query_string($dateFilter);
$dateFilterSql   = $dateFilter !== null
    ? 'AND `articles`.`published_at` >= :date_start AND `articles`.`published_at` < :date_end'
    : '';

$database = new Database();
$pdo      = $database->getConnection();

$stmt = $pdo->prepare(
    "SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`extract`,
            `articles`.`thumbnail`, `articles`.`image`, `articles`.`published_at`, `articles`.`featured`,
            `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
     FROM `articles`
     INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
     WHERE `articles`.`status_id` = :status_id {$dateFilterSql}
     ORDER BY `articles`.`published_at` DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':status_id', STATUS_PUBLICADO, PDO::PARAM_INT);
if ($dateFilter !== null) {
    $stmt->bindValue(':date_start', $dateFilter['start']);
    $stmt->bindValue(':date_end', $dateFilter['end']);
}
$stmt->bindValue(':limit', PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($articles as &$articleRow) {
    $articleRow['title'] = repair_known_mojibake($articleRow['title']);
}
unset($articleRow);

// Portada curada por secciones — solo en la vista por defecto (sin paginar,
// sin filtro de fecha activo); en cualquier otra vista de index.php el
// visitante ya pidió algo específico (página 2+, un rango de fechas) y
// mezclarlo con bloques curados de otras categorías sería ruido, no ayuda.
$homepageSections = ($page === 1 && $dateFilter === null) ? fetch_homepage_sections($pdo) : [];

$pageTitle = 'CaboVision.tv — Noticias de Los Cabos y Baja California Sur';

require __DIR__ . '/views/partials/header.php';
?>

<h1 class="arf-portada__title">Últimas Noticias</h1>

<div class="arf-layout">
    <div class="arf-layout__main">
        <div class="arf-grid">
            <?php foreach ($articles as $articleIndex => $article): ?>
                <?php $isPriorityCard = ($page === 1 && $articleIndex === 0); ?>
                <?php include __DIR__ . '/views/partials/article_card.php'; ?>
            <?php endforeach; ?>
        </div>

        <?php if (empty($articles)): ?>
            <p><?= $dateFilter !== null ? 'No hay noticias publicadas en esa fecha.' : 'No hay noticias publicadas todavía.' ?></p>
        <?php endif; ?>

        <nav class="arf-pagination" aria-label="Paginación de noticias">
            <?php if ($page > 1): ?>
                <a href="<?= base_path() ?>/index.php?page=<?= $page - 1 ?><?= $dateFilterQuery ?>">&larr; Anterior</a>
            <?php endif; ?>
            <?php if (count($articles) === PER_PAGE): ?>
                <a href="<?= base_path() ?>/index.php?page=<?= $page + 1 ?><?= $dateFilterQuery ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </nav>

        <?php foreach ($homepageSections as $section): ?>
            <section class="homepage-section">
                <div class="homepage-section__header">
                    <h2 class="homepage-section__title"><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <a class="homepage-section__more" href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($section['category_alias']) ?>">Ver todo &rarr;</a>
                </div>
                <div class="homepage-section__grid">
                    <?php foreach ($section['articles'] as $article): ?>
                        <?php $isPriorityCard = false; ?>
                        <?php include __DIR__ . '/views/partials/article_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <aside class="arf-layout__aside">
        <?php $placement = 'lateral'; require __DIR__ . '/views/partials/sponsor_banner.php'; ?>
    </aside>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
