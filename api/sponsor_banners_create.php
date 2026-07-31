<?php

declare(strict_types=1);

// =============================================================================
// api/sponsor_banners_create.php — Alta de campaña comercial (AdServer interno)
// Endpoint: POST /api/sponsor_banners_create.php
// Auth: Bearer JWT + requireRole(['Admin']) — decisión comercial, solo Admin
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

const VALID_PLACEMENTS = ['superior', 'lateral', 'intercalado'];

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    send_error('Payload JSON inválido.', 400);
}

$sponsorName   = sanitize_string((string) ($payload['sponsor_name'] ?? ''), 150);
$imagePath     = sanitize_string((string) ($payload['image_path'] ?? ''), 255);
$redirectUrl   = (string) ($payload['redirect_url'] ?? '');
$placementType = (string) ($payload['placement_type'] ?? '');
$impressions   = sanitize_int($payload['purchased_impressions'] ?? 0, 0);
$startDate     = (string) ($payload['start_date'] ?? '');
$endDate       = (string) ($payload['end_date'] ?? '');

if ($sponsorName === '' || $imagePath === '') {
    send_error('sponsor_name e image_path son requeridos.', 422);
}
if (filter_var($redirectUrl, FILTER_VALIDATE_URL) === false
    || (!str_starts_with($redirectUrl, 'http://') && !str_starts_with($redirectUrl, 'https://'))) {
    send_error('redirect_url debe ser una URL http(s) válida.', 422);
}
if (!in_array($placementType, VALID_PLACEMENTS, true)) {
    send_error('placement_type debe ser superior, lateral o intercalado.', 422);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    send_error('start_date y end_date son requeridos en formato YYYY-MM-DD.', 422);
}
if ($startDate > $endDate) {
    send_error('start_date no puede ser posterior a end_date.', 422);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare(
        'INSERT INTO `sponsor_banners`
            (`sponsor_name`, `image_path`, `redirect_url`, `placement_type`, `purchased_impressions`,
             `accumulated_clicks`, `status`, `start_date`, `end_date`, `created_at`, `updated_at`)
         VALUES
            (:sponsor_name, :image_path, :redirect_url, :placement_type, :impressions,
             0, \'activo\', :start_date, :end_date, NOW(), NOW())'
    );
    $stmt->execute([
        ':sponsor_name' => $sponsorName,
        ':image_path'   => $imagePath,
        ':redirect_url' => $redirectUrl,
        ':placement_type' => $placementType,
        ':impressions'  => $impressions,
        ':start_date'   => $startDate,
        ':end_date'     => $endDate,
    ]);

    send_success('Campaña creada.', ['id' => (int) $pdo->lastInsertId()], 201);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [sponsor_banners_create] ' . $e->getMessage());
    send_error('Error interno al crear la campaña.', 500);
}
