<?php

declare(strict_types=1);

// =============================================================================
// helpers/media_path_resolver.php — Única fuente de verdad para derivar fecha
// real + ruta limpia de un <img src="/images/..."> heredado del CMS legacy.
// Antes vivía duplicado en scripts/link_articles_media.php Y
// scripts/rewrite_content_images.php — exactamente el tipo de divergencia que
// ya causó el bug real de repair_known_mojibake() (Mandamiento #10: un solo
// criterio válido por concepto).
//
// Convenciones reales confirmadas contra E:\RESPALDO CABOVISION.zip (censo
// 2026-07-18 + auditoría de huérfanos 2026-07-21):
//   1. /images/stories/{yyyy}/{mes_es}/{d}{mes_abrev}/...        (91% del contenido)
//   2. /images/Comunicabos/acadep/{yyyy}/{mm}/{dd}/...           (8%)
//   3. /images/Comunicabos/{autor}/{yyyy}/{mes_es}/{dd}/...      (variante por autor, mes en español)
//   4. /images/stories/{yyyy}/editoriales/{archivo}              (fotos de columnistas, sin día/mes real)
//   5. /images/{archivo}                                         (logos/autor en raíz, sin fecha real)
// Los patrones 4 y 5 son activos semi-estáticos reutilizados en muchos
// artículos (no una captura fechada por nota) — se les asigna el 1 de enero
// del año de la ruta (patrón 4) o la fecha de hoy (patrón 5, sin año
// disponible), suficiente para la política Hot/Cold (todo esto ya cae en
// Cold de cualquier forma) sin inventar una fecha de captura que no existe.
// =============================================================================

// 2026-07-21: se agregaron abreviaturas reales encontradas en el ZIP —
// "jun"/"jul" representan el 99% (1,443 de 1,457) de las referencias que
// SÍ estaban en el respaldo pero no calificaban por usar el mes abreviado
// en vez del nombre completo (ej. /images/stories/2017/jun/1jun/...).
const MEDIA_RESOLVER_MESES_ES = [
    'enero' => '01', 'ene' => '01',
    'febrero' => '02', 'feb' => '02',
    'marzo' => '03', 'mar' => '03',
    'abril' => '04', 'abr' => '04',
    'mayo' => '05', 'may' => '05',
    'junio' => '06', 'jun' => '06',
    'julio' => '07', 'jul' => '07',
    'agosto' => '08', 'ago' => '08',
    'septiembre' => '09', 'setiembre' => '09', 'sep' => '09', 'set' => '09',
    'octubre' => '10', 'oct' => '10',
    'noviembre' => '11', 'nov' => '11',
    'diciembre' => '12', 'dic' => '12',
];

