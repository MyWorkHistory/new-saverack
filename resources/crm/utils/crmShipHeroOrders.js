import { crmIsAdmin, crmIsPortalUser } from "./crmUser";

/**
 * Matches Laravel `shiphero.orders.write`: inventory.update OR inventory.view, including 3PL portal
 * logins (client_account_id) where `inventory.view` is granted without listing keys in permission_keys.
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
  if (keys.includes("inventory.update") || keys.includes("inventory.view")) {
    return true;
  }
  if (crmIsPortalUser(user)) {
    return true;
  }

  return false;
}
