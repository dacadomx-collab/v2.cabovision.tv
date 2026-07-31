<?php

declare(strict_types=1);

// =============================================================================
// scripts/generate_jwt_keys.php — Inyecta un JWT_SECRET aleatorio en .env
// Uso: php scripts/generate_jwt_keys.php
// =============================================================================

$root    = dirname(__DIR__);
$envPath = $root . '/.env';

if (!is_file($envPath)) {
    fwrite(STDERR, "Error: no existe .env. Ejecuta primero scripts/generate_env.php\n");
    exit(1);
}

$content = file_get_contents($envPath);
if ($content === false) {
    fwrite(STDERR, "Error: no se pudo leer .env\n");
    exit(1);
}

$secret = base64_encode(random_bytes(64));

$replaced = preg_replace('/^JWT_SECRET\s*=.*$/m', 'JWT_SECRET = "' . $secret . '"', $content, -1, $count);

if ($count === 0) {
    fwrite(STDERR, "Aviso: no se encontró la línea JWT_SECRET en .env. Añádela manualmente:\n");
    fwrite(STDERR, "JWT_SECRET = \"{$secret}\"\n");
    exit(1);
}

if (file_put_contents($envPath, $replaced) === false) {
    fwrite(STDERR, "Error: no se pudo escribir .env\n");
    exit(1);
}

echo "✓ JWT_SECRET regenerado (64 bytes aleatorios, base64).\n";
echo "⚠ Esto invalida todas las sesiones activas (access y refresh tokens existentes).\n";
