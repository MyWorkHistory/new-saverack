export function wholesaleStatusLabel(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "Draft";
  if (s === "pending") return "Pending";
  if (s === "in_progress") return "In Progress";
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
  { value: "in_progress", label: "In Progress" },
  { value: "completed", label: "Completed" },
  { value: "shipped", label: "Shipped" },
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
