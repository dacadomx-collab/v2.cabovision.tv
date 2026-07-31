<?php

declare(strict_types=1);

// =============================================================================
// helpers/asfl_logger.php — AXON Synaptic Flow Ledger (ASFL)
// Telemetría de I/O en caliente para depuración en entorno LOCAL únicamente.
// Mandamiento #13: Aislamiento de Entornos — JAMÁS escribe en producción.
//
// Uso:
//   require_once __DIR__ . '/../helpers/asfl_logger.php';
//   asfl_log('REQUEST', ['endpoint' => 'auth_login.php', 'method' => 'POST']);
//   asfl_log('RESPONSE', ['status' => 'success', 'http_code' => 200]);
// =============================================================================

/**
 * Registra un evento de I/O en el ledger local (logs/asfl_ledger.log).
 * No-op silencioso si APP_ENV no es 'local' — evita fugas de datos sensibles
 * y overhead de escritura a disco en staging/producción.
 *
 * @param array<string,mixed> $context
 */
function asfl_log(string $direction, array $context = []): void
{
    $env = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
    $appEnv = (string) ($env['APP_ENV'] ?? '');

    if ($appEnv !== 'local') {
        return;
    }

    $entry = [
        'timestamp' => date('Y-m-d H:i:s.v'),
        'direction' => $direction,
        'context'   => $context,
    ];

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $ledgerPath = dirname(__DIR__) . '/logs/asfl_ledger.log';

    @file_put_contents($ledgerPath, $line, FILE_APPEND | LOCK_EX);
}
