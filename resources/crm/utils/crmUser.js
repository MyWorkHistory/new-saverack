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
