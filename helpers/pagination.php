<?php

declare(strict_types=1);

// =============================================================================
// helpers/pagination.php — Paginación compacta reutilizable
// Antes index.php/categoria.php imprimían UN <a> por cada página existente
// (881 enlaces en index.php con 10,562 artículos a 12 por página) — reportado
// por el Arquitecto como "no eficaz ni eficiente". Reemplazo: ventana de
// páginas alrededor de la actual + primera/última + flechas prev/next,
// con "…" para los saltos. Compartido entre index.php y categoria.php para
// no duplicar la lógica (Mandamiento #8).
// =============================================================================

/**
 * Genera el HTML de una barra de paginación compacta.
 *
 * @param array<string,int|string> $extraParams Parámetros GET a preservar en cada
 *        enlace (ej. filtros de búsqueda/fecha, alias de categoría) — ya deben
 *        venir sanitizados por el llamador.
 */
function render_pagination(int $currentPage, int $totalPages, string $baseUrl, array $extraParams = []): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $buildUrl = static function (int $page) use ($baseUrl, $extraParams): string {
        $params = array_merge($extraParams, ['page' => $page]);

        return $baseUrl . '?' . http_build_query($params);
    };

    $windowSize = 2;
    $pagesToShow = [1, $totalPages];
    for ($i = $currentPage - $windowSize; $i <= $currentPage + $windowSize; $i++) {
        if ($i >= 1 && $i <= $totalPages) {
            $pagesToShow[] = $i;
        }
    }
    $pagesToShow = array_unique($pagesToShow);
    sort($pagesToShow);

    $html = '<nav class="pagination" aria-label="Paginación de noticias">';

    if ($currentPage > 1) {
        $html .= '<a class="pagination__link pagination__link--nav" href="'
            . htmlspecialchars($buildUrl($currentPage - 1), ENT_QUOTES, 'UTF-8')
            . '" aria-label="Página anterior">&lsaquo;</a>';
    }

    $previousShown = null;
    foreach ($pagesToShow as $p) {
        if ($previousShown !== null && $p - $previousShown > 1) {
            $html .= '<span class="pagination__ellipsis">&hellip;</span>';
        }
        $isActive = $p === $currentPage;
        $html .= '<a class="pagination__link' . ($isActive ? ' pagination__link--active' : '') . '" href="'
            . htmlspecialchars($buildUrl($p), ENT_QUOTES, 'UTF-8') . '"'
            . ($isActive ? ' aria-current="page"' : '') . '>' . $p . '</a>';
        $previousShown = $p;
    }

    if ($currentPage < $totalPages) {
        $html .= '<a class="pagination__link pagination__link--nav" href="'
            . htmlspecialchars($buildUrl($currentPage + 1), ENT_QUOTES, 'UTF-8')
            . '" aria-label="Página siguiente">&rsaquo;</a>';
    }

    $html .= '</nav>';

    return $html;
}
