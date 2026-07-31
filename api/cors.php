<?php

declare(strict_types=1);

// =============================================================================
// api/cors.php — Gestor Centralizado de CORS (AXON_DCD Security Standard)
// Mandamiento #14: CORS ≠ Auth. CORS controla el NAVEGADOR, no a Postman.
//
// INCLUIR como PRIMERA instrucción de cada endpoint PHP, antes de cualquier
// require_once o lógica que pueda fallar — garantiza que el navegador siempre
// reciba los headers CORS aunque un require posterior crashee.
// =============================================================================

// ── Leer FRONTEND_URL y ALLOWED_ORIGINS del .env (parser inline sin dependencias) ──
$_corsEnvPath     = dirname(__DIR__) . '/.env';
$_corsFrontendUrl = '';
$_corsAllowedRaw  = '';

if (is_readable($_corsEnvPath)) {
    foreach (file($_corsEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $_corsLine) {
        $_corsLine = trim($_corsLine);
        if ($_corsLine === '' || $_corsLine[0] === '#' || $_corsLine[0] === ';') {
            continue;
        }
        $_corsPos = strpos($_corsLine, '=');
        if ($_corsPos === false) {
            continue;
        }
        $_corsKey = trim(substr($_corsLine, 0, $_corsPos));
        $_corsVal = trim(substr($_corsLine, $_corsPos + 1));
        // Strip comillas
        $_corsLen = strlen($_corsVal);
        if ($_corsLen >= 2) {
            $_corsF = $_corsVal[0];
            $_corsL = $_corsVal[$_corsLen - 1];
            if (($_corsF === '"' && $_corsL === '"') || ($_corsF === "'" && $_corsL === "'")) {
                $_corsVal = substr($_corsVal, 1, $_corsLen - 2);
            }
        }
        if ($_corsKey === 'FRONTEND_URL') {
            $_corsFrontendUrl = $_corsVal;
        }
        if ($_corsKey === 'ALLOWED_ORIGINS') {
            $_corsAllowedRaw = $_corsVal;
        }
    }
}

unset($_corsEnvPath, $_corsLine, $_corsPos, $_corsKey, $_corsLen, $_corsF, $_corsL);

// ── Whitelist de orígenes permitidos ─────────────────────────────────────────
$_corsAllowed = [
    'http://localhost',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://127.0.0.1',
    'http://127.0.0.1:3000',
];

// Añadir orígenes del .env
if ($_corsFrontendUrl !== '') {
    $_corsAllowed[] = $_corsFrontendUrl;
}
if ($_corsAllowedRaw !== '') {
    foreach (array_map('trim', explode(',', $_corsAllowedRaw)) as $_corsExtra) {
        if ($_corsExtra !== '' && !in_array($_corsExtra, $_corsAllowed, true)) {
            $_corsAllowed[] = $_corsExtra;
        }
    }
}

unset($_corsFrontendUrl, $_corsAllowedRaw, $_corsExtra, $_corsVal);

// ── Emitir headers CORS ───────────────────────────────────────────────────────
$_corsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Solo emitir Allow-Origin si el origen está en la whitelist.
// Para orígenes desconocidos NO se emite el header → el navegador bloquea solo.
if (in_array($_corsOrigin, $_corsAllowed, true)) {
    header("Access-Control-Allow-Origin: {$_corsOrigin}");
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Cache-Control, Authorization');
header('Access-Control-Max-Age: 86400');  // Cache preflight 24 h
header('Vary: Origin');
header('Content-Type: application/json; charset=UTF-8');

unset($_corsAllowed, $_corsOrigin);

// ── Preflight OPTIONS ─────────────────────────────────────────────────────────
// El navegador envía OPTIONS antes de cualquier POST/GET cross-origin.
// Respondemos 204 sin tocar DB ni lógica de negocio.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// ── Operación Escudo (2026-07-21): WAF ligero + rate limiting ───────────────
// Después de OPTIONS (el preflight nunca debe consumir cupo ni activar el WAF)
// pero antes de cualquier lógica de negocio de los endpoints que incluyen este
// archivo como primera instrucción (ver cabecera del archivo).
require_once __DIR__ . '/../helpers/security_shield.php';

if (is_ip_banned()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado.']);
    exit;
}

waf_block_if_malicious();
rate_limit_enforce('api', 60, 60); // 60 peticiones/min por IP — generoso, no bloquea navegación real
