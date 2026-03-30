/**
 * Injects Tickets / Board under CRM for is_crm_owner. Uses <button> (not <a>) so the
 * main TailAdmin Vue Router does not treat same-origin URLs as in-app navigation.
 * Re-injects after route changes (phase "done" no longer blocks when DOM is recreated).
 */
(function () {
  var loading = false;
  var ownerCached = null;

  function ticketsAppUrl(path) {
    var rel = "tickets-app/" + path.replace(/^\//, "");
    return new URL(rel, window.location.href).href;
  }

  var ICON_TICKET =
    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 0 0 5.197v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 0 0-5.197V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" /></svg>';
  var ICON_BOARD =
    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125Z" /></svg>';

  function apiBase() {
    var p = location.pathname;
    var mark = "/tickets-app";
    var i = p.indexOf(mark);
    if (i !== -1) {
      return p.slice(0, i) + "/api";
    }
    var dir = p.replace(/\/[^/]*$/, "") || "";
    return dir + "/api";
  }

  function token() {
    return localStorage.getItem("auth_token");
  }

  function findCrmNavList() {
    var aside =
      document.querySelector("aside.fixed.z-99999") ||
      document.querySelector("aside.fixed[class*='z-99999']") ||
      document.querySelector("aside.fixed");
    if (!aside) return null;
    var dash =
      aside.querySelector('a[href="/dashboard"]') ||
      aside.querySelector('a[href$="/dashboard"]') ||
      aside.querySelector('a.router-link-active[href*="dashboard"]');
    if (dash) {
      var ul = dash.closest("ul");
      if (ul) return ul;
    }
    var users =
      aside.querySelector('a[href="/users"]') ||
      aside.querySelector('a[href$="/users"]');
    if (users) {
      var ulU = users.closest("ul");
      if (ulU) return ulU;
    }
    var headings = aside.querySelectorAll("nav h2");
    for (var h = 0; h < headings.length; h++) {
      var h2 = headings[h];
      if (h2.textContent && h2.textContent.trim() === "CRM") {
        var ul2 = h2.nextElementSibling;
        if (ul2 && ul2.tagName === "UL") return ul2;
      }
    }
    return aside.querySelector("nav ul.flex.flex-col.gap-4");
  }

  function removeStaleInjected(ul) {
    ul.querySelectorAll("li[data-saverack-tickets-nav]").forEach(function (li) {
      li.remove();
    });
  }

  /** Button — avoids Vue Router hijacking <a href> to same host */
  function externalNavButton(href, label, iconSvg) {
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className =
      "menu-item group menu-item-inactive flex w-full items-center gap-3 text-left cursor-pointer border-0 bg-transparent p-0 font-inherit";
    btn.setAttribute("data-saverack-external", "1");

    var iconWrap = document.createElement("span");
    iconWrap.className = "menu-item-icon-inactive";
    iconWrap.innerHTML = iconSvg;

    var text = document.createElement("span");
    text.className = "menu-item-text";
    text.textContent = label;

    btn.appendChild(iconWrap);
    btn.appendChild(text);

    function go(e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      e.stopPropagation();
      window.location.assign(href);
    }
    btn.addEventListener("click", go, true);

    return btn;
  }

  async function inject() {
    if (loading) return;
    if (!token()) {
      ownerCached = null;
      return;
    }

    var ul = findCrmNavList();
    if (!ul) return;

    if (ul.querySelector("li[data-saverack-tickets-nav]")) {
      return;
    }

    if (ownerCached === false) {
      return;
    }

    if (ownerCached === null) {
      loading = true;
      try {
        var r = await fetch(apiBase() + "/auth/me", {
          headers: {
            Authorization: "Bearer " + token(),
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          credentials: "same-origin",
        });
        if (!r.ok) {
          ownerCached = null;
          return;
        }
        var me = await r.json();
        ownerCached = !!me.is_crm_owner;
      } catch (_) {
        ownerCached = null;
        return;
      } finally {
        loading = false;
      }
    }

    if (!ownerCached) {
      return;
    }

    loading = true;
    try {
      var ulNow = findCrmNavList();
      if (!ulNow) {
        return;
      }
      removeStaleInjected(ulNow);
      if (ulNow.querySelector("li[data-saverack-tickets-nav]")) {
        return;
      }

      var urlTickets = ticketsAppUrl("tickets");
      var urlBoard = ticketsAppUrl("tickets/board");

      function row(href, label, iconSvg, id) {
        var li = document.createElement("li");
        li.setAttribute("data-saverack-tickets-nav", "1");
        if (id) li.id = id;
        li.appendChild(externalNavButton(href, label, iconSvg));
        return li;
      }

      ulNow.appendChild(row(urlTickets, "Tickets", ICON_TICKET, "saverack-tickets-nav-items"));
      ulNow.appendChild(row(urlBoard, "Board", ICON_BOARD));
    } finally {
      loading = false;
    }
  }

  var obs = new MutationObserver(function () {
    inject();
  });
  obs.observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", inject);
  } else {
    inject();
  }
})();
