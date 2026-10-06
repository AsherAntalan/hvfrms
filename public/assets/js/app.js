/* HVFRMS shared front-end helpers — Laravel version */
(function () {
  "use strict";

  // ---- Base URL + CSRF from <meta> (Laravel convention) ----
  const meta = (name) => {
    const el = document.querySelector(`meta[name="${name}"]`);
    return el ? el.getAttribute("content") : "";
  };
  window.HV = {
    base: "/",
    csrf: meta("csrf-token"),
  };

  // ---- fetch helper with CSRF + JSON ----
  window.hvPost = async function (url, data) {
    const body = new URLSearchParams(data || {});
    if (!body.has("_token")) body.append("_token", window.HV.csrf);
    const res = await fetch(url, {
      method: "POST",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": window.HV.csrf,
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body,
    });
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (e) {
      return { ok: false, message: "Unexpected server response.", raw: text };
    }
  };

  window.hvGet = async function (url) {
    const res = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } });
    return res.json();
  };

  // ---- Live alert badge polling ----
  async function refreshAlertBadge() {
    try {
      const r = await hvGet("/alerts/count");
      if (!r || !r.ok) return;
      document.querySelectorAll("[data-alert-badge]").forEach((el) => {
        el.textContent = r.count;
        el.style.display = r.count > 0 ? "" : "none";
      });
    } catch (e) {}
  }
  if (document.querySelector("[data-alert-badge]")) {
    setInterval(refreshAlertBadge, 60000);
  }

  // ---- Debounce for live search boxes ----
  window.hvDebounce = function (fn, wait) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait || 250);
    };
  };
})();
