<?php
declare(strict_types=1);

namespace App\Ai;

interface SentimentAnalyzer
{
    /** @return array{sentiment: 'positive'|'neutral'|'negative', summary: string} */
    public function analyze(string $text): array;
}
