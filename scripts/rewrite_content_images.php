<?php

declare(strict_types=1);

// =============================================================================
// scripts/rewrite_content_images.php — Reescribe <img src="/images/..."> DENTRO
// de articles.content para que apunten a api/media_bridge.php, igual que ya
// hace articles.thumbnail. Reutiliza la misma lógica de fecha/ruta limpia que
// scripts/link_articles_media.php (extractDateFromPath/buildCleanPath) para
// no duplicar reglas divergentes — Mandamiento #10 (un solo criterio válido).
//
// Solo reescribe una <img> si su ruta limpia calculada YA tiene una fila real
// en media_assets con file_hash NOT NULL (subida confirmada) — nunca apunta
// al bridge una imagen que no se sabe si existe físicamente. Las que no
// califican se dejan intactas (siguen rotas como estaban, no se empeora nada).
//
// MODO POR DEFECTO: solo lectura / reporte (dry-run). Nada se escribe en
// articles.content hasta correr con --commit. --limit=N acota cuántos
// artículos se actualizan en una corrida.
//
// Uso:
//   php scripts/rewrite_content_images.php                  (reporte, no escribe nada)
//   php scripts/rewrite_content_images.php --commit --limit=200
// =============================================================================

require_once __DIR__ . '/../api/conexion.php';
require_once __DIR__ . '/../helpers/media_path_resolver.php';

const MEDIA_TENANT_ID = 1002; // Misma constante que ColdStorageClient/link_articles_media.php

$commit = in_array('--commit', $argv, true);
$limit  = PHP_INT_MAX;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$database = new Database();
$pdo      = $database->getConnection();

$stmt = $pdo->query("SELECT id, content FROM articles WHERE content LIKE '%<img%'");

$updateStmt = $pdo->prepare('UPDATE articles SET content = :content WHERE id = :id');

$totalArticles   = 0;
$articlesChanged = 0;
$totalImgTags    = 0;
$replacedImgTags = 0;
$updatedInDb     = 0;

$mediaLookupCache = [];

foreach ($stmt as $row) {
    if ($articlesChanged >= $limit) {
        break;
    }
    $totalArticles++;
    $articleId = (int) $row['id'];
    $content   = (string) $row['content'];

    if (!preg_match_all('#<img\s[^>]*src="(/images/[^"]+\.(?:jpg|jpeg|png|gif|webp))"[^>]*>#i', $content, $matches, PREG_SET_ORDER)) {
        continue;
    }

    $newContent   = $content;
    $articleTouched = false;

    foreach ($matches as $match) {
        $totalImgTags++;
        $fullImgTag = $match[0];
        $imgPath    = $match[1];

        $capturedAt = extract_date_from_media_path($imgPath);
        if ($capturedAt === null) {
            continue;
        }
        $cleanPath = build_clean_media_path(MEDIA_TENANT_ID, $imgPath, $capturedAt);

        if (!array_key_exists($cleanPath, $mediaLookupCache)) {
            $check = $pdo->prepare('SELECT 1 FROM media_assets WHERE relative_path = :p AND file_hash IS NOT NULL LIMIT 1');
            $check->execute([':p' => $cleanPath]);
            $mediaLookupCache[$cleanPath] = $check->fetchColumn() !== false;
        }

        if (!$mediaLookupCache[$cleanPath]) {
            continue; // no confirmado como subido de verdad — se deja intacto
        }

        $bridgeUrl = '/CaboVision.tv/api/media_bridge.php?path=' . rawurlencode($cleanPath);
        $newImgTag = str_replace($imgPath, $bridgeUrl, $fullImgTag);
        if (!str_contains($newImgTag, 'loading=')) {
            $newImgTag = preg_replace('#<img\s#i', '<img loading="lazy" ', $newImgTag, 1) ?? $newImgTag;
        }

        if ($newImgTag !== $fullImgTag) {
            $newContent = str_replace($fullImgTag, $newImgTag, $newContent);
            $replacedImgTags++;
            $articleTouched = true;
        }
    }

    if ($articleTouched) {
        $articlesChanged++;
        if ($commit) {
            $updateStmt->execute([':content' => $newContent, ':id' => $articleId]);
            $updatedInDb++;
        }
    }
}

echo "=== Reescritura de <img> embebidas en articles.content ===\n";
echo 'Modo: ' . ($commit ? "COMMIT (limit={$limit})" : 'DRY-RUN (solo reporte, nada escrito)') . "\n\n";
echo "Artículos con <img> en content revisados: {$totalArticles}\n";
echo "Etiquetas <img> encontradas: {$totalImgTags}\n";
echo "Etiquetas <img> reescritas (con subida confirmada real): {$replacedImgTags}\n";
echo "Artículos con al menos 1 reescritura: {$articlesChanged}\n";
if ($commit) {
    echo "Artículos actualizados en la BD: {$updatedInDb}\n";
}