/** Extrae la fecha real (YYYY-MM-DD) de una ruta de imagen heredada, o null si no matchea ningún patrón conocido. */
function extract_date_from_media_path(string $path): ?string
{
    if (preg_match('#/images/stories/(\d{4})/([a-z]+)/(\d{1,2})[a-z]*/#i', $path, $m)) {
        $mes = MEDIA_RESOLVER_MESES_ES[mb_strtolower($m[2])] ?? null;
        if ($mes !== null) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $mes, (int) $m[3]);
        }
    }
    if (preg_match('#/images/Comunicabos/acadep/(\d{4})/(\d{2})/(\d{2})/#i', $path, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    // Variante por autor con mes NUMÉRICO (ej. Comunicabos/erikmeza/2019/01/26/,
    // hallado real 2026-07-24 — mismo formato que el patrón "acadep" de arriba
    // pero bajo cualquier otro nombre de autor).
    if (preg_match('#/images/Comunicabos/[^/]+/(\d{4})/(\d{2})/(\d{2})/#i', $path, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    // Variante por autor: Comunicabos/{autor}/{yyyy}/{mes_es}/{dd}/ (mes en español, no numérico)
    if (preg_match('#/images/Comunicabos/[^/]+/(\d{4})/([a-z]+)/(\d{1,2})/#i', $path, $m)) {
        $mes = MEDIA_RESOLVER_MESES_ES[mb_strtolower($m[2])] ?? null;
        if ($mes !== null) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $mes, (int) $m[3]);
        }
    }
    // Fotos de columnistas reutilizadas: /images/stories/{yyyy}/editoriales/{archivo}
    // Sin día/mes real — se fija 01/01 del año de la ruta (activo evergreen, no una
    // captura fechada; el año real de la carpeta sí se preserva).
    if (preg_match('#/images/stories/(\d{4})/editoriales/#i', $path, $m)) {
        return sprintf('%04d-01-01', (int) $m[1]);
    }
    // Logos/autor en la raíz de /images/ sin ningún segmento de fecha — sin año
    // disponible en la ruta. Se usa una fecha ancla FIJA (no "hoy"): si se
    // usara la fecha de ejecución, el mismo archivo evergreen (ej.
    // armando.jpg, reutilizado en decenas de notas) calcularía una
    // relative_path distinta cada día que se corra el script, subiendo el
    // mismo logo una y otra vez en vez de deduplicar por nombre.
    if (preg_match('#^/images/[^/]+\.(?:jpg|jpeg|png|gif|webp)$#i', $path)) {
        return '2020-01-01';
    }
    // Día con cero de relleno de 3 dígitos real encontrado en el ZIP (ej.
    // "010nov" = día 10, no día "01" + "0nov"): (\d{1,2})[a-z]* del patrón
    // principal no lo cubre porque deja un dígito residual. Se toman los
    // últimos 2 dígitos antes de las letras como el día real.
    if (preg_match('#/images/stories/(\d{4})/([a-z]+)/0(\d{2})[a-z]+/#i', $path, $m)) {
        $mes = MEDIA_RESOLVER_MESES_ES[mb_strtolower($m[2])] ?? null;
        if ($mes !== null) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $mes, (int) $m[3]);
        }
    }
    // Subcarpeta temática sin número de día real (ej. stories/{yyyy}/mayo/gobierno/,
    // .../noviembre/eventos/) — se conserva año y mes reales (sí están en la
    // ruta), día fijo 01: mejor que descartar la referencia, sin inventar un
    // día que la ruta no provee.
    if (preg_match('#/images/stories/(\d{4})/([a-z]+)/[a-z_]+/#i', $path, $m)) {
        $mes = MEDIA_RESOLVER_MESES_ES[mb_strtolower($m[2])] ?? null;
        if ($mes !== null) {
            return sprintf('%04d-%02d-01', (int) $m[1], (int) $mes);
        }
    }
    // Ya en formato limpio {tenant}/{yyyy}/{mm}/{dd}/{archivo} (ej.
    // /images/1002/2019/10/31/5dbbc3f43c271.jpg, hallado real 2026-07-24 en
    // el barrido final) — subido por un flujo más reciente que ya usa la
    // convención de destino de este mismo proyecto, fecha numérica directa.
    if (preg_match('#/images/\d+/(\d{4})/(\d{2})/(\d{2})/#i', $path, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    // Foto de autor en la raíz de Comunicabos/{autor}/ SIN subcarpeta de fecha
    // (ej. /images/Comunicabos/ArmandoF/armando-figaredo.png, hallado real
    // 2026-07-24) — mismo caso que el logo evergreen en /images/ raíz: activo
    // reutilizado en decenas de notas, no una captura fechada. Misma fecha
    // ancla fija (nunca "hoy") para deduplicar por nombre entre corridas.
    if (preg_match('#/images/Comunicabos/[^/]+/[^/]+\.(?:jpg|jpeg|png|gif|webp)$#i', $path)) {
        return '2020-01-01';
    }

    return null;
}

/** Construye la ruta limpia {tenant}/{yyyy}/{mm}/{dd}/{archivo} para media_assets/el bridge. */
function build_clean_media_path(int $tenantId, string $originalPath, string $capturedAt): string
{
    $filename = preg_replace('/[^\w\-.]/', '_', basename($originalPath)) ?? basename($originalPath);
    [$y, $m, $d] = explode('-', $capturedAt);

    return sprintf('%d/%s/%s/%s/%s', $tenantId, $y, $m, $d, $filename);
}

/**
 * Resuelve la ruta real dentro del ZIP para una referencia <img> del HTML
 * original, con dos niveles de fallback cuando la coincidencia exacta falla
 * (2026-07-24, "Cacería de Huérfanos" — auditoría real sobre las 4,939
 * referencias no encontradas: 4,537 son huecos genuinos del respaldo -carpeta
 * del día completo ausente-, pero 402 SÍ existen en el ZIP bajo un nombre
 * distinto, en dos formas concretas):
 *
 *   1. Exacta (prefijo + $imgPath tal cual) — comportamiento previo intacto.
 *   2. Decodificada (html_entity_decode + rawurldecode) — cubre referencias
 *      del HTML original con "%20"/"&Aacute;"/etc. que el ZIP ya guarda
 *      "limpias" (173 de 402 recuperados así).
 *   3. Fuzzy por carpeta — cuando el nombre EN EL ZIP mismo está mojibake
 *      (ej. "IMPLEMENTARµ" en el archivo real vs "IMPLEMENTARÁ" ya decodificado
 *      del HTML): se listan las entradas reales de esa carpeta y se compara
 *      el nombre sin extensión tras quitar TODO carácter no-ASCII-alfanumérico
 *      de ambos lados (ambas variantes colapsan a "IMPLEMENTAR"). Solo se
 *      acepta si hay EXACTAMENTE una coincidencia en la carpeta — con más de
 *      una, se prefiere no migrar a migrar el archivo equivocado (229 de 402
 *      recuperados así, sin ningún falso positivo detectado en la auditoría).
 *
 * @return string|null Ruta real dentro del ZIP, o null si no se pudo resolver por ningún método.
 */
function resolve_zip_target(\ZipArchive $zip, string $prefix, string $imgPath): ?string
{
    static $folderCache = [];

    $exact = $prefix . $imgPath;
    if ($zip->locateName($exact) !== false) {
        return $exact;
    }

    $decoded = html_entity_decode(rawurldecode($imgPath), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decodedTarget = $prefix . $decoded;
    if ($zip->locateName($decodedTarget) !== false) {
        return $decodedTarget;
    }

    $folder = dirname($decodedTarget) . '/';
    if (!isset($folderCache[$folder])) {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, $folder) && $name !== $folder) {
                $entries[] = $name;
            }
        }
        $folderCache[$folder] = $entries;
    }

    $wantStem = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($decoded, PATHINFO_FILENAME)));
    if ($wantStem === '') {
        return null;
    }

    $matches = [];
    foreach ($folderCache[$folder] as $entry) {
        $stem = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo(basename($entry), PATHINFO_FILENAME)));
        if ($stem === $wantStem) {
            $matches[] = $entry;
        }
    }

    return count($matches) === 1 ? $matches[0] : null;
}
