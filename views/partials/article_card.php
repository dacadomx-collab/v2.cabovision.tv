<?php
/**
 * @var array<string,mixed> $article Debe estar definida antes de incluir este partial.
 * @var bool $isPriorityCard OPCIONAL — true solo para la primera tarjeta visible
 *     (candidata real a LCP, above-the-fold). Priority Loading (Growth
 *     Marketing doc): esa imagen NUNCA lleva loading="lazy" ni debe competir
 *     por prioridad de red con el resto — se sirve fetchpriority="high".
 *     Si la vista no la define, se asume false (comportamiento previo intacto).
 */
require_once __DIR__ . '/../../helpers/media.php';
require_once __DIR__ . '/../../helpers/base_path.php';
$isPriorityCard = $isPriorityCard ?? false;
$mediaPlaceholder = media_placeholder_path();
$thumb = resolve_media_path($article['thumbnail'] ?? null) !== $mediaPlaceholder
    ? resolve_media_path($article['thumbnail'] ?? null)
    : resolve_media_path($article['image'] ?? null);
$isFallback = $thumb === $mediaPlaceholder;
?>
<article class="card arf-col-3 article-card">
    <a class="article-card__link" href="<?= base_path() ?>/articulo.php?alias=<?= urlencode($article['alias']) ?>">
        <div class="article-card__media<?= $isFallback ? ' article-card__media--fallback' : '' ?>">
            <?php if (!empty($article['featured'])): ?>
                <span class="article-card__featured">Destacado</span>
            <?php endif; ?>
            <?php if ($isFallback): ?>
                <div class="media-fallback">
                    <img src="<?= htmlspecialchars($mediaPlaceholder, ENT_QUOTES, 'UTF-8') ?>" alt="CaboVision.tv" class="media-fallback__logo" width="400" height="74">
                    <span class="media-fallback__label">Imagen no disponible</span>
                </div>
            <?php else: ?>
                <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?>"
                     <?= $isPriorityCard
                        ? 'loading="eager" fetchpriority="high"'
                        : 'loading="lazy" fetchpriority="low"' ?>>
            <?php endif; ?>
            <span class="article-card__overlay" aria-hidden="true"></span>
        </div>
        <div class="article-card__body">
            <?php if (!empty($article['category_name'])): ?>
                <span class="article-card__category"><?= htmlspecialchars($article['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <h3 class="article-card__title"><?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <?php if (!empty($article['extract'])): ?>
                <p class="article-card__extract"><?= htmlspecialchars($article['extract'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
    </a>
</article>
