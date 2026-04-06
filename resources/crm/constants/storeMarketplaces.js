/**
 * Fixed marketplace list for store create/edit (searchable dropdown).
 * @type {string[]}
 */
export const STORE_MARKETPLACE_OPTIONS = [
  "Manual",
  "Shopify",
  "Amazon",
  "WooCommerce",
  "Channel Advisor",
  "BigCommerce",
  "Ebay",
  "Magento 2",
  "Walmart",
  "Etsy",
  "MyStoreNo",
  "Google Store",
  "TikTok",
];

/**
 * Map legacy/free-text values to canonical option when case/spacing differs.
 *
 * @param {string|null|undefined} val
 * @returns {string}
 */
export function normalizeMarketplaceValue(val) {
  if (val == null || String(val).trim() === "") {
    return "";
  }
  const t = String(val).trim();
  const hit = STORE_MARKETPLACE_OPTIONS.find(
    (o) => o.toLowerCase() === t.toLowerCase(),
  );
  return hit ?? t;
}

/**
 * Options for CrmSearchableSelect `{ id, name }[]`. If current value is not in the list, show it first.
 *
 * @param {string|null|undefined} currentRaw
 * @returns {{ id: string, name: string }[]}
 */
export function marketplaceOptionsForValue(currentRaw) {
  const normalized = normalizeMarketplaceValue(currentRaw);
  const base = STORE_MARKETPLACE_OPTIONS.map((name) => ({ id: name, name }));
  if (
    normalized &&
    !STORE_MARKETPLACE_OPTIONS.some((n) => n === normalized)
  ) {
    return [
      { id: normalized, name: `${normalized} (other)` },
      ...base,
    ];
  }
  return base;
}
