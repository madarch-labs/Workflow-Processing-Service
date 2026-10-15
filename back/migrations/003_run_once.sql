-- "Run once per record" (md/idempotentie_plan.md): process flag + marks "process P was started for CRM record X".

ALTER TABLE processes ADD COLUMN IF NOT EXISTS run_once BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS process_object_runs (
    process_id      BIGINT    NOT NULL REFERENCES processes(id),
    object_type     TEXT      NOT NULL,
    object_id       TEXT      NOT NULL,              -- id of the saved CRM record (the "id" of the event)
    execution_id    UUID      NOT NULL,              -- first (blocking) execution; no FK: the mark is written before the executions row
    event_id        TEXT      NOT NULL,
    trigger         TEXT      NOT NULL,              -- stream, child, manual
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    blocked_count   INT       NOT NULL DEFAULT 0,
    last_blocked_at TIMESTAMP,
    PRIMARY KEY (process_id, object_type, object_id)
);

CREATE INDEX IF NOT EXISTS idx_por_object ON process_object_runs(object_type, object_id);
CREATE INDEX IF NOT EXISTS idx_por_process_created ON process_object_runs(process_id, created_at DESC);
