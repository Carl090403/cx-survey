<?php
declare(strict_types=1);

// Minimal PSR-4-style autoloader (namespace App\ -> src/)
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use App\Ai\KeywordSentimentAnalyzer;
use App\Ai\LlmSentimentAnalyzer;
use App\Ai\SentimentAnalyzer;
use App\Database;

function db(): Database
{
    static $db = null;
    if ($db === null) {
        $db = new Database(getenv('DB_PATH') ?: __DIR__ . '/../database/app.sqlite');
        $db->migrate(__DIR__ . '/../database/schema.sql');
    }
    return $db;
}

/** Swap the AI provider via env: LLM_API_KEY set => real LLM, otherwise deterministic offline analyzer. */
function sentimentAnalyzer(): SentimentAnalyzer
{
    $key = getenv('LLM_API_KEY');
    return $key
        ? new LlmSentimentAnalyzer($key, getenv('LLM_BASE_URL') ?: 'https://api.openai.com/v1', getenv('LLM_MODEL') ?: 'gpt-4o-mini')
        : new KeywordSentimentAnalyzer();
}
