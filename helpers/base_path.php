<?php

declare(strict_types=1);

// =============================================================================
// helpers/base_path.php — Prefijo de ruta del sitio, derivado de APP_URL
// (.env, ya definido en el Codex — ver .env.example). Corrige el hardcodeo
// literal de "/CaboVision.tv/" que asumia el subdirectorio de XAMPP local en
// TODAS las rutas del sitio (nav, assets, media bridge) — en staging/produccion
// el docroot es la raiz del dominio, sin ese subdirectorio, y el string
// literal rompia CSS/JS/imagenes/enlaces ahi (2026-07-31).
// =============================================================================

/**
 * Prefijo de ruta del sitio (ej. "/CaboVision.tv" en XAMPP local, "" en un
 * dominio real como v2.cabovision.tv) — el componente de ruta de APP_URL.
 * Usar SIEMPRE en vez de hardcodear el subdirectorio local.
 */
function base_path(): string
{
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    $envPath = dirname(__DIR__) . '/.env';
    $env     = is_readable($envPath) ? @parse_ini_file($envPath, false, INI_SCANNER_RAW) : false;
    $appUrl  = $env !== false ? (string) ($env['APP_URL'] ?? '') : '';

    $path = rtrim((string) (parse_url($appUrl, PHP_URL_PATH) ?? ''), '/');

    return $path;
}
