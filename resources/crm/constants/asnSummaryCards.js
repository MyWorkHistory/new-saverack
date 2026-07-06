export const ASN_SUMMARY_CARDS = [
  {
    key: "pending",
    label: "Pending",
    sub: "Pending ASNs",
    status: "pending",
    icon: "localShipping",
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
  },
  {
    key: "in_progress",
    label: "In-Progress",
    sub: "Processing ASNs",
    status: "in_progress",
    icon: "hourglass",
    iconStyle: { background: "#fef3c7", color: "#b45309" },
  },
  {
    key: "completed",
    label: "Completed",
    sub: "Completed ASNs",
    status: "completed",
    icon: "checkCircle",
    iconStyle: { background: "#dcfce7", color: "#166534" },
  },
  {
    key: "non_compliant",
    label: "Non-Compliant",
    sub: "Non-Compliant ASNs",
    status: "non_compliant",
    icon: "cancel",
    iconStyle: { background: "#ffe4e6", color: "#be123c" },
  },
];
