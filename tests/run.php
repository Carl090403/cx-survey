<?php
declare(strict_types=1);

// Dependency-free test runner (PHPUnit-style assertions) so the suite runs anywhere: `php tests/run.php`
$tmp = tempnam(sys_get_temp_dir(), 'cx') . '.sqlite';
putenv("DB_PATH=$tmp");
putenv('LLM_API_KEY');   // force the offline analyzer
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/routes.php';

use App\Ai\LlmSentimentAnalyzer;
use App\Queue\Job;
use App\Queue\JobQueue;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); $passed++; echo "  ✔ $name\n"; }
    catch (Throwable $e) { $failed++; echo "  ✘ $name\n      " . $e->getMessage() . "\n"; }
}
function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($msg . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$api = app();
$survey = null;

echo "Survey API\n";
test('creates a survey with questions (201)', function () use ($api, &$survey) {
    [$status, $body] = $api->dispatch('POST', '/api/surveys', [
        'title' => 'Store visit — Kanata', 'location' => 'Kanata',
        'questions' => [
            ['type' => 'nps', 'label' => 'How likely are you to recommend us?'],
            ['type' => 'rating', 'label' => 'Cleanliness'],
            ['type' => 'text', 'label' => 'Tell us more'],
        ],
    ]);
    assertSame(201, $status);
    assertSame(3, count($body['questions']));
    $survey = $body;
});

test('rejects invalid survey with field errors (422)', function () use ($api) {
    [$status, $body] = $api->dispatch('POST', '/api/surveys', ['title' => '', 'questions' => [['type' => 'video', 'label' => '']]]);
    assertSame(422, $status);
    assertSame(true, isset($body['errors']['title'], $body['errors']['questions.0.type'], $body['errors']['questions.0.label']));
});

test('validates NPS range on submission', function () use ($api, &$survey) {
    [$q1, $q2, $q3] = array_column($survey['questions'], 'id');
    [$status, $body] = $api->dispatch('POST', "/api/surveys/{$survey['id']}/responses", ['answers' => [$q1 => 11, $q2 => 3, $q3 => 'ok']]);
    assertSame(422, $status);
    assertSame('NPS must be 0-10', $body['errors']["answers.$q1"]);
});

test('accepts responses (202) and queues AI analysis', function () use ($api, &$survey) {
    [$q1, $q2, $q3] = array_column($survey['questions'], 'id');
    $rows = [[10, 5, 'Great staff, very friendly and fast'], [9, 4, 'Clean store, good service'],
             [3, 2, 'Slow checkout and rude cashier'], [7, 3, 'It was fine']];
    foreach ($rows as [$nps, $rating, $text]) {
        [$status, $body] = $api->dispatch('POST', "/api/surveys/{$survey['id']}/responses", ['answers' => [$q1 => $nps, $q2 => $rating, $q3 => $text]]);
        assertSame(202, $status);
        assertSame('queued', $body['analysis']);
    }
    assertSame(4, (new JobQueue(db()))->pending('ai'));
});

test('report before worker: sentiment pending, NPS computed', function () use ($api, &$survey) {
    [, $r] = $api->dispatch('GET', "/api/surveys/{$survey['id']}/report");
    assertSame(4, $r['responses']);
    assertSame(25, $r['nps']);            // 2 promoters, 1 detractor, 4 total => 25
    assertSame(['pending' => 4], $r['sentiment']);
    assertSame(3.5, $r['ratings'][0]['avg']);
});

test('worker processes queue and report shows sentiment split', function () use ($api, &$survey) {
    assertSame(4, (new JobQueue(db()))->work('ai'));
    [, $r] = $api->dispatch('GET', "/api/surveys/{$survey['id']}/report");
    assertSame(['negative' => 1, 'neutral' => 1, 'positive' => 2], $r['sentiment']);
});

test('unknown survey returns 404', function () use ($api) {
    assertSame(404, $api->dispatch('GET', '/api/surveys/999')[0]);
    assertSame(404, $api->dispatch('GET', '/api/surveys/999/report')[0]);
});

echo "Queue\n";
final class FlakyJob implements Job
{
    public static int $calls = 0;
    public function handle(): void { self::$calls++; throw new RuntimeException('downstream timeout'); }
    public function toArray(): array { return []; }
    public static function fromArray(array $data): static { return new static(); }
}
test('failing job is retried with backoff then moved to failed_jobs', function () {
    $q = new JobQueue(db(), maxAttempts: 2);
    $q->dispatch(new FlakyJob(), 'flaky');
    $q->work('flaky');                                        // attempt 1 -> released with backoff
    assertSame(1, $q->pending('flaky'));
    db()->execute("UPDATE jobs SET available_at = 0 WHERE queue = 'flaky'"); // fast-forward time
    $q->work('flaky');                                        // attempt 2 -> failed
    assertSame(0, $q->pending('flaky'));
    assertSame(2, FlakyJob::$calls);
    assertSame(1, count(db()->select('SELECT * FROM failed_jobs')));
});

echo "LLM output guard\n";
test('accepts well-formed LLM JSON', function () {
    assertSame('negative', LlmSentimentAnalyzer::parse('{"sentiment":"negative","summary":"Long wait"}')['sentiment']);
});
test('rejects hallucinated label / non-JSON', function () {
    foreach (['{"sentiment":"angry"}', 'Sure! Here is the JSON...'] as $bad) {
        try { LlmSentimentAnalyzer::parse($bad); throw new LogicException('should have thrown'); }
        catch (UnexpectedValueException) {}
    }
});

@unlink($tmp);
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
