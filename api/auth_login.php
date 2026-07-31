<?php

declare(strict_types=1);

// =============================================================================
// api/auth_login.php — Login JWT Enterprise (Access + Refresh + Device Binding)
// Endpoint: POST /api/auth_login.php
// Mandamiento #2: Seguridad Nivel Militar | Mandamiento #14: CORS ≠ Auth
//
// Protocolo de Conexión Modular de 6 Capas:
//   Capa 1: CORS whitelist (cors.php, vía ALLOWED_ORIGINS en .env)
//   Capa 2: RBAC — bloqueo fulminante si status != 'activo'
//   Capa 3: Restricción explícita de verbo HTTP (solo POST)
//   Capa 4: Content-Type application/json estricto + sanitización de payload
//   Capa 5: PDO con ATTR_EMULATE_PREPARES=false, cero concatenación (conexion.php)
//   Capa 6: Try/Catch global — error real a error_log, cliente recibe JSON genérico
//
// Doctrina "Zero Enumeration": el mismo 401 "Credenciales inválidas" cubre
// email inexistente, password incorrecto Y cuenta suspendida. Ningún atacante
// debe poder distinguir el motivo del fallo por mensaje ni por timing.
//
// Schema (reconciliado 2026-07-17, ver knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md):
//   - `users`/`roles`/`model_has_roles` — sistema RBAC Spatie-style YA
//     existente en cabovision_local con 17 cuentas reales del equipo
//     editorial. `status` es la única columna añadida (ALTER aditivo).
//     `database/schema_v1_auth.sql` (users/roles/user_roles nuevos) quedó
//     DEPRECADO — no se aplicó, se adoptó el schema real en su lugar.
//   - `login_attempts` — nueva, aplicada a cabovision_local.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';
require_once __DIR__ . '/../validators/validator.php';

// Operación Escudo: además del límite genérico de 60/min ya aplicado en
// cors.php, login tiene su propio límite más estricto (5/min por IP) — capa
// perimetral independiente de login_attempts (que solo cuenta FALLOS
// consecutivos; esto cuenta CUALQUIER intento, éxito o no, para frenar
// fuerza bruta distribuida antes de que llegue a tocar la BD).
rate_limit_enforce('login', 5, 60);

asfl_log('REQUEST', ['endpoint' => 'auth_login.php', 'method' => $_SERVER['REQUEST_METHOD']]);

// ── Capa 3: Restricción explícita de verbo HTTP ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Método no permitido.', 405);
}

// ── Capa 4a: Content-Type estricto — rechaza cualquier cosa que no sea JSON ──
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
    send_error('Content-Type debe ser application/json.', 415);
}

// ── Capa 4b: Payload JSON válido y sanitizado ───────────────────────────────
try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    send_error('Payload JSON inválido.', 400);
}
if (!is_array($payload)) {
    send_error('Payload JSON inválido.', 400);
}

$email      = sanitize_email((string) ($payload['email'] ?? ''));
$password   = (string) ($payload['password'] ?? '');
$recordarme = (bool) ($payload['recordarme'] ?? false);

if (!is_valid_email($email)) {
    send_error('Correo electrónico inválido.', 422);
}
if ($password === '') {
    send_error('La contraseña es requerida.', 422);
}

