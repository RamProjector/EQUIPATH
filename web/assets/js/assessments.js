(() => {
  "use strict";

  const endpoints = {
    csrf: "../api/csrf.php",
    list: "../api/assessments.php",
    candidates: "../api/assessment-eligible-applications.php",
    schedule: "../api/create-assessment.php",
    result: "../api/record-assessment-result.php"
  };
  const byId = (id) => document.getElementById(id);
  const workspace = byId("assessment-workspace");
  const status = byId("assessment-status");
  const list = byId("assessment-list");
  const refreshButton = byId("refresh-assessments");
  const manager = byId("assessment-manager");
  const scheduleForm = byId("assessment-form");
  const applicationSelect = byId("assessment-application");
  const scheduleButton = byId("schedule-assessment");
  const candidateStatus = byId("assessment-candidate-status");
  const resultEditor = byId("assessment-result-editor");
  const resultTitle = byId("assessment-result-title");
  const resultForm = byId("assessment-result-form");
  const resultEventId = byId("assessment-result-event-id");
  const saveResultButton = byId("save-assessment-result");
  const cancelResultButton = byId("cancel-assessment-result");

  let csrfToken = null;
  let signedIn = false;
  let requestInProgress = false;

  function setStatus(element, message, kind = "") {
    element.textContent = message;
    element.dataset.kind = kind;
  }

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
        access_denied: "This account is not authorized to manage assessments.",
        csrf_validation_failed: "Your security token expired. Sign in again or refresh the page.",
        assessment_not_allowed: "This application cannot be scheduled in its current state.",
        assessment_result_not_allowed: "The result cannot be recorded yet. Confirm the assessment has ended and has no prior result.",
        invalid_assessment_details: "Check the application, assessment type, and start/end times.",
        invalid_assessment_result: "Check the outcome, score, and feedback.",
        service_unavailable: "The service is temporarily unavailable. Please try again later."
      };
      const error = new Error(messages[data?.error] || "The request could not be completed. Please try again.");
      error.code = data?.error;
      error.status = response.status;
      throw error;
    }
    return data;
  }

  async function refreshCsrf() {
    const response = await fetch(endpoints.csrf, {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    const data = await readJson(response);
    if (typeof data.csrf_token !== "string" || data.csrf_token.length < 32) {
      throw new Error("Could not establish a valid security token.");
    }
    csrfToken = data.csrf_token;
  }

  function formatDate(value) {
    if (typeof value !== "string") return "Date unavailable";
    // DATETIME values have no timezone metadata, so preserve their stored
    // wall-clock representation instead of inventing a timezone conversion.
    return value.replace("T", " ").slice(0, 16);
  }

  function addText(parent, tag, className, value) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = value;
    parent.append(element);
    return element;
  }

  function renderAssessments(items, canManage) {
    list.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
      addText(list, "li", "empty-state", "No assessments are available for your account.");
      return;
    }

    for (const item of items) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title",
        (item.application_number || "Application") + " · " + (item.assessment_type || "Assessment"));
      addText(content, "p", "notification-message",
        item.program && typeof item.program.name === "string" ? item.program.name : "Program unavailable");
      addText(content, "p", "notification-meta",
        formatDate(item.starts_at) + " – " + formatDate(item.ends_at) + " · " + String(item.event_status || "unknown"));

      if (typeof item.location_or_link === "string" && item.location_or_link !== "") {
        addText(content, "p", "notification-message", "Location / link: " + item.location_or_link);
      }
      if (typeof item.notes === "string" && item.notes !== "") {
        addText(content, "p", "notification-message", "Staff notes: " + item.notes);
      }
      if (item.result) {
        const scorePart = item.result.score === null ? "" : " · Score: " + item.result.score;
        addText(content, "p", "assessment-result-summary", "Result: " + item.result.outcome + scorePart);
        if (typeof item.result.feedback === "string" && item.result.feedback !== "") {
          addText(content, "p", "notification-message", "Feedback: " + item.result.feedback);
        }
      }
      row.append(content);

      if (canManage && item.event_status === "scheduled" && Number.isSafeInteger(item.assessment_event_id)) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "button secondary compact";
        button.textContent = "Record result";
        button.addEventListener("click", () => openResultEditor(item));
        row.append(button);
      } else if (item.event_status === "completed") {
        addText(row, "span", "assignment-state", "Completed");
      }
      list.append(row);
    }
  }

  async function loadCandidates() {
    if (manager.hidden || !signedIn) return;
    setStatus(candidateStatus, "Loading eligible applications…");
    scheduleButton.disabled = true;
    try {
      const response = await fetch(endpoints.candidates, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      applicationSelect.replaceChildren();
      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = data.applications.length ? "Choose an eligible application" : "No eligible applications available";
      applicationSelect.append(placeholder);
      for (const application of data.applications) {
        const option = document.createElement("option");
        option.value = String(application.application_id);
        option.textContent = application.application_number + " · " + application.program.name + " · " + application.application_status;
        applicationSelect.append(option);
      }
      scheduleButton.disabled = data.applications.length === 0;
      setStatus(candidateStatus, data.applications.length
        ? data.applications.length + " applications available for scheduling."
        : "No applications currently meet the scheduling rules.", data.applications.length ? "success" : "");
    } catch (error) {
      setStatus(candidateStatus, error.message || "Could not load assessment candidates.", "error");
      scheduleButton.disabled = true;
    }
  }

  async function loadAssessments() {
    if (!signedIn) return;
    refreshButton.disabled = true;
    setStatus(status, "Loading assessments…");
    try {
      const response = await fetch(endpoints.list, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      workspace.hidden = false;
      manager.hidden = !data.can_manage;
      if (!data.can_manage) resultEditor.hidden = true;
      renderAssessments(data.assessments, data.can_manage === true);
      const count = Array.isArray(data.assessments) ? data.assessments.length : 0;
      setStatus(status, count === 1 ? "1 assessment" : count + " assessments", "success");
      if (data.can_manage) await loadCandidates();
    } catch (error) {
      if (error.code === "access_denied") {
        workspace.hidden = true;
        return;
      }
      setStatus(status, error.message || "Could not load assessments.", "error");
      if (error.code === "authentication_required") {
        signedIn = false;
        workspace.hidden = true;
      }
    } finally {
      refreshButton.disabled = false;
    }
  }

  function openResultEditor(item) {
    resultEventId.value = String(item.assessment_event_id);
    resultTitle.textContent = "Record result · " + item.application_number + " · " + item.assessment_type;
    byId("assessment-outcome").value = "passed";
    byId("assessment-score").value = "";
    byId("assessment-feedback").value = "";
    resultEditor.hidden = false;
    setStatus(status, "Results can only be recorded after the scheduled end time.");
    resultEditor.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  scheduleForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!signedIn || requestInProgress || !csrfToken) return;
    requestInProgress = true;
    scheduleButton.disabled = true;
    setStatus(status, "Scheduling assessment…");
    try {
      const startsAt = byId("assessment-starts-at").value;
      const endsAt = byId("assessment-ends-at").value;
      if (!applicationSelect.value || !startsAt || !endsAt) {
        throw new Error("Choose an application and both assessment times.");
      }
      if (new Date(endsAt) <= new Date(startsAt)) {
        throw new Error("The end time must be after the start time.");
      }
      const response = await fetch(endpoints.schedule, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({
          application_id: Number(applicationSelect.value),
          assessment_type: byId("assessment-type").value,
          starts_at: startsAt,
          ends_at: endsAt,
          location_or_link: byId("assessment-location").value.trim(),
          notes: byId("assessment-notes").value.trim()
        })
      });
      await readJson(response);
      scheduleForm.reset();
      await loadAssessments();
      setStatus(status, "Assessment scheduled and applicant notified.", "success");
    } catch (error) {
      setStatus(status, error.message || "Could not schedule assessment.", "error");
    } finally {
      requestInProgress = false;
      scheduleButton.disabled = !applicationSelect.value;
    }
  });

  resultForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!signedIn || requestInProgress || !csrfToken) return;
    requestInProgress = true;
    saveResultButton.disabled = true;
    setStatus(status, "Recording result…");
    try {
      const scoreText = byId("assessment-score").value.trim();
      const score = scoreText === "" ? null : scoreText;
      if (score !== null && (!/^\d+(?:\.\d{1,2})?$/.test(score) || Number(score) < 0 || Number(score) > 99999.99)) {
        throw new Error("Score must be non-negative with at most two decimal places.");
      }
      const response = await fetch(endpoints.result, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({
          assessment_event_id: Number(resultEventId.value),
          outcome: byId("assessment-outcome").value,
          score,
          feedback: byId("assessment-feedback").value.trim()
        })
      });
      await readJson(response);
      resultEditor.hidden = true;
      resultForm.reset();
      await loadAssessments();
      setStatus(status, "Assessment result recorded. The applicant has been notified.", "success");
    } catch (error) {
      setStatus(status, error.message || "Could not record assessment result.", "error");
    } finally {
      requestInProgress = false;
      saveResultButton.disabled = false;
    }
  });

  cancelResultButton.addEventListener("click", () => {
    resultEditor.hidden = true;
    resultForm.reset();
  });
  refreshButton.addEventListener("click", loadAssessments);
  applicationSelect.addEventListener("change", () => {
    scheduleButton.disabled = !applicationSelect.value || requestInProgress;
  });

  document.addEventListener("equipath:authenticated", async () => {
    signedIn = true;
    try {
      await refreshCsrf();
      await loadAssessments();
    } catch (error) {
      setStatus(status, error.message || "Could not initialize assessment workspace.", "error");
    }
  });

  document.addEventListener("equipath:signed-out", () => {
    signedIn = false;
    csrfToken = null;
    workspace.hidden = true;
    manager.hidden = true;
    resultEditor.hidden = true;
    list.replaceChildren();
    applicationSelect.replaceChildren();
    resultForm.reset();
    scheduleForm.reset();
  });
})();
