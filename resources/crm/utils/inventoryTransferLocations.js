/**
 * Shared location helpers for Restock-style transfer lightbox (Restock, Put Away, Inventory).
 */
import {
  TRANSFER_CART_LOCATIONS,
  isTransferCartLocationName,
  matchTransferCartCode,
} from "../constants/restockTransferCart.js";

export { TRANSFER_CART_LOCATIONS, isTransferCartLocationName, matchTransferCartCode };

export const RECEIVING_LOCATION_NAME = "Receiving";

export function isReceivingLocationName(name) {
  return String(name || "").trim().toLowerCase() === RECEIVING_LOCATION_NAME.toLowerCase();
}

export function preferredWarehouseId(product, row = null) {
  const fromRow = String(row?.warehouse_id || "").trim();
  if (fromRow) return fromRow;
  const warehouses = Array.isArray(product?.warehouses) ? product.warehouses : [];
  return String(warehouses[0]?.warehouse_id || "").trim();
}

export function flattenProductLocations(product, { includeEmpty = false } = {}) {
  const out = [];
  const warehouses = Array.isArray(product?.warehouses) ? product.warehouses : [];
  warehouses.forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      const qty = Number(loc?.quantity || 0);
      if (!includeEmpty && qty <= 0) return;
      out.push({
        ...loc,
        quantity: qty,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
      });
    });
  });
  return out;
}

export function pickLocationsFromProduct(product, row = null) {
  const whId = preferredWarehouseId(product, row);
  return flattenProductLocations(product, { includeEmpty: true }).filter(
    (loc) =>
      loc.pickable === true &&
      (!whId || String(loc.warehouse_id || "") === whId),
  );
}

/** Non-pickable locations with qty (Restock "from" options). */
export function backstockLocationsFromProduct(product, row = null) {
  const whId = preferredWarehouseId(product, row);
  return flattenProductLocations(product, { includeEmpty: false }).filter(
    (loc) =>
      loc.pickable === false &&
      (!whId || String(loc.warehouse_id || "") === whId),
  );
}

/** Receiving locations with qty (Put Away "from" options). */
export function receivingLocationsFromProduct(product, row = null) {
  const whId = preferredWarehouseId(product, row);
  return flattenProductLocations(product, { includeEmpty: false }).filter(
    (loc) =>
      isReceivingLocationName(loc.location_name || loc.location_id) &&
      (!whId || String(loc.warehouse_id || "") === whId),
  );
}

export function buildCartFromOptions(product, row = null) {
  const whId = preferredWarehouseId(product, row);
  const withQty = flattenProductLocations(product, { includeEmpty: false }).filter((loc) =>
    isTransferCartLocationName(loc.location_name || loc.location_id),
  );
  const preferred = whId
    ? withQty.filter((loc) => String(loc.warehouse_id || "") === whId)
    : withQty;
  if (preferred.length) return preferred;
  if (withQty.length) return withQty;

  const anyCart = flattenProductLocations(product, { includeEmpty: true }).filter((loc) =>
    isTransferCartLocationName(loc.location_name || loc.location_id),
  );
  const anyPreferred = whId
    ? anyCart.filter((loc) => String(loc.warehouse_id || "") === whId)
    : anyCart;
  if (anyPreferred.length) return anyPreferred;
  if (anyCart.length) return anyCart;

  return TRANSFER_CART_LOCATIONS.map((code) => ({
    location_id: code,
    location_name: code,
    quantity: 0,
    warehouse_id: whId,
    pickable: false,
  }));
}

export function resolveCartDestination(product, row, cartCode) {
  const code = String(cartCode || "").trim().toUpperCase();
  if (!code) return null;
  const locs = flattenProductLocations(product, { includeEmpty: true });
  const match = locs.find(
    (loc) => matchTransferCartCode(loc.location_name || loc.location_id) === code,
  );
  if (match?.location_id) {
    return { to_location_id: String(match.location_id) };
  }
  return { to_location: code };
}

export function formatLocationWithQty(loc) {
  const name = loc?.location_name || loc?.location_id || "—";
  const qty = Number(loc?.quantity ?? 0);
  return `${name} (QTY: ${qty.toLocaleString()})`;
}
