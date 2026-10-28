-- Error handling of steps (md/tasks_list/done/7.1-on-error.md): errors handled by on_error, classified error codes.

ALTER TABLE executions        ADD COLUMN IF NOT EXISTS handled_errors INT NOT NULL DEFAULT 0;
ALTER TABLE execution_actions ADD COLUMN IF NOT EXISTS error_code TEXT;
ALTER TABLE execution_actions ADD COLUMN IF NOT EXISTS handled    TEXT;   -- NULL | continue | goto
