(() => {
  "use strict";

  const csrfUrl = "../api/csrf.php";
  const loginUrl = "../api/authenticate.php";
  const logoutUrl = "../api/logout.php";
  const notificationsUrl = "../api/notifications.php";
  const markReadUrl = "../api/mark-notification-read.php";

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

  let csrfToken = null;
  let busy = false;

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
      throw new Error(messages[data?.error] || "The request could not be completed. Please try again.");
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
    loginButton.textContent = value && loginForm.checkValidity() ? "Please wait…" : "Sign in";
  }

  function formatDate(value) {
    if (typeof value !== "string") return "Date unavailable";
    const date = new Date(value.replace(" ", "T") + (/[zZ]|[+-]\d{2}:?\d{2}$/.test(value) ? "" : "Z"));
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
      setStatus(loginStatus, "You have signed out.", "success");
      await refreshCsrfToken();
    } catch (error) {
      setStatus(notificationStatus, error.message || "Sign-out failed.", "error");
    } finally {
      setBusy(false);
    }
  });

  refreshButton.addEventListener("click", loadNotifications);

  refreshCsrfToken().catch((error) => {
    setStatus(loginStatus, error.message || "Sign-in is currently unavailable.", "error");
  });
})();
