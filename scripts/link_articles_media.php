<?php

declare(strict_types=1);

// =============================================================================
// scripts/link_articles_media.php — Enlaza artículos reales (cabovision_local
// .articles, 10,713 filas) con sus imágenes reales dentro de
// E:\RESPALDO CABOVISION.zip, y las transfiere a Cold Storage EN STREAMING
// (ZipArchive::getStream() -> curl vía ColdStorageClient::uploadStreamToColdStorage())
// SIN escribir ningún byte del ZIP en el disco C: — ni el binario completo del
// archivo se descomprime a disco, ni se crean temporales.
//
// Cómo enlaza sin dump perdido: los 10,674 artículos con <img> ya tienen la
// ruta de imagen original en su HTML (`content`). Se verifica que exista en
// el ZIP (ZipArchive::locateName, solo metadata), se deriva la fecha real
// desde la carpeta (NO desde `published_at`, bulk-importada y no confiable) y
// se reconstruye la ruta limpia de destino: {tenant}/{yyyy}/{mm}/{dd}/archivo.ext
// (tenant=1002, la constante ya establecida — bridge_serve.php exige un
// tenant numérico al inicio de la ruta, no se puede omitir).
//
// MODO POR DEFECTO: solo lectura / reporte (dry-run). Nada se transfiere ni se
// escribe en media_assets hasta correr con --commit. --limit=N acota cuántas
// imágenes se intentan subir en una corrida (protege contra reintentar miles
// de subidas si el bridge físico está caído).
//
// 2026-07-19: restaurado tras dos reemplazos no solicitados de este archivo
// (uno leía un manifest.json inexistente y hardcodeaba geolocalización falsa
// GEO_MOCK; otro apareció como "ACADEP PIPELINE V2.0"). Esta es la versión
// verificada con pruebas reales de integración contra un servidor local.
//
// Uso:
//   php scripts/link_articles_media.php                    (reporte, no escribe nada)
//   php scripts/link_articles_media.php --commit --limit=5  (sube hasta 5, inserta solo las que confirmen éxito)
// =============================================================================

require_once __DIR__ . '/../api/conexion.php';
require_once __DIR__ . '/../helpers/ColdStorageClient.php';
require_once __DIR__ . '/../helpers/media_path_resolver.php';

const ZIP_PATH      = 'E:\\RESPALDO CABOVISION.zip';
const ZIP_PREFIX    = 'RESPALDO CABOVISION/backup/homedir/public_html';
const HOT_STORAGE_YEARS = 2;
const MEDIA_TENANT_ID   = 1002; // Misma constante que ColdStorageClient — un solo valor válido (Mandamiento 10)
const MAX_RETRIES       = 3;
const RETRY_DELAY_SECONDS = 1.5;
const RETRYABLE_HTTP_CODES = [502, 504, 0]; // 0 = fallo de conexión (curl_errno != 0), ni siquiera hubo respuesta HTTP

$commit = in_array('--commit', $argv, true);
$limit  = PHP_INT_MAX;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

function resolveTier(string $capturedAt): string
{
    $cutoff = (new DateTimeImmutable())->modify('-' . HOT_STORAGE_YEARS . ' years')->format('Y-m-d');
    return $capturedAt >= $cutoff ? 'hot' : 'cold';
}

function guessMime(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        default => 'image/jpeg',
    };
}

/**
 * Blindaje antibloqueo: reintenta hasta MAX_RETRIES veces con espera de
 * RETRY_DELAY_SECONDS solo ante fallos transitorios (502/504/caída de
 * conexión) — nunca ante 409 (colisión real, no se resuelve reintentando) ni
 * 403 (clave inválida, tampoco se resuelve reintentando). Cada intento pide
 * un stream NUEVO del ZIP (uno ya leído no se puede reutilizar).
 *
 * @return array{success: bool, httpCode: int, conflict: bool, attempts: int}
 */
function uploadWithRetry(ZipArchive $zip, string $zipTarget, ColdStorageClient $coldClient, string $cleanPath, int $size, string $mime): array
{
    $result = ['success' => false, 'httpCode' => 0, 'conflict' => false];

    for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++) {
        $stream = $zip->getStream($zipTarget);
        if ($stream === false) {
            return ['success' => false, 'httpCode' => 0, 'conflict' => false, 'attempts' => $attempt];
        }

        $result = $coldClient->uploadStreamToColdStorage($cleanPath, $stream, $size, $mime);
        fclose($stream);

        if ($result['success'] || $result['conflict']) {
            return $result + ['attempts' => $attempt];
        }
        if (!in_array($result['httpCode'], RETRYABLE_HTTP_CODES, true)) {
            return $result + ['attempts' => $attempt]; // fallo definitivo (403, 400, etc.) — no reintentar
        }
        if ($attempt < MAX_RETRIES) {
            usleep((int) (RETRY_DELAY_SECONDS * 1_000_000));
        }
    }

    return $result + ['attempts' => MAX_RETRIES];
}

