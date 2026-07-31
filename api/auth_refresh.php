<?php

declare(strict_types=1);

// =============================================================================
// api/auth_refresh.php — Rotación de Tokens (Refresh → nuevo Access + Refresh)
// Endpoint: POST /api/auth_refresh.php
// Mandamiento #14: requiere refresh_token + device_id coincidente (Device Binding)
//
// NOTA: Rotación stateless. El refresh token anterior NO queda invalidado en
// servidor (no hay tabla de revocación — Mandamiento #9). El cliente debe
// descartar el refresh anterior al recibir el nuevo par.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';

asfl_log('REQUEST', ['endpoint' => 'auth_refresh.php', 'method' => $_SERVER['REQUEST_METHOD']]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Método no permitido.', 405);
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    send_error('Payload JSON inválido.', 400);
}

$refreshToken = (string) ($payload['refresh_token'] ?? '');
$deviceId     = sanitize_string((string) ($payload['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? '')));

if ($refreshToken === '' || $deviceId === '') {
    send_error('refresh_token y device_id son requeridos.', 422);
}

$env    = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
$secret = (string) ($env['JWT_SECRET'] ?? '');

if ($secret === '') {
    send_error('Configuración de seguridad incompleta.', 500);
}

$decoded = jwtDecodeTyped($refreshToken, $secret, JWT_TYPE_REFRESH);

if ($decoded === null) {
    asfl_log('RESPONSE', ['endpoint' => 'auth_refresh.php', 'status' => 'error', 'reason' => 'token_invalido_o_expirado']);
    send_error('Refresh token inválido, expirado o de tipo incorrecto.', 401);
}

if (!jwtVerifyDevice($decoded, $deviceId)) {
    asfl_log('RESPONSE', ['endpoint' => 'auth_refresh.php', 'status' => 'error', 'reason' => 'device_mismatch']);
    send_error('El dispositivo no coincide con el token. Inicia sesión nuevamente.', 401);
}

$accessTtl  = (int) ($env['JWT_ACCESS_TTL'] ?? 900);
$refreshTtl = (int) ($env['JWT_REFRESH_TTL'] ?? 2592000);

$claims = [
    'sub'   => $decoded['sub']   ?? null,
    'email' => $decoded['email'] ?? null,
    'role'  => $decoded['role']  ?? null,
];

$newAccessToken  = jwtEncodeAccess($claims, $secret, $deviceId, $accessTtl);
$newRefreshToken = jwtEncodeRefresh($claims, $secret, $deviceId, $refreshTtl);

asfl_log('RESPONSE', ['endpoint' => 'auth_refresh.php', 'status' => 'success', 'sub' => $claims['sub']]);

send_success('Tokens rotados correctamente.', [
    'access_token'  => $newAccessToken,
    'refresh_token' => $newRefreshToken,
    'device_id'     => $deviceId,
    'expires_in'    => $accessTtl,
]);
