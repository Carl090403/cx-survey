<?php
declare(strict_types=1);

namespace App\Queue;

use App\Database;

/**
 * Database-backed queue with reservation, retries and exponential backoff,
 * modelled on Laravel's `database` queue driver. In production the same
 * interface would sit on Redis or SQS.
 */
final class JobQueue
{
    public function __construct(private Database $db, private int $maxAttempts = 3) {}

    public function dispatch(Job $job, string $queue = 'default', int $delaySeconds = 0): int
    {
        return $this->db->insert(
            'INSERT INTO jobs (queue, payload, available_at) VALUES (?, ?, ?)',
            [$queue, json_encode(['class' => $job::class, 'data' => $job->toArray()]), time() + $delaySeconds]
        );
    }

    /** Process up to $limit jobs; returns number processed (succeeded or failed). */
    public function work(string $queue = 'default', int $limit = 100): int
    {
        $processed = 0;
        while ($processed < $limit && ($row = $this->reserve($queue)) !== null) {
            $payload = json_decode($row['payload'], true);
            try {
                /** @var Job $job */
                $job = $payload['class']::fromArray($payload['data']);
                $job->handle();
                $this->db->execute('DELETE FROM jobs WHERE id = ?', [$row['id']]);
            } catch (\Throwable $e) {
                $this->release($row, $e);
            }
            $processed++;
        }
        return $processed;
    }

    public function pending(string $queue = 'default'): int
    {
        return (int) $this->db->select('SELECT COUNT(*) c FROM jobs WHERE queue = ?', [$queue])[0]['c'];
    }

    private function reserve(string $queue): ?array
    {
        return $this->db->transaction(function (Database $db) use ($queue) {
            $rows = $db->select(
                'SELECT * FROM jobs WHERE queue = ? AND reserved_at IS NULL AND available_at <= ? ORDER BY id LIMIT 1',
                [$queue, time()]
            );
            if (!$rows) {
                return null;
            }
            $db->execute('UPDATE jobs SET reserved_at = ?, attempts = attempts + 1 WHERE id = ?', [time(), $rows[0]['id']]);
            $rows[0]['attempts']++;
            return $rows[0];
        });
    }

    private function release(array $row, \Throwable $e): void
    {
        if ($row['attempts'] >= $this->maxAttempts) {
            $this->db->insert('INSERT INTO failed_jobs (payload, exception) VALUES (?, ?)', [$row['payload'], $e->getMessage()]);
            $this->db->execute('DELETE FROM jobs WHERE id = ?', [$row['id']]);
            return;
        }
        $backoff = 2 ** $row['attempts'];          // 2s, 4s, 8s...
        $this->db->execute('UPDATE jobs SET reserved_at = NULL, available_at = ? WHERE id = ?', [time() + $backoff, $row['id']]);
    }
}
