<?php

declare(strict_types=1);

// =============================================================================
// helpers/media.php — Resolución segura de rutas de medios (Hot/Cold Storage)
// El histórico de `articles`/`candidates` referencia imágenes bajo /images/...
// Toda salida de imagen debe pasar por aquí para nunca romper <img> ni asumir
// que el archivo físico existe en disco.
//
// v2 (2026-07-17): integra el tier de media_assets — si el archivo no está
// físicamente en Hot, consulta si está registrado como Cold y, de ser así,
// enruta a través de api/media_bridge.php en vez de caer directo a placeholder.
// Ver database/schema_v2_media.sql.
// =============================================================================

require_once __DIR__ . '/../api/conexion.php';
require_once __DIR__ . '/base_path.php';

/** Ruta pública del logotipo de marca usado como fallback — única fuente de verdad. */
function media_placeholder_path(): string
{
    return base_path() . '/assets/img/logocabovis_glow.png';
}

/**
 * Consulta el tier de un media_asset por su ruta relativa. Cacheada en un
 * array estático de por vida del request — evita N+1 queries en listados con
 * muchos misses de Hot Storage (ej. páginas de artículos antiguos).
 */
function media_lookup_tier(string $relativePath): ?string
{
    static $cache = [];
    static $pdo   = null;

    if (array_key_exists($relativePath, $cache)) {
        return $cache[$relativePath];
    }

    if ($pdo === null) {
        $database = new Database();
        $pdo      = $database->getConnection();
    }

    $stmt = $pdo->prepare('SELECT storage_tier FROM media_assets WHERE relative_path = :p LIMIT 1');
    $stmt->execute([':p' => $relativePath]);
    $tier = $stmt->fetchColumn();

    return $cache[$relativePath] = ($tier !== false ? (string) $tier : null);
}

function resolve_media_path(?string $dbPath): string
{
    $placeholder = media_placeholder_path();

    if ($dbPath === null || $dbPath === '') {
        return $placeholder;
    }

    // Solo rutas relativas dentro del propio dominio — nunca seguir URLs externas
    // ni rutas que intenten escapar de la raíz del proyecto.
    if (str_starts_with($dbPath, 'http://') || str_starts_with($dbPath, 'https://') || str_contains($dbPath, '..')) {
        return $placeholder;
    }

    $relative = ltrim($dbPath, '/');
    $absolute = dirname(__DIR__) . '/' . $relative;

    if (is_file($absolute)) {
        return base_path() . "/{$relative}"; // Hot — camino más frecuente, sin tocar la BD
    }

    if (media_lookup_tier($relative) === 'cold') {
        return base_path() . '/api/media_bridge.php?path=' . rawurlencode($relative);
    }

    return $placeholder; // ni en Hot ni registrado como Cold — comportamiento previo sin cambios
}

/**
 * Obtiene los bytes reales de un media_asset (Hot directo del disco, o Cold
 * vía túnel al bridge ACADEP) — extraído de api/media_bridge.php (2026-07-24)
 * para que api/og_image.php (pipeline Open Graph 1200x630) reutilice EXACTAMENTE
 * la misma lógica de origen en vez de duplicarla (Mandamiento #10). Conserva
 * los mismos efectos secundarios reales que ya tenía media_bridge.php al leer
 * de Cold: actualiza `last_served_at` y registra telemetría de consumo — es
 * lectura real de Cold Storage sin importar quién la pidió.
 *
 * @return string|null Bytes crudos, o null si el archivo no existe en ningún tier.
 */
function fetch_media_bytes(\PDO $pdo, string $relativePath): ?string
{
    $stmt = $pdo->prepare('SELECT storage_tier, file_hash, migrated_to_cold_at FROM media_assets WHERE relative_path = :p LIMIT 1');
    $stmt->execute([':p' => $relativePath]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    // Igual que resolve_media_path(): un archivo recién subido por el editor
    // (api/articles_create.php) vive en disco SIN fila en media_assets todavía
    // (ese catálogo lo puebla el pipeline de migración, no el alta editorial) —
    // exigir una fila aquí rompería el pipeline OG para artículos nuevos.
    // Verificar el disco directo, sin depender de storage_tier, es la misma
    // fuente de verdad que ya usa resolve_media_path() para decidir Hot.
    $hotFile = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($hotFile)) {
        return (string) file_get_contents($hotFile);
    }

    if (($row['storage_tier'] ?? null) === 'cold' && !empty($row['file_hash']) && !empty($row['migrated_to_cold_at'])) {
        $env       = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
        $bridgeUrl = rtrim((string) ($env['ACADEP_BRIDGE_URL'] ?? ''), '/');
        $bridgeKey = (string) ($env['ACADEP_BRIDGE_KEY'] ?? '');

        if ($bridgeUrl === '' || $bridgeKey === '') {
            return null;
        }

        $tenant     = strtok($relativePath, '/') ?: '';
        $uploadedAt = new \DateTimeImmutable((string) $row['migrated_to_cold_at']);
        $ext        = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
        $asset      = sprintf(
            '%s/%s/%s/%s/%s.%s',
            $tenant,
            $uploadedAt->format('Y'),
            $uploadedAt->format('m'),
            $uploadedAt->format('d'),
            $row['file_hash'],
            $ext
        );

        $ch = curl_init($bridgeUrl . '/view?asset=' . urlencode($asset));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['X-ACADEP-Bridge-Key: ' . $bridgeKey],
            CURLOPT_RETURNTRANSFER => true,
            // Mientras el tunel publico de ACADEP no este activo, la IP LAN
            // (192.168.1.224) es inalcanzable desde el hosting de staging —
            // el timeout de conexion debe fallar rapido para no colgar el
            // request de paginas con imagenes historicas (Cold Storage).
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $body !== false) {
            $upd = $pdo->prepare('UPDATE media_assets SET last_served_at = NOW() WHERE relative_path = :p');
            $upd->execute([':p' => $relativePath]);

            date_default_timezone_set('America/Mazatlan');
            $log = $pdo->prepare(
                'INSERT INTO telemetria_cold_storage (relative_path, ip_origen, bytes_transferidos, consultado_at)
                 VALUES (:path, :ip, :bytes, :consultado_at)'
            );
            $log->execute([
                ':path'          => $relativePath,
                ':ip'            => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                ':bytes'         => strlen($body),
                ':consultado_at' => date('Y-m-d H:i:s'),
            ]);

            return $body;
        }

        error_log('[' . date('Y-m-d H:i:s') . '] [fetch_media_bytes] Fallo al leer Cold (' . $code . '): ' . $asset);
    }

    return null;
}
