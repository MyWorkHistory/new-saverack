/** Matches Home dashboard pill — distinct on-hold orders (not a sum of hold-type cards). */
export const ON_HOLD_TOTAL_CARD = {
  key: "on_hold",
  label: "On-Hold",
  titleUpper: "ALL ON-HOLD",
  sub: "Distinct orders on hold (matches Home)",
  icon: "hourglass",
  holdReason: null,
  titleColor: "#b45309",
  iconStyle: { background: "#fef3c7", color: "#b45309" },
};

/** Hold-type breakdown cards (backorder last) — orders with multiple holds may appear in more than one card. */
export const HOLD_TYPE_SECTIONS = [
  {
    key: "hold_operator",
    label: "Operator Hold",
    titleUpper: "OPERATOR HOLD",
    sub: "Orders on hold by operator",
    icon: "supportAgent",
    holdReason: "operator",
    titleColor: "#2563eb",
    iconStyle: { background: "#dbeafe", color: "#2563eb" },
  },
  {
    key: "hold_address",
    label: "Address Hold",
    titleUpper: "ADDRESS HOLD",
    sub: "Orders on hold for address issues",
    icon: "location",
    holdReason: "address",
    titleColor: "#ea580c",
    iconStyle: { background: "#ffedd5", color: "#ea580c" },
  },
  {
    key: "hold_fraud",
    label: "Fraud Hold",
    titleUpper: "FRAUD HOLD",
    sub: "Orders on hold for fraud review",
    icon: "gppBad",
    holdReason: "fraud",
    titleColor: "#dc2626",
    iconStyle: { background: "#fee2e2", color: "#dc2626" },
  },
  {
    key: "hold_payment",
    label: "Payment Hold",
    titleUpper: "PAYMENT HOLD",
    sub: "Orders on hold for payment issues",
    icon: "payments",
    holdReason: "payment",
    titleColor: "#d97706",
    iconStyle: { background: "#fef3c7", color: "#d97706" },
  },
  {
    key: "hold_user",
    label: "User Hold",
    titleUpper: "USER HOLD",
    sub: "Orders on hold by user request",
    icon: "account",
    holdReason: "user",
    titleColor: "#7c3aed",
    iconStyle: { background: "#f3e8ff", color: "#7c3aed" },
  },
  {
    key: "hold_backorder",
    label: "Backorder",
    titleUpper: "BACKORDER",
    sub: "Orders on hold due to inventory shortage",
    icon: "localShipping",
    holdReason: null,
    titleColor: "#dc2626",
    iconStyle: { background: "#fee2e2", color: "#dc2626" },
  },
];

/** @deprecated Use HOLD_TYPE_SECTIONS (includes backorder as last card). */
export const HOLD_BACKORDER_CARD = HOLD_TYPE_SECTIONS[HOLD_TYPE_SECTIONS.length - 1];

/** @deprecated Prefer ON_HOLD_TOTAL_CARD + HOLD_TYPE_SECTIONS. */
export const HOLD_SECTIONS = [...HOLD_TYPE_SECTIONS];
