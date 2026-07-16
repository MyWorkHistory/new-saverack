import { crmIsPortalUser } from "./crmUser";
import { userHasModuleAction, userHasPerm } from "./crmPerms";

/**
 * Matches Laravel `shiphero.orders.write`: any orders create/update/delete for CRM staff.
 * Portal client accounts may mutate their own orders (API enforces client_account_id scope).
 *
 * @param {object|null|undefined} user
 */
export function canWriteShipHeroOrders(user) {
  if (!user || typeof user !== "object") {
    return false;
  }
  if (userHasModuleAction(user, "orders", ["create", "update", "delete"])) {
    return true;
  }
  if (crmIsPortalUser(user) && Number(user.client_account_id || 0) > 0) {
    return true;
  }

  return false;
}

/**
 * Create Order page / drawer.
 */
export function canCreateOrders(user) {
  return (
    userHasPerm(user, "orders_create.create", "orders_create.update", "orders.create") ||
    userHasModuleAction(user, "orders", ["create", "update"])
  );
}

/**
 * Cancel / remove order (treated as delete).
 */
export function canDeleteOrders(user) {
  return (
    userHasPerm(user, "orders_search.delete", "orders.delete") ||
    userHasModuleAction(user, "orders", ["delete", "update"])
  );
}
