<?php

declare(strict_types=1);

// =============================================================================
// api/categories_update.php — Edición de categoría existente (nombre,
// descripción, categoría padre, estado publicada/despublicada). El alias
// NUNCA se regenera aquí — cambiarlo rompería enlaces/SEO ya indexados de
// una categoría que puede llevar años publicada (mismo criterio que
// articles_update.php: el alias es inmutable tras la creación).
// Endpoint: POST /api/categories_update.php
// Auth: Bearer JWT + Rol Admin
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';

requireRole(['Admin'], $authPayload);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Método no permitido.', 405);
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    send_error('Payload JSON inválido.', 400);
}
if (!is_array($payload)) {
    send_error('Payload JSON inválido.', 400);
}

$categoryId  = sanitize_int($payload['id'] ?? null, 0);
$name        = sanitize_string((string) ($payload['name'] ?? ''), 255);
$description = sanitize_string((string) ($payload['description'] ?? ''), 2000);
$parentId    = sanitize_int($payload['parent_id'] ?? null, 0);
$publish     = (bool) ($payload['publish'] ?? false);
$status      = $publish ? 'publicada' : 'despublicada';

if ($categoryId <= 0) {
    send_error('Falta el identificador de la categoría.', 422);
}
if ($name === '') {
    send_error('El nombre es requerido.', 422);
}
if ($parentId === $categoryId) {
    send_error('Una categoría no puede ser su propia categoría padre.', 422);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $existing = $pdo->prepare('SELECT `id` FROM `categories` WHERE `id` = :id LIMIT 1');
    $existing->execute([':id' => $categoryId]);
    if ($existing->fetch(\PDO::FETCH_ASSOC) === false) {
        send_error('La categoría indicada no existe.', 404);
    }

    if ($parentId > 0) {
        $parentCheck = $pdo->prepare('SELECT `id` FROM `categories` WHERE `id` = :id LIMIT 1');
        $parentCheck->execute([':id' => $parentId]);
        if ($parentCheck->fetch(\PDO::FETCH_ASSOC) === false) {
            send_error('La categoría padre indicada no existe.', 422);
        }
    }

    $update = $pdo->prepare(
        'UPDATE `categories`
         SET `name` = :name, `description` = :description, `parent_id` = :parent_id,
             `status` = :status, `updated_at` = NOW()
         WHERE `id` = :id'
    );
    $update->execute([
        ':name'        => $name,
        ':description' => $description,
        ':parent_id'   => $parentId > 0 ? $parentId : null,
        ':status'      => $status,
        ':id'          => $categoryId,
    ]);

    require_once __DIR__ . '/../helpers/object_cache.php';
    cache_invalidate('cabovision_nav_v1');

    send_success('Categoría actualizada.', ['id' => $categoryId]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [categories_update] ' . $e->getMessage());
    send_error('Error interno al actualizar la categoría.', 500);
}
