<?php

declare(strict_types=1);

// =============================================================================
// api/categories_admin_list.php — Listado de TODAS las categorías (cualquier
// estado), para la tabla de admin/categorias.php. Distinto de
// api/categories_list.php (público, solo publicadas, para el menú de
// navegación) — el admin necesita ver también las despublicadas para poder
// reactivarlas.
// Endpoint: GET /api/categories_admin_list.php
// Auth: Bearer JWT + Rol Admin
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';

requireRole(['Admin'], $authPayload);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->query(
        "SELECT c.`id`, c.`name`, c.`alias`, c.`status`, c.`parent_id`, p.`name` AS `parent_name`
         FROM `categories` c
         LEFT JOIN `categories` p ON p.`id` = c.`parent_id`
         ORDER BY c.`name` ASC"
    );
    $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    send_success('Categorías obtenidas.', ['categories' => $categories]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [categories_admin_list] ' . $e->getMessage());
    send_error('Error interno al obtener las categorías.', 500);
}
