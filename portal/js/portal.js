(() => {
  const { createClient } = supabase;
  const cfg = window.SA_CONFIG;
  const sb = createClient(cfg.supabaseUrl, cfg.supabaseAnonKey);
  const app = document.getElementById("app");
  let flash = null;

  const esc = (v) =>
    String(v ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");

  function setFlash(kind, message) {
    flash = { kind, message };
  }

  function flashHtml() {
    if (!flash) return "";
    const html = `<div class="${flash.kind === "error" ? "error" : "ok"}">${esc(flash.message)}</div>`;
    flash = null;
    return html;
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

  function layout(title, body) {
    document.title = `${title} · ${cfg.siteName}`;
    app.innerHTML = `<div class="wrap">
      <header class="top">
        <div class="brand"><img src="assets/logo.png" alt="" width="32" height="32"><strong>${esc(cfg.siteName)}</strong></div>
        <nav>
          <a href="#download">Download</a>
          <a href="#login">Login</a>
          <a href="#signup">Sign up</a>
          <a href="#logout">Sign out</a>
        </nav>
      </header>
      <main>${flashHtml()}${body}</main>
    </div>`;
  }

  function loginView(error = "") {
    layout(
      "Login",
      `<section class="card">
      <h1>Sign in</h1>
      <form id="f">
        <label>Email<input type="email" id="email" required></label>
        <label>Password<input type="password" id="password" required></label>
        ${error ? `<div class="error">${esc(error)}</div>` : ""}
        <button type="submit">Sign in</button>
      </form>
      <p><a href="#signup">Create account</a></p>
    </section>`
    );
    document.getElementById("f").onsubmit = async (e) => {
      e.preventDefault();
      const email = document.getElementById("email").value.trim();
      const password = document.getElementById("password").value;
      const { error: err } = await sb.auth.signInWithPassword({ email, password });
      if (err) return loginView(err.message);
      location.hash = "download";
      route();
    };
  }

  function signupView(error = "", ok = "") {
    layout(
      "Sign up",
      `<section class="card">
      <h1>Create account</h1>
      <form id="f">
        <label>Email<input type="email" id="email" required></label>
        <label>Password<input type="password" id="password" minlength="6" required></label>
        ${error ? `<div class="error">${esc(error)}</div>` : ""}
        ${ok ? `<div class="ok">${esc(ok)}</div>` : ""}
        <button type="submit">Sign up</button>
      </form>
      <p><a href="#login">Already have an account</a></p>
    </section>`
    );
    document.getElementById("f").onsubmit = async (e) => {
      e.preventDefault();
      const email = document.getElementById("email").value.trim();
      const password = document.getElementById("password").value;
      const { error: err } = await sb.auth.signUp({ email, password });
      if (err) return signupView(err.message);
      signupView("", "Check your email to confirm, then sign in.");
    };
  }

  async function downloadView() {
    const { data } = await sb.auth.getSession();
    if (!data.session) {
      location.hash = "login";
      return loginView();
    }
    layout(
      "Download",
      `<section class="card">
      <h1>Install access</h1>
      <p>Signed in as ${esc(data.session.user.email)}. Request download access; an admin will approve and send a one-time token.</p>
      <form id="f">
        <label>Note (optional)<textarea id="note" rows="3"></textarea></label>
        <button type="submit">Request download access</button>
      </form>
    </section>`
    );
    document.getElementById("f").onsubmit = async (e) => {
      e.preventDefault();
      const note = document.getElementById("note").value.trim();
      try {
        const res = await rpc("sa_request_download", { p_note: note || null });
        if (!res || !res.ok) {
          const reason = res?.reason || "";
          const msg =
            reason === "email"
              ? "Confirm your email first, then request again."
              : reason === "banned" || reason === "blocked"
                ? "This account cannot request download access."
                : reason === "auth"
                  ? "Sign in again."
                  : "Could not create download request.";
          setFlash("error", msg);
        } else {
          setFlash(
            "ok",
            res.exists
              ? "You already have a pending request. Wait for admin approval."
              : "Download request sent. An admin will approve it."
          );
        }
      } catch (ex) {
        setFlash("error", String(ex.message || ex));
      }
      route();
    };
  }

  async function route() {
    const hash = (location.hash || "#download").replace(/^#/, "") || "download";
    if (hash === "logout") {
      await sb.auth.signOut();
      setFlash("ok", "Signed out.");
      location.hash = "login";
      return loginView();
    }
    if (hash === "login") return loginView();
    if (hash === "signup") return signupView();
    return downloadView();
  }

  window.addEventListener("hashchange", route);
  route();
})();
