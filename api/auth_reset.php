<?php

declare(strict_types=1);

// =============================================================================
// api/auth_reset.php — Confirmación de recuperación de contraseña (Paso 2 de 2)
// Endpoint: POST /api/auth_reset.php
// Auth: Público (el token en el payload ES la credencial de un solo uso —
// no hay sesión Bearer previa, es exactamente el mismo patrón que
// api/auth_login.php: login.php tampoco exige sesión porque ES el punto de
// entrada)
//
// Protocolo de 6 Capas:
//   Capa 1: CORS whitelist (cors.php)
//   Capa 2: N/A — el propio token de un solo uso ES el mecanismo de
//           autorización de esta operación (equivalente funcional a RBAC
//           aquí: sin token válido, cero acceso a nada)
//   Capa 3: Restricción explícita de verbo HTTP (POST únicamente)
//   Capa 4: Content-Type application/json + sanitización + política de contraseña
//   Capa 5: PDO ATTR_EMULATE_PREPARES=false + prepared statements puros
//   Capa 6: Try/Catch global — error real a error_log, cliente recibe JSON genérico
//
// Un solo uso SIN tabla nueva: el token trae `pwd_fp` (huella del
// password_hash vigente al momento de emitirlo, ver api/auth_recover.php). Se
// exige que esa huella siga coincidiendo con el password_hash ACTUAL del
// usuario — en cuanto esta ruta cambia la contraseña, cualquier copia
// filtrada del mismo token (reenviada, cacheada en el historial del
// navegador, etc.) deja de servir de inmediato, sin necesitar una columna
// `usado` ni una tabla de tokens aparte (Mandamiento #9).
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/security_shield.php';

const RESET_MIN_PASSWORD_LENGTH = 8;
const INVALID_TOKEN_MESSAGE = 'Este enlace ya no es válido. Solicita uno nuevo.';

rate_limit_enforce('auth_reset', 10, 60);

// ── Capa 3: Restricción explícita de verbo HTTP ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Método no permitido.', 405);
}

// ── Capa 4a: Content-Type estricto ──────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
    send_error('Content-Type debe ser application/json.', 415);
}

// ── Capa 4b: Payload JSON + sanitización ────────────────────────────────────
try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    send_error('Payload JSON inválido.', 400);
}
if (!is_array($payload)) {
    send_error('Payload JSON inválido.', 400);
}

$token       = (string) ($payload['token'] ?? '');
$newPassword = (string) ($payload['password'] ?? '');

if ($token === '') {
    send_error(INVALID_TOKEN_MESSAGE, 422);
}
// Política mínima real (MODULO_01 §4.6 calibra un medidor 0-100%, pero el
// backend SIEMPRE exige un mínimo duro — el medidor del frontend es
// orientativo, nunca la única barrera).
if (mb_strlen($newPassword) < RESET_MIN_PASSWORD_LENGTH) {
    send_error('La contraseña debe tener al menos ' . RESET_MIN_PASSWORD_LENGTH . ' caracteres.', 422);
}

try {
    $env    = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
    $secret = (string) ($env['JWT_SECRET'] ?? '');
    if ($secret === '') {
        send_error('Configuración de seguridad incompleta.', 500);
    }

    $decoded = jwtDecode($token, $secret);
    if ($decoded === null || ($decoded['type'] ?? '') !== 'password_reset') {
        // Firma inválida, expirado, o no es un token de este tipo — mismo
        // mensaje genérico sin distinguir el motivo (no darle a un atacante
        // pistas de si el token "casi" era válido).
        send_error(INVALID_TOKEN_MESSAGE, 401);
    }

    $userId = (int) ($decoded['sub'] ?? 0);
    if ($userId <= 0) {
        send_error(INVALID_TOKEN_MESSAGE, 401);
    }

    // ── Capa 5: PDO sin emulación de prepares (ya forzado en conexion.php) ──
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare('SELECT `id`, `password`, `status` FROM `users` WHERE `id` = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($user === false || $user['status'] !== 'activo') {
        send_error(INVALID_TOKEN_MESSAGE, 401);
    }

    // Un solo uso real: la huella del token debe coincidir con el password_hash
    // VIGENTE — si ya se usó una vez (o el password cambió por otra vía),
    // la huella ya no calza y el token queda inerte.
    $currentFingerprint = substr(hash('sha256', (string) $user['password']), 0, 16);
    if (!hash_equals($currentFingerprint, (string) ($decoded['pwd_fp'] ?? ''))) {
        send_error(INVALID_TOKEN_MESSAGE, 401);
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    $update = $pdo->prepare('UPDATE `users` SET `password` = :password, `updated_at` = NOW() WHERE `id` = :id');
    $update->execute([':password' => $newHash, ':id' => $userId]);

    send_success('Contraseña actualizada. Ya puedes iniciar sesión con tu nueva contraseña.', []);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [auth_reset] ' . $e->getMessage());
    send_error('Error interno al restablecer la contraseña.', 500);
} catch (\Throwable $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [auth_reset] Fallo de infraestructura: ' . $e->getMessage());
    send_error('Error interno del servidor.', 500);
}
