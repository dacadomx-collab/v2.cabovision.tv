<?php

declare(strict_types=1);

// =============================================================================
// scripts/acadep_bridge_local/bridge_serve.php
// NO se despliega a producción (excluido por .github/workflows/deploy.yml,
// vive fuera de api/) — este archivo corre EN el servidor físico ACADEP
// (Ubuntu 24.04 LTS, Xeon E3-1220 v5), detrás del túnel Cloudflare, sirviendo
// GET (lectura Cold) y POST /upload (recepción desde helpers/ColdStorageClient.php).
//
// Protocolo AURA CORE v2.0 (2026-07-19): puerto 8081 cerrado a la LAN pública
// por UFW, administrado internamente por cloudflared.service — el túnel es el
// único camino de entrada. Payload multipart/form-data (campo `file`),
// autenticado con el header `X-ACADEP-Bridge-Key`.
//
// Arranque local (servidor ACADEP, Linux):
//   php -S 127.0.0.1:8081 -t /mnt/storage_cold/bridge
//   (cloudflared corre como servicio systemd, no manual)
//
// Requiere ACADEP_BRIDGE_KEY como variable de entorno del proceso PHP local
// (idéntica a la del único .env del proyecto, en la raíz — NUNCA hardcodeada aquí).
//
// Raíz física: /mnt/storage_cold/images/ — punto de montaje del pool RAID1
// de los dos discos mecánicos (ver knowledge/reportes_internos/
// tutorial_cloudflared_acadep.md para el mdadm/fstab). El SSD del servidor
// NO se usa para el histórico — solo sistema operativo y procesos.
//
// Polyfill de compatibilidad (2026-07-19): str_contains() y str_starts_with()
// son de PHP 8.0+. Si el servidor real corre PHP 7.4, este archivo usaba
// ambas — polyfillar solo una no evita el fallo, porque str_contains()
// (línea de $isUpload, más abajo) se ejecuta primero. Sin efecto si el
// intérprete ya es 8.0+ (function_exists evita redeclarar).
// =============================================================================

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$apiKey = getenv('ACADEP_BRIDGE_KEY');
$given  = $_SERVER['HTTP_X_ACADEP_BRIDGE_KEY'] ?? '';

if (!$apiKey || !hash_equals($apiKey, $given)) {
    http_response_code(403);
    exit;
}

$path = $_GET['path'] ?? '';
if (!preg_match('#^\d+/\d{4}/\d{2}/\d{2}/[\w\-.]+\.(jpg|jpeg|png|webp)$#i', $path)) {
    http_response_code(400);
    exit;
}

/**
 * Bitácora de ingesta — NUEVO (2026-07-19, no existía antes en este archivo).
 * Ruta relativa dentro del árbol del proyecto, no /var/log/ raíz — evita
 * depender de permisos de root en el proceso PHP del bridge. @ silencia el
 * fallo de escritura a propósito: un problema de permisos en el log nunca
 * debe tumbar la subida real del archivo.
 */
function logIngestAudit(string $event, string $path, int $httpCode): void
{
    $line = json_encode([
        'timestamp' => date('c'),
        'event'     => $event,
        'path'      => $path,
        'http_code' => $httpCode,
    ], JSON_UNESCAPED_UNICODE) . "\n";

    @file_put_contents(__DIR__ . '/../../logs/ingest_audit.json', $line, FILE_APPEND | LOCK_EX);
}

$coldRoot = '/mnt/storage_cold/images/'; // raíz física dedicada, fuera de cualquier webroot público
$target   = $coldRoot . $path;           // separador '/' nativo en Linux, sin conversión necesaria

$isUpload = $_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($_SERVER['REQUEST_URI'] ?? '', '/upload');

if ($isUpload) {
    $realColdRoot = realpath($coldRoot);
    $destDir      = dirname($target);

    if ($realColdRoot === false || !str_starts_with(realpath($destDir) ?: $destDir, $realColdRoot)) {
        // directorio destino aún no existe la primera vez — validar el padre antes de crear
        if (!str_starts_with($destDir, $coldRoot)) {
            http_response_code(400);
            exit;
        }
    }

    // Control de colisiones inmutables: si la ruta ya existe, abortar con 409
    // en vez de sobrescribir — protege la bitácora multimedia histórica.
    if (is_file($target)) {
        logIngestAudit('collision', $path, 409);
        http_response_code(409);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        exit;
    }

    @mkdir($destDir, 0755, true);
    $moved = move_uploaded_file($_FILES['file']['tmp_name'], $target);

    logIngestAudit($moved ? 'ingest_ok' : 'ingest_failed', $path, $moved ? 201 : 500);
    http_response_code($moved ? 201 : 500);
    exit;
}

// ── Lectura (GET) ────────────────────────────────────────────────────────────
$file = realpath($target);
$root = realpath($coldRoot);

// realpath() + startsWith: segunda barrera anti path-traversal, no confiar solo en el regex
if ($file === false || $root === false || strpos($file, $root) !== 0) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . mime_content_type($file));
header('Cache-Control: private, max-age=3600'); // no cachear públicamente contenido servido vía bridge interno
readfile($file);
