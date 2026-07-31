<?php

declare(strict_types=1);

// =============================================================================
// api/auth_recover.php — Solicitud de recuperación de contraseña (Paso 1 de 2)
// Endpoint: POST /api/auth_recover.php
// Auth: Público (es el punto de entrada del flujo "Olvidé mi contraseña")
//
// Protocolo de 6 Capas:
//   Capa 1: CORS whitelist (cors.php)
//   Capa 2: N/A — endpoint público por diseño, sin sesión previa que validar
//   Capa 3: Restricción explícita de verbo HTTP (POST únicamente)
//   Capa 4: Content-Type application/json + sanitización del payload
//   Capa 5: PDO ATTR_EMULATE_PREPARES=false + prepared statements puros
//   Capa 6: Try/Catch global — error real a error_log, cliente recibe JSON genérico
//
// Doctrina Zero Enumeration (MODULO_01 §2.2/§3.6): la respuesta es SIEMPRE el
// mismo mensaje genérico, exista o no la cuenta, esté activa o suspendida —
// jamás se revela cuál de esos casos ocurrió. El tiempo de respuesta también
// se mantiene similar entre ambos casos (mismo camino: se computa el token y
// se intenta enviar el correo solo si el usuario existe, pero SIEMPRE se
// llega al mismo mensaje final sin ramas visibles desde afuera).
//
// Token sin tabla nueva (Mandamiento #9 — Inmutabilidad del Sistema): en vez
// de la tabla `recuperacion_password` que describe el manual genérico, se
// reutiliza la infraestructura JWT ya existente (api/jwt.php, sin
// dependencias, ya validada en producción por auth_login.php). El token
// incluye `pwd_fp` = huella corta del password_hash ACTUAL del usuario — al
// cambiar la contraseña en api/auth_reset.php, esa huella deja de coincidir,
// logrando la garantía de "un solo uso" sin necesitar una columna `usado`.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';
require_once __DIR__ . '/../helpers/security_shield.php';
require_once __DIR__ . '/../helpers/mailer.php';
require_once __DIR__ . '/../helpers/mail_templates.php';
require_once __DIR__ . '/../validators/validator.php';

const RESET_TOKEN_TTL_SECONDS = 3600; // 1 hora — más corto que una invitación (MODULO_01 §3.6)

// Límite propio, más estricto que el genérico de cors.php — este endpoint no
// requiere contraseña para "intentarse", así que es más atractivo para abuso
// (enumeración por timing, spam de correos hacia terceros) que el login.
rate_limit_enforce('auth_recover', 5, 60);

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

$email = sanitize_email((string) ($payload['email'] ?? ''));

// Mensaje único de éxito — se define ANTES de cualquier consulta a BD para
// que sea imposible, por accidente, devolver un mensaje distinto en alguna
// rama (Zero Enumeration real, no solo "casi siempre igual").
$genericMessage = 'Si el correo existe en nuestro sistema, recibirás un enlace de recuperación en breve.';

if (!is_valid_email($email)) {
    // Incluso un email con formato inválido responde igual — nunca "formato
    // de correo incorrecto" (eso también filtraría información de timing/
    // validación distinta a un intento con email bien formado pero inexistente).
    send_success($genericMessage, []);
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare('SELECT `id`, `name`, `email`, `password`, `status` FROM `users` WHERE `email` = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($user !== false && $user['status'] === 'activo') {
        $env    = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
        $secret = (string) ($env['JWT_SECRET'] ?? '');
        $appUrl = (string) ($env['APP_URL'] ?? 'http://localhost/CaboVision.tv');

        if ($secret !== '') {
            $pwdFingerprint = substr(hash('sha256', (string) $user['password']), 0, 16);

            $resetToken = jwtEncode([
                'sub'    => (int) $user['id'],
                'type'   => 'password_reset',
                'pwd_fp' => $pwdFingerprint,
            ], $secret, RESET_TOKEN_TTL_SECONDS);

            $resetUrl = $appUrl . '/admin/restablecer.php?token=' . rawurlencode($resetToken);

            $emailHtml = build_password_reset_email_html($resetUrl, (string) $user['name'], (int) (RESET_TOKEN_TTL_SECONDS / 60));
            send_transactional_email($email, (string) $user['name'], 'Recupera tu acceso — CaboVision.tv', $emailHtml);
        } else {
            error_log('[' . date('Y-m-d H:i:s') . '] [auth_recover] JWT_SECRET no configurado — no se pudo emitir token de recuperación.');
        }
    }
    // Si el usuario no existe o está suspendido: no se hace NADA más — se
    // cae directo al mismo mensaje genérico de abajo, sin distinción visible.

    send_success($genericMessage, []);
} catch (\PDOException $e) {
    // ── Capa 6: Try/Catch global — nunca se expone el error real de PDO ─────
    error_log('[' . date('Y-m-d H:i:s') . '] [auth_recover] ' . $e->getMessage());
    // Incluso ante un fallo real de servidor, Zero Enumeration exige el mismo
    // mensaje — un 500 distinto filtraría que el email SÍ disparó lógica real.
    send_success($genericMessage, []);
}
