const API = "api/index.php";
let csrfToken = "";
let currentUser = null;
let feedbackCache = [];

function esc(value) {
  return String(value ?? "").replace(/[&<>"']/g, character => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
  }[character]));
}

function statusClass(status) {
  return status === "Resolved" ? "resolved" : status === "Pending" ? "pending" : "review";
}

function showToast(message) {
  const toast = document.querySelector("#toast");
  if (!toast) return;
  toast.textContent = message;
  toast.style.display = "block";
  setTimeout(() => { toast.style.display = "none"; }, 3200);
}

function formatError(error) {
  showToast(error instanceof Error ? error.message : "Something went wrong.");
}

async function api(action, options = {}) {
  const method = options.method || "GET";
  const headers = { Accept: "application/json" };
  const request = { method, credentials: "same-origin", headers };
  const url = `${API}?action=${encodeURIComponent(action)}`;

  if (method !== "GET") {
    headers["X-CSRF-Token"] = csrfToken;
    if (options.data instanceof FormData) {
      request.body = options.data;
    } else {
      headers["Content-Type"] = "application/json";
      request.body = JSON.stringify(options.data || {});
    }
  }

  let response;
  try {
    response = await fetch(url, request);
  } catch {
    throw new Error("Cannot reach the PHP API. Open this site through XAMPP Apache.");
  }

  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error("The server returned an invalid response. Check that Apache is running PHP.");
  }
  if (result.csrfToken) csrfToken = result.csrfToken;
  if (!response.ok) throw new Error(result.error || "The request could not be completed.");
  return result;
}

function renderRecent(feedbacks) {
  const grid = document.querySelector("#feedbackGrid");
  if (!grid) return;
  grid.innerHTML = feedbacks.slice(0, 6).map(item => `
    <article class="feedback-card">
      <div class="person"><div class="avatar">👤</div><div><strong>${esc(item.name)}</strong><small>${esc(item.submitterRole)}</small></div></div>
      <div class="feedback-text">“${esc(item.message)}”</div>
      ${item.image ? `<div class="feedback-image-wrap"><img src="${esc(item.image)}" alt="Image attached to feedback"></div>` : ""}
      <div class="card-footer"><span>${esc(item.category)}</span><span>${esc(item.date)}</span></div>
      <div class="public-feedback-status"><span class="status ${statusClass(item.status)}">${esc(item.status)}</span></div>
      ${item.response ? `<div class="public-feedback-response"><strong>Admin response</strong><p>${esc(item.response)}</p></div>` : ""}
    </article>`).join("") || "<p>No feedback has been submitted yet.</p>";
}

function renderProfile(data) {
  const box = document.querySelector("#profileDetails");
  const table = document.querySelector("#profileHistory");
  if (!box || !table) return;
  const user = data.user;
  const identifier = user.institutional_email || user.institutional_id || "";
  box.innerHTML = `<div class="avatar-large">👤</div><p class="profile-label">PROFILE FOR</p><h2>${esc(user.full_name)}</h2>${identifier ? `<p>${esc(identifier)}</p>` : ""}<p>${esc(user.role)}</p><div class="status-list"><span class="status resolved">ACTIVE ACCOUNT</span></div><button class="ghost-btn" id="logout" type="button">LOG OUT</button>`;
  const rows = data.feedbacks.map(item => `<tr><td>${esc(item.message.slice(0, 55))}${item.message.length > 55 ? "..." : ""}</td><td>${esc(item.category)}</td><td><span class="status ${statusClass(item.status)}">${esc(item.status)}</span></td><td>${esc(item.date)}</td></tr>${item.response ? `<tr><td colspan="4"><strong>Admin response:</strong> ${esc(item.response)}</td></tr>` : ""}`).join("");
  table.innerHTML = rows ? `<div class="table-wrap"><table><thead><tr><th>Concern</th><th>Category</th><th>Status</th><th>Date</th></tr></thead><tbody>${rows}</tbody></table></div>` : "<p>No feedback submitted yet.</p>";
}

