<?php

declare(strict_types=1);

// =============================================================================
// api/candidates_get.php — Detalle de un candidato por id, para precargar
// el formulario de edición.
// Endpoint: GET /api/candidates_get.php?id=123
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

$candidateId = sanitize_int($_GET['id'] ?? null, 0);
if ($candidateId <= 0) {
    send_error('El parámetro id es requerido.', 422);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare('SELECT * FROM `candidates` WHERE `id` = :id LIMIT 1');
    $stmt->execute([':id' => $candidateId]);
    $candidate = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($candidate === false) {
        send_error('Candidato no encontrado.', 404);
    }

    send_success('Candidato obtenido.', ['candidate' => $candidate]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [candidates_get] ' . $e->getMessage());
    send_error('Error interno al obtener el candidato.', 500);
}
