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
