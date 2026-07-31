<?php

declare(strict_types=1);

// =============================================================================
// honeypot.php — Manejador compartido de honeypots (Operación Escudo, 2026-07-21)
// wp-login.php, wp-admin.php, xmlrpc.php (raíz) apuntan aquí. CaboVision.tv NO
// es WordPress — cualquier request real a estas rutas es 100% escaneo
// automatizado, cero riesgo de bloquear un usuario legítimo. Registra la
// intrusión y banea la IP, respondiendo con un 404 genérico (no delata que
// es un honeypot).
// =============================================================================

require_once __DIR__ . '/helpers/security_shield.php';

security_log('honeypot_hit', 'ruta=' . ($_SERVER['REQUEST_URI'] ?? '?'));
ban_ip(86400); // 24h — bot de escaneo confirmado, no un falso positivo posible

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
echo "<!doctype html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>";
