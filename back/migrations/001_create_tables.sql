-- WPS schema (TS §4.1). TIMESTAMP columns are stored in UTC.

CREATE TABLE IF NOT EXISTS users (
    id            BIGSERIAL PRIMARY KEY,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL CHECK (role IN ('admin', 'editor', 'viewer')),
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Process configurations
CREATE TABLE IF NOT EXISTS processes (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    object_type   TEXT NOT NULL,              -- Accounts, Contacts, Calls ...
    is_child      BOOLEAN NOT NULL DEFAULT FALSE,
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    priority      INT NOT NULL DEFAULT 0,     -- lower value = runs first
    version       INT NOT NULL DEFAULT 1,
    definition    JSONB NOT NULL,             -- conditions, preload, actions
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at    TIMESTAMP                   -- soft delete keeps the execution audit consistent
);

CREATE INDEX IF NOT EXISTS idx_processes_object_type ON processes(object_type);
CREATE INDEX IF NOT EXISTS idx_processes_is_child ON processes(is_child) WHERE is_child = FALSE;
CREATE INDEX IF NOT EXISTS idx_processes_active ON processes(is_active) WHERE is_active = TRUE;
CREATE INDEX IF NOT EXISTS idx_processes_resolver ON processes(object_type, priority)
    WHERE deleted_at IS NULL AND is_active = TRUE AND is_child = FALSE;

-- Process runs for a specific event
CREATE TABLE IF NOT EXISTS executions (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    process_id          BIGINT NOT NULL REFERENCES processes(id),
    process_version     INT NOT NULL,
    parent_execution_id UUID REFERENCES executions(id) ON DELETE SET NULL,
    trigger             TEXT NOT NULL DEFAULT 'stream',   -- stream, manual, child
    event_id            TEXT NOT NULL,
    object_type         TEXT NOT NULL,
    object_id           TEXT NOT NULL,
    status              TEXT NOT NULL DEFAULT 'running',  -- running, completed, failed, retrying
    retry_count         INT NOT NULL DEFAULT 0,
    error_message       TEXT,
    started_at          TIMESTAMP NOT NULL DEFAULT NOW(),
    finished_at         TIMESTAMP,
    context_snapshot    JSONB                             -- final context snapshot
);

CREATE INDEX IF NOT EXISTS idx_executions_process_id ON executions(process_id);
CREATE INDEX IF NOT EXISTS idx_executions_event_id ON executions(event_id);
CREATE INDEX IF NOT EXISTS idx_executions_status ON executions(status);
CREATE INDEX IF NOT EXISTS idx_executions_started_at ON executions(started_at DESC);
CREATE INDEX IF NOT EXISTS idx_executions_parent ON executions(parent_execution_id);

-- Steps of an execution (audit)
CREATE TABLE IF NOT EXISTS execution_actions (
    id            BIGSERIAL PRIMARY KEY,
    execution_id  UUID NOT NULL REFERENCES executions(id) ON DELETE CASCADE,
    step_index    INT NOT NULL,
    action_type   TEXT NOT NULL,
    status        TEXT NOT NULL,                  -- success, failed, skipped
    attempts      INT NOT NULL DEFAULT 1,
    started_at    TIMESTAMP,
    duration_ms   INT,
    request_data  JSONB,
    result_data   JSONB,
    error_message TEXT,
    UNIQUE (execution_id, step_index)
);

CREATE INDEX IF NOT EXISTS idx_exec_actions_exec_id ON execution_actions(execution_id);
