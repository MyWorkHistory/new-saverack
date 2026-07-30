export const LTL_STATUSES = {
  draft: { label: "Draft", tone: "warning" },
  pending: { label: "Pending", tone: "info" },
  quoted: { label: "Quoted", tone: "success" },
  scheduled: { label: "Scheduled", tone: "primary" },
  in_transit: { label: "In-Transit", tone: "secondary" },
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

export function ltlStatusBadgeClass(status) {
  const tone = LTL_STATUSES[status]?.tone || "secondary";
  return `badge text-bg-${tone === "warning" ? "warning" : tone === "info" ? "info" : tone === "success" ? "success" : tone === "primary" ? "primary" : "secondary"}`;
}
