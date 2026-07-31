<?php

declare(strict_types=1);

// =============================================================================
// api/conexion.php — Conexión PDO Centralizada (AXON_DCD Security Standard)
// Mandamiento #11: Arranque Blindado — TODA conexión pasa por aquí.
// Mandamiento #12: Bóveda de Secretos — Lee credenciales SOLO desde .env
// Mandamiento #13 / REGLA CERO: Aislamiento de Entornos — la BD NUNCA es
// local. El fallback de DB_HOST jamás debe ser 'localhost' o '127.0.0.1'.
// =============================================================================

class Database
{
    /**
     * Host remoto centralizado de respaldo si DB_HOST falta en .env (Regla Cero).
     * NUNCA hardcodear aquí un hostname real de hosting — definir el valor real
     * únicamente en `.env` (Mandamiento #12: Bóveda de Secretos). Este placeholder
     * fuerza un fallo visible en vez de conectar silenciosamente a un host
     * heredado de otro proyecto.
     */
    private const DEFAULT_REMOTE_DB_HOST = '[HOST_BD_REMOTO_DEL_HOSTING]';

    private string $host;
    private string $db_name;
    private string $username;
    private string $password;
    private string $allowed_origins;
    public ?PDO $conn = null;

    public function __construct()
    {
        $env = $this->loadEnv(__DIR__ . '/../.env');

        $this->host            = (string) ($env['DB_HOST'] ?? self::DEFAULT_REMOTE_DB_HOST);
        $this->db_name         = (string) ($env['DB_NAME'] ?? '');
        $this->username        = (string) ($env['DB_USER'] ?? '');
        $this->password        = (string) ($env['DB_PASS'] ?? '');
        $this->allowed_origins = (string) ($env['ALLOWED_ORIGINS'] ?? '');
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function jsonError(string $message, int $httpCode = 500): never
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => $message, 'data' => []]);
        exit;
    }

    private function loadEnv(string $path): array
    {
        if (!is_readable($path)) {
            $this->jsonError('Error crítico de servidor: Configuración no encontrada.');
        }
        $data = parse_ini_file($path, false, INI_SCANNER_RAW);
        if ($data === false) {
            $this->jsonError('Error crítico de servidor: Formato de configuración inválido.');
        }
        return $data;
    }

    // ── CORS (opcional — usar api/cors.php para control granular por endpoint) ─

    public function setCorsHeaders(): void
    {
        $origin      = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedList = array_map('trim', explode(',', $this->allowed_origins));

        // Origen desconocido → no emitir header, el navegador bloquea solo
        if (!empty($origin) && !in_array($origin, $allowedList, true)) {
            $this->jsonError('Acceso denegado: Origen no autorizado.', 403);
        }

        if (in_array($origin, $allowedList, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
        } else {
            // Fallback para herramientas de desarrollo sin HTTP_ORIGIN (Postman, local)
            header('Access-Control-Allow-Origin: ' . ($allowedList[0] ?? '*'));
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Vary: Origin');
        header('Content-Type: application/json; charset=UTF-8');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    // ── CONEXIÓN PDO ──────────────────────────────────────────────────────────

    public function getConnection(): PDO
    {
        if (empty($this->db_name) || empty($this->username)) {
            $this->jsonError('Error de BD: credenciales incompletas.');
        }

        try {
            $dsn        = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // Previene SQL Injection
            ]);
        } catch (PDOException $e) {
            // NUNCA exponer el mensaje real de PDO al frontend
            error_log('[' . date('Y-m-d H:i:s') . '] [Database::getConnection] ' . $e->getMessage());
            $this->jsonError('Error de conexión a la base de datos. Intente más tarde.');
        }

        return $this->conn;
    }
}
