(() => {
  "use strict";

  const byId = (id) => document.getElementById(id);
  const workspace = byId("registrar-workspace");
  if (!workspace) return;

  const status = byId("registrar-status");
  const importForm = byId("registrar-import-form");
  const previewButton = byId("preview-registrar-import");
  const commitButton = byId("commit-registrar-import");
  const previewPanel = byId("registrar-preview-panel");
  const previewSummary = byId("registrar-preview-summary");
  const previewBody = byId("registrar-preview-body");
  const historyList = byId("registrar-batch-history");
  const exportLink = byId("export-registrar-credits");
  const exportFrom = byId("registrar-export-from");
  const exportTo = byId("registrar-export-to");

  let csrfToken = null;
  let previewToken = null;
  let busy = false;

  function setStatus(message, kind = "") {
    status.textContent = message;
    status.dataset.kind = kind;
  }

  async function readJson(response) {
    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error("The server returned an unreadable response.");
    }
    if (!response.ok || data?.ok !== true) {
      const messages = {
        authentication_required: "Your session expired. Sign in again.",
        access_denied: "This account is not authorized for registrar integration.",
        csrf_validation_failed: "The security token expired. Refresh the page and try again.",
        invalid_import_file: "Choose a valid CSV file no larger than 2 MiB.",
        invalid_import_file_or_rows: "The CSV format or one or more rows are invalid.",
        preview_missing_expired_or_invalid: "This preview expired or is no longer valid. Preview the CSV again.",
        invalid_import_rows: "The import rows are invalid. Preview the CSV again.",
        invalid_report_range: "Choose a valid export date range.",
        service_unavailable: "The service is temporarily unavailable. Please try again later."
      };
      const error = new Error(messages[data?.error] || "The request could not be completed.");
      error.code = data?.error;
      error.status = response.status;
      throw error;
    }
    return data;
  }

  async function ensureCsrfToken() {
    const response = await fetch("../api/csrf.php", {
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" }
    });
    const data = await readJson(response);
    if (typeof data.csrf_token !== "string" || data.csrf_token.length < 32) {
      throw new Error("Could not establish a valid security token. Refresh the page.");
    }
    csrfToken = data.csrf_token;
    return csrfToken;
  }

  function appendCell(row, value) {
    const cell = document.createElement("td");
    cell.textContent = value == null ? "" : String(value);
    row.append(cell);
  }

  function renderPreview(rows) {
    previewBody.replaceChildren();
    for (const item of rows) {
      const row = document.createElement("tr");
      appendCell(row, item.row_number);
      appendCell(row, item.external_record_id);
      appendCell(row, item.application_number);
      appendCell(row, [item.course_code, item.course_title].filter(Boolean).join(" — "));
      appendCell(row, item.approved_credits);
      appendCell(row, item.status);
      appendCell(row, item.reason);
      previewBody.append(row);
    }
    previewPanel.hidden = false;
  }

  async function previewImport(event) {
    event.preventDefault();
    if (busy) return;
    const file = byId("registrar-csv-file").files?.[0];
    const sourceSystem = byId("registrar-source-system").value.trim();
    if (!file || !sourceSystem) {
      setStatus("Choose a CSV file and enter the source system identifier.", "error");
      return;
    }
    if (file.size < 1 || file.size > 2 * 1024 * 1024) {
      setStatus("The CSV must be between 1 byte and 2 MiB.", "error");
      return;
    }

    busy = true;
    previewButton.disabled = true;
    commitButton.disabled = true;
    previewToken = null;
    commitButton.hidden = true;
    previewPanel.hidden = true;
    setStatus("Validating and reconciling the CSV…");
    try {
      await ensureCsrfToken();
      const form = new FormData();
      form.append("source_system", sourceSystem);
      form.append("file", file);
      const response = await fetch("../api/registrar-import-preview.php", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json", "X-CSRF-Token": csrfToken },
        body: form
      });
      const data = await readJson(response);
      // Inputs can change while the request is in flight. Never bind a token
      // to a form that no longer matches the file and source actually previewed.
      if (byId("registrar-csv-file").files?.[0] !== file ||
          byId("registrar-source-system").value.trim() !== sourceSystem) {
        previewToken = null;
        commitButton.hidden = true;
        commitButton.disabled = true;
        previewPanel.hidden = true;
        setStatus("The CSV or source system changed while previewing. Preview the current inputs again.", "error");
        return;
      }
      if (typeof data.preview_token !== "string" || !/^[a-f0-9]{64}$/.test(data.preview_token)) {
        throw new Error("The server did not return a valid preview token. Preview the CSV again.");
      }
      previewToken = data.preview_token;
      renderPreview(Array.isArray(data.rows) ? data.rows : []);
      const counts = data.counts || {};
      previewSummary.textContent =
        "Ready: " + (counts.ready || 0) +
        " · Already mapped: " + (counts.already_mapped || 0) +
        " · Conflicts: " + (counts.conflict || 0) +
        " · Invalid: " + (counts.invalid || 0) +
        " · Preview expires in " + (data.preview_expires_in_seconds || 900) + " seconds.";
      commitButton.hidden = !(data.commit_allowed === true && typeof previewToken === "string");
      setStatus("Preview complete. Review every row before committing mappings.", "success");
    } catch (error) {
      setStatus(error.message || "Could not preview this import.", "error");
    } finally {
      busy = false;
      previewButton.disabled = false;
    }
  }

  async function commitImport() {
    if (busy || !previewToken) return;
    if (!window.confirm("Commit only the mappings shown in this reviewed preview? This will not change accreditation decisions or approved credit totals.")) return;

    busy = true;
    commitButton.disabled = true;
    previewButton.disabled = true;
    setStatus("Committing the reviewed mappings…");
    try {
      await ensureCsrfToken();
      const response = await fetch("../api/registrar-import-commit.php", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({ preview_token: previewToken })
      });
      const data = await readJson(response);
      previewToken = null;
      commitButton.hidden = true;
      const batch = data.batch || {};
      setStatus("Import recorded. Accepted: " + (batch.accepted_rows || 0) +
        "; rejected: " + (batch.rejected_rows || 0) + ".", "success");
      await loadHistory();
    } catch (error) {
      setStatus(error.message || "Could not commit the import.", "error");
      if (error.code === "preview_missing_expired_or_invalid") {
        previewToken = null;
        commitButton.hidden = true;
      }
    } finally {
      busy = false;
      commitButton.disabled = false;
      previewButton.disabled = false;
    }
  }

  function renderHistory(batches) {
    historyList.replaceChildren();
    if (!Array.isArray(batches) || batches.length === 0) {
      const item = document.createElement("li");
      item.className = "empty-state";
      item.textContent = "No registrar integration batches have been recorded.";
      historyList.append(item);
      return;
    }
    for (const batch of batches) {
      const item = document.createElement("li");
      item.className = "notification-item";
      const content = document.createElement("div");
      content.className = "notification-content";
      const heading = document.createElement("h4");
      heading.textContent = String(batch.source_system || "Unknown source") + " · " + String(batch.batch_status || "Unknown status");
      const detail = document.createElement("p");
      detail.textContent = "Rows: " + batch.total_rows + " · Accepted: " + batch.accepted_rows +
        " · Rejected: " + batch.rejected_rows + " · New mappings: " + batch.new_mapping_count +
        " · Created: " + (batch.created_at || "unknown");
      content.append(heading, detail);
      item.append(content);
      historyList.append(item);
    }
  }

  async function loadHistory() {
    try {
      const response = await fetch("../api/registrar-integration.php", {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" }
      });
      const data = await readJson(response);
      renderHistory(data.batches);
      workspace.hidden = false;
    } catch (error) {
      historyList.replaceChildren();
      const item = document.createElement("li");
      item.className = "empty-state";
      item.textContent = error.message || "Could not load registrar integration history.";
      historyList.append(item);
      if (error.code === "access_denied" || error.code === "authentication_required") workspace.hidden = true;
    }
  }

  function updateExportUrl() {
    const params = new URLSearchParams();
    if (exportFrom.value) params.set("from", exportFrom.value);
    if (exportTo.value) params.set("to", exportTo.value);
    const query = params.toString();
    exportLink.href = "../api/export-registrar-credits.php" + (query ? "?" + query : "");
  }

  function invalidatePreview() {
    if (!previewToken) return;
    previewToken = null;
    commitButton.hidden = true;
    commitButton.disabled = true;
    previewPanel.hidden = true;
    setStatus("The CSV or source system changed. Preview it again before committing.", "error");
  }

  byId("registrar-csv-file").addEventListener("change", invalidatePreview);
  byId("registrar-source-system").addEventListener("input", invalidatePreview);
  importForm.addEventListener("submit", previewImport);
  commitButton.addEventListener("click", commitImport);
  byId("refresh-registrar-history").addEventListener("click", loadHistory);
  exportFrom.addEventListener("change", updateExportUrl);
  exportTo.addEventListener("change", updateExportUrl);

  document.addEventListener("equipath:authenticated", () => {
    previewToken = null;
    commitButton.hidden = true;
    previewPanel.hidden = true;
    loadHistory();
  });
  document.addEventListener("equipath:signed-out", () => {
    previewToken = null;
    commitButton.hidden = true;
    previewPanel.hidden = true;
    historyList.replaceChildren();
  });
  updateExportUrl();
})();
