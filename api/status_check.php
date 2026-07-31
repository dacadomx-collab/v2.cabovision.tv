<?php

declare(strict_types=1);

// =============================================================================
// api/status_check.php — Triple Handshake (Landing Test)
// Diagnóstico de arranque: A) Permisos de archivos, B) Transacción CRUD de BD,
// C) Banner SMTP en puerto 465. Mandamiento #11: Arranque Blindado.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/asfl_logger.php';

asfl_log('REQUEST', ['endpoint' => 'status_check.php']);

$checks = [
    'filesystem' => checkFilesystem(),
    'database'   => checkDatabase(),
    'smtp'       => checkSmtp(),
];

$allOk = !in_array(false, array_column($checks, 'ok'), true);

asfl_log('RESPONSE', ['endpoint' => 'status_check.php', 'all_ok' => $allOk, 'checks' => $checks]);

send_json_response(
    $allOk ? 'success' : 'error',
    $allOk ? 'Triple Handshake completo. Todos los sistemas operativos.' : 'Uno o más checks fallaron.',
    $checks
);

// -----------------------------------------------------------------------------
// CHECK A: Permisos de archivos (755 directorios, 644 archivos)
// -----------------------------------------------------------------------------

function checkFilesystem(): array
{
    $root  = dirname(__DIR__);
    $perms = substr(sprintf('%o', fileperms($root)), -4);

    return [
        'ok'      => is_readable($root) && is_writable($root . '/logs'),
        'detalle' => "Permisos raíz: {$perms}. logs/ escribible: " . (is_writable($root . '/logs') ? 'sí' : 'no'),
    ];
}

// -----------------------------------------------------------------------------
// CHECK B: Transacción segura CRUD contra la Base de Datos Centralizada
// remota (Regla Cero — JAMÁS una BD local, ver api/conexion.php).
// -----------------------------------------------------------------------------

function checkDatabase(): array
{
    try {
        $database = new Database();
        $pdo      = $database->getConnection();

        $pdo->beginTransaction();
        $pdo->exec('CREATE TEMPORARY TABLE IF NOT EXISTS axon_status_test (id INT)');
        $pdo->exec('INSERT INTO axon_status_test (id) VALUES (1)');
        $stmt = $pdo->query('SELECT COUNT(*) FROM axon_status_test');
        $count = (int) $stmt->fetchColumn();
        $pdo->exec('DELETE FROM axon_status_test WHERE id = 1');
        $pdo->rollBack();

        return [
            'ok'      => $count === 1,
            'detalle' => 'CRUD transaccional verificado contra la BD remota centralizada.',
        ];
    } catch (\Throwable $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] [status_check::checkDatabase] ' . $e->getMessage());
        return ['ok' => false, 'detalle' => 'No se pudo verificar la base de datos remota centralizada.'];
    }
}

// -----------------------------------------------------------------------------
// CHECK C: fsockopen al puerto 465 — validar banner 220 del SMTP
// -----------------------------------------------------------------------------

function checkSmtp(): array
{
    $env  = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
    $host = (string) ($env['SMTP_HOST'] ?? '');

    if ($host === '') {
        return ['ok' => false, 'detalle' => 'SMTP_HOST no configurado en .env.'];
    }

    $socket = @fsockopen($host, 465, $errno, $errstr, 5);
    if ($socket === false) {
        return ['ok' => false, 'detalle' => "No se pudo conectar a {$host}:465 ({$errstr})."];
    }

    $banner = fgets($socket, 256) ?: '';
    fclose($socket);

    return [
        'ok'      => str_starts_with($banner, '220'),
        'detalle' => "Banner SMTP recibido: " . trim($banner),
    ];
}
