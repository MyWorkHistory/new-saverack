/**
 * Brand: `public/logo.jpg` (served at /logo.jpg).
 *
 * Never derive the asset path from pathname “parent folders”. Doing so breaks
 * nested routes (e.g. `/staff/123` became `/staff/logo.jpg` and the image 404s).
 *
 * Optional prefix: if the app is mounted after a path segment before
 * `/tickets-app/...`, include that prefix (e.g. `/app/tickets-app/...` → `/app/logo.jpg`).
 */
export const BRAND_CACHE_BUST = "20260402a";

export function publicAssetUrl(suffix) {
  const s = suffix.startsWith("/") ? suffix : `/${suffix}`;
  const p = location.pathname;
  const mark = "/tickets-app";
  const i = p.indexOf(mark);
  if (i > 0) {
    return p.slice(0, i) + s;
  }
  return s;
}

export const BRAND_MARK_SRC = () =>
  publicAssetUrl(`/logo.jpg?v=${BRAND_CACHE_BUST}`);

export const BRAND_FAVICON_SRC = () =>
  publicAssetUrl(`/logo.jpg?v=${BRAND_CACHE_BUST}`);
