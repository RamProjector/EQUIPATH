(() => {
  "use strict";

  const endpoints = {
    csrf: "../api/csrf.php",
    list: "../api/credit-recommendations.php",
    submit: "../api/submit-credit-recommendations.php"
  };
  const byId = (id) => document.getElementById(id);
  const editor = byId("credit-recommendations-editor");
  const title = byId("credit-recommendations-title");
  const subtitle = byId("credit-recommendations-subtitle");
  const list = byId("credit-recommendations-list");
  const status = byId("credit-recommendations-status");
  const form = byId("credit-recommendations-form");
  const fields = byId("credit-recommendation-fields");
  const addButton = byId("add-credit-recommendation");
  const submitButton = byId("submit-credit-recommendations");
  const closeButton = byId("close-credit-recommendations");

  let csrfToken = null;
  let signedIn = false;
  let requestInProgress = false;
  let currentAssignment = null;
  let existingCourseCodes = new Set();
  let rowCounter = 0;

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
        access_denied: "This account is not authorized to submit credit recommendations.",
        csrf_validation_failed: "Your security token expired. Sign in again or refresh the page.",
        credit_recommendations_not_allowed: "The assignment or application is not accepting these recommendations, or a course code already exists.",
        invalid_credit_recommendations: "Check each course code, title, credit value, and rationale.",
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

  function renderRecommendations(items) {
    list.replaceChildren();
    existingCourseCodes = new Set();
    if (!Array.isArray(items) || items.length === 0) {
      addText(list, "li", "empty-state", "No course-credit recommendations have been submitted for this assignment.");
      return;
    }
    for (const item of items) {
      existingCourseCodes.add(String(item.course_code || "").toUpperCase());
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title", item.course_code + " · " + item.course_title);
      addText(content, "p", "notification-meta", "Recommended credits: " + item.recommended_credits);
      addText(content, "p", "notification-message", item.rationale);
      addText(content, "p", "notification-meta", "Submitted " + String(item.created_at || ""));
      row.append(content);
      list.append(row);
    }
  }

  function addRecommendationRow() {
    rowCounter += 1;
    const number = rowCounter;
    const card = document.createElement("fieldset");
    card.className = "criterion-card credit-recommendation-row";
    const legend = document.createElement("legend");
    legend.textContent = "Course recommendation " + number;
    card.append(legend);

    const codeLabel = document.createElement("label");
    codeLabel.htmlFor = "credit-course-code-" + number;
    codeLabel.textContent = "Course code";
    const code = document.createElement("input");
    code.id = codeLabel.htmlFor;
    code.name = "course_code";
    code.type = "text";
    code.maxLength = 40;
    code.pattern = "[A-Za-z0-9][A-Za-z0-9._-]{0,39}";
    code.required = true;
    code.autocomplete = "off";

    const titleLabel = document.createElement("label");
    titleLabel.htmlFor = "credit-course-title-" + number;
    titleLabel.textContent = "Course title";
    const courseTitle = document.createElement("input");
    courseTitle.id = titleLabel.htmlFor;
    courseTitle.name = "course_title";
    courseTitle.type = "text";
    courseTitle.maxLength = 180;
    courseTitle.required = true;

    const creditsLabel = document.createElement("label");
    creditsLabel.htmlFor = "credit-course-credits-" + number;
    creditsLabel.textContent = "Recommended credits";
    const credits = document.createElement("input");
    credits.id = creditsLabel.htmlFor;
    credits.name = "recommended_credits";
    credits.type = "number";
    credits.min = "0";
    credits.max = "9999.99";
    credits.step = "0.01";
    credits.required = true;

    const rationaleLabel = document.createElement("label");
    rationaleLabel.htmlFor = "credit-course-rationale-" + number;
    rationaleLabel.textContent = "Rationale";
    const rationale = document.createElement("textarea");
    rationale.id = rationaleLabel.htmlFor;
    rationale.name = "rationale";
    rationale.rows = 3;
    rationale.maxLength = 4000;
    rationale.required = true;

    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "button secondary compact";
    remove.textContent = "Remove course";
    remove.addEventListener("click", () => card.remove());

    card.append(codeLabel, code, titleLabel, courseTitle, creditsLabel, credits, rationaleLabel, rationale, remove);
    fields.append(card);
  }

  async function loadRecommendations(assignmentId) {
    const response = await fetch(endpoints.list + "?evaluator_assignment_id=" + encodeURIComponent(String(assignmentId)), {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    const data = await readJson(response);
    renderRecommendations(data.recommendations);
    const canSubmit = data.can_submit === true;
    form.hidden = !canSubmit;
    addButton.disabled = !canSubmit;
    submitButton.disabled = !canSubmit;
    if (canSubmit && fields.children.length === 0) addRecommendationRow();
    if (!canSubmit) {
      setStatus("Recommendations can only be added by the assigned evaluator after evaluation submission.", "");
    } else {
      setStatus("Existing recommendations are read-only. Add new course codes to submit additional recommendations.");
    }
  }

  async function openRecommendations(assignment) {
    if (!signedIn) return;
    currentAssignment = assignment;
    editor.hidden = false;
    title.textContent = "Credit recommendations · " + assignment.application_number;
    subtitle.textContent = assignment.program && assignment.program.name ? assignment.program.name : "";
    fields.replaceChildren();
    rowCounter = 0;
    form.reset();
    setStatus("Loading recommendations…");
    try {
      await loadRecommendations(assignment.evaluator_assignment_id);
      editor.scrollIntoView({ behavior: "smooth", block: "start" });
    } catch (error) {
      setStatus(error.message || "Could not load recommendations.", "error");
    }
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!signedIn || requestInProgress || !csrfToken || !currentAssignment) return;
    const rows = Array.from(fields.querySelectorAll(".credit-recommendation-row"));
    if (rows.length === 0) {
      setStatus("Add at least one course recommendation.", "error");
      return;
    }

    const recommendations = [];
    const submittedCodes = new Set();
    for (const row of rows) {
      const courseCode = row.querySelector('[name="course_code"]').value.trim().toUpperCase();
      const courseTitle = row.querySelector('[name="course_title"]').value.trim();
      const credits = row.querySelector('[name="recommended_credits"]').value.trim();
      const rationale = row.querySelector('[name="rationale"]').value.trim();
      if (!/^[A-Z0-9][A-Z0-9._-]{0,39}$/.test(courseCode)) {
        row.querySelector('[name="course_code"]').focus();
        setStatus("Course codes may contain letters, numbers, periods, underscores and hyphens.", "error");
        return;
      }
      if (existingCourseCodes.has(courseCode) || submittedCodes.has(courseCode)) {
        row.querySelector('[name="course_code"]').focus();
        setStatus("Each course code can only be recommended once for this assignment.", "error");
        return;
      }
      if (!courseTitle || !/^\d{1,4}(?:\.\d{1,2})?$/.test(credits) || Number(credits) < 0 || Number(credits) > 9999.99 || !rationale) {
        setStatus("Complete every course title, valid credit value and rationale.", "error");
        return;
      }
      submittedCodes.add(courseCode);
      recommendations.push({
        course_code: courseCode,
        course_title: courseTitle,
        recommended_credits: credits,
        rationale
      });
    }

    requestInProgress = true;
    submitButton.disabled = true;
    addButton.disabled = true;
    setStatus("Submitting recommendations…");
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
          evaluator_assignment_id: currentAssignment.evaluator_assignment_id,
          recommendations
        })
      });
      const data = await readJson(response);
      fields.replaceChildren();
      rowCounter = 0;
      form.reset();
      await loadRecommendations(currentAssignment.evaluator_assignment_id);
      setStatus(data.recommendations.count + " credit recommendation(s) submitted. The coordinator has been notified.", "success");
    } catch (error) {
      setStatus(error.message || "Could not submit recommendations.", "error");
    } finally {
      requestInProgress = false;
      submitButton.disabled = form.hidden;
      addButton.disabled = form.hidden;
    }
  });

  addButton.addEventListener("click", addRecommendationRow);
  closeButton.addEventListener("click", () => {
    editor.hidden = true;
    currentAssignment = null;
    fields.replaceChildren();
    form.reset();
  });

  document.addEventListener("equipath:open-credit-recommendations", (event) => {
    if (event.detail && event.detail.assignment) openRecommendations(event.detail.assignment);
  });

  document.addEventListener("equipath:authenticated", async () => {
    signedIn = true;
    try {
      await refreshCsrf();
    } catch (error) {
      setStatus(error.message || "Could not initialize credit recommendations.", "error");
    }
  });

  document.addEventListener("equipath:signed-out", () => {
    signedIn = false;
    csrfToken = null;
    currentAssignment = null;
    editor.hidden = true;
    list.replaceChildren();
    fields.replaceChildren();
    form.reset();
  });
})();
