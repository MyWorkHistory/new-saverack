/**
 * Resolve paths like `/storage/...` for <img src> so they load from the Laravel app.
 *
 * - Production (same origin): uses the current page origin — `/storage/...` works.
 * - Avatars are served from `/avatars/…` under public/ (see UserAvatarService). Vite proxies `/avatars`.
 * - Legacy `/storage/…` URLs still work when resolvePublicUrl receives a full `https://` URL from the API.
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
