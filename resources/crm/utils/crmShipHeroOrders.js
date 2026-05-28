import { crmIsAdmin, crmIsPortalUser } from "./crmUser";

/**
 * Matches Laravel `shiphero.orders.write`: orders.update (preferred), with inventory.update as
 * temporary backward-compatible fallback.
 *
 * @param {object|null|undefined} user
 */
export function canWriteShipHeroOrders(user) {
  if (!user || typeof user !== "object") {
    return false;
  }
  if (crmIsAdmin(user) || user.is_crm_owner === true || user.is_crm_owner === 1 || user.is_crm_owner === "1") {
    return true;
  }
  const keys = Array.isArray(user.permission_keys) ? user.permission_keys : [];
  if (keys.includes("orders.update") || keys.includes("inventory.update")) {
    return true;
  }
  // Portal users are read-only for orders in CRM UI.
  if (crmIsPortalUser(user)) return false;

  return false;
}
