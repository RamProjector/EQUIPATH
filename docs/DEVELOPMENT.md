# EquiPath development setup

## Current status

This is an early foundation, not a complete application. It includes the public landing page, a deliberately limited API health endpoint, a supplementary WinForms API-connectivity screen, relational database migrations, credential verification, HTTP session endpoints, login throttling, and server-side authorization primitives. Application listing and draft creation APIs are implemented. Protected PDF evidence upload/download and a coordinator-only screening API foundation are present; full staff/applicant screens, evaluation workflows, assessment handling, accreditation decision enforcement, and registrar integration are not implemented yet.

Do not use real applicant records or portfolio documents in this scaffold.

## Web stack (proposal-aligned)

1. Install a current XAMPP distribution with PHP and MySQL/MariaDB.
2. Copy the repository's web directory to xampp/htdocs/equipath.
3. Start Apache and MySQL in the XAMPP Control Panel.
4. Create a fresh development database, then import database/migrations/001_initial_schema.sql followed by database/migrations/002_authentication_rate_limits.sql using phpMyAdmin's Import tab.
5. Open http://localhost/equipath/public/ and check http://localhost/equipath/api/health.php.

Migration 001 creates the equipath_eteeap database if it does not exist and creates 23 core relational tables. Migration 002 adds the authentication rate-limit table. Neither migration seeds users or contains DROP statements. Apply migration 001 only to a fresh development database, then apply later migrations in order. A migration file is not a backup or a production migration plan; schema upgrades must be versioned and reviewed.

Configure DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME, and a random AUTH_RATE_LIMIT_SECRET (at least 32 characters) in the PHP process environment before using the authentication endpoints. Keep these values out of source control and use a least-privilege database account. The CSRF bootstrap endpoint is GET /api/csrf.php; submit JSON credentials by POST to /api/authenticate.php with the returned token in the X-CSRF-Token header; POST to /api/logout.php with the current CSRF token to sign out. GET /api/applications.php returns at most 50 applications owned by the signed-in applicant and/or assigned to the signed-in evaluator. It requires an active account with an applicant or evaluator role, and checks ownership/assignment for each returned application. POST /api/create-application.php creates a draft for an active program and an application period whose status is open and current time is within its configured window. Send JSON containing integer `program_id` and `period_id`, plus the current `X-CSRF-Token` obtained from GET /api/csrf.php. The endpoint derives applicant identity from the authenticated session, generates the application number server-side, and transactionally writes the draft and initial status history. The service has MySQL integration coverage for owner/status persistence, history, and rejection of inactive programs or closed periods. The screening endpoint accepts a coordinator's eligible, ineligible, or needs-correction outcome, stores the decision and related status history/audit record transactionally, and creates an applicant notification. Feedback entered in this endpoint is applicant-facing. MySQL integration tests cover successful side effects and invalid states/outcomes; HTTP guard tests cover method and CSRF rejection. GET /api/notifications.php returns up to 100 newest notifications for the signed-in user only. POST /api/mark-notification-read.php accepts a positive integer `notification_id` and requires a current CSRF token; it only updates notifications owned by the signed-in recipient, is idempotent for already-read notifications, and writes an audit event on first read. MySQL integration tests cover recipient isolation, read-state persistence and audit logging; HTTP guard tests cover method restrictions, unauthenticated inbox denial and CSRF rejection. POST /api/upload-document.php accepts multipart form fields `application_id`, `document_type`, and `document` plus the current `X-CSRF-Token`. It is applicant-only, verifies ownership and editable application status, requires an active program requirement key, accepts PDF files up to 10 MiB by default (or a smaller per-requirement limit), checks server-detected MIME and PDF signature, generates a random storage key, writes a SHA-256 digest, records an audit event, and stores bytes only at `EQUIPATH_PRIVATE_STORAGE_PATH` outside the web root. Configure that path to a private writable directory not served by Apache/Nginx; uploads fail closed if it is missing. `EQUIPATH_MAX_UPLOAD_BYTES` can lower the default limit (maximum 10 MiB). GET /api/download-document.php?document_id={id} returns a PDF as an attachment only to the owning applicant or an evaluator with a current assignment. It verifies the stored file size, PDF signature, and SHA-256 before sending bytes, uses no-store and anti-sniffing/frame protections, and writes a successful-download audit event before responding. Unauthorized access is denied and administrator access is not implicit. Automated MySQL integration tests cover owner and assigned-evaluator access, cross-applicant denial, administrator denial, integrity verification, and audit logging. Automated HTTP guard tests verify that GET is rejected and multipart POST without a valid CSRF token is denied before database access. A successful authenticated multipart upload and its rollback/cleanup paths still need end-to-end integration coverage. Antivirus scanning/content disarm also remains outstanding; do not use real applicant files or treat the feature as production-ready. Enforce HTTPS in deployed environments. The health endpoint deliberately returns database: not_checked; it does not connect to the database. Do not interpret a healthy HTTP response as proof that MySQL is configured or reachable.

