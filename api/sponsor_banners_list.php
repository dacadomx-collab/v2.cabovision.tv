<?php

declare(strict_types=1);

// =============================================================================
// api/sponsor_banners_list.php — Listado de campañas del AdServer interno
// Endpoint: GET /api/sponsor_banners_list.php
// Auth: Bearer JWT + requireRole(['Admin', 'Editor']) — gestión comercial
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';

requireRole(['Admin', 'Editor'], $authPayload);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->query(
        'SELECT `id`, `sponsor_name`, `image_path`, `redirect_url`, `placement_type`,
                `purchased_impressions`, `accumulated_clicks`, `status`, `start_date`, `end_date`
         FROM `sponsor_banners`
         ORDER BY `id` DESC'
    );

    send_success('Campañas obtenidas.', ['banners' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [sponsor_banners_list] ' . $e->getMessage());
    send_error('Error interno al obtener las campañas.', 500);
}
