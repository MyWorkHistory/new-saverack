/**
 * ShipHero store types for CRM deep-links (?type=&shop=).
 * @type {{ value: string, label: string }[]}
 */
export const SHIPHERO_STORE_TYPE_OPTIONS = [
  { value: "api", label: "Public API" },
  { value: "amazon", label: "Amazon" },
  { value: "shopify", label: "Shopify" },
  { value: "woocommerce", label: "WooCommerce" },
  { value: "walmart", label: "Walmart" },
  { value: "etsy", label: "Etsy" },
  { value: "tiktok", label: "TikTok" },
  { value: "bigcommerce", label: "BigCommerce" },
];

const SETTINGS_URL_BASE = "https://app.shiphero.com/dashboard/stores/settings";

/**
 * @param {string|null|undefined} type
 * @returns {string}
 */
export function shipHeroStoreTypeLabel(type) {
  if (type == null || String(type).trim() === "") return "";
  const slug = String(type).trim().toLowerCase();
  const hit = SHIPHERO_STORE_TYPE_OPTIONS.find((o) => o.value === slug);
  return hit ? hit.label : slug;
}

/**
 * @param {string|null|undefined} type
 * @returns {boolean}
 */
export function isShipHeroStoreApiType(type) {
  return String(type || "")
    .trim()
    .toLowerCase() === "api";
}

/**
 * Resolve shop ID from a store row (CRM override or ShipHero legacy_id).
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {string}
 */
export function shipHeroStoreShopId(row) {
  if (!row || typeof row !== "object") return "";
  const shop = String(row.shop_id ?? "").trim();
  if (shop) return shop;
  return String(row.legacy_id ?? "").trim();
}

/**
 * Build ShipHero store settings URL. Needs shop ID; Public API has no link.
 * With type → ?type=&shop=; shop only → ?shop=
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {string|null}
 */
export function shipHeroStoreSettingsUrl(row) {
  if (!row || typeof row !== "object") return null;

  const fromApi = String(row.settings_url ?? "").trim();
  const shopId = shipHeroStoreShopId(row);
  const type = String(row.store_type ?? "")
    .trim()
    .toLowerCase();

  if (isShipHeroStoreApiType(type)) {
    return null;
  }

  if (shopId === "") {
    return null;
  }

  if (type && SHIPHERO_STORE_TYPE_OPTIONS.some((o) => o.value === type)) {
    return `${SETTINGS_URL_BASE}?type=${encodeURIComponent(type)}&shop=${encodeURIComponent(shopId)}`;
  }

  if (fromApi) {
    return fromApi;
  }

  return `${SETTINGS_URL_BASE}?shop=${encodeURIComponent(shopId)}`;
}
