<?php

declare(strict_types=1);

// =============================================================================
// helpers/security_shield.php — Blindaje perimetral ligero a nivel de
// aplicación (Operación Escudo, 2026-07-21). Sin NGINX real en este stack
// (Apache/XAMPP) — se implementa en PHP usando APCu (ya activo, mismo patrón
// que helpers/object_cache.php), sin dependencias nuevas, sin bloquear
// tráfico legítimo por diseño: límites generosos, degradación seguridad si
// APCu no está disponible (deja pasar en vez de romper el sitio).
// =============================================================================

/**
 * Límite de tasa por IP + "bucket" (ej. 'api', 'login'). Si se excede,
 * responde 429 y termina la ejecución — nunca bloquea silenciosamente sin
 * decirle al cliente por qué (Retry-After real).
 */
function rate_limit_enforce(string $bucket, int $maxRequests, int $windowSeconds): void
{
    if (!function_exists('apcu_fetch')) {
        return; // sin APCu: no se aplica límite, el sitio sigue funcionando (degradación segura)
    }

    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'ratelimit_' . $bucket . '_' . $ip;

    $count = apcu_fetch($key);
    if ($count === false) {
        apcu_store($key, 1, $windowSeconds);
        return;
    }

    if ((int) $count >= $maxRequests) {
        header('Retry-After: ' . $windowSeconds);
        http_response_code(429);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Demasiadas solicitudes. Intenta más tarde.']);
        security_log('rate_limit_exceeded', $bucket);
        exit;
    }

    apcu_inc($key);
}

/**
 * Firmas conservadoras de ataque común (SQLi/XSS/Directory Traversal) sobre
 * la URI y el query string. Deliberadamente conservador — prefiere dejar
 * pasar un caso dudoso a bloquear tráfico legítimo (Fricción Cero). No
 * inspecciona el body de POST (evitaría romper contenido editorial real con
 * HTML/comillas legítimas — esa validación ya vive en input_sanitizer.php
 * por campo, con contexto, no aquí a ciegas).
 */
function waf_block_if_malicious(): void
{
    // urldecode: REQUEST_URI llega tal cual el cliente lo envió (ej. "%20"
    // literal, no espacio real) — sin decodificar, patrones como "union select"
    // con %20 en vez de espacio real pasarían el WAF sin ser detectados.
    $uri = rawurldecode($_SERVER['REQUEST_URI'] ?? '');

    $patterns = [
        '/\.\.\//',                                   // path traversal
        '/union\s+select/i',                          // SQLi
        '/<script\b/i',                                // XSS crudo en URL
        '/base64_decode\s*\(/i',                       // payload PHP típico de RCE
        '/etc\/passwd/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $uri) === 1) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'Solicitud rechazada.']);
            security_log('waf_pattern_blocked', $pattern . ' :: ' . $uri);
            ban_ip(3600); // 1h — fingerprinting simple, no permanente ante falso positivo
            exit;
        }
    }
}

/** Verifica si la IP actual está baneada (honeypot/WAF) — llamar antes de procesar cualquier request real. */
function is_ip_banned(): bool
{
    if (!function_exists('apcu_fetch')) {
        return false;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    return apcu_fetch('ipban_' . $ip) !== false;
}

/** Banea la IP actual por $ttlSeconds (default 24h) — usado por honeypots y el WAF. */
function ban_ip(int $ttlSeconds = 86400): void
{
    if (!function_exists('apcu_store')) {
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    apcu_store('ipban_' . $ip, true, $ttlSeconds);
}

/** Log de auditoría real de intrusiones — append-only en BD (ver database/schema_v7_security_intrusion_log.sql). */
function security_log(string $eventType, string $detail): void
{
    try {
        require_once __DIR__ . '/../api/conexion.php';
        $pdo = (new Database())->getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO security_intrusion_log (event_type, ip_origen, user_agent, request_uri, detail, occurred_at)
             VALUES (:event_type, :ip, :ua, :uri, :detail, NOW())'
        );
        $stmt->execute([
            ':event_type' => $eventType,
            ':ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua'         => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':uri'        => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
            ':detail'     => substr($detail, 0, 255),
        ]);
    } catch (\Throwable $e) {
        // Nunca romper la respuesta de bloqueo real por un fallo de logging — solo se registra en error_log.
        error_log('[' . date('Y-m-d H:i:s') . '] [security_shield] Fallo al registrar intrusión: ' . $e->getMessage());
    }
}
