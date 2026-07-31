<?php
declare(strict_types=1);

/**
 * ACADEP UTILITY: Generador de Manifiesto desde Archivo ZIP Extendido
 * Escanea el interior del ZIP en la USB E: sin descomprimir en disco local.
 */

$zipFilePath = 'E:/RESPALDO CABOVISION.zip'; 
$outputPath = __DIR__ . '/articles_manifest.json';

if (!is_file($zipFilePath)) {
    die("Error de raíz: No se encuentra el archivo comprimido en '{$zipFilePath}'. Verifica la ruta.\n");
}

echo "Abriendo el búnker multimedia ZIP en la unidad E:...\n";

$zip = new ZipArchive();
if ($zip->open($zipFilePath) !== true) {
    die("Fallo crítico: No se pudo abrir o leer el archivo ZIP.\n");
}

$images = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $filename = $stat['name'];
    
    // Filtrar extensiones válidas bajo la Regla de ORO
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['webp', 'jpg', 'jpeg', 'png'], true)) {
        // Mapeamos la ruta virtual interna que el publicador procesará
        $images[] = 'zip://' . $zipFilePath . '#' . $filename;
    }
}
$zip->close();

$manifestData = [
    [
        "tenant_id"    => "1002",
        "article_id"   => "migracion-masiva-zip",
        "published_at" => "2026-07-19",
        "images"       => $images
    ]
];

file_put_contents($outputPath, json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "✓ ¡Manifiesto automatizado creado con éxito! Indexadas " . count($images) . " imágenes.\n";