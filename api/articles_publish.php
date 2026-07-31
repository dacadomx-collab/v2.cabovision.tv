<?php

declare(strict_types=1);

// =============================================================================
// api/articles_publish.php — Cambia un artículo de borrador (status_id=0) a
// publicado (status_id=1). Endpoint: POST /api/articles_publish.php
// Auth: Bearer JWT + Rol (Admin | Editor) — publicar es una decisión editorial
// de mayor peso que crear un borrador, por eso NO se incluye 'Autor' aquí
// (sí puede crear vía articles_create.php, no puede autopublicarse).
//
// Protocolo de 6 Capas (Mandamiento #2), igual patrón que api/auth_login.php:
//   Capa 1: CORS whitelist (cors.php)
//   Capa 2: RBAC — requireRole(['Admin','Editor']), 403 si no califica
//   Capa 3: Restricción explícita de verbo HTTP (POST o PUT únicamente)
//   Capa 4: Sanitización estricta del `id` recibido (sanitize_int, entero positivo)
//   Capa 5: PDO ATTR_EMULATE_PREPARES=false + prepared statement puro (conexion.php)
//   Capa 6: Try/Catch global — error real a error_log, cliente recibe JSON genérico
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php'; // expone $authPayload, 401 si no hay token válido
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/base_path.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';

const STATUS_PUBLICADO = 1;

// ── Capa 2: RBAC ─────────────────────────────────────────────────────────────
requireRole(['Admin', 'Editor'], $authPayload);

asfl_log('REQUEST', ['endpoint' => 'articles_publish.php', 'method' => $_SERVER['REQUEST_METHOD']]);

// ── Capa 3: Restricción explícita de verbo HTTP ─────────────────────────────
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
    send_error('Método no permitido.', 405);
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    send_error('Payload JSON inválido.', 400);
}

// ── Capa 4: Sanitización estricta del ID ────────────────────────────────────
$articleId = sanitize_int($payload['id'] ?? null, 0);
if ($articleId <= 0) {
    send_error('El id del artículo es requerido y debe ser un entero positivo.', 422);
}

try {
    // ── Capa 5: PDO sin emulación de prepares (ya forzado en conexion.php), prepared statement puro ──
    $database = new Database();
    $pdo      = $database->getConnection();

    $check = $pdo->prepare('SELECT `id`, `status_id`, `alias` FROM `articles` WHERE `id` = :id LIMIT 1');
    $check->execute([':id' => $articleId]);
    $article = $check->fetch(\PDO::FETCH_ASSOC);

    if ($article === false) {
        send_error('El artículo indicado no existe.', 404);
    }
    if ((int) $article['status_id'] === STATUS_PUBLICADO) {
        send_error('El artículo ya estaba publicado.', 409);
    }

    $update = $pdo->prepare(
        'UPDATE `articles` SET `status_id` = :status_id, `updated_at` = NOW() WHERE `id` = :id'
    );
    $update->execute([':status_id' => STATUS_PUBLICADO, ':id' => $articleId]);

    asfl_log('RESPONSE', ['endpoint' => 'articles_publish.php', 'status' => 'success', 'id' => $articleId]);

    send_success('Artículo publicado.', [
        'id'    => $articleId,
        'alias' => $article['alias'],
        'url'   => base_path() . '/articulo.php?alias=' . urlencode((string) $article['alias']),
    ]);
} catch (\PDOException $e) {
    // ── Capa 6: Try/Catch global — nunca se expone el error real de PDO al cliente ──
    error_log('[' . date('Y-m-d H:i:s') . '] [articles_publish] ' . $e->getMessage());
    send_error('Error interno al publicar el artículo.', 500);
} catch (\Throwable $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [articles_publish] Fallo de infraestructura: ' . $e->getMessage());
    send_error('Error interno del servidor.', 500);
}
