/**
 * Resolve paths like `/storage/...` for <img src> so they load from the Laravel app.
 *
 * - Production (same origin): uses the current page origin — `/storage/...` works.
 * - Vite dev on another port: set `VITE_APP_ORIGIN=http://127.0.0.1:8000` (no trailing slash)
 *   to match `php artisan serve`, or rely on the dev proxy for `/storage` in vite.crm.config.js.
 */
export function resolvePublicUrl(path) {
  if (path == null || path === "") return "";
  const s = String(path).trim();
  if (/^(https?:|blob:|data:)/i.test(s)) return s;
  const normalized = s.startsWith("/") ? s : `/${s}`;
  const origin = (import.meta.env.VITE_APP_ORIGIN || "").replace(/\/$/, "");
  if (origin) {
    return `${origin}${normalized}`;
  }
  if (typeof window !== "undefined" && window.location?.origin) {
    return `${window.location.origin}${normalized}`;
  }
  return normalized;
}
