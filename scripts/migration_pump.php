<?php

declare(strict_types=1);

// =============================================================================
// scripts/migration_pump.php — ETL de notas históricas (Joomla, woaxp_content)
// hacia el schema modular real de cabovision_local.
//
// Origen (solo lectura): LEGACY_DB_* — BD sandbox `cabovision_legacy`.
// Destino (escritura):   cabovision_local, vía api/conexion.php (Database) —
//   cabovision_core fue purgada 2026-07-17 (ver knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md,
//   §Módulo 01) y NO debe volver a usarse.
//
// Tablas destino reales (verificadas en código, no inventadas):
//   - articles      (api/articles_create.php): title, alias, content, extract,
//                    category_id, status_id, user_id, published_at, created_at, updated_at
//   - media_assets   (database/schema_v2_media.sql): article_id, relative_path,
//                    storage_tier, mime_type, captured_at
//
// Uso: php scripts/migration_pump.php
// =============================================================================

require_once __DIR__ . '/../api/conexion.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';

// ── CONFIGURACIÓN DE MIGRACIÓN (verificada contra la BD antes de correr) ────
// El histórico Joomla no tiene autor ni categoría propios: deben apuntar a un
// `user_id` y `category_id` reales que YA existan en cabovision_local. IDs
// verificados por consulta directa a la BD el 2026-07-18 (no adivinados):
// `users.id=989` ("Cabovision", sistemas@acadep.com, status=activo — cuenta
// genérica de sistema, no una de las 17 cuentas editoriales personales) y
// `categories.id=2` ("Contenido General" — no se usó id=1 "ROOT", que es el
// nodo raíz del árbol de categorías, no una categoría de contenido real).
// El script igual valida su existencia en caliente antes de insertar nada.
const MIGRATION_USER_ID     = 989;
const MIGRATION_CATEGORY_ID = 2;

const STATUS_PENDIENTE = 0; // Mismo valor que api/articles_create.php — revisión editorial antes de publicar

const MEDIA_TENANT_ID  = 1002; // Fijo, ver knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md §Módulo 02
const HOT_STORAGE_YEARS = 2;   // 24 meses en Hot, resto en Cold (misma política que cold_storage_migration.php)

// ── CONEXIONES DUALES ─────────────────────────────────────────────────────

function connectLegacy(): PDO
{
    $env = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];

    $host = (string) ($env['LEGACY_DB_HOST'] ?? '');
    $name = (string) ($env['LEGACY_DB_NAME'] ?? '');
    $user = (string) ($env['LEGACY_DB_USER'] ?? '');
    $pass = (string) ($env['LEGACY_DB_PASS'] ?? '');

    if ($host === '' || $name === '') {
        fwrite(STDERR, "Error: LEGACY_DB_HOST / LEGACY_DB_NAME no configurados en .env\n");
        exit(1);
    }

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES         => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // cursor no bufferizado: no carga toda la tabla en memoria
    ]);
}

// ── NORMALIZACIÓN Y SANITIZACIÓN ─────────────────────────────────────────

function normalizeImagePath(string $path): string
{
    return trim(str_replace('\\', '/', $path));
}

// 2026-07-21: sanitizeArticleHtml()/slugify() consolidadas en
// helpers/input_sanitizer.php (sanitize_article_html()/slugify_text()) —
// ahora también las usa api/articles_create.php v2, que antes no sanitizaba
// el HTML del editor en absoluto. Mandamiento #10: un solo criterio válido.

function uniqueAlias(PDO $localPdo, string $baseAlias): string
{
    $alias  = $baseAlias;
    $suffix = 2;
    $check  = $localPdo->prepare('SELECT `id` FROM `articles` WHERE `alias` = :alias LIMIT 1');

    while (true) {
        $check->execute([':alias' => $alias]);
        if ($check->fetch(PDO::FETCH_ASSOC) === false) {
            return $alias;
        }
        $alias = "{$baseAlias}-{$suffix}";
        $suffix++;
    }
}

function resolveStorageTier(DateTimeImmutable $capturedAt): string
{
    $cutoff = (new DateTimeImmutable())->modify('-' . HOT_STORAGE_YEARS . ' years');

    return $capturedAt >= $cutoff ? 'hot' : 'cold';
}

function guessMimeType(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return match ($ext) {
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        default       => 'image/jpeg',
    };
}

// ── VALIDACIÓN PREVIA (aborta si la configuración apunta a datos inexistentes) ─

function assertMigrationConfig(PDO $localPdo): void
{
    if (MIGRATION_USER_ID <= 0 || MIGRATION_CATEGORY_ID <= 0) {
        fwrite(STDERR, "Error: define MIGRATION_USER_ID y MIGRATION_CATEGORY_ID (ids reales) antes de correr el script.\n");
        exit(1);
    }

    $userCheck = $localPdo->prepare('SELECT `id` FROM `users` WHERE `id` = :id LIMIT 1');
    $userCheck->execute([':id' => MIGRATION_USER_ID]);
    if ($userCheck->fetch(PDO::FETCH_ASSOC) === false) {
        fwrite(STDERR, 'Error: MIGRATION_USER_ID (' . MIGRATION_USER_ID . ") no existe en `users`.\n");
        exit(1);
    }

    $categoryCheck = $localPdo->prepare('SELECT `id` FROM `categories` WHERE `id` = :id LIMIT 1');
    $categoryCheck->execute([':id' => MIGRATION_CATEGORY_ID]);
    if ($categoryCheck->fetch(PDO::FETCH_ASSOC) === false) {
        fwrite(STDERR, 'Error: MIGRATION_CATEGORY_ID (' . MIGRATION_CATEGORY_ID . ") no existe en `categories`.\n");
        exit(1);
    }
}

