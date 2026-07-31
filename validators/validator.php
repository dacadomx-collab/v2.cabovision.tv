<?php

declare(strict_types=1);

// =============================================================================
// validators/validator.php — Validación de Formato (AXON_DCD Security Standard)
// Mandamiento #2: Validar SIEMPRE la forma del dato antes de usarlo en lógica
// de negocio o consultas. Complementa a helpers/input_sanitizer.php (que
// limpia) — este archivo solo verifica true/false, no transforma datos.
// =============================================================================

/**
 * Valida formato de correo electrónico (RFC 5322 simplificado vía filter_var).
 */
function is_valid_email(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valida que la cadena sea un FQDN (Fully Qualified Domain Name) bien
 * formado: labels alfanuméricos separados por puntos, TLD de al menos
 * 2 caracteres alfabéticos. Útil para validar ALLOWED_ORIGINS, dominios
 * de SMTP, o entradas de usuario que esperan un dominio.
 */
function is_valid_fqdn(string $value): bool
{
    if ($value === '' || strlen($value) > 253) {
        return false;
    }

    $pattern = '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/';

    return (bool) preg_match($pattern, $value);
}

/**
 * Valida que la cadena sea una URL bien formada (http/https).
 */
function is_valid_url(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_URL) !== false
        && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'));
}

/**
 * Valida que un entero esté dentro de un rango permitido (inclusivo).
 */
function is_valid_int_range(mixed $value, int $min, int $max): bool
{
    if (!is_numeric($value)) {
        return false;
    }

    $intValue = (int) $value;

    return $intValue >= $min && $intValue <= $max;
}

/**
 * Valida fuerza mínima de contraseña: 8+ caracteres, al menos una mayúscula,
 * una minúscula y un dígito. Ajustar según política de seguridad del proyecto.
 */
function is_valid_password_strength(string $value): bool
{
    return (bool) preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,}$/', $value);
}
