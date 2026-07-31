<?php

declare(strict_types=1);

// =============================================================================
// scripts/cold_storage_migration.php — Cron nocturno: empuja a Cold Storage
// (servidor local ACADEP, vía túnel) todo lo que superó los 2 años en Hot.
// Uso: php scripts/cold_storage_migration.php
//
// 2026-07-18: la subida HTTP ahora pasa por helpers/ColdStorageClient.php
// (antes tenía su propio curl inline duplicado, con el mismo bug de header
// que el resto del código ya corregido — X-Bridge-Key). El path que se sube
// es el `relative_path` YA asignado en `media_assets` cuando el archivo
// entró a Hot Storage — no se recalcula con buildClusteredPath(), eso es
// solo para archivos que todavía no tienen una ruta asignada.
// =============================================================================

require_once __DIR__ . '/../api/conexion.php';
require_once __DIR__ . '/../helpers/ColdStorageClient.php';

$database = new Database();
$pdo      = $database->getConnection();
$client   = new ColdStorageClient();

$cutoff = (new DateTime())->modify('-2 years')->format('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT id, relative_path FROM media_assets WHERE storage_tier = 'hot' AND captured_at < :cutoff"
);
$stmt->execute([':cutoff' => $cutoff]);

$migrated  = 0;
$failed    = 0;
$conflicts = 0;

foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $localPath = dirname(__DIR__) . '/' . $row['relative_path'];
    if (!is_file($localPath)) {
        continue;
    }

    $result = $client->uploadToColdStorage($row['relative_path'], $localPath);

    if ($result['conflict']) {
        // 409: la ruta ya existe en Cold — NO se borra el original de Hot,
        // se preserva para revisión manual en vez de perder la única copia.
        $conflicts++;
        continue;
    }

    if ($result['success']) {
        unlink($localPath); // libera espacio del hosting solo tras confirmar éxito remoto
        $upd = $pdo->prepare(
            "UPDATE media_assets SET storage_tier='cold', migrated_to_cold_at=NOW() WHERE id = :id"
        );
        $upd->execute([':id' => $row['id']]);
        $migrated++;
    } else {
        error_log('[' . date('Y-m-d H:i:s') . '] [cold_storage_migration] Fallo al migrar (HTTP ' . $result['httpCode'] . '): ' . $row['relative_path']);
        $failed++;
    }
}

echo "Migrados: {$migrated} | Fallidos: {$failed} | Conflictos 409: {$conflicts}\n";
