-- Who filed the overtime request. Only a Supervisor may create one, so this
-- records the requester for the approval queue and audit trail. Existing rows
-- keep NULL because they predate the supervisor-only workflow.
ALTER TABLE overtime_records
    ADD COLUMN requested_by BIGINT(20) NULL AFTER requested_at;

ALTER TABLE overtime_records
    ADD KEY idx_ot_requested_by (requested_by);

ALTER TABLE overtime_records
    ADD CONSTRAINT fk_ot_requested_by
        FOREIGN KEY (requested_by) REFERENCES users(user_id) ON DELETE SET NULL;
