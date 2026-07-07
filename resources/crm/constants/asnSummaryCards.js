import { ASN_STATUS_DISPLAY } from "../utils/asnStatusDisplay.js";

function cardFromStatus(key, status, sub) {
  const display = ASN_STATUS_DISPLAY[status];
  const borderColor = display.labelColor;
  const backgroundTint = display.iconStyle?.background || "#fff";

  return {
    key,
    status,
    sub,
    label: display.label,
    titleUpper: display.label.toUpperCase().replace(/\s+/g, " "),
    titleColor: display.labelColor,
    icon: display.icon,
    iconStyle: display.iconStyle,
    cardStyle: {
      background: backgroundTint,
      borderColor,
    },
    activeCardStyle: {
      background: backgroundTint,
      borderColor,
      boxShadow: `0 0 0 1px ${borderColor}`,
    },
  };
}

export const ASN_SUMMARY_CARDS = [
  cardFromStatus("pending", "pending", "Pending ASNs"),
  cardFromStatus("in_progress", "in_progress", "Processing ASNs"),
  cardFromStatus("completed", "completed", "Completed ASNs"),
  cardFromStatus("non_compliant", "non_compliant", "Non-Compliant ASNs"),
];
