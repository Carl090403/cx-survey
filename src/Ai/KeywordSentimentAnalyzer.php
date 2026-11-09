<?php
declare(strict_types=1);

namespace App\Ai;

/** Deterministic offline analyzer: used in tests/CI and as a fallback when the LLM is unavailable. */
final class KeywordSentimentAnalyzer implements SentimentAnalyzer
{
    private const POSITIVE = ['great', 'excellent', 'friendly', 'fast', 'clean', 'love', 'helpful', 'amazing', 'good'];
    private const NEGATIVE = ['slow', 'rude', 'dirty', 'bad', 'wait', 'cold', 'broken', 'terrible', 'never', 'wrong'];

    public function analyze(string $text): array
    {
        $words = preg_split('/\W+/u', mb_strtolower($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $score = count(array_intersect($words, self::POSITIVE)) - count(array_intersect($words, self::NEGATIVE));
        $sentiment = $score > 0 ? 'positive' : ($score < 0 ? 'negative' : 'neutral');
        $summary = mb_strimwidth(trim($text), 0, 80, '…');
        return ['sentiment' => $sentiment, 'summary' => $summary];
    }
}
