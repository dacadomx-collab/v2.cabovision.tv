<?php

declare(strict_types=1);

// =============================================================================
// api/auth_middleware.php — Validación de Bearer JWT + RBAC
// Mandamiento #14: Todo endpoint POST/PUT/DELETE requiere autenticación real.
//
// INCLUIR después de cors.php y ANTES de la lógica de negocio.
// Si el token es inválido o ausente → HTTP 401 y termina el script.
//
// Expone: $authPayload (array con sub, email, role, iat, exp)
//
// Uso:
//   require_once __DIR__ . '/cors.php';
//   require_once __DIR__ . '/jwt.php';
//   require_once __DIR__ . '/auth_middleware.php';
//   // A partir de aquí $authPayload está disponible.
//   requireRole(['admin', 'super_admin'], $authPayload);
// =============================================================================

require_once __DIR__ . '/jwt.php';

/**
 * Verifica que el rol del usuario autenticado esté en $allowedRoles.
 * Si no tiene permiso → HTTP 403 y termina el script.
 *
 * Jerarquía de roles sugerida: super_admin > admin > operaciones > ventas > viewer
 *
 * @param string[] $allowedRoles  Roles que pueden acceder al endpoint.
 * @param array<string,mixed> $payload  El $authPayload expuesto por este middleware.
 */
function requireRole(array $allowedRoles, array $payload): void
{
    $role = (string) ($payload['role'] ?? '');
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Acceso denegado. No tienes permisos para esta operación.',
            'data'    => [],
        ]);
        exit();
    }
}

/**
 * Device Binding — exige que el device_id del token coincida con el
 * device_id presentado en el header X-Device-Id del request actual.
 * Llamar después de obtener $authPayload, en endpoints sensibles
 * (ej. cambio de contraseña, datos de pago, cierre de sesión global).
 */
function requireDeviceBinding(array $payload, string $presentedDeviceId): void
{
    if (!jwtVerifyDevice($payload, $presentedDeviceId)) {
        http_response_code(401);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Token no corresponde a este dispositivo. Inicia sesión nuevamente.',
            'data'    => [],
        ]);
        exit();
    }
}

/** @var array<string,mixed> $authPayload */
$authPayload = (static function (): array {
    // ── Leer JWT_SECRET desde .env ────────────────────────────────────────────
    $envPath = dirname(__DIR__) . '/.env';
    $secret  = '';

    if (is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // Strip comillas
            $len = strlen($val);
            if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                $val = substr($val, 1, $len - 2);
            }
            if ($key === 'JWT_SECRET') {
                $secret = $val;
                break;
            }
        }
    }

    if ($secret === '') {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Configuración de seguridad incompleta.', 'data' => []]);
        exit();
    }

    // ── Extraer token del header Authorization: Bearer <token> ───────────────
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader === '' && function_exists('apache_request_headers')) {
        $headers    = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Autenticación requerida. Token no encontrado.', 'data' => []]);
        exit();
    }

    $token   = substr($authHeader, 7);
    // Solo se aceptan access tokens aquí. Un refresh token NUNCA debe usarse
    // como sesión — solo es válido contra /api/auth_refresh.php.
    $payload = jwtDecodeTyped($token, $secret, JWT_TYPE_ACCESS);

    if ($payload === null) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Token inválido, expirado o de tipo incorrecto. Inicia sesión nuevamente.', 'data' => []]);
        exit();
    }

    return $payload;
})();
