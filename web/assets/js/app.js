(() => {
  "use strict";

  const csrfUrl = "../api/csrf.php";
  const loginUrl = "../api/authenticate.php";
  const logoutUrl = "../api/logout.php";
  const notificationsUrl = "../api/notifications.php";
  const markReadUrl = "../api/mark-notification-read.php";
  const evaluationAssignmentsUrl = "../api/evaluation-assignments.php";
  const evaluationRubricUrl = "../api/evaluation-rubric.php";
  const saveEvaluationUrl = "../api/save-evaluation.php";

  const byId = (id) => document.getElementById(id);
  const loginForm = byId("login-form");
  const loginButton = byId("login-button");
  const loginStatus = byId("login-status");
  const workspace = byId("workspace");
  const workspaceTitle = byId("workspace-title");
  const accountLabel = byId("account-label");
  const notificationList = byId("notification-list");
  const notificationStatus = byId("notification-status");
  const logoutButton = byId("logout-button");
  const refreshButton = byId("refresh-notifications");
  const evaluationWorkspace = byId("evaluation-workspace");
  const evaluationQueueStatus = byId("evaluation-queue-status");
  const evaluationAssignmentList = byId("evaluation-assignment-list");
  const refreshEvaluationsButton = byId("refresh-evaluations");
  const evaluationEditor = byId("evaluation-editor");
  const evaluationEditorTitle = byId("evaluation-editor-title");
  const evaluationEditorSubtitle = byId("evaluation-editor-subtitle");
  const evaluationCriteria = byId("evaluation-criteria");
  const evaluationForm = byId("evaluation-form");
  const evaluationStatus = byId("evaluation-status");
  const saveEvaluationButton = byId("save-evaluation");
  const submitEvaluationButton = byId("submit-evaluation");
  const cancelEvaluationButton = byId("cancel-evaluation");

  let csrfToken = null;
  let busy = false;
  let currentEvaluation = null;

  async function readJson(response) {
    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error("The server returned an unreadable response. Please try again.");
    }
    if (!response.ok || data?.ok !== true) {
      const messages = {
        authentication_required: "Your session has expired. Please sign in again.",
        invalid_credentials: "The email or password is incorrect.",
        login_temporarily_unavailable: "Sign-in is temporarily unavailable. Please wait and try again.",
        csrf_validation_failed: "Your security token expired. Refresh the page and try again.",
        access_denied: "This account is not authorized to perform that action.",
        service_unavailable: "The service is temporarily unavailable. Please try again later."
      };
      const error = new Error(messages[data?.error] || "The request could not be completed. Please try again.");
      error.code = data?.error;
      error.status = response.status;
      throw error;
    }
    return data;
  }

  async function refreshCsrfToken() {
    const response = await fetch(csrfUrl, {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    const data = await readJson(response);
    if (typeof data.csrf_token !== "string" || data.csrf_token.length < 32) {
      throw new Error("Could not establish a valid security token. Refresh the page and try again.");
    }
    csrfToken = data.csrf_token;
    return csrfToken;
  }

  function setStatus(element, message, kind = "") {
    element.textContent = message;
    element.dataset.kind = kind;
  }

  function setBusy(value) {
    busy = value;
    loginButton.disabled = value;
    logoutButton.disabled = value;
    refreshButton.disabled = value;
    refreshEvaluationsButton.disabled = value;
    saveEvaluationButton.disabled = value;
    submitEvaluationButton.disabled = value;
    loginButton.textContent = value && loginForm.checkValidity() ? "Please wait…" : "Sign in";
  }

  function formatDate(value) {
    if (typeof value !== "string") return "Date unavailable";
    // MySQL DATETIME values do not carry a timezone. Preserve their displayed
    // wall-clock value rather than silently assuming UTC or the browser zone.
    if (!/[zZ]|[+-]\\d{2}:?\\d{2}$/.test(value)) {
      return value.replace("T", " ").slice(0, 16);
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "Date unavailable";
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: "medium",
      timeStyle: "short"
    }).format(date);
  }

  function renderNotifications(items) {
    notificationList.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
      const empty = document.createElement("li");
      empty.className = "empty-state";
      empty.textContent = "You have no notifications yet.";
      notificationList.append(empty);
      return;
    }

    for (const item of items) {
      const row = document.createElement("li");
      row.className = "notification-item";
      if (item.read_at) row.classList.add("is-read");

      const content = document.createElement("div");
      content.className = "notification-content";

      const title = document.createElement("h4");
      title.className = "notification-title";
      title.textContent = typeof item.title === "string" ? item.title : "Notification";

      const message = document.createElement("p");
      message.className = "notification-message";
      message.textContent = typeof item.message === "string" ? item.message : "";

      const meta = document.createElement("p");
      meta.className = "notification-meta";
      meta.textContent = formatDate(item.created_at) + (item.read_at ? " · Read" : " · Unread");

      content.append(title, message, meta);
      row.append(content);

      if (!item.read_at && Number.isSafeInteger(item.notification_id) && item.notification_id > 0) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "button secondary compact";
        button.textContent = "Mark as read";
        button.setAttribute("aria-label", "Mark notification as read: " + (typeof item.title === "string" ? item.title : "Notification"));
        button.addEventListener("click", () => markNotificationRead(item.notification_id, button));
        row.append(button);
      }
      notificationList.append(row);
    }
  }

  async function loadNotifications() {
    refreshButton.disabled = true;
    setStatus(notificationStatus, "Loading notifications…");
    try {
      const response = await fetch(notificationsUrl, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      renderNotifications(data.notifications);
      const count = Array.isArray(data.notifications) ? data.notifications.length : 0;
      setStatus(notificationStatus, count === 1 ? "1 notification" : count + " notifications", "success");
    } catch (error) {
      setStatus(notificationStatus, error.message || "Could not load notifications.", "error");
      if (error.message && error.message.includes("session has expired")) {
        workspace.hidden = true;
        loginForm.hidden = false;
      }
    } finally {
      refreshButton.disabled = false;
    }
  }

  async function markNotificationRead(notificationId, button) {
    if (busy || !csrfToken) return;
    button.disabled = true;
    try {
      const response = await fetch(markReadUrl, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({ notification_id: notificationId })
      });
      await readJson(response);
      await loadNotifications();
    } catch (error) {
      setStatus(notificationStatus, error.message || "Could not update notification.", "error");
      button.disabled = false;
    }
  }

  function renderEvaluationAssignments(items) {
    evaluationAssignmentList.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
      const empty = document.createElement("li");
      empty.className = "empty-state";
      empty.textContent = "No active evaluation assignments are waiting for you.";
      evaluationAssignmentList.append(empty);
      return;
    }

    for (const item of items) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      const title = document.createElement("h4");
      title.className = "notification-title";
      title.textContent = typeof item.application_number === "string" ? item.application_number : "Application";
      const program = document.createElement("p");
      program.className = "notification-message";
      program.textContent = item.program && typeof item.program.name === "string" ? item.program.name : "Program unavailable";
      const meta = document.createElement("p");
      meta.className = "notification-meta";
      meta.textContent = "Assignment: " + String(item.assignment_status || "unknown");
      content.append(title, program, meta);
      row.append(content);

      if (item.assignment_status === "submitted") {
        const complete = document.createElement("span");
        complete.className = "assignment-state";
        complete.textContent = "Submitted";
        row.append(complete);
      } else if (item.active_rubric_count !== 1 || !Number.isSafeInteger(item.active_rubric_id)) {
        const warning = document.createElement("p");
        warning.className = "rubric-warning";
        warning.textContent = item.active_rubric_count === 0
          ? "No active rubric is configured for this program. Contact the coordinator."
          : "More than one active rubric is configured. Scoring is paused until the coordinator resolves it.";
        row.append(warning);
      } else if (Number.isSafeInteger(item.evaluator_assignment_id) && item.evaluator_assignment_id > 0) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "button secondary compact";
        button.textContent = item.assignment_status === "in_progress" ? "Continue evaluation" : "Open evaluation";
        button.addEventListener("click", () => openEvaluation(item.evaluator_assignment_id));
        row.append(button);
      }
      evaluationAssignmentList.append(row);
    }
  }

  async function loadEvaluationAssignments() {
    refreshEvaluationsButton.disabled = true;
    setStatus(evaluationQueueStatus, "Loading assignments…");
    try {
      const response = await fetch(evaluationAssignmentsUrl, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      evaluationWorkspace.hidden = false;
      renderEvaluationAssignments(data.assignments);
      const count = Array.isArray(data.assignments) ? data.assignments.length : 0;
      setStatus(evaluationQueueStatus, count === 1 ? "1 assignment" : count + " assignments", "success");
    } catch (error) {
      if (error.code === "access_denied") {
        evaluationWorkspace.hidden = true;
        return;
      }
      setStatus(evaluationQueueStatus, error.message || "Could not load evaluation assignments.", "error");
      if (error.code === "authentication_required") {
        workspace.hidden = true;
        loginForm.hidden = false;
      }
    } finally {
      refreshEvaluationsButton.disabled = false;
    }
  }

  async function openEvaluation(assignmentId) {
    currentEvaluation = null;
    evaluationEditor.hidden = false;
    evaluationCriteria.replaceChildren();
    setStatus(evaluationStatus, "Loading rubric…");
    try {
      const response = await fetch(evaluationRubricUrl + "?evaluator_assignment_id=" + encodeURIComponent(String(assignmentId)), {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      currentEvaluation = {
        assignmentId: data.assignment.evaluator_assignment_id,
        rubricId: data.rubric.rubric_id,
        criteria: data.criteria
      };
      evaluationEditorTitle.textContent = "Evaluate " + data.assignment.application_number;
      evaluationEditorSubtitle.textContent = data.assignment.program.name + " · " + data.rubric.rubric_name + " · Version " + data.rubric.version_number;
      evaluationCriteria.replaceChildren();

      for (const criterion of data.criteria) {
        const card = document.createElement("section");
        card.className = "criterion-card";
        const heading = document.createElement("h5");
        heading.textContent = criterion.criterion_name;
        const description = document.createElement("p");
        description.className = "criterion-description";
        description.textContent = criterion.description || criterion.criterion_code;
        const detail = document.createElement("p");
        detail.className = "notification-meta";
        detail.textContent = "Maximum score: " + criterion.max_score + " · Weight: " + criterion.weight;

        const scoreLabel = document.createElement("label");
        const scoreId = "criterion-score-" + criterion.criterion_id;
        scoreLabel.htmlFor = scoreId;
        scoreLabel.textContent = "Score";
        const scoreInput = document.createElement("input");
        scoreInput.id = scoreId;
        scoreInput.className = "criterion-score";
        scoreInput.type = "number";
        scoreInput.min = "0";
        scoreInput.max = String(criterion.max_score);
        scoreInput.step = "0.01";
        scoreInput.inputMode = "decimal";
        scoreInput.value = criterion.score === null ? "" : String(criterion.score);
        scoreInput.dataset.criterionId = String(criterion.criterion_id);
        scoreInput.setAttribute("aria-describedby", scoreId + "-hint");
        const hint = document.createElement("span");
        hint.id = scoreId + "-hint";
        hint.className = "field-hint";
        hint.textContent = "Enter a value from 0 to " + criterion.max_score + ".";

        const feedbackLabel = document.createElement("label");
        const feedbackId = "criterion-feedback-" + criterion.criterion_id;
        feedbackLabel.htmlFor = feedbackId;
        feedbackLabel.textContent = "Feedback (optional)";
        const feedbackInput = document.createElement("textarea");
        feedbackInput.id = feedbackId;
        feedbackInput.className = "criterion-feedback";
        feedbackInput.maxLength = 4000;
        feedbackInput.rows = 3;
        feedbackInput.value = typeof criterion.feedback === "string" ? criterion.feedback : "";

        card.append(heading, description, detail, scoreLabel, scoreInput, hint, feedbackLabel, feedbackInput);
        evaluationCriteria.append(card);
      }
      setStatus(evaluationStatus, "Rubric ready. Save a draft or submit when every criterion is scored.");
    } catch (error) {
      setStatus(evaluationStatus, error.message || "Could not load the rubric.", "error");
    }
  }

  function collectEvaluationScores(requireAll) {
    const scores = [];
    const inputs = Array.from(evaluationCriteria.querySelectorAll(".criterion-score"));
    for (const input of inputs) {
      const criterionId = Number(input.dataset.criterionId);
      const feedbackInput = byId("criterion-feedback-" + input.dataset.criterionId);
      const value = input.value.trim();
      const feedback = feedbackInput ? feedbackInput.value : "";
      if (value === "") {
        if (requireAll) {
          input.focus();
          throw new Error("Enter a score for every criterion before submitting.");
        }
        if (feedback.trim() !== "") {
          feedbackInput.focus();
          throw new Error("Enter a score before saving feedback for that criterion.");
        }
        continue;
      }
      const score = Number(value);
      const maximum = Number(input.max);
      if (!Number.isFinite(score) || score < 0 || score > maximum || !/^\d+(?:\.\d{1,2})?$/.test(value)) {
        input.focus();
        throw new Error("Each score must be between 0 and its maximum, with at most two decimal places.");
      }
      scores.push({ criterion_id: criterionId, score: value, feedback });
    }
    if (scores.length === 0) throw new Error("Enter at least one criterion score before saving.");
    return scores;
  }

  async function sendEvaluation(submit) {
    if (!currentEvaluation || !csrfToken) return;
    saveEvaluationButton.disabled = true;
    submitEvaluationButton.disabled = true;
    try {
      const scores = collectEvaluationScores(submit);
      setStatus(evaluationStatus, submit ? "Submitting evaluation…" : "Saving draft…");
      const response = await fetch(saveEvaluationUrl, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({
          evaluator_assignment_id: currentEvaluation.assignmentId,
          rubric_id: currentEvaluation.rubricId,
          scores,
          submit
        })
      });
      const data = await readJson(response);
      if (submit) {
        const percentage = data.evaluation.weighted_percentage;
        setStatus(evaluationStatus, "Evaluation submitted" + (typeof percentage === "number" ? ". Weighted result: " + percentage.toFixed(2) + "%" : "") + ". This is not an accreditation decision.", "success");
        currentEvaluation = null;
        evaluationEditor.hidden = true;
        await loadEvaluationAssignments();
      } else {
        const assignmentId = currentEvaluation.assignmentId;
        await openEvaluation(assignmentId);
        setStatus(evaluationStatus, "Draft saved. You can continue editing before submission.", "success");
      }
    } catch (error) {
      setStatus(evaluationStatus, error.message || "Could not save the evaluation.", "error");
    } finally {
      saveEvaluationButton.disabled = false;
      submitEvaluationButton.disabled = false;
    }
  }

  loginForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setStatus(loginStatus, "Signing in…");
    try {
      const token = csrfToken || await refreshCsrfToken();
      const response = await fetch(loginUrl, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": token
        },
        body: JSON.stringify({
          email: byId("email").value.trim(),
          password: byId("password").value
        })
      });
      const data = await readJson(response);
      const user = data.user || {};
      // Authentication rotates the session and clears its old CSRF token.
      // Fetch a fresh token before any subsequent state-changing request.
      csrfToken = null;
      await refreshCsrfToken();
      workspaceTitle.textContent = "Welcome, " + [user.given_name, user.family_name].filter(Boolean).join(" ");
      accountLabel.textContent = typeof user.email === "string" ? user.email : "";
      loginForm.hidden = true;
      loginStatus.textContent = "";
      workspace.hidden = false;
      byId("password").value = "";
      await loadNotifications();
      await loadEvaluationAssignments();
      document.dispatchEvent(new CustomEvent('equipath:authenticated'));
      workspace.focus?.();
    } catch (error) {
      setStatus(loginStatus, error.message || "Sign-in failed.", "error");
    } finally {
      setBusy(false);
    }
  });

  logoutButton.addEventListener("click", async () => {
    if (busy) return;
    setBusy(true);
    try {
      const response = await fetch(logoutUrl, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "X-CSRF-Token": csrfToken || ""
        }
      });
      await readJson(response);
      csrfToken = null;
      workspace.hidden = true;
      loginForm.hidden = false;
      notificationList.replaceChildren();
      evaluationWorkspace.hidden = true;
      evaluationEditor.hidden = true;
      evaluationAssignmentList.replaceChildren();
      evaluationCriteria.replaceChildren();
      currentEvaluation = null;
      setStatus(loginStatus, "You have signed out.", "success");
      document.dispatchEvent(new CustomEvent('equipath:signed-out'));
      await refreshCsrfToken();
    } catch (error) {
      setStatus(notificationStatus, error.message || "Sign-out failed.", "error");
    } finally {
      setBusy(false);
    }
  });

  refreshButton.addEventListener("click", loadNotifications);
  refreshEvaluationsButton.addEventListener("click", loadEvaluationAssignments);
  saveEvaluationButton.addEventListener("click", () => sendEvaluation(false));
  submitEvaluationButton.addEventListener("click", () => sendEvaluation(true));
  cancelEvaluationButton.addEventListener("click", () => {
    currentEvaluation = null;
    evaluationEditor.hidden = true;
    evaluationCriteria.replaceChildren();
  });

  refreshCsrfToken().catch((error) => {
    setStatus(loginStatus, error.message || "Sign-in is currently unavailable.", "error");
  });
})();