## Desktop stack (supplementary)

1. Install Visual Studio with the .NET desktop development workload and the .NET 10 SDK.
2. Open desktop/EquiPath.Desktop.csproj.
3. Start Apache and ensure the PHP API is available at http://localhost/equipath/api/health.php.
4. Run the desktop project and select Check PHP API.

The desktop client calls PHP instead of connecting directly to MySQL. This keeps database credentials out of the desktop binary and allows the PHP API to enforce the same business rules for both clients.

## Run tests

From the repository root:

    php -l web/api/health.php
    php -l web/public/index.php
    php tests/php/run.php
    php tests/php/schema_contract.php
    php tests/php/session_security.php\n    php tests/php/authorization_integration.php

For database integration tests, apply migrations 001 and 002 to a disposable MySQL 8.4 database and set DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, and a random AUTH_RATE_LIMIT_SECRET of at least 32 characters. CI provisions an isolated MySQL service, applies both migrations, and runs database, authentication, authorization, session-security, login-throttling, screening, notification, document-upload input-validation, and authorized document-download integration tests automatically.

The initial schema covers configurable program requirements and approved credit records as well as application workflow data. The current database tests check table creation, unique email enforcement, and rejection of invalid foreign-key references. Authentication integration tests verify valid active-account credentials, wrong passwords, unknown email addresses, pending/disabled accounts, empty inputs, safe return values, and successful-login timestamps. They do not prove every workflow, authorization rule, concurrency case, backup, or restoration scenario.

## Important test gates before real use

Before enabling a workflow, add tests for the corresponding behavior:

- Authentication: credential-verifier tests cover valid/invalid credentials, pending/disabled accounts, unknown email, empty input, and safe return values. Session security primitives now cover session ID rotation, minimized identity storage, CSRF token validation, and session destruction. Still required: password reset, role/resource authorization, comprehensive endpoint tests, and operational recovery. HTTP authentication endpoints and database-backed login throttling are present as a development foundation but must not be treated as production-ready until reviewed and tested end-to-end.
- Authorization: `AccessControl` rechecks active account status and database-backed role membership at decision time. Applicants are restricted to their own applications; evaluators require a current assignment; other staff roles must be explicitly allow-listed by the calling endpoint. Administrator access is not implicit. The application listing endpoint now calls the policy for each returned application, and the MySQL authorization integration test exercises the real endpoint to verify it does not expose another applicant's application. Other protected workflows still do not exist and must enforce the relevant policy with endpoint-level tests before being enabled.
- Input and SQL: prepared statements; malformed IDs and payloads; server-side validation; invalid state transitions; duplicate submissions and concurrency.
- Evidence files: upload validation, randomized private storage, upload audit events, authorized attachment downloads, stored-file integrity checks, and download audit events are implemented. CI checks upload endpoint method and CSRF guards over HTTP; still required: successful authenticated multipart-upload and rollback/cleanup integration coverage, malware scanning or an approved manual scanning workflow, retention/deletion policy, and operational storage monitoring.
- Evaluation and decisions: rubric score bounds, required feedback, assignment constraints, approval provenance, immutable or versioned decision history.
- Database: migrations from clean and prior versions, constraint violations, transaction rollback, least-privilege DB user, backup and restore drill.
- Privacy and audit: audit events for sensitive reads/changes, no passwords/tokens/file contents in logs, retention/export rules confirmed with ADFC.
- Integration: malformed/duplicate registrar imports, validation reports, idempotent retries, and reconciliation of failures.
- Desktop client: API timeout, HTTP error, invalid JSON, unsupported API response, and no embedded database credentials.

## CI

GitHub Actions runs PHP syntax and smoke tests, validates the schema contract, applies both migrations to a clean MySQL 8.4 service, runs database integrity, credential-verifier, authorization, session-security, and login-throttling tests, and builds the WinForms project on Windows. Green CI is a required quality gate, not proof that the system is production-ready.
