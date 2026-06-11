<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Claude API klijent (Messages API, raw cURL — konzistentno s ostatkom panela).
 * Asistent SAMO čita i predlaže; izvršne izmjene uvijek ide kroz agent operacije
 * uz potvrdu čovjeka. Admin unosi vlastiti API ključ (poglavlje 14.2).
 */
final class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    // Najmoćniji generalno dostupan model; admin može promijeniti u postavkama.
    public const DEFAULT_MODEL = 'claude-opus-4-8';

    public function __construct(
        private readonly string $api_key,
        private readonly string $model = self::DEFAULT_MODEL,
    ) {
    }

    /**
     * Jedan poziv asistentu: system prompt + korisnička poruka s kontekstom.
     * Vraća tekst odgovora. Adaptivno razmišljanje za dijagnostiku (effort high).
     */
    public function ask(string $system, string $user_message, int $max_tokens = 4096): string
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => $max_tokens,
            'system' => $system,
            // Opus 4.8: adaptivno razmišljanje (budget_tokens je uklonjen — 400)
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => 'high'],
            'messages' => [
                ['role' => 'user', 'content' => $user_message],
            ],
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->api_key,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            throw new HttpException(502, 'anthropic_unreachable: ' . $curl_error);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(502, 'anthropic_bad_response');
        }
        if ($status >= 400) {
            $msg = $decoded['error']['message'] ?? "http_$status";
            throw new HttpException(422, 'anthropic: ' . $msg);
        }
        // Sigurnosno odbijanje (safety classifier) — provjeri stop_reason prije content
        if (($decoded['stop_reason'] ?? null) === 'refusal') {
            throw new HttpException(422, 'anthropic_refusal');
        }

        // content je niz blokova; uzmi tekstualne (thinking blokovi su prazni po defaultu)
        $text = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        if ($text === '') {
            throw new HttpException(502, 'anthropic_empty');
        }
        return $text;
    }

    /** Provjera ključa: minimalni poziv (max_tokens 1). @return bool */
    public function verify(): bool
    {
        try {
            $this->ask('You are a connectivity test.', 'Reply with OK.', max_tokens: 16);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
