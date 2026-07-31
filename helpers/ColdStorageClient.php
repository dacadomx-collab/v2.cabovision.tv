<?php

declare(strict_types=1);

// =============================================================================
// helpers/ColdStorageClient.php — Cliente PHP 8.1+ del puente hacia Cold
// Storage (servidor físico ACADEP, vía scripts/acadep_bridge_local/bridge_serve.php).
//
// Protocolo AURA CORE v2.0 (2026-07-19): puerto 8081 cerrado a la LAN pública
// por UFW, gestionado internamente por cloudflared.service. Payload real
// multipart/form-data (campo `file`), header `X-ACADEP-Bridge-Key`. Este
// archivo y scripts/acadep_bridge_local/bridge_serve.php se actualizaron
// juntos en el mismo commit para que el contrato sea consistente en ambos
// lados — la versión anterior de este cliente usaba binario crudo +
// `X-Bridge-Key` porque esa era la única versión de bridge_serve.php
// verificable en este repositorio en ese momento (ver historial de
// knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md §Módulo 02 para el porqué del cambio).
//
// multipart/form-data en streaming real: CURLStringFile (PHP 8.1+) permite
// adjuntar contenido binario ya en memoria como parte de un multipart sin
// necesitar una ruta de archivo real en disco — así uploadStreamToColdStorage()
// puede leer un ZipArchive::getStream() (zip:// no es un FILE* válido para
// CURLOPT_INFILE, ver commit anterior) sin tocar el disco C: en ningún punto.
// =============================================================================

class ColdStorageClient
{
    private const MEDIA_TENANT_ID = 1002; // Fijo, ver knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md §Módulo 02

    private string $bridgeUrl;
    private string $bridgeKey;

    public function __construct()
    {
        $envPath = dirname(__DIR__) . '/.env';
        if (!is_file($envPath)) {
            throw new \RuntimeException('Perímetro comprometido: archivo .env de la raíz ausente.');
        }

        $env = parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: [];
        $this->bridgeUrl = rtrim((string) ($env['ACADEP_BRIDGE_URL'] ?? ''), '/');
        $this->bridgeKey = (string) ($env['ACADEP_BRIDGE_KEY'] ?? '');

        if ($this->bridgeUrl === '' || $this->bridgeKey === '') {
            throw new \RuntimeException('Falta configuración crítica del puente multimedia en el .env raíz.');
        }
    }

    /**
     * Ruta destino segmentada por fecha (o una fecha explícita, útil para
     * migrar histórico con su fecha real en vez de la fecha de ejecución).
     * Formato: {tenant}/{yyyy}/{mm}/{dd}/archivo.ext — SIN prefijo `images/`,
     * compatible con la regex de bridge_serve.php y con media_assets.relative_path.
     */
    public function buildClusteredPath(string $filename, ?DateTimeImmutable $when = null): string
    {
        $when = $when ?? new DateTimeImmutable();
        $safeFilename = preg_replace('/[^\w\-.]/', '_', $filename) ?? $filename;

        return sprintf(
            '%d/%s/%s/%s/%s',
            self::MEDIA_TENANT_ID,
            $when->format('Y'),
            $when->format('m'),
            $when->format('d'),
            $safeFilename
        );
    }

    /**
     * Sube un archivo real ya presente en disco (usado por
     * scripts/cold_storage_migration.php). CURLFile transmite en streaming
     * directo desde el path, sin cargarlo completo en memoria de PHP.
     *
     * @return array{success: bool, httpCode: int, conflict: bool, sha256: ?string}
     */
    public function uploadToColdStorage(string $relativePath, string $localFilePath): array
    {
        if (!is_file($localFilePath)) {
            error_log('[' . date('Y-m-d H:i:s') . '] [ColdStorageClient] Archivo local no existe: ' . $localFilePath);
            return ['success' => false, 'httpCode' => 0, 'conflict' => false, 'sha256' => null];
        }

        $mimeType = mime_content_type($localFilePath) ?: 'application/octet-stream';
        $filePart = new \CURLFile($localFilePath, $mimeType, basename($localFilePath));

        return $this->dispatch($relativePath, $filePart);
    }

