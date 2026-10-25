# EquiPath development setup

## Current status

This is an initial scaffold, not a complete application. The public landing page and API health endpoint are the only implemented web features. The WinForms client currently checks whether the PHP API is reachable. Authentication, database access, application processing, document uploads, evaluation, decisions, notifications, and audit logging are not implemented yet.

Do not use real applicant records or portfolio documents in this scaffold.

## Web stack (proposal-aligned)

1. Install a current XAMPP distribution with PHP and MySQL/MariaDB.
2. Copy the repository's `web` directory to `xampp/htdocs/equipath`.
3. Start Apache in the XAMPP Control Panel.
4. Open `http://localhost/equipath/public/`.
5. Check `http://localhost/equipath/api/health.php`.

The health endpoint deliberately returns `database: not_checked`; it does not connect to the database. Do not interpret a healthy HTTP response as proof that MySQL is configured or reachable.

## Desktop stack (supplementary)

1. Install Visual Studio with the **.NET desktop development** workload and the .NET 10 SDK.
2. Open `desktop/EquiPath.Desktop.csproj`.
3. Start Apache and ensure the PHP API is available at `http://localhost/equipath/api/health.php`.
4. Run the desktop project and select **Check PHP API**.

The desktop client calls PHP instead of connecting directly to MySQL. This keeps database credentials out of the desktop binary and allows the PHP API to enforce the same business rules for both clients.

## Run the PHP smoke tests

From the repository root, run:

```sh
php -l web/api/health.php
php -l web/public/index.php
php tests/php/run.php
```

The smoke tests check the starter API response contract and the landing-page workflow labels. They are not a substitute for integration, authorization, database, upload, or end-to-end tests.

## Important test gates before real use

Before enabling a workflow, add tests for the corresponding behavior:

- **Authentication:** valid/invalid credentials, password hashing, disabled accounts, session rotation, logout, login throttling.
- **Authorization:** every protected endpoint denied by default; applicant cannot read another applicant's files; evaluators see only assigned cases; registrar and approver permissions remain distinct; administrator cannot silently approve academic outcomes.
- **Input and SQL:** prepared statements; malformed IDs and payloads; server-side validation; invalid state transitions; duplicate submissions and concurrency.
- **Evidence uploads:** extension/MIME/content checks, size limits, randomized storage names, storage outside the public web root, path traversal rejection, access checks on download.
- **Evaluation and decisions:** rubric score bounds, required feedback, assignment constraints, approval provenance, immutable or versioned decision history.
- **Database:** migration from a clean database, foreign-key/check constraints, transaction rollback, unique constraints, least-privilege DB user, backup and restore drill.
- **Privacy and audit:** audit events for sensitive reads/changes, no passwords/tokens/file contents in logs, retention/export rules confirmed with ADFC.
- **Integration:** malformed/duplicate registrar imports, validation reports, idempotent retries, and reconciliation of failures.
- **Desktop client:** API timeout, HTTP error, invalid JSON, unsupported API response, and no embedded database credentials.

## CI

GitHub Actions runs PHP syntax checks and dependency-free smoke tests on pull requests. It also builds the WinForms project on a Windows runner. These checks must pass before merging; green CI alone does not mean the system is production-ready.
