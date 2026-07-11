/** Prefixes safe for order-detail back navigation via return_to query. */
const ORDER_RETURN_PREFIXES = [
  "/admin/orders",
  "/admin/clients/accounts/",
  "/admin/inventory",
  "/users/orders",
];

/**
 * @param {string} path
 * @returns {boolean}
 */
export function isAllowedOrderReturnPath(path) {
  if (!path || typeof path !== "string") return false;
  const trimmed = path.trim();
  if (!trimmed.startsWith("/") || trimmed.startsWith("//")) return false;
  if (trimmed.includes("://")) return false;

  for (const prefix of ORDER_RETURN_PREFIXES) {
    if (trimmed.startsWith(prefix)) return true;
  }

  // Staff/portal global search may open an order from any CRM page.
  return trimmed.startsWith("/admin/") || trimmed.startsWith("/users/");
}

/**
 * @param {import('vue-router').RouteLocationNormalizedLoaded} route
 * @returns {string|null}
 */
export function buildOrderDetailReturnTo(route) {
  const path = route?.fullPath;
  if (!path || !isAllowedOrderReturnPath(path)) return null;
  return path;
}

/**
 * @param {import('vue-router').Router} router
 * @param {import('vue-router').RouteLocationNormalizedLoaded} route
 * @param {{ isPortalUser: boolean, isReturnPreviewMode: boolean, selectedAccountId?: string|number }} options
 */
export function resolveOrderDetailBack(router, route, options) {
  const { isPortalUser, isReturnPreviewMode, selectedAccountId } = options;

  if (isReturnPreviewMode) {
    router.push({ name: "user-return-create-search" });
    return;
  }

  const returnTo = String(route.query.return_to || "").trim();
  if (returnTo && isAllowedOrderReturnPath(returnTo)) {
    router.push(returnTo);
    return;
  }

  if (typeof window !== "undefined" && window.history.length > 1) {
    router.back();
    return;
  }

  const accountId = String(selectedAccountId || "").trim();
  const q = accountId ? { client_account_id: accountId } : {};
  if (isPortalUser) {
    router.push({ name: "user-orders", query: q });
    return;
  }
  router.push({ name: "orders-search", query: q });
}

/**
 * Preserve return_to when rebuilding order detail query after in-page navigation.
 *
 * @param {import('vue-router').RouteLocationNormalizedLoaded} route
 * @param {Record<string, string>} query
 * @returns {Record<string, string>}
 */
export function preserveOrderDetailReturnQuery(route, query) {
  const returnTo = String(route.query.return_to || "").trim();
  if (returnTo && isAllowedOrderReturnPath(returnTo)) {
    return { ...query, return_to: returnTo };
  }
  return query;
}
