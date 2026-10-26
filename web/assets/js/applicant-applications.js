(() => {
  "use strict";

  const urls = {
    csrf: "../api/csrf.php",
    options: "../api/application-options.php",
    applications: "../api/applications.php",
    create: "../api/create-application.php",
    documents: "../api/application-documents.php",
    upload: "../api/upload-document.php",
    submit: "../api/submit-application.php",
    download: "../api/download-document.php"
  };
  const byId = (id) => document.getElementById(id);
  const workspace = byId("applicant-workspace");
  const status = byId("applicant-status");
  const refreshButton = byId("refresh-applications");
  const applicationList = byId("applicant-application-list");
  const createForm = byId("new-application-form");
  const programSelect = byId("new-application-program");
  const periodSelect = byId("new-application-period");
  const optionsHelp = byId("application-options-help");
  const createButton = byId("create-application-button");
  const documentPanel = byId("applicant-document-panel");
  const documentTitle = byId("applicant-document-title");
  const documentSubtitle = byId("applicant-document-subtitle");
  const documentList = byId("applicant-document-list");
  const requirementSelect = byId("applicant-document-type");
  const fileInput = byId("applicant-document-file");
  const uploadForm = byId("applicant-upload-form");
  const uploadButton = byId("applicant-upload-button");
  const uploadHelp = byId("applicant-upload-help");
  const submitApplicationButton = byId("submit-application-button");
  const submitApplicationHelp = byId("submit-application-help");

  let csrfToken = null;
  let signedIn = false;
  let busy = false;
  let programs = [];
  let periods = [];
  let applications = [];
  let selectedApplication = null;

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
        access_denied: "This account is not authorized to manage applicant applications.",
        csrf_validation_failed: "Your security token expired. Refresh the page and try again.",
        program_or_period_unavailable: "That program or application period is no longer available. Refresh the options.",
        invalid_application_details: "Choose a valid program and open application period.",
        document_rejected: "The PDF was rejected. Check that it is a valid PDF and that the requirement is active.",
        required_documents_incomplete: "The required evidence is incomplete.",
        application_not_submittable: "This application cannot be submitted in its current state or no active coordinator is configured.",
        document_too_large: "The file exceeds the permitted size.",
        service_unavailable: "The service is temporarily unavailable. Please try again later."
      };
      const error = new Error(messages[data?.error] || "The request could not be completed. Please try again.");
      error.code = data?.error;
      error.status = response.status;
      if (data?.error === "required_documents_incomplete" && Array.isArray(data.missing_requirements)) {
        error.message += " Missing: " + data.missing_requirements.map(item => item.name).filter(name => typeof name === "string").join(", ") + ".";
      }
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

  function formatBytes(bytes) {
    const value = Number(bytes);
    if (!Number.isFinite(value) || value < 0) return "Size unavailable";
    if (value < 1024) return value + " B";
    if (value < 1048576) return (value / 1024).toFixed(1) + " KiB";
    return (value / 1048576).toFixed(2) + " MiB";
  }

  function renderOptions() {
    programSelect.replaceChildren();
    const programPlaceholder = document.createElement("option");
    programPlaceholder.value = "";
    programPlaceholder.textContent = programs.length ? "Choose an active program" : "No active programs available";
    programSelect.append(programPlaceholder);
    for (const program of programs) {
      const option = document.createElement("option");
      option.value = String(program.program_id);
      option.textContent = program.program_code + " · " + program.program_name;
      programSelect.append(option);
    }

    periodSelect.replaceChildren();
    const periodPlaceholder = document.createElement("option");
    periodPlaceholder.value = "";
    periodPlaceholder.textContent = periods.length ? "Choose an open period" : "No open application periods";
    periodSelect.append(periodPlaceholder);
    for (const period of periods) {
      const option = document.createElement("option");
      option.value = String(period.period_id);
      option.textContent = period.period_name + " · closes " + period.closes_at;
      periodSelect.append(option);
    }
    createButton.disabled = programs.length === 0 || periods.length === 0;
    optionsHelp.textContent = createButton.disabled
      ? "You can track existing applications, but a new draft requires an active program and a currently open period."
      : "Creating a draft does not submit it for screening.";
  }

  async function loadOptions() {
    const response = await fetch(urls.options, {
      method: "GET", credentials: "same-origin", cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    const data = await readJson(response);
    programs = Array.isArray(data.programs) ? data.programs : [];
    periods = Array.isArray(data.open_periods) ? data.open_periods : [];
    renderOptions();
  }

  function renderApplications() {
    applicationList.replaceChildren();
    if (applications.length === 0) {
      addText(applicationList, "li", "empty-state", "You do not have any applications yet. Create a draft when an application period is open.");
      return;
    }
    for (const application of applications) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title", application.application_number);
      addText(content, "p", "notification-message", application.program?.name || "Program unavailable");
      addText(content, "p", "notification-meta",
        "Status: " + String(application.application_status).replaceAll("_", " ") +
        (application.submitted_at ? " · Submitted " + application.submitted_at : " · Not submitted"));
      const button = document.createElement("button");
      button.type = "button";
      button.className = "button secondary compact";
      button.textContent = "View documents";
      button.addEventListener("click", () => openDocuments(application));
      content.append(button);
      row.append(content);
      applicationList.append(row);
    }
  }

  async function loadApplications() {
    const response = await fetch(urls.applications, {
      method: "GET", credentials: "same-origin", cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    const data = await readJson(response);
    applications = Array.isArray(data.applications)
      ? data.applications.filter(item => item.access_type === "applicant")
      : [];
    renderApplications();
  }

  function renderRequirements() {
    requirementSelect.replaceChildren();
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "Choose a document requirement";
    requirementSelect.append(placeholder);
    const program = programs.find(item => Number(item.program_id) === Number(selectedApplication?.program_id));
    const requirements = Array.isArray(program?.requirements) ? program.requirements : [];
    for (const requirement of requirements) {
      const option = document.createElement("option");
      option.value = requirement.requirement_key;
      option.textContent = requirement.requirement_name + (requirement.is_required ? " · Required" : " · Optional");
      option.dataset.maxBytes = requirement.max_file_size_bytes === null ? "" : String(requirement.max_file_size_bytes);
      requirementSelect.append(option);
    }
    const editable = ["draft", "needs_correction"].includes(selectedApplication?.application_status);
    requirementSelect.disabled = requirements.length === 0 || !editable;
    uploadButton.disabled = requirementSelect.disabled;
    submitApplicationButton.disabled = !editable || requirements.length === 0;
    submitApplicationButton.hidden = !editable;
    submitApplicationHelp.textContent = !editable
      ? "This application is not editable or has already been submitted."
      : requirements.length === 0
        ? "Submission is blocked until the program has active required-document configuration."
        : "Submission requires every active required document to have a current pending or accepted version. The server verifies this again.";
    uploadHelp.textContent = requirements.length === 0
      ? "No active document requirements are configured for this program. Contact the coordinator."
      : ["draft", "needs_correction"].includes(selectedApplication?.application_status)
        ? "Only PDF is accepted. Maximum 10 MiB, or the smaller limit configured for the selected requirement. Uploads are not automatically malware-scanned."
        : "This application is not editable. Documents remain available for review, but new uploads are disabled.";
  }

  async function loadDocuments(application) {
    const response = await fetch(urls.documents + "?application_id=" + encodeURIComponent(String(application.application_id)), {
      method: "GET", credentials: "same-origin", cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    const data = await readJson(response);
    documentList.replaceChildren();
    if (!Array.isArray(data.documents) || data.documents.length === 0) {
      addText(documentList, "li", "empty-state", "No documents have been uploaded to this application.");
      return;
    }
    for (const document of data.documents) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title", document.document_type.replaceAll("_", " ") + " · v" + document.version_number);
      addText(content, "p", "notification-message", document.original_filename);
      addText(content, "p", "notification-meta",
        formatBytes(document.file_size_bytes) + " · Review: " + document.review_status.replaceAll("_", " ") + " · " + document.uploaded_at);
      const link = document.createElement("a");
      link.className = "button secondary compact";
      link.href = urls.download + "?document_id=" + encodeURIComponent(String(document.document_id));
      link.textContent = "Download PDF";
      link.setAttribute("aria-label", "Download " + document.original_filename);
      content.append(link);
      row.append(content);
      documentList.append(row);
    }
  }

  async function openDocuments(application) {
    selectedApplication = application;
    documentPanel.hidden = false;
    documentTitle.textContent = "Documents · " + application.application_number;
    documentSubtitle.textContent = application.program?.name || "Program unavailable";
    renderRequirements();
    setStatus("Loading documents…");
    try {
      await loadDocuments(application);
      setStatus("Application status: " + application.application_status.replaceAll("_", " "), "success");
      documentPanel.scrollIntoView({ behavior: "smooth", block: "start" });
    } catch (error) {
      setStatus(error.message || "Could not load application documents.", "error");
    }
  }

  async function refreshAll() {
    if (!signedIn || busy) return;
    refreshButton.disabled = true;
    setStatus("Refreshing your applications…");
    try {
      await loadOptions();
      await loadApplications();
      if (selectedApplication) {
        const refreshed = applications.find(item => item.application_id === selectedApplication.application_id);
        if (refreshed) {
          selectedApplication = refreshed;
          renderRequirements();
          await loadDocuments(refreshed);
        } else {
          selectedApplication = null;
          documentPanel.hidden = true;
        }
      }
      setStatus(applications.length === 1 ? "1 application" : applications.length + " applications", "success");
    } catch (error) {
      setStatus(error.message || "Could not refresh applications.", "error");
      if (error.code === "access_denied") workspace.hidden = true;
    } finally {
      refreshButton.disabled = false;
    }
  }

  createForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!signedIn || busy || !csrfToken) return;
    const programId = Number(programSelect.value);
    const periodId = Number(periodSelect.value);
    if (!Number.isSafeInteger(programId) || programId < 1 || !Number.isSafeInteger(periodId) || periodId < 1) {
      setStatus("Choose an active program and open application period.", "error");
      return;
    }
    busy = true;
    createButton.disabled = true;
    refreshButton.disabled = true;
    setStatus("Creating your draft…");
    try {
      const response = await fetch(urls.create, {
        method: "POST", credentials: "same-origin", cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({ program_id: programId, period_id: periodId })
      });
      const data = await readJson(response);
      await loadApplications();
      const created = applications.find(item => item.application_id === data.application.application_id);
      if (created) await openDocuments(created);
      setStatus("Draft created. Upload the required PDF evidence before submission is added to the workflow.", "success");
    } catch (error) {
      setStatus(error.message || "Could not create application draft.", "error");
    } finally {
      busy = false;
      createButton.disabled = programs.length === 0 || periods.length === 0;
      refreshButton.disabled = false;
    }
  });

  submitApplicationButton.addEventListener("click", async () => {
    if (!signedIn || busy || !csrfToken || !selectedApplication) return;
    if (!["draft", "needs_correction"].includes(selectedApplication.application_status)) {
      setStatus("Only drafts or applications returned for correction can be submitted.", "error");
      return;
    }
    const confirmed = window.confirm("Submit this application for initial screening? You will not be able to upload more documents unless the college returns it for correction.");
    if (!confirmed) return;

    busy = true;
    submitApplicationButton.disabled = true;
    uploadButton.disabled = true;
    refreshButton.disabled = true;
    setStatus("Checking required documents and submitting application…");
    const submittedApplicationId = selectedApplication.application_id;
    try {
      const response = await fetch(urls.submit, {
        method: "POST", credentials: "same-origin", cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({ application_id: submittedApplicationId })
      });
      const data = await readJson(response);
      await loadApplications();
      selectedApplication = applications.find(item => item.application_id === submittedApplicationId) || null;
      if (selectedApplication) {
        await loadDocuments(selectedApplication);
        renderRequirements();
      } else {
        documentPanel.hidden = true;
      }
      setStatus("Application " + data.application.application_number + " was submitted for screening.", "success");
    } catch (error) {
      setStatus(error.message || "Could not submit application.", "error");
      renderRequirements();
    } finally {
      busy = false;
      refreshButton.disabled = false;
      renderRequirements();
    }
  });

  requirementSelect.addEventListener("change", () => {
    const selected = requirementSelect.selectedOptions[0];
    const maxBytes = Number(selected?.dataset.maxBytes || 0);
    fileInput.value = "";
    fileInput.setCustomValidity("");
    fileInput.setAttribute("aria-describedby", "applicant-upload-help");
    if (maxBytes > 0) {
      fileInput.dataset.maxBytes = String(maxBytes);
      uploadHelp.textContent = "The selected requirement limit is " + formatBytes(maxBytes) + ". Files are stored privately but are not automatically malware-scanned.";
    } else {
      delete fileInput.dataset.maxBytes;
      uploadHelp.textContent = "Maximum 10 MiB. Only PDF is accepted. Files are stored privately but are not automatically malware-scanned.";
    }
  });

  fileInput.addEventListener("change", () => {
    const file = fileInput.files?.[0];
    const maxBytes = Number(fileInput.dataset.maxBytes || 10485760);
    if (file && file.size > Math.min(maxBytes || 10485760, 10485760)) {
      fileInput.setCustomValidity("The selected file exceeds the allowed size.");
      fileInput.reportValidity();
    } else {
      fileInput.setCustomValidity("");
    }
  });

  uploadForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!signedIn || busy || !csrfToken || !selectedApplication) return;
    if (!["draft", "needs_correction"].includes(selectedApplication.application_status)) {
      setStatus("Documents can only be uploaded while an application is a draft or needs correction.", "error");
      return;
    }
    const file = fileInput.files?.[0];
    if (!file) {
      fileInput.focus();
      setStatus("Choose a PDF file to upload.", "error");
      return;
    }
    const selectedRequirement = requirementSelect.selectedOptions[0];
    const maxBytes = Number(selectedRequirement?.dataset.maxBytes || 10485760);
    if (file.size > Math.min(maxBytes || 10485760, 10485760)) {
      fileInput.focus();
      setStatus("The selected PDF exceeds the size limit for this requirement.", "error");
      return;
    }
    busy = true;
    uploadButton.disabled = true;
    refreshButton.disabled = true;
    setStatus("Uploading private PDF evidence…");
    try {
      const formData = new FormData();
      formData.append("application_id", String(selectedApplication.application_id));
      formData.append("document_type", requirementSelect.value);
      formData.append("document", file);
      const response = await fetch(urls.upload, {
        method: "POST", credentials: "same-origin", cache: "no-store",
        headers: { "Accept": "application/json", "X-CSRF-Token": csrfToken },
        body: formData
      });
      await readJson(response);
      fileInput.value = "";
      await loadDocuments(selectedApplication);
      await loadApplications();
      selectedApplication = applications.find(item => item.application_id === selectedApplication.application_id) || selectedApplication;
      renderRequirements();
      setStatus("PDF uploaded privately. Its review status is pending; uploading does not submit the application.", "success");
    } catch (error) {
      setStatus(error.message || "Could not upload PDF evidence.", "error");
    } finally {
      busy = false;
      uploadButton.disabled = requirementSelect.disabled;
      refreshButton.disabled = false;
    }
  });

  byId("close-applicant-documents").addEventListener("click", () => {
    selectedApplication = null;
    documentPanel.hidden = true;
  });
  refreshButton.addEventListener("click", refreshAll);

  document.addEventListener("equipath:authenticated", async () => {
    signedIn = true;
    try {
      await refreshCsrf();
      await refreshAll();
      workspace.hidden = false;
    } catch (error) {
      if (error.code === "access_denied") {
        workspace.hidden = true;
        return;
      }
      setStatus(error.message || "Could not initialize applicant workspace.", "error");
    }
  });

  document.addEventListener("equipath:signed-out", () => {
    signedIn = false;
    busy = false;
    csrfToken = null;
    programs = [];
    periods = [];
    applications = [];
    selectedApplication = null;
    workspace.hidden = true;
    applicationList.replaceChildren();
    documentList.replaceChildren();
    programSelect.replaceChildren();
    periodSelect.replaceChildren();
    requirementSelect.replaceChildren();
    fileInput.value = "";
    documentPanel.hidden = true;
  });
})();
