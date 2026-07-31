<?php

declare(strict_types=1);

// =============================================================================
// api/reporte_forense_fragment.php — Contenido protegido del dashboard forense
// Endpoint: GET /api/reporte_forense_fragment.php
// Auth: Bearer JWT + Rol Admin (Mandamiento #14)
//
// Por qué existe este endpoint en vez de proteger admin/reporte_forense.php
// directamente con auth_middleware.php: nuestra autenticación es JWT stateless
// guardado en sessionStorage del navegador (no hay cookie de sesión PHP). Una
// navegación normal del navegador a una página .php NUNCA adjunta el header
// Authorization — solo un fetch() explícito desde JS lo hace. Por eso el gate
// real vive AQUÍ (donde sí llega el Bearer token real vía fetch), y el
// contenido sensible nunca se imprime en el HTML servido por
// admin/reporte_forense.php hasta que este endpoint lo autoriza.
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php'; // expone $authPayload, 401 si no hay Bearer válido
require_once __DIR__ . '/../helpers/response.php';

requireRole(['Admin'], $authPayload);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Método no permitido.', 405);
}

ob_start();
?>
<div class="hero">
    <span class="hero__eyebrow"><span class="dot"></span> ACADEP — Estado: Esterilizado y Operativo</span>
    <h1>SITREP Operación CaboVision.tv<br>Reconstrucción de Búnker Digital</h1>
    <p>Informe de situación consolidado: intervención forense sobre la infraestructura comprometida de <strong>cabovision.tv</strong> y reconstrucción íntegra del sistema en sandbox local blindado.</p>
    <div class="hero__meta">
        <span>Proyecto: CaboVision.tv</span>
        <span>Entorno local verificado: XAMPP · PHP 8.0.30 · MariaDB 10.4.32</span>
        <span>Clasificación: Acceso restringido — Rol Admin</span>
    </div>
    <div class="hero__meta">
        <span>Entregado: 15 de Julio de 2026 — Oficina Central de ACADEP, La Paz, Baja California Sur, México</span>
    </div>
</div>

<section>
    <div class="section-title"><span class="index">01</span> Contadores de Impacto</div>
    <div class="section-sub">Magnitud de la operación de rescate y reconstrucción, medida en tiempo, volumen de datos y amenazas documentadas.</div>
    <div class="arf-grid">
        <div class="glass metric metric--navy">
            <div class="metric__icon">⏱ Tiempo de operación</div>
            <div class="metric__value">15h<small>Proyección total de hardening · sobre ~12h de microcirugía forense activa ya invertidas</small></div>
            <div class="metric__desc">Auditoría, purga de residuos cruzados, corrección de schema real y reconstrucción modular.</div>
        </div>
        <div class="glass metric metric--gold">
            <div class="metric__icon">📦 Almacenamiento bajo ataque</div>
            <div class="metric__value">265 GB<small>Reportado por administración del hosting</small></div>
            <div class="metric__desc">Saturación de la cuenta compartida en GreenGeeks que motivó la suspensión total de la cuenta.</div>
            <span class="tag tag--report" style="margin-top:0.6rem;display:inline-block;">Bitácora de gestión — no verificado por este agente</span>
        </div>
        <div class="glass metric metric--emerald">
            <div class="metric__icon">🗄 Volumen reconquistado</div>
            <div class="metric__value">957 MB<small>10,713 artículos de noticias indexados</small></div>
            <div class="metric__desc">Base de datos <code>cabovision_local</code> — 7 tablas oficiales, verificada fila por fila contra el motor real.</div>
            <span class="tag tag--verified" style="margin-top:0.6rem;display:inline-block;">Verificado por consulta directa</span>
        </div>
        <div class="glass metric metric--crimson">
            <div class="metric__icon">🛡 Amenazas documentadas</div>
            <div class="metric__value">10<small>Artefactos de malware · 6 cuentas administrativas fraudulentas identificadas</small></div>
            <div class="metric__desc">Postmortem forense completo en <code>knowledge/malware_behavior.md</code> — neutralizados por el proveedor de hosting antes de esta reconstrucción.</div>
        </div>
    </div>
</section>

