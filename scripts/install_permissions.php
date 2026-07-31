<?php

declare(strict_types=1);

// =============================================================================
// scripts/install_permissions.php — Audita/corrige permisos 755 (dirs) / 644 (files)
// Uso: php scripts/install_permissions.php
//
// NOTA: en Windows/XAMPP chmod() es mayormente un no-op (NTFS no usa el
// modelo POSIX). Este script es funcional y necesario en el servidor de
// producción Linux; en local solo reporta el estado sin fallar.
// =============================================================================

$root = dirname(__DIR__);

$skipDirs = ['.git', 'node_modules', 'vendor'];

$targetDirPerm  = 0755;
$targetFilePerm = 0644;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$fixed  = 0;
$failed = 0;

foreach ($iterator as $path => $fileInfo) {
    foreach ($skipDirs as $skip) {
        if (str_contains($path, DIRECTORY_SEPARATOR . $skip . DIRECTORY_SEPARATOR)) {
            continue 2;
        }
    }

    $targetPerm = $fileInfo->isDir() ? $targetDirPerm : $targetFilePerm;

    if (@chmod($path, $targetPerm)) {
        $fixed++;
    } else {
        $failed++;
    }
}

echo "✓ Permisos procesados: {$fixed} correctos/aplicados.\n";

if ($failed > 0) {
    echo "⚠ {$failed} rutas no se pudieron modificar (normal en Windows/NTFS).\n";
}

echo "→ En el servidor de producción (Linux), re-ejecutar este script tras cada deploy garantiza 755/644.\n";
