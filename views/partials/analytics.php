<?php

declare(strict_types=1);

// =============================================================================
// views/partials/analytics.php — Inyección aislada de Google Analytics 4 (GA4)
// Mandamiento #12 (Bóveda de Secretos): el Measurement ID vive en .env, nunca
// hardcodeado en el código fuente.
// Mandamiento #1 (Mobile-First): el tag se carga async — no bloquea el render
// ni penaliza el tiempo de carga móvil.
//
// INCLUIR ÚNICAMENTE desde views/partials/header.php, dentro de <head>.
// No incluir en ningún otro punto (Mandamiento #10: un solo punto de verdad
// para el tag global — evita doble conteo de pageviews en GA4).
// =============================================================================

$ga4EnvPath       = dirname(__DIR__, 2) . '/.env';
$ga4MeasurementId = '';

if (is_readable($ga4EnvPath)) {
    $ga4Env           = parse_ini_file($ga4EnvPath, false, INI_SCANNER_RAW) ?: [];
    $ga4MeasurementId = (string) ($ga4Env['GA4_MEASUREMENT_ID'] ?? '');
}

// Sin Measurement ID configurado en .env → no emitir ningún tag. Evita
// registrar tráfico bajo un ID vacío y evita errores de consola en local
// mientras el Arquitecto no haya dado de alta la propiedad GA4 real.
if ($ga4MeasurementId === '') {
    return;
}
?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($ga4MeasurementId, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){ dataLayer.push(arguments); }
  gtag('js', new Date());
  gtag('config', '<?= htmlspecialchars($ga4MeasurementId, ENT_QUOTES, 'UTF-8') ?>', {
    anonymize_ip: true,
    send_page_view: true
  });
</script>
