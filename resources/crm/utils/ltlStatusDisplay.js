/** LTL status chip colors — Draft matches ASN; Quoted uses light green like ASN Completed. */
export const LTL_STATUS_DISPLAY = {
  draft: {
    label: "Draft",
    icon: "description",
    iconStyle: { background: "#fff7ed", color: "#c2410c" },
    labelColor: "#c2410c",
  },
  pending: {
    label: "Pending",
    icon: "hourglass",
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
    labelColor: "#1e3a8a",
  },
  quoted: {
    label: "Quoted",
    icon: "checkCircle",
    iconStyle: { background: "#dcfce7", color: "#166534" },
    labelColor: "#166534",
  },
  scheduled: {
    label: "Scheduled",
    icon: "calendarMonth",
    iconStyle: { background: "#e0e7ff", color: "#3730a3" },
    labelColor: "#3730a3",
  },
  in_transit: {
    label: "In-Transit",
    icon: "localShipping",
    iconStyle: { background: "#f1f5f9", color: "#475569" },
    labelColor: "#475569",
  },
};

export function normalizeLtlStatus(status) {
  return String(status || "").toLowerCase().replace(/-/g, "_") || "draft";
}

export function ltlStatusDisplay(status) {
  const key = normalizeLtlStatus(status);
  return LTL_STATUS_DISPLAY[key] || LTL_STATUS_DISPLAY.draft;
}