function logMediaFailure(string $cleanPath, int $httpCode, int $attempts): void
{
    $line = sprintf(
        "[%s] path=%s httpCode=%d intentos=%d\n",
        date('Y-m-d H:i:s'),
        $cleanPath,
        $httpCode,
        $attempts
    );
    @file_put_contents(dirname(__DIR__) . '/logs/media_failures.log', $line, FILE_APPEND | LOCK_EX);
}

$zip = new ZipArchive();
if ($zip->open(ZIP_PATH) !== true) {
    fwrite(STDERR, "Error: no se pudo abrir " . ZIP_PATH . "\n");
    exit(1);
}

$database = new Database();
$pdo      = $database->getConnection();
$coldClient = $commit ? new ColdStorageClient() : null;

// Excluye artículos que ya tienen AL MENOS una subida real confirmada
// (file_hash IS NOT NULL) — sin esto, cada corrida con --commit --limit=N
// vuelve a intentar el mismo primer bloque de candidatos (siempre el mismo
// orden, sin OFFSET ni marca de progreso) y nunca avanza a los siguientes.
// Confirmado en vivo 2026-07-21: un lote de --limit=200 dio 0 subidas
// nuevas, 200/200 en 409 — todas ya existían de la corrida anterior.
$stmt = $pdo->query(
    "SELECT id, content FROM articles
     WHERE content LIKE '%<img%'
       AND id NOT IN (
           SELECT article_id FROM media_assets
           WHERE article_id IS NOT NULL AND file_hash IS NOT NULL
       )"
);

$insert = $pdo->prepare(
    'INSERT INTO `media_assets` (`article_id`, `relative_path`, `storage_tier`, `captured_at`, `mime_type`, `file_hash`, `migrated_to_cold_at`)
     VALUES (:article_id, :relative_path, :storage_tier, :captured_at, :mime_type, :file_hash, NOW())
     ON DUPLICATE KEY UPDATE `article_id` = VALUES(`article_id`), `file_hash` = VALUES(`file_hash`), `migrated_to_cold_at` = VALUES(`migrated_to_cold_at`)'
);

// Imágenes compartidas por varios artículos (ej. foto de columnista repetida
// en decenas de notas) mapean a la MISMA relative_path (uq_media_relative_path
// es UNIQUE — un archivo físico = una fila, article_id es "quién lo trajo
// primero", no un vínculo muchos-a-muchos; cambiarlo requeriría alterar el
// schema, Mandamiento #9). Auditoría real 2026-07-22: 153/200 intentos de un
// lote eran justo esto — el archivo YA estaba en Cold Storage por otro
// artículo, y el 409 consumía cupo de --limit sin subir nada nuevo. Cargar el
// set de rutas ya existentes ANTES del bucle permite saltarlas sin gastar
// intento de red ni cupo de --limit, dejando ese cupo para subidas reales.
$existingPaths = array_flip($pdo->query('SELECT `relative_path` FROM `media_assets`')->fetchAll(\PDO::FETCH_COLUMN));

$totalRefs     = 0;
$foundInZip    = 0;
$missingZip    = 0;
$noDatePattern = 0;
$hotCount      = 0;
$coldCount     = 0;
$uploadAttempts = 0;
$uploadOk       = 0;
$uploadConflict = 0;
$uploadFail     = 0;
$inserted       = 0;
$skippedShared  = 0;

