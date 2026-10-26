-- Waiting executions (md/tasks_list/done/7.2-waiting-executions.md): a parked execution leaves Redis and the worker,
-- its state waits here until due_at; WaitResumeJob wakes it up.

ALTER TABLE executions ADD COLUMN IF NOT EXISTS waiting_until TIMESTAMPTZ;
ALTER TABLE executions ADD COLUMN IF NOT EXISTS wait_reason   TEXT;

CREATE TABLE IF NOT EXISTS execution_waits (
    execution_id UUID PRIMARY KEY REFERENCES executions(id) ON DELETE CASCADE,
    process_id   BIGINT      NOT NULL REFERENCES processes(id),
    due_at       TIMESTAMPTZ NOT NULL,
    reason       TEXT        NOT NULL,              -- delay | retry | …
    info         JSONB       NOT NULL DEFAULT '{}', -- what the step needs after waking (pc, seq, attempts …)
    state        JSONB       NOT NULL,              -- the full exec:{uuid} state
    attempts     INT         NOT NULL DEFAULT 0,
    locked_until TIMESTAMPTZ,
    locked_by    TEXT,
    last_error   TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_execution_waits_due ON execution_waits (due_at);
CREATE INDEX IF NOT EXISTS idx_execution_waits_process ON execution_waits (process_id);
CREATE INDEX IF NOT EXISTS idx_executions_waiting ON executions (waiting_until) WHERE status = 'waiting';
