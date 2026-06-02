/** @typedef {{ value: string, label: string }} BillingPreferenceOption */

/** @type {BillingPreferenceOption[]} */
export const POSTAGE_OPTIONS = [
  { value: "save_rack_all_postage", label: "Save Rack Provides All Postage" },
  { value: "customer_usps", label: "Customer Provides USPS Account" },
  { value: "customer_ups", label: "Customer Provides UPS Account" },
  { value: "customer_fedex", label: "Customer Provides Fedex Account" },
  {
    value: "customer_multiple_carriers",
    label: "Customer Provides Multiple Carrier Accounts",
  },
];

/** @type {BillingPreferenceOption[]} */
export const PACKAGING_OPTIONS = [
  {
    value: "save_rack_all_packaging",
    label: "Save Rack Provides All Packaging Materials",
  },
  {
    value: "customer_some_packaging",
    label: "Customer Provides Some Packaging Materials",
  },
  {
    value: "customer_all_packaging",
    label: "Customer Provides All Packaging Materials",
  },
];

export const DEFAULT_POSTAGE_OPTION = "save_rack_all_postage";
export const DEFAULT_PACKAGING_OPTION = "save_rack_all_packaging";

/**
 * @param {string|null|undefined} key
 * @param {BillingPreferenceOption[]} options
 * @param {string} fallbackValue
 */
function labelForKey(key, options, fallbackValue) {
  const k = String(key || "").trim();
  const hit = options.find((o) => o.value === k);
  if (hit) return hit.label;
  const fallback = options.find((o) => o.value === fallbackValue);
  return fallback ? fallback.label : "—";
}

/** @param {string|null|undefined} key */
export function postageLabelForKey(key) {
  return labelForKey(key, POSTAGE_OPTIONS, DEFAULT_POSTAGE_OPTION);
}

/** @param {string|null|undefined} key */
export function packagingLabelForKey(key) {
  return labelForKey(key, PACKAGING_OPTIONS, DEFAULT_PACKAGING_OPTION);
}
