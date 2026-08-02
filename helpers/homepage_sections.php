<?php

declare(strict_types=1);

// =============================================================================
// helpers/homepage_sections.php — Portada curada por secciones (cierra el gap
// más grande del inventario: HomeController::index() legacy armaba la home
// con ~19 bloques hardcoded por categoría). En vez de repetir esa misma
// query-por-bloque hardcodeada en PHP, aquí es UNA lista de datos (categoría +
// título + límite) recorrida por una sola función reutilizable — mismo
// resultado editorial, sin duplicar la consulta SQL 19 veces.
//
// Solo se incluyen categorías con `status` = "publicada" — los columnistas
// retirados (Figaredo, Sánchez, Nájar, Echeveste, Tapia, Lage, Ardines, etc.)
// están marcados "Despublicada" en la BD real y se excluyen a propósito, no
// se resucitan secciones que el propio cliente ya desactivó. Confirmar con
// cliente si alguno debe reactivarse.
// =============================================================================

/**
 * @return array<int, array{title: string, category_id: int, limit: int}>
 */
function homepage_section_definitions(): array
{
    return [
        ['title' => 'Micrositios de Gobierno',        'category_id' => 69,  'limit' => 4],
        ['title' => 'Ayuntamiento de Los Cabos',       'category_id' => 48,  'limit' => 4],
        ['title' => 'Congreso del Estado B.C.S.',      'category_id' => 61,  'limit' => 4],
        ['title' => 'FITURCA',                         'category_id' => 71,  'limit' => 4],
        ['title' => 'FONMAR',                          'category_id' => 105, 'limit' => 4],
        ['title' => 'Questro Club Golf',               'category_id' => 149, 'limit' => 3],
        ['title' => 'Hotel El Ganzo',                  'category_id' => 150, 'limit' => 3],
        ['title' => 'The Place at Cabo',                'category_id' => 151, 'limit' => 3],
        ['title' => 'Veleros Beach Club',               'category_id' => 152, 'limit' => 3],
        ['title' => 'Festival Internacional de Cine',   'category_id' => 153, 'limit' => 3],
        ['title' => 'Fundación Questro',                'category_id' => 154, 'limit' => 3],
        ['title' => 'Conversando con...',               'category_id' => 147, 'limit' => 4],
        ['title' => 'Parque Nacional Marino Espíritu Santo', 'category_id' => 148, 'limit' => 4],
        ['title' => 'En la Mira',                       'category_id' => 157, 'limit' => 4],
        ['title' => 'Primer Informe de Labores',        'category_id' => 158, 'limit' => 4],
        ['title' => 'Eventos',                          'category_id' => 89,  'limit' => 4],
        ['title' => 'Difusión Internacional',           'category_id' => 84,  'limit' => 6],
        ['title' => 'Ecoturismo y Naturaleza',          'category_id' => 35,  'limit' => 8],
        ['title' => 'Especiales',                       'category_id' => 130, 'limit' => 4],
        ['title' => 'Editoriales',                      'category_id' => 9,   'limit' => 4],
    ];
}

/**
 * Trae, para cada sección definida arriba, hasta N artículos publicados de su
 * categoría — se omite del resultado cualquier sección sin artículos (nunca
 * se renderiza un bloque vacío). Una query por sección (igual criterio que el
 * propio HomeController legacy, ~19 queries pequeñas por categoría indexada
 * por category_id — no una sola query gigante con UNIONs difícil de mantener).
 *
 * @return array<int, array{title: string, category_id: int, articles: array}>
 */
function fetch_homepage_sections(\PDO $pdo): array
{
    $statusPublicado = 1;

    $stmt = $pdo->prepare(
        "SELECT `articles`.`id`, `articles`.`title`, `articles`.`alias`, `articles`.`extract`,
                `articles`.`thumbnail`, `articles`.`image`, `articles`.`published_at`, `articles`.`featured`,
                `categories`.`name` AS `category_name`, `categories`.`alias` AS `category_alias`
         FROM `articles`
         INNER JOIN `categories` ON `categories`.`id` = `articles`.`category_id`
         WHERE `articles`.`status_id` = :status_id AND `articles`.`category_id` = :category_id
         ORDER BY `articles`.`published_at` DESC
         LIMIT :limit"
    );

    $sections = [];
    foreach (homepage_section_definitions() as $def) {
        $stmt->bindValue(':status_id', $statusPublicado, \PDO::PARAM_INT);
        $stmt->bindValue(':category_id', $def['category_id'], \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $def['limit'], \PDO::PARAM_INT);
        $stmt->execute();
        $articles = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($articles)) {
            continue; // sin contenido reciente en esta categoría — no se muestra el bloque
        }

        foreach ($articles as &$row) {
            $row['title'] = repair_known_mojibake($row['title']);
        }
        unset($row);

        $sections[] = [
            'title'          => $def['title'],
            'category_id'    => $def['category_id'],
            'category_alias' => $articles[0]['category_alias'],
            'articles'       => $articles,
        ];
    }

    return $sections;
}
