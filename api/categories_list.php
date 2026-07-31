<?php

declare(strict_types=1);

// =============================================================================
// api/categories_list.php — Categorías publicadas para navegación/filtro
// Endpoint: GET /api/categories_list.php
// Auth: Público (Mandamiento #14 — solo mutaciones exigen JWT)
//
// Schema real: categories.status es VARCHAR libre con valores inconsistentes
// en mayúsculas ('Publicada', 'publicada') — se filtra con LOWER() por eso.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';

asfl_log('REQUEST', ['endpoint' => 'categories_list.php', 'method' => $_SERVER['REQUEST_METHOD']]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    // parent_id se incluye para que el frontend agrupe categorías hijas dentro
    // de un <li class="dropdown"> del padre en vez de saturar la barra
    // principal con las 37 categorías publicadas en una sola fila horizontal.
    $stmt = $pdo->query(
        "SELECT `id`, `name`, `alias`, `parent_id`
         FROM `categories`
         WHERE LOWER(`status`) = 'publicada'
         ORDER BY `name` ASC"
    );
    $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    asfl_log('RESPONSE', ['endpoint' => 'categories_list.php', 'status' => 'success', 'count' => count($categories)]);

    send_success('Categorías obtenidas.', ['categories' => $categories]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [categories_list] ' . $e->getMessage());
    send_error('Error interno al obtener las categorías.', 500);
}
