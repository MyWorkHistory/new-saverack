export const LTL_STATUSES = {
  draft: { label: "Draft" },
  pending: { label: "Pending" },
  quoted: { label: "Quoted" },
  scheduled: { label: "Scheduled" },
  in_transit: { label: "In-Transit" },
};

export const LTL_DIRECTIONS = [
  { value: "ship_to_save_rack", label: "Ship To Save Rack" },
  { value: "ship_from_save_rack", label: "Ship From Save Rack" },
];

export const LTL_LOAD_OPTIONS = [
  { value: "dock", label: "Dock" },
  { value: "liftgate", label: "Liftgate Needed" },
  { value: "custom", label: "Custom" },
];

export const LTL_PICKUP_TYPES = [
  { value: "business", label: "Business" },
  { value: "residential", label: "Residential" },
];

export const LTL_SERVICES = [{ value: "standard_ltl", label: "Standard LTL" }];

export const LTL_TIME_MODES = [
  { value: "asap", label: "As Soon As Possible" },
  { value: "specific", label: "Specific Date & Time" },
];

export function formatLtlMoney(cents) {
  if (cents == null || cents === "") return "—";
  const n = Number(cents);
  if (!Number.isFinite(n)) return "—";
  return new Intl.NumberFormat(undefined, { style: "currency", currency: "USD" }).format(n / 100);
}

/** @deprecated Prefer LtlStatusChip / ltlStatusDisplay. Kept for any residual callers. */
export function ltlStatusBadgeClass(status) {
  const key = String(status || "").toLowerCase();
  if (key === "draft") return "bg-warning-subtle text-warning-emphasis";
  if (key === "pending") return "bg-primary-subtle text-primary-emphasis";
  if (key === "quoted") return "bg-success-subtle text-success-emphasis";
  if (key === "scheduled") return "bg-primary-subtle text-primary-emphasis";
  return "bg-body-secondary text-body-secondary";
}
