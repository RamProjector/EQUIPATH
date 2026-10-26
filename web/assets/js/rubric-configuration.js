(() => {
  "use strict";

  const urls = {
    csrf: "../api/csrf.php",
    management: "../api/rubric-management.php",
    save: "../api/save-rubric.php"
  };
  const byId = (id) => document.getElementById(id);
  const workspace = byId("rubric-workspace");
  const status = byId("rubric-status");
  const refreshButton = byId("refresh-rubrics");
  const programSelect = byId("rubric-program");
  const historyList = byId("rubric-history-list");
  const rubricForm = byId("rubric-form");
  const rubricNameInput = byId("rubric-name");
  const criteriaEditor = byId("rubric-criteria-editor");
  const addCriterionButton = byId("add-rubric-criterion");
  const saveButton = byId("save-rubric");

  let csrfToken = null;
  let signedIn = false;
  let busy = false;
  let programs = [];
  let rubrics = [];
  let nextCriterionKey = 1;

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
        access_denied: "This account is not authorized to configure rubrics.",
        csrf_validation_failed: "Your security token expired. Refresh the page and try again.",
        rubric_change_not_allowed: "The rubric cannot be changed while applications are under evaluation or the current rubric configuration is inconsistent.",
        invalid_rubric_details: "Check the rubric name and each criterion's code, name, maximum score and weight.",
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

  function selectedProgram() {
    return programs.find(program => Number(program.program_id) === Number(programSelect.value)) || null;
  }

  function renderPrograms() {
    programSelect.replaceChildren();
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = programs.length ? "Choose an active program" : "No active programs available";
    programSelect.append(placeholder);
    for (const program of programs) {
      const option = document.createElement("option");
      option.value = String(program.program_id);
      option.textContent = program.program_code + " · " + program.program_name;
      programSelect.append(option);
    }
  }

  function renderHistory() {
    historyList.replaceChildren();
    const program = selectedProgram();
    if (!program) {
      addText(historyList, "li", "empty-state", "Choose a program to view its rubric versions.");
      return;
    }
    const versions = rubrics.filter(rubric => Number(rubric.program_id) === Number(program.program_id));
    if (versions.length === 0) {
      addText(historyList, "li", "empty-state", "No rubric versions have been configured for this program.");
      if (!rubricNameInput.value.trim()) rubricNameInput.value = program.program_name + " Evaluation Rubric";
      return;
    }
    for (const rubric of versions) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title",
        rubric.rubric_name + " · Version " + rubric.version_number + (rubric.is_active ? " · Active" : " · Archived"));
      addText(content, "p", "notification-meta",
        rubric.criteria.length + " criteria · Created " + rubric.created_at);
      if (rubric.criteria.length > 0) {
        const criteria = document.createElement("ul");
        criteria.className = "approved-credit-history";
        for (const criterion of rubric.criteria) {
          addText(criteria, "li", "",
            criterion.criterion_code + " · " + criterion.criterion_name +
            " — max " + criterion.max_score + ", weight " + criterion.weight);
        }
        content.append(criteria);
      }
      row.append(content);
      historyList.append(row);
    }
    if (!rubricNameInput.value.trim()) rubricNameInput.value = versions.find(item => item.is_active)?.rubric_name || program.program_name + " Evaluation Rubric";
  }

  function addCriterionRow(values = {}) {
    const key = nextCriterionKey++;
    const fieldset = document.createElement("fieldset");
    fieldset.className = "criterion-card rubric-criterion-row";
    fieldset.dataset.key = String(key);
    const heading = document.createElement("div");
    heading.className = "inbox-heading";
    addText(heading, "h5", "", "Criterion " + key);
    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "button secondary compact";
    remove.textContent = "Remove";
    remove.addEventListener("click", () => {
      fieldset.remove();
      renumberCriterionRows();
    });
    heading.append(remove);

    function addField(labelText, field, type, value, options = {}) {
      const label = document.createElement("label");
      const id = "rubric-criterion-" + key + "-" + field;
      label.htmlFor = id;
      label.textContent = labelText;
      const input = document.createElement(type === "textarea" ? "textarea" : "input");
      input.id = id;
      input.dataset.field = field;
      if (type !== "textarea") input.type = type;
      input.required = options.required !== false;
      input.value = value ?? "";
      if (options.maxLength) input.maxLength = options.maxLength;
      if (options.min !== undefined) input.min = String(options.min);
      if (options.max !== undefined) input.max = String(options.max);
      if (options.step !== undefined) input.step = String(options.step);
      if (type === "textarea") input.rows = 2;
      fieldset.append(label, input);
    }

    fieldset.append(heading);
    addField("Criterion code", "criterion_code", "text", values.criterion_code || "", { maxLength: 40 });
    addField("Criterion name", "criterion_name", "text", values.criterion_name || "", { maxLength: 180 });
    addField("Description (optional)", "description", "textarea", values.description || "", { required: false, maxLength: 4000 });
    addField("Maximum score", "max_score", "number", values.max_score || "10", { min: 0.01, max: 99999.99, step: 0.01 });
    addField("Weight", "weight", "number", values.weight || "1", { min: 0.0001, max: 999.9999, step: 0.0001 });
    criteriaEditor.append(fieldset);
  }

  function renumberCriterionRows() {
    const rows = Array.from(criteriaEditor.querySelectorAll(".rubric-criterion-row"));
    rows.forEach((row, index) => {
      const heading = row.querySelector("h5");
      if (heading) heading.textContent = "Criterion " + (index + 1);
    });
  }

  function collectCriteria() {
    const rows = Array.from(criteriaEditor.querySelectorAll(".rubric-criterion-row"));
    if (rows.length < 1 || rows.length > 100) throw new Error("Add between 1 and 100 criteria.");
    return rows.map((row, index) => {
      const field = (name) => row.querySelector('[data-field="' + name + '"]')?.value.trim() ?? "";
      return {
        criterion_code: field("criterion_code"),
        criterion_name: field("criterion_name"),
        description: field("description"),
        max_score: field("max_score"),
        weight: field("weight"),
        sort_order: index
      };
    });
  }

  async function loadManagement(selectedProgramId = null) {
    refreshButton.disabled = true;
    setStatus("Loading programs and rubric history…");
    try {
      const response = await fetch(urls.management, {
        method: "GET", credentials: "same-origin", cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      programs = Array.isArray(data.programs) ? data.programs : [];
      rubrics = Array.isArray(data.rubrics) ? data.rubrics : [];
      renderPrograms();
      if (selectedProgramId !== null &&
          programs.some(program => Number(program.program_id) === Number(selectedProgramId))) {
        programSelect.value = String(selectedProgramId);
      }
      renderHistory();
      workspace.hidden = false;
      setStatus(programs.length + " active programs · " + rubrics.length + " rubric versions.", "success");
    } catch (error) {
      if (error.code === "access_denied") {
        workspace.hidden = true;
        return;
      }
      setStatus(error.message || "Could not load rubric configuration.", "error");
      if (error.code === "authentication_required") workspace.hidden = true;
    } finally {
      refreshButton.disabled = false;
    }
  }

  programSelect.addEventListener("change", () => {
    rubricNameInput.value = "";
    renderHistory();
  });
  addCriterionButton.addEventListener("click", () => {
    if (criteriaEditor.querySelectorAll(".rubric-criterion-row").length >= 100) {
      setStatus("A rubric cannot contain more than 100 criteria.", "error");
      return;
    }
    addCriterionRow();
  });

  rubricForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!signedIn || busy || !csrfToken) return;
    const program = selectedProgram();
    if (!program) {
      programSelect.focus();
      setStatus("Choose an active program.", "error");
      return;
    }
    let criteria;
    try {
      criteria = collectCriteria();
    } catch (error) {
      setStatus(error.message, "error");
      return;
    }
    if (!rubricNameInput.value.trim()) {
      rubricNameInput.focus();
      setStatus("Enter a rubric name.", "error");
      return;
    }
    const confirmMessage = "Create a new rubric version for " + program.program_name +
      "? The current active rubric will be archived if the program has no applications under evaluation.";
    if (!window.confirm(confirmMessage)) return;

    busy = true;
    saveButton.disabled = true;
    refreshButton.disabled = true;
    setStatus("Validating and creating a new rubric version…");
    try {
      const response = await fetch(urls.save, {
        method: "POST", credentials: "same-origin", cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({
          program_id: Number(program.program_id),
          rubric_name: rubricNameInput.value.trim(),
          criteria
        })
      });
      const data = await readJson(response);
      await loadManagement(program.program_id);
      rubricNameInput.value = data.rubric.rubric_name;
      criteriaEditor.replaceChildren();
      addCriterionRow();
      setStatus("Created rubric version " + data.rubric.version_number + " with " + data.rubric.criterion_count + " criteria.", "success");
    } catch (error) {
      setStatus(error.message || "Could not save rubric version.", "error");
    } finally {
      busy = false;
      saveButton.disabled = false;
      refreshButton.disabled = false;
    }
  });

  refreshButton.addEventListener("click", () => loadManagement(programSelect.value ? Number(programSelect.value) : null));

  document.addEventListener("equipath:authenticated", async () => {
    signedIn = true;
    try {
      await refreshCsrf();
      await loadManagement();
      criteriaEditor.replaceChildren();
      addCriterionRow();
    } catch (error) {
      if (error.code === "access_denied") workspace.hidden = true;
      else setStatus(error.message || "Could not initialize rubric configuration.", "error");
    }
  });

  document.addEventListener("equipath:signed-out", () => {
    signedIn = false;
    busy = false;
    csrfToken = null;
    programs = [];
    rubrics = [];
    workspace.hidden = true;
    programSelect.replaceChildren();
    historyList.replaceChildren();
    criteriaEditor.replaceChildren();
    rubricNameInput.value = "";
  });
})();
