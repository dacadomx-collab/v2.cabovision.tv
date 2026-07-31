<?php

declare(strict_types=1);

// =============================================================================
// categoria.php — Listado de noticias filtrado por categoría (alias)
// Mismo patrón que index.php/articulo.php: PDO directo, header.php/footer.php,
// article_card.php por cada nota. header.php y footer.php ya generan enlaces
// aquí (/CaboVision.tv/categoria.php?alias=...) — esta vista cierra ese 404.
// =============================================================================

require_once __DIR__ . '/api/conexion.php';
require_once __DIR__ . '/helpers/input_sanitizer.php';
require_once __DIR__ . '/helpers/security_shield.php';
require_once __DIR__ . '/helpers/base_path.php';
require_once __DIR__ . '/helpers/date_filter.php';

if (is_ip_banned()) {
    http_response_code(403);
    exit('Acceso denegado.');
}
waf_block_if_malicious();
rate_limit_enforce('page', 120, 60); // 120 vistas/min por IP — generoso, no bloquea navegación real

const STATUS_PUBLICADO = 1;
const PER_PAGE = 12;

$categoryAlias = trim((string) ($_GET['alias'] ?? ''));

if ($categoryAlias === '') {
    http_response_code(404);
    exit('Categoría no encontrada.');
}

$page   = max(1, sanitize_int($_GET['page'] ?? 1, 1));
$offset = ($page - 1) * PER_PAGE;

$database = new Database();
$pdo      = $database->getConnection();

$categoryStmt = $pdo->prepare('SELECT `id`, `name`, `alias` FROM `categories` WHERE `alias` = :alias LIMIT 1');
$categoryStmt->execute([':alias' => $categoryAlias]);
$category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

if ($category === false) {
    http_response_code(404);
    exit('Categoría no encontrada.');
}
$category['name'] = repair_known_mojibake($category['name']);

$dateFilter      = resolve_date_filter_range();
$dateFilterQuery = date_filter_query_string($dateFilter);
$dateFilterSql   = $dateFilter !== null
    ? 'AND `articles`.`published_at` >= :date_start AND `articles`.`published_at` < :date_end'
    : '';

$stmt = $pdo->prepare(
    "SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`extract`,
            `articles`.`thumbnail`, `articles`.`image`, `articles`.`published_at`, `articles`.`featured`,
            `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
     FROM `articles`
     INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
     WHERE `articles`.`status_id` = :status_id AND `categories`.`alias` = :category_alias {$dateFilterSql}
     ORDER BY `articles`.`published_at` DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':status_id', STATUS_PUBLICADO, PDO::PARAM_INT);
$stmt->bindValue(':category_alias', $categoryAlias, PDO::PARAM_STR);
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

$pageTitle = $category['name'] . ' — CaboVision.tv';

require __DIR__ . '/views/partials/header.php';
?>

<h1 class="arf-category__title"><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></h1>

<div class="arf-layout">
    <div class="arf-layout__main">
        <div class="arf-grid">
            <?php foreach ($articles as $articleIndex => $article): ?>
                <?php $isPriorityCard = ($page === 1 && $articleIndex === 0); ?>
                <?php include __DIR__ . '/views/partials/article_card.php'; ?>
            <?php endforeach; ?>
        </div>

        <?php if (empty($articles)): ?>
            <p><?= $dateFilter !== null ? 'No hay noticias publicadas en esta categoría en esa fecha.' : 'No hay noticias publicadas en esta categoría todavía.' ?></p>
        <?php endif; ?>

        <nav class="arf-pagination" aria-label="Paginación de noticias">
            <?php if ($page > 1): ?>
                <a href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($categoryAlias) ?>&page=<?= $page - 1 ?><?= $dateFilterQuery ?>">&larr; Anterior</a>
            <?php endif; ?>
            <?php if (count($articles) === PER_PAGE): ?>
                <a href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($categoryAlias) ?>&page=<?= $page + 1 ?><?= $dateFilterQuery ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </nav>
    </div>

    <aside class="arf-layout__aside">
        <?php $placement = 'lateral'; require __DIR__ . '/views/partials/sponsor_banner.php'; ?>
    </aside>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
