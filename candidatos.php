<?php

declare(strict_types=1);

// =============================================================================
// candidatos.php — Directorio público de Candidatos, agrupado por cargo (y por
// distrito para Diputados Locales, con enlace a su mapa) — cierra el gap del
// microsite legacy "Elecciones 2021" (Admin\CandidateController::candidatos()),
// consumiendo la tabla `candidates` ya existente (Mandamiento #9: sin schema
// nuevo). Mismo patrón de seguridad que categoria.php/index.php.
// =============================================================================

require_once __DIR__ . '/api/conexion.php';
require_once __DIR__ . '/helpers/security_shield.php';
require_once __DIR__ . '/helpers/base_path.php';
require_once __DIR__ . '/helpers/media.php';
require_once __DIR__ . '/helpers/candidate_enums.php';

if (is_ip_banned()) {
    http_response_code(403);
    exit('Acceso denegado.');
}
waf_block_if_malicious();
rate_limit_enforce('page', 120, 60);

const POSITION_FEDERAL     = 1;
const POSITION_LOCAL       = 2;
const POSITION_GOBERNADOR  = 3;
const POSITION_ALCALDE     = 4;

$database = new Database();
$pdo      = $database->getConnection();

$stmt = $pdo->query('SELECT `id`, `name`, `photo`, `parties`, `position`, `district` FROM `candidates` ORDER BY `name` ASC');
$all  = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byPosition = [
    POSITION_GOBERNADOR => [],
    POSITION_ALCALDE    => [],
    POSITION_FEDERAL    => [],
];
$byDistrict = [];

foreach ($all as $candidate) {
    $position = (int) $candidate['position'];
    if ($position === POSITION_LOCAL) {
        $district = $candidate['district'] !== null ? (int) $candidate['district'] : 0;
        $byDistrict[$district][] = $candidate;
    } elseif (isset($byPosition[$position])) {
        $byPosition[$position][] = $candidate;
    }
}
ksort($byDistrict);

$pageTitle = 'Candidatos — CaboVision.tv';

require __DIR__ . '/views/partials/header.php';

/** Tarjeta compacta de candidato — más simple que article_card.php, no comparte layout con notas. */
function render_candidate_card(array $c): void
{
    $photoUrl = resolve_media_path($c['photo'] ?? null);
    $parties  = resolve_candidate_parties($c['parties'] ?? '');
    ?>
    <div class="candidate-card">
        <img class="candidate-card__photo" src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" width="120" height="120">
        <div class="candidate-card__body">
            <span class="candidate-card__name"><?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($parties !== ''): ?>
                <span class="candidate-card__party"><?= htmlspecialchars($parties, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>

<h1 class="arf-portada__title">Candidatos</h1>

<div class="arf-layout">
    <div class="arf-layout__main candidates-directory">

        <?php if (!empty($byPosition[POSITION_GOBERNADOR])): ?>
            <section class="candidates-section">
                <h2><?= htmlspecialchars(resolve_candidate_position(POSITION_GOBERNADOR), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="candidates-grid">
                    <?php foreach ($byPosition[POSITION_GOBERNADOR] as $c) render_candidate_card($c); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($byPosition[POSITION_ALCALDE])): ?>
            <section class="candidates-section">
                <h2><?= htmlspecialchars(resolve_candidate_position(POSITION_ALCALDE), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="candidates-grid">
                    <?php foreach ($byPosition[POSITION_ALCALDE] as $c) render_candidate_card($c); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($byPosition[POSITION_FEDERAL])): ?>
            <section class="candidates-section">
                <h2><?= htmlspecialchars(resolve_candidate_position(POSITION_FEDERAL), ENT_QUOTES, 'UTF-8') ?> — Distrito II Los Cabos</h2>
                <div class="candidates-grid">
                    <?php foreach ($byPosition[POSITION_FEDERAL] as $c) render_candidate_card($c); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php foreach ($byDistrict as $district => $candidates): ?>
            <section class="candidates-section">
                <h2>
                    Diputados Locales — Distrito <?= (int) $district ?>
                    <?php if (isset(CANDIDATE_DISTRICT_MAPS[$district])): ?>
                        <a class="candidates-section__map-link" href="<?= htmlspecialchars(CANDIDATE_DISTRICT_MAPS[$district], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Ver mapa del distrito</a>
                    <?php endif; ?>
                </h2>
                <div class="candidates-grid">
                    <?php foreach ($candidates as $c) render_candidate_card($c); ?>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if (empty($all)): ?>
            <p>No hay candidatos registrados todavía.</p>
        <?php endif; ?>
    </div>

    <aside class="arf-layout__aside">
        <?php $placement = 'lateral'; require __DIR__ . '/views/partials/sponsor_banner.php'; ?>
    </aside>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
