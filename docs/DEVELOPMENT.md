# EquiPath development setup

## Current status

This is an early foundation, not a complete application. It includes the public landing page, a deliberately limited API health endpoint, a supplementary WinForms API-connectivity screen, and an initial relational database migration. Only a minimal database-backed credential verifier and isolated secure-session primitives have been added for testing. A complete role/resource authorization middleware, database-backed application APIs, document upload/download, evaluation workflows, accreditation decision enforcement, notifications, and registrar integration are not implemented yet.

Do not use real applicant records or portfolio documents in this scaffold.

## Web stack (proposal-aligned)

1. Install a current XAMPP distribution with PHP and MySQL/MariaDB.
2. Copy the repository's web directory to xampp/htdocs/equipath.
3. Start Apache and MySQL in the XAMPP Control Panel.
4. Create a fresh development database, then import database/migrations/001_initial_schema.sql followed by database/migrations/002_authentication_rate_limits.sql using phpMyAdmin's Import tab.
5. Open http://localhost/equipath/public/ and check http://localhost/equipath/api/health.php.

Migration 001 creates the equipath_eteeap database if it does not exist and creates 23 core relational tables. Migration 002 adds the authentication rate-limit table. Neither migration seeds users or contains DROP statements. Apply migration 001 only to a fresh development database, then apply later migrations in order. A migration file is not a backup or a production migration plan; schema upgrades must be versioned and reviewed.

The health endpoint deliberately returns database: not_checked; it does not connect to the database. Do not interpret a healthy HTTP response as proof that MySQL is configured or reachable.

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

For database integration tests, apply the migration to a disposable MySQL 8.4 database and set DB_HOST, DB_USER, DB_PASSWORD, and DB_NAME before running php tests/php/database_integration.php. CI provisions an isolated MySQL service, applies the migration, and runs these checks automatically.

The initial schema covers configurable program requirements and approved credit records as well as application workflow data. The current database tests check table creation, unique email enforcement, and rejection of invalid foreign-key references. Authentication integration tests verify valid active-account credentials, wrong passwords, unknown email addresses, pending/disabled accounts, empty inputs, safe return values, and successful-login timestamps. They do not prove every workflow, authorization rule, concurrency case, backup, or restoration scenario.

## Important test gates before real use

Before enabling a workflow, add tests for the corresponding behavior:

- Authentication: credential-verifier tests cover valid/invalid credentials, pending/disabled accounts, unknown email, empty input, and safe return values. Session security primitives now cover session ID rotation, minimized identity storage, CSRF token validation, and session destruction. Still required: password reset, role/resource authorization, comprehensive endpoint tests, and operational recovery. HTTP authentication endpoints and database-backed login throttling are present as a development foundation but must not be treated as production-ready until reviewed and tested end-to-end.
- Authorization: every protected endpoint denied by default; applicant cannot read another applicant's files; evaluators see only assigned cases; registrar and approver permissions remain distinct; administrator cannot silently approve academic outcomes.
- Input and SQL: prepared statements; malformed IDs and payloads; server-side validation; invalid state transitions; duplicate submissions and concurrency.
- Evidence uploads: extension/MIME/content checks, size limits, randomized storage names, storage outside the public web root, path traversal rejection, access checks on download.
- Evaluation and decisions: rubric score bounds, required feedback, assignment constraints, approval provenance, immutable or versioned decision history.
- Database: migrations from clean and prior versions, constraint violations, transaction rollback, least-privilege DB user, backup and restore drill.
- Privacy and audit: audit events for sensitive reads/changes, no passwords/tokens/file contents in logs, retention/export rules confirmed with ADFC.
- Integration: malformed/duplicate registrar imports, validation reports, idempotent retries, and reconciliation of failures.
- Desktop client: API timeout, HTTP error, invalid JSON, unsupported API response, and no embedded database credentials.

## CI

GitHub Actions runs PHP syntax and smoke tests, validates the schema contract, applies the migration to a clean MySQL 8.4 service, runs database integrity and credential-verifier tests, and builds the WinForms project on Windows. Green CI is a required quality gate, not proof that the system is production-ready.
