/**
 * Resolve paths like `/storage/...` for <img src> so they load from the Laravel app.
 *
 * - Production (same origin): uses the current page origin — `/storage/...` works.
 * - Avatars are served from `/avatars/…` under public/ (see UserAvatarService). Vite proxies `/avatars`.
 * - Full URLs from the API (e.g. http://localhost/storage/…) are normalized to pathname + origin.
 */
export function resolvePublicUrl(path) {
  if (path == null || path === "") return "";
  let s = String(path).trim();
  if (/^blob:|^data:/i.test(s)) return s;

  if (/^https?:/i.test(s)) {
    try {
      const u = new URL(s);
      if (
        u.pathname.startsWith("/storage/") ||
        u.pathname.startsWith("/avatars/")
      ) {
        s = u.pathname;
      } else {
        return s;
      }
    } catch {
      return s;
    }
  }

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
