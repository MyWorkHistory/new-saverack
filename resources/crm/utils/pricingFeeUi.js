export const PRICING_CATEGORY_OPTIONS = [
  { value: "all", label: "All Categories" },
  { value: "fulfillment", label: "Fulfillment" },
  { value: "returns", label: "Returns" },
  { value: "storage", label: "Storage" },
  { value: "receiving", label: "Receiving" },
  { value: "custom_work", label: "Custom Work" },
];

export function formatPrice(amount) {
  const n = Number(amount);
  if (!Number.isFinite(n)) return "$0.00";
  try {
    return new Intl.NumberFormat(undefined, { style: "currency", currency: "USD" }).format(n);
  } catch {
    return `$${n}`;
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
