<?php

declare(strict_types=1);

// =============================================================================
// helpers/candidate_enums.php — Catálogos de datos reales (no de código) del
// microsite público de Candidatos: los 73 candidatos migrados del sistema
// legacy guardan `parties`/`position`/`gender` como códigos numéricos (ver
// legacy config/enums.php). v2 v2 permite además texto libre en `parties`
// desde el alta nueva (admin/candidatos.php) — este helper resuelve ambos
// formatos sin asumir uno solo (Mandamiento #9: no se altera el schema para
// forzar un único formato sobre datos históricos ya migrados).
// =============================================================================

const CANDIDATE_POSITIONS = [
    1 => 'Diputado Federal',
    2 => 'Diputado Local',
    3 => 'Gobernador',
    4 => 'Alcaldía de Los Cabos',
];

const CANDIDATE_PARTIES = [
    1  => 'Morena',
    2  => 'Partido Verde Ecologista de México',
    3  => 'Movimiento Ciudadano',
    4  => 'Partido de la Revolución Democrática',
    5  => 'Redes Sociales Progresistas',
    6  => 'Partido Acción Nacional',
    7  => 'Partido del Trabajo',
    8  => 'Fuerza por México',
    9  => 'Candidatura Independiente',
    10 => 'Partido Revolucionario Institucional',
    11 => 'Partido Encuentro Solidario',
    12 => 'Nueva Alianza',
    13 => 'Partido Humanista',
    14 => 'BCS Coherente',
    15 => 'Partido de Renovación Sudcaliforniana',
    16 => 'Partido Encuentro Social',
    17 => 'Unidos Contigo',
];

/** Mapas de Google My Maps por distrito local (position=2) — referencia pública ya existente del ciclo 2021. */
const CANDIDATE_DISTRICT_MAPS = [
    1  => 'https://www.google.com/maps/d/u/0/viewer?mid=1qCNvZZTYT2IVQrptKh4W139VlVjhRZuX&ll=23.01567279448437%2C-109.90512550000001&z=11',
    7  => 'https://www.google.com/maps/d/u/0/viewer?mid=1cKYtIf6jk2S7P6sWTf_yqQbxAAAR3FUg&ll=23.121815860342902%2C-109.7454135&z=13',
    8  => 'https://www.google.com/maps/d/u/0/viewer?mid=1eKDzST7VDzbTYxC9LJt6HCTq2HnoK0iF&ll=22.946444496415893%2C-110.0057545&z=12',
    9  => 'https://www.google.com/maps/d/u/0/viewer?mid=1bSuCkkeh-ZpsDXKioVXP1kEBBcYsB-h7&ll=22.923983921550946%2C-109.93190500000001&z=15',
    12 => 'https://www.google.com/maps/d/u/0/viewer?mid=1DddUlJQqsSzzd4UyGIAVbuHfiJ5rjxTh&ll=23.359717590342687%2C-109.68168250000001&z=10',
    16 => 'https://www.google.com/maps/d/u/0/viewer?mid=1Vs4NHKg8w1FvhZwqya77cf6X7mPMfxSD&ll=22.900337419470024%2C-109.9291435&z=14',
];

/**
 * Resuelve la columna `parties` a texto humano — si el valor completo es una
 * lista de códigos numéricos separados por coma (formato de los 73 registros
 * migrados), los traduce vía CANDIDATE_PARTIES; si trae texto libre (formato
 * de altas nuevas desde v2), lo regresa tal cual.
 */
function resolve_candidate_parties(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }

    $parts = array_map('trim', explode(',', $raw));
    $isAllNumericCodes = array_reduce(
        $parts,
        fn(bool $carry, string $p) => $carry && $p !== '' && ctype_digit($p) && isset(CANDIDATE_PARTIES[(int) $p]),
        true
    );

    if (!$isAllNumericCodes) {
        return $raw; // texto libre, ya humano
    }

    return implode(', ', array_map(fn(string $p) => CANDIDATE_PARTIES[(int) $p], $parts));
}

function resolve_candidate_position(?int $position): string
{
    return CANDIDATE_POSITIONS[$position] ?? 'Candidatura';
}
