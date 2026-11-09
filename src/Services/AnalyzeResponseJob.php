<?php
declare(strict_types=1);

namespace App\Services;

use App\Queue\Job;

/** Queued job: run AI sentiment analysis on a response's free-text answers, off the request path. */
final class AnalyzeResponseJob implements Job
{
    public function __construct(public readonly int $responseId) {}

    public function handle(): void
    {
        $texts = db()->select(
            "SELECT a.value FROM answers a JOIN questions q ON q.id = a.question_id
             WHERE a.response_id = ? AND q.type = 'text'",
            [$this->responseId]
        );
        $text = trim(implode(' ', array_column($texts, 'value')));
        if ($text === '') {
            return;
        }
        $result = sentimentAnalyzer()->analyze($text);
        db()->execute('UPDATE responses SET sentiment = ?, summary = ? WHERE id = ?',
            [$result['sentiment'], $result['summary'], $this->responseId]);
    }

    public function toArray(): array
    {
        return ['responseId' => $this->responseId];
    }

    public static function fromArray(array $data): static
    {
        return new static((int) $data['responseId']);
    }
}