function renderAdmin(data) {
  ["pendingCount", "reviewCount", "resolvedCount", "totalCount"].forEach((id, index) => {
    const element = document.querySelector(`#${id}`);
    if (element) element.textContent = [data.counts.Pending, data.counts["Under Review"], data.counts.Resolved, data.total][index];
  });
  const list = document.querySelector("#issueList");
  if (!list) return;
  const category = document.querySelector("#adminCategory").value;
  const status = document.querySelector("#adminStatus").value;
  const filtered = data.feedbacks.filter(item =>
    (category === "All" || item.category === category) &&
    (status === "All" || item.status === status)
  );
  list.innerHTML = filtered.map(item => `
    <article class="issue">
      <div class="issue-top"><div><h4>${esc(item.name)} - ${esc(item.category)}${item.anonymous ? " (anonymous publicly)" : ""}</h4><small>${esc(item.date)} · ${esc(item.submitterRole)}</small></div><span class="status ${statusClass(item.status)}">${esc(item.status)}</span></div>
      <p>${esc(item.message)}</p>
      ${item.image ? `<img class="issue-image" src="${esc(item.image)}" alt="Attached feedback image">` : ""}
      <div class="issue-actions">
        <button class="ghost-btn" data-status="Pending" data-id="${Number(item.id)}">Pending</button>
        <button class="ghost-btn" data-status="Under Review" data-id="${Number(item.id)}">Under Review</button>
        <button class="primary-btn" data-status="Resolved" data-id="${Number(item.id)}" style="padding:9px 16px;outline:0;border:0">Resolved</button>
      </div>
      <div class="field" style="margin-top:12px"><label for="response-${Number(item.id)}">RESPONSE TO USER</label><textarea class="textarea" id="response-${Number(item.id)}" rows="3" maxlength="5000" placeholder="Write an update or resolution for this concern">${esc(item.response || "")}</textarea><button class="ghost-btn" data-response-id="${Number(item.id)}" style="margin-top:8px">SAVE RESPONSE</button></div>
    </article>`).join("") || '<div class="card"><p>No matching feedback.</p></div>';
}

function updateHomeForAdmin() {
  const subtitle = document.querySelector("#homeSubtitle");
  if (subtitle) subtitle.textContent = "Review concerns and support service improvement.";
  const action = document.querySelector("#homeAction");
  if (action) {
    action.href = "admin.html";
    action.textContent = "OPEN ADMIN DASHBOARD";
  }
  const labels = ["Review Incoming Reports", "Respond to Concerns", "Track Resolutions", "Improve ERHTCI Services"];
  document.querySelectorAll(".home-features .feature p").forEach((element, index) => {
    if (labels[index]) element.textContent = labels[index];
  });
}

async function loadFeedbacks() {
  const result = await api("feedbacks");
  feedbackCache = result.feedbacks;
  renderRecent(feedbackCache);
}

async function loadAdmin() {
  const result = await api("admin");
  feedbackCache = result.feedbacks;
  renderAdmin(result);
}

