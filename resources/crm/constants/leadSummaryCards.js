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

export const LEAD_SUMMARY_CARDS = [
  cardFromStatus(
    "open",
    "open",
    "Open",
    "New leads",
    "checkCircle",
    "#166534",
    { background: "#dcfce7", color: "#166534" },
  ),
  cardFromStatus(
    "contacted",
    "contacted",
    "Contacted",
    "Reached out",
    "chat",
    "#1d4ed8",
    { background: "#dbeafe", color: "#1d4ed8" },
  ),
  cardFromStatus(
    "interested",
    "interested",
    "Interested",
    "Showing interest",
    "taskAlt",
    "#b45309",
    { background: "#fef3c7", color: "#b45309" },
  ),
  cardFromStatus(
    "future_opportunity",
    "future_opportunity",
    "Future Opportunity",
    "Later potential",
    "calendarMonth",
    "#7c3aed",
    { background: "#ede9fe", color: "#7c3aed" },
  ),
  cardFromStatus(
    "follow_up",
    "follow_up",
    "Follow Up",
    "Needs follow up",
    "hourglass",
    "#dc2626",
    { background: "#fee2e2", color: "#dc2626" },
  ),
];