<section>
    <div class="section-title"><span class="index">02</span> Cronología de la Crisis de Almacenamiento</div>
    <div class="section-sub">Reconstrucción cronológica del incidente, de la persistencia silenciosa a la suspensión total de la cuenta de hosting.</div>
    <div class="timeline">
        <div class="glass milestone milestone--m1" style="animation-delay:0.05s;">
            <div class="milestone__date">📅 Enero – Abril 2026 <span class="tag tag--verified">Verificado</span></div>
            <h3>Hito 1 — Cabeza de Playa Silenciosa</h3>
            <p>Persistencia a nivel de identidad: creación directa en base de datos de al menos 6 cuentas administrativas fraudulentas en el WordPress comprometido (14-ene, 20-ene, un par el 25 y 29-mar, 15-abr, más una cuenta de fecha indeterminada tipo "bot de servicio"). Una de ellas imita visualmente al usuario admin estándar con correo de apariencia oficial.</p>
        </div>
        <div class="glass milestone milestone--m2" style="animation-delay:0.15s;">
            <div class="milestone__date">📅 15 de Abril 2026 <span class="tag tag--verified">Verificado</span></div>
            <h3>Hito 2 — Anclaje de Persistencia</h3>
            <p>Instalación encubierta de <code>ace-loader-bit.php</code> en <code>wp-content/mu-plugins/</code> — WordPress lo ejecuta en cada carga de página sin aparecer jamás en el listado estándar de plugins.</p>
        </div>
        <div class="glass milestone milestone--m3" style="animation-delay:0.25s;">
            <div class="milestone__date">📅 08 de Mayo 2026 <span class="tag tag--verified">Verificado</span></div>
            <h3>Hito 3 — Captura Estática de Resguardo</h3>
            <p>Último snapshot íntegro indexado por la Wayback Machine (<code>web.archive.org/web/20260508145818</code>), congelando el Look &amp; Feel legítimo antes de la alteración masiva de código.</p>
        </div>
        <div class="glass milestone milestone--m4" style="animation-delay:0.35s;">
            <div class="milestone__date">📅 07 de Julio 2026 · 03:10–03:19 UTC <span class="tag tag--verified">Verificado</span></div>
            <h3>Hito 4 — Movimiento Lateral</h3>
            <p>Aprovechando la falta de aislamiento de usuario del sistema en el hosting compartido, el atacante replica el loader <code>filefuns</code> y el payload disfrazado <code>toggige-arrow.jpg</code> en las carpetas de imágenes de los tres dominios de la cuenta.</p>
        </div>
        <div class="glass milestone milestone--m5" style="animation-delay:0.45s;">
            <div class="milestone__date">📅 15 de Julio 2026 · 06:10–06:37 UTC <span class="tag tag--verified">Verificado</span></div>
            <h3>Hito 5 — Intrusión en Caliente y Bloqueo</h3>
            <p>Ráfagas continuas cada 3–10 segundos buscando abrir un canal hacia el C2 <code>142.54.180.18</code>. GreenGeeks suspende la cuenta por desborde de <strong>265 GB</strong> de almacenamiento.</p>
            <span class="tag tag--report">Cierre de incidente — bitácora de gestión</span>
        </div>
    </div>
</section>

<section>
    <div class="section-title"><span class="index">03</span> Dictamen de Negociación de Infraestructura</div>
    <div class="section-sub">Registro de gestión con el equipo de soporte de GreenGeeks para el rescate del activo real antes del reseteo de fábrica.</div>
    <div class="glass panel">
        <div class="panel__head">
            <h3>GreenGeeks &times; ACADEP — Bitácora de Gestión</h3>
            <span class="tag tag--report">Reporte interno — no verificado de forma independiente por este agente</span>
        </div>
        <p>El administrador de servidores de GreenGeeks (identificado internamente como <strong>Karl D</strong>) identificó que la base de datos real en explotación era <code>comunicabos_cabovision</code>, con un peso neto de <strong>957 MB</strong> (~1 GB crudo, 10,713 artículos), mientras que la base de datos de soporte técnico ofrecida inicialmente solo registraba <strong>23 MB</strong> — evidencia de que el primer acceso ofrecido no era el sistema de producción real.</p>
        <p>Karl D generó los dumps comprimidos desde la consola root del servidor y los depositó en <code>/home/comunicabos/</code>. Se envió un requerimiento formal para congelar el reseteo de fábrica de la cuenta hasta salvaguardar el directorio de correo (<code>mail/</code>) y completar el filtrado de imágenes legítimas del incidente.</p>
        <div class="callout callout--warn">Esta sección documenta gestión operativa con un tercero (el proveedor de hosting) que este agente no puede verificar de forma independiente — se conserva tal como fue reportada internamente por el equipo de ACADEP, separada de los hallazgos técnicos verificados de las secciones 01, 02 y 05.</div>
    </div>
</section>

