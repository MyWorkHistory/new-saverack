export const LEAD_STATUSES = [
  "open",
  "contacted",
  "interested",
  "future_opportunity",
  "follow_up",
  "non_responsive",
  "not_interested",
  "not_qualified",
];

export const LEAD_FOLLOW_UP_DAY_OPTIONS = [1, 3, 5, 7, 10, 15, 30, 60, 90];

export const LEAD_STATUS_LABELS = {
  open: "Open",
  contacted: "Contacted",
  interested: "Interested",
  future_opportunity: "Future Opportunity",
  follow_up: "Follow Up",
  non_responsive: "Non-Responsive",
  not_interested: "Not Interested",
  not_qualified: "Not Qualified",
};

export function leadStatusLabel(status) {
  const key = String(status || "").toLowerCase();
  return LEAD_STATUS_LABELS[key] || String(status || "").replace(/_/g, " ");
}

export function formatFollowUpDays(days) {
  const n = Number(days);
  if (!Number.isFinite(n) || n <= 0) return "—";
  return n === 1 ? "1 day" : `${n} days`;
}
