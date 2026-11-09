<?php
declare(strict_types=1);

use App\Http\Router;
use App\Queue\JobQueue;
use App\Services\SurveyService;

function app(): Router
{
    $surveys = new SurveyService(db(), new JobQueue(db()));
    $r = new Router();
    $r->add('POST', '/api/surveys', fn ($p, $b) => [201, $surveys->create($b)]);
    $r->add('GET', '/api/surveys/{id}', fn ($p) => ($s = $surveys->find($p['id'])) ? [200, $s] : [404, ['message' => 'survey not found']]);
    $r->add('POST', '/api/surveys/{id}/responses', fn ($p, $b) => [202, $surveys->submit($p['id'], $b['answers'] ?? [])]);
    $r->add('GET', '/api/surveys/{id}/report', fn ($p) => [200, $surveys->report($p['id'])]);
    return $r;
}
