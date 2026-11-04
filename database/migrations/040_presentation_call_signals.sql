CREATE TABLE IF NOT EXISTS presentation_simulation_call_signals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    simulation_id BIGINT UNSIGNED NOT NULL,
    sender_hash CHAR(64) NULL,
    recipient_hash CHAR(64) NULL,
    signal_type ENUM('ready', 'offer', 'answer', 'candidate', 'hangup', 'stream_name') NOT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_presentation_call_signals_recipient (simulation_id, recipient_hash, id),
    KEY idx_presentation_call_signals_sender (simulation_id, sender_hash, id),
    CONSTRAINT fk_presentation_call_signals_simulation FOREIGN KEY (simulation_id)
        REFERENCES presentation_simulations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
