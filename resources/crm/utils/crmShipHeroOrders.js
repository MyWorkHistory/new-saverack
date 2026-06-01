import { crmIsAdmin, crmIsPortalUser } from "./crmUser";

/**
 * Matches Laravel `shiphero.orders.write`: orders.update (preferred), with inventory.update as
 * temporary backward-compatible fallback. Portal client accounts may mutate their own orders
 * (API enforces client_account_id scope).
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
  // Portal: same access as orders.view (implicit for client_account_id on the user).
  if (crmIsPortalUser(user) && Number(user.client_account_id || 0) > 0) {
    return true;
  }
  // Staff CRM (non-portal): orders.view grants detail mutations — same routes as the orders list.
  if (!crmIsPortalUser(user) && keys.includes("orders.view")) {
    return true;
  }

  return false;
}
