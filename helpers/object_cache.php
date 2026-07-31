<?php

declare(strict_types=1);

// =============================================================================
// helpers/object_cache.php — Caché de objetos nativo (APCu, ya activo en este
// XAMPP — sin Redis/Memcached, no forman parte del stack). Uso: datos
// repetitivos consultados en cada request (menú, categorías, config) que hoy
// se recalculan desde la BD en cada carga de página.
//
// Invalidación: por TTL (expira solo) + invalidación explícita vía
// cache_invalidate() para cuando exista un endpoint real que mute los datos
// cacheados (ej. un futuro categories_create.php debe llamar
// cache_invalidate('cabovision_nav_v1') tras escribir). Hoy no existe ningún
// endpoint que mute `categories` — no se inventa esa llamada donde no hay
// caller real (Mandamiento #4), solo se deja la función lista para cuando
// exista.
//
// Degradación segura: si APCu no está disponible (extensión deshabilitada en
// algún entorno), cache_remember() simplemente ejecuta el callback cada vez
// — el sitio sigue funcionando igual, solo sin el ahorro de caché.
// =============================================================================

/**
 * Devuelve el valor cacheado bajo $key, o ejecuta $callback(), lo cachea por
 * $ttl segundos y lo devuelve. Si $callback() lanza una excepción, nada se
 * cachea (el próximo request vuelve a intentar la consulta real).
 */
function cache_remember(string $key, int $ttl, callable $callback): mixed
{
    if (function_exists('apcu_fetch')) {
        $hit   = false;
        $value = apcu_fetch($key, $hit);
        if ($hit) {
            return $value;
        }
    }

    $value = $callback();

    if (function_exists('apcu_store')) {
        apcu_store($key, $value, $ttl);
    }

    return $value;
}

/** Fuerza la expiración inmediata de una clave — llamar tras mutar los datos que cachea. */
function cache_invalidate(string $key): void
{
    if (function_exists('apcu_delete')) {
        apcu_delete($key);
    }
}
