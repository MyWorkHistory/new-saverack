/**
 * Client-side document title and meta tags for CRM routes.
 * Link previews in some apps only see the initial HTML; SPA updates help the tab and some crawlers.
 */

const DEFAULT_TITLE = "Save Rack";
const DEFAULT_DESC =
  "Save Rack CRM — manage staff, webmaster tasks, and operations.";

function ensureMeta(attr, key) {
  let el = document.querySelector(`meta[${attr}="${key}"]`);
  if (!el) {
    el = document.createElement("meta");
    el.setAttribute(attr, key);
    document.head.appendChild(el);
  }
  return el;
}

/**
 * @param {{ title?: string; description?: string }} opts
 */
export function setCrmPageMeta(opts = {}) {
  const title = opts.title || DEFAULT_TITLE;
  const description = opts.description || DEFAULT_DESC;
  document.title = title;
  ensureMeta("name", "description").setAttribute("content", description);
  ensureMeta("property", "og:title").setAttribute("content", title);
  ensureMeta("property", "og:description").setAttribute("content", description);
  ensureMeta("property", "og:type").setAttribute("content", "website");
  ensureMeta("name", "twitter:card").setAttribute("content", "summary");
}

/**
 * @param {import("vue-router").RouteLocationNormalizedLoaded} to
 */
export function applyRouteMeta(to) {
  const m = to.meta || {};
  setCrmPageMeta({
    title: typeof m.title === "string" ? m.title : DEFAULT_TITLE,
    description: typeof m.description === "string" ? m.description : DEFAULT_DESC,
  });
}
