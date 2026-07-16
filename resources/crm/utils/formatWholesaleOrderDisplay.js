export function wholesaleStatusLabel(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "Draft";
  if (s === "pending") return "Pending";
  if (s === "in_progress") return "Ready to Ship";
  if (s === "completed") return "Completed";
  if (s === "shipped") return "Shipped";
  return status || "—";
}

export function wholesaleStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "pending") return "bg-warning-subtle text-warning-emphasis";
  if (s === "in_progress") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  if (s === "shipped") return "bg-info-subtle text-info-emphasis";
  return "bg-body-secondary text-body-secondary";
}

export function wholesaleLineStatusLabel(status) {
  const s = String(status || "").toLowerCase();
  if (s === "pending") return "Pending";
  if (s === "ship_as_is") return "Ship As Is";
  if (s === "barcode_ready") return "Barcode Ready";
  return status || "—";
}

export function wholesaleLineStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "pending") return "bg-warning-subtle text-warning-emphasis";
  if (s === "ship_as_is") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "barcode_ready") return "bg-success-subtle text-success-emphasis";
  return "bg-body-secondary text-body-secondary";
}

export function wholesaleTypeLabel(type) {
  const t = String(type || "").toLowerCase();
  if (t === "amazon") return "Amazon";
  if (t === "tiktok") return "TikTok";
  if (t === "walmart") return "Walmart";
  if (t === "b2b") return "B2B";
  if (t === "other") return "Other";
  return type || "—";
}

export const WHOLESALE_MANUAL_STATUS_OPTIONS = [
  { value: "pending", label: "Pending" },
  { value: "completed", label: "Completed" },
];

export const WHOLESALE_STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "pending", label: "Pending" },
  { value: "in_progress", label: "Ready to Ship" },
  { value: "completed", label: "Completed" },
  { value: "shipped", label: "Shipped" },
];

export const WHOLESALE_SKU_BARCODE_LABEL_OPTIONS = [
  { value: "apply_new", label: "Apply New Barcode Labels" },
  { value: "none", label: "No Barcode Labels" },
];

export const WHOLESALE_COVER_EXISTING_BARCODE_OPTIONS = [
  { value: "yes", label: "Yes" },
  { value: "no", label: "No" },
];

export const WHOLESALE_SKU_PACKAGING_OPTIONS = [
  { value: "none", label: "No Additional Packaging" },
  { value: "poly_bag", label: "Poly Bag Each Item" },
  { value: "bubble_mailer", label: "Bubble Mailer Each Item" },
  { value: "box", label: "Box Each Item" },
  { value: "bubble_wrap", label: "Bubble Wrap Each Item" },
  { value: "other", label: "Other (Specify)" },
];

export const WHOLESALE_BUNDLE_CONFIG_OPTIONS = [
  { value: "not_bundled", label: "Not Bundled (Single SKU)" },
  { value: "bundle_together", label: "Bundle Individual SKUs Together" },
];

export const WHOLESALE_SHIPPING_METHOD_REQUIREMENT_OPTIONS = [
  { value: "boxes", label: "Ship all in boxes" },
  { value: "pallet", label: "Ship all on pallet" },
];

export const WHOLESALE_MASTER_CARTON_OPTIONS = [
  { value: "yes", label: "Yes" },
  { value: "no", label: "No" },
  { value: "other", label: "Other (specify in comments)" },
];

export const WHOLESALE_SHIPPING_LABELS_PROVIDER_OPTIONS = [
  { value: "client_provides", label: "Client Provides Shipping Labels" },
  { value: "save_rack_provides", label: "Save Rack Provides Shipping Labels" },
];

export function wholesaleOptionLabel(options, value) {
  const raw = String(value || "").trim();
  if (!raw) return null;
  const match = options.find((opt) => opt.value === raw);
  return match?.label ?? raw;
}

export function wholesaleShippingLabelsProviderLabel(provider) {
  return wholesaleOptionLabel(WHOLESALE_SHIPPING_LABELS_PROVIDER_OPTIONS, provider);
}

export const WHOLESALE_REQUIREMENT_SECTIONS = [
  {
    id: "sku-labels",
    label: "SKU Barcode Labels",
    icon: "barcode",
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
    valueKey: "sku_barcode_labels",
    commentKey: "sku_barcode_labels_comment",
    options: WHOLESALE_SKU_BARCODE_LABEL_OPTIONS,
  },
  {
    id: "cover-existing",
    label: "Cover Existing Barcodes",
    icon: "gppBad",
    iconStyle: { background: "#fee2e2", color: "#dc2626" },
    valueKey: "cover_existing_barcodes",
    commentKey: "cover_existing_barcodes_comment",
    options: WHOLESALE_COVER_EXISTING_BARCODE_OPTIONS,
  },
  {
    id: "packaging",
    label: "Individual SKU Packaging",
    icon: "inventoryBox",
    iconStyle: { background: "#f3e8ff", color: "#7c3aed" },
    valueKey: "individual_sku_packaging",
    commentKey: "individual_sku_packaging_comment",
    options: WHOLESALE_SKU_PACKAGING_OPTIONS,
  },
  {
    id: "bundle",
    label: "Bundle Configuration",
    icon: "shelves",
    iconStyle: { background: "#e0e7ff", color: "#3730a3" },
    valueKey: "bundle_configuration",
    commentKey: "bundle_configuration_comment",
    options: WHOLESALE_BUNDLE_CONFIG_OPTIONS,
  },
  {
    id: "shipping-method",
    label: "Shipping Method",
    icon: "localShipping",
    iconStyle: { background: "#fef3c7", color: "#b45309" },
    valueKey: "shipping_method_requirement",
    commentKey: "shipping_method_requirement_comment",
    options: WHOLESALE_SHIPPING_METHOD_REQUIREMENT_OPTIONS,
  },
  {
    id: "master-cartons",
    label: "Master Cartons",
    icon: "package",
    iconStyle: { background: "#f1f5f9", color: "#64748b" },
    valueKey: "master_cartons",
    commentKey: "master_cartons_comment",
    options: WHOLESALE_MASTER_CARTON_OPTIONS,
  },
];

export const WHOLESALE_TYPE_OPTIONS = [
  { value: "", label: "All types" },
  { value: "amazon", label: "Amazon" },
  { value: "tiktok", label: "TikTok" },
  { value: "walmart", label: "Walmart" },
  { value: "b2b", label: "B2B" },
  { value: "other", label: "Other" },
];

export const WHOLESALE_TYPE_CREATE_OPTIONS = WHOLESALE_TYPE_OPTIONS.filter((o) => o.value !== "");
