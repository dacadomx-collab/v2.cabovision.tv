<?php
declare(strict_types=1);

/**
 * helpers/ai_runtime_factory.php  v1.0
 *
 * PATRÓN: AI Runtime Factory — activo genérico de la factoría {{HOLDING_NAME}},
 * promovido desde un proyecto productivo del holding. Instancia un objeto
 * efímero de ejecución conversacional a partir del `runtime_config` de un
 * proyecto/tenant. Ningún estado se comparte entre peticiones concurrentes:
 * cada request PHP construye su propio árbol de objetos.
 *
 * INTEGRACIÓN OBLIGATORIA (a definir por el proyecto clonado):
 *   Esta factoría NUNCA debe llamar a un proveedor de IA (OpenAI/Anthropic/
 *   Ollama) de forma directa. Sustituye el marcador {{AI_DISPATCH_HANDLER}}
 *   por el orquestador real del proyecto — una clase/función central que
 *   controle proveedor, modelo, límites de tokens y logging de uso.
 *
 * AISLAMIENTO DE SIMULACIÓN (opcional):
 *   Si el proyecto expone un modo sandbox/demo para pruebas internas, pasa
 *   un `$simulationTokenUuid` explícito para forzar un `contextKey` separado
 *   y evitar que la telemetría de pruebas se mezcle con tráfico real.
 */

final class AiRuntimeFactory
{
    /**
     * @param array       $runtimeConfig       Contrato JSON de configuración del tenant.
     * @param string      $baseContextKey      Identificador real del prompt/bot activo.
     * @param string|null $simulationTokenUuid UUID de simulación efímera. NULL = tráfico real.
     */
    public static function forProject(
        array   $runtimeConfig,
        string  $baseContextKey,
        ?string $simulationTokenUuid = null
    ): AiRuntimeInstance {
        if ($baseContextKey === '') {
            throw new \InvalidArgumentException('AiRuntimeFactory: baseContextKey no puede estar vacío.');
        }

        $resolvedContextKey = (string) ($runtimeConfig['llm']['context_key_override'] ?? $baseContextKey);

        return new AiRuntimeInstance($runtimeConfig, $resolvedContextKey, $simulationTokenUuid);
    }
}

/**
 * Instancia efímera del AI Runtime. Un objeto por conversación/simulación —
 * no debe persistirse entre requests (sin serialización a sesión ni caché).
 */
final class AiRuntimeInstance
{
    /** Frases que disparan el Output Validator (Prompt Firewall). */
    private const BLOCKED_OUTPUT_PATTERNS = [
        '/ignore\s+previous/i',
        '/ignora\s+las?\s+instrucciones?\s+anteriores/i',
        '/system\s*prompt/i',
        '/api[_\s-]?key/i',
    ];

    public function __construct(
        private readonly array   $runtimeConfig,
        private readonly string  $contextKey,
        private readonly ?string $simulationTokenUuid = null
    ) {
    }

    public function isSimulation(): bool
    {
        return $this->simulationTokenUuid !== null;
    }

    /**
     * Ejecuta una inferencia de texto. Construye mensajes aislados por rol
     * (SYSTEM / KNOWLEDGE / USER) — Prompt Firewall: nunca se concatenan en
     * un solo bloque de texto libre.
     *
     * @param string      $systemPrompt  Prompt de sistema (fuente de verdad del bot, no de este JSON).
     * @param string|null $knowledge     Contexto RAG opcional (documento/fragmento recuperado).
     * @param string      $userMessage   Mensaje del usuario final.
     * @param int         $idUsuario     Actor autenticado para telemetría/rate-limit.
     * @return array  {content, ...datos de uso del proveedor}
     */
    public function chat(string $systemPrompt, ?string $knowledge, string $userMessage, int $idUsuario): array
    {
        $maxInputTokens = (int) ($this->runtimeConfig['security']['maxInputTokens'] ?? 4000);
        if (mb_strlen($userMessage) > $maxInputTokens * 4) {
            // Heurística conservadora (~4 chars/token) — corte duro antes de despachar.
            throw new \RuntimeException('AI_RUNTIME_INPUT_EXCEDE_LIMITE');
        }

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        if ($knowledge !== null && $knowledge !== '') {
            $messages[] = ['role' => 'system', 'content' => "[KNOWLEDGE]\n" . $knowledge];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // {{AI_DISPATCH_HANDLER}} — sustituir por el orquestador real del proyecto.
        // JAMÁS invocar un SDK de proveedor de IA directamente desde este punto.
        $result = \AiDispatchHandler::dispatch(
            messages:   $messages,
            idUsuario:  $idUsuario,
            contextKey: $this->contextKey
        );

        $result['content'] = $this->applyOutputValidator($result['content']);

        return $result;
    }

    private function applyOutputValidator(string $content): string
    {
        foreach (self::BLOCKED_OUTPUT_PATTERNS as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return 'Lo siento, no puedo compartir esa información. ¿En qué más puedo ayudarte?';
            }
        }
        return $content;
    }
}
