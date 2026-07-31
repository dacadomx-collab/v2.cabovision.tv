# MÓDULO 04: GROWTH MARKETING ORGÁNICO Y ADSERVER B2B (WHITE-LABEL)

## 1. SISTEMA DE AUTOMATIZACIÓN DE METADATOS Y SEO (ON-PAGE)
El motor de metadatos interceptará el ciclo de vida de la petición editorial, inyectando etiquetas HTML antes de liberar el primer byte (TTFB). Cero renderizado del lado del cliente (Client-Side Rendering) para proteger la métrica INP.

*   **Social Graph (Open Graph & Twitter Cards):** Inyección dinámica de `og:title` (máx 70 chars), `og:description` (máx 155 chars) y `og:image` (formato WebP/AVIF, 1200x630px).
*   **Sitemaps Dinámicos en Tiempo Real:** Prohibido el uso de archivos XML estáticos. El sitemap de noticias (últimas 48 horas) operará sobre un endpoint físico virtualizado (`/sitemap-news.xml`), limitando a 500 artículos recientes y utilizando caché de memoria (TTL 300s) que se invalida al publicar nuevo contenido.
*   **JSON-LD Estructurado (Zero-Render-Delay):** Inyección en el servidor (SSR) de las entidades `NewsArticle` y `BreadcrumbList`. El payload debe comprimirse (Gzip/Brotli) para no superar 1.5 KB, eliminando desplazamientos tardíos del DOM (Cumulative Layout Shift).

## 2. INGENIERÍA DEL ADSERVER Y TELEMETRÍA (METRICAS B2B)
El sistema registrará el rendimiento publicitario de forma nativa, bloqueando fraudes y scripts de terceros que degraden el Web Performance Optimization (WPO).

*   **Impresiones Visibles (Viewability MRC):** Uso exclusivo de la API nativa `Intersection Observer` (Vanilla JS). Se registrará una impresión solo si el banner es visible al 50% durante al menos 1 segundo continuo (30% para megabanners).
*   **Tiempo de Permanencia (Screen Retention Time):** El temporizador de exposición se pausará automáticamente si el usuario cambia de pestaña o el navegador pierde el foco.
*   **Transmisión Asíncrona:** El envío de datos al servidor utilizará `navigator.sendBeacon()` o fetch en background, garantizando fricción cero en el hilo principal.
*   **Interceptación de Clics Limpia:** Se utilizarán redirecciones HTTP 302 controladas desde el backend, validando la URL de destino contra una whitelist para prevenir vulnerabilidades de Open-Redirect.

## 3. CIBERSEGURIDAD PERIMETRAL Y ANTI-FRAUDE PUBLICITARIO
Protección integral contra bots y manipulación de métricas (Click-Fraud).

*   **Anonimización y Sesiones Únicas (Anti-Fraude):** Los clics únicos se validarán mediante un hash unidireccional (SHA-256) que combina una clave rotativa (salt), el User-Agent y la IP truncada (eliminando el último octeto IPv4 / 80 bits IPv6 para cumplir normativas de privacidad).
*   **Rate Limiting Perimetral (NGINX / Servidor Web):** Configuración de cortafuegos en capa de red. Ejemplo: Límite de 10 peticiones/segundo al endpoint de telemetría, devolviendo código HTTP 429 ante abusos, sin saturar la base de datos.
*   **Rotación de Tokens CSRF:** Inyección de tokens criptográficos de un solo uso en paneles de patrocinadores y formularios, bloqueando falsificación de solicitudes (XSS/CSRF).
