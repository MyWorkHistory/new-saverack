/**
 * Brand: `public/logo.jpg` (served at /logo.jpg). Subpath-safe via publicAssetUrl.
 */
export const BRAND_CACHE_BUST = "20260331a";

export function publicAssetUrl(suffix) {
  const p = location.pathname;
  const mark = "/tickets-app";
  const i = p.indexOf(mark);
  if (i !== -1) {
    return p.slice(0, i) + suffix;
  }
  const dir = p.replace(/\/[^/]*$/, "") || "";
  return dir + suffix;
}

export const BRAND_MARK_SRC = () =>
  publicAssetUrl(`/logo.jpg?v=${BRAND_CACHE_BUST}`);

export const BRAND_FAVICON_SRC = () =>
  publicAssetUrl(`/logo.jpg?v=${BRAND_CACHE_BUST}`);
