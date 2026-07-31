<?php

declare(strict_types=1);

// =============================================================================
// api/users_create.php — Alta de usuarios del panel administrativo, exclusiva
// del Super Admin (rol "Admin", máxima jerarquía real ya existente en
// `roles`/`model_has_roles` — no se crea un rol "Super Admin" nuevo, Admin ya
// es el tope de la jerarquía en auth_login.php, Mandamiento #10: un solo
// nombre válido por concepto, no duplicar sinónimos del mismo rol).
// Endpoint: POST /api/users_create.php
//
// Protocolo de 6 Capas (mismo patrón que api/articles_publish.php):
//   Capa 1: CORS whitelist (cors.php)
//   Capa 2: RBAC — requireRole(['Admin']), 403 si no califica
//   Capa 3: Restricción explícita de verbo HTTP (POST únicamente)
//   Capa 4: Content-Type application/json + sanitización estricta del payload
//   Capa 5: PDO ATTR_EMULATE_PREPARES=false + prepared statements puros
//   Capa 6: Try/Catch global — error real a error_log, cliente recibe JSON genérico
//
// Contraseña temporal: se genera aquí (random_bytes, nunca elegida por el
// creador ni por el nuevo usuario en este flujo), se hashea con BCrypt
// cost=12 antes de guardar, y se intenta enviar por correo de bienvenida
// (helpers/mailer.php, best-effort — ver ese archivo para el estado real de
// entrega en este entorno). Se devuelve UNA VEZ en la respuesta al Admin que
// creó la cuenta, para que pueda entregarla manualmente si el correo no
// llega (real en localhost) — nunca se vuelve a poder consultar después.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php'; // expone $authPayload
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/mailer.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';
require_once __DIR__ . '/../validators/validator.php';

// ── Capa 2: RBAC ─────────────────────────────────────────────────────────────
requireRole(['Admin'], $authPayload);

asfl_log('REQUEST', ['endpoint' => 'users_create.php', 'method' => $_SERVER['REQUEST_METHOD']]);

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

$name    = sanitize_string((string) ($payload['name'] ?? ''), 255);
$email   = sanitize_email((string) ($payload['email'] ?? ''));
$roleId  = sanitize_int($payload['role_id'] ?? null, 0);
$sendMail = (bool) ($payload['send_welcome_email'] ?? true);

if ($name === '') {
    send_error('El nombre es requerido.', 422);
}
if (!is_valid_email($email)) {
    send_error('Correo electrónico inválido.', 422);
}
if ($roleId <= 0) {
    send_error('El rol es requerido.', 422);
}

try {
    // ── Capa 5: PDO sin emulación de prepares (ya forzado en conexion.php) ──
    $database = new Database();
    $pdo      = $database->getConnection();

    $roleCheck = $pdo->prepare('SELECT `id`, `name` FROM `roles` WHERE `id` = :id LIMIT 1');
    $roleCheck->execute([':id' => $roleId]);
    $role = $roleCheck->fetch(\PDO::FETCH_ASSOC);
    if ($role === false) {
        send_error('El rol indicado no existe.', 422);
    }

    $emailCheck = $pdo->prepare('SELECT `id` FROM `users` WHERE `email` = :email LIMIT 1');
    $emailCheck->execute([':email' => $email]);
    if ($emailCheck->fetch(\PDO::FETCH_ASSOC) !== false) {
        send_error('Ya existe una cuenta con ese correo electrónico.', 409);
    }

    // Contraseña temporal criptográficamente segura — nunca uniqid()/rand().
    // 12 bytes -> 16 chars base64 URL-safe, suficiente entropía para una
    // credencial de un solo uso que el usuario debe cambiar al ingresar.
    $tempPassword = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    $pdo->beginTransaction();

    $insertUser = $pdo->prepare(
        'INSERT INTO `users` (`name`, `email`, `password`, `status`, `created_at`, `updated_at`)
         VALUES (:name, :email, :password, \'activo\', NOW(), NOW())'
    );
    $insertUser->execute([':name' => $name, ':email' => $email, ':password' => $passwordHash]);
    $newUserId = (int) $pdo->lastInsertId();

    $insertRole = $pdo->prepare(
        'INSERT INTO `model_has_roles` (`role_id`, `model_type`, `model_id`) VALUES (:role_id, :model_type, :model_id)'
    );
    $insertRole->execute([':role_id' => $roleId, ':model_type' => 'App\\User', ':model_id' => $newUserId]);

    $pdo->commit();

    $emailSent = false;
    if ($sendMail) {
        $emailSent = send_transactional_email(
            $email,
            $name,
            'Tu acceso a CaboVision.tv',
            '<p>Hola ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Se creó tu cuenta en el panel de CaboVision.tv con el rol <strong>' . htmlspecialchars((string) $role['name'], ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p>Correo: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '<br>Contraseña temporal: <strong>' . htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p>Te recomendamos cambiarla después de tu primer inicio de sesión.</p>'
        );
    }

    asfl_log('RESPONSE', ['endpoint' => 'users_create.php', 'status' => 'success', 'user_id' => $newUserId, 'role' => $role['name']]);

    send_success('Usuario creado.', [
        'id'                 => $newUserId,
        'name'               => $name,
        'email'              => $email,
        'role'               => $role['name'],
        'temp_password'      => $tempPassword,
        'welcome_email_sent' => $emailSent,
    ], 201);
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[' . date('Y-m-d H:i:s') . '] [users_create] ' . $e->getMessage());
    send_error('Error interno al crear el usuario.', 500);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[' . date('Y-m-d H:i:s') . '] [users_create] Fallo de infraestructura: ' . $e->getMessage());
    send_error('Error interno del servidor.', 500);
}
