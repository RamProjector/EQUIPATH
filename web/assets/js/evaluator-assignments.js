(() => {
  "use strict";

  const urls = {
    csrf: "../api/csrf.php",
    options: "../api/evaluator-assignment-options.php",
    assign: "../api/assign-evaluator.php"
  };
  const byId = (id) => document.getElementById(id);
  const workspace = byId("assignment-workspace");
  const status = byId("assignment-status");
  const refreshButton = byId("refresh-assignments");
  const applicationList = byId("assignment-application-list");

  let csrfToken = null;
  let signedIn = false;
  let busy = false;
  let applications = [];
  let evaluators = [];
  let assignments = [];

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
        access_denied: "This account is not authorized to assign evaluators.",
        csrf_validation_failed: "Your security token expired. Refresh the page and try again.",
        assignment_not_allowed: "This application or evaluator is not eligible for this assignment, or the evaluator is already assigned.",
        invalid_assignment_details: "Choose an application and an active evaluator.",
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
    const response = await fetch(urls.csrf, {
      method: "GET", credentials: "same-origin", cache: "no-store",
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

  function renderApplications() {
    applicationList.replaceChildren();
    if (applications.length === 0) {
      addText(applicationList, "li", "empty-state", "No applications are currently eligible for evaluator assignment.");
      return;
    }

    const assignmentsByApplication = new Map();
    for (const assignment of assignments) {
      if (!assignmentsByApplication.has(assignment.application_id)) {
        assignmentsByApplication.set(assignment.application_id, []);
      }
      assignmentsByApplication.get(assignment.application_id).push(assignment);
    }

    for (const application of applications) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title", application.application_number);
      addText(content, "p", "notification-message",
        application.applicant.given_name + " " + application.applicant.family_name + " · " + application.program.name);
      addText(content, "p", "notification-meta",
        "Status: " + application.application_status.replaceAll("_", " ") +
        " · Assigned evaluators: " + application.assignment_count);

      const currentAssignments = assignmentsByApplication.get(application.application_id) || [];
      if (currentAssignments.length > 0) {
        const list = document.createElement("ul");
        list.className = "approved-credit-history";
        for (const assignment of currentAssignments) {
          addText(list, "li", "",
            assignment.evaluator.given_name + " " + assignment.evaluator.family_name +
            " (" + assignment.evaluator.email + ") · " + assignment.assignment_status.replaceAll("_", " "));
        }
        content.append(list);
      } else {
        addText(content, "p", "notification-meta", "No evaluators assigned yet.");
      }

      const form = document.createElement("form");
      form.className = "assessment-form";
      const label = document.createElement("label");
      const selectId = "assignment-evaluator-" + application.application_id;
      label.htmlFor = selectId;
      label.textContent = "Assign an evaluator";
      const select = document.createElement("select");
      select.id = selectId;
      select.required = true;
      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = evaluators.length ? "Choose an active evaluator" : "No active evaluators available";
      select.append(placeholder);
      const assignedIds = new Set(currentAssignments.map(item => Number(item.evaluator_user_id)));
      for (const evaluator of evaluators) {
        if (assignedIds.has(Number(evaluator.user_id))) continue;
        const option = document.createElement("option");
        option.value = String(evaluator.user_id);
        option.textContent = evaluator.family_name + ", " + evaluator.given_name + " · " + evaluator.email;
        select.append(option);
      }
      const button = document.createElement("button");
      button.type = "submit";
      button.className = "button primary";
      button.textContent = "Assign evaluator";
      button.disabled = evaluators.length === 0 || Array.from(select.options).length < 2;
      form.append(label, select, button);
      form.addEventListener("submit", event => {
        event.preventDefault();
        assignEvaluator(application, Number(select.value), button);
      });
      content.append(form);
      row.append(content);
      applicationList.append(row);
    }
  }

  async function loadOptions() {
    refreshButton.disabled = true;
    setStatus("Loading assignment queue…");
    try {
      const response = await fetch(urls.options, {
        method: "GET", credentials: "same-origin", cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      applications = Array.isArray(data.applications) ? data.applications : [];
      evaluators = Array.isArray(data.evaluators) ? data.evaluators : [];
      assignments = Array.isArray(data.assignments) ? data.assignments : [];
      renderApplications();
      workspace.hidden = false;
      setStatus(applications.length + " applications · " + evaluators.length + " active evaluators available.", "success");
    } catch (error) {
      if (error.code === "access_denied") {
        workspace.hidden = true;
        return;
      }
      setStatus(error.message || "Could not load evaluator assignments.", "error");
      if (error.code === "authentication_required") workspace.hidden = true;
    } finally {
      refreshButton.disabled = false;
    }
  }

  async function assignEvaluator(application, evaluatorId, button) {
    if (!signedIn || busy || !csrfToken) return;
    if (!Number.isSafeInteger(evaluatorId) || evaluatorId < 1) {
      setStatus("Choose an active evaluator.", "error");
      return;
    }
    const alreadyAssigned = assignments.some(item =>
      Number(item.application_id) === Number(application.application_id) &&
      Number(item.evaluator_user_id) === evaluatorId
    );
    if (alreadyAssigned) {
      setStatus("That evaluator is already assigned to this application.", "error");
      return;
    }

    busy = true;
    button.disabled = true;
    refreshButton.disabled = true;
    setStatus("Assigning evaluator…");
    try {
      const response = await fetch(urls.assign, {
        method: "POST", credentials: "same-origin", cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({
          application_id: application.application_id,
          evaluator_user_id: evaluatorId
        })
      });
      const data = await readJson(response);
      await loadOptions();
      setStatus("Evaluator assigned to " + application.application_number + ". The evaluator and applicant have been notified.", "success");
    } catch (error) {
      setStatus(error.message || "Could not assign evaluator.", "error");
    } finally {
      busy = false;
      button.disabled = false;
      refreshButton.disabled = false;
    }
  }

  refreshButton.addEventListener("click", loadOptions);
  document.addEventListener("equipath:authenticated", async () => {
    signedIn = true;
    try {
      await refreshCsrf();
      await loadOptions();
    } catch (error) {
      if (error.code === "access_denied") workspace.hidden = true;
      else setStatus(error.message || "Could not initialize assignment workspace.", "error");
    }
  });
  document.addEventListener("equipath:signed-out", () => {
    signedIn = false;
    busy = false;
    csrfToken = null;
    applications = [];
    evaluators = [];
    assignments = [];
    workspace.hidden = true;
    applicationList.replaceChildren();
  });
})();
