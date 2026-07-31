<?php

declare(strict_types=1);

// =============================================================================
// api/users_list.php — Listado de usuarios del panel, exclusivo del Super
// Admin (rol "Admin"). Endpoint: GET /api/users_list.php
// Mismo Protocolo de 6 Capas (GET no muta, pero sigue exigiendo Auth+RBAC —
// esta lista incluye correos reales del equipo editorial, dato sensible).
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
        "SELECT u.id, u.name, u.email, u.status, u.created_at,
                GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ', ') AS roles
         FROM `users` u
         LEFT JOIN `model_has_roles` mhr ON mhr.model_id = u.id AND mhr.model_type = 'App\\\\User'
         LEFT JOIN `roles` r ON r.id = mhr.role_id
         GROUP BY u.id
         ORDER BY u.created_at DESC"
    );
    $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $rolesStmt = $pdo->query('SELECT `id`, `name` FROM `roles` ORDER BY `id` ASC');
    $roles = $rolesStmt->fetchAll(\PDO::FETCH_ASSOC);

    send_success('Listado generado.', ['users' => $users, 'roles' => $roles]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [users_list] ' . $e->getMessage());
    send_error('Error interno al listar usuarios.', 500);
}
