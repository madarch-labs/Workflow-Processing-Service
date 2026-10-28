-- Flat program execution (md/tasks_list/done/0.1-program-compiler.md): instruction address, tree path, loop
-- iteration and user step id of every audited step. step_index is now the audit sequence number (seq);
-- for linear processes seq == pc == the old step index.
ALTER TABLE execution_actions ADD COLUMN IF NOT EXISTS pc        INT;
ALTER TABLE execution_actions ADD COLUMN IF NOT EXISTS step_path TEXT;
ALTER TABLE execution_actions ADD COLUMN IF NOT EXISTS iteration TEXT NOT NULL DEFAULT '';
ALTER TABLE execution_actions ADD COLUMN IF NOT EXISTS step_id   TEXT;

-- existing rows come from linear programs: pc == step_index
UPDATE execution_actions SET pc = step_index WHERE pc IS NULL;

CREATE INDEX IF NOT EXISTS idx_exec_actions_exec_pc ON execution_actions(execution_id, pc);
