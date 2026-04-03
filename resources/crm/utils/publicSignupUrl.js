/**
 * Public signup is served only by the root SPA build (vite.spa.config.js → public/index.html).
 * The CRM staff bundle (vite.crm.config.js → /tickets-app/) must link or redirect here.
 *
 * Optional: set VITE_PUBLIC_SIGNUP_URL (full URL) when signup lives on another host/path.
 */
export function getPublicSignupUrl() {
  const fromEnv = import.meta.env.VITE_PUBLIC_SIGNUP_URL;
  if (typeof fromEnv === "string" && fromEnv.trim() !== "") {
    return fromEnv.trim();
  }
  if (
    typeof window !== "undefined" &&
    window.location &&
    window.location.origin
  ) {
    return `${window.location.origin}/create`;
  }
  return "/create";
}

/** True when this build is deployed at site root (base `/`), not under /tickets-app/. */
export function isRootSpaBundle() {
  const base = import.meta.env.BASE_URL || "/";
  return base === "/";
}
