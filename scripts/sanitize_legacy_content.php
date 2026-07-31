<?php

declare(strict_types=1);

// =============================================================================
// scripts/sanitize_legacy_content.php — Limpieza de articles.content heredado
// del CMS Joomla original, dos problemas reales resueltos en una sola pasada
// (mismo campo, una sola UPDATE por artículo):
//
//   1. DEDUPLICACIÓN DE IMAGEN PRINCIPAL: articulo.php ya muestra el
//      thumbnail/image como foto de portada — si la PRIMERA etiqueta <img> de
//      `content` es la misma foto (mismo nombre de archivo, comparación por
//      basename porque una puede venir como ruta legacy "/images/..." y la
//      otra ya reescrita a "api/media_bridge.php?path=..."), se ve duplicada
//      arriba del artículo. Se elimina esa <img> SOLO si está al inicio real
//      del contenido (whitespace inicial permitido, nada más) — nunca una que
//      coincida más adelante en el texto por casualidad.
//
//   2. PURGA DE ESTILOS/CLASES HEREDADAS: el CMS original (o su editor WYSIWYG)
//      inyectó style="..."/class="..."/bgcolor/color/align/face/size directo
//      en <p>/<span>/<div> del cuerpo editorial — rompe el Slate Dark Theme
//      (fondos y textos hardcodeados que no responden a data-theme="dark").
//      Se eliminan TODOS esos atributos vía DOMDocument (no regex: HTML real
//      de 2016-2020 con anidamiento irregular, regex es frágil para esto) —
//      el contenido no usa NINGUNA clase propia de este proyecto, así que
//      "eliminar todas las class" no tiene falso positivo posible aquí.
//
// MODO POR DEFECTO: solo lectura / reporte (dry-run). Nada se escribe en
// articles.content hasta correr con --commit. --limit=N acota el lote.
// --sample=N (solo en dry-run) imprime el diff de los primeros N artículos
// afectados, para auditar el resultado real antes de comprometer nada.
//
// Uso:
//   php scripts/sanitize_legacy_content.php --sample=5
//   php scripts/sanitize_legacy_content.php --commit --limit=500
// =============================================================================

require_once __DIR__ . '/../api/conexion.php';

$commit = in_array('--commit', $argv, true);
$limit  = PHP_INT_MAX;
$sample = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
    if (preg_match('/^--sample=(\d+)$/', $arg, $m)) {
        $sample = (int) $m[1];
    }
}

/**
 * Extrae la <img> inicial de $html SOLO si está al principio real del
 * contenido — permite el envoltorio típico de Joomla `<p style="text-align:
 * center;"><img .../></p>` (confirmado real: el patrón exacto usado en las
 * ~10,674 notas de este respaldo para centrar la foto de apertura), pero
 * NUNCA una <img> que aparezca más adelante en el cuerpo del texto.
 * Devuelve [bloqueCompletoARemover, basenameLowercase] o null.
 */
function extractLeadingImg(string $html): ?array
{
    if (!preg_match('#^\s*(?:<p[^>]*>\s*)?(<img\b[^>]*\bsrc\s*=\s*"([^"]+)"[^>]*/?>)\s*(?:</p>)?\s*#i', $html, $m)) {
        return null;
    }
    $src = $m[2];
    $decodedPath = $src;
    if (preg_match('#[?&]path=([^&"]+)#i', $src, $pm)) {
        $decodedPath = rawurldecode($pm[1]);
    }

    return [$m[0], strtolower(basename($decodedPath))];
}

/**
 * Elimina style/class/atributos de presentación legacy de TODOS los
 * elementos de $html vía DOMDocument (nunca regex para esto — HTML real
 * anidado de forma irregular). Envuelve con "<?xml encoding=utf-8?>" para que
 * libxml no interprete el HTML como ISO-8859-1 y corrompa acentos/UTF-8.
 */
function stripLegacyPresentation(string $html): string
{
    if (trim($html) === '') {
        return $html;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $wrapped = '<?xml encoding="utf-8"?><div id="cv-sanitize-root">' . $html . '</div>';
    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    foreach (['style', 'class', 'bgcolor', 'color', 'align', 'face', 'size'] as $attr) {
        foreach (iterator_to_array($xpath->query("//*[@{$attr}]")) as $node) {
            /** @var DOMElement $node */
            $node->removeAttribute($attr);
        }
    }

    $root = null;
    foreach ($dom->getElementsByTagName('div') as $div) {
        if ($div->getAttribute('id') === 'cv-sanitize-root') {
            $root = $div;
            break;
        }
    }
    if ($root === null) {
        return $html; // defensivo: si el wrapper no se pudo ubicar, no se toca el contenido
    }

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }

    return $out;
}

$database = new Database();
$pdo      = $database->getConnection();

$stmt = $pdo->query(
    "SELECT id, content, thumbnail, image FROM articles
     WHERE content LIKE '%<img%' OR content LIKE '% style=%' OR content LIKE '% class=%'
        OR content LIKE '% bgcolor=%' OR content LIKE '% color=%' OR content LIKE '% align=%'
        OR content LIKE '% face=%' OR content LIKE '% size=%'"
);

$updateStmt = $pdo->prepare('UPDATE articles SET content = :content WHERE id = :id');

$totalReviewed  = 0;
$dedupCount     = 0;
$presentationCount = 0;
$changedCount   = 0;
$updatedInDb    = 0;
$samplesShown   = 0;

foreach ($stmt as $row) {
    if ($changedCount >= $limit) {
        break;
    }
    $totalReviewed++;

    $articleId = (int) $row['id'];
    $content   = (string) $row['content'];
    $original  = $content;

    // ── Misión 2: deduplicar imagen principal repetida al inicio ───────────
    $heroPath = $row['thumbnail'] !== null && $row['thumbnail'] !== '' ? $row['thumbnail'] : $row['image'];
    if ($heroPath !== null && $heroPath !== '') {
        $heroBasename = strtolower(basename((string) $heroPath));
        $leading = extractLeadingImg($content);
        if ($leading !== null && $leading[1] === $heroBasename) {
            $content = substr($content, strlen($leading[0]));
            $dedupCount++;
        }
    }

    // ── Misión 3: purgar estilos/clases legacy ──────────────────────────────
    $cleaned = stripLegacyPresentation($content);
    if ($cleaned !== $content) {
        $presentationCount++;
        $content = $cleaned;
    }

    if ($content !== $original) {
        $changedCount++;

        if ($sample > 0 && $samplesShown < $sample) {
            echo "--- Artículo id={$articleId} ---\n";
            echo "ANTES (primeros 300 chars):\n" . mb_substr($original, 0, 300) . "\n\n";
            echo "DESPUÉS (primeros 300 chars):\n" . mb_substr($content, 0, 300) . "\n\n";
            $samplesShown++;
        }

        if ($commit) {
            $updateStmt->execute([':content' => $content, ':id' => $articleId]);
            $updatedInDb++;
        }
    }
}

echo "=== Sanitización de contenido legacy (dedupe imagen + purga de estilos) ===\n";
echo 'Modo: ' . ($commit ? "COMMIT (limit={$limit})" : 'DRY-RUN (solo reporte, nada escrito)') . "\n\n";
echo "Artículos revisados: {$totalReviewed}\n";
echo "Imágenes principales deduplicadas: {$dedupCount}\n";
echo "Artículos con estilos/clases legacy purgados: {$presentationCount}\n";
echo "Artículos con al menos 1 cambio: {$changedCount}\n";
if ($commit) {
    echo "Artículos actualizados en la BD: {$updatedInDb}\n";
}
