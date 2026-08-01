<?php

declare(strict_types=1);

// =============================================================================
// api/categories_create.php — Alta de categoría (módulo Categorías-admin,
// portado desde el sistema legacy — routes/web.php: Admin\CategoryController,
// bajo middleware ['admin'] únicamente, mismo alcance aquí).
// Endpoint: POST /api/categories_create.php
// Auth: Bearer JWT + Rol Admin
//
// Protocolo de 6 Capas (Mandamiento #2), mismo patrón que articles_create.php.
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

$name        = sanitize_string((string) ($payload['name'] ?? ''), 255);
$description = sanitize_string((string) ($payload['description'] ?? ''), 2000);
$parentId    = sanitize_int($payload['parent_id'] ?? null, 0);
// Convención real ya establecida en categories_list.php (LOWER(status) =
// 'publicada') — se guarda en minúsculas para no repetir el mismo problema
// de mayúsculas inconsistentes que ya tiene el dato legacy migrado.
$publish     = (bool) ($payload['publish'] ?? false);
$status      = $publish ? 'publicada' : 'despublicada';

if ($name === '') {
    send_error('El nombre es requerido.', 422);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    if ($parentId > 0) {
        $parentCheck = $pdo->prepare('SELECT `id` FROM `categories` WHERE `id` = :id LIMIT 1');
        $parentCheck->execute([':id' => $parentId]);
        if ($parentCheck->fetch(\PDO::FETCH_ASSOC) === false) {
            send_error('La categoría padre indicada no existe.', 422);
        }
    }

    $baseAlias = slugify_text($name);
    if ($baseAlias === '') {
        send_error('No fue posible generar un alias válido a partir del nombre.', 422);
    }

    $alias  = $baseAlias;
    $suffix = 2;
    $aliasCheck = $pdo->prepare('SELECT `id` FROM `categories` WHERE `alias` = :alias LIMIT 1');
    while (true) {
        $aliasCheck->execute([':alias' => $alias]);
        if ($aliasCheck->fetch(\PDO::FETCH_ASSOC) === false) {
            break;
        }
        $alias = "{$baseAlias}-{$suffix}";
        $suffix++;
    }

    $insert = $pdo->prepare(
        'INSERT INTO `categories` (`name`, `alias`, `status`, `description`, `parent_id`, `created_at`, `updated_at`)
         VALUES (:name, :alias, :status, :description, :parent_id, NOW(), NOW())'
    );
    $insert->execute([
        ':name'        => $name,
        ':alias'       => $alias,
        ':status'      => $status,
        ':description' => $description,
        ':parent_id'   => $parentId > 0 ? $parentId : null,
    ]);

    $newId = (int) $pdo->lastInsertId();

    // Invalida el caché de navegación (helpers/object_cache.php, TTL 300s) —
    // sin esto la categoría nueva no aparecería en el menú hasta que expire
    // el caché por su cuenta.
    require_once __DIR__ . '/../helpers/object_cache.php';
    cache_invalidate('cabovision_nav_v1');
    cache_invalidate('cabovision_year_range_v1');

    send_success('Categoría creada.', ['id' => $newId, 'alias' => $alias], 201);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [categories_create] ' . $e->getMessage());
    send_error('Error interno al crear la categoría.', 500);
}
