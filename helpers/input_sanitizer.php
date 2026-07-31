<?php

declare(strict_types=1);

// =============================================================================
// helpers/input_sanitizer.php — Sanitización de Entrada (AXON_DCD Security Standard)
// Mandamiento #2: Seguridad Nivel Militar. Usar SIEMPRE sobre input externo
// (body JSON, $_GET, $_POST, headers) antes de procesarlo o persistirlo.
// =============================================================================

/**
 * Sanitiza una cadena de texto: recorta espacios, elimina etiquetas HTML/JS
 * y normaliza caracteres de control. No reemplaza el uso de Prepared
 * Statements — esto previene XSS al momento de RENDERIZAR, no SQLi.
 */
function sanitize_string(string $value, int $maxLength = 255): string
{
    $value = trim($value);
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

    return mb_substr($value, 0, $maxLength);
}

/**
 * Sanitiza un entero proveniente de input externo. Retorna $default si el
 * valor no es numérico.
 */
function sanitize_int(mixed $value, int $default = 0): int
{
    if (!is_numeric($value)) {
        return $default;
    }

    return (int) $value;
}

/**
 * Sanitiza un correo electrónico: trim + lowercase + filtro de caracteres
 * inválidos vía filter_var. Retorna cadena vacía si el formato es inválido.
 */
function sanitize_email(string $value): string
{
    $value = strtolower(trim($value));
    $clean = filter_var($value, FILTER_SANITIZE_EMAIL);

    return $clean !== false ? $clean : '';
}

/**
 * Sanitiza un array asociativo plano (sin anidamiento) aplicando
 * sanitize_string() a cada valor string. Útil para payloads JSON simples.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function sanitize_array(array $payload, int $maxLength = 255): array
{
    $clean = [];
    foreach ($payload as $key => $value) {
        $clean[$key] = is_string($value) ? sanitize_string($value, $maxLength) : $value;
    }

    return $clean;
}

/**
 * Repara mojibake real confirmado en `categories.name` (ej. "Pol├¡tica" en
 * vez de "Política"): bytes UTF-8 que en algún punto de la migración se
 * decodificaron como CP437/DOS y se volvieron a guardar como UTF-8. El
 * round-trip UTF-8 -> CP437 revierte exactamente esa corrupción — probado
 * contra los valores reales de la BD (confirmado: "Pol├¡tica" -> "Política").
 * Solo se aplica si el marcador "├" (U+251C, el síntoma real observado) está
 * presente — cadenas ya limpias con acentos correctos (ej. "Difusión") no lo
 * contienen y se devuelven intactas, para no corromper texto que sí está bien.
 */
function repair_known_mojibake(string $value): string
{
    if (!str_contains($value, "\u{251C}")) {
        return $value;
    }

    $repaired = @iconv('UTF-8', 'CP437', $value);

    return $repaired !== false ? $repaired : $value;
}

/**
 * Sanitiza HTML editorial con whitelist estricta de etiquetas — permite
 * formato básico de nota (párrafos, listas, enlaces, imágenes, subtítulos)
 * y elimina atributos de evento (`on*`) y esquemas `javascript:`. No
 * reemplaza Prepared Statements (SQLi) — esto cubre XSS al renderizar
 * `articles.content` sin escapar (es HTML real, por diseño).
 *
 * 2026-07-21: consolidado aquí — antes vivía duplicado como función privada
 * en scripts/migration_pump.php Y le faltaba por completo a
 * api/articles_create.php (el editor guardaba el HTML crudo sin ningún
 * filtro). Mandamiento #10: un solo criterio válido por concepto.
 */
function sanitize_article_html(string $html): string
{
    $allowedTags = '<p><br><strong><em><b><i><ul><ol><li><a><img><h2><h3><blockquote>';
    $clean       = strip_tags($html, $allowedTags);
    $clean       = preg_replace('/\s+on\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $clean) ?? $clean;
    $clean       = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1=$2#$2', $clean) ?? $clean;

    return $clean;
}

/** Genera un slug ASCII (a-z0-9-) a partir de texto con acentos/UTF-8. Consolidado, antes duplicado. */
function slugify_text(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

    return trim($text, '-');
}
