/** Fixed Transfer Cart location codes for restock transfers. */
export const TRANSFER_CART_LOCATIONS = ["T-01", "T-02", "T-03", "T-04", "T-05", "T-06"];

export const RESTOCK_STATUS_PENDING = "pending";
export const RESTOCK_STATUS_TRANSFER_CART = "transfer_cart";
export const RESTOCK_STATUS_COMPLETE = "complete";

export function restockStatusLabel(status) {
  const s = String(status || "").toLowerCase();
  if (s === RESTOCK_STATUS_TRANSFER_CART) return "Transfer Cart";
  if (s === RESTOCK_STATUS_COMPLETE) return "Complete";
  return "Pending";
}

export function restockStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === RESTOCK_STATUS_TRANSFER_CART) return "bg-info-subtle text-info";
  if (s === RESTOCK_STATUS_COMPLETE) return "bg-success-subtle text-success";
  return "bg-warning-subtle text-warning-emphasis";
}

export function isTransferCartLocationName(name) {
  const n = String(name || "").trim().toUpperCase();
  return TRANSFER_CART_LOCATIONS.includes(n);
}