foreach ($stmt as $row) {
    if ($uploadAttempts >= $limit) {
        break;
    }
    $articleId = (int) $row['id'];
    if (!preg_match_all('#src="(/images/[^"]+\.(?:jpg|jpeg|png|gif|webp))"#i', (string) $row['content'], $matches)) {
        continue;
    }

    foreach (array_unique($matches[1]) as $imgPath) {
        if ($uploadAttempts >= $limit) {
            break;
        }
        $totalRefs++;

        $zipTarget = resolve_zip_target($zip, ZIP_PREFIX, $imgPath);
        if ($zipTarget === null) {
            $missingZip++;
            continue;
        }
        $foundInZip++;

        $capturedAt = extract_date_from_media_path($imgPath);
        if ($capturedAt === null) {
            $noDatePattern++;
            continue;
        }

        $tier = resolveTier($capturedAt);
        $tier === 'hot' ? $hotCount++ : $coldCount++;

        if (!$commit) {
            continue;
        }

        // ── Streaming real: getStream() da un recurso de lectura del ZIP,
        // nunca se escribe en disco C: — se lee a memoria (RAM) y se adjunta
        // como multipart/form-data real vía CURLStringFile. Con reintentos
        // ante fallos transitorios (502/504/conexión caída).
        $stat = $zip->statName($zipTarget);
        $size = (int) ($stat['size'] ?? 0);
        $mime = guessMime($imgPath);

        // Nombre destino decodificado (html_entity_decode + rawurldecode),
        // nunca $imgPath tal cual: cuando resolve_zip_target() recuperó el
        // archivo por decode/fuzzy, $imgPath trae "%20"/entidades HTML sin
        // resolver — el archivo migrado no debe heredar ese ruido literal.
        $decodedImgPath = html_entity_decode(rawurldecode($imgPath), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleanPath = build_clean_media_path(MEDIA_TENANT_ID, basename($decodedImgPath), $capturedAt);

        // Ya subida por otro artículo (misma imagen compartida) — se salta
        // SIN gastar cupo de --limit ni golpear el bridge, ver comentario junto
        // a $existingPaths más arriba.
        if (isset($existingPaths[$cleanPath])) {
            $skippedShared++;
            continue;
        }

        $uploadAttempts++;

        $result = uploadWithRetry($zip, $zipTarget, $coldClient, $cleanPath, $size, $mime);

        // 409 confirmado = el bridge YA tiene ese archivo (drift real entre
        // servidor y esta BD detectado 2026-07-22, ver ColdStorageClient::
        // uploadStreamToColdStorage) — se registra igual que un éxito, solo
        // que sin bytes transferidos ahora mismo, para dejar de re-intentarlo
        // en cada corrida futura Y en el resto de este mismo lote.
        if (!$result['success'] && !$result['conflict']) {
            $uploadFail++;
            // Log de exclusión no-bloqueante: se registra y se sigue con el
            // siguiente artículo, nunca se detiene ni se ciclea reintentando
            // indefinidamente el mismo archivo.
            logMediaFailure($cleanPath, $result['httpCode'], $result['attempts']);
            error_log(
                '[' . date('Y-m-d H:i:s') . '] [link_articles_media] Fallo tras ' . $result['attempts']
                . ' intento(s) (HTTP ' . $result['httpCode'] . '): ' . $cleanPath
            );
            continue;
        }

        $result['conflict'] ? $uploadConflict++ : $uploadOk++;

        // Se registra en media_assets tanto si el archivo llegó ahora (success)
        // como si el bridge confirmó que ya estaba (conflict) — nunca una fila
        // "cold" fantasma sin que el bridge la respalde de una forma u otra.
        $insert->execute([
            ':article_id'    => $articleId,
            ':relative_path' => $cleanPath,
            ':storage_tier'  => $tier,
            ':captured_at'   => $capturedAt,
            ':mime_type'     => $mime,
            ':file_hash'     => $result['sha256'],
        ]);
        $inserted++;
        $existingPaths[$cleanPath] = true; // visible al resto de este mismo lote, no solo a corridas futuras
    }
}

$zip->close();

echo "=== Enlace + transferencia streaming articles <-> imágenes del ZIP ===\n";
echo 'Modo: ' . ($commit ? "COMMIT (limit={$limit})" : 'DRY-RUN (solo reporte, nada transferido)') . "\n\n";
echo "Referencias <img> procesadas: {$totalRefs}\n";
echo "Encontradas en el ZIP: {$foundInZip}\n";
echo "NO encontradas en el ZIP (huérfanas): {$missingZip}\n";
echo "Encontradas pero sin patrón de fecha reconocido: {$noDatePattern}\n";
echo "Clasificadas Hot (< 2 años): {$hotCount}\n";
echo "Clasificadas Cold (>= 2 años): {$coldCount}\n";
if ($commit) {
    echo "\nImágenes compartidas ya subidas por otro artículo (omitidas sin gastar cupo): {$skippedShared}\n";
    echo "Intentos de subida real: {$uploadAttempts}\n";
    echo "Subidas exitosas (bytes transferidos ahora): {$uploadOk}\n";
    echo "Ya existían en el bridge, drift de BD corregido (409, sin transferir bytes): {$uploadConflict}\n";
    echo "Subidas fallidas (error real, no conflicto): {$uploadFail}\n";
    echo "Filas insertadas en media_assets (solo con subida confirmada): {$inserted}\n";
    if ($uploadFail > 0) {
        echo "Detalle de fallos: logs/media_failures.log\n";
    }
}