// device_id: preferir el enviado por el cliente (UUID persistido en el
// dispositivo); si no llega, derivar uno desde IP + User-Agent.
$deviceId = sanitize_string((string) ($payload['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? '')));
if ($deviceId === '') {
    $deviceId = jwtMakeDeviceId();
}

$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// Hash dummy fijo — NUNCA corresponde a ningún password real. Se usa cuando
// el email no existe, para que password_verify() siempre corra y el costo de
// CPU/latencia sea idéntico exista o no la cuenta (anti-enumeración por timing).
const DUMMY_HASH = '$2y$10$C6UzMDM.H6dfI/f/IKcEeO4RY4vhI3iCr9BEIhZ.O2SybICQIiiWG';

// RBAC: se consulta el sistema YA existente y real en `cabovision_local`
// (roles Spatie-style: `roles` + `model_has_roles` polimórfico) — decisión
// 2026-07-17, adopta las 17 cuentas reales del equipo editorial en vez de
// duplicar con una tabla `user_roles` paralela.
//
// El valor almacenado en `model_has_roles.model_type` es "App\User" (8
// bytes, UN solo backslash real — verificado con bin2hex() vía PDO con
// bound parameter, que es como corre esta consulta y evita por completo
// cualquier ambigüedad de escapado de literales SQL). En PHP de comillas
// simples, 'App\\User' (2 backslashes en el código fuente) produce
// exactamente ese backslash real — confirmado end-to-end, encuentra el
// rol correcto (ej. "Admin" para model_id=989).
const AUTH_MODEL_TYPE = 'App\\User';

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    // Closure de fallo genérico — registra el intento fallido (auditoría
    // append-only) y responde SIEMPRE el mismo mensaje/código, sin impostar
    // el motivo real (Capa 2, Capa 6, doctrina Zero Enumeration).
    $genericFail = static function () use ($email, $ipHash, $pdo): never {
        $ins = $pdo->prepare(
            'INSERT INTO login_attempts (identifier, ip_hash, success) VALUES (:id, :ip, 0)'
        );
        $ins->execute([':id' => $email, ':ip' => $ipHash]);
        send_error('Credenciales inválidas.', 401);
    };

    // ── Tarpitting progresivo: fallos recientes por identificador+IP ────────
    $recent = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE identifier = :id AND ip_hash = :ip AND success = 0
           AND attempted_at > NOW() - INTERVAL 15 MINUTE"
    );
    $recent->execute([':id' => $email, ':ip' => $ipHash]);
    $failCount = (int) $recent->fetchColumn();

    if ($failCount >= 10) {
        // Bloqueo duro: no vale la pena ni tarpitting, corta de inmediato
        send_error('Demasiados intentos. Intenta más tarde.', 429);
    }
    if ($failCount > 0) {
        // 300ms por fallo previo, tope 3s — penaliza fuerza bruta sin
        // bloquear de forma perceptible a un usuario que solo se equivocó una vez
        usleep(min($failCount * 300_000, 3_000_000));
    }

    $stmt = $pdo->prepare(
        'SELECT id, email, password, status FROM users WHERE email = :email LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);

    $hashToVerify = $user !== false ? (string) $user['password'] : DUMMY_HASH;
    $passwordOk   = password_verify($password, $hashToVerify);

    if ($user === false || !$passwordOk) {
        $genericFail();
    }

    // ── Capa 2: RBAC — bloqueo fulminante si el usuario está suspendido ─────
    if ($user['status'] !== 'activo') {
        $genericFail();
    }

    // Éxito: registrar intento exitoso (auditoría). El contador de fallos
    // vive en login_attempts (append-only), no en una columna de `users`
    // — la tabla real no tiene `last_login_at`/`failed_login_count` (esas
    // solo existían en el schema descartado, ver database/schema_v1_auth.sql).
    $ok = $pdo->prepare('INSERT INTO login_attempts (identifier, ip_hash, success) VALUES (:id, :ip, 1)');
    $ok->execute([':id' => $email, ':ip' => $ipHash]);

    // ── Roles del usuario (RBAC Spatie-style: roles + model_has_roles) ──────
    $rolesStmt = $pdo->prepare(
        'SELECT r.name FROM roles r
         INNER JOIN model_has_roles mhr ON mhr.role_id = r.id
         WHERE mhr.model_type = :model_type AND mhr.model_id = :uid
         ORDER BY r.id ASC'
    );
    $rolesStmt->execute([':model_type' => AUTH_MODEL_TYPE, ':uid' => $user['id']]);
    $roles = $rolesStmt->fetchAll(\PDO::FETCH_COLUMN);

    if (empty($roles)) {
        error_log('[' . date('Y-m-d H:i:s') . '] [auth_login] Usuario sin rol asignado: id=' . $user['id']);
        send_error('Cuenta sin rol asignado. Contacta al administrador.', 403);
    }

    $env        = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
    $secret     = (string) ($env['JWT_SECRET'] ?? '');
    $accessTtl  = (int) ($env['JWT_ACCESS_TTL'] ?? 900);
    $refreshTtl = (int) ($env['JWT_REFRESH_TTL'] ?? 2592000);

    // "Mantenerse Registrado" (MODULO_01_LOGIN_Y_ACCESO.md §3.5) — extiende
    // SOLO la vida del refresh token a 60 días; el access token sigue siendo
    // de vida corta sin excepción (ninguna otra capa de seguridad se relaja:
    // Device Binding y la firma HS256 aplican igual). Este proyecto es
    // stateless (JWT, sin columna token_expira_en en BD — ver nota de
    // arquitectura en api/jwt.php), así que "recordarme" se resuelve al
    // EMITIR el token, no con una fila que actualizar después.
    $recordarmeRefreshTtl = 60 * 24 * 60 * 60; // 60 días
    if ($recordarme) {
        $refreshTtl = $recordarmeRefreshTtl;
    }

    if ($secret === '') {
        send_error('Configuración de seguridad incompleta.', 500);
    }

    $claims = [
        'sub'   => (int) $user['id'],
        'email' => (string) $user['email'],
        'role'  => $roles[0],   // compat con requireRole() existente (rol de mayor jerarquía)
        'roles' => $roles,      // set completo — RBAC granular futuro, ver auth_middleware.php
    ];

    $accessToken  = jwtEncodeAccess($claims, $secret, $deviceId, $accessTtl);
    $refreshToken = jwtEncodeRefresh($claims, $secret, $deviceId, $refreshTtl);

    asfl_log('RESPONSE', ['endpoint' => 'auth_login.php', 'status' => 'success', 'user_id' => $user['id']]);

    send_success('Autenticación exitosa.', [
        'access_token'  => $accessToken,
        'refresh_token' => $refreshToken,
        'device_id'     => $deviceId,
        'expires_in'    => $accessTtl,
        'role'          => $roles[0],
        'roles'         => $roles,
    ]);

} catch (\PDOException $e) {
    // Capa 6: Try/Catch global — el detalle real solo va al log del servidor
    error_log('[' . date('Y-m-d H:i:s') . '] [auth_login] ' . $e->getMessage());
    send_error('Error interno al procesar el inicio de sesión.', 500);
}
