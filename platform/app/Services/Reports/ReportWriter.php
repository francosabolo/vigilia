<?php

namespace App\Services\Reports;

use Anthropic\Client;
use Throwable;

/**
 * Writes the prose of a report from facts the platform already has. The AI only words them: every number and
 * time in the text comes from the facts given, never from the model. Without the AI, a plain template is used.
 */
class ReportWriter
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Redactás informes de una empresa de seguridad para la administración de un consorcio o un barrio cerrado en Argentina.
Escribís en español rioplatense, en tono profesional y claro, sin adjetivos de venta ni exageraciones.
Usá SOLO los datos que te paso: no inventes números, horarios, personas ni hechos. Si un dato falta, no lo menciones.
No uses títulos, viñetas ni markdown: devolvé solo párrafos de texto separados por una línea en blanco.
PROMPT;

    public function __construct(private readonly Client $client) {}

    /**
     * @param  array<string, mixed>  $facts
     */
    public function write(string $task, array $facts, string $fallback): string
    {
        if (! config('seguria.ai.enabled') || ! config('services.anthropic.key')) {
            return $fallback;
        }

        try {
            $message = $this->client->messages->create(
                model: config('seguria.ai.review_model', config('seguria.ai.model')),
                maxTokens: 1500,
                system: self::SYSTEM_PROMPT,
                messages: [['role' => 'user', 'content' => $task."\n\nDatos:\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
            );
        } catch (Throwable $exception) {
            report($exception);

            return $fallback;
        }

        $text = trim(collect($message->content)->filter(fn ($block) => $block->type === 'text')->map(fn ($block) => $block->text)->implode("\n\n"));

        return $text !== '' ? $text : $fallback;
    }
}
