export const ASN_STATUS_DISPLAY = {
  draft: {
    label: "Draft",
    icon: "description",
    iconStyle: { background: "#fff7ed", color: "#c2410c" },
    labelColor: "#c2410c",
  },
  pending: {
    label: "Pending",
    icon: "localShipping",
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
  non_compliant: {
    label: "Non-Compliant",
    icon: "cancel",
    iconStyle: { background: "#ffe4e6", color: "#be123c" },
    labelColor: "#be123c",
  },
};

export function normalizeAsnStatus(status) {
  return String(status || "").toLowerCase().replace(/-/g, "_") || "pending";
}

export function asnStatusDisplay(status) {
  const key = normalizeAsnStatus(status);
  return ASN_STATUS_DISPLAY[key] || ASN_STATUS_DISPLAY.pending;
}
