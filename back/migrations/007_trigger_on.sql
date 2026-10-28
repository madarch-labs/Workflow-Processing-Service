-- Event types a process is started by (md/tasks_list/done/6.2-event-type.md): create / update / delete.
-- The default keeps the former behaviour of every existing process.

ALTER TABLE processes
    ADD COLUMN IF NOT EXISTS trigger_on TEXT[] NOT NULL DEFAULT ARRAY['create', 'update']::TEXT[];

ALTER TABLE processes DROP CONSTRAINT IF EXISTS processes_trigger_on_check;
ALTER TABLE processes ADD CONSTRAINT processes_trigger_on_check
    CHECK (trigger_on <@ ARRAY['create', 'update', 'delete']::TEXT[]);
