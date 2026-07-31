<?php

declare(strict_types=1);

// =============================================================================
// api/article_detail.php — Detalle de una noticia publicada por `alias`
// Endpoint: GET /api/article_detail.php?alias=nombre-de-la-nota
// Auth: Público (Mandamiento #14 — solo mutaciones exigen JWT)
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';

const STATUS_PUBLICADO = 1;

asfl_log('REQUEST', ['endpoint' => 'article_detail.php', 'method' => $_SERVER['REQUEST_METHOD']]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

$alias = sanitize_string((string) ($_GET['alias'] ?? ''), 400);

if ($alias === '') {
    send_error('El parámetro alias es requerido.', 422);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare(
        'SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`content`,
                `articles`.`extract`, `articles`.`image`, `articles`.`thumbnail`, `articles`.`has_video`,
                `articles`.`published_at`, `articles`.`hits`,
                `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
         FROM `articles`
         INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
         WHERE `articles`.`alias` = :alias AND `articles`.`status_id` = :status_id
         LIMIT 1'
    );
    $stmt->execute([':alias' => $alias, ':status_id' => STATUS_PUBLICADO]);
    $article = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($article === false) {
        asfl_log('RESPONSE', ['endpoint' => 'article_detail.php', 'status' => 'error', 'reason' => 'no_encontrado']);
        send_error('Noticia no encontrada.', 404);
    }

    $updateHits = $pdo->prepare('UPDATE `articles` SET `hits` = `hits` + 1 WHERE `id` = :id');
    $updateHits->execute([':id' => $article['id']]);

    asfl_log('RESPONSE', ['endpoint' => 'article_detail.php', 'status' => 'success', 'id' => $article['id']]);

    send_success('Noticia obtenida.', ['article' => $article]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [article_detail] ' . $e->getMessage());
    send_error('Error interno al obtener la noticia.', 500);
}
