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
  // Staff CRM (non-portal): orders.view grants detail mutations — same routes as the orders list.
  if (!crmIsPortalUser(user) && keys.includes("orders.view")) {
    return true;
  }
  // Portal: read-only unless orders.update / inventory.update matched above.
  if (crmIsPortalUser(user)) return false;

  return false;
}
