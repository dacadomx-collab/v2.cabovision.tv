<?php

declare(strict_types=1);

// =============================================================================
// helpers/date_filter.php — Filtro cronológico (?year=&month=&day=) compartido
// por index.php y categoria.php.
//
// Usa `published_at` (nunca `created_at`): es la columna real que el sitio ya
// muestra/ordena en todas las vistas, tiene índice dedicado
// (idx_articles_published_at, ver database/schema_v5_indexes_performance.sql)
// y es la que tiene sentido para un lector ("¿qué se publicó ese día?").
// `created_at` en los datos migrados no sirve para esto: toda la migración
// quedó con el mismo año de ingestión, no la fecha real de la nota — filtrar
// por ahí rompería la función sin que se note (Mandamiento #4, no inventar
// sobre datos que no se verificaron).
// =============================================================================

require_once __DIR__ . '/input_sanitizer.php';

/**
 * Resuelve el rango de fechas [start, end) a partir de $_GET['year'/'month'/'day'].
 * Retorna null si no se pidió ningún filtro (year ausente o inválido).
 *
 * @return array{start:string,end:string,year:int,month:int,day:int}|null
 */
function resolve_date_filter_range(): ?array
{
    $year  = sanitize_int($_GET['year'] ?? null, 0);
    $month = sanitize_int($_GET['month'] ?? null, 0);
    $day   = sanitize_int($_GET['day'] ?? null, 0);

    if ($year < 2000 || $year > 2100) {
        return null; // sin año valido, no se aplica ningún filtro
    }
    $month = ($month >= 1 && $month <= 12) ? $month : 0;
    $day   = ($month > 0 && $day >= 1 && $day <= 31) ? $day : 0;

    try {
        if ($day > 0) {
            $start = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            $end   = $start->modify('+1 day');
        } elseif ($month > 0) {
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $end   = $start->modify('+1 month');
        } else {
            $start = new DateTimeImmutable(sprintf('%04d-01-01', $year));
            $end   = $start->modify('+1 year');
        }
    } catch (\Exception) {
        return null; // fecha imposible (ej. 31 de febrero) — se ignora el filtro, no se rompe la página
    }

    return [
        'start' => $start->format('Y-m-d H:i:s'),
        'end'   => $end->format('Y-m-d H:i:s'),
        'year'  => $year,
        'month' => $month,
        'day'   => $day,
    ];
}

/** Query string (sin "?") para reusar el filtro activo en enlaces de paginación. */
function date_filter_query_string(?array $dateFilter): string
{
    if ($dateFilter === null) {
        return '';
    }
    $parts = ['year=' . $dateFilter['year']];
    if ($dateFilter['month'] > 0) {
        $parts[] = 'month=' . $dateFilter['month'];
    }
    if ($dateFilter['day'] > 0) {
        $parts[] = 'day=' . $dateFilter['day'];
    }

    return '&' . implode('&', $parts);
}
