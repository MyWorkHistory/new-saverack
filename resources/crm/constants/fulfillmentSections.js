export const RTS_ORDER_DATE_FROM = "2026-05-01";
export const ON_HOLD_ORDER_DATE_FROM = "2026-02-01";

export const FULFILLMENT_SECTIONS = [
  {
    key: "ready_to_ship",
    label: "Ready to Ship",
    titleUpper: "READY TO SHIP",
    sub: "Total Orders",
    icon: "inventoryBox",
    titleColor: "#2563eb",
    iconStyle: { background: "#dbeafe", color: "#2563eb" },
    routeName: "orders-awaiting",
    pillVariant: "neutral",
  },
  {
    key: "shipped",
    label: "Shipped Today",
    titleUpper: "SHIPPED TODAY",
    sub: "Total Shipments",
    icon: "truck",
    titleColor: "#16a34a",
    iconStyle: { background: "#dcfce7", color: "#16a34a" },
    routeName: "orders-shipped",
    metaSuffix: "(today)",
    pillVariant: "success",
    emptyMessage: "No shipments in snapshot for today.",
  },
];
