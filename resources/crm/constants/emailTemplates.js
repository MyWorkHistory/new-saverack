export const EMAIL_TEMPLATE_CATEGORIES = [
  "contacted",
  "interested",
  "future_opportunity",
  "follow_up",
  "non_responsive",
  "not_interested",
  "not_qualified",
];

export const EMAIL_TEMPLATE_CATEGORY_LABELS = {
  contacted: "Contacted",
  interested: "Interested",
  future_opportunity: "Future Opportunity",
  follow_up: "Follow Up",
  non_responsive: "Non-Responsive",
  not_interested: "Not Interested",
  not_qualified: "Not Qualified",
};

/** Icon name + accent colors for category group headers and pills. */
export const EMAIL_TEMPLATE_CATEGORY_META = {
  contacted: {
    icon: "phone",
    accent: "#2563eb",
    softBg: "#dbeafe",
    softText: "#1d4ed8",
  },
  interested: {
    icon: "star",
    accent: "#7c3aed",
    softBg: "#ede9fe",
    softText: "#6d28d9",
  },
  future_opportunity: {
    icon: "calendar",
    accent: "#0891b2",
    softBg: "#cffafe",
    softText: "#0e7490",
  },
  follow_up: {
    icon: "clock",
    accent: "#dc2626",
    softBg: "#fee2e2",
    softText: "#b91c1c",
  },
  non_responsive: {
    icon: "mute",
    accent: "#64748b",
    softBg: "#e2e8f0",
    softText: "#475569",
  },
  not_interested: {
    icon: "x",
    accent: "#78716c",
    softBg: "#e7e5e4",
    softText: "#57534e",
  },
  not_qualified: {
    icon: "slash",
    accent: "#6b7280",
    softBg: "#f3f4f6",
    softText: "#4b5563",
  },
};

export function emailTemplateCategoryLabel(category) {
  const key = String(category || "").toLowerCase();
  return EMAIL_TEMPLATE_CATEGORY_LABELS[key] || String(category || "").replace(/_/g, " ");
}

export function emailTemplateCategoryMeta(category) {
  const key = String(category || "").toLowerCase();
  return EMAIL_TEMPLATE_CATEGORY_META[key] || EMAIL_TEMPLATE_CATEGORY_META.contacted;
}
