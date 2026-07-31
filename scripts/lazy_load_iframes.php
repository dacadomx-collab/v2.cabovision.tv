<?php

declare(strict_types=1);

// =============================================================================
// scripts/lazy_load_iframes.php — Agrega loading="lazy" a <iframe> embebidos
// en articles.content (embeds de YouTube reales, verificado: 878 artículos,
// 644 sin loading= todavía) que no lo tengan ya. Mismo patrón de seguridad
// que rewrite_content_images.php: dry-run por defecto, --commit para escribir.
//
// Uso:
//   php scripts/lazy_load_iframes.php                  (reporte, no escribe nada)
//   php scripts/lazy_load_iframes.php --commit --limit=1000
// =============================================================================

require_once __DIR__ . '/../api/conexion.php';

$commit = in_array('--commit', $argv, true);
$limit  = PHP_INT_MAX;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$database = new Database();
$pdo      = $database->getConnection();

$stmt = $pdo->query("SELECT id, content FROM articles WHERE content LIKE '%<iframe%'");
$updateStmt = $pdo->prepare('UPDATE articles SET content = :content WHERE id = :id');

$totalArticles = 0;
$totalIframes   = 0;
$alreadyLazy    = 0;
$updated        = 0;

foreach ($stmt as $row) {
    if ($updated >= $limit) {
        break;
    }
    $totalArticles++;
    $content = (string) $row['content'];

    if (!preg_match_all('#<iframe\b[^>]*>#i', $content, $matches)) {
        continue;
    }

    $newContent = $content;
    $touched    = false;

    foreach ($matches[0] as $iframeTag) {
        $totalIframes++;
        if (str_contains($iframeTag, 'loading=')) {
            $alreadyLazy++;
            continue;
        }
        $newTag = preg_replace('#<iframe\s#i', '<iframe loading="lazy" ', $iframeTag, 1) ?? $iframeTag;
        if ($newTag !== $iframeTag) {
            $newContent = str_replace($iframeTag, $newTag, $newContent);
            $touched = true;
        }
    }

    if ($touched) {
        $updated++;
        if ($commit) {
            $updateStmt->execute([':content' => $newContent, ':id' => (int) $row['id']]);
        }
    }
}

echo "=== Lazy loading de <iframe> embebidos ===\n";
echo 'Modo: ' . ($commit ? "COMMIT (limit={$limit})" : 'DRY-RUN (solo reporte, nada escrito)') . "\n\n";
echo "Artículos con <iframe> revisados: {$totalArticles}\n";
echo "Etiquetas <iframe> encontradas: {$totalIframes}\n";
echo "Ya tenían loading=: {$alreadyLazy}\n";
echo "Artículos actualizados" . ($commit ? '' : ' (simulado)') . ": {$updated}\n";
