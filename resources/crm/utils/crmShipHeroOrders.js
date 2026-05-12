import { crmIsAdmin, crmIsPortalUser } from "./crmUser";

/**
 * Matches Laravel `shiphero.orders.write` gate: staff with inventory.view may change ShipHero orders;
 * portal / client-linked logins still need inventory.update.
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
  if (keys.includes("inventory.update")) {
    return true;
  }
  if (crmIsPortalUser(user)) {
    return false;
  }

  return keys.includes("inventory.view");
}