async function logout() {
  try {
    await api("logout", { method: "POST" });
    location.href = "login.html";
  } catch (error) {
    formatError(error);
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  const loginForm = document.querySelector("#loginForm");
  const signupForm = document.querySelector("#signupForm");
  const roleInput = document.querySelector("#role");
  const submitButtons = [...document.querySelectorAll('form button[type="submit"]')];
  submitButtons.forEach(button => { button.disabled = true; });

  try {
    const session = await api("bootstrap");
    csrfToken = session.csrfToken;
    currentUser = session.user;
  } catch (error) {
    formatError(error);
    return;
  }
  submitButtons.forEach(button => { button.disabled = false; });

  if (signupForm) {
    const idField = document.querySelector("#idNumberField");
    const idInput = document.querySelector("#idNumber");
    const emailField = document.querySelector("#emailField");
    const emailInput = document.querySelector("#email");
    const toggleFields = () => {
      const student = roleInput.value === "Student";
      idField.style.display = student ? "block" : "none";
      idInput.disabled = !student;
      idInput.required = student;
      emailField.style.display = student ? "none" : "block";
      emailInput.disabled = student;
      emailInput.required = !student;
    };
    roleInput.addEventListener("change", toggleFields);
    toggleFields();

    signupForm.addEventListener("submit", async event => {
      event.preventDefault();
      const password = document.querySelector("#password").value;
      if (password !== document.querySelector("#confirmPassword").value) {
        showToast("Passwords do not match.");
        return;
      }
      if (Array.from(password).length < 12 || new TextEncoder().encode(password).length > 72) {
        showToast("Use at least 12 characters and no more than 72 UTF-8 bytes for your password.");
        return;
      }
      const role = roleInput.value;
      try {
        const result = await api("register", {
          method: "POST",
          data: {
            fullName: document.querySelector("#fullName").value.trim(),
            role,
            identifier: role === "Student" ? idInput.value.trim() : emailInput.value.trim(),
            password
          }
        });
        showToast(result.message);
        setTimeout(() => { location.href = "login.html"; }, 700);
      } catch (error) {
        formatError(error);
      }
    });
  }

  if (loginForm) {
    loginForm.addEventListener("submit", async event => {
      event.preventDefault();
      try {
        const result = await api("login", {
          method: "POST",
          data: {
            identifier: document.querySelector("#loginIdentifier").value.trim(),
            password: document.querySelector("#accountPassword").value
          }
        });
        currentUser = result.user;
        if (result.csrfToken) csrfToken = result.csrfToken;
        location.href = currentUser.role === "Admin" ? "admin.html" : "index.html";
      } catch (error) {
        formatError(error);
      }
    });
  }

  if (loginForm && currentUser) {
    location.replace(currentUser.role === "Admin" ? "admin.html" : "index.html");
    return;
  }

  const isFeedbackPage = Boolean(document.querySelector("#feedbackGrid"));
  const isProfilePage = Boolean(document.querySelector("#profileDetails"));
  const isAdminPage = Boolean(document.querySelector("#issueList"));
  if ((isFeedbackPage || isProfilePage || isAdminPage) && !currentUser) {
    location.replace("login.html");
    return;
  }
  if (isAdminPage && currentUser.role !== "Admin") {
    location.replace("index.html");
    return;
  }
  if (isFeedbackPage && currentUser.role === "Admin") {
    location.replace("admin.html");
    return;
  }

  const nav = document.querySelector(".nav-links");
  if (nav && currentUser?.role === "Admin") {
    nav.querySelector('a[href="feedbacks.html"]')?.remove();
    if (!nav.querySelector('a[href="admin.html"]')) {
      const link = document.createElement("a");
      link.href = "admin.html";
      link.textContent = "ADMIN DASHBOARD";
      if (isAdminPage) link.classList.add("active");
      nav.insertBefore(link, nav.children[1] || null);
    }
  }
  if (currentUser?.role === "Admin") updateHomeForAdmin();
  if (isAdminPage && currentUser?.role === "Admin") {
    const welcome = document.querySelector("#adminWelcome");
    if (welcome) welcome.textContent = `Signed in as ${currentUser.full_name} (Admin).`;
  }

  if (isFeedbackPage) {
    try { await loadFeedbacks(); } catch (error) { formatError(error); }
  }
  if (isProfilePage) {
    try { renderProfile(await api("profile")); } catch (error) { formatError(error); }
  }
  if (isAdminPage) {
    try { await loadAdmin(); } catch (error) { formatError(error); }
  }

  const imageInput = document.querySelector("#feedbackImage");
  const fileNameLabel = document.querySelector("#fileName");
  imageInput?.addEventListener("change", () => {
    if (fileNameLabel) fileNameLabel.textContent = imageInput.files?.[0]?.name || "No file chosen";
  });

  const feedbackForm = document.querySelector("#feedbackForm");
  feedbackForm?.addEventListener("submit", async event => {
    event.preventDefault();
    const formData = new FormData();
    formData.set("message", document.querySelector("#comment").value.trim());
    formData.set("category", document.querySelector("#category").value);
    formData.set("anonymous", document.querySelector('input[name="identity"]:checked')?.value === "anonymous" ? "true" : "false");
    if (imageInput.files?.[0]) formData.set("image", imageInput.files[0]);
    try {
      const result = await api("submit-feedback", { method: "POST", data: formData });
      feedbackForm.reset();
      if (fileNameLabel) fileNameLabel.textContent = "No file chosen";
      await loadFeedbacks();
      showToast(result.message);
    } catch (error) {
      formatError(error);
    }
  });

  document.querySelector("#adminCategory")?.addEventListener("change", () => {
    if (feedbackCache.length) {
      const counts = { Pending: 0, "Under Review": 0, Resolved: 0 };
      feedbackCache.forEach(item => { counts[item.status]++; });
      renderAdmin({ counts, total: feedbackCache.length, feedbacks: feedbackCache });
    }
  });
  document.querySelector("#adminStatus")?.addEventListener("change", () => {
    if (feedbackCache.length) {
      const counts = { Pending: 0, "Under Review": 0, Resolved: 0 };
      feedbackCache.forEach(item => { counts[item.status]++; });
      renderAdmin({ counts, total: feedbackCache.length, feedbacks: feedbackCache });
    }
  });

  document.querySelector("#issueList")?.addEventListener("click", async event => {
    const statusButton = event.target.closest("[data-status][data-id]");
    const responseButton = event.target.closest("[data-response-id]");
    try {
      if (statusButton) {
        await api("status", { method: "POST", data: { id: Number(statusButton.dataset.id), status: statusButton.dataset.status } });
        await loadAdmin();
        showToast("Status updated.");
      } else if (responseButton) {
        const id = Number(responseButton.dataset.responseId);
        const response = document.querySelector(`#response-${id}`).value.trim();
        await api("response", { method: "POST", data: { id, response } });
        await loadAdmin();
        showToast("Response saved.");
      }
    } catch (error) {
      formatError(error);
    }
  });

  document.querySelector("#logout")?.addEventListener("click", logout);
});
