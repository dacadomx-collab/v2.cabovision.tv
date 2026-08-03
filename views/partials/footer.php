    <?php require_once __DIR__ . '/../../helpers/base_path.php'; ?>
    </main>
    <footer class="site-footer">
        <div class="container">
            <button type="button" class="back-to-top" id="back-to-top">↑ Ir a inicio</button>

            <div class="newsletter-box">
                <h2>Suscríbete</h2>
                <p>Recibe en tu correo las últimas noticias de Los Cabos y Baja California Sur.</p>
                <!--
                    Mismo Mailchimp list ya usado por el sistema legacy
                    (u=f56aedc7c2009bc915b22277c, id=455b913276) — se reutiliza
                    a propósito para no perder a los suscriptores existentes ni
                    duplicar listas. El honeypot oculto (b_...) es requisito del
                    propio Mailchimp para descartar bots, no algo nuestro.
                -->
                <form class="newsletter-box__form" action="https://cabovision.us1.list-manage.com/subscribe/post?u=f56aedc7c2009bc915b22277c&amp;id=455b913276" method="post" target="_blank" novalidate>
                    <input type="email" name="EMAIL" placeholder="Correo electrónico" aria-label="Correo electrónico" required>
                    <div aria-hidden="true" style="position:absolute;left:-5000px;">
                        <input type="text" name="b_f56aedc7c2009bc915b22277c_455b913276" tabindex="-1" value="">
                    </div>
                    <button type="submit">Suscribirme</button>
                </form>
            </div>

            <nav aria-label="Enlaces de pie de página">
                <ul id="footer-nav" class="footer-nav">
                    <?php foreach ($categoriesTopLevel ?? [] as $entry): ?>
                        <li><a href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($entry['category']['alias']) ?>"><?= htmlspecialchars($entry['category']['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                        <?php foreach ($entry['children'] as $child): ?>
                            <li><a href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($child['alias']) ?>"><?= htmlspecialchars($child['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <p class="site-footer__copy">&copy; <?= date('Y') ?> CaboVision.tv. Todos los derechos reservados.</p>
        </div>
    </footer>

    <button type="button" id="floating-back-to-top" class="floating-back-to-top" aria-label="Volver arriba" hidden>↑</button>

    <!-- swiper.min.js removido 2026-07-21 (auditoría Fast by Design): 126 KB
         sin ningún consumidor real .swiper en el proyecto (Mandamiento #8). -->
    <script src="<?= base_path() ?>/assets/js/main.js" defer></script>
    <!-- sponsor-telemetry.js (2026-07-21): existía en el repo desde antes, pero
         nunca se cargaba en ninguna página — los banners reales de
         sponsor_banners tampoco se renderizaban en ningún lado hasta ahora
         (ver views/partials/sponsor_banner.php). Vanilla JS, <2KB, defer. -->
    <script src="<?= base_path() ?>/assets/js/sponsor-telemetry.js" defer></script>
</body>
</html>
