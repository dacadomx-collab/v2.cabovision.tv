<?php

declare(strict_types=1);

// =============================================================================
// api/candidates_list.php — Listado de candidatos para el panel admin.
// Endpoint: GET /api/candidates_list.php?search=texto (opcional)
// Auth: Bearer JWT + Rol Admin (el legacy exigía 'admin' middleware solo
// para el índice/listado — create/edit eran de cualquier usuario autenticado,
// ver candidates_create.php/candidates_update.php).
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';

requireRole(['Admin'], $authPayload);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

$search = sanitize_string((string) ($_GET['search'] ?? ''), 255);

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $where  = '';
    $params = [];
    if ($search !== '') {
        $where = 'WHERE `name` LIKE :search OR `parties` LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare(
        "SELECT `id`, `name`, `age`, `gender`, `parties`, `position`, `district`, `photo`, `email`
         FROM `candidates` {$where}
         ORDER BY `name` ASC
         LIMIT 200"
    );
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    send_success('Candidatos obtenidos.', ['candidates' => $candidates]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [candidates_list] ' . $e->getMessage());
    send_error('Error interno al obtener los candidatos.', 500);
}
