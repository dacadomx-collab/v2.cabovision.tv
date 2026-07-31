<?php

declare(strict_types=1);

// =============================================================================
// api/get_ip.php — Reporta la IP publica de salida de este servidor, para
// dar de alta en la whitelist de MySQL remoto del hosting de produccion.
// Diagnostico de infraestructura, no toca BD ni credenciales.
// =============================================================================

header('Content-Type: text/plain; charset=UTF-8');

$ip = trim((string) @file_get_contents('https://api.ipify.org'));

if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
    http_response_code(502);
    echo "No se pudo determinar la IP de salida.\n";
    exit;
}

echo $ip . "\n";
