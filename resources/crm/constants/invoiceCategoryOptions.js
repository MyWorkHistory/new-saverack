/** Invoice line categories — same options as invoice add/edit item modals. */
export const INVOICE_CATEGORY_OPTIONS = [
  { value: "fulfillment", label: "Fulfillment" },
  { value: "wholesale", label: "Wholesale" },
  { value: "postage", label: "Postage" },
  { value: "packaging", label: "Packaging" },
  { value: "returns", label: "Returns" },
  { value: "ad_hoc", label: "Ad Hoc" },
  { value: "bank fee", label: "Bank Fee" },
  { value: "duties & taxes", label: "Duties & Taxes" },
  { value: "storage", label: "Storage" },
  { value: "on_demand", label: "On Demand" },
  { value: "receiving", label: "Receiving" },
  { value: "credits", label: "Credits" },
  { value: "other", label: "Other" },
];

export const DEFAULT_INVOICE_CATEGORY =
  INVOICE_CATEGORY_OPTIONS[0]?.value ?? "fulfillment";

export function invoiceCategoryLabel(value) {
  const key = String(value ?? "");
  if (key === "amazon prep") return "Wholesale";
  const found = INVOICE_CATEGORY_OPTIONS.find((o) => o.value === key);
  return found?.label ?? key;
}

export function isCreditCategory(value) {
  return String(value || "").toLowerCase() === "credits";
}
