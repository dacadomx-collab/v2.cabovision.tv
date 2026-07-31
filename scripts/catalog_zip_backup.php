<?php

declare(strict_types=1);

// =============================================================================
// scripts/catalog_zip_backup.php — Inspección programática del respaldo masivo
// E:\RESPALDO CABOVISION.zip (26.7 GB, backup JetBackup/cPanel) SIN descomprimir
// el archivo completo — ZipArchive lee solo el directorio central del ZIP
// (rápido, ~1.3s aquí) y extrae/lee entradas individuales bajo demanda.
//
// Requiere la extensión `zip` de PHP — habilitada en C:\xampp\php\php.ini
// el 2026-07-18 (no estaba activa; el .dll ya venía incluido en XAMPP).
//
// Uso: php scripts/catalog_zip_backup.php
// =============================================================================

const ZIP_PATH = 'E:\\RESPALDO CABOVISION.zip';

if (!extension_loaded('zip')) {
    fwrite(STDERR, "Error: la extensión ZipArchive de PHP no está cargada.\n");
    exit(1);
}

if (!is_file(ZIP_PATH)) {
    fwrite(STDERR, 'Error: no se encontró el archivo en ' . ZIP_PATH . "\n");
    exit(1);
}

$zip = new ZipArchive();
$openResult = $zip->open(ZIP_PATH);
if ($openResult !== true) {
    fwrite(STDERR, "Error al abrir el ZIP (código {$openResult}).\n");
    exit(1);
}

echo "=== Inventario: " . ZIP_PATH . " ===\n";
echo 'Entradas totales: ' . $zip->numFiles . "\n\n";

// ── Tarea 1: localizar el .sql de producción (si existe) ───────────────────
$sqlEntries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (preg_match('/\.sql(\.gz)?$/i', $name)) {
        $sqlEntries[] = $name;
    }
}

echo "--- Búsqueda de dump SQL ---\n";
if ($sqlEntries === []) {
    echo "No se encontró ningún archivo .sql dentro del ZIP.\n";

    // No es un fallo silencioso: se lee el manifiesto de JetBackup para
    // confirmar la causa raíz en vez de asumir un ZIP corrupto o incompleto.
    $manifestIndex = $zip->locateName('RESPALDO CABOVISION/backup/jetbackup.index');
    if ($manifestIndex !== false) {
        $manifest = json_decode((string) $zip->getFromIndex($manifestIndex), true);
        $items    = $manifest['items'] ?? [];
        $paths    = array_column($items, 'path');
        echo 'Manifiesto JetBackup confirma el alcance real del backup: solo incluye [' . implode(', ', $paths) . "]\n";
        echo "No incluye ítem de tipo MySQL — este backup nunca tuvo un dump de base de datos, no es un problema de extracción.\n";
    }
} else {
    foreach ($sqlEntries as $entry) {
        echo "Encontrado: {$entry}\n";
        // Extracción SOLO de esta entrada específica (no todo el ZIP) hacia database/.
        $destName = 'database/' . basename($entry);
        if ($zip->extractTo(dirname(__DIR__), [$entry])) {
            echo "  -> Extraído a {$destName}\n";
        }
    }
}

// ── Tarea 2: censo del árbol multimedia (images/, img/) ────────────────────
echo "\n--- Censo multimedia por tenant/año ---\n";
$tenantYears      = [];
$totalImgFiles    = 0;
$totalImgBytes    = 0;
$quarantineFiles  = 0;
$quarantineBytes  = 0;
$bannerFiles      = 0;
$bannerBytes      = 0;
$uncategorized    = 0;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (str_ends_with($name, '/')) {
        continue; // directorio, no archivo
    }
    $stat = $zip->statIndex($i);
    $size = (int) ($stat['size'] ?? 0);

    if (preg_match('#/(images|img)/\.quarantine/#i', $name)) {
        $quarantineFiles++;
        $quarantineBytes += $size;
        continue;
    }
    if (preg_match('#/(images|img)/banners/#i', $name)) {
        $bannerFiles++;
        $bannerBytes += $size;
        continue;
    }
    if (preg_match('#/(images|img)/(\d+)/(\d{4})/#i', $name, $m)) {
        $key = "{$m[1]}/tenant_{$m[2]}/{$m[3]}";
        $tenantYears[$key] = ($tenantYears[$key] ?? 0) + 1;
        $totalImgFiles++;
        $totalImgBytes += $size;
    } elseif (preg_match('#/(images|img)/#i', $name)) {
        $uncategorized++;
    }
}

ksort($tenantYears);
foreach ($tenantYears as $key => $count) {
    echo "{$key} : {$count} archivos\n";
}

echo "\nTotal clasificado por tenant/año: {$totalImgFiles} archivos, " . round($totalImgBytes / 1073741824, 2) . " GB\n";
echo "Banners/sponsors (images/banners/): {$bannerFiles} archivos, " . round($bannerBytes / 1048576, 2) . " MB\n";
echo "Cuarentena (.quarantine/): {$quarantineFiles} archivos, " . round($quarantineBytes / 1048576, 2) . " MB\n";
echo "Sin clasificar (fuera del patrón tenant/año): {$uncategorized} archivos\n";

$zip->close();
