<?php

declare(strict_types=1);

// =============================================================================
// api/articles_get.php — Detalle de una noticia por `id`, para el panel de
// edición (admin/editor.php?id=). A diferencia de api/article_detail.php
// (público, por `alias`, solo publicadas, incrementa `hits`), este endpoint
// es autenticado, busca por `id` y devuelve el artículo en CUALQUIER estado
// (borrador o publicado) — el editor necesita poder editar notas aún no
// publicadas, que article_detail.php nunca expondría.
// Endpoint: GET /api/articles_get.php?id=123
// Auth: Bearer JWT + Rol (Admin | Autor | Editor)
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';

requireRole(['Admin', 'Autor', 'Editor'], $authPayload);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

$articleId = sanitize_int($_GET['id'] ?? null, 0);
if ($articleId <= 0) {
    send_error('El parámetro id es requerido.', 422);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare(
        'SELECT `id`, `title`, `alias`, `content`, `extract`, `thumbnail`, `category_id`, `status_id`
         FROM `articles`
         WHERE `id` = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $articleId]);
    $article = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($article === false) {
        send_error('Noticia no encontrada.', 404);
    }

    send_success('Noticia obtenida.', ['article' => $article]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [articles_get] ' . $e->getMessage());
    send_error('Error interno al obtener la noticia.', 500);
}