// ── EJECUCIÓN ──────────────────────────────────────────────────────────────

$database = new Database();
$localPdo = $database->getConnection();
$legacyPdo = connectLegacy();

assertMigrationConfig($localPdo);

$insertArticle = $localPdo->prepare(
    'INSERT INTO `articles`
        (`title`, `alias`, `content`, `extract`, `category_id`, `status_id`, `user_id`, `published_at`, `created_at`, `updated_at`)
     VALUES
        (:title, :alias, :content, :extract, :category_id, :status_id, :user_id, :published_at, NOW(), NOW())'
);

$insertMedia = $localPdo->prepare(
    'INSERT INTO `media_assets`
        (`article_id`, `relative_path`, `storage_tier`, `mime_type`, `captured_at`)
     VALUES
        (:article_id, :relative_path, :storage_tier, :mime_type, :captured_at)
     ON DUPLICATE KEY UPDATE `article_id` = VALUES(`article_id`)'
);

$totalRows = (int) $legacyPdo->query('SELECT COUNT(*) FROM `woaxp_content`')->fetchColumn();

$stmt = $legacyPdo->query('SELECT `id`, `title`, `introtext`, `created`, `images` FROM `woaxp_content`');

$migrated  = 0;
$skipped   = 0;
$processed = 0;

$localPdo->beginTransaction();

try {
    foreach ($stmt as $row) {
        $processed++;
        if ($processed % 100 === 0 || $processed === $totalRows) {
            $pct    = $totalRows > 0 ? (int) round($processed / $totalRows * 100) : 0;
            $filled = (int) round($pct / 10);
            $bar    = str_repeat('█', $filled) . str_repeat('░', 10 - $filled);
            // "\r" reescribe la misma línea en vez de system('cls'): un clear de pantalla
            // real depende de un TTY interactivo y rompe si la salida se redirige a un
            // log — "\r" funciona en ambos casos y es el patrón estándar de progreso CLI.
            fwrite(STDOUT, "\r[PROGRESO EDITORIAL] ID: {$row['id']} [{$bar}] {$pct}% ({$processed}/{$totalRows}) - OK");
            if ($processed === $totalRows) {
                fwrite(STDOUT, "\n");
            }
        }

        try {
            $legacyId = (int) $row['id'];
            $title    = trim((string) $row['title']);

            if ($title === '') {
                error_log("[migration_pump] Fila legacy id={$legacyId} sin título — omitida.");
                $skipped++;
                continue;
            }

            $createdAt = new DateTimeImmutable((string) $row['created']);
            $content   = sanitize_article_html((string) $row['introtext']);
            $extract   = mb_substr(strip_tags($content), 0, 255);

            $alias = uniqueAlias($localPdo, slugify_text($title));

            $insertArticle->execute([
                ':title'         => $title,
                ':alias'         => $alias,
                ':content'       => $content,
                ':extract'       => $extract !== '' ? $extract : null,
                ':category_id'   => MIGRATION_CATEGORY_ID,
                ':status_id'     => STATUS_PENDIENTE,
                ':user_id'       => MIGRATION_USER_ID,
                ':published_at'  => $createdAt->format('Y-m-d H:i:s'),
            ]);
            $newArticleId = (int) $localPdo->lastInsertId();

            $imagesRaw = (string) ($row['images'] ?? '');
            $images    = json_decode($imagesRaw, true);
            $imageIntro = is_array($images) ? (string) ($images['image_intro'] ?? '') : '';

            if ($imageIntro !== '') {
                $relativePath = MEDIA_TENANT_ID . '/' . normalizeImagePath($imageIntro);
                $tier         = resolveStorageTier($createdAt);

                $insertMedia->execute([
                    ':article_id'    => $newArticleId,
                    ':relative_path' => $relativePath,
                    ':storage_tier'  => $tier,
                    ':mime_type'     => guessMimeType($imageIntro),
                    ':captured_at'   => $createdAt->format('Y-m-d'),
                ]);
            }

            $migrated++;
        } catch (Throwable $rowError) {
            // Fallo puntual de una fila (dato corrupto, duplicado, etc.) — se registra
            // y el bucle continúa; MySQL/InnoDB no invalida la transacción abierta
            // por un error de sentencia individual (a diferencia de PostgreSQL).
            error_log('[' . date('Y-m-d H:i:s') . '] [migration_pump] Fila legacy id=' . ($row['id'] ?? '?') . ' — ' . $rowError->getMessage());
            $skipped++;
        }
    }

    $localPdo->commit();
} catch (Throwable $fatalError) {
    $localPdo->rollBack();
    error_log('[' . date('Y-m-d H:i:s') . '] [migration_pump] Fallo de infraestructura, transacción revertida: ' . $fatalError->getMessage());
    fwrite(STDERR, "Error fatal — transacción revertida. Ver logs/error.log.\n");
    exit(1);
}

echo "Migrados: {$migrated} | Omitidos: {$skipped}\n";
