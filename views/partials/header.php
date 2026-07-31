<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'CaboVision.tv', ENT_QUOTES, 'UTF-8') ?></title>

    <?php
    // ── SEO / Open Graph / Twitter Cards ────────────────────────────────────
    // Variables OPCIONALES que cada vista puede definir ANTES de requerir este
    // partial: $pageDescription, $pageImage (URL absoluta), $pageUrl (canónica),
    // $pageJsonLd (string JSON ya codificado con json_encode()). Si la vista no
    // las define, se usan valores por defecto a nivel de sitio (Mandamiento #6:
    // ninguna vista se rompe por no declarar estas variables).
    $_baseScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_baseHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_baseUrl    = $_baseScheme . '://' . $_baseHost;

    $ogTitle       = $pageTitle ?? 'CaboVision.tv — Noticias de Los Cabos y Baja California Sur';
    $ogDescription = $pageDescription ?? 'Noticias de Los Cabos y Baja California Sur en tiempo real.';
    $ogImage       = $pageImage ?? ($_baseUrl . base_path() . '/assets/img/banner-cabovision.jpg');
    $ogUrl         = $pageUrl ?? ($_baseUrl . ($_SERVER['REQUEST_URI'] ?? (base_path() . '/index.php')));
    ?>
    <meta name="description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="canonical" href="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8') ?>">

    <meta property="og:type" content="<?= isset($pageJsonLd) ? 'article' : 'website' ?>">
    <meta property="og:site_name" content="CaboVision.tv">
    <meta property="og:locale" content="es_MX">
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8') ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">

    <?php if (isset($pageJsonLd) && $pageJsonLd !== ''): ?>
    <script type="application/ld+json"><?= $pageJsonLd ?></script>
    <?php endif; ?>

    <?php require __DIR__ . '/analytics.php'; ?>

    <?php
    // ── NAV DE CATEGORÍAS — RENDER SERVER-SIDE (foreach puro de PHP) ────────────
    // Antes se poblaba vía fetch() de JS a api/categories_list.php (dejaba el
    // menú vacío hasta que terminara la petición asíncrona, y los crawlers sin
    // JS nunca lo veían). Ahora se consulta directo aquí, en el mismo request
    // que ya sirve la página — SEO real y sin parpadeo. api/categories_list.php
    // se conserva para el <select> del panel admin (assets/js/admin.js) y
    // como endpoint JSON público reusable, no se elimina (Mandamiento 8: no es
    // código muerto, tiene otro consumidor real).
    require_once __DIR__ . '/../../api/conexion.php';
    require_once __DIR__ . '/../../helpers/input_sanitizer.php';
    require_once __DIR__ . '/../../helpers/object_cache.php';
    require_once __DIR__ . '/../../helpers/base_path.php';

    $categoriesTopLevel = [];
    $mainMenuCurated = [];
    $losCabosALaCartaChildren = [];
    try {
        // Caché de objetos (APCu, ver helpers/object_cache.php): la consulta de
        // categorías + toda su derivación (top-level, menú curado, hijos) antes
        // corría en CADA carga de página (index.php/categoria.php/articulo.php
        // incluyen header.php). TTL de 300s como red de seguridad — hoy no
        // existe ningún endpoint que mute `categories`, así que no hay
        // invalidación explícita que enganchar todavía (ver cabecera del
        // archivo); cuando exista, debe llamar cache_invalidate('cabovision_nav_v1').
        $nav = cache_remember('cabovision_nav_v1', 300, static function () {
            $navDb  = new Database();
            $navPdo = $navDb->getConnection();
            $navStmt = $navPdo->query(
                "SELECT `id`, `name`, `alias`, `parent_id`
                 FROM `categories`
                 WHERE LOWER(`status`) = 'publicada'
                 ORDER BY `name` ASC"
            );
            $allCategories = $navStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Reparación de mojibake confirmado (helpers/input_sanitizer.php::
            // repair_known_mojibake()) — se aplica UNA sola vez aquí, en la fuente
            // compartida que header.php Y footer.php consumen, en vez de repetirla
            // en cada punto de salida HTML.
            foreach ($allCategories as &$categoryRow) {
                $categoryRow['name'] = repair_known_mojibake($categoryRow['name']);
            }
            unset($categoryRow);

            $byId = [];
            foreach ($allCategories as $cat) {
                $byId[(int) $cat['id']] = $cat;
            }

            // "Top-level" = sin padre publicado (parent_id NULL, 0, 1 -ROOT-, o un
            // padre que no está en la lista de publicadas) — mismo criterio que
            // usaba main.js, ahora resuelto en PHP.
            $isTopLevel = static function (array $cat) use ($byId): bool {
                $parentId = $cat['parent_id'];
                return $parentId === null || (int) $parentId === 0 || (int) $parentId === 1 || !isset($byId[(int) $parentId]);
            };

            $categoriesTopLevel = [];
            foreach ($allCategories as $cat) {
                if (!$isTopLevel($cat)) {
                    continue;
                }
                $children = array_values(array_filter(
                    $allCategories,
                    static fn (array $c): bool => !$isTopLevel($c) && (int) $c['parent_id'] === (int) $cat['id']
                ));
                $categoriesTopLevel[] = ['category' => $cat, 'children' => $children];
            }

            // ── MENÚ PRINCIPAL CURADO — estructura verificada contra
            // CACHE/Portada_ESTERILIZADA.html (guía de funcionalidad autorizada por
            // el Arquitecto, 2026-07-16): PORTADA, NOTICIAS, NATURALEZA, ESPECIAL,
            // MICROSITIOS, LOS CABOS A LA CARTA. $categoriesTopLevel (arriba) sigue
            // completo y se usa en footer.php como índice secundario del sitio —
            // aquí solo se selecciona el subconjunto curado para la barra
            // principal, por ID de categoría real (verificados publicados):
            // Noticias=70, Naturaleza=35, Especiales=31, Micrositios=12.
            $mainMenuCuratedIds = [70, 35, 31, 12];
            $mainMenuCurated = [];
            foreach ($mainMenuCuratedIds as $curatedId) {
                if (!isset($byId[$curatedId])) {
                    continue; // Categoría despublicada o inexistente — no rompe el menú.
                }
                $cat = $byId[$curatedId];
                $children = array_values(array_filter(
                    $allCategories,
                    static fn (array $c): bool => (int) $c['parent_id'] === $curatedId
                ));
                $mainMenuCurated[] = ['category' => $cat, 'children' => $children];
            }

            // "LOS CABOS A LA CARTA" en el sitio original enlazaba a rutas propias
            // de Laravel (/entrevistas, /programas-completos) que no existen en
            // esta arquitectura basada en categorías. Se mapea a las categorías
            // reales y publicadas que las reemplazaron: Entrevistas Radio Cabo
            // Mil (134) y Programas Completos Cabo Mil (133) — mismo contenido,
            // misma intención editorial, sin inventar rutas ni tablas nuevas.
            $losCabosALaCartaChildren = [];
            foreach ([134, 133] as $curatedId) {
                if (isset($byId[$curatedId])) {
                    $losCabosALaCartaChildren[] = $byId[$curatedId];
                }
            }

            return [
                'categoriesTopLevel'       => $categoriesTopLevel,
                'mainMenuCurated'          => $mainMenuCurated,
                'losCabosALaCartaChildren' => $losCabosALaCartaChildren,
            ];
        });

        $categoriesTopLevel       = $nav['categoriesTopLevel'];
        $mainMenuCurated          = $nav['mainMenuCurated'];
        $losCabosALaCartaChildren = $nav['losCabosALaCartaChildren'];
    } catch (\PDOException $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] [header.php nav] ' . $e->getMessage());
        // Fallback silencioso: el menú sale solo con "Portada" — nunca rompe la página.
    }

    // ── FILTRO CRONOLÓGICO (2026-07-31) — rango real de años con notas
    // publicadas, cacheado igual que el menú (TTL 300s, misma red de
    // seguridad: MIN/MAX(YEAR(published_at)) no cambia con cada request).
    require_once __DIR__ . '/../../helpers/date_filter.php';
    $dateFilterActive = resolve_date_filter_range();
    try {
        $yearRange = cache_remember('cabovision_year_range_v1', 300, static function () {
            $yearDb  = new Database();
            $yearPdo = $yearDb->getConnection();
            $row = $yearPdo->query('SELECT MIN(YEAR(`published_at`)) AS min_y, MAX(YEAR(`published_at`)) AS max_y FROM `articles`')
                ->fetch(\PDO::FETCH_ASSOC);
            return ['min' => (int) ($row['min_y'] ?? date('Y')), 'max' => (int) ($row['max_y'] ?? date('Y'))];
        });
    } catch (\PDOException $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] [header.php date_filter] ' . $e->getMessage());
        $yearRange = ['min' => (int) date('Y'), 'max' => (int) date('Y')];
    }

    // El filtro solo tiene sentido en listados (portada/categoría) — en
    // articulo.php (una sola nota) no hay nada que filtrar.
    $currentScript   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $showDateFilter  = in_array($currentScript, ['index.php', 'categoria.php'], true);
    $dateFilterAction = $currentScript === 'categoria.php' ? 'categoria.php' : 'index.php';
    ?>

    <link rel="icon" href="<?= base_path() ?>/favicon.ico">

    <!--
        NOTA DE ARQUITECTURA — orden de cascada crítico, no accidental:
        bootstrap.css/app.css redefinen `.card`, `.container`, `.main-menu`
        (clases que ya usamos en todo el sitio). Con igual especificidad, la
        regla que aparece MÁS TARDE en el DOM gana — por eso main.css debe
        quedar DESPUÉS de ambos en el HTML, aunque cargue por una vía distinta
        (inline vs. preload). El orden de DESCARGA real (preload asíncrono) no
        cambia el orden de CASCADA, que sigue la posición en el documento.
        Bootstrap trae ~1000 reglas `!important`, pero son de clases
        utilitarias (`.d-none`, `.text-center`, etc.) que NUNCA usamos en
        nuestro propio HTML — no matchean nada nuestro, no aplican.
        Riesgo residual conocido y aceptado: `articles.content` (HTML crudo
        importado del CMS original) puede traer clases de Bootstrap de su
        propia época y sí heredar esos estilos dentro del cuerpo del artículo
        — eso es correcto, es contenido de esa misma plantilla.
    -->
    <!-- Rendimiento Front-End Extremo (2026-07-21): bootstrap.css/app.css
         (113 KB combinados, solo mecánica de grid/dropdown, no crítico para
         el primer pintado) se difieren con preload+swap — no bloquean el
         render. Van PRIMERO en el DOM a propósito (ver nota de arriba: deben
         perder los empates de especificidad contra main.css). -->
    <link rel="preload" href="<?= base_path() ?>/assets/legacy/bootstrap.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="<?= base_path() ?>/assets/legacy/app.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link rel="stylesheet" href="<?= base_path() ?>/assets/legacy/bootstrap.css">
        <link rel="stylesheet" href="<?= base_path() ?>/assets/legacy/app.css">
    </noscript>

    <!-- Critical CSS inline: main.css (10 KB, nuestro sistema real de diseño)
         directo aquí, sin request de red, cero FOUC — y en posición POSTERIOR
         a bootstrap/app arriba, para seguir ganando la cascada como antes. -->
    <style><?php
        $mainCssPath = __DIR__ . '/../../assets/css/main.css';
        echo is_file($mainCssPath) ? file_get_contents($mainCssPath) : '';
    ?></style>
    <!-- swiper.min.css removido 2026-07-21 (auditoría Fast by Design): 23 KB
         cargados en cada página sin ningún carrusel .swiper real en ningún
         archivo del proyecto — verificado por búsqueda completa antes de
         quitarlo (Mandamiento #8, código huérfano). -->

    <script>
        // Anti-FOUC: aplica el tema guardado ANTES del primer paint.
        (function () {
            var saved = localStorage.getItem('cabovision_theme');
            if (saved === 'dark' || saved === 'light') {
                document.documentElement.setAttribute('data-theme', saved);
            }
        })();
    </script>
    <!-- Prefijo de ruta del sitio para JS estático (no procesa PHP, no puede
         leer base_path() directo) — mismo valor que helpers/base_path.php,
         derivado de APP_URL. Debe declararse ANTES de cualquier <script src>
         que lo consuma (assets/js/*.js). -->
    <script>window.BASE_PATH = "<?= htmlspecialchars(base_path(), ENT_QUOTES, 'UTF-8') ?>";</script>
</head>
<body>
    <!-- Barra de navegación móvil legítima, clonada de CACHE/Portada_ESTERILIZADA.html
         (líneas 597-680). app.css ya la oculta automáticamente en desktop
         (.menu-xs{display:none !important} a partir de 991px) — por eso convive
         con .site-header sin duplicarse; main.css oculta .site-header por debajo
         de esa misma marca (ver assets/css/main.css). -->
    <div id="headerxs" class="row sticky menu-xs" style="background:#f8f9fa;">
        <div class="col-2">
            <div id="menuToggle" class="row">
                <input type="checkbox" aria-label="Abrir menú de navegación">
                <span></span>
                <span></span>
                <span></span>
                <ul id="menu">
                    <li><a href="<?= base_path() ?>/index.php">Portada</a></li>
                    <?php foreach ($mainMenuCurated as $entry): ?>
                        <li><a href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($entry['category']['alias']) ?>"><?= htmlspecialchars($entry['category']['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                        <?php foreach ($entry['children'] as $child): ?>
                            <li><a href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($child['alias']) ?>">— <?= htmlspecialchars($child['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php if (!empty($losCabosALaCartaChildren)): ?>
                        <li><a href="#">Los Cabos a la Carta</a></li>
                        <?php foreach ($losCabosALaCartaChildren as $child): ?>
                            <li><a href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($child['alias']) ?>">— <?= htmlspecialchars($child['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="col-10 text-center">
            <a href="<?= base_path() ?>/index.php">
                <img id="logoc" style="padding:10px;" width="400" height="74" src="<?= base_path() ?>/assets/img/logocabovis_glow.png" alt="CaboVision.tv — Noticias de Los Cabos y Baja California Sur">
            </a>
        </div>
    </div>

    <!-- Fila de logo + banner superior — ARF-Grid real (2026-07-22, ver
         assets/css/main.css §FILA LOGO+BANNER): antes usaba .row/.col-lg-6 de
         Bootstrap crudo, reemplazado a petición explícita del Arquitecto para
         dejar de depender del grid heredado en este bloque. -->
    <div class="gkPage">
        <div class="arf-grid arf-header-row">
            <div class="arf-col-2 invisible-xs" id="logocab">
                <a href="<?= base_path() ?>/index.php">
                    <img src="<?= base_path() ?>/assets/img/logocabovis_glow.png" alt="CaboVision.tv — Noticias de Los Cabos y Baja California Sur" width="400" height="74">
                </a>
            </div>
            <div class="arf-col-2" id="banner-top">
                <img class="banner-cab" src="<?= base_path() ?>/assets/img/banner-cabovision.jpg" alt="CaboVision.tv" width="1213" height="275">
            </div>
        </div>
    </div>

    <!-- Menú de escritorio — Bootstrap 4 nativo (.navbar, .navbar-expand-lg,
         .navbar-nav, .nav-item, .nav-link, .dropdown-menu, .dropdown-item).
         navbar-expand-lg de bootstrap.css oculta todo el bloque por debajo de
         992px por CSS puro, sin JS de Bootstrap (coexiste con #headerxs/
         .menu-xs, el off-canvas móvil de arriba). Diseño de fila única FLUIDA
         (sin scroll, sin JS de posicionamiento): cada <li> reparte el ancho
         disponible a partes iguales (flex:1 1 0) y su texto puede envolver a
         una segunda línea DENTRO de su propia píldora si el nombre es largo
         — el renglón de items nunca se parte en dos filas, así que los
         dropdowns (position:absolute normal) nunca se solapan con nada.
         Ver assets/css/main.css §HEADER/NAV para el detalle. -->
    <div id="myHeader" class="row">
        <nav class="navbar navbar-expand-lg main-menu" id="main-menu">
            <div class="collapse navbar-collapse w-100" id="main-menu-collapse">
                <ul class="navbar-nav" id="main-nav">
                    <li class="nav-item active">
                        <a class="nav-link" href="<?= base_path() ?>/index.php">Portada</a>
                    </li>
                    <?php foreach ($mainMenuCurated as $entry): ?>
                        <?php $cat = $entry['category']; $children = $entry['children']; ?>
                        <?php if (empty($children)): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($cat['alias']) ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></a>
                            </li>
                        <?php else: ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($cat['alias']) ?>" role="button" aria-haspopup="true" aria-expanded="false">
                                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <div class="dropdown-menu">
                                    <?php foreach ($children as $child): ?>
                                        <a class="dropdown-item" href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($child['alias']) ?>"><?= htmlspecialchars($child['name'], ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!empty($losCabosALaCartaChildren)): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" aria-haspopup="true" aria-expanded="false">
                                Los Cabos a la Carta
                            </a>
                            <div class="dropdown-menu">
                                <?php foreach ($losCabosALaCartaChildren as $child): ?>
                                    <a class="dropdown-item" href="<?= base_path() ?>/categoria.php?alias=<?= urlencode($child['alias']) ?>"><?= htmlspecialchars($child['name'], ENT_QUOTES, 'UTF-8') ?></a>
                                <?php endforeach; ?>
                            </div>
                        </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav main-menu__aside">
                    <li class="nav-item social-top">
                        <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Cambiar modo día/noche">
                            <!-- SVG inline en vez de glifos Unicode ☀/☾ (2026-07-21): esos
                                 caracteres no tienen cobertura confiable en todas las fuentes
                                 (se veían como "C"/tofu en pruebas reales de navegador) — SVG
                                 no depende de ninguna fuente, cero peticiones de red. -->
                            <svg class="theme-toggle__icon theme-toggle__icon--sun" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <circle cx="12" cy="12" r="4"/>
                                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
                            </svg>
                            <svg class="theme-toggle__icon theme-toggle__icon--moon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                <path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5 8.5 8.5 0 1 0 20.5 14.5z"/>
                            </svg>
                        </button>
                        <a class="nav-link" style="border: transparent;margin: 0 5px;" target="_blank" href="https://www.facebook.com/cabovision/" aria-label="Facebook">
                            <svg class="social" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M13 22v-9h3l.5-3.5H13V7.2c0-1 .3-1.7 1.8-1.7H17V2.3C16.6 2.2 15.4 2 14 2c-2.9 0-4.9 1.8-4.9 5v2.5H6V13h3.1v9h3.9z"/></svg>
                        </a>
                        <a class="nav-link" style="border: transparent;margin: 0 5px;" target="_blank" href="https://twitter.com/CabovisionTV" aria-label="Twitter">
                            <svg class="social" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22 5.9c-.7.3-1.5.6-2.3.7.8-.5 1.5-1.3 1.8-2.3-.8.5-1.7.8-2.6 1a4.1 4.1 0 0 0-7 3.7A11.6 11.6 0 0 1 3.4 4.6a4 4 0 0 0 1.3 5.5c-.7 0-1.3-.2-1.9-.5v.1c0 2 1.4 3.6 3.3 4a4.1 4.1 0 0 1-1.9.1 4.1 4.1 0 0 0 3.8 2.8A8.3 8.3 0 0 1 2 18.4a11.6 11.6 0 0 0 6.3 1.8c7.5 0 11.7-6.3 11.7-11.7v-.5c.8-.6 1.5-1.3 2-2.1z"/></svg>
                        </a>
                        <a class="nav-link" style="border: transparent;margin: 0 5px;" target="_blank" href="https://www.youtube.com/user/Expresdeunic" aria-label="YouTube">
                            <svg class="social" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M23 12s0-3.5-.4-5.2a3 3 0 0 0-2.1-2.1C18.8 4 12 4 12 4s-6.8 0-8.5.7a3 3 0 0 0-2.1 2.1C1 8.5 1 12 1 12s0 3.5.4 5.2a3 3 0 0 0 2.1 2.1C5.2 20 12 20 12 20s6.8 0 8.5-.7a3 3 0 0 0 2.1-2.1c.4-1.7.4-5.2.4-5.2zM10 15.5v-7l6 3.5-6 3.5z"/></svg>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>

    <?php if ($showDateFilter): ?>
    <div class="gkPage">
        <form class="date-filter" method="get" action="<?= base_path() ?>/<?= $dateFilterAction ?>">
            <?php if ($dateFilterAction === 'categoria.php' && isset($categoryAlias)): ?>
                <input type="hidden" name="alias" value="<?= htmlspecialchars($categoryAlias, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <label for="date-filter-year">Año</label>
            <select id="date-filter-year" name="year">
                <option value="">Todos</option>
                <?php for ($y = $yearRange['max']; $y >= $yearRange['min']; $y--): ?>
                    <option value="<?= $y ?>" <?= ($dateFilterActive !== null && $dateFilterActive['year'] === $y) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <label for="date-filter-month">Mes</label>
            <select id="date-filter-month" name="month">
                <option value="">Todos</option>
                <?php foreach (['01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'] as $monthNum => $monthName): ?>
                    <option value="<?= $monthNum ?>" <?= ($dateFilterActive !== null && $dateFilterActive['month'] === (int) $monthNum) ? 'selected' : '' ?>><?= $monthName ?></option>
                <?php endforeach; ?>
            </select>
            <label for="date-filter-day">Día</label>
            <select id="date-filter-day" name="day">
                <option value="">Todos</option>
                <?php for ($d = 1; $d <= 31; $d++): ?>
                    <option value="<?= $d ?>" <?= ($dateFilterActive !== null && $dateFilterActive['day'] === $d) ? 'selected' : '' ?>><?= $d ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit">Filtrar</button>
            <?php if ($dateFilterActive !== null): ?>
                <a class="date-filter__clear" href="<?= base_path() ?>/<?= $dateFilterAction ?><?= ($dateFilterAction === 'categoria.php' && isset($categoryAlias)) ? '?alias=' . urlencode($categoryAlias) : '' ?>">Quitar filtro</a>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <main class="container">
