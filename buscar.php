<?php

declare(strict_types=1);

// =============================================================================
// buscar.php — Resultados de búsqueda pública por título, consumiendo la
// misma lógica que api/articles_list.php?search= (2026-08) directamente por
// PDO (mismo criterio que index.php/categoria.php: la vista no llama a su
// propia API interna vía HTTP). Cierra el gap real: el motor de búsqueda ya
// existía, pero no había ningún formulario visible para usarlo.
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
rate_limit_enforce('page', 120, 60);

const STATUS_PUBLICADO = 1;
const PER_PAGE = 12;

$search = sanitize_string((string) ($_GET['q'] ?? ''), 255);
$page   = max(1, sanitize_int($_GET['page'] ?? 1, 1));
$offset = ($page - 1) * PER_PAGE;

$articles = [];
$total    = 0;

if ($search !== '') {
    $database = new Database();
    $pdo      = $database->getConnection();

    $params = [':status_id' => STATUS_PUBLICADO, ':search' => '%' . $search . '%'];

    $stmt = $pdo->prepare(
        "SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`extract`,
                `articles`.`thumbnail`, `articles`.`image`, `articles`.`published_at`, `articles`.`featured`,
                `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
         FROM `articles`
         INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
         WHERE `articles`.`status_id` = :status_id AND `articles`.`title` LIKE :search
         ORDER BY `articles`.`published_at` DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':status_id', STATUS_PUBLICADO, PDO::PARAM_INT);
    $stmt->bindValue(':search', $params[':search'], PDO::PARAM_STR);
    $stmt->bindValue(':limit', PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($articles as &$articleRow) {
        $articleRow['title'] = repair_known_mojibake($articleRow['title']);
    }
    unset($articleRow);

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) AS `total` FROM `articles`
         WHERE `status_id` = :status_id AND `title` LIKE :search'
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
}

$pageTitle = ($search !== '' ? 'Resultados para "' . $search . '"' : 'Buscar') . ' — CaboVision.tv';

require __DIR__ . '/views/partials/header.php';
?>

<h1 class="arf-portada__title">Buscar</h1>

<form class="arf-search-form" action="<?= base_path() ?>/buscar.php" method="get">
    <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Buscar noticias…" aria-label="Buscar noticias" required minlength="2">
    <button type="submit">Buscar</button>
</form>

<div class="arf-layout">
    <div class="arf-layout__main">
        <?php if ($search === ''): ?>
            <p>Escribe un término para buscar entre las noticias publicadas.</p>
        <?php else: ?>
            <p class="arf-search-results-count"><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?> para "<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"</p>

            <div class="arf-grid">
                <?php foreach ($articles as $articleIndex => $article): ?>
                    <?php $isPriorityCard = false; ?>
                    <?php include __DIR__ . '/views/partials/article_card.php'; ?>
                <?php endforeach; ?>
            </div>

            <?php if (empty($articles)): ?>
                <p>No encontramos noticias que coincidan con tu búsqueda.</p>
            <?php endif; ?>

            <nav class="arf-pagination" aria-label="Paginación de resultados">
                <?php if ($page > 1): ?>
                    <a href="<?= base_path() ?>/buscar.php?q=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">&larr; Anterior</a>
                <?php endif; ?>
                <?php if (count($articles) === PER_PAGE): ?>
                    <a href="<?= base_path() ?>/buscar.php?q=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">Siguiente &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>

    <aside class="arf-layout__aside">
        <?php $placement = 'lateral'; require __DIR__ . '/views/partials/sponsor_banner.php'; ?>
    </aside>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
