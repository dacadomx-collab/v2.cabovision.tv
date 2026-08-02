<?php

declare(strict_types=1);

// =============================================================================
// api/articles_list.php — Listado paginado de noticias publicadas
// Endpoint: GET /api/articles_list.php?page=1&per_page=12&category=politica&search=texto
// Auth: Público (Mandamiento #14 — solo mutaciones exigen JWT)
//
// Schema real (knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md):
//   articles (id, title, alias, content, published_at, featured, hits, extract,
//             image, has_video, thumbnail, user_id, category_id, status_id, ...)
//   statuses.id = 1 → name = 'publicado' (único estado visible al público)
//   categories (id, name, alias, status, ...)
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';

const STATUS_PUBLICADO = 1;

asfl_log('REQUEST', ['endpoint' => 'articles_list.php', 'method' => $_SERVER['REQUEST_METHOD']]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

$page          = max(1, sanitize_int($_GET['page'] ?? 1, 1));
$perPage       = min(30, max(1, sanitize_int($_GET['per_page'] ?? 12, 12)));
$offset        = ($page - 1) * $perPage;
$categoryAlias = sanitize_string((string) ($_GET['category'] ?? ''), 255);
$search        = sanitize_string((string) ($_GET['search'] ?? ''), 255);

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $params = [':status_id' => STATUS_PUBLICADO];
    $categoryFilter = '';
    $searchFilter   = '';

    if ($categoryAlias !== '') {
        $categoryFilter = 'AND `categories`.`alias` = :category_alias';
        $params[':category_alias'] = $categoryAlias;
    }
    if ($search !== '') {
        $searchFilter = 'AND `articles`.`title` LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare(
        "SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`extract`,
                `articles`.`thumbnail`, `articles`.`image`, `articles`.`published_at`,
                `articles`.`featured`, `articles`.`hits`,
                `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
         FROM `articles`
         INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
         WHERE `articles`.`status_id` = :status_id {$categoryFilter} {$searchFilter}
         ORDER BY `articles`.`published_at` DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $articles = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) AS `total`
         FROM `articles`
         INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
         WHERE `articles`.`status_id` = :status_id {$categoryFilter} {$searchFilter}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch(\PDO::FETCH_ASSOC)['total'];

    asfl_log('RESPONSE', ['endpoint' => 'articles_list.php', 'status' => 'success', 'count' => count($articles)]);

    send_success('Listado de noticias obtenido.', [
        'articles'   => $articles,
        'page'       => $page,
        'per_page'   => $perPage,
        'total'      => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [articles_list] ' . $e->getMessage());
    send_error('Error interno al obtener las noticias.', 500);
}
