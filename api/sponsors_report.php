<?php

declare(strict_types=1);

// =============================================================================
// api/sponsors_report.php — Panel Ejecutivo de Patrocinadores (AdServer B2B)
// Endpoint: GET /api/sponsors_report.php?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
// Auth: Bearer JWT + requireRole(['Admin']) — datos comerciales sensibles.
//
// Fuente real: sponsor_banners (catálogo) + sponsors_metricas (append-only,
// impresion/clic ya filtrados por MRC viewability — ver
// assets/js/sponsor-telemetry.js, solo dispara 'impression' tras >=1s visible
// sobre el umbral configurado, por diseño ya son "impresiones viables", no
// impresiones brutas sin filtrar).
//
// Precisión honesta (Mandamiento #4): "Tiempo Promedio Visible" NO se
// reporta aquí — sponsors_metricas no tiene columna de duración (dwell_ms);
// el JS actual solo registra el evento de impresión al cruzar el umbral, no
// cuánto tiempo permaneció visible después. Agregar esa métrica requeriría
// una columna nueva + cambio de sponsor-telemetry.js — no inventado aquí.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';

requireRole(['Admin'], $authPayload);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

$desde = sanitize_string((string) ($_GET['desde'] ?? ''), 10);
$hasta = sanitize_string((string) ($_GET['hasta'] ?? ''), 10);

// Rango por defecto: últimos 30 días — evita un table scan sin límite si el
// cliente no manda fechas.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    $desde = (new DateTimeImmutable('-30 days'))->format('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    $hasta = (new DateTimeImmutable())->format('Y-m-d');
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $stmt = $pdo->prepare(
        "SELECT
            sb.id,
            sb.sponsor_name,
            sb.placement_type,
            sb.status,
            sb.purchased_impressions,
            sb.accumulated_clicks AS clicks_totales_historicos,
            COUNT(CASE WHEN sm.tipo_evento = 'impresion' THEN 1 END) AS impresiones_periodo,
            COUNT(CASE WHEN sm.tipo_evento = 'clic' THEN 1 END) AS clics_periodo,
            -- Clics únicos (2026-07-23): COUNT(DISTINCT hash_sesion) sobre el
            -- mismo hash anti-fraude que ya calcula sponsors_track.php — un
            -- visitante que hace clic 2 veces cuenta 1 vez aquí, aunque
            -- clics_periodo (arriba) siga contando el evento bruto. Columna
            -- ya indexada (idx_sponsors_metricas_sesion), sin cambio de schema.
            COUNT(DISTINCT CASE WHEN sm.tipo_evento = 'clic' THEN sm.hash_sesion END) AS clics_unicos_periodo,
            ROUND(
                100 * COUNT(CASE WHEN sm.tipo_evento = 'clic' THEN 1 END)
                / NULLIF(COUNT(CASE WHEN sm.tipo_evento = 'impresion' THEN 1 END), 0)
            , 2) AS ctr_pct
         FROM `sponsor_banners` sb
         LEFT JOIN `sponsors_metricas` sm
            ON sm.banner_id = sb.id
            AND DATE(sm.created_at) BETWEEN :desde AND :hasta
         GROUP BY sb.id
         ORDER BY impresiones_periodo DESC"
    );
    $stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
    $porBanner = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totales = [
        'impresiones_periodo'  => 0,
        'clics_periodo'        => 0,
        'clics_unicos_periodo' => 0,
    ];
    foreach ($porBanner as $row) {
        $totales['impresiones_periodo']  += (int) $row['impresiones_periodo'];
        $totales['clics_periodo']        += (int) $row['clics_periodo'];
        $totales['clics_unicos_periodo'] += (int) $row['clics_unicos_periodo'];
    }
    $totales['ctr_pct'] = $totales['impresiones_periodo'] > 0
        ? round(100 * $totales['clics_periodo'] / $totales['impresiones_periodo'], 2)
        : null;

    send_success('Reporte generado.', [
        'rango'      => ['desde' => $desde, 'hasta' => $hasta],
        'totales'    => $totales,
        'por_banner' => $porBanner,
        'nota'       => 'CTR calculado sobre impresiones ya filtradas por viewability (>=1s visible). '
                       . 'Clics únicos = hash_sesion distintos por banner (el total general es la suma por banner, '
                       . 'no deduplicado entre banners distintos). '
                       . 'Tiempo Promedio Visible no disponible: sponsors_metricas no registra duración todavía.',
    ]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [sponsors_report] ' . $e->getMessage());
    send_error('Error interno al generar el reporte.', 500);
}
