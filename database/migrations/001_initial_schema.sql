-- EquiPath initial relational schema
-- Target: MySQL 8.0.16+ / compatible MariaDB with enforced CHECK constraints.
-- Apply only to a new development database. This migration intentionally contains no DROP statements.
CREATE DATABASE IF NOT EXISTS equipath_eteeap CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE equipath_eteeap;

CREATE TABLE roles (
    role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(40) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_role_key (role_key)
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    given_name VARCHAR(100) NOT NULL,
    family_name VARCHAR(100) NOT NULL,
    account_status ENUM('pending','active','disabled','locked') NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_email (email),
    KEY ix_users_status (account_status),
    CONSTRAINT chk_users_email_nonempty CHECK (CHAR_LENGTH(TRIM(email)) > 0),
    CONSTRAINT chk_users_password_hash_nonempty CHECK (CHAR_LENGTH(password_hash) >= 20)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY ix_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE academic_programs (
    program_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_code VARCHAR(30) NOT NULL,
    program_name VARCHAR(180) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (program_id),
    UNIQUE KEY uq_program_code (program_code),
    CONSTRAINT chk_program_code_nonempty CHECK (CHAR_LENGTH(TRIM(program_code)) > 0)
) ENGINE=InnoDB;

CREATE TABLE application_periods (
    period_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_name VARCHAR(120) NOT NULL,
    opens_at DATETIME NOT NULL,
    closes_at DATETIME NOT NULL,
    period_status ENUM('draft','scheduled','open','closed','archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (period_id),
    KEY ix_period_status_dates (period_status, opens_at, closes_at),
    CONSTRAINT chk_period_date_range CHECK (closes_at > opens_at)
) ENGINE=InnoDB;

CREATE TABLE applications (
    application_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_number VARCHAR(40) NOT NULL,
    applicant_user_id BIGINT UNSIGNED NOT NULL,
    program_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    application_status ENUM('draft','submitted','screening','needs_correction','eligible','ineligible','under_evaluation','assessment_scheduled','awaiting_decision','approved','rejected','withdrawn') NOT NULL DEFAULT 'draft',
    submitted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (application_id),
    UNIQUE KEY uq_application_number (application_number),
    KEY ix_applicant_applications (applicant_user_id, created_at),
    KEY ix_application_queue (application_status, updated_at),
    KEY ix_application_program_period (program_id, period_id),
    CONSTRAINT fk_applications_applicant FOREIGN KEY (applicant_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_applications_program FOREIGN KEY (program_id) REFERENCES academic_programs(program_id) ON DELETE RESTRICT,
    CONSTRAINT fk_applications_period FOREIGN KEY (period_id) REFERENCES application_periods(period_id) ON DELETE RESTRICT,
    CONSTRAINT chk_application_submitted_time CHECK (application_status = 'draft' OR submitted_at IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE application_status_history (
    status_history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    reason VARCHAR(1000) NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (status_history_id),
    KEY ix_status_history_application (application_id, changed_at),
    CONSTRAINT fk_status_history_application FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_status_history_actor FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE application_documents (
    document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    storage_key VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    detected_mime_type VARCHAR(120) NOT NULL,
    file_size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    review_status ENUM('pending','accepted','rejected','replacement_requested') NOT NULL DEFAULT 'pending',
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id),
    UNIQUE KEY uq_document_version (application_id, document_type, version_number),
    UNIQUE KEY uq_document_storage_key (storage_key),
    KEY ix_documents_review (application_id, review_status),
    CONSTRAINT fk_documents_application FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_document_size CHECK (file_size_bytes > 0),
    CONSTRAINT chk_document_sha256 CHECK (sha256 REGEXP '^[0-9a-fA-F]{64}$'),
    CONSTRAINT chk_document_version CHECK (version_number > 0)
) ENGINE=InnoDB;

CREATE TABLE screening_reviews (
    screening_review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    outcome ENUM('pending','eligible','ineligible','needs_correction') NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (screening_review_id),
    KEY ix_screening_application (application_id, created_at),
    CONSTRAINT fk_screening_application FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_screening_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_screening_review_time CHECK (outcome = 'pending' OR reviewed_at IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE evaluator_assignments (
    evaluator_assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    evaluator_user_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assignment_status ENUM('assigned','in_progress','submitted','reassigned','cancelled') NOT NULL DEFAULT 'assigned',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    PRIMARY KEY (evaluator_assignment_id),
    UNIQUE KEY uq_application_evaluator (application_id, evaluator_user_id),
    KEY ix_evaluator_queue (evaluator_user_id, assignment_status),
    CONSTRAINT fk_assignments_application FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignments_evaluator FOREIGN KEY (evaluator_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignments_assigner FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_assignment_submit_time CHECK (assignment_status <> 'submitted' OR submitted_at IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE evaluation_rubrics (
    rubric_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id BIGINT UNSIGNED NOT NULL,
    rubric_name VARCHAR(160) NOT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rubric_id),
    UNIQUE KEY uq_rubric_program_version (program_id, rubric_name, version_number),
    CONSTRAINT fk_rubrics_program FOREIGN KEY (program_id) REFERENCES academic_programs(program_id) ON DELETE RESTRICT,
    CONSTRAINT chk_rubric_version CHECK (version_number > 0)
) ENGINE=InnoDB;

CREATE TABLE rubric_criteria (
    criterion_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rubric_id BIGINT UNSIGNED NOT NULL,
    criterion_code VARCHAR(40) NOT NULL,
    criterion_name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    max_score DECIMAL(7,2) NOT NULL,
    weight DECIMAL(7,4) NOT NULL DEFAULT 1.0000,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (criterion_id),
    UNIQUE KEY uq_rubric_criterion_code (rubric_id, criterion_code),
    CONSTRAINT fk_criteria_rubric FOREIGN KEY (rubric_id) REFERENCES evaluation_rubrics(rubric_id) ON DELETE RESTRICT,
    CONSTRAINT chk_criterion_max_score CHECK (max_score > 0),
    CONSTRAINT chk_criterion_weight CHECK (weight > 0)
) ENGINE=InnoDB;

CREATE TABLE evaluation_scores (
    evaluation_score_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    evaluator_assignment_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    score DECIMAL(7,2) NOT NULL,
    feedback TEXT NULL,
    scored_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (evaluation_score_id),
    UNIQUE KEY uq_assignment_criterion_score (evaluator_assignment_id, criterion_id),
    CONSTRAINT fk_scores_assignment FOREIGN KEY (evaluator_assignment_id) REFERENCES evaluator_assignments(evaluator_assignment_id) ON DELETE RESTRICT,
    CONSTRAINT fk_scores_criterion FOREIGN KEY (criterion_id) REFERENCES rubric_criteria(criterion_id) ON DELETE RESTRICT,
    CONSTRAINT chk_score_nonnegative CHECK (score >= 0)
) ENGINE=InnoDB;

CREATE TABLE assessment_events (
    assessment_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    assessment_type ENUM('interview','examination','demonstration','other') NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    location_or_link VARCHAR(500) NULL,
    event_status ENUM('scheduled','completed','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assessment_event_id),
    KEY ix_assessment_schedule (starts_at, event_status),
    KEY ix_assessment_application (application_id, starts_at),
    CONSTRAINT fk_assessments_application FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_assessments_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_assessment_time_range CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE assessment_results (
    assessment_result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_event_id BIGINT UNSIGNED NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    outcome ENUM('pending','passed','failed','incomplete','excused') NOT NULL DEFAULT 'pending',
    score DECIMAL(7,2) NULL,
    feedback TEXT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assessment_result_id),
    UNIQUE KEY uq_assessment_result (assessment_event_id),
    CONSTRAINT fk_assessment_results_event FOREIGN KEY (assessment_event_id) REFERENCES assessment_events(assessment_event_id) ON DELETE RESTRICT,
    CONSTRAINT fk_assessment_results_recorder FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_assessment_score_nonnegative CHECK (score IS NULL OR score >= 0)
) ENGINE=InnoDB;

CREATE TABLE credit_recommendations (
    credit_recommendation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    evaluator_assignment_id BIGINT UNSIGNED NOT NULL,
    course_code VARCHAR(40) NOT NULL,
    course_title VARCHAR(180) NOT NULL,
    recommended_credits DECIMAL(6,2) NOT NULL,
    rationale TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (credit_recommendation_id),
    KEY ix_credit_recommendations_application (application_id),
    CONSTRAINT fk_credit_recommendations_application FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_recommendations_assignment FOREIGN KEY (evaluator_assignment_id) REFERENCES evaluator_assignments(evaluator_assignment_id) ON DELETE RESTRICT,
    CONSTRAINT chk_recommended_credits CHECK (recommended_credits >= 0)
) ENGINE=InnoDB;

CREATE TABLE accreditation_decisions (
    accreditation_decision_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    decided_by BIGINT UNSIGNED NOT NULL,
    decision ENUM('approved','rejected','deferred','returned_for_revision') NOT NULL,
    rationale TEXT NOT NULL,
    decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    supersedes_decision_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (accreditation_decision_id),
    KEY ix_decisions_application (application_id, decided_at),
    CONSTRAINT fk_decisions_application FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_decisions_decider FOREIGN KEY (decided_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_decisions_supersedes FOREIGN KEY (supersedes_decision_id) REFERENCES accreditation_decisions(accreditation_decision_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    related_application_id BIGINT UNSIGNED NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id),
    KEY ix_notifications_inbox (recipient_user_id, read_at, created_at),
    CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_notifications_application FOREIGN KEY (related_application_id) REFERENCES applications(application_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    audit_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    action_key VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    outcome ENUM('success','denied','failure') NOT NULL,
    request_id CHAR(36) NULL,
    source_ip VARBINARY(16) NULL,
    metadata JSON NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_log_id),
    KEY ix_audit_actor_time (actor_user_id, occurred_at),
    KEY ix_audit_entity_time (entity_type, entity_id, occurred_at),
    KEY ix_audit_action_time (action_key, occurred_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE integration_batches (
    integration_batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_by BIGINT UNSIGNED NOT NULL,
    source_system VARCHAR(100) NOT NULL,
    batch_status ENUM('queued','processing','completed','completed_with_errors','failed') NOT NULL DEFAULT 'queued',
    source_file_key VARCHAR(255) NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    accepted_rows INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary JSON NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (integration_batch_id),
    KEY ix_integration_batch_status (batch_status, created_at),
    CONSTRAINT fk_integration_batch_starter FOREIGN KEY (started_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_integration_row_counts CHECK (accepted_rows + rejected_rows <= total_rows),
    CONSTRAINT chk_integration_time_range CHECK (completed_at IS NULL OR started_at IS NULL OR completed_at >= started_at)
) ENGINE=InnoDB;

CREATE TABLE external_record_mappings (
    external_record_mapping_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration_batch_id BIGINT UNSIGNED NOT NULL,
    source_system VARCHAR(100) NOT NULL,
    external_record_id VARCHAR(180) NOT NULL,
    local_entity_type VARCHAR(80) NOT NULL,
    local_entity_id BIGINT UNSIGNED NOT NULL,
    mapped_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (external_record_mapping_id),
    UNIQUE KEY uq_external_record (source_system, external_record_id),
    KEY ix_external_local_entity (local_entity_type, local_entity_id),
    CONSTRAINT fk_external_mapping_batch FOREIGN KEY (integration_batch_id) REFERENCES integration_batches(integration_batch_id) ON DELETE RESTRICT
) ENGINE=InnoDB;
