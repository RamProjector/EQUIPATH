<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="EquiPath ETEEAP application and accreditation tracking portal for ADFC.">
  <meta name="theme-color" content="#f5f8fa">
  <title>EquiPath | ADFC ETEEAP</title>
  <link rel="stylesheet" href="../assets/css/app.css">
  <script src="../assets/js/app.js" defer></script>
  <script src="../assets/js/screening.js" defer></script>
  <script src="../assets/js/evaluator-assignments.js" defer></script>
  <script src="../assets/js/rubric-configuration.js" defer></script>
  <script src="../assets/js/reports.js" defer></script>
  <script src="../assets/js/applicant-applications.js" defer></script>
  <script src="../assets/js/assessments.js" defer></script>
  <script src="../assets/js/credit-recommendations.js" defer></script>
  <script src="../assets/js/accreditation.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#main-content">Skip to content</a>
  <main class="shell" id="main-content">
    <header class="topbar">
      <a class="brand" href="./" aria-label="EquiPath home">
        <span class="brand-mark" aria-hidden="true">E</span>
        <span>EquiPath</span>
      </a>
      <span class="environment">Development environment</span>
    </header>

    <section class="hero">
      <p class="eyebrow">Asian Development Foundation College · ETEEAP</p>
      <h1>Your experience.<br><span>Your next academic step.</span></h1>
      <p class="intro">A clearer path for applications, portfolio evidence, evaluation progress, assessment schedules, and authorized accreditation decisions.</p>
      <div class="actions">
        <a class="button primary" href="#sign-in">Sign in to EquiPath</a>
        <a class="button secondary" href="../api/health.php">Check API</a>
      </div>
    </section>

    <section id="sign-in" class="workspace-card" aria-labelledby="sign-in-title">
      <div class="section-heading">
        <p class="eyebrow">Secure access</p>
        <h2 id="sign-in-title">Sign in</h2>
        <p class="section-intro">Use an account provisioned by your institution. Public account registration is not available in this development build.</p>
      </div>
      <form id="login-form" class="login-form" autocomplete="on">
        <label for="email">Institutional email</label>
        <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" maxlength="4096" required>
        <button class="button primary" id="login-button" type="submit">Sign in</button>
      </form>
      <p id="login-status" class="status-message" role="status" aria-live="polite"></p>
    </section>

    <section id="workspace" class="workspace-card" aria-labelledby="workspace-title" hidden>
      <div class="workspace-heading">
        <div>
          <p class="eyebrow">Your workspace</p>
          <h2 id="workspace-title">Welcome</h2>
          <p id="account-label" class="section-intro"></p>
        </div>
        <button class="button secondary" id="logout-button" type="button">Sign out</button>
      </div>

      <section class="inbox" aria-labelledby="inbox-title">
        <div class="inbox-heading">
          <div>
            <h3 id="inbox-title">Notifications</h3>
            <p class="section-intro">Private updates for your signed-in account.</p>
          </div>
          <button id="refresh-notifications" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="notification-status" class="status-message" role="status" aria-live="polite"></p>
        <ul id="notification-list" class="notification-list" aria-label="Your notifications"></ul>
      </section>

      <section id="screening-workspace" class="inbox" aria-labelledby="screening-title" hidden>
        <div class="inbox-heading">
          <div>
            <h3 id="screening-title">Application screening</h3>
            <p class="section-intro">Review submitted applications and their evidence. Eligibility requires every active required document to be accepted.</p>
          </div>
          <button id="refresh-screening" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="screening-status" class="status-message" role="status" aria-live="polite"></p>
        <ul id="screening-candidate-list" class="notification-list" aria-label="Applications awaiting screening"></ul>
        <div id="screening-editor" class="evaluation-editor" hidden>
          <div class="inbox-heading">
            <div>
              <h4 id="screening-editor-title">Review application</h4>
              <p id="screening-editor-subtitle" class="section-intro"></p>
            </div>
            <button id="close-screening-editor" class="button secondary compact" type="button">Close</button>
          </div>
          <h5>Required evidence</h5>
          <label for="screening-document-notes">Document review reason</label>
          <textarea id="screening-document-notes" rows="3" maxlength="900" aria-describedby="screening-document-notes-help"></textarea>
          <p id="screening-document-notes-help" class="field-hint">A reason is required to reject a document or request a replacement. This note is shown to the applicant.</p>
          <ul id="screening-document-list" class="notification-list" aria-label="Application evidence and review actions"></ul>
          <form id="screening-decision-form" class="assessment-form">
            <label for="screening-outcome">Screening outcome</label>
            <select id="screening-outcome" required>
              <option value="eligible">Eligible to proceed</option>
              <option value="needs_correction">Return for corrections</option>
              <option value="ineligible">Ineligible</option>
            </select>
            <label for="screening-notes">Applicant-facing feedback (required for correction or ineligibility outcomes)</label>
            <textarea id="screening-notes" rows="4" maxlength="900"></textarea>
            <button id="submit-screening-decision" class="button primary" type="submit">Record screening outcome</button>
          </form>
        </div>
      </section>

      <section id="assignment-workspace" class="inbox" aria-labelledby="assignment-title" hidden>
        <div class="inbox-heading">
          <div>
            <h3 id="assignment-title">Evaluator assignments</h3>
            <p class="section-intro">Assign active evaluators to applications approved for evaluation. Duplicate assignments are rejected by the server.</p>
          </div>
          <button id="refresh-assignments" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="assignment-status" class="status-message" role="status" aria-live="polite"></p>
        <ul id="assignment-application-list" class="notification-list" aria-label="Applications available for evaluator assignment"></ul>
      </section>

      <section id="rubric-workspace" class="inbox" aria-labelledby="rubric-title" hidden>
        <div class="inbox-heading">
          <div>
            <h3 id="rubric-title">Evaluation rubric configuration</h3>
            <p class="section-intro">Create a new immutable rubric version for an active program. Existing versions and submitted scores are preserved.</p>
          </div>
          <button id="refresh-rubrics" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="rubric-status" class="status-message" role="status" aria-live="polite"></p>
        <label for="rubric-program">Program</label>
        <select id="rubric-program" required>
          <option value="">Choose an active program</option>
        </select>
        <h4>Rubric history</h4>
        <ul id="rubric-history-list" class="notification-list" aria-label="Rubric versions for the selected program"></ul>
        <div class="evaluation-editor">
          <h4>Create a new version</h4>
          <p class="field-hint">Rubric changes are blocked while an application for this program is under evaluation, preventing unfinished assignments from switching criteria.</p>
          <form id="rubric-form" class="assessment-form">
            <label for="rubric-name">Rubric name</label>
            <input id="rubric-name" type="text" maxlength="160" required>
            <div class="inbox-heading">
              <h5>Criteria</h5>
              <button id="add-rubric-criterion" class="button secondary compact" type="button">Add criterion</button>
            </div>
            <div id="rubric-criteria-editor" class="criteria-list"></div>
            <button id="save-rubric" class="button primary" type="submit">Create rubric version</button>
          </form>
        </div>
      </section>

      <section id="reports-workspace" class="inbox" aria-labelledby="reports-title" hidden>
        <div class="inbox-heading">
          <div>
            <h3 id="reports-title">Reports</h3>
            <p class="section-intro">Aggregate workflow activity for the selected period. Reports exclude applicant names, email addresses and document metadata.</p>
          </div>
          <a id="export-report" class="button secondary compact" href="../api/export-report.php" target="_blank" rel="noopener">Export CSV</a>
        </div>
        <form id="reports-filter-form" class="assessment-form">
          <label for="reports-from">From</label>
          <input id="reports-from" type="date" required>
          <label for="reports-to">To</label>
          <input id="reports-to" type="date" required>
          <p class="field-hint">Choose a range of up to 366 calendar days.</p>
          <button id="refresh-reports" class="button primary" type="submit">Refresh report</button>
        </form>
        <p id="reports-status" class="status-message" role="status" aria-live="polite"></p>
        <div id="reports-summary" class="report-summary" aria-label="Report summary"></div>
        <div id="reports-sections" class="report-sections"></div>
      </section>

      <section id="applicant-workspace" class="inbox" aria-labelledby="applicant-title" hidden>
        <div class="inbox-heading">
          <div>
            <h3 id="applicant-title">My applications</h3>
            <p class="section-intro">Start an application during an open period, track its status, and upload required PDF evidence.</p>
          </div>
          <button id="refresh-applications" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="applicant-status" class="status-message" role="status" aria-live="polite"></p>
        <ul id="applicant-application-list" class="notification-list" aria-label="Your applications"></ul>
        <div id="new-application-panel" class="evaluation-editor">
          <h4>Start an application</h4>
          <form id="new-application-form" class="assessment-form">
            <label for="new-application-program">Program</label>
            <select id="new-application-program" required>
              <option value="">Choose an active program</option>
            </select>
            <label for="new-application-period">Application period</label>
            <select id="new-application-period" required>
              <option value="">Choose an open period</option>
            </select>
            <p id="application-options-help" class="field-hint"></p>
            <button id="create-application-button" class="button primary" type="submit">Create draft</button>
          </form>
        </div>
        <div id="applicant-document-panel" class="evaluation-editor" hidden>
          <div class="inbox-heading">
            <div>
              <h4 id="applicant-document-title">Application documents</h4>
              <p id="applicant-document-subtitle" class="section-intro"></p>
            </div>
            <button id="close-applicant-documents" class="button secondary compact" type="button">Close</button>
          </div>
          <ul id="applicant-document-list" class="notification-list" aria-label="Uploaded application documents"></ul>
          <div class="evaluation-actions">
            <button id="submit-application-button" class="button primary" type="button">Submit application for screening</button>
          </div>
          <p id="submit-application-help" class="field-hint">Submission is available only after every required document has a current pending or accepted version. The server checks completeness again when you submit.</p>
          <form id="applicant-upload-form" class="assessment-form" enctype="multipart/form-data">
            <label for="applicant-document-type">Document requirement</label>
            <select id="applicant-document-type" required>
              <option value="">Choose a requirement</option>
            </select>
            <label for="applicant-document-file">PDF evidence (maximum 10 MiB, or lower if the requirement sets a limit)</label>
            <input id="applicant-document-file" type="file" accept="application/pdf,.pdf" required>
            <button id="applicant-upload-button" class="button primary" type="submit">Upload PDF</button>
            <p id="applicant-upload-help" class="field-hint">Only PDF is accepted. Uploaded documents are private; uploads are not automatically malware-scanned.</p>
          </form>
        </div>
      </section>

      <section id="evaluation-workspace" class="inbox" aria-labelledby="evaluation-title" hidden>
        <div class="inbox-heading">
          <div>
            <h3 id="evaluation-title">Evaluation assignments</h3>
            <p class="section-intro">Score assigned portfolios against the active program rubric.</p>
          </div>
          <button id="refresh-evaluations" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="evaluation-queue-status" class="status-message" role="status" aria-live="polite"></p>
        <ul id="evaluation-assignment-list" class="notification-list" aria-label="Assigned evaluations"></ul>

        <div id="evaluation-editor" class="evaluation-editor" hidden>
          <div class="inbox-heading">
            <div>
              <h4 id="evaluation-editor-title">Evaluation rubric</h4>
              <p id="evaluation-editor-subtitle" class="section-intro"></p>
            </div>
            <button id="cancel-evaluation" class="button secondary compact" type="button">Close</button>
          </div>
          <p class="section-intro">Enter a score for each criterion. Drafts can be edited; submission locks the evaluation and notifies the coordinator. A score is not an accreditation decision.</p>
          <form id="evaluation-form">
            <div id="evaluation-criteria" class="criteria-list"></div>
            <div class="evaluation-actions">
              <button id="save-evaluation" class="button secondary" type="button">Save draft</button>
              <button id="submit-evaluation" class="button primary" type="button">Submit evaluation</button>
            </div>
            <p id="evaluation-status" class="status-message" role="status" aria-live="polite"></p>
          </form>
        </div>

        <div id="credit-recommendations-editor" class="evaluation-editor" hidden>
          <div class="inbox-heading">
            <div>
              <h4 id="credit-recommendations-title">Credit recommendations</h4>
              <p id="credit-recommendations-subtitle" class="section-intro"></p>
            </div>
            <button id="close-credit-recommendations" class="button secondary compact" type="button">Close</button>
          </div>
          <p class="section-intro">These are evaluator recommendations for review by authorized college personnel. They are not approved credits or a final accreditation decision.</p>
          <ul id="credit-recommendations-list" class="notification-list" aria-label="Submitted credit recommendations"></ul>
          <p id="credit-recommendations-status" class="status-message" role="status" aria-live="polite"></p>
          <form id="credit-recommendations-form" class="assessment-form">
            <div id="credit-recommendation-fields" class="criteria-list"></div>
            <div class="evaluation-actions">
              <button id="add-credit-recommendation" class="button secondary" type="button">Add course</button>
              <button id="submit-credit-recommendations" class="button primary" type="submit">Submit recommendations</button>
            </div>
          </form>
        </div>
      </section>

      <section id="assessment-workspace" class="inbox" aria-labelledby="assessment-title" hidden>
        <div class="inbox-heading">
          <div>
            <h3 id="assessment-title">Assessments</h3>
            <p class="section-intro">View schedules and recorded results for applications you are authorized to access.</p>
          </div>
          <button id="refresh-assessments" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="assessment-status" class="status-message" role="status" aria-live="polite"></p>
        <ul id="assessment-list" class="notification-list" aria-label="Assessment schedule and results"></ul>

        <div id="assessment-manager" class="evaluation-editor" hidden>
          <h4>Schedule an assessment</h4>
          <p class="section-intro">Assessment scheduling requires every active evaluator assignment to be submitted. Additional events may be scheduled after the first event is created.</p>
          <form id="assessment-form" class="assessment-form">
            <label for="assessment-application">Application</label>
            <select id="assessment-application" required>
              <option value="">Choose an eligible application</option>
            </select>
            <label for="assessment-type">Assessment type</label>
            <select id="assessment-type" required>
              <option value="interview">Interview</option>
              <option value="examination">Examination</option>
              <option value="demonstration">Demonstration</option>
              <option value="other">Other</option>
            </select>
            <label for="assessment-starts-at">Starts at</label>
            <input id="assessment-starts-at" type="datetime-local" required>
            <label for="assessment-ends-at">Ends at</label>
            <input id="assessment-ends-at" type="datetime-local" required>
            <label for="assessment-location">Location or meeting link (optional)</label>
            <input id="assessment-location" type="text" maxlength="500">
            <label for="assessment-notes">Staff notes (optional)</label>
            <textarea id="assessment-notes" rows="3" maxlength="4000"></textarea>
            <button id="schedule-assessment" class="button primary" type="submit">Schedule assessment</button>
          </form>
        </div>

        <div id="assessment-result-editor" class="evaluation-editor" hidden>
          <div class="inbox-heading">
            <h4 id="assessment-result-title">Record assessment result</h4>
            <button id="cancel-assessment-result" class="button secondary compact" type="button">Close</button>
          </div>
          <form id="assessment-result-form" class="assessment-form">
            <input id="assessment-result-event-id" type="hidden">
            <label for="assessment-outcome">Outcome</label>
            <select id="assessment-outcome" required>
              <option value="passed">Passed</option>
              <option value="failed">Failed</option>
              <option value="incomplete">Incomplete</option>
              <option value="excused">Excused</option>
            </select>
            <label for="assessment-score">Score (optional)</label>
            <input id="assessment-score" type="number" min="0" max="99999.99" step="0.01">
            <label for="assessment-feedback">Feedback (optional)</label>
            <textarea id="assessment-feedback" rows="3" maxlength="4000"></textarea>
            <button id="save-assessment-result" class="button primary" type="submit">Record result</button>
          </form>
        </div>
      </section>

      <section id="accreditation-workspace" class="inbox" aria-labelledby="accreditation-title" hidden>
        <div class="inbox-heading">
          <div>
            <h3 id="accreditation-title">Accreditation decisions</h3>
            <p class="section-intro">Authorized approvers record official decisions separately from evaluator recommendations.</p>
          </div>
          <button id="refresh-accreditation" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="accreditation-status" class="status-message" role="status" aria-live="polite"></p>
        <label for="accreditation-application">Application awaiting decision or eligible for an explicit revision</label>
        <select id="accreditation-application">
          <option value="">Choose an application</option>
        </select>
        <div id="accreditation-editor" class="evaluation-editor" hidden>
          <h4 id="accreditation-editor-title">Decision details</h4>
          <p id="accreditation-editor-subtitle" class="section-intro"></p>
          <h5>Credit recommendations for review</h5>
          <ul id="accreditation-recommendation-list" class="notification-list" aria-label="Evaluator credit recommendations"></ul>
          <h5>Decision history</h5>
          <ul id="accreditation-history-list" class="notification-list" aria-label="Accreditation decision history"></ul>
          <form id="accreditation-decision-form" class="assessment-form" hidden>
            <label for="accreditation-decision">Decision</label>
            <select id="accreditation-decision" required>
              <option value="approved">Approve</option>
              <option value="rejected">Reject</option>
              <option value="deferred">Defer for further review</option>
              <option value="returned_for_revision">Return for revision</option>
            </select>
            <div id="supersede-decision-container" hidden>
              <label class="checkbox-label" for="supersede-decision">
                <input id="supersede-decision" type="checkbox">
                I confirm this is an explicit superseding decision replacing the current decision.
              </label>
            </div>
            <div id="approved-credit-options" class="criteria-list" hidden></div>
            <label for="accreditation-rationale">Decision rationale</label>
            <textarea id="accreditation-rationale" rows="4" maxlength="4000" required></textarea>
            <button id="submit-accreditation-decision" class="button primary" type="submit">Record decision</button>
          </form>
        </div>
      </section>
    </section>

    <section id="workflow" class="workflow" aria-labelledby="workflow-title">
      <div class="section-heading">
        <p class="eyebrow">The process</p>
        <h2 id="workflow-title">One process, clear progress.</h2>
      </div>
      <div class="cards">
        <article class="card"><span class="step">01</span><h3>Apply</h3><p>Prepare an application and submit supporting evidence.</p></article>
        <article class="card"><span class="step">02</span><h3>Screen</h3><p>Authorized staff check completeness and request corrections.</p></article>
        <article class="card"><span class="step">03</span><h3>Evaluate</h3><p>Assigned evaluators review evidence against approved criteria.</p></article>
        <article class="card"><span class="step">04</span><h3>Decide</h3><p>Authorized personnel record official recommendations and decisions.</p></article>
      </div>
    </section>

    <footer>
      <span>EquiPath · ADFC</span>
      <span>Development build — do not submit real applicant records.</span>
    </footer>
  </main>
</body>
</html>
