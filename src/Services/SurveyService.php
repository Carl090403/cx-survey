<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Http\ValidationException;
use App\Queue\JobQueue;

final class SurveyService
{
    public function __construct(private Database $db, private JobQueue $queue) {}

    public function create(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $questions = $input['questions'] ?? [];
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'required';
        }
        if (!is_array($questions) || $questions === []) {
            $errors['questions'] = 'at least one question is required';
        }
        foreach ((array) $questions as $i => $q) {
            if (!in_array($q['type'] ?? null, ['nps', 'rating', 'text', 'checkbox'], true)) {
                $errors["questions.$i.type"] = 'must be one of nps, rating, text, checkbox';
            }
            if (trim((string) ($q['label'] ?? '')) === '') {
                $errors["questions.$i.label"] = 'required';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $id = $this->db->transaction(function (Database $db) use ($title, $input, $questions) {
            $id = $db->insert('INSERT INTO surveys (title, location) VALUES (?, ?)', [$title, $input['location'] ?? null]);
            foreach (array_values($questions) as $pos => $q) {
                $db->insert('INSERT INTO questions (survey_id, type, label, position) VALUES (?, ?, ?, ?)',
                    [$id, $q['type'], $q['label'], $pos]);
            }
            return $id;
        });
        return $this->find($id);
    }

    public function find(int $id): ?array
    {
        $survey = $this->db->select('SELECT * FROM surveys WHERE id = ?', [$id])[0] ?? null;
        if ($survey) {
            $survey['questions'] = $this->db->select('SELECT id, type, label FROM questions WHERE survey_id = ? ORDER BY position', [$id]);
        }
        return $survey;
    }

    /** @param array<int|string, mixed> $answers question_id => value */
    public function submit(int $surveyId, array $answers): array
    {
        $survey = $this->find($surveyId) ?? throw new \OutOfBoundsException('survey not found');
        $errors = [];
        foreach ($survey['questions'] as $q) {
            $v = $answers[$q['id']] ?? null;
            if ($v === null || $v === '') {
                $errors["answers.{$q['id']}"] = 'required';
            } elseif ($q['type'] === 'nps' && !(is_numeric($v) && (int) $v >= 0 && (int) $v <= 10)) {
                $errors["answers.{$q['id']}"] = 'NPS must be 0-10';
            } elseif ($q['type'] === 'rating' && !(is_numeric($v) && (int) $v >= 1 && (int) $v <= 5)) {
                $errors["answers.{$q['id']}"] = 'rating must be 1-5';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $responseId = $this->db->transaction(function (Database $db) use ($surveyId, $survey, $answers) {
            $rid = $db->insert('INSERT INTO responses (survey_id) VALUES (?)', [$surveyId]);
            foreach ($survey['questions'] as $q) {
                $db->insert('INSERT INTO answers (response_id, question_id, value) VALUES (?, ?, ?)',
                    [$rid, $q['id'], is_bool($answers[$q['id']]) ? (int) $answers[$q['id']] : (string) $answers[$q['id']]]);
            }
            return $rid;
        });
        $this->queue->dispatch(new AnalyzeResponseJob($responseId), 'ai');
        return ['response_id' => $responseId, 'analysis' => 'queued'];
    }

    /** Aggregated report: NPS, average ratings, sentiment split. */
    public function report(int $surveyId): array
    {
        $this->find($surveyId) ?? throw new \OutOfBoundsException('survey not found');
        $nps = $this->db->select(
            "SELECT CAST(a.value AS INTEGER) v FROM answers a JOIN questions q ON q.id = a.question_id
             WHERE q.survey_id = ? AND q.type = 'nps'", [$surveyId]);
        $scores = array_column($nps, 'v');
        $n = count($scores);
        $promoters = count(array_filter($scores, fn ($s) => $s >= 9));
        $detractors = count(array_filter($scores, fn ($s) => $s <= 6));
        $ratings = $this->db->select(
            "SELECT q.label, ROUND(AVG(CAST(a.value AS REAL)), 2) avg FROM answers a JOIN questions q ON q.id = a.question_id
             WHERE q.survey_id = ? AND q.type = 'rating' GROUP BY q.id ORDER BY q.position", [$surveyId]);
        $sentiment = $this->db->select(
            'SELECT COALESCE(sentiment, \'pending\') s, COUNT(*) c FROM responses WHERE survey_id = ? GROUP BY s', [$surveyId]);
        return [
            'responses' => (int) $this->db->select('SELECT COUNT(*) c FROM responses WHERE survey_id = ?', [$surveyId])[0]['c'],
            'nps' => $n ? (int) round(100 * ($promoters - $detractors) / $n) : null,
            'ratings' => $ratings,
            'sentiment' => array_column($sentiment, 'c', 's'),
        ];
    }
}
