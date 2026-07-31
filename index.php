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

$database = new Database();
$pdo      = $database->getConnection();

$stmt = $pdo->prepare(
    "SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`extract`,
            `articles`.`thumbnail`, `articles`.`image`, `articles`.`published_at`, `articles`.`featured`,
            `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
     FROM `articles`
     INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
     WHERE `articles`.`status_id` = :status_id
     ORDER BY `articles`.`published_at` DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':status_id', STATUS_PUBLICADO, PDO::PARAM_INT);
$stmt->bindValue(':limit', PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($articles as &$articleRow) {
    $articleRow['title'] = repair_known_mojibake($articleRow['title']);
}
unset($articleRow);

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
            <p>No hay noticias publicadas todavía.</p>
        <?php endif; ?>

        <nav class="arf-pagination" aria-label="Paginación de noticias">
            <?php if ($page > 1): ?>
                <a href="<?= base_path() ?>/index.php?page=<?= $page - 1 ?>">&larr; Anterior</a>
            <?php endif; ?>
            <?php if (count($articles) === PER_PAGE): ?>
                <a href="<?= base_path() ?>/index.php?page=<?= $page + 1 ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </nav>
    </div>

    <aside class="arf-layout__aside">
        <?php $placement = 'lateral'; require __DIR__ . '/views/partials/sponsor_banner.php'; ?>
    </aside>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
