<?php

declare(strict_types=1);

// =============================================================================
// scripts/generate_env.php — Clona .env.example a .env con el host correcto
// Uso: php scripts/generate_env.php [nombre_del_proyecto] [host_bd_remoto]
//
// REGLA CERO: el operador DEBE proveer el host remoto real del hosting del
// proyecto clonado como 2º argumento. Este script jamás hardcodea un host
// real — solo VALIDA que el valor recibido no sea 'localhost'/'127.0.0.1'
// (Mandamiento #13: Aislamiento de Entornos. Mandamiento #12: Bóveda de
// Secretos — ningún hostname de hosting real vive en este archivo).
// =============================================================================

$root        = dirname(__DIR__);
$examplePath = $root . '/.env.example';
$envPath     = $root . '/.env';
$projectName = $argv[1] ?? '[NOMBRE_DEL_PROYECTO]';
$remoteHost  = $argv[2] ?? '';

if ($remoteHost === '' || in_array(strtolower($remoteHost), ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Error: debes proveer el host remoto real del hosting como 2º argumento (Regla Cero — nunca localhost).\n");
    fwrite(STDERR, "Uso: php scripts/generate_env.php <nombre_del_proyecto> <host_bd_remoto>\n");
    exit(1);
}

if (!is_readable($examplePath)) {
    fwrite(STDERR, "Error: no se encontró .env.example en {$examplePath}\n");
    exit(1);
}

if (is_file($envPath)) {
    fwrite(STDERR, "Aviso: .env ya existe. No se sobrescribe. Bórralo manualmente si quieres regenerarlo.\n");
    exit(1);
}

$content = file_get_contents($examplePath);
if ($content === false) {
    fwrite(STDERR, "Error: no se pudo leer .env.example\n");
    exit(1);
}

// Sustituir placeholders básicos y el host remoto real provisto por el operador.
$content = str_replace('NOMBRE_DEL_PROYECTO', $projectName, $content);
$content = preg_replace('/^DB_HOST\s*=.*$/m', 'DB_HOST = "' . $remoteHost . '"', $content) ?? $content;

if (file_put_contents($envPath, $content) === false) {
    fwrite(STDERR, "Error: no se pudo escribir .env\n");
    exit(1);
}

echo "✓ .env generado en {$envPath}\n";
echo "✓ DB_HOST fijado a '{$remoteHost}' (Regla Cero verificada — la BD nunca es local).\n";
echo "→ Completa manualmente DB_NAME, DB_USER, DB_PASS y las API Keys reales.\n";
echo "→ Ejecuta scripts/generate_jwt_keys.php para inyectar secretos JWT seguros.\n";
