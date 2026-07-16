import { crmIsAdmin, crmIsPortalUser } from "./crmUser";

/**
 * @param {object|null|undefined} user
 * @returns {string[]}
 */
export function userPermissionKeys(user) {
  if (!user || typeof user !== "object") return [];
  return Array.isArray(user.permission_keys) ? user.permission_keys : [];
}

/**
 * True if user has any of the listed keys (admins / CRM owner always true).
 *
 * @param {object|null|undefined} user
 * @param {...string} keys
 */
export function userHasPerm(user, ...keys) {
  if (!user || typeof user !== "object") return false;
  if (
    crmIsAdmin(user) ||
    user.is_crm_owner === true ||
    user.is_crm_owner === 1 ||
    user.is_crm_owner === "1"
  ) {
    return true;
  }
  const set = userPermissionKeys(user);
  return keys.some((k) => typeof k === "string" && k !== "" && set.includes(k));
}

/**
 * True if user has `{prefix}.action` or any `{prefix}_*.action` (or legacy parent).
 *
 * @param {object|null|undefined} user
 * @param {string} modulePrefix e.g. "orders", "receiving", "returns"
 * @param {string|string[]} actions e.g. "update" or ["create","update","delete"]
 */
export function userHasModuleAction(user, modulePrefix, actions) {
  if (!user || typeof user !== "object") return false;
  if (
    crmIsAdmin(user) ||
    user.is_crm_owner === true ||
    user.is_crm_owner === 1 ||
    user.is_crm_owner === "1"
  ) {
    return true;
  }
  const list = Array.isArray(actions) ? actions : [actions];
  const set = userPermissionKeys(user);
  for (const action of list) {
    if (set.includes(`${modulePrefix}.${action}`)) return true;
    const re = new RegExp(
      `^${modulePrefix.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}_[a-z0-9_]+\\.${action}$`,
    );
    if (set.some((k) => re.test(k))) return true;
  }
  return false;
}
