export const PROJECT_STATUS_DISPLAY = {
  draft: {
    label: "Draft",
    icon: "editNote",
    iconStyle: { background: "#f1f5f9", color: "#475569" },
    labelColor: "#475569",
  },
  pending: {
    label: "Pending",
    icon: "schedule",
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
    labelColor: "#1e3a8a",
  },
  in_progress: {
    label: "In-Progress",
    icon: "hourglass",
    iconStyle: { background: "#fef3c7", color: "#b45309" },
    labelColor: "#b45309",
  },
  review: {
    label: "Review",
    icon: "visibility",
    iconStyle: { background: "#ede9fe", color: "#6d28d9" },
    labelColor: "#6d28d9",
  },
  completed: {
    label: "Completed",
    icon: "checkCircle",
    iconStyle: { background: "#dcfce7", color: "#166534" },
    labelColor: "#166534",
  },
};

/** Display order: Draft → Pending → In-Progress → Review → Completed. */
export const PROJECT_STATUSES = [
  "draft",
  "pending",
  "in_progress",
  "review",
  "completed",
];

export function projectStatusDisplay(status) {
  const key = String(status || "").toLowerCase().replace(/-/g, "_") || "draft";
  return PROJECT_STATUS_DISPLAY[key] || PROJECT_STATUS_DISPLAY.draft;
}
