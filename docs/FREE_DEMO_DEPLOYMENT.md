# Free-compatible deployment guide (demo only)

## Purpose and status

This guide describes a cautious path for publishing a **synthetic-data demonstration** of EquiPath while preserving the PHP + MySQL + HTML/CSS/JavaScript + Apache-compatible stack. It does not deploy the application or certify any hosting provider as compatible.

**Do not use real applicant records, identification documents, portfolio evidence, or production credentials.** The current repository is an early foundation, not a production-ready student-records system. Malware scanning/content disarm, retention/deletion operations, backup-and-restore drills, and several end-to-end workflow tests remain outstanding.

## Recommended first path

Use a free PHP/MySQL shared host such as InfinityFree for a disposable demonstration, provided its current account limits and database behavior pass the checks below. Free-tier terms can change. Do not pay to unlock a feature; stop and reassess if the free tier cannot support a required capability.

A hosted application needs a **remote MySQL-compatible database**. Your local XAMPP MySQL service is not automatically reachable from the internet. Do not expose your home computer's MySQL port or router to make it public.

## Compatibility gate — before uploading

The repository CI uses MySQL 8.4. A shared host may provide MariaDB or a different MySQL version, and the repository's SQL uses modern MySQL features. Before using a host, confirm:

- PHP version supports the syntax and extensions used by the repository (including PDO MySQL).
- Database engine/version supports the migrations' CHECK constraints, JSON usage, regular-expression expressions, indexes, and foreign-key behavior.
- The account allows importing the schema size and creating all required tables/indexes.
- HTTPS is available and can be enforced.
- PHP can write to a private directory **outside the public web root** for uploaded PDF evidence.
- The host supports the required environment/configuration mechanism for database settings and the authentication rate-limit secret. If it only supports a private configuration file, keep that file outside the public web root and out of Git.
- The host's outbound/inbound connection policy, request limits, storage quota, and execution limits are sufficient for a small synthetic demo.

Do not assume compatibility from the label “MySQL support.” Run the migrations and automated tests against a disposable database using the actual hosted engine before accepting the deployment.

## Deployment sequence

### 1. Prepare an isolated demo database

1. Create a new free hosting account and a **new, empty database** dedicated to this demo.
2. Record the host-provided database host, database name, username, and port in a private password manager or secure local note. Never paste credentials into GitHub issues, source files, screenshots, or this chat.
3. Create/use a least-privilege database user for this application. Do not reuse a hosting control-panel administrator account as the application's database user.
4. Keep the existing migration files unchanged in Git. Import migrations 001_initial_schema.sql then 002_authentication_rate_limits.sql into the empty demo database.
5. Some shared hosts create the database for you and reject CREATE DATABASE or USE statements. If import fails for that reason, create a **separate deployment copy** of the migration with only the provider-incompatible database-selection statements removed; do not edit the canonical migration in the repository. Review the SQL diff before importing.
6. If any migration fails or the engine silently ignores constraints, stop. Do not continue with a partially created schema or real data.

### 2. Configure secrets privately

Set these PHP process/environment settings using the host's supported private configuration mechanism:

- DB_HOST
- DB_PORT
- DB_USER
- DB_PASSWORD
- DB_NAME
- AUTH_RATE_LIMIT_SECRET — use a cryptographically random value of at least 32 characters

Use different credentials and a different random secret for every environment. Do not commit .env files, database exports with real data, or credentials. Ensure PHP errors are logged privately and never display stack traces, SQL, paths, tokens, or credentials to visitors.

### 3. Upload the application with the correct paths

1. Deploy from a reviewed commit/branch, not an untracked working directory.
2. Preserve the relationship between web/public/ and web/api/ expected by the current app. Verify the actual URL paths for index.php and each /api/*.php endpoint after upload. Do not simply move the landing page into a new directory if that breaks API routes.
3. Ensure source/configuration files, tests, migrations, .git data, dependency files, logs, and private uploads cannot be downloaded as public files.
4. Keep uploaded PDFs in a separate private writable directory configured with EQUIPATH_PRIVATE_STORAGE_PATH. It must not be inside public_html, htdocs, or any other web-served directory. If the host cannot provide a private writable directory, **disable uploads and do not proceed with document workflows**.
5. Enforce HTTPS. Do not rely on a successful HTTP health response as evidence that the database is connected: the current health endpoint deliberately reports database: not_checked.

### 4. Run a synthetic-data smoke test

Use only fake accounts and fake, non-sensitive sample PDFs. Confirm each item and record pass/fail without recording secrets:

- [ ] HTTPS works; HTTP is redirected or otherwise prevented from carrying credentials.
- [ ] Landing page and static assets load without console errors.
- [ ] PHP API endpoints resolve at the expected URLs.
- [ ] Application can connect to the hosted database and both migrations were applied successfully.
- [ ] Sign-in, session rotation, CSRF checks, sign-out, and login throttling work in the hosted environment.
- [ ] Role restrictions and ownership checks deny unauthorized requests.
- [ ] Applicant draft/application queries work without exposing another account's data.
- [ ] PDF upload/download is disabled unless private storage, integrity checks, authorization, and cleanup behavior pass.
- [ ] Private files and configuration cannot be fetched directly over HTTP.
- [ ] Error responses do not reveal credentials, filesystem paths, SQL, or internal stack traces.
- [ ] A test export contains no applicant names, emails, document metadata, or storage identifiers.
- [ ] A backup can be restored to a separate disposable database (do not test restoration over the active database).

The hosted engine must be tested directly. Passing local XAMPP tests alone is not sufficient.

## Known gaps — do not misrepresent these as complete

The development guide identifies unfinished or partial workflows, including coordinator screening/assignment queues, rubric configuration, registrar integration, parts of evaluation/assessment/approver flows, and end-to-end authenticated multipart upload/rollback coverage. Malware scanning/content disarm, approved retention/deletion procedures, storage monitoring, and operational recovery also remain outstanding.

The current GET /api/health.php endpoint does not perform a database connection check. A 200 OK response is not proof of a working database.

## Rollback and cleanup

For a demo-only deployment, keep a record of the exact commit deployed and the migration versions applied. If a smoke test fails, put the demo offline, revoke/rotate the demo DB password and AUTH_RATE_LIMIT_SECRET, and remove test data and private files through the host's supported controls. Do not drop databases or delete files in any shared/production environment without explicit review and authorization.

## Alternative if shared hosting fails compatibility checks

A PHP container on a free application host plus a free MySQL-protocol-compatible managed database is a possible experiment, but it introduces additional deployment and SQL compatibility work. Free compute may sleep, storage may be ephemeral, quotas may apply, and external database traffic may be restricted. Do not switch the application to PostgreSQL or change the approved stack merely to fit a hosting provider.

## External references

- InfinityFree hosting feature claims and current terms: https://www.infinityfree.com/
- Render free-service limitations: https://render.com/docs/free
- TiDB Cloud Starter compatibility and limits: https://docs.pingcap.com/tidbcloud/dev-guide-sample-application-php/
- OWASP File Upload Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html

Re-check provider documentation immediately before deployment because free-tier limits and available features can change.