    /**
     * Variante en streaming de uploadToColdStorage(): sube contenido leído
     * directamente de un recurso de flujo (ej. ZipArchive::getStream()) sin
     * pasar por ningún archivo temporal en disco C:. Lee el stream completo
     * a una cadena en memoria RAM (los binarios reales son de pocos KB/MB —
     * no un problema de memoria) y la adjunta vía CURLStringFile, que sí
     * soporta multipart/form-data real sin requerir un path de filesystem.
     *
     * @param resource $stream
     * @return array{success: bool, httpCode: int, conflict: bool, sha256: ?string}
     */
    public function uploadStreamToColdStorage(string $relativePath, $stream, int $sizeBytes, string $mimeType = 'application/octet-stream'): array
    {
        if (!is_resource($stream)) {
            error_log('[' . date('Y-m-d H:i:s') . '] [ColdStorageClient] Stream inválido para: ' . $relativePath);
            return ['success' => false, 'httpCode' => 0, 'conflict' => false, 'sha256' => null];
        }

        $binary = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 1048576); // 1 MB por lectura — nunca se materializa en disco, solo en esta variable de RAM
            if ($chunk === false) {
                break;
            }
            $binary .= $chunk;
        }

        if (strlen($binary) !== $sizeBytes) {
            error_log(
                '[' . date('Y-m-d H:i:s') . "] [ColdStorageClient] Tamaño leído (" . strlen($binary)
                . ") no coincide con el esperado ({$sizeBytes}) para: {$relativePath}"
            );
            return ['success' => false, 'httpCode' => 0, 'conflict' => false, 'sha256' => null];
        }

        $filePart = new \CURLStringFile($binary, basename($relativePath), $mimeType);

        $result = $this->dispatch($relativePath, $filePart);

        // Deriva locales de conflicto (2026-07-22): un 409 significa que el
        // bridge YA tiene ese archivo — la subida se abortó por diseño (ver
        // dispatch()), pero eso no dice si NUESTRA base de datos local sabe
        // de esa fila. Auditoría real: media_assets sin ese relative_path pero
        // el bridge respondiendo 409 en TODOS los intentos (drift real entre
        // el servidor y esta BD, de una corrida anterior interrumpida). El
        // sha256 del servidor no está disponible en un 409 (no hay respuesta
        // de "Approved" que lo traiga) — se calcula aquí sobre los mismos
        // bytes ya leídos, válido porque la ruta destino es determinística
        // por fecha+nombre (una colisión de ruta = mismo archivo lógico).
        if ($result['conflict'] && $result['sha256'] === null) {
            $result['sha256'] = hash('sha256', $binary);
        }

        return $result;
    }

    /**
     * Envío real multipart/form-data compartido por ambos métodos de subida.
     * Campo del archivo: `file`. `tenant_id` viaja como campo de texto propio
     * — confirmado 2026-07-19 contra el servidor real (LAN 192.168.1.224:8081,
     * PHP 8.2.32): devolvía 400 con {"expectedField":"tenant_id",
     * "receivedFileFields":["file"]} hasta que se agregó explícitamente aquí.
     * El campo del archivo ya estaba correcto (`file`), solo faltaba este.
     *
     * @param \CURLFile|\CURLStringFile $filePart
     * @return array{success: bool, httpCode: int, conflict: bool, sha256: ?string}
     */
    private function dispatch(string $relativePath, \CURLFile|\CURLStringFile $filePart): array
    {
        $endpoint = $this->bridgeUrl . '/upload?path=' . rawurlencode($relativePath);
        $metaJson = json_encode($this->buildControlPayload($relativePath), JSON_UNESCAPED_UNICODE) ?: '{}';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'file'      => $filePart,
                'tenant_id' => (string) self::MEDIA_TENANT_ID,
                'meta'      => $metaJson,
            ],
            CURLOPT_HTTPHEADER     => [
                'X-ACADEP-Bridge-Key: ' . $this->bridgeKey, // Header real validado por bridge_serve.php (HTTP_X_ACADEP_BRIDGE_KEY)
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // Control de colisiones inmutables: abortar de inmediato ante 409 —
        // protege la bitácora multimedia histórica de sobrescrituras.
        if ($httpCode === 409) {
            error_log(
                '[' . date('Y-m-d H:i:s') . '] [ColdStorageClient] CONFLICTO 409: '
                . 'ruta ya existente en Cold Storage, subida abortada — ' . $relativePath
            );
            return ['success' => false, 'httpCode' => 409, 'conflict' => true, 'sha256' => null];
        }
        if ($curlErrno !== 0) {
            error_log('[' . date('Y-m-d H:i:s') . '] [ColdStorageClient] Error de transporte (curl_errno=' . $curlErrno . '): ' . $relativePath);
        }

        // 200 OK con {"status":"Approved","sha256":"..."} — confirmado 2026-07-19
        // contra el servidor real (no 201: esa era la respuesta del mock local
        // que se usó antes de tener acceso al servidor real). El sha256 real del
        // servidor es la única fuente válida para reconstruir la ruta de lectura
        // de GET /view — no se recalcula localmente, puede no coincidir.
        $success = $httpCode === 200 && $response !== false;
        $sha256  = null;
        if ($success) {
            $decoded = json_decode((string) $response, true);
            $sha256  = is_array($decoded) ? ($decoded['sha256'] ?? null) : null;
        }

        return ['success' => $success, 'httpCode' => $httpCode, 'conflict' => false, 'sha256' => $sha256];
    }

    /**
     * Telemetría de control del emisor. `municipio`/`estado`/`pais` quedan en
     * null: no hay ninguna librería ni servicio GeoIP registrado en el Codex
     * de este proyecto — no se inventa una llamada a un servicio externo no
     * autorizado ni se hardcodea una ubicación falsa (Mandamiento #4).
     *
     * @return array{relative_path: string, origin_ip: string, municipio: null, estado: null, pais: null, timestamp: string}
     */
    private function buildControlPayload(string $relativePath): array
    {
        return [
            'relative_path' => $relativePath,
            'origin_ip'     => $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()),
            'municipio'     => null,
            'estado'        => null,
            'pais'          => null,
            'timestamp'     => date('c'),
        ];
    }
}
