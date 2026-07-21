export const PRICING_CATEGORY_OPTIONS = [
  { value: "all", label: "All Categories" },
  { value: "fulfillment", label: "Fulfillment" },
  { value: "returns", label: "Returns" },
  { value: "storage", label: "Storage" },
  { value: "receiving", label: "Receiving" },
  { value: "custom_work", label: "Custom Work" },
  { value: "wholesale", label: "Wholesale" },
  { value: "packaging", label: "Packaging" },
  { value: "amazon", label: "Amazon" },
  { value: "postage", label: "Postage" },
];

/** Account / portal fee category filters (same as settings). */
export const CLIENT_VISIBLE_PRICING_CATEGORY_OPTIONS = PRICING_CATEGORY_OPTIONS;

/** @type {Record<string, { label: string, subtitle: string, accent: string, headerBg: string }>} */
export const PRICING_CATEGORY_META = {
  fulfillment: {
    label: "Fulfillment",
    subtitle: "Pick, pack, and ship orders per unit.",
    accent: "#1d4ed8",
    headerBg: "#dbeafe",
  },
  returns: {
    label: "Returns",
    subtitle: "Processing, labels, and restocking for returns.",
    accent: "#b45309",
    headerBg: "#fef3c7",
  },
  storage: {
    label: "Storage",
    subtitle: "Warehouse storage and inventory holding fees.",
    accent: "#0f766e",
    headerBg: "#ccfbf1",
  },
  receiving: {
    label: "Receiving",
    subtitle: "Inbound ASN and receiving labor charges.",
    accent: "#7c2d12",
    headerBg: "#ffedd5",
  },
  custom_work: {
    label: "Custom Work",
    subtitle: "Special projects and non-standard services.",
    accent: "#6b21a8",
    headerBg: "#f3e8ff",
  },
  wholesale: {
    label: "Wholesale",
    subtitle: "Wholesale order handling and processing.",
    accent: "#1e3a8a",
    headerBg: "#dbeafe",
  },
  packaging: {
    label: "Packaging",
    subtitle: "Boxes, mailers, and packaging materials.",
    accent: "#0369a1",
    headerBg: "#e0f2fe",
  },
  amazon: {
    label: "Amazon",
    subtitle: "Amazon FBA prep and labeling services.",
    accent: "#c2410c",
    headerBg: "#ffedd5",
  },
  postage: {
    label: "Postage",
    subtitle: "Carrier postage fees.",
    accent: "#334155",
    headerBg: "#e2e8f0",
  },
};

export const PRICING_CATEGORY_ORDER = PRICING_CATEGORY_OPTIONS.filter((o) => o.value !== "all").map(
  (o) => o.value,
);

export function categoryMeta(category) {
  const key = String(category || "").trim().toLowerCase();
  return (
    PRICING_CATEGORY_META[key] ?? {
      label: key ? key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase()) : "Other",
      subtitle: "",
      accent: "#64748b",
      headerBg: "#f1f5f9",
    }
  );
}

/**
 * @param {Array<{ category?: string }>} fees
 * @returns {Array<{ category: string, meta: ReturnType<typeof categoryMeta>, fees: typeof fees }>}
 */
export function groupFeesByCategory(fees) {
  const list = Array.isArray(fees) ? fees : [];
  const buckets = new Map();
  for (const fee of list) {
    const cat = String(fee?.category || "").trim().toLowerCase() || "other";
    if (!buckets.has(cat)) buckets.set(cat, []);
    buckets.get(cat).push(fee);
  }

  const ordered = [];
  for (const cat of PRICING_CATEGORY_ORDER) {
    if (buckets.has(cat)) {
      ordered.push({ category: cat, meta: categoryMeta(cat), fees: buckets.get(cat) });
      buckets.delete(cat);
    }
  }
  for (const [cat, catFees] of buckets) {
    ordered.push({ category: cat, meta: categoryMeta(cat), fees: catFees });
  }
  return ordered;
}

export function formatPrice(amount, category = null) {
  const n = Number(amount);
  const cat = String(category || "").toLowerCase();
  if (!Number.isFinite(n)) {
    return cat === "storage" ? "$0.000" : "$0.00";
  }
  if (cat === "storage") {
    return `$${n.toFixed(3)}`;
  }
  try {
    return new Intl.NumberFormat(undefined, { style: "currency", currency: "USD" }).format(n);
  } catch {
    return `$${n.toFixed(2)}`;
  }
}

export function excerpt(text, max = 100) {
  if (!text) return "";
  const s = String(text);
  return s.length <= max ? s : `${s.slice(0, max).trim()}…`;
}

export function categoryBadgeClass(category) {
  const c = String(category || "").trim().toLowerCase();
  if (c === "fulfillment") return "settings-pricing-badge settings-pricing-badge--fulfillment";
  if (c === "returns") return "settings-pricing-badge settings-pricing-badge--returns";
  if (c === "storage") return "settings-pricing-badge settings-pricing-badge--storage";
  if (c === "receiving") return "settings-pricing-badge settings-pricing-badge--receiving";
  if (c === "custom_work") return "settings-pricing-badge settings-pricing-badge--custom";
  if (c === "wholesale") return "settings-pricing-badge settings-pricing-badge--wholesale";
  if (c === "packaging") return "settings-pricing-badge settings-pricing-badge--packaging";
  if (c === "amazon") return "settings-pricing-badge settings-pricing-badge--amazon";
  if (c === "postage") return "settings-pricing-badge settings-pricing-badge--postage";
  return "settings-pricing-badge";
}

export function feeMatchesSearch(fee, query) {
  const q = String(query || "").trim().toLowerCase();
  if (!q) return true;
  const name = String(fee?.name || "").toLowerCase();
  const description = String(fee?.description || "").toLowerCase();
  return name.includes(q) || description.includes(q);
}

export function feeMatchesCategory(fee, categoryFilter) {
  const filter = String(categoryFilter || "all").trim().toLowerCase();
  if (filter === "" || filter === "all") return true;
  return String(fee?.category || "").trim().toLowerCase() === filter;
}
