import { PROJECT_STATUS_DISPLAY } from "../utils/projectStatusDisplay.js";

function cardFromStatus(key, status, sub) {
  const display = PROJECT_STATUS_DISPLAY[status];

  return {
    key,
    status,
    sub,
    label: display.label,
    titleUpper: display.label.toUpperCase().replace(/\s+/g, " "),
    titleColor: display.labelColor,
    icon: display.icon,
    iconStyle: display.iconStyle,
  };
}

export const PROJECT_SUMMARY_CARDS = [
  cardFromStatus("pending", "pending", "Pending Projects"),
  cardFromStatus("in_progress", "in_progress", "In-Progress Projects"),
  cardFromStatus("completed", "completed", "Completed Projects"),
];
