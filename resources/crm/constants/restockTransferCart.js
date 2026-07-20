/** Fixed Transfer Cart location codes for restock transfers. */
export const TRANSFER_CART_LOCATIONS = ["T-01", "T-02", "T-03", "T-04", "T-05", "T-06"];

export const RESTOCK_STATUS_PENDING = "pending";
export const RESTOCK_STATUS_TRANSFER_CART = "transfer_cart";
export const RESTOCK_STATUS_COMPLETE = "complete";

export function restockStatusLabel(status) {
  const s = String(status || "").toLowerCase();
  if (s === RESTOCK_STATUS_TRANSFER_CART) return "Transfer";
  if (s === RESTOCK_STATUS_COMPLETE) return "Complete";
  return "Pending";
}

export function restockStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === RESTOCK_STATUS_TRANSFER_CART) return "bg-primary-subtle text-primary-emphasis";
  if (s === RESTOCK_STATUS_COMPLETE) return "bg-success-subtle text-success";
  return "bg-warning-subtle text-warning-emphasis";
}

/** Normalize location labels like "T-01", "T01", "t-01 cart" → "T-01" or null. */
export function matchTransferCartCode(name) {
  const raw = String(name || "").trim().toUpperCase();
  if (!raw) return null;
  for (const code of TRANSFER_CART_LOCATIONS) {
    const compact = code.replace("-", "");
    const escaped = code.replace("-", "[-]?");
    const re = new RegExp(`(?:^|[^A-Z0-9])${escaped}(?:$|[^A-Z0-9])`);
    if (raw === code || raw.replace(/[^A-Z0-9]/g, "") === compact || re.test(raw)) {
      return code;
    }
  }
  return null;
}

export function isTransferCartLocationName(name) {
  return matchTransferCartCode(name) !== null;
}
