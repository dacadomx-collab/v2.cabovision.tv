<?php

declare(strict_types=1);

// =============================================================================
// api/jwt.php — Implementación HS256 JWT sin dependencias externas (sin Composer)
// Mandamiento #14: Token de sesión seguro para endpoints protegidos.
//
// Uso:
//   require_once __DIR__ . '/jwt.php';
//   $token   = jwtEncode(['sub' => 1, 'email' => 'user@example.com', 'role' => 'admin'], $secret, 3600);
//   $payload = jwtDecode($token, $secret); // null si inválido o expirado
// =============================================================================

function jwtEncode(array $payload, string $secret, int $ttlSeconds = 3600): string
{
    $now            = time();
    $payload['iat'] = $now;
    $payload['exp'] = $now + $ttlSeconds;

    $header    = jwtBase64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']) ?: '');
    $body      = jwtBase64url(json_encode($payload) ?: '');
    $signature = jwtBase64url(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

    return "{$header}.{$body}.{$signature}";
}

/**
 * Decodifica y valida el token.
 * @return array<string,mixed>|null  null si firma inválida, expirado o formato incorrecto.
 */
function jwtDecode(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $body, $sig] = $parts;

    // Verificación de firma con comparación timing-safe (previene timing attacks)
    $expected = jwtBase64url(hash_hmac('sha256', "{$header}.{$body}", $secret, true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $payload = json_decode(jwtBase64urlDecode($body), true);
    if (!is_array($payload)) {
        return null;
    }

    // Verificar expiración
    if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

function jwtBase64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function jwtBase64urlDecode(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

// =============================================================================
// JWT ENTERPRISE — Access/Refresh con rotación y Device Binding
// Mandamiento #14: Sesión robusta, sin Composer, sin estado en servidor.
//
// Patrón:
//   1. Login emite un par {access, refresh} ligado a un device_id.
//   2. Cada request protegida valida el access token (TTL corto, ej. 15 min).
//   3. Cuando el access expira, el cliente llama /api/auth_refresh.php con el
//      refresh token (TTL largo, ej. 30 días) → se emite un NUEVO par
//      (rotación: el refresh anterior queda obsoleto en el cliente).
//   4. Device Binding: el refresh token solo es válido si el device_id que lo
//      acompaña coincide con el que se emitió originalmente — mitiga robo de
//      refresh tokens exfiltrados a otro dispositivo/navegador.
//
// NOTA: Esta implementación es stateless (sin tabla de revocación). Para
// revocación real (logout forzado, "cerrar sesión en todos los dispositivos")
// se requiere una tabla `refresh_tokens_blacklist` — NO se crea aquí
// (Mandamiento #9: Inmutabilidad del Sistema). Documentar la necesidad en
// knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md antes de implementarla.
// =============================================================================

const JWT_TYPE_ACCESS  = 'access';
const JWT_TYPE_REFRESH = 'refresh';

/**
 * Emite un access token de vida corta. Incluye 'type' => 'access' y 'device_id'.
 *
 * @param array<string,mixed> $claims  sub, email, role, etc. (sin 'type'/'exp'/'iat')
 */
function jwtEncodeAccess(array $claims, string $secret, string $deviceId, int $ttlSeconds = 900): string
{
    $claims['type']      = JWT_TYPE_ACCESS;
    $claims['device_id'] = $deviceId;

    return jwtEncode($claims, $secret, $ttlSeconds);
}

/**
 * Emite un refresh token de vida larga. Incluye 'type' => 'refresh' y 'device_id'.
 */
function jwtEncodeRefresh(array $claims, string $secret, string $deviceId, int $ttlSeconds = 2592000): string
{
    $claims['type']      = JWT_TYPE_REFRESH;
    $claims['device_id'] = $deviceId;

    return jwtEncode($claims, $secret, $ttlSeconds);
}

/**
 * Decodifica y exige que el token sea del tipo esperado ('access' | 'refresh').
 * Retorna null si la firma es inválida, expiró, o el 'type' no coincide.
 *
 * @return array<string,mixed>|null
 */
function jwtDecodeTyped(string $token, string $secret, string $expectedType): ?array
{
    $payload = jwtDecode($token, $secret);
    if ($payload === null) {
        return null;
    }
    if (($payload['type'] ?? '') !== $expectedType) {
        return null;
    }
    return $payload;
}

/**
 * Genera un device_id estable a partir de IP + User-Agent del cliente actual.
 * Usar solo como fallback si el cliente no envía su propio device_id (header
 * X-Device-Id o cuerpo del request) — un identificador generado por el
 * cliente (ej. UUID persistido en localStorage) es más confiable.
 */
function jwtMakeDeviceId(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    return hash('sha256', $ip . '|' . $ua);
}

/**
 * Compara el device_id del payload contra el device_id presentado en el
 * request actual, con comparación timing-safe.
 */
function jwtVerifyDevice(array $payload, string $presentedDeviceId): bool
{
    return hash_equals((string) ($payload['device_id'] ?? ''), $presentedDeviceId);
}
