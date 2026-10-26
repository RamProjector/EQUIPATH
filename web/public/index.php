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
          <p class="section-intro">Only eligible applications, or applications whose assigned evaluations are all submitted, can be scheduled.</p>
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
