(() => {
  "use strict";

  const endpoints = {
    csrf: "../api/csrf.php",
    candidates: "../api/accreditation-candidates.php",
    decisions: "../api/accreditation-decisions.php",
    recommendations: "../api/application-credit-recommendations.php",
    submit: "../api/record-accreditation-decision.php"
  };
  const byId = (id) => document.getElementById(id);
  const workspace = byId("accreditation-workspace");
  const status = byId("accreditation-status");
  const refreshButton = byId("refresh-accreditation");
  const applicationSelect = byId("accreditation-application");
  const editor = byId("accreditation-editor");
  const editorTitle = byId("accreditation-editor-title");
  const editorSubtitle = byId("accreditation-editor-subtitle");
  const recommendationList = byId("accreditation-recommendation-list");
  const historyList = byId("accreditation-history-list");
  const decisionForm = byId("accreditation-decision-form");
  const decisionSelect = byId("accreditation-decision");
  const supersedeContainer = byId("supersede-decision-container");
  const supersedeCheckbox = byId("supersede-decision");
  const creditOptions = byId("approved-credit-options");
  const rationaleInput = byId("accreditation-rationale");
  const submitButton = byId("submit-accreditation-decision");

  let csrfToken = null;
  let signedIn = false;
  let busy = false;
  let currentApplication = null;
  let currentDecisionId = null;
  let availableRecommendations = [];

  function setStatus(message, kind = "") {
    status.textContent = message;
    status.dataset.kind = kind;
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
        access_denied: "This account is not authorized to record accreditation decisions.",
        csrf_validation_failed: "Your security token expired. Sign in again or refresh the page.",
        invalid_accreditation_decision: "Check the decision, rationale and approved-credit entries.",
        accreditation_decision_not_allowed: "The application state, current decision, or approved-credit recommendations do not permit this action.",
        decision_history_inconsistent: "The decision history has multiple current decisions. Stop and ask an authorized administrator to reconcile the records.",
        application_not_found: "The application could not be found.",
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

  function addText(parent, tag, className, value) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = value;
    parent.append(element);
    return element;
  }

  async function loadCandidates(selectedApplicationId = null) {
    if (!signedIn) return;
    refreshButton.disabled = true;
    setStatus("Loading applications awaiting decision…");
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
      placeholder.textContent = data.applications.length ? "Choose an application" : "No applications awaiting decision";
      applicationSelect.append(placeholder);
      for (const application of data.applications) {
        const option = document.createElement("option");
        option.value = String(application.application_id);
        const conflict = application.current_decision_count > 1 ? " · DECISION HISTORY CONFLICT" : "";
        option.textContent = application.application_number + " · " + application.application_status + conflict;
        applicationSelect.append(option);
      }
      workspace.hidden = false;
      if (selectedApplicationId !== null &&
          Array.from(applicationSelect.options).some(option => option.value === String(selectedApplicationId))) {
        applicationSelect.value = String(selectedApplicationId);
      } else {
        applicationSelect.value = "";
      }
      setStatus(data.applications.length
        ? data.applications.length + " applications available for decision or explicit supersession."
        : "No applications are currently available for accreditation decisions.", data.applications.length ? "success" : "");
    } catch (error) {
      if (error.code === "access_denied") {
        workspace.hidden = true;
        return;
      }
      setStatus(error.message || "Could not load accreditation candidates.", "error");
      if (error.code === "authentication_required") {
        signedIn = false;
        workspace.hidden = true;
      }
    } finally {
      refreshButton.disabled = false;
    }
  }

  function renderRecommendations(items) {
    recommendationList.replaceChildren();
    availableRecommendations = Array.isArray(items) ? items : [];
    if (availableRecommendations.length === 0) {
      addText(recommendationList, "li", "empty-state", "No evaluator credit recommendations have been submitted for this application.");
      return;
    }
    for (const item of availableRecommendations) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title", item.course_code + " · " + item.course_title);
      addText(content, "p", "notification-meta", "Recommended credits: " + item.recommended_credits + " · Assignment #" + item.evaluator_assignment_id);
      addText(content, "p", "notification-message", item.rationale);
      row.append(content);
      recommendationList.append(row);
    }
  }

  function renderDecisionHistory(decisions) {
    historyList.replaceChildren();
    if (!Array.isArray(decisions) || decisions.length === 0) {
      addText(historyList, "li", "empty-state", "No decision has been recorded for this application yet.");
      return;
    }
    for (const decision of decisions) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title",
        decision.decision.replaceAll("_", " ") + (decision.is_current ? " · Current" : " · Superseded"));
      addText(content, "p", "notification-meta", "Decision #" + decision.accreditation_decision_id + " · " + decision.decided_at);
      addText(content, "p", "notification-message", decision.rationale);
      if (decision.supersedes_decision_id) {
        addText(content, "p", "notification-meta", "Supersedes decision #" + decision.supersedes_decision_id);
      }
      if (Array.isArray(decision.approved_credits) && decision.approved_credits.length > 0) {
        const credits = document.createElement("ul");
        credits.className = "approved-credit-history";
        for (const credit of decision.approved_credits) {
          addText(credits, "li", "", credit.course_code + " · " + credit.course_title + " — " + credit.approved_credits + " credits");
        }
        content.append(credits);
      }
      row.append(content);
      historyList.append(row);
    }
  }

  function renderApprovedCreditOptions(items) {
    creditOptions.replaceChildren();
    const grouped = new Map();
    for (const item of items) {
      const code = String(item.course_code || "").toUpperCase();
      const title = String(item.course_title || "");
      if (!code || !title) continue;
      if (!grouped.has(code)) grouped.set(code, new Map());
      const titles = grouped.get(code);
      const amount = Number(item.recommended_credits);
      titles.set(title, Math.max(titles.get(title) || 0, Number.isFinite(amount) ? amount : 0));
    }

    for (const [code, titles] of grouped.entries()) {
      const card = document.createElement("fieldset");
      card.className = "criterion-card approved-credit-option";
      card.dataset.courseCode = code;
      if (titles.size !== 1) {
        addText(card, "p", "rubric-warning", "Conflicting course titles were recommended for " + code + ". Resolve the recommendation conflict before approving this course.");
        creditOptions.append(card);
        continue;
      }
      const [courseTitle, maximum] = Array.from(titles.entries())[0];
      card.dataset.courseTitle = courseTitle;
      card.dataset.maximum = String(maximum);
      const checkboxLabel = document.createElement("label");
      checkboxLabel.className = "checkbox-label";
      const checkbox = document.createElement("input");
      checkbox.type = "checkbox";
      checkbox.className = "approved-credit-check";
      checkbox.setAttribute("aria-label", "Approve " + code + " " + courseTitle);
      checkboxLabel.append(checkbox);
      const labelText = document.createElement("span");
      labelText.textContent = code + " · " + courseTitle + " (maximum recommended: " + maximum.toFixed(2) + ")";
      checkboxLabel.append(labelText);

      const amountLabel = document.createElement("label");
      const amountId = "approved-credit-amount-" + code.replace(/[^A-Z0-9_-]/g, "-");
      amountLabel.htmlFor = amountId;
      amountLabel.textContent = "Approved credits";
      const amountInput = document.createElement("input");
      amountInput.id = amountId;
      amountInput.type = "number";
      amountInput.min = "0";
      amountInput.max = String(maximum);
      amountInput.step = "0.01";
      amountInput.className = "approved-credit-amount";
      amountInput.disabled = true;
      amountInput.required = false;
      checkbox.addEventListener("change", () => {
        amountInput.disabled = !checkbox.checked;
        amountInput.required = checkbox.checked;
        if (!checkbox.checked) amountInput.value = "";
      });
      card.append(checkboxLabel, amountLabel, amountInput);
      creditOptions.append(card);
    }
  }

  async function loadApplication(applicationId) {
    currentApplication = null;
    currentDecisionId = null;
    editor.hidden = true;
    decisionForm.hidden = true;
    setStatus("Loading decision history and recommendations…");
    try {
      const [decisionResponse, recommendationResponse] = await Promise.all([
        fetch(endpoints.decisions + "?application_id=" + encodeURIComponent(String(applicationId)), {
          method: "GET",
          credentials: "same-origin",
          cache: "no-store",
          headers: { "Accept": "application/json" }
        }),
        fetch(endpoints.recommendations + "?application_id=" + encodeURIComponent(String(applicationId)), {
          method: "GET",
          credentials: "same-origin",
          cache: "no-store",
          headers: { "Accept": "application/json" }
        })
      ]);
      const [decisionData, recommendationData] = await Promise.all([
        readJson(decisionResponse),
        readJson(recommendationResponse)
      ]);
      currentApplication = decisionData.application;
      currentDecisionId = decisionData.current_decision_id;
      editorTitle.textContent = "Decision · " + decisionData.application.application_number;
      editorSubtitle.textContent = decisionData.application.program.name + " · Current status: " + decisionData.application.application_status;
      renderRecommendations(recommendationData.recommendations);
      renderDecisionHistory(decisionData.decisions);
      renderApprovedCreditOptions(recommendationData.recommendations);

      decisionForm.hidden = decisionData.can_decide !== true;
      supersedeContainer.hidden = currentDecisionId === null;
      supersedeCheckbox.checked = false;
      rationaleInput.value = "";
      decisionSelect.value = "approved";
      creditOptions.hidden = false;
      decisionSelect.dispatchEvent(new Event("change"));
      if (decisionData.can_decide === true) {
        setStatus(currentDecisionId === null
          ? "Review the recommendations, select the decision, and record the rationale."
          : "A current decision exists. Check the explicit supersession confirmation before replacing it.", "success");
      } else {
        setStatus("This application is view-only in its current state.", "");
      }
      editor.hidden = false;
    } catch (error) {
      setStatus(error.message || "Could not load the selected application.", "error");
      editor.hidden = true;
    }
  }

  decisionSelect.addEventListener("change", () => {
    const isApproval = decisionSelect.value === "approved";
    creditOptions.hidden = !isApproval;
    if (!isApproval) {
      for (const checkbox of creditOptions.querySelectorAll(".approved-credit-check")) {
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event("change"));
      }
    }
  });

  applicationSelect.addEventListener("change", () => {
    if (!applicationSelect.value) {
      editor.hidden = true;
      currentApplication = null;
      currentDecisionId = null;
      return;
    }
    loadApplication(Number(applicationSelect.value));
  });

  decisionForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!signedIn || busy || !csrfToken || !currentApplication) return;
    const decision = decisionSelect.value;
    const rationale = rationaleInput.value.trim();
    if (!rationale) {
      rationaleInput.focus();
      setStatus("A decision rationale is required.", "error");
      return;
    }
    if (currentDecisionId !== null && !supersedeCheckbox.checked) {
      supersedeCheckbox.focus();
      setStatus("Confirm explicit supersession before recording a replacement decision.", "error");
      return;
    }

    const approvedCredits = [];
    if (decision === "approved") {
      for (const card of creditOptions.querySelectorAll(".approved-credit-option")) {
        const checkbox = card.querySelector(".approved-credit-check");
        if (!checkbox || !checkbox.checked) continue;
        const amountInput = card.querySelector(".approved-credit-amount");
        const amount = amountInput ? amountInput.value.trim() : "";
        const maximum = Number(card.dataset.maximum);
        if (!amount || !/^\d{1,4}(?:\.\d{1,2})?$/.test(amount) || Number(amount) < 0 || Number(amount) > maximum) {
          amountInput?.focus();
          setStatus("Each approved course needs a valid amount no greater than its recommendation.", "error");
          return;
        }
        approvedCredits.push({
          course_code: card.dataset.courseCode,
          course_title: card.dataset.courseTitle,
          approved_credits: amount
        });
      }
      if (approvedCredits.length === 0) {
        setStatus("Select at least one recommended course to approve.", "error");
        return;
      }
    }

    busy = true;
    submitButton.disabled = true;
    refreshButton.disabled = true;
    setStatus("Recording accreditation decision…");
    const selectedId = currentApplication.application_id;
    try {
      const response = await fetch(endpoints.submit, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({
          application_id: selectedId,
          decision,
          rationale,
          supersedes_decision_id: currentDecisionId !== null && supersedeCheckbox.checked ? currentDecisionId : null,
          approved_credits: approvedCredits
        })
      });
      const data = await readJson(response);
      await loadCandidates(selectedId);
      if (applicationSelect.value) await loadApplication(selectedId);
      setStatus("Decision recorded and applicant notified. Decision #" + data.decision.accreditation_decision_id + ".", "success");
    } catch (error) {
      setStatus(error.message || "Could not record the accreditation decision.", "error");
    } finally {
      busy = false;
      submitButton.disabled = false;
      refreshButton.disabled = false;
    }
  });

  refreshButton.addEventListener("click", () => loadCandidates(applicationSelect.value ? Number(applicationSelect.value) : null));

  document.addEventListener("equipath:authenticated", async () => {
    signedIn = true;
    try {
      await refreshCsrf();
      await loadCandidates();
    } catch (error) {
      setStatus(error.message || "Could not initialize accreditation workspace.", "error");
    }
  });

  document.addEventListener("equipath:signed-out", () => {
    signedIn = false;
    csrfToken = null;
    currentApplication = null;
    currentDecisionId = null;
    workspace.hidden = true;
    editor.hidden = true;
    decisionForm.hidden = true;
    applicationSelect.replaceChildren();
    recommendationList.replaceChildren();
    historyList.replaceChildren();
    creditOptions.replaceChildren();
    rationaleInput.value = "";
  });
})();
