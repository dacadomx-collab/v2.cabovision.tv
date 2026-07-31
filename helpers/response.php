<?php

declare(strict_types=1);

// =============================================================================
// helpers/response.php — Response Contract Estricto (AXON_DCD Standard)
// Mandamiento #5: Contrato de API Estricto.
// TODO endpoint debe responder JSON con exactamente estas tres claves:
// {"status": "success"|"error", "message": string, "data": array}
// =============================================================================

/**
 * Emite una respuesta JSON conforme al Response Contract y termina el script.
 *
 * @param array<mixed> $data
 */
function send_json_response(string $status, string $message, array $data = [], int $httpCode = 200): never
{
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode(
        ['status' => $status, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

/** Azúcar sintáctica para respuestas exitosas (status: success). */
function send_success(string $message, array $data = [], int $httpCode = 200): never
{
    send_json_response('success', $message, $data, $httpCode);
}

/** Azúcar sintáctica para respuestas de error (status: error). */
function send_error(string $message, int $httpCode = 400, array $data = []): never
{
    send_json_response('error', $message, $data, $httpCode);
}
