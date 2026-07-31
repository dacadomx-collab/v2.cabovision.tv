# FUENTE DE VERDAD CONSOLIDADA
## CaboVision.tv | DCD LABS / VECTOR_CERO — Estado Real del Proyecto

> Esta copia ya fue clonada del machote genérico (checklist de clonación completado
> el 2026-06-22/24) y corresponde específicamente a **CaboVision.tv**, no a la
> plantilla madre. Vector de comunicación entre el Arquitecto humano y los agentes
> IA que trabajan en este repositorio — se actualiza con evidencia real, nunca con
> proyecciones. Última sincronización: **2026-07-21**.

---

## 1. IDENTIDAD Y ENTORNO

- **Proyecto:** CaboVision.tv — portal de noticias de Los Cabos y Baja California Sur.
- **Entorno local:** `C:\xampp\htdocs\CaboVision.tv\` (XAMPP: Apache + MySQL/MariaDB, PHP 8.2.32).
- **BD real:** `cabovision_local` (`DB_HOST=localhost` — sí, local; a diferencia del machote genérico, este proyecto opera hoy sin BD remota separada de staging/producción real todavía).
- **BD legacy de solo lectura:** `cabovision_legacy` (usada por `scripts/migration_pump.php`, bloqueado indefinidamente — ver §4).
- **Servidor físico Cold Storage:** Ubuntu 24.04 LTS, LAN `192.168.1.224:8081`, PHP 8.2 — conexión directa (sin túnel), ver `knowledge/reportes_internos/bitacora_tecnica_infraestructura.md`.

## 2. ARQUITECTURA REAL (verificada, no la boilerplate del machote)

| Capa | Componentes reales | Estado |
| :--- | :--- | :--- |
| **Seguridad** | `api/auth_login.php` (6 capas: CORS, RBAC de 17 cuentas reales, anti-enumeración por timing, tarpitting 10/15min, device binding SHA-256, PDO sin emulación), `api/auth_middleware.php`, `helpers/input_sanitizer.php` | ✅ Real, probado end-to-end |
| **Datos** | `api/conexion.php` (`class Database`, PDO `ATTR_EMULATE_PREPARES=false`), `helpers/response.php` | ✅ |
| **Medios (CDN Inversa Hot/Cold)** | `helpers/media_path_resolver.php`, `helpers/ColdStorageClient.php`, `api/media_bridge.php`, `scripts/link_articles_media.php`, `scripts/rewrite_content_images.php` | ✅ Pipeline real y probado. Migración: **97.72%** (7,001/7,164 `media_assets` con `file_hash` real) |
| **Frontend público** | `index.php`, `articulo.php`, `categoria.php`, `views/partials/{header,footer,article_card,analytics}.php` | ✅ Construidos 2026-07-20/21 — no existían antes (causa real del 404 histórico en URLs amigables) |
| **Rendimiento** | `helpers/object_cache.php` (APCu, sin Redis/Memcached), índices reales en `articles.alias`, `articles.published_at`, `categories.alias` | ✅ Medido: menú 28ms→11-15ms; queries de 4,193 filas escaneadas → 1 |
| **Telemetría/Ads** | `sponsor_banners`, `sponsors_metricas` (append-only), `api/sponsors_track.php`, `assets/js/sponsor-telemetry.js`, `telemetria_cold_storage` (append-only) | ✅ Reales, probados |
| **Visual (Editorial Moderno)** | `assets/css/main.css` (tipografía serif nativa, tema claro/oscuro real vía `data-theme`+`localStorage`), `assets/js/main.js` | ✅ Construido 2026-07-21, verificado con capturas de navegador real (light/dark) |

## 3. LO QUE EL MACHOTE ASUME QUE NO APLICA AQUÍ

- `assets/img/logo.svg` — no existe; el logo real es `assets/img/logocabovis_glow.png`.
- `refresh_tokens_blacklist` / rotación de refresh tokens — no implementado; `auth_login.php` no usa el patrón Access/Refresh JWT completo del machote, usa JWT simple + tarpitting.
- Túnel Proxy Seguro para ChatBot IA (`validators/proxy_tunnel_validator.php`) — **no hay ningún proveedor de IA configurado en `.env` de este proyecto**. Cualquier feature de IA editorial (resúmenes automáticos, etc.) mencionada en documentos de `knowledge/revision/` está bloqueada por esto, no por código.

## 4. BLOQUEADORES REALES VIGENTES

- **`cabovision_legacy.woaxp_content` tiene 0 filas** — el dump histórico esperado nunca se importó; `migration_pump.php` está listo pero sin fuente real a la que apuntar.
- **~4,920 referencias de imagen no están en `E:\RESPALDO CABOVISION.zip`** — pérdida de datos real y anterior a este proyecto, no recuperable por ingeniería (verificado directamente contra el ZIP).
- **5 alias duplicados en `articles`** — impide un índice `UNIQUE` real en `articles.alias`; pendiente decisión del Arquitecto sobre cuál fila debe prevalecer por URL.
- **WAF / rate limiting a nivel de sitio** — no iniciado; solo existe tarpitting en `auth_login.php`.

## 5. REFERENCIAS

- Manual operativo del agente: [`CLAUDE.md`](CLAUDE.md)
- Checklist de fases con fecha real por ítem: [`knowledge/migration_checklist.md`](knowledge/migration_checklist.md)
- Reporte ejecutivo de infraestructura (visual): [`knowledge/reportes_internos/comparativa_infraestructura.html`](knowledge/reportes_internos/comparativa_infraestructura.html)
- Bitácora técnica de servidor físico/RAID1: [`knowledge/reportes_internos/bitacora_tecnica_infraestructura.md`](knowledge/reportes_internos/bitacora_tecnica_infraestructura.md)
- Ledger de coordinación inter-agente: [`knowledge/99_INTER_AI_HANDSHAKE_LEDGER.md`](knowledge/99_INTER_AI_HANDSHAKE_LEDGER.md)
- Codex y schema maestro: [`knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md`](knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md)
- Contratos de API: [`knowledge/03_CONTRATOS_API_Y_RUTAS.md`](knowledge/03_CONTRATOS_API_Y_RUTAS.md)
