# EquiPath development setup

## Current status

This is an early foundation, not a complete application. It includes the public landing page, a deliberately limited API health endpoint, a supplementary WinForms API-connectivity screen, and an initial relational database migration. Authentication, authorization middleware, database-backed API operations, document upload/download, evaluation workflows, accreditation decision enforcement, notifications, and registrar integration are not implemented yet.

Do not use real applicant records or portfolio documents in this scaffold.

## Web stack (proposal-aligned)

1. Install a current XAMPP distribution with PHP and MySQL/MariaDB.
2. Copy the repository's web directory to xampp/htdocs/equipath.
3. Start Apache and MySQL in the XAMPP Control Panel.
4. Create a fresh development database, then import database/migrations/001_initial_schema.sql using phpMyAdmin's Import tab.
5. Open http://localhost/equipath/public/ and check http://localhost/equipath/api/health.php.

The migration creates the equipath_eteeap database if it does not exist, then creates 23 relational tables. It contains no DROP statements or seed users. Apply it only to a fresh development database. A migration file is not a backup or a production migration plan; schema upgrades must be versioned and reviewed.

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

The initial schema covers configurable program requirements and approved credit records as well as application workflow data. The current database tests check table creation, unique email enforcement, and rejection of invalid foreign-key references. They do not prove every workflow, authorization rule, concurrency case, backup, or restoration scenario.

## Important test gates before real use

Before enabling a workflow, add tests for the corresponding behavior:

- Authentication: valid/invalid credentials, password hashing, disabled accounts, session rotation, logout, login throttling.
- Authorization: every protected endpoint denied by default; applicant cannot read another applicant's files; evaluators see only assigned cases; registrar and approver permissions remain distinct; administrator cannot silently approve academic outcomes.
- Input and SQL: prepared statements; malformed IDs and payloads; server-side validation; invalid state transitions; duplicate submissions and concurrency.
- Evidence uploads: extension/MIME/content checks, size limits, randomized storage names, storage outside the public web root, path traversal rejection, access checks on download.
- Evaluation and decisions: rubric score bounds, required feedback, assignment constraints, approval provenance, immutable or versioned decision history.
- Database: migrations from clean and prior versions, constraint violations, transaction rollback, least-privilege DB user, backup and restore drill.
- Privacy and audit: audit events for sensitive reads/changes, no passwords/tokens/file contents in logs, retention/export rules confirmed with ADFC.
- Integration: malformed/duplicate registrar imports, validation reports, idempotent retries, and reconciliation of failures.
- Desktop client: API timeout, HTTP error, invalid JSON, unsupported API response, and no embedded database credentials.

## CI

GitHub Actions runs PHP syntax and smoke tests, validates the schema contract, applies the migration to a clean MySQL 8.4 service, runs database integrity tests, and builds the WinForms project on Windows. Green CI is a required quality gate, not proof that the system is production-ready.
