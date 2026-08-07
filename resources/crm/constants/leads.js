export const LEAD_STATUSES = [
  "open",
  "contacted",
  "interested",
  "future_opportunity",
  "follow_up",
  "non_responsive",
  "not_interested",
  "not_qualified",
  "account_created",
];

export const LEAD_FOLLOW_UP_DAY_OPTIONS = [1, 3, 5, 7, 10, 15, 30, 60, 90];

/** Sentinel for Follow Up Off in selects (serialized as null to the API). */
export const LEAD_FOLLOW_UP_OFF = "";

export const LEAD_STATUS_LABELS = {
  open: "Open",
  contacted: "Contacted",
  interested: "Interested",
  future_opportunity: "Future Opportunity",
  follow_up: "Follow Up",
  non_responsive: "Non-Responsive",
  not_interested: "Not Interested",
  not_qualified: "Not Qualified",
  account_created: "Account Created",
};

export function leadStatusLabel(status) {
  const key = String(status || "").toLowerCase();
  return LEAD_STATUS_LABELS[key] || String(status || "").replace(/_/g, " ");
}

export function formatFollowUpDays(days) {
  if (days === null || days === undefined || days === "" || days === "off") {
    return "Off";
  }
  const n = Number(days);
  if (!Number.isFinite(n) || n <= 0) return "Off";
  return n === 1 ? "1 day" : `${n} days`;
}

/** Remaining countdown until follow_up_at (or API follow_up_label). */
export function formatFollowUpRemaining(leadOrLabel, followUpAt, followUpDays) {
  if (leadOrLabel && typeof leadOrLabel === "object") {
    if (leadOrLabel.follow_up_label) return leadOrLabel.follow_up_label;
    followUpAt = leadOrLabel.follow_up_at;
    followUpDays = leadOrLabel.follow_up_days;
  } else if (typeof leadOrLabel === "string" && followUpAt === undefined) {
    return leadOrLabel || "—";
  }

  if (followUpDays === null || followUpDays === undefined || followUpDays === "") {
    return "—";
  }
  if (!followUpAt) return "—";

  const target = new Date(String(followUpAt).includes("T") ? followUpAt : `${followUpAt}T00:00:00`);
  if (Number.isNaN(target.getTime())) return "—";

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  target.setHours(0, 0, 0, 0);
  const diffMs = target.getTime() - today.getTime();
  const days = Math.round(diffMs / 86400000);
  if (days < 0) return "Overdue";
  if (days === 0) return "Due";
  return days === 1 ? "1 day" : `${days} days`;
}

export function followUpSelectValue(days) {
  if (days === null || days === undefined || days === "" || days === "off") {
    return LEAD_FOLLOW_UP_OFF;
  }
  return Number(days);
}

export function followUpPayloadValue(selectValue) {
  if (selectValue === LEAD_FOLLOW_UP_OFF || selectValue === null || selectValue === undefined || selectValue === "off") {
    return null;
  }
  return Number(selectValue);
}

export function leadInitials(name) {
  const parts = String(name || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}
