(() => {
  "use strict";

  const urls = {
    reports: "../api/reports.php",
    export: "../api/export-report.php"
  };
  const byId = (id) => document.getElementById(id);
  const workspace = byId("reports-workspace");
  const form = byId("reports-filter-form");
  const fromInput = byId("reports-from");
  const toInput = byId("reports-to");
  const refreshButton = byId("refresh-reports");
  const exportLink = byId("export-report");
  const status = byId("reports-status");
  const summary = byId("reports-summary");
  const sections = byId("reports-sections");

  let signedIn = false;
  let busy = false;

  function setStatus(message, kind = "") {
    status.textContent = message;
    status.dataset.kind = kind;
  }

  function localDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return year + "-" + month + "-" + day;
  }

  function setDefaultRange() {
    const today = new Date();
    const start = new Date(today);
    start.setDate(start.getDate() - 89);
    fromInput.value = localDate(start);
    toInput.value = localDate(today);
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
        access_denied: "This account is not authorized to view aggregate reports.",
        invalid_report_range: "Choose valid dates with the start on or before the end, no more than 366 days apart.",
        service_unavailable: "The reporting service is temporarily unavailable. Please try again later."
      };
      const error = new Error(messages[data?.error] || "The report could not be loaded.");
      error.code = data?.error;
      error.status = response.status;
      throw error;
    }
    return data;
  }

  function addText(parent, tag, className, value) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = value;
    parent.append(element);
    return element;
  }

  function buildQuery() {
    const params = new URLSearchParams();
    if (fromInput.value) params.set("from", fromInput.value);
    if (toInput.value) params.set("to", toInput.value);
    return params.toString();
  }

  function updateExportLink() {
    exportLink.href = urls.export + "?" + buildQuery();
  }

  function formatLabel(value) {
    return String(value).replaceAll("_", " ").replace(/[a-z]/g, letter => letter.toUpperCase());
  }

  function renderSummary(totals) {
    summary.replaceChildren();
    for (const [key, value] of Object.entries(totals || {})) {
      const card = document.createElement("div");
      card.className = "report-summary-card";
      addText(card, "p", "report-summary-label", formatLabel(key));
      addText(card, "p", "report-summary-value", Number(value).toLocaleString());
      summary.append(card);
    }
  }

  function renderTable(title, rows) {
    const section = document.createElement("section");
    section.className = "report-section";
    addText(section, "h4", "", title);
    if (!Array.isArray(rows) || rows.length === 0) {
      addText(section, "p", "empty-state", "No records for this period.");
      return section;
    }

    const table = document.createElement("table");
    table.className = "report-table";
    const header = document.createElement("thead");
    const headerRow = document.createElement("tr");
    const keys = Object.keys(rows[0]);
    for (const key of keys) addText(headerRow, "th", "", formatLabel(key));
    header.append(headerRow);
    table.append(header);

    const body = document.createElement("tbody");
    for (const row of rows) {
      const tr = document.createElement("tr");
      for (const key of keys) {
        const cell = document.createElement("td");
        const value = row[key] === null || row[key] === undefined ? "" : String(row[key]);
        cell.textContent = key === "total" ? Number(value).toLocaleString() : value;
        tr.append(cell);
      }
      body.append(tr);
    }
    table.append(body);
    section.append(table);
    return section;
  }

  function renderReport(report) {
    renderSummary(report.totals);
    sections.replaceChildren();
    const definitions = [
      ["Applications by status", "applications_by_status"],
      ["Applications by program", "applications_by_program"],
      ["Submissions by month", "submissions_by_month"],
      ["Screening outcomes", "screening_outcomes"],
      ["Evaluator assignments", "evaluator_assignments"],
      ["Assessment events", "assessment_events"],
      ["Accreditation decisions", "accreditation_decisions"]
    ];
    for (const [title, key] of definitions) {
      sections.append(renderTable(title, report[key]));
    }

    const credits = document.createElement("section");
    credits.className = "report-section";
    addText(credits, "h4", "", "Approved credits");
    const creditList = document.createElement("dl");
    for (const [key, value] of Object.entries(report.approved_credits || {})) {
      addText(creditList, "dt", "", formatLabel(key));
      addText(creditList, "dd", "", key === "total_credits" ? String(value) : Number(value).toLocaleString());
    }
    credits.append(creditList);
    sections.append(credits);
  }

  async function loadReports() {
    if (!signedIn || busy) return;
    if (!fromInput.value || !toInput.value) {
      setStatus("Choose both report dates.", "error");
      return;
    }
    busy = true;
    refreshButton.disabled = true;
    updateExportLink();
    setStatus("Building aggregate report…");
    try {
      const response = await fetch(urls.reports + "?" + buildQuery(), {
        method: "GET", credentials: "same-origin", cache: "no-store",
        headers: { "Accept": "application/json" }
      });
      const data = await readJson(response);
      renderReport(data.report);
      workspace.hidden = false;
      setStatus("Report generated for " + data.report.range.from + " through " + data.report.range.to + ".", "success");
    } catch (error) {
      setStatus(error.message || "Could not load reports.", "error");
      if (error.code === "access_denied" || error.code === "authentication_required") workspace.hidden = true;
    } finally {
      busy = false;
      refreshButton.disabled = false;
    }
  }

  form.addEventListener("submit", event => {
    event.preventDefault();
    loadReports();
  });
  fromInput.addEventListener("change", updateExportLink);
  toInput.addEventListener("change", updateExportLink);

  document.addEventListener("equipath:authenticated", async () => {
    signedIn = true;
    if (!fromInput.value || !toInput.value) setDefaultRange();
    updateExportLink();
    await loadReports();
  });

  document.addEventListener("equipath:signed-out", () => {
    signedIn = false;
    busy = false;
    workspace.hidden = true;
    summary.replaceChildren();
    sections.replaceChildren();
    status.textContent = "";
  });

  setDefaultRange();
  updateExportLink();
})();
