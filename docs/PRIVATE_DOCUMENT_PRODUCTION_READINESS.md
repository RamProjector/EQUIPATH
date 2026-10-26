# Private Portfolio Document Production Readiness

**Status: not production-ready until the deployment owner closes the applicable gates below.** This checklist describes required operational and application controls; it does not claim that those controls are implemented.

The current upload service accepts PDF files, checks MIME/signature and size, stores them outside the public web root, assigns a random storage key, and records a SHA-256 digest. Those controls reduce risk but do not prove a PDF is harmless. A PDF can contain malicious content even when its MIME type and header look correct.

## Release gates

### 1. Malware inspection and safe review

- [ ] ADFC/security owner selects and approves a malware scanning approach suitable for the deployment environment.
- [ ] Uploaded files are kept in a private quarantine state until scanning completes successfully.
- [ ] Scanner unavailable, timeout, malformed response, or scan error must fail closed: the file must not be made available to evaluators or other users.
- [ ] Record a bounded scan result, scanner/version, timestamp, and correlation identifier. Do not put document contents or secrets in logs.
- [ ] Define a safe procedure for rejected files, rescanning, and incident handling.
- [ ] Consider content disarm/reconstruction only if the approved tool supports PDFs and the institution accepts its effects on evidence fidelity.

The current schema does not yet provide a complete scan/quarantine workflow. Do not mark this gate complete merely because a command-line scanner is installed on a server.

### 2. Storage and upload limits

- [ ] Set `EQUIPATH_PRIVATE_STORAGE_PATH` to a dedicated writable directory outside the web root; verify the resolved path at deployment.
- [ ] Use a dedicated operating-system identity with least-privilege access. Do not make the directory web-readable or executable.
- [ ] Confirm storage capacity monitoring and alerts exist; plan for exhaustion and interrupted uploads.
- [ ] Set `EQUIPATH_MAX_UPLOAD_BYTES` to an institution-approved value at or below the code's 10 MiB default.
- [ ] Configure PHP/web-server request limits consistently with the application limit and test the actual deployed values.
- [ ] Test that temporary uploads, rejected files, and failed transactions do not leave abandoned files.
- [ ] Restrict direct filesystem access, encrypt disks/backups where supported, and verify backup access permissions.

### 3. Authorization and integrity

- [ ] Keep the storage directory private; all downloads must go through the authenticated API.
- [ ] Test owner-only applicant access and current evaluator-assignment access; do not grant administrator access implicitly.
- [ ] Verify storage key, file size, PDF signature/MIME, and digest on download.
- [ ] Keep CSRF protection on state-changing browser requests and validate access against current database roles.
- [ ] Run the automated upload/download integration tests on every change to these endpoints.
- [ ] Perform an independent security review before real applicant documents are accepted.

### 4. Retention, deletion, and legal approval

- [ ] ADFC's records/privacy owner approves retention periods for applications, submitted evidence, superseded versions, audit events, and backups.
- [ ] Document the legal/academic hold process before deleting records.
- [ ] Define how a document deletion request affects the database row, private file, backups, and audit evidence.
- [ ] Implement a scheduled, auditable cleanup process only after the retention schedule and hold rules are approved.
- [ ] Test restoration of both database metadata and matching private document files.
- [ ] Do not invent a retention duration in code before ADFC confirms its records-management and legal requirements.

### 5. Audit logging and incident response

- [ ] Restrict audit-log database permissions and access to authorized staff.
- [ ] Log successful and denied sensitive document access without storing document bytes, session tokens, passwords, or unnecessary personal data.
- [ ] Establish log retention and tamper-monitoring rules with the records/security owner.
- [ ] Define incident contacts and response steps for suspected malware, unauthorized access, missing files, and storage compromise.
- [ ] Verify monitoring catches storage failures and that audit logging is reviewed.

## Verification evidence to retain for release

For each completed gate, record the reviewer, date, environment, test result, and relevant ticket or change reference. Use synthetic test documents and accounts; never place real applicant evidence in CI fixtures.

## References

- OWASP, [File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html): allow-listing, content validation, generated filenames, size limits, authorization, and least-privilege storage.
- OWASP, [Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html): security-event coverage, protecting logs, avoiding sensitive data, and retaining logs only for the required period.

These references are general security guidance. ADFC must approve the applicable operational, privacy, and records-management decisions.
