/** True if this CRM user should be treated as an administrator (full user module, etc.). */
export function crmIsAdmin(user) {
  if (!user || typeof user !== "object") return false;
  if (user.is_admin === true || user.is_admin === 1 || user.is_admin === "1") {
    return true;
  }
  const roles = user.roles;
  if (Array.isArray(roles)) {
    return roles.some(
      (r) => String(r?.name || "").toLowerCase() === "admin",
    );
  }
  return false;
}
