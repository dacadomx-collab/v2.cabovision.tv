<?php

declare(strict_types=1);

// =============================================================================
// api/sponsors_redirect.php — Redirector intermedio del AdServer interno
// Endpoint: GET /api/sponsors_redirect.php?id=123
// Auth: Público (es un enlace de banner, clicado por visitantes anónimos)
//
// Propósito: cada clic en un banner de `sponsor_banners` pasa por AQUÍ antes
// de llegar al anunciante — permite contar el clic en el servidor (blindado
// contra ad-blockers, que sí bloquean scripts de tracking del lado cliente
// pero no pueden bloquear una navegación real a esta URL propia) y registrar
// intentos sospechosos en `perimetral_incidents`.
//
// NO es un endpoint JSON — es un redirector HTTP puro (302), por eso no usa
// helpers/response.php ni el Response Contract estándar (excepción de
// arquitectura, igual que api/reporte_forense_fragment.php con HTML).
// =============================================================================

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';

const HOME_FALLBACK = '/CaboVision.tv/index.php';

/**
 * Atribución UTM (2026-07-23, Growth Marketing — MODULO_04): cada clic
 * saliente hacia el sitio del patrocinador se marca con utm_source/medium/
 * campaign/content, para que el ANUNCIANTE vea en su propio Analytics que el
 * tráfico vino de CaboVision.tv y de qué campaña/posición exacta — sin esto,
 * el patrocinador no tiene forma de atribuir conversiones a la pauta que
 * compró. Los parámetros del destino real SIEMPRE ganan sobre los nuestros
 * (array_merge con $existing al final) — nunca se pisa un UTM que el propio
 * anunciante ya haya puesto en su `redirect_url`.
 */
function appendUtmParams(string $url, string $sponsorName, string $placementType): string
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return $url; // ya se validó como URL http(s) antes de llegar aquí; defensivo nada más
    }

    parse_str($parts['query'] ?? '', $existing);

    $utm = [
        'utm_source'   => 'cabovision',
        'utm_medium'   => 'banner',
        'utm_campaign' => slugify_text($sponsorName) ?: 'patrocinador',
        'utm_content'  => $placementType,
    ];

    $query = http_build_query(array_merge($utm, $existing));
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return "{$parts['scheme']}://{$parts['host']}{$port}{$path}?{$query}{$fragment}";
}

function logPerimetralIncident(PDO $pdo, string $type, string $ip, ?string $session): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO `perimetral_incidents` (`incident_type`, `ip_address`, `user_session`, `created_at`)
             VALUES (:type, :ip, :session, NOW())'
        );
        $stmt->execute([':type' => $type, ':ip' => $ip, ':session' => $session]);
    } catch (\PDOException $e) {
        // El logging de incidentes nunca debe romper la redirección real del visitante.
        error_log('[' . date('Y-m-d H:i:s') . '] [sponsors_redirect::logPerimetralIncident] ' . $e->getMessage());
    }
}

$clientIp = sanitize_string((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 45);
$userSession = sanitize_string((string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? ($_COOKIE['PHPSESSID'] ?? '')), 255);
$userSession = $userSession !== '' ? $userSession : null;

$bannerId = sanitize_int($_GET['id'] ?? null, 0);

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    if ($bannerId <= 0) {
        logPerimetralIncident($pdo, 'invalid_key', $clientIp, $userSession);
        header('Location: ' . HOME_FALLBACK, true, 302);
        exit;
    }

    // Incremento atómico: UPDATE ... SET accumulated_clicks = accumulated_clicks + 1
    // en la misma sentencia evita condiciones de carrera entre clics concurrentes
    // (no requiere leer-modificar-escribir desde PHP).
    $updateStmt = $pdo->prepare(
        "UPDATE `sponsor_banners`
         SET `accumulated_clicks` = `accumulated_clicks` + 1
         WHERE `id` = :id AND `status` = 'activo' AND CURDATE() BETWEEN `start_date` AND `end_date`"
    );
    $updateStmt->execute([':id' => $bannerId]);

    if ($updateStmt->rowCount() === 0) {
        // No existe, está pausado/finalizado, o fuera de vigencia — no es un
        // error de servidor, pero sí una anomalía perimetral a registrar.
        logPerimetralIncident($pdo, 'invalid_key', $clientIp, $userSession);
        header('Location: ' . HOME_FALLBACK, true, 302);
        exit;
    }

    $selectStmt = $pdo->prepare('SELECT `redirect_url`, `sponsor_name`, `placement_type` FROM `sponsor_banners` WHERE `id` = :id LIMIT 1');
    $selectStmt->execute([':id' => $bannerId]);
    $row = $selectStmt->fetch(\PDO::FETCH_ASSOC);

    $destination = $row['redirect_url'] ?? '';

    // Blindaje anti open-redirect: solo se sigue si es una URL absoluta http(s)
    // válida — nunca una ruta que pueda interpretarse como local/relativa a
    // otro esquema (malware_behavior.md: ninguna redirección se resuelve a
    // partir de datos externos sin control).
    if (!is_string($destination) || filter_var($destination, FILTER_VALIDATE_URL) === false
        || !str_starts_with($destination, 'http://') && !str_starts_with($destination, 'https://')) {
        logPerimetralIncident($pdo, 'ad_fraud', $clientIp, $userSession);
        header('Location: ' . HOME_FALLBACK, true, 302);
        exit;
    }

    $destination = appendUtmParams($destination, (string) $row['sponsor_name'], (string) $row['placement_type']);

    header('Location: ' . $destination, true, 302);
    exit;
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [sponsors_redirect] ' . $e->getMessage());
    header('Location: ' . HOME_FALLBACK, true, 302);
    exit;
}
