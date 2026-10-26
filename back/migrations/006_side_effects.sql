-- Journal of external side effects (md/tasks_list/done/0.4-side-effect-ledger.md): an e-mail, a webhook or a
-- notification is recorded as "started" before the call and "done" after it, so a step repeated after a worker
-- crash does not send it twice ("started" without "done" = unknown outcome).
CREATE TABLE IF NOT EXISTS step_side_effects (
    execution_id   UUID        NOT NULL REFERENCES executions(id) ON DELETE CASCADE,
    effect_key     TEXT        NOT NULL,                 -- "{pc}[@{iteration}][#{sub}]"
    action_type    TEXT        NOT NULL,
    delivery       TEXT        NOT NULL CHECK (delivery IN ('at_most_once', 'at_least_once')),
    status         TEXT        NOT NULL CHECK (status IN ('started', 'done', 'failed')),
    attempt_token  UUID        NOT NULL,
    request_hash   TEXT        NOT NULL,
    response       JSONB,                                -- masked (no secrets)
    error_message  TEXT,
    attempts       INT         NOT NULL DEFAULT 1,
    started_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at    TIMESTAMPTZ,
    PRIMARY KEY (execution_id, effect_key)
);
CREATE INDEX IF NOT EXISTS idx_side_effects_started ON step_side_effects(started_at);
