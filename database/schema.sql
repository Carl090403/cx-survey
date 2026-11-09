-- Portable schema (SQLite for local dev/tests; same structure maps 1:1 to MySQL migrations)
CREATE TABLE IF NOT EXISTS surveys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    location    TEXT,
    created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS questions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    survey_id   INTEGER NOT NULL REFERENCES surveys(id) ON DELETE CASCADE,
    type        TEXT    NOT NULL CHECK (type IN ('nps','rating','text','checkbox')),
    label       TEXT    NOT NULL,
    position    INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS responses (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    survey_id   INTEGER NOT NULL REFERENCES surveys(id) ON DELETE CASCADE,
    submitted_at TEXT   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sentiment   TEXT,              -- filled asynchronously by AnalyzeResponseJob
    summary     TEXT
);
CREATE TABLE IF NOT EXISTS answers (
    response_id INTEGER NOT NULL REFERENCES responses(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL REFERENCES questions(id),
    value       TEXT    NOT NULL,
    PRIMARY KEY (response_id, question_id)
);
-- Database-backed job queue (same idea as Laravel's `database` queue driver)
CREATE TABLE IF NOT EXISTS jobs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    queue        TEXT    NOT NULL DEFAULT 'default',
    payload      TEXT    NOT NULL,
    attempts     INTEGER NOT NULL DEFAULT 0,
    available_at INTEGER NOT NULL,
    reserved_at  INTEGER
);
CREATE TABLE IF NOT EXISTS failed_jobs (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    payload   TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_jobs_available ON jobs(queue, reserved_at, available_at);
CREATE INDEX IF NOT EXISTS idx_responses_survey ON responses(survey_id);
