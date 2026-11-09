# CX Survey API — surveys, queued AI sentiment analysis & reporting (PHP 8.3)

A small **customer-experience platform backend**: create surveys (NPS, rating, text, checkbox),
collect responses through a JSON REST API, analyse free-text comments **asynchronously with an
LLM** through a **database-backed job queue**, and serve an aggregated report (NPS, average
ratings, sentiment split).

Written in modern, framework-free PHP 8.3 (strict types, readonly properties, enums-style
validation) with the same building blocks as a Laravel service — routing, form-request style
validation (422 + field errors), transactions, queued jobs with retries/backoff and a
`failed_jobs` table — so it maps directly onto Laravel (`Route::post`, `FormRequest`,
`ShouldQueue`, `php artisan queue:work`).

## Features
| Area | What it shows |
|---|---|
| REST API design | `POST /api/surveys`, `GET /api/surveys/{id}`, `POST /api/surveys/{id}/responses` (202 Accepted), `GET /api/surveys/{id}/report` |
| Data modelling | surveys → questions → responses → answers, FK + CHECK constraints, indexes (`database/schema.sql`) |
| Queued jobs | `JobQueue`: reserve → handle → delete; retry with exponential backoff; dead-letter to `failed_jobs` |
| LLM integration | `LlmSentimentAnalyzer` calls any OpenAI-compatible endpoint, forces JSON output and **validates it** before writing to the DB (hallucinated labels → exception → retry) |
| Testability | `SentimentAnalyzer` interface: deterministic offline analyzer in tests/CI, real LLM in prod (`LLM_API_KEY`) |
| Reporting | NPS = %promoters − %detractors, rating averages, sentiment distribution in SQL |

## Run
```bash
php tests/run.php                       # 10 tests, no dependencies
php -S localhost:8000 -t public         # API
php bin/worker.php ai                   # queue worker (like queue:work --queue=ai)

curl -X POST localhost:8000/api/surveys -d '{"title":"Store visit","questions":[{"type":"nps","label":"Recommend us?"},{"type":"text","label":"Why?"}]}'
curl -X POST localhost:8000/api/surveys/1/responses -d '{"answers":{"1":10,"2":"Excellent and friendly staff"}}'
curl localhost:8000/api/surveys/1/report
```
Use a real LLM: `export LLM_API_KEY=... LLM_MODEL=gpt-4o-mini` (any OpenAI-compatible base URL via `LLM_BASE_URL`).

## How AI tools were used (and reviewed)
AI coding assistants were used to scaffold boilerplate and brainstorm test cases; every change was
reviewed, tested and owned by me. The LLM *in the product* is treated as an untrusted dependency:
strict JSON contract, validation, retries, offline fallback.

## Next steps
* Port to Laravel 11 (Eloquent models, FormRequests, `ShouldQueue` jobs on Redis/SQS)
* MySQL in Docker + Redis queue driver
* Angular (Nx) dashboard consuming `/report`
