(() => {
  const { createClient } = supabase;
  const cfg = window.SA_CONFIG;
  const sb = createClient(cfg.supabaseUrl, cfg.supabaseAnonKey);
  const app = document.getElementById("app");
  const NAV = [
    ["overview", "Dashboard"],
    ["users", "Users"],
    ["plans", "Plans"],
    ["downloads", "Downloads"],
    ["tokens", "Install tokens"],
    ["proxy", "Proxies"],
    ["activity", "Activity"],
    ["trash", "Trash"],
  ];

  let flash = null;
  let issuedToken = null;
  let page = "overview";

  const esc = (v) =>
    String(v ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");

  const day = (v) => {
    if (!v) return "—";
    const d = new Date(v);
    return Number.isNaN(d.getTime()) ? "—" : d.toISOString().slice(0, 10);
  };

  const when = (v) => {
    if (!v) return "—";
    const d = new Date(v);
    return Number.isNaN(d.getTime())
      ? "—"
      : d.toISOString().slice(0, 16).replace("T", " ");
  };

  const pill = (status) => {
    const s = String(status || "");
    let cls = "";
    if (["active", "confirmed", "approved"].includes(s)) cls = "ok";
    else if (["pending", "wipe pending"].includes(s)) cls = "warn";
    else if (
      ["rejected", "unconfirmed", "revoked", "banned", "blocked", "none", "used"].includes(s)
    )
      cls = "bad";
    return `<span class="pill ${cls}">${esc(s)}</span>`;
  };

  async function sha256Hex(text) {
    const data = new TextEncoder().encode(text);
    const buf = await crypto.subtle.digest("SHA-256", data);
    return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("");
  }

  function newInstallToken() {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    return (
      "sa_" +
      [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("")
    );
  }

  function tokenHint(token) {
    return "sa_…" + token.slice(-6);
  }

  function isoAfter(months) {
    const d = new Date();
    d.setUTCMonth(d.getUTCMonth() + months);
    return d.toISOString().replace(/\.\d{3}Z$/, "Z");
  }

  function isoNow() {
    return new Date().toISOString().replace(/\.\d{3}Z$/, "Z");
  }

  function planIsLive(plan) {
    if ((plan.status || "") !== "active") return false;
    if (!plan.expires_at) return true;
    return new Date(plan.expires_at).getTime() > Date.now();
  }

  async function rows(path) {
    const url = `${cfg.supabaseUrl}/rest/v1/${path}`;
    const session = (await sb.auth.getSession()).data.session;
    const r = await fetch(url, {
      headers: {
        apikey: cfg.supabaseAnonKey,
        Authorization: `Bearer ${session?.access_token || cfg.supabaseAnonKey}`,
        Accept: "application/json",
      },
    });
    if (!r.ok) throw new Error(await r.text());
    const json = await r.json();
    return Array.isArray(json) ? json : [];
  }

  async function write(method, path, body, prefer = "return=minimal") {
    const session = (await sb.auth.getSession()).data.session;
    const r = await fetch(`${cfg.supabaseUrl}/rest/v1/${path}`, {
      method,
      headers: {
        apikey: cfg.supabaseAnonKey,
        Authorization: `Bearer ${session?.access_token || cfg.supabaseAnonKey}`,
        "Content-Type": "application/json",
        Prefer: prefer,
        Accept: "application/json",
      },
      body: body == null ? undefined : JSON.stringify(body),
    });
    if (!r.ok) throw new Error((await r.text()) || "Save failed");
    const text = await r.text();
    return text ? JSON.parse(text) : null;
  }

  async function rpc(name, args) {
    const session = (await sb.auth.getSession()).data.session;
    const r = await fetch(`${cfg.supabaseUrl}/rest/v1/rpc/${name}`, {
      method: "POST",
      headers: {
        apikey: cfg.supabaseAnonKey,
        Authorization: `Bearer ${session?.access_token || cfg.supabaseAnonKey}`,
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(args || {}),
    });
    const text = await r.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      data = text;
    }
    if (!r.ok) throw new Error(typeof data === "string" ? data : JSON.stringify(data));
    return data;
  }

  async function audit(action, targetUser, detail) {
    const session = (await sb.auth.getSession()).data.session;
    if (!session?.user?.id) return;
    try {
      await write("POST", "sa_audit", [
        {
          actor_id: session.user.id,
          action,
          target_user: targetUser,
          detail,
        },
      ]);
    } catch {
      /* optional */
    }
  }

  function setFlash(kind, message) {
    flash = { kind, message };
  }

  function shell(title, bodyHtml, email) {
    const nav = NAV.map(
      ([id, label]) =>
        `<a href="#${id}" class="${id === page ? "active" : ""}">${esc(label)}</a>`
    ).join("");
    const f = flash
      ? `<div class="${flash.kind === "error" ? "error" : "okbox"}">${esc(flash.message)}</div>`
      : "";
    flash = null;
    const tokenBox = issuedToken
      ? `<div class="okbox">Install token (copy now): <code id="issued">${esc(issuedToken)}</code>
         <button class="btn small" type="button" onclick="navigator.clipboard.writeText(document.getElementById('issued').textContent)">Copy</button></div>`
      : "";
    issuedToken = null;
    return `<div class="shell"><aside class="side">
      <div class="brand-lockup"><img class="brand-logo" src="assets/logo.png" alt="SA Browser" width="40" height="40" style="width:40px;height:40px;object-fit:contain">
      <div><strong>SA Browser</strong><span>Admin</span></div></div>
      <nav class="nav">${nav}</nav>
      <div class="side-foot"><div>${esc(email)}</div>
      <a class="signout" href="#logout">Sign out</a></div></aside>
      <main class="main">${f}${tokenBox}${bodyHtml}</main></div>`;
  }

  function loginView(error = "", email = "") {
    document.title = "Sign in · SA Browser Admin";
    app.innerHTML = `<div class="login-screen">
      <section class="login-brand">
        <img class="brand-logo" src="assets/logo.png" alt="SA Browser" width="40" height="40" style="width:40px;height:40px;object-fit:contain;display:block">
        <h1>SA Browser Admin</h1>
        <p>Accounts, plans, and install tokens for the desktop app. Now on GitHub Pages.</p>
      </section>
      <section class="login-card-wrap">
        <form class="login-card" id="login-form">
          <h2>Sign in</h2>
          <p class="muted">Admin email only.</p>
          <label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="username" value="${esc(email)}" required>
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
          ${error ? `<div class="error">${esc(error)}</div>` : ""}
          <div style="margin-top:18px"><button class="btn copper" type="submit">Enter admin</button></div>
        </form>
      </section>
    </div>`;
    document.getElementById("login-form").onsubmit = async (e) => {
      e.preventDefault();
      const em = document.getElementById("email").value.trim();
      const pw = document.getElementById("password").value;
      const { data, error: err } = await sb.auth.signInWithPassword({ email: em, password: pw });
      if (err || !data.session) {
        loginView(err?.message || "Sign in failed", em);
        return;
      }
      if (!data.user?.email_confirmed_at) {
        await sb.auth.signOut();
        loginView("Confirm your email before opening the admin panel.", em);
        return;
      }
      try {
        const ok = await rpc("sa_is_admin", {});
        if (ok !== true) {
          await sb.auth.signOut();
          loginView("This account is not an SA Browser admin.", em);
          return;
        }
      } catch (ex) {
        await sb.auth.signOut();
        loginView(String(ex.message || ex), em);
        return;
      }
      location.hash = "overview";
      route();
    };
  }

  async function requireAdmin() {
    const { data } = await sb.auth.getSession();
    if (!data.session) return null;
    try {
      const ok = await rpc("sa_is_admin", {});
      if (ok !== true) {
        await sb.auth.signOut();
        return null;
      }
    } catch {
      await sb.auth.signOut();
      return null;
    }
    return data.session;
  }

  async function renderOverview(session) {
    let error = "";
    let counts = { users: 0, pending: 0, live: 0, tokens: 0, downloads: 0 };
    let pending = [];
    let downloadPending = [];
    try {
      const accounts = await rows("sa_accounts?select=id,email&order=created_at.desc");
      const plans = await rows(
        "sa_plans?select=id,user_id,plan,status,requested_at,expires_at&order=requested_at.desc"
      );
      const tokens = await rows("sa_install_tokens?select=id,revoked,used_at,use_count,max_uses");
      let dlReqs = [];
      try {
        dlReqs = await rows(
          "sa_download_requests?select=id,user_id,email,status,created_at&status=eq.pending&order=created_at.desc&limit=8"
        );
      } catch {
        dlReqs = [];
      }
      const emails = Object.fromEntries(accounts.map((a) => [a.id, a.email || ""]));
      pending = plans
        .filter((p) => p.status === "pending")
        .map((p) => ({ ...p, email: emails[p.user_id] || p.user_id }))
        .slice(0, 6);
      counts.users = accounts.length;
      counts.pending = plans.filter((p) => p.status === "pending").length;
      counts.live = plans.filter(planIsLive).length;
      counts.tokens = tokens.filter(
        (t) => !t.revoked && !t.used_at && (t.use_count || 0) < (t.max_uses || 1)
      ).length;
      downloadPending = dlReqs.map((r) => ({
        ...r,
        email: r.email || emails[r.user_id] || r.user_id,
      }));
      counts.downloads = downloadPending.length;
    } catch (e) {
      error = String(e.message || e);
    }

    const dlHtml = downloadPending.length
      ? downloadPending
          .map(
            (req) => `<div class="spread" style="padding:10px 0;border-top:1px solid var(--line)">
        <div><strong>${esc(req.email)}</strong><div class="muted">requested ${esc(day(req.created_at))}</div></div>
        <div class="row">
          <button class="btn small copper" data-act="approve_download" data-request="${esc(req.id)}" data-user="${esc(req.user_id)}">Approve + token</button>
          <button class="btn small danger" data-act="reject_download" data-request="${esc(req.id)}" data-user="${esc(req.user_id)}">Reject</button>
        </div></div>`
          )
          .join("")
      : `<p class="empty">No pending download requests from the portal.</p>`;

    const planHtml = pending.length
      ? pending
          .map(
            (plan) => `<div class="spread" style="padding:10px 0;border-top:1px solid var(--line)">
        <div><strong>${esc(plan.email)}</strong><div class="muted">${esc(plan.plan)} · requested ${esc(day(plan.requested_at))}</div></div>
        <div class="row">
          <button class="btn small copper" data-act="approve" data-plan="${esc(plan.id)}" data-user="${esc(plan.user_id)}" data-kind="${esc(plan.plan)}">Approve</button>
          <button class="btn small danger" data-act="reject" data-plan="${esc(plan.id)}" data-user="${esc(plan.user_id)}" data-kind="${esc(plan.plan)}">Reject</button>
        </div></div>`
          )
          .join("")
      : `<p class="empty">No pending plan requests.</p>`;

    app.innerHTML = shell(
      "Dashboard",
      `<div class="page-head"><h1>Dashboard</h1>
      <p class="muted">Strict flow: portal signup → request download → approve (+ token) → user downloads.</p></div>
      ${error ? `<div class="error">${esc(error)}</div>` : ""}
      <div class="stats">
        <div class="stat"><span>Users</span><b>${counts.users}</b></div>
        <div class="stat"><span>Pending plans</span><b>${counts.pending}</b></div>
        <div class="stat"><span>Pending downloads</span><b>${counts.downloads}</b></div>
        <div class="stat"><span>Unused tokens</span><b>${counts.tokens}</b></div>
      </div>
      <div class="grid-2">
        <div class="card"><div class="spread"><h3>Download / install requests</h3><a class="btn ghost small" href="#downloads">All pending</a></div>${dlHtml}</div>
        <div class="card"><div class="spread"><h3>Plan requests</h3><a class="btn ghost small" href="#plans">All pending</a></div>${planHtml}</div>
      </div>
      <div class="card" style="margin-top:16px"><h3>How access works</h3>
      <p>Portal signup/login → Request install → Admin approves → Download → NSIS token.</p>
      <a class="btn small" href="#downloads">Open downloads</a>
      <a class="btn small ghost" href="#users">Go to users</a></div>`,
      session.user.email
    );
    bindActions();
  }

  async function renderUsers(session) {
    let error = "";
    let accounts = [];
    try {
      accounts = await rows(
        "sa_accounts?select=id,email,email_confirmed,status,admin_note,created_at&order=created_at.desc"
      );
    } catch (e) {
      error = String(e.message || e);
    }
    const rowsHtml = accounts
      .map(
        (a) => `<tr>
      <td><strong>${esc(a.email)}</strong><div class="muted">${esc(a.id)}</div></td>
      <td>${a.email_confirmed ? pill("confirmed") : pill("unconfirmed")}</td>
      <td>${pill(a.status || "none")}</td>
      <td>${esc(day(a.created_at))}</td>
      <td class="row">
        <button class="btn small" data-act="issue" data-user="${esc(a.id)}">Token</button>
        <button class="btn small" data-act="grant_monthly" data-user="${esc(a.id)}">+1mo</button>
        <button class="btn small" data-act="grant_yearly" data-user="${esc(a.id)}">+1yr</button>
        <button class="btn small danger" data-act="block" data-user="${esc(a.id)}">Block</button>
        <button class="btn small" data-act="unblock" data-user="${esc(a.id)}">Unblock</button>
      </td></tr>`
      )
      .join("");
    app.innerHTML = shell(
      "Users",
      `<div class="page-head"><h1>Users</h1><p class="muted">Issue tokens, grant plans, block accounts.</p></div>
      ${error ? `<div class="error">${esc(error)}</div>` : ""}
      <div class="card"><table class="table"><thead><tr><th>Email</th><th>Email</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
      <tbody>${rowsHtml || `<tr><td colspan="5" class="empty">No users</td></tr>`}</tbody></table></div>`,
      session.user.email
    );
    bindActions();
  }

  async function renderPlans(session) {
    let error = "";
    let plans = [];
    let emails = {};
    try {
      const accounts = await rows("sa_accounts?select=id,email");
      emails = Object.fromEntries(accounts.map((a) => [a.id, a.email || ""]));
      plans = await rows(
        "sa_plans?select=id,user_id,plan,status,requested_at,expires_at,approved_at&order=requested_at.desc"
      );
    } catch (e) {
      error = String(e.message || e);
    }
    const body = plans
      .map((p) => {
        const actions =
          p.status === "pending"
            ? `<button class="btn small copper" data-act="approve" data-plan="${esc(p.id)}" data-user="${esc(p.user_id)}" data-kind="${esc(p.plan)}">Approve</button>
               <button class="btn small danger" data-act="reject" data-plan="${esc(p.id)}" data-user="${esc(p.user_id)}" data-kind="${esc(p.plan)}">Reject</button>`
            : "";
        return `<tr>
        <td>${esc(emails[p.user_id] || p.user_id)}</td>
        <td>${esc(p.plan)}</td>
        <td>${pill(p.status)}${planIsLive(p) ? " · live" : ""}</td>
        <td>${esc(day(p.requested_at))}</td>
        <td>${esc(day(p.expires_at))}</td>
        <td class="row">${actions}</td></tr>`;
      })
      .join("");
    app.innerHTML = shell(
      "Plans",
      `<div class="page-head"><h1>Plans</h1></div>
      ${error ? `<div class="error">${esc(error)}</div>` : ""}
      <div class="card"><table class="table"><thead><tr><th>User</th><th>Plan</th><th>Status</th><th>Requested</th><th>Expires</th><th></th></tr></thead>
      <tbody>${body || `<tr><td colspan="6" class="empty">No plans</td></tr>`}</tbody></table></div>`,
      session.user.email
    );
    bindActions();
  }

  async function renderDownloads(session) {
    let error = "";
    let reqs = [];
    let emails = {};
    try {
      const accounts = await rows("sa_accounts?select=id,email");
      emails = Object.fromEntries(accounts.map((a) => [a.id, a.email || ""]));
      reqs = await rows(
        "sa_download_requests?select=id,user_id,email,status,created_at,reviewed_at,admin_note&order=created_at.desc"
      );
    } catch (e) {
      error = String(e.message || e);
    }
    const body = reqs
      .map((r) => {
        const actions =
          r.status === "pending"
            ? `<button class="btn small copper" data-act="approve_download" data-request="${esc(r.id)}" data-user="${esc(r.user_id)}">Approve + token</button>
               <button class="btn small danger" data-act="reject_download" data-request="${esc(r.id)}" data-user="${esc(r.user_id)}">Reject</button>`
            : "";
        return `<tr>
        <td>${esc(r.email || emails[r.user_id] || r.user_id)}</td>
        <td>${pill(r.status)}</td>
        <td>${esc(day(r.created_at))}</td>
        <td>${esc(day(r.reviewed_at))}</td>
        <td class="row">${actions}</td></tr>`;
      })
      .join("");
    app.innerHTML = shell(
      "Downloads",
      `<div class="page-head"><h1>Downloads</h1><p class="muted">Approve portal install requests and issue one-time tokens.</p></div>
      ${error ? `<div class="error">${esc(error)}</div>` : ""}
      <div class="card"><table class="table"><thead><tr><th>User</th><th>Status</th><th>Requested</th><th>Reviewed</th><th></th></tr></thead>
      <tbody>${body || `<tr><td colspan="5" class="empty">No requests</td></tr>`}</tbody></table></div>`,
      session.user.email
    );
    bindActions();
  }

  async function renderTokens(session) {
    let error = "";
    let tokens = [];
    let emails = {};
    try {
      const accounts = await rows("sa_accounts?select=id,email");
      emails = Object.fromEntries(accounts.map((a) => [a.id, a.email || ""]));
      tokens = await rows(
        "sa_install_tokens?select=id,user_id,token_hint,label,revoked,used_at,use_count,max_uses,expires_at,created_at&order=created_at.desc"
      );
    } catch (e) {
      error = String(e.message || e);
    }
    const body = tokens
      .map((t) => {
        const used = t.used_at || (t.use_count || 0) >= (t.max_uses || 1);
        const status = t.revoked ? "revoked" : used ? "used" : "active";
        return `<tr>
        <td>${esc(emails[t.user_id] || t.user_id)}</td>
        <td><code>${esc(t.token_hint || "")}</code></td>
        <td>${esc(t.label || "")}</td>
        <td>${pill(status)}</td>
        <td>${esc(t.use_count || 0)}/${esc(t.max_uses || 1)}</td>
        <td>${esc(day(t.expires_at))}</td>
        <td>${
          !t.revoked && !used
            ? `<button class="btn small danger" data-act="revoke" data-token="${esc(t.id)}" data-user="${esc(t.user_id)}" data-hint="${esc(t.token_hint || "")}">Revoke</button>`
            : ""
        }</td></tr>`;
      })
      .join("");
    app.innerHTML = shell(
      "Install tokens",
      `<div class="page-head"><h1>Install tokens</h1></div>
      ${error ? `<div class="error">${esc(error)}</div>` : ""}
      <div class="card"><table class="table"><thead><tr><th>User</th><th>Hint</th><th>Label</th><th>Status</th><th>Uses</th><th>Expires</th><th></th></tr></thead>
      <tbody>${body || `<tr><td colspan="7" class="empty">No tokens</td></tr>`}</tbody></table></div>`,
      session.user.email
    );
    bindActions();
  }

  async function renderProxy(session) {
    let error = "";
    let providers = [];
    try {
      providers = await rows(
        "sa_proxy_providers?select=id,label,enabled,host,http_port,socks_port,username,password,api_key,notes,sort_order&order=sort_order.asc"
      );
    } catch (e) {
      error = String(e.message || e);
    }
    const cards = providers
      .map(
        (p) => `<div class="card" style="margin-bottom:12px">
      <form data-proxy-form data-id="${esc(p.id)}">
        <div class="spread"><h3>${esc(p.label || p.id)}</h3>
          <label><input type="checkbox" name="enabled" ${p.enabled ? "checked" : ""}> Enabled</label></div>
        <div class="grid-2">
          <label>Host<input name="host" value="${esc(p.host || "")}" required></label>
          <label>Username<input name="username" value="${esc(p.username || "")}" required></label>
          <label>HTTP port<input name="http_port" type="number" value="${esc(p.http_port || 8080)}" required></label>
          <label>SOCKS port<input name="socks_port" type="number" value="${esc(p.socks_port || 1080)}" required></label>
          <label>Password<input name="password" type="password" placeholder="leave blank to keep"></label>
          <label>API key<input name="api_key" type="password" placeholder="leave blank to keep"></label>
        </div>
        <div style="margin-top:12px"><button class="btn copper" type="submit">Save ${esc(p.id)}</button></div>
      </form></div>`
      )
      .join("");
    app.innerHTML = shell(
      "Proxies",
      `<div class="page-head"><h1>Proxies</h1></div>
      ${error ? `<div class="error">${esc(error)}</div>` : ""}
      ${cards || `<p class="empty">No proxy providers</p>`}`,
      session.user.email
    );
    document.querySelectorAll("[data-proxy-form]").forEach((form) => {
      form.onsubmit = async (e) => {
        e.preventDefault();
        const id = form.getAttribute("data-id");
        const fd = new FormData(form);
        try {
          const existing = await rows(
            `sa_proxy_providers?select=password,api_key&id=eq.${encodeURIComponent(id)}&limit=1`
          );
          const row = {
            host: String(fd.get("host") || "").trim(),
            username: String(fd.get("username") || "").trim(),
            http_port: Number(fd.get("http_port") || 8080),
            socks_port: Number(fd.get("socks_port") || 1080),
            enabled: fd.get("enabled") === "on",
          };
          const pw = String(fd.get("password") || "");
          const key = String(fd.get("api_key") || "");
          if (pw) row.password = pw;
          else if (existing[0]) row.password = existing[0].password;
          if (key) row.api_key = key;
          else if (existing[0]) row.api_key = existing[0].api_key;
          await write("PATCH", `sa_proxy_providers?id=eq.${encodeURIComponent(id)}`, row);
          await audit("proxy.save", null, { provider_id: id });
          setFlash("ok", `Saved ${id}`);
          route();
        } catch (ex) {
          setFlash("error", String(ex.message || ex));
          route();
        }
      };
    });
  }

  async function renderActivity(session) {
    let error = "";
    let rowsData = [];
    try {
      rowsData = await rows(
        "sa_audit?select=id,actor_id,action,target_user,detail,created_at&order=created_at.desc&limit=100"
      );
    } catch (e) {
      error = String(e.message || e);
    }
    const body = rowsData
      .map(
        (r) => `<tr>
      <td>${esc(when(r.created_at))}</td>
      <td>${esc(r.action)}</td>
      <td>${esc(r.target_user || "—")}</td>
      <td><code>${esc(JSON.stringify(r.detail || {}))}</code></td></tr>`
      )
      .join("");
    app.innerHTML = shell(
      "Activity",
      `<div class="page-head"><h1>Activity</h1></div>
      ${error ? `<div class="error">${esc(error)}</div>` : ""}
      <div class="card"><table class="table"><thead><tr><th>When</th><th>Action</th><th>Target</th><th>Detail</th></tr></thead>
      <tbody>${body || `<tr><td colspan="4" class="empty">No activity</td></tr>`}</tbody></table></div>`,
      session.user.email
    );
  }

  async function renderTrash(session) {
    let error = "";
    let accounts = [];
    try {
      accounts = await rows(
        "sa_accounts?select=id,email,status,ban_reason,updated_at&or=(status.eq.banned,status.eq.blocked)&order=updated_at.desc"
      );
    } catch (e) {
      error = String(e.message || e);
    }
    const body = accounts
      .map(
        (a) => `<tr>
      <td>${esc(a.email)}</td>
      <td>${pill(a.status)}</td>
      <td>${esc(a.ban_reason || "—")}</td>
      <td>${esc(day(a.updated_at))}</td>
      <td><button class="btn small" data-act="unblock" data-user="${esc(a.id)}">Unblock</button></td></tr>`
      )
      .join("");
    app.innerHTML = shell(
      "Trash",
      `<div class="page-head"><h1>Trash</h1><p class="muted">Banned / blocked accounts.</p></div>
      ${error ? `<div class="error">${esc(error)}</div>` : ""}
      <div class="card"><table class="table"><thead><tr><th>Email</th><th>Status</th><th>Reason</th><th>Updated</th><th></th></tr></thead>
      <tbody>${body || `<tr><td colspan="5" class="empty">Empty</td></tr>`}</tbody></table></div>`,
      session.user.email
    );
    bindActions();
  }

  async function handleAction(btn) {
    const act = btn.getAttribute("data-act");
    const userId = btn.getAttribute("data-user") || "";
    const planId = btn.getAttribute("data-plan") || "";
    const kind = btn.getAttribute("data-kind") || "monthly";
    const requestId = btn.getAttribute("data-request") || "";
    const tokenId = btn.getAttribute("data-token") || "";
    const hint = btn.getAttribute("data-hint") || "";
    const session = (await sb.auth.getSession()).data.session;
    try {
      if (act === "approve") {
        const expires = isoAfter(kind === "yearly" ? 12 : 1);
        await write("PATCH", `sa_plans?id=eq.${encodeURIComponent(planId)}`, {
          status: "active",
          approved_at: isoNow(),
          approved_by: session.user.id,
          expires_at: expires,
        });
        await audit("plan.approve", userId, { plan_id: planId, plan: kind, expires_at: expires });
        setFlash("ok", `Plan approved until ${day(expires)}.`);
      } else if (act === "reject") {
        await write("PATCH", `sa_plans?id=eq.${encodeURIComponent(planId)}`, {
          status: "rejected",
        });
        await audit("plan.reject", userId, { plan_id: planId, plan: kind });
        setFlash("ok", "Plan rejected.");
      } else if (act === "approve_download") {
        const accounts = await rows(
          `sa_accounts?select=id,email,email_confirmed,status&id=eq.${encodeURIComponent(userId)}&limit=1`
        );
        if (!accounts.length) throw new Error("User not found");
        const acc = accounts[0];
        if (!acc.email_confirmed) throw new Error("Cannot approve: user email not confirmed");
        if (acc.status === "banned" || acc.status === "blocked")
          throw new Error(`Cannot approve: account is ${acc.status}`);
        const token = newInstallToken();
        const ins = await write(
          "POST",
          "sa_install_tokens",
          [
            {
              user_id: userId,
              token_hash: await sha256Hex(token),
              token_hint: tokenHint(token),
              label: "download",
              expires_at: isoAfter(12),
              created_by: session.user.id,
              max_uses: 1,
              use_count: 0,
              used_at: null,
              download_request_id: requestId,
            },
          ],
          "return=representation"
        );
        const tokenRow = Array.isArray(ins) ? ins[0] : ins;
        await write("PATCH", `sa_download_requests?id=eq.${encodeURIComponent(requestId)}`, {
          status: "approved",
          reviewed_at: isoNow(),
          reviewed_by: session.user.id,
          install_token_id: tokenRow.id,
          updated_at: isoNow(),
        });
        await audit("download.approve", userId, {
          request_id: requestId,
          hint: tokenHint(token),
          max_uses: 1,
        });
        issuedToken = token;
        setFlash("ok", "Download approved. Copy the one-time install token now.");
        page = "downloads";
        location.hash = "downloads";
      } else if (act === "reject_download") {
        await write("PATCH", `sa_download_requests?id=eq.${encodeURIComponent(requestId)}`, {
          status: "rejected",
          reviewed_at: isoNow(),
          reviewed_by: session.user.id,
          updated_at: isoNow(),
        });
        await audit("download.reject", userId || null, { request_id: requestId });
        setFlash("ok", "Download request rejected.");
      } else if (act === "issue") {
        const accounts = await rows(
          `sa_accounts?select=id,email,email_confirmed,status&id=eq.${encodeURIComponent(userId)}&limit=1`
        );
        if (!accounts.length) throw new Error("User not found");
        const acc = accounts[0];
        if (!acc.email_confirmed) throw new Error("Cannot issue token: email not confirmed");
        if (acc.status === "banned" || acc.status === "blocked")
          throw new Error(`Cannot issue token: account is ${acc.status}`);
        const token = newInstallToken();
        await write("POST", "sa_install_tokens", [
          {
            user_id: userId,
            token_hash: await sha256Hex(token),
            token_hint: tokenHint(token),
            label: "desktop",
            expires_at: isoAfter(12),
            created_by: session.user.id,
            max_uses: 1,
            use_count: 0,
            used_at: null,
          },
        ]);
        await audit("token.issue", userId, { hint: tokenHint(token), label: "desktop", max_uses: 1 });
        issuedToken = token;
        setFlash("ok", "One-time install token created. Copy it now.");
      } else if (act === "revoke") {
        await write("PATCH", `sa_install_tokens?id=eq.${encodeURIComponent(tokenId)}`, {
          revoked: true,
        });
        await audit("token.revoke", userId || null, { token_id: tokenId, hint });
        setFlash("ok", "Token revoked.");
      } else if (act === "block") {
        await write("PATCH", `sa_accounts?id=eq.${encodeURIComponent(userId)}`, {
          status: "blocked",
        });
        await write(
          "PATCH",
          `sa_install_tokens?user_id=eq.${encodeURIComponent(userId)}`,
          { revoked: true }
        );
        await audit("user.block", userId, {});
        setFlash("ok", "User blocked.");
      } else if (act === "unblock") {
        await write("PATCH", `sa_accounts?id=eq.${encodeURIComponent(userId)}`, {
          status: "active",
          ban_reason: null,
        });
        await audit("user.unblock", userId, {});
        setFlash("ok", "User unblocked.");
      } else if (act === "grant_monthly" || act === "grant_yearly") {
        const planKind = act === "grant_yearly" ? "yearly" : "monthly";
        try {
          await rpc("sa_admin_grant_plan", {
            p_user_id: userId,
            p_plan: planKind,
          });
        } catch {
          const expires = isoAfter(planKind === "yearly" ? 12 : 1);
          await write("POST", "sa_plans", [
            {
              user_id: userId,
              plan: planKind,
              status: "active",
              approved_at: isoNow(),
              approved_by: session.user.id,
              expires_at: expires,
            },
          ]);
        }
        await audit("plan.grant", userId, { plan: planKind });
        setFlash("ok", `Granted ${planKind} plan.`);
      }
    } catch (ex) {
      setFlash("error", String(ex.message || ex));
    }
    route();
  }

  function bindActions() {
    document.querySelectorAll("[data-act]").forEach((btn) => {
      btn.onclick = () => handleAction(btn);
    });
  }

  async function route() {
    const hash = (location.hash || "#overview").replace(/^#/, "") || "overview";
    if (hash === "logout") {
      await sb.auth.signOut();
      location.hash = "login";
      loginView();
      return;
    }
    if (hash === "login") {
      loginView();
      return;
    }
    page = hash;
    const session = await requireAdmin();
    if (!session) {
      loginView();
      return;
    }
    document.title = `${NAV.find((n) => n[0] === page)?.[1] || "Admin"} · SA Browser Admin`;
    if (page === "overview") return renderOverview(session);
    if (page === "users") return renderUsers(session);
    if (page === "plans") return renderPlans(session);
    if (page === "downloads") return renderDownloads(session);
    if (page === "tokens") return renderTokens(session);
    if (page === "proxy") return renderProxy(session);
    if (page === "activity") return renderActivity(session);
    if (page === "trash") return renderTrash(session);
    page = "overview";
    return renderOverview(session);
  }

  window.addEventListener("hashchange", route);
  route();
})();
