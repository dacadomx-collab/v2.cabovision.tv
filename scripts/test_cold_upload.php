<?php

declare(strict_types=1);

// =============================================================================
// scripts/test_cold_upload.php — Smoke test manual del puente Cold Storage.
// Sube UNA imagen real de prueba contra el túnel configurado en ACADEP_BRIDGE_URL
// para verificar: flujo binario crudo, header X-Bridge-Key, y manejo de 409.
// No borra ni modifica nada local — solo lee un asset existente y hace el POST.
// Uso: php scripts/test_cold_upload.php
// =============================================================================

require_once __DIR__ . '/../helpers/ColdStorageClient.php';

$sourceImage = dirname(__DIR__) . '/assets/img/logocabovis_glow.png';

if (!is_file($sourceImage)) {
    fwrite(STDERR, "Error: imagen de prueba no encontrada en {$sourceImage}\n");
    exit(1);
}

$client       = new ColdStorageClient();
$relativePath = $client->buildClusteredPath('smoke_test_' . date('His') . '.png');

echo "Smoke test — Cold Storage Bridge\n";
echo "Ruta destino: {$relativePath}\n";
echo "Origen local: {$sourceImage} (" . filesize($sourceImage) . " bytes)\n";
echo "Enviando...\n";

$result = $client->uploadToColdStorage($relativePath, $sourceImage);

echo "--- Resultado ---\n";
echo 'HTTP code: ' . $result['httpCode'] . "\n";
echo 'Conflicto 409: ' . ($result['conflict'] ? 'SÍ' : 'no') . "\n";
echo 'Éxito: ' . ($result['success'] ? 'SÍ' : 'NO') . "\n";