<section>
    <div class="section-title"><span class="index">04</span> Maniobras de Ingestión Local y el Fix de Spatie</div>
    <div class="section-sub">Maniobras de control ejecutadas para llevar el dump real a un entorno XAMPP funcional.</div>
    <div class="glass panel">
        <div class="panel__head">
            <h3>Resolución de bloqueos de importación</h3>
            <span class="tag tag--report">Bitácora operativa</span>
        </div>
        <p>El motor físico arrojó el <strong>Error 1231 de MariaDB</strong> por variables de entorno <code>time_zone</code> en <code>NULL</code> en el footer del script de exportación. Se resolvió forzando un streaming crudo de bytes por consola de comandos en vez de depender del importador gráfico de phpMyAdmin para el archivo completo.</p>
        <div class="callout">Esta sección describe la resolución del bloqueo de importación reportada en la bitácora operativa del equipo — este agente no reprodujo el Error 1231 de forma independiente.</div>
    </div>
    <div class="glass panel">
        <div class="panel__head">
            <h3>Bug crítico corregido y verificado: <code>model_has_roles</code></h3>
            <span class="tag tag--verified">Verificado por consulta directa a la BD</span>
        </div>
        <p>Al procesar la estructura de accesos se descubrió que la tabla pivote <code>model_has_roles</code> mapea los privilegios usando el namespace heredado de Laravel <code>App\User</code>, no el formato moderno <code>App\Models\User</code> que asumía el código de login. Con el valor equivocado, el login funcionaba pero <strong>ningún usuario resolvía rol nunca</strong>, dejando <code>requireRole()</code> inoperante en todo el panel de operadores.</p>
        <p>Corregido de raíz en <code>api/auth_login.php</code> y verificado con una consulta real contra <code>cabovision_local</code>: <code>sistemas@acadep.com → Admin</code>, <code>daniel@acadep.com → Autor</code>, <code>erik.comunicabos@gmail.com → Editor</code>. Inicio de sesión del personal de ACADEP restaurado por completo.</p>
    </div>
</section>

<section>
    <div class="section-title"><span class="index">05</span> El Nuevo Búnker de Datos Blindado — CaboVision.tv v2.0</div>
    <div class="section-sub">Arquitectura actual en <code>C:\xampp\htdocs\CaboVision.tv</code>.
        <strong>Certificación de entorno (verificada, no proyectada):</strong> el sandbox local corre hoy sobre
        <code>PHP 8.0.30</code> / MariaDB 10.4.32. <code>PHP 8.1+</code> es el requisito formal para el servidor de
        <strong>producción</strong> al ejecutar la migración — ver <code>knowledge/migration_checklist.md</code> §1.
    </div>
    <div class="arf-grid">
        <div class="glass feature">
            <div class="feature__num">01 / BÓVEDA</div>
            <h4>.env aislado</h4>
            <p>Credenciales fuera del webroot público, bloqueadas en <code>.htaccess</code> por extensión y por nombre de archivo.</p>
        </div>
        <div class="glass feature">
            <div class="feature__num">02 / DATOS</div>
            <h4>PDO 100% preparado</h4>
            <p><code>api/conexion.php</code> fuerza <code>ATTR_EMULATE_PREPARES=false</code> y <code>ERRMODE_EXCEPTION</code> — cero concatenación de variables en SQL.</p>
        </div>
        <div class="glass feature">
            <div class="feature__num">03 / AUTH</div>
            <h4>JWT stateless firmado</h4>
            <p>Rol resuelto una sola vez en el login vía <code>model_has_roles</code>, incrustado y firmado HS256 en el token.</p>
        </div>
        <div class="glass feature">
            <div class="feature__num">04 / MEDIOS</div>
            <h4>resolve_media_path()</h4>
            <p>Verifica existencia física real en disco antes de imprimir cualquier <code>&lt;img&gt;</code> — fallback automático a placeholder de marca.</p>
        </div>
        <div class="glass feature">
            <div class="feature__num">05 / FRONTEND</div>
            <h4>ARF-Grid atómico</h4>
            <p><code>index.php</code>: <code>display:flex; flex-wrap:wrap; justify-content:center</code>, cero anchos fijos, <code>aspect-ratio</code> nativo en cada tarjeta.</p>
        </div>
        <div class="glass feature">
            <div class="feature__num">06 / GOBERNANZA</div>
            <h4>Codex vivo</h4>
            <p>Todo endpoint, tabla y decisión de arquitectura registrada en <code>knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md</code> y <code>03_CONTRATOS_API_Y_RUTAS.md</code>.</p>
        </div>
    </div>
</section>

<footer>
    ACADEP — Informe generado internamente para uso de dirección y cliente. Secciones <span class="tag tag--verified" style="vertical-align:middle;">Verificado</span> confirmadas por consulta directa a la base de datos real o auditoría de archivos ejecutada en esta sesión; secciones <span class="tag tag--report" style="vertical-align:middle;">Bitácora de gestión</span> reflejan reportes operativos internos no reproducidos de forma independiente.
    <br><strong>Entrega oficial:</strong> 15 de Julio de 2026 · Oficina Central de ACADEP, La Paz, Baja California Sur, México.
</footer>
<?php
$fragmentHtml = ob_get_clean();

send_success('Fragmento del dashboard forense.', ['html' => $fragmentHtml]);
