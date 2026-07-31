<?php

declare(strict_types=1);

// =============================================================================
// api/sponsors_track.php — Receptor de telemetría publicitaria (Módulo 04)
// Endpoint: POST /api/sponsors_track.php
// Auth: Público (recibe navigator.sendBeacon() de visitantes anónimos, igual
// que api/sponsors_redirect.php — Mandamiento #14 no aplica aquí porque no
// hay mutación de datos de negocio sensibles, solo un log de telemetría).
//
// Recibe el payload de assets/js/sponsor-telemetry.js: {banner_id, event_type,
// page_url}. Calcula hash_sesion server-side (el salt NUNCA sale de aquí) e
// inserta en sponsors_metricas (⚠️ INMUTABLE, ver database/schema_v3_ads.sql).
//
// Protocolo de 6 Capas:
//   1. CORS desde whitelist de .env (api/cors.php)
//   2. Anti-fraude: SHA-256(salt + UA + IP truncada)
//   3. Restricción de método HTTP
//   4. Captura de payload JSON/texto plano sin bloquear al emisor
//   5. Persistencia PDO sin emulación de prepares
//   6. Try/Catch global -> logs/error.log, contrato de respuesta unificado
// =============================================================================

// ── CAPA 1: CORS ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';

// ── CAPA 3: Restricción de método HTTP ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Método no permitido.', 405);
}

const VALID_EVENT_MAP = [
    'impression' => 'impresion',
    'click'      => 'clic',
];

/**
 * Trunca una IP para anonimización (Mandamiento de privacidad, ver
 * MODULO_04_MARKETING_ORGANICO.md §3): IPv4 pierde el último octeto,
 * IPv6 pierde los últimos 80 bits (10 bytes).
 */
function truncateIp(string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return '0.0.0.0';
    }

    if (strlen($packed) === 4) { // IPv4
        $packed[3] = "\x00";
    } else { // IPv6 — conserva los primeros 48 bits, cero el resto (80 bits)
        for ($i = 6; $i < 16; $i++) {
            $packed[$i] = "\x00";
        }
    }

    return inet_ntop($packed) ?: '0.0.0.0';
}

/** CAPA 2: hash de sesión anti-fraude — el salt jamás sale de esta función. */
function buildSessionHash(string $salt, string $ip, string $userAgent): string
{
    return hash('sha256', $salt . truncateIp($ip) . $userAgent);
}

try {
    $envPath = dirname(__DIR__) . '/.env';
    $env     = parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: [];
    $salt    = (string) ($env['ADS_TELEMETRY_SALT'] ?? '');

    if ($salt === '') {
        error_log('[' . date('Y-m-d H:i:s') . '] [sponsors_track] ADS_TELEMETRY_SALT no configurado en .env');
        send_error('Servicio de telemetría no disponible.', 503);
    }

    // ── CAPA 4: captura de payload (JSON vía Blob de sendBeacon, o texto plano) ──
    $rawBody = file_get_contents('php://input');
    $payload = json_decode((string) $rawBody, true);

    if (!is_array($payload)) {
        send_error('Payload inválido.', 400);
    }

    $bannerId  = filter_var($payload['banner_id'] ?? null, FILTER_VALIDATE_INT);
    $eventType = (string) ($payload['event_type'] ?? '');

    if ($bannerId === false || $bannerId === null || $bannerId <= 0) {
        send_error('banner_id inválido.', 422);
    }
    if (!array_key_exists($eventType, VALID_EVENT_MAP)) {
        send_error('event_type inválido.', 422);
    }

    $clientIp  = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $hashSesion = buildSessionHash($salt, $clientIp, $userAgent);

    // ── CAPA 5: persistencia PDO sin emulación de prepares ──────────────────
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare(
        'INSERT INTO `sponsors_metricas` (`banner_id`, `tipo_evento`, `hash_sesion`)
         VALUES (:banner_id, :tipo_evento, :hash_sesion)'
    );
    $stmt->execute([
        ':banner_id'   => $bannerId,
        ':tipo_evento' => VALID_EVENT_MAP[$eventType],
        ':hash_sesion' => $hashSesion,
    ]);

    send_success('Evento registrado.', [], 201);
} catch (\PDOException $e) {
    // FK inválida (banner_id inexistente) u otro fallo de integridad — no se
    // expone el detalle real de PDO al cliente (Mandamiento: nunca mostrar
    // errores de PDO en frontend).
    error_log('[' . date('Y-m-d H:i:s') . '] [sponsors_track] ' . $e->getMessage());
    send_error('No se pudo registrar el evento.', 422);
} catch (\Throwable $e) {
    // ── CAPA 6: Try/Catch global ─────────────────────────────────────────────
    error_log('[' . date('Y-m-d H:i:s') . '] [sponsors_track] Fallo de infraestructura: ' . $e->getMessage());
    send_error('Error interno del servidor.', 500);
}
