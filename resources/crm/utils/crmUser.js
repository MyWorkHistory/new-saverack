/** Role `name` values that mean “administrator” (prod: `admin`, often paired with `staff`). */
const ADMIN_ROLE_NAMES = new Set([
  "admin",
  "administrator",
  "super_admin",
  "superadmin",
]);

/** True if this CRM user should be treated as an administrator (full user module, etc.). */
export function crmIsAdmin(user) {
  if (!user || typeof user !== "object") return false;
  if (user.is_admin === true || user.is_admin === 1 || user.is_admin === "1") {
    return true;
  }
  const roles = user.roles;
  if (Array.isArray(roles)) {
    return roles.some((r) =>
      ADMIN_ROLE_NAMES.has(String(r?.name || "").toLowerCase()),
    );
  }
  return false;
}

/** Portal/non-staff account: linked directly to a client account. */
export function crmIsPortalUser(user) {
  if (!user || typeof user !== "object") return false;
  const raw = user.client_account_id;
  if (raw === null || raw === undefined) return false;
  if (typeof raw === "number") return Number.isFinite(raw) && raw > 0;
  const v = String(raw).trim();
  return v !== "" && v !== "0";
}

/** Pending signup or missing ShipHero setup — show welcome instead of dashboard. */
export function crmPortalNeedsWelcome(user) {
  if (!crmIsPortalUser(user)) return false;
  if (user.portal_setup_complete === true) return false;
  if (user.status === "pending") return true;
  if (user.client_account_status === "pending") return true;
  if (!user.shiphero_ready) return true;

  return false;
}

/** Default path after portal login/register. */
export function crmPortalPostAuthPath(user) {
  if (crmPortalNeedsWelcome(user)) {
    return "/users/welcome";
  }

  return "/users/dashboard";
}
