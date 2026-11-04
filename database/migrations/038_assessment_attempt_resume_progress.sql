ALTER TABLE assessment_attempts
    ADD COLUMN current_question_index SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER question_order_json;
