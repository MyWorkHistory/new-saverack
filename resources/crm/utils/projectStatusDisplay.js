export const PROJECT_STATUS_DISPLAY = {
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
  completed: {
    label: "Completed",
    icon: "checkCircle",
    iconStyle: { background: "#dcfce7", color: "#166534" },
    labelColor: "#166534",
  },
};

export const PROJECT_STATUSES = ["pending", "in_progress", "completed"];

export function projectStatusDisplay(status) {
  const key = String(status || "").toLowerCase().replace(/-/g, "_") || "pending";
  return PROJECT_STATUS_DISPLAY[key] || PROJECT_STATUS_DISPLAY.pending;
}
