<?php
/**
 * views/partials/sponsor_banner.php — Zona de banner real del AdServer.
 * @var string $placement 'superior'|'lateral'|'intercalado' — debe definirse antes de incluir.
 *
 * Cero CLS: el contenedor reserva `aspect-ratio` fijo ANTES de que la imagen
 * cargue (igual criterio que .article-card__media) — el layout nunca salta
 * cuando el banner real entra. Wiring real con sponsor-telemetry.js
 * (data-sponsor-banner-id) y api/sponsors_redirect.php (clic contado server-side).
 */
require_once __DIR__ . '/../../api/conexion.php';
require_once __DIR__ . '/../../helpers/base_path.php';

$placement = $placement ?? 'lateral';
$banner    = null;

try {
    $pdo = (new Database())->getConnection();
    $stmt = $pdo->prepare(
        "SELECT id, sponsor_name, image_path, redirect_url FROM sponsor_banners
         WHERE status = 'activo' AND placement_type = :placement
           AND start_date <= CURDATE() AND end_date >= CURDATE()
         ORDER BY RAND() LIMIT 1"
    );
    $stmt->execute([':placement' => $placement]);
    $banner = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [sponsor_banner partial] ' . $e->getMessage());
}

if ($banner === null) {
    return; // sin campaña real activa para esta posición — no se renderiza nada, no un placeholder falso
}
?>
<div class="sponsor-slot sponsor-slot--<?= htmlspecialchars($placement, ENT_QUOTES, 'UTF-8') ?>">
    <span class="sponsor-slot__label">Publicidad</span>
    <a href="<?= base_path() ?>/api/sponsors_redirect.php?id=<?= (int) $banner['id'] ?>"
       target="_blank" rel="noopener sponsored"
       data-sponsor-banner-id="<?= (int) $banner['id'] ?>"
       data-viewability-threshold="0.5">
        <?php
        // sponsor_banners.image_path se guardó como ruta absoluta desde la raíz
        // del dominio (ej. "/assets/img/ozuna.jpg"), pero en XAMPP local este
        // proyecto vive bajo /CaboVision.tv/ — sin ese prefijo, el navegador
        // pide la imagen un nivel arriba de donde realmente está. base_path()
        // resuelve el prefijo real del entorno (vacío en un dominio real).
        $bannerImageSrc = str_starts_with($banner['image_path'], base_path() . '/')
            ? $banner['image_path']
            : base_path() . $banner['image_path'];
        ?>
        <img src="<?= htmlspecialchars($bannerImageSrc, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($banner['sponsor_name'], ENT_QUOTES, 'UTF-8') ?>"
             loading="lazy" width="300" height="250">
    </a>
</div>
