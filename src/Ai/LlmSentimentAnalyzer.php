<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * Calls any OpenAI-compatible chat-completions endpoint and forces a strict JSON answer.
 * The output is validated before it touches the database: an LLM is treated as an
 * untrusted dependency (bad JSON / unexpected label => exception => job retried).
 */
final class LlmSentimentAnalyzer implements SentimentAnalyzer
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private int $timeoutSeconds = 15,
    ) {}

    public function analyze(string $text): array
    {
        $body = [
            'model' => $this->model,
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' =>
                    'You classify customer-experience survey comments. Reply ONLY with JSON: '
                    . '{"sentiment":"positive|neutral|negative","summary":"<max 15 words>"}'],
                ['role' => 'user', 'content' => $text],
            ],
        ];
        $ch = curl_init(rtrim($this->baseUrl, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status >= 400) {
            throw new \RuntimeException("LLM call failed (HTTP $status)");
        }
        $content = json_decode((string) $raw, true)['choices'][0]['message']['content'] ?? '';
        return self::parse($content);
    }

    /** Public so it can be unit-tested without network. */
    public static function parse(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data) || !in_array($data['sentiment'] ?? null, ['positive', 'neutral', 'negative'], true)) {
            throw new \UnexpectedValueException('LLM returned an invalid payload');
        }
        return ['sentiment' => $data['sentiment'], 'summary' => mb_substr((string) ($data['summary'] ?? ''), 0, 200)];
    }
}
