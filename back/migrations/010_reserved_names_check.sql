-- 5.2.9: sys, config and object_before become reserved variable names.
-- This is a report, not a hard failure: existing processes may still use them as ordinary variables and would stop
-- saving after the upgrade (running executions use a definition snapshot). Rename those variables first.

DO $$
DECLARE
    r RECORD;
    found INTEGER := 0;
BEGIN
    FOR r IN
        SELECT id, name
        FROM processes
        WHERE definition::text ~ '"(var|as|item_var)"\s*:\s*"(sys|config|object_before)"'
           OR definition::text ~ '"(target|record)"\s*:\s*"(sys|config|object_before)(\.|")'
    LOOP
        found := found + 1;
        RAISE NOTICE 'Process % (%): reserved variable name sys/config/object_before is used; rename it (5.2.9)', r.id, r.name;
    END LOOP;
    IF found = 0 THEN
        RAISE NOTICE '5.2.9: no process uses sys/config/object_before as a variable';
    ELSE
        RAISE NOTICE '5.2.9: % process(es) must be updated before they can be saved again', found;
    END IF;
END $$;
