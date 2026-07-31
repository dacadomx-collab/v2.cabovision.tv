<?php
declare(strict_types=1);

/**
 * validators/proxy_tunnel_validator.php  v1.0
 *
 * PATRÓN: Túnel Proxy Seguro (Gateway) — activo genérico de la factoría
 * {{HOLDING_NAME}}, promovido desde un proyecto productivo del holding.
 * Valida en el servidor central cada petición entrante desde un puente
 * desplegado en el hosting de un cliente/prospecto:
 *   1. Firma HMAC SHA-256 (X-Signature) contra el sharedSecret del tenant.
 *   2. Anti-Replay (X-Timestamp) — rechaza desviación mayor a 300s (Clock Skew).
 *   3. Nonce atómico (X-Nonce) — rechaza reintento de un mismo valor dentro de 600s.
 *
 * DEPENDENCIA DE INFRAESTRUCTURA — NOTA DE COMPATIBILIDAD:
 *   Este validador usa ÚNICAMENTE APCu para el almacenamiento de Nonces (no
 *   Redis). Antes de activar este módulo en un hosting nuevo, confirma que
 *   la extensión `apcu` está disponible en [HOST_DE_DESPLIEGUE]. Si no lo
 *   está, el validador degrada de forma segura NO permisiva: rechaza TODA
 *   petición con error 503 en vez de omitir la validación de Nonce. Migrar
 *   a Redis requiere autorización explícita del Arquitecto del proyecto
 *   (alta de dependencia de infraestructura nueva — Mandamiento 9).
 */

final class ProxyTunnelValidator
{
    private const CLOCK_SKEW_SECONDS = 300;
    private const NONCE_TTL_SECONDS  = 600;
    private const APCU_NONCE_PREFIX  = 'proxy_tunnel_nonce_';

    /**
     * Valida firma HMAC + anti-replay + nonce de una petición entrante del
     * Túnel Proxy. Lanza RuntimeException con mensaje apto para log interno
     * (nunca debe exponerse literal al cliente).
     *
     * @param string $rawBody       Cuerpo crudo de la petición (php://input), tal cual llegó.
     * @param string $signature     Valor de la cabecera X-Signature.
     * @param string $timestamp     Valor de la cabecera X-Timestamp (epoch segundos, string).
     * @param string $nonce         Valor de la cabecera X-Nonce (UUID v4 o hex aleatorio).
     * @param string $sharedSecret  Secreto del tenant (leído desde BD/`.env`, nunca hardcodeado).
     *
     * @throws \RuntimeException  Motivo interno del rechazo (ver mensajes constantes abajo).
     */
    public static function validate(
        string $rawBody,
        string $signature,
        string $timestamp,
        string $nonce,
        string $sharedSecret
    ): void {
        if (!extension_loaded('apcu') || !ini_get('apc.enabled')) {
            throw new \RuntimeException('APCU_NO_DISPONIBLE: extensión de memoria volátil ausente en el gateway.');
        }

        if ($signature === '' || $timestamp === '' || $nonce === '' || $sharedSecret === '') {
            throw new \RuntimeException('CABECERAS_INCOMPLETAS: X-Signature, X-Timestamp o X-Nonce ausentes.');
        }

        self::validateTimestamp($timestamp);
        self::validateSignature($rawBody, $timestamp, $nonce, $signature, $sharedSecret);
        self::validateNonce($nonce);
    }

    private static function validateTimestamp(string $timestamp): void
    {
        if (!ctype_digit($timestamp)) {
            throw new \RuntimeException('TIMESTAMP_INVALIDO: X-Timestamp no es un epoch numérico.');
        }

        $skew = abs(time() - (int) $timestamp);
        if ($skew > self::CLOCK_SKEW_SECONDS) {
            throw new \RuntimeException("CLOCK_SKEW_EXCEDIDO: desviación de {$skew}s (límite " . self::CLOCK_SKEW_SECONDS . 's).');
        }
    }

    private static function validateSignature(
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $signature,
        string $sharedSecret
    ): void {
        // Contrato de firma: HMAC-SHA256(timestamp . "\n" . nonce . "\n" . rawBody, sharedSecret)
        // El mismo orden DEBE reproducirse en el puente desplegado en el hosting del cliente.
        $payload  = $timestamp . "\n" . $nonce . "\n" . $rawBody;
        $expected = hash_hmac('sha256', $payload, $sharedSecret);

        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('FIRMA_INVALIDA: X-Signature no coincide con el HMAC esperado.');
        }
    }

    private static function validateNonce(string $nonce): void
    {
        $key = self::APCU_NONCE_PREFIX . hash('sha256', $nonce);

        // apcu_add() es atómico: falla si la llave ya existe — evita condiciones
        // de carrera entre workers PHP-FPM concurrentes validando el mismo Nonce.
        $stored = apcu_add($key, 1, self::NONCE_TTL_SECONDS);

        if ($stored === false) {
            throw new \RuntimeException('NONCE_DUPLICADO: solicitud repetida detectada (posible replay).');
        }
    }
}
