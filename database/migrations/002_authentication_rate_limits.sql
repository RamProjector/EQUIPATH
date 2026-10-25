-- Authentication throttling support.
-- Apply after 001_initial_schema.sql. Retains only keyed counters and timestamps,
-- never raw passwords or submitted credentials.
USE equipath_eteeap;

CREATE TABLE authentication_rate_limits (
    rate_limit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_hash CHAR(64) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rate_limit_id),
    UNIQUE KEY uq_auth_rate_limit_subject (subject_hash),
    KEY ix_auth_rate_limit_blocked_until (blocked_until),
    CONSTRAINT chk_auth_rate_limit_count CHECK (attempt_count >= 0)
) ENGINE=InnoDB;
