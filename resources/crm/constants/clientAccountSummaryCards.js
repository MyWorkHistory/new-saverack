function cardFromStatus(key, status, title, sub, icon, titleColor, iconStyle) {
  return {
    key,
    status,
    titleUpper: title.toUpperCase(),
    sub,
    titleColor,
    icon,
    iconStyle,
  };
}

export const CLIENT_ACCOUNT_SUMMARY_CARDS = [
  cardFromStatus(
    "active",
    "active",
    "Active",
    "Accounts marked active",
    "checkCircle",
    "#166534",
    { background: "#dcfce7", color: "#166534" },
  ),
  cardFromStatus(
    "pending",
    "pending",
    "Pending",
    "Awaiting activation",
    "hourglass",
    "#b45309",
    { background: "#fef3c7", color: "#b45309" },
  ),
  cardFromStatus(
    "paused",
    "paused",
    "Paused",
    "Temporarily paused accounts",
    "pauseCircle",
    "#dc2626",
    { background: "#fee2e2", color: "#dc2626" },
  ),
  cardFromStatus(
    "inactive",
    "inactive",
    "Inactive",
    "Inactive accounts",
    "cancel",
    "#64748b",
    { background: "#f1f5f9", color: "#64748b" },
  ),
];
