(() => {
  "use strict";

  const urls = {
    csrf: "../api/csrf.php",
    candidates: "../api/screening-candidates.php",
    documents: "../api/application-documents.php",
    download: "../api/download-document.php",
    reviewDocument: "../api/review-application-document.php",
    screenApplication: "../api/screen-application.php"
  };
  const byId = (id) => document.getElementById(id);
  const workspace = byId("screening-workspace");
  const status = byId("screening-status");
  const refreshButton = byId("refresh-screening");
  const candidateList = byId("screening-candidate-list");
  const editor = byId("screening-editor");
  const editorTitle = byId("screening-editor-title");
  const editorSubtitle = byId("screening-editor-subtitle");
  const documentList = byId("screening-document-list");
  const documentNotes = byId("screening-document-notes");
  const decisionForm = byId("screening-decision-form");
  const decisionOutcome = byId("screening-outcome");
  const decisionNotes = byId("screening-notes");
  const submitDecisionButton = byId("submit-screening-decision");

  let csrfToken = null;
  let signedIn = false;
  let busy = false;
  let candidates = [];
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
        access_denied: "This account is not authorized to screen applications.",
        csrf_validation_failed: "Your security token expired. Refresh the page and try again.",
        document_not_reviewable: "Only pending documents on an active screening application can be reviewed. Refresh the queue.",
        application_not_screenable: "This application cannot be screened in its current state or required evidence is not accepted.",
        invalid_document_review: "Check the document review outcome and reason.",
        invalid_screening_details: "Check the screening outcome and feedback.",
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

  function renderCandidates() {
    candidateList.replaceChildren();
    if (candidates.length === 0) {
      addText(candidateList, "li", "empty-state", "No applications are currently awaiting screening or correction.");
      return;
    }
    for (const application of candidates) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title", application.application_number);
      addText(content, "p", "notification-message",
        application.applicant.given_name + " " + application.applicant.family_name + " · " + application.program.name);
      addText(content, "p", "notification-meta",
        "Status: " + application.application_status.replaceAll("_", " ") +
        " · Documents: " + application.document_count +
        " · Required not accepted: " + application.unaccepted_required_document_count);
      const button = document.createElement("button");
      button.type = "button";
      button.className = "button secondary compact";
      button.textContent = "Review application";
      button.addEventListener("click", () => openApplication(application));
      content.append(button);
      row.append(content);
      candidateList.append(row);
    }
  }

  async function loadCandidates() {
    refreshButton.disabled = true;
    setStatus("Loading screening queue…");
    try {
      const response = await fetch(urls.candidates, {
        method: "GET", credentials: "same-origin", cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      candidates = Array.isArray(data.applications) ? data.applications : [];
      renderCandidates();
      workspace.hidden = false;
      setStatus(candidates.length === 1 ? "1 application in the screening queue." : candidates.length + " applications in the screening queue.", "success");
      if (selectedApplication) {
        const refreshed = candidates.find(item => item.application_id === selectedApplication.application_id);
        if (refreshed) {
          selectedApplication = refreshed;
          await loadDocuments(refreshed);
          updateDecisionAvailability();
        } else {
          selectedApplication = null;
          editor.hidden = true;
        }
      }
    } catch (error) {
      if (error.code === "access_denied") {
        workspace.hidden = true;
        return;
      }
      setStatus(error.message || "Could not load screening queue.", "error");
      if (error.code === "authentication_required") workspace.hidden = true;
    } finally {
      refreshButton.disabled = false;
    }
  }

  function updateDecisionAvailability() {
    if (!selectedApplication) {
      submitDecisionButton.disabled = true;
      return;
    }
    const eligibleReady = Number(selectedApplication.required_document_count) > 0 &&
      Number(selectedApplication.unaccepted_required_document_count) === 0;
    const outcomeIsEligible = decisionOutcome.value === "eligible";
    submitDecisionButton.disabled = outcomeIsEligible && !eligibleReady;
    if (outcomeIsEligible && !eligibleReady) {
      decisionNotes.setCustomValidity("");
      decisionNotes.placeholder = "Eligibility is blocked until every required document has been accepted.";
    } else {
      decisionNotes.placeholder = "Feedback visible to the applicant (optional).";
    }
  }

  async function loadDocuments(application) {
    const response = await fetch(urls.documents + "?application_id=" + encodeURIComponent(String(application.application_id)), {
      method: "GET", credentials: "same-origin", cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    const data = await readJson(response);
    documentList.replaceChildren();
    if (!Array.isArray(data.documents) || data.documents.length === 0) {
      addText(documentList, "li", "empty-state", "No evidence has been uploaded to this application.");
      return;
    }

    for (const document of data.documents) {
      const row = document.createElement("li");
      row.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      addText(content, "h4", "notification-title",
        document.document_type.replaceAll("_", " ") + " · version " + document.version_number);
      addText(content, "p", "notification-message", document.original_filename);
      addText(content, "p", "notification-meta",
        (Number(document.file_size_bytes) / 1048576).toFixed(2) + " MiB · " +
        document.review_status.replaceAll("_", " ") + " · " + document.uploaded_at);
      const download = document.createElement("a");
      download.className = "button secondary compact";
      download.href = urls.download + "?document_id=" + encodeURIComponent(String(document.document_id));
      download.textContent = "Download PDF";
      download.setAttribute("aria-label", "Download " + document.original_filename);
      content.append(download);

      if (document.review_status === "pending" &&
          ["submitted", "screening", "needs_correction"].includes(application.application_status)) {
        const actions = document.createElement("div");
        actions.className = "evaluation-actions";
        for (const [outcome, label] of [
          ["accepted", "Accept"],
          ["rejected", "Reject"],
          ["replacement_requested", "Request replacement"]
        ]) {
          const button = document.createElement("button");
          button.type = "button";
          button.className = outcome === "accepted" ? "button primary compact" : "button secondary compact";
          button.textContent = label;
          button.addEventListener("click", () => reviewDocument(document, outcome, button));
          actions.append(button);
        }
        content.append(actions);
      }
      row.append(content);
      documentList.append(row);
    }
  }

  async function openApplication(application) {
    selectedApplication = application;
    editor.hidden = false;
    editorTitle.textContent = "Screen " + application.application_number;
    editorSubtitle.textContent = application.applicant.given_name + " " + application.applicant.family_name +
      " · " + application.program.name + " · Status: " + application.application_status.replaceAll("_", " ");
    documentNotes.value = "";
    decisionNotes.value = "";
    decisionOutcome.value = "eligible";
    updateDecisionAvailability();
    setStatus("Loading evidence and current review states…");
    try {
      await loadDocuments(application);
      setStatus("Review each pending document. Only accepted latest versions count toward eligibility.", "success");
      editor.scrollIntoView({ behavior: "smooth", block: "start" });
    } catch (error) {
      setStatus(error.message || "Could not load application evidence.", "error");
    }
  }

  async function reviewDocument(document, outcome, button) {
    if (!selectedApplication || !csrfToken || busy) return;
    const notes = documentNotes.value.trim();
    if (outcome !== "accepted" && !notes) {
      documentNotes.focus();
      setStatus("Enter an applicant-facing reason before rejecting a document or requesting a replacement.", "error");
      return;
    }
    const confirmMessage = outcome === "accepted"
      ? "Accept this document version?"
      : outcome === "rejected"
        ? "Reject this document version and return the application for correction?"
        : "Request a replacement and return the application for correction?";
    if (!window.confirm(confirmMessage)) return;

    busy = true;
    button.disabled = true;
    refreshButton.disabled = true;
    setStatus("Saving document review…");
    try {
      const response = await fetch(urls.reviewDocument, {
        method: "POST", credentials: "same-origin", cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({
          document_id: document.document_id,
          outcome,
          notes
        })
      });
      await readJson(response);
      await loadCandidates();
      documentNotes.value = "";
      setStatus("Document review recorded and applicant notified.", "success");
    } catch (error) {
      setStatus(error.message || "Could not save document review.", "error");
    } finally {
      busy = false;
      refreshButton.disabled = false;
    }
  }

  decisionOutcome.addEventListener("change", updateDecisionAvailability);

  decisionForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!selectedApplication || !csrfToken || busy) return;
    if (decisionOutcome.value === "eligible" &&
        (Number(selectedApplication.required_document_count) < 1 ||
         Number(selectedApplication.unaccepted_required_document_count) > 0)) {
      updateDecisionAvailability();
      setStatus("Eligibility requires active required-document configuration and every latest required PDF to be accepted.", "error");
      return;
    }
    if (decisionOutcome.value !== "eligible" && !decisionNotes.value.trim()) {
      decisionNotes.focus();
      setStatus("Provide applicant-facing feedback for a correction or ineligibility outcome.", "error");
      return;
    }
    if (!window.confirm("Record this screening outcome? The applicant will receive the feedback you enter.")) return;

    busy = true;
    submitDecisionButton.disabled = true;
    refreshButton.disabled = true;
    setStatus("Recording screening outcome…");
    const applicationId = selectedApplication.application_id;
    try {
      const response = await fetch(urls.screenApplication, {
        method: "POST", credentials: "same-origin", cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({
          application_id: applicationId,
          outcome: decisionOutcome.value,
          notes: decisionNotes.value.trim()
        })
      });
      const data = await readJson(response);
      await loadCandidates();
      editor.hidden = true;
      selectedApplication = null;
      setStatus("Screening outcome recorded: " + data.screening.application_status.replaceAll("_", " ") + ".", "success");
    } catch (error) {
      setStatus(error.message || "Could not record screening outcome.", "error");
    } finally {
      busy = false;
      refreshButton.disabled = false;
      updateDecisionAvailability();
    }
  });

  byId("close-screening-editor").addEventListener("click", () => {
    selectedApplication = null;
    editor.hidden = true;
  });
  refreshButton.addEventListener("click", loadCandidates);

  document.addEventListener("equipath:authenticated", async () => {
    signedIn = true;
    try {
      await refreshCsrf();
      await loadCandidates();
    } catch (error) {
      if (error.code === "access_denied") workspace.hidden = true;
      else setStatus(error.message || "Could not initialize screening workspace.", "error");
    }
  });

  document.addEventListener("equipath:signed-out", () => {
    signedIn = false;
    busy = false;
    csrfToken = null;
    candidates = [];
    selectedApplication = null;
    workspace.hidden = true;
    candidateList.replaceChildren();
    documentList.replaceChildren();
    documentNotes.value = "";
    decisionNotes.value = "";
    editor.hidden = true;
  });
})();
