<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import OrdersRemoveHoldsModal from "../../components/orders/OrdersRemoveHoldsModal.vue";
import OrdersPlaceHoldModal from "../../components/orders/OrdersPlaceHoldModal.vue";
import AsnProductCatalogPanel from "../../components/inventory/AsnProductCatalogPanel.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { usePortalLastRefreshed } from "../../composables/usePortalLastRefreshed.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin, crmIsPortalUser } from "../../utils/crmUser";
import { canWriteShipHeroOrders } from "../../utils/crmShipHeroOrders";
import {
  carrierForApi,
  formatCarrierLabel,
  formatCarrierTrackingLine,
  formatCurrentShippingMethod,
} from "../../utils/orderShippingDisplay.js";

const props = defineProps({
  portalReturnPreview: { type: Boolean, default: false },
  /** When nested inside another staff-page (e.g. return create order preview), omit duplicate page shell. */
  embeddedInParent: { type: Boolean, default: false },
});

const crmUser = inject("crmUser", ref(null));
const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(false);
const refreshing = ref(false);
/** Prevents a second load when stripping ?refresh=1 from the URL after list navigation. */
const skipOrderLoadFromRouteWatch = ref(false);
const { markRefreshed, lastRefreshedLabel, lastRefreshedAt } = usePortalLastRefreshed();
const order = ref(null);
const selectedAccountId = ref(String(route.query.client_account_id || ""));
/** Set from order detail API / session when URL omits client_account_id (common on portal). */
const lastOrderAccountId = ref(Number(route.query.client_account_id || 0) || 0);
const loadError = ref("");
const loadNotice = ref("");
const activeLoadKey = ref("");
const itemSortKey = ref("name");
const itemSortDir = ref("asc");
const confirmFulfilledOpen = ref(false);
const confirmCancelOpen = ref(false);
const actionBusy = ref(false);
const readyToShipBusy = ref(false);

const shippingModalOpen = ref(false);
const shippingSaveBusy = ref(false);
const shippingForm = ref({
  first_name: "",
  last_name: "",
  company: "",
  address1: "",
  address2: "",
  phone: "",
  city: "",
  state: "",
  country: "",
  zip: "",
  email: "",
});

const carrierField = ref("");
const methodField = ref("");
const shippingLinesSaveBusy = ref(false);

const allowPartialLocal = ref(false);
const allowPartialSaveBusy = ref(false);

const tagsLocal = ref([]);
const tagInputValue = ref("");
const tagsSaveBusy = ref(false);

const addItemsModalOpen = ref(false);
const addItemsBusy = ref(false);
const addItemsCatalogPanelKey = ref(0);
const addNewSkuOpen = ref(false);
const addNewSkuBusy = ref(false);
const addNewSkuName = ref("");
const addNewSkuSku = ref("");

const attachmentFileInput = ref(null);
const attachmentUploadBusy = ref(false);

const removeHoldsModalOpen = ref(false);
const removeHoldsBusy = ref(false);
const placeHoldModalOpen = ref(false);
const placeHoldBusy = ref(false);
const moreActionsOpen = ref(false);
const moreActionsBtnRef = ref(null);
const moreActionsMenuRef = ref(null);
const moreActionsMenuStyle = ref({ visibility: "hidden" });
const moreActionsLayoutBound = ref(false);
const requireSignatureLocal = ref(false);
const giftNoteLocal = ref("");
const optionsSaveBusy = ref(false);
const packingNoteLocal = ref("");
const packingNoteSaveBusy = ref(false);

const editLineModalOpen = ref(false);
const editLineBusy = ref(false);
const editLineRow = ref(null);
const editQtyPending = ref(0);

const confirmDeleteLineOpen = ref(false);
const lineDeleteBusy = ref(false);
const deleteLineRow = ref(null);

const itemMenuOpenId = ref(null);
const itemMenuRect = ref({ top: 0, left: 0 });

const CARRIER_PRESETS = ["Cheapest", "ups", "fedex", "usps", "dhl", "asendia_one", "ontrac", "lasership"];
const METHOD_PRESETS = ["Select", "Ground", "Priority", "Express", "Standard", "A124"];

/** Curated labels aligned with ShipHero; API does not expose a public carrier→method list in this app. */
const METHOD_OPTIONS_BY_CARRIER = {
  cheapest: ["Select", "Ground", "Priority", "Express", "Standard", "A124"],
  ups: ["Ground", "3 Day Select", "2nd Day Air", "Next Day Air Saver", "Next Day Air", "Standard", "Priority", "Express"],
  fedex: [
    "Ground",
    "Home Delivery",
    "Express Saver",
    "2Day",
    "Standard Overnight",
    "Priority Overnight",
    "International Priority",
    "International Economy",
  ],
  usps: ["First Class", "Priority Mail", "Priority Mail Express", "Parcel Select Ground", "Media Mail", "Ground"],
  dhl: ["Express Worldwide", "Express 12", "Express 9", "Express Easy"],
  asendia_one: ["Select", "Ground", "Priority", "Express", "Standard"],
  ontrac: ["Ground", "Express"],
  lasership: ["Select", "Ground", "Next Day"],
};

function carrierPresetKey(carrier) {
  return String(carrier || "").trim().toLowerCase();
}

function resolveCarrierPreset(carrier) {
  const raw = String(carrier || "").trim();
  if (!raw) return "";
  const key = carrierPresetKey(raw);
  for (const p of CARRIER_PRESETS) {
    if (carrierPresetKey(p) === key) return p;
  }
  return formatCarrierLabel(raw) || raw;
}

const orderId = computed(() => String(route.params.shipheroOrderId || ""));

const isDraftOrder = computed(() => order.value?.is_draft === true);
const draftId = computed(() => Number(order.value?.draft_id || 0));

const isPortalUser = computed(() => crmIsPortalUser(crmUser.value));
const portalClientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const isUserPortalRoute = computed(() => route.meta?.userPortal === true);

function accountIdFromSessionSnapshot() {
  const oid = orderId.value;
  if (!oid) return 0;
  const prefix = "orders.snapshot.";
  const suffix = `.${oid}`;
  try {
    for (let i = 0; i < sessionStorage.length; i++) {
      const key = sessionStorage.key(i);
      if (!key?.startsWith(prefix) || !key.endsWith(suffix)) continue;
      const accountPart = key.slice(prefix.length, key.length - suffix.length);
      const id = Number(accountPart || 0);
      if (id > 0) return id;
    }
  } catch (_) {
    /* ignore quota / private mode */
  }
  return 0;
}

function normalizeAccountId(raw) {
  if (raw == null || raw === "") return 0;
  const s = Array.isArray(raw) ? String(raw[0] ?? "") : String(raw);
  const id = Number(s.trim());
  return Number.isFinite(id) && id > 0 ? id : 0;
}

function resolveClientAccountIdForOrderContext() {
  const sources = [
    route.query.client_account_id,
    selectedAccountId.value,
    lastOrderAccountId.value,
    crmUser.value?.client_account_id,
    accountIdFromSessionSnapshot(),
  ];
  for (const raw of sources) {
    const id = normalizeAccountId(raw);
    if (id > 0) return id;
  }
  return 0;
}

function applyOrderDetailAccountMeta(data) {
  const id = Number(data?.client_account_id ?? 0);
  if (id <= 0) return;
  lastOrderAccountId.value = id;
  const next = String(id);
  if (selectedAccountId.value !== next) {
    selectedAccountId.value = next;
  }
}

const headingOrderNumber = computed(() => String(order.value?.order_number || "—").replace(/^#\s*/, ""));
const statusClass = computed(() => {
  const raw = String(
    order.value?.status || order.value?.raw_fulfillment_status || ""
  ).toLowerCase();
  if (raw.includes("hold") || raw.includes("backorder")) return "text-danger bg-danger-subtle";
  if (raw.includes("unfulfilled")) return "text-secondary bg-secondary-subtle";
  if (raw.includes("incomplete")) return "text-secondary bg-secondary-subtle";
  if (raw.includes("fulfilled") || raw === "complete" || raw.includes("complete")) {
    return "text-success bg-success-subtle";
  }
  if (raw.includes("ship")) return "text-success bg-success-subtle";
  return "text-secondary bg-secondary-subtle";
});

const isReturnPreviewMode = computed(() => props.portalReturnPreview === true);

const orderDetailRootClass = computed(() =>
  props.embeddedInParent
    ? "order-detail-page order-detail-page--embedded"
    : "staff-page staff-page--wide order-detail-page",
);

const canRunShipHeroActions = computed(
  () => !isReturnPreviewMode.value && canWriteShipHeroOrders(crmUser.value),
);

const canViewProductCatalog = computed(() => {
  const user = crmUser.value;
  if (!user || typeof user !== "object") return false;
  if (crmIsPortalUser(user) && Number(user.client_account_id || 0) > 0) return true;
  if (crmIsAdmin(user) || user.is_crm_owner === true || user.is_crm_owner === 1) return true;
  const keys = Array.isArray(user.permission_keys) ? user.permission_keys : [];

  return keys.includes("inventory.view");
});

const catalogClientAccountId = computed(() => resolveClientAccountIdForOrderContext());

const hasOrderAccountContext = computed(() => catalogClientAccountId.value > 0);

const addItemsCatalogDeniedMessage = computed(() => {
  if (!canViewProductCatalog.value) {
    return "Inventory view permission is required to pick SKUs from the catalog.";
  }
  if (catalogClientAccountId.value <= 0 && !isUserPortalRoute.value && !isPortalUser.value) {
    return "Select a client account to load products. Open this order from the Orders list or add ?client_account_id= to the URL.";
  }

  return "";
});

const canCreateCatalogSku = computed(
  () =>
    canViewProductCatalog.value
    && (catalogClientAccountId.value > 0 || isUserPortalRoute.value || isPortalUser.value),
);

const addItemsCreateSkuRoute = computed(() => {
  const id = catalogClientAccountId.value;
  if (id <= 0 || isPortalUser.value) return null;

  return {
    name: "inventory-on-demand",
    query: { client_account_id: String(id) },
  };
});

const canUseStaffOrderHeaderActions = computed(
  () => Boolean(order.value) && !isReturnPreviewMode.value,
);

const portalPrimaryBtnClass = "btn btn-primary btn-sm staff-page-primary";

const addItemsBtnClass = computed(() =>
  isPortalUser.value
    ? portalPrimaryBtnClass
    : "btn btn-outline-secondary btn-sm order-detail-page__add-items-btn",
);

const shippingEditBtnClass = computed(() =>
  isPortalUser.value ? portalPrimaryBtnClass : "btn btn-outline-secondary btn-sm",
);

const moreActionsBtnClass = computed(() =>
  isPortalUser.value
    ? "btn btn-outline-secondary dropdown-toggle order-detail-page__more-actions-toggle orders-toolbar-outline-btn"
    : "btn btn-outline-secondary dropdown-toggle order-detail-page__more-actions-toggle",
);

function orderHasActiveHold(o) {
  if (!o || typeof o !== "object") return false;
  if (o.has_active_hold === true) return true;
  const h = o.holds;
  if (h && typeof h === "object") {
    return Object.values(h).some((v) => v === true);
  }
  return false;
}

const showNotReadyToShipBanner = computed(
  () => order.value && !isDraftOrder.value && orderHasActiveHold(order.value),
);

const detailHoldsNormalized = computed(() => {
  const h = order.value?.holds;
  const o = h && typeof h === "object" ? h : {};
  return {
    fraud_hold: !!o.fraud_hold,
    address_hold: !!o.address_hold,
    shipping_method_hold: !!o.shipping_method_hold,
    operator_hold: !!o.operator_hold,
    payment_hold: !!o.payment_hold,
    client_hold: !!o.client_hold,
  };
});

const detailIsCrmUserHold = computed(() => {
  if (order.value?.is_crm_user_hold === true) return true;
  const h = detailHoldsNormalized.value;
  if (h.client_hold) return true;
  const tags = Array.isArray(order.value?.tags) ? order.value.tags : [];
  return !!(h.operator_hold && tags.includes("saverack:user_hold"));
});

const detailHasRemovableHolds = computed(() => {
  const h = detailHoldsNormalized.value;
  return !!(h.fraud_hold || h.address_hold || h.payment_hold || detailIsCrmUserHold.value);
});

/** Hold flags for Remove Holds modal (User Hold uses operator_hold + tag, not client_hold). */
const detailHoldsForRemoveModal = computed(() => {
  const h = detailHoldsNormalized.value;
  return {
    fraud_hold: h.fraud_hold,
    address_hold: h.address_hold,
    payment_hold: h.payment_hold,
    client_hold: detailIsCrmUserHold.value,
  };
});

/** CRM User Hold (operator_hold + tag, or legacy client_hold). */
const detailOnlyCrmUserHold = computed(() => {
  const h = detailHoldsNormalized.value;
  if (!detailIsCrmUserHold.value) return false;
  return !(h.fraud_hold || h.address_hold || h.payment_hold || h.shipping_method_hold);
});

/** Warehouse operator hold only — not clearable as user hold from CRM. */
const detailOnlyOperatorHold = computed(() => {
  const h = detailHoldsNormalized.value;
  if (!h.operator_hold || detailIsCrmUserHold.value) return false;
  return !(h.fraud_hold || h.address_hold || h.payment_hold || h.client_hold || h.shipping_method_hold);
});

const orderIsShipped = computed(() => {
  const o = order.value;
  if (!o) return false;
  const s = String(o.status || o.raw_fulfillment_status || "")
    .toLowerCase()
    .trim();
  if (!s) return false;
  if (s.includes("cancel")) return false;
  return (
    s === "shipped" ||
    s === "fulfilled" ||
    s === "complete" ||
    s.startsWith("shipped") ||
    s.includes("fulfilled")
  );
});

const orderIsReadyToShip = computed(() => {
  const o = order.value;
  if (!o || isDraftOrder.value || showNotReadyToShipBanner.value || showBackorderHeaderBadge.value || orderIsShipped.value) {
    return false;
  }
  const s = String(o.status || o.raw_fulfillment_status || "")
    .toLowerCase()
    .trim();
  if (s.includes("cancel")) return false;
  if (s.includes("hold")) return false;
  return (
    s === "" ||
    s.includes("unfulfilled") ||
    s.includes("await") ||
    s.includes("pending") ||
    s.includes("ready") ||
    s.includes("open") ||
    s.includes("incomplete")
  );
});

const orderHeaderBadgeLabel = computed(() => {
  if (isDraftOrder.value) return "Draft";
  if (showNotReadyToShipBanner.value) return "On Hold";
  if (showBackorderHeaderBadge.value) return "Backorder";
  if (orderIsShipped.value) return "Shipped";
  if (orderIsReadyToShip.value) return "Ready to Ship";
  return "";
});

const showOrderHeaderBadge = computed(() => orderHeaderBadgeLabel.value !== "");

const orderHeaderBadgeClass = computed(() => {
  if (isDraftOrder.value) {
    return "badge rounded-pill fw-medium text-warning-emphasis bg-warning-subtle";
  }
  if (showNotReadyToShipBanner.value) {
    return "badge rounded-pill fw-medium text-danger-emphasis bg-danger-subtle";
  }
  if (showBackorderHeaderBadge.value) {
    return "badge rounded-pill fw-medium text-danger-emphasis bg-danger-subtle";
  }
  if (orderIsShipped.value) {
    return "badge rounded-pill fw-medium text-success-emphasis bg-success-subtle";
  }
  if (orderIsReadyToShip.value) {
    return "badge rounded-pill fw-medium text-primary-emphasis bg-primary-subtle";
  }
  return "badge rounded-pill fw-medium";
});

const orderHasBackorderLines = computed(() => {
  const rows = order.value?.items;
  if (!Array.isArray(rows)) return false;
  return rows.some((r) => Number(r?.backorder_quantity ?? 0) > 0);
});

const orderIsTerminalFulfillment = computed(() => {
  const o = order.value;
  if (!o) return false;
  const s = String(o.status || o.raw_fulfillment_status || "")
    .toLowerCase()
    .trim();
  if (!s) return false;
  if (s === "shipped" || s === "fulfilled" || s === "complete" || s.startsWith("shipped")) return true;
  if (s === "canceled" || s === "cancelled") return true;
  return false;
});

const showBackorderHeaderBadge = computed(
  () =>
    orderHasBackorderLines.value &&
    !orderIsTerminalFulfillment.value &&
    !showNotReadyToShipBanner.value,
);

const shipheroAdminUrl = computed(() => {
  const legacyId = order.value?.legacy_id;
  if (legacyId == null || legacyId === "") return null;
  const n = Number(legacyId);
  if (!Number.isFinite(n) || n <= 0) return null;
  return `https://app.shiphero.com/dashboard/orders/details/${n}`;
});

const hasUserHold = computed(() => detailIsCrmUserHold.value);

const canPlaceHold = computed(
  () => canRunShipHeroActions.value && !orderIsTerminalFulfillment.value,
);

const showRemoveHoldBtn = computed(
  () =>
    showNotReadyToShipBanner.value &&
    detailHasRemovableHolds.value &&
    canRunShipHeroActions.value,
);

/** Admin sidebar hold guidance (not shown on portal order view). */
const showAdminSidebarHoldNote = computed(
  () =>
    !isPortalUser.value &&
    !isReturnPreviewMode.value &&
    Boolean(order.value) &&
    showNotReadyToShipBanner.value &&
    (detailOnlyOperatorHold.value ||
      (detailOnlyCrmUserHold.value && showRemoveHoldBtn.value)),
);

const sortedItems = computed(() => {
  const rows = Array.isArray(order.value?.items) ? [...order.value.items] : [];
  const dir = itemSortDir.value === "desc" ? -1 : 1;
  const key = itemSortKey.value;
  const numericKeys = new Set([
    "quantity",
    "quantity_allocated",
    "quantity_pending_fulfillment",
    "backorder_quantity",
  ]);
  rows.sort((a, b) => {
    if (numericKeys.has(key)) {
      const na = Number(a?.[key] ?? 0);
      const nb = Number(b?.[key] ?? 0);
      return (na - nb) * dir;
    }
    const av = a?.[key];
    const bv = b?.[key];
    if (typeof av === "number" || typeof bv === "number") {
      const na = Number(av ?? 0);
      const nb = Number(bv ?? 0);
      return (na - nb) * dir;
    }
    const sa = String(av ?? "").toLowerCase();
    const sb = String(bv ?? "").toLowerCase();
    return sa.localeCompare(sb) * dir;
  });
  return rows;
});

function inventoryClientAccountIdForDetail() {
  return resolveClientAccountIdForOrderContext();
}

function inventoryDetailRouteForItem(item) {
  const sku = String(item?.sku || "").trim();
  if (!sku) return null;
  const query = {};
  const clientAccountId = inventoryClientAccountIdForDetail();
  if (clientAccountId > 0) {
    query.client_account_id = String(clientAccountId);
  }
  return {
    name: isPortalUser.value ? "user-inventory-detail" : "inventory-detail",
    params: { sku },
    query,
  };
}

function inventoryDetailHref(item) {
  const route = inventoryDetailRouteForItem(item);
  if (!route) return "";
  return router.resolve(route).href;
}

function openItemInventoryInNewTab(item, event) {
  if (event) {
    event.preventDefault();
    event.stopPropagation();
  }
  const href = inventoryDetailHref(item);
  if (!href) return;
  window.open(href, "_blank", "noopener,noreferrer");
}

function itemRowMenuKey(row) {
  if (!row || typeof row !== "object") return "";
  if (row.id) return String(row.id).trim();
  return `sku:${String(row.sku || "").trim()}`;
}

const itemMenuOpenRow = computed(() => {
  const id = itemMenuOpenId.value;
  if (!id) return null;
  const rows = sortedItems.value;
  return rows.find((r) => itemRowMenuKey(r) === id) ?? null;
});

const formattedShippingAddress = computed(() => {
  const a = order.value?.shipping_address;
  if (!a || typeof a !== "object") return "";
  const parts = [];
  const name = [a.first_name, a.last_name].filter(Boolean).join(" ").trim();
  if (name) parts.push(name);
  if (a.company) parts.push(String(a.company));
  const line1 = [a.address1, a.address2].filter(Boolean).join(", ").trim();
  if (line1) parts.push(line1);
  const cityLine = [a.city, a.state, a.zip].filter(Boolean).join(", ").trim();
  if (cityLine) parts.push(cityLine);
  if (a.country) parts.push(String(a.country));
  return parts.length ? parts.join("\n") : "—";
});

const customerDisplayName = computed(() => {
  const a = order.value?.shipping_address;
  if (!a || typeof a !== "object") return "";
  const name = [a.first_name, a.last_name].filter(Boolean).join(" ").trim();
  return name || "—";
});

const shippingAddressDisplayCaps = computed(() => {
  const t = formattedShippingAddress.value;
  if (!t || t === "—") return "—";
  return t.toUpperCase();
});

const currentShippingMethodDisplay = computed(() => {
  const o = order.value;
  if (!o) return "—";
  return formatCurrentShippingMethod(
    o.shipping_carrier,
    o.method,
    o.shipping_line?.title,
  );
});

const trackingLabels = computed(() => {
  const rows = order.value?.tracking_labels;
  return Array.isArray(rows) ? rows : [];
});

const trackingLabelCostDisplay = computed(() => {
  const cost = order.value?.total_label_cost;
  if (cost === null || cost === undefined || Number.isNaN(Number(cost))) {
    return null;
  }
  const n = Number(cost);
  return `$${n.toFixed(2)}`;
});

const carrierSelectOptions = computed(() => {
  const labels = new Map();
  for (const p of CARRIER_PRESETS) {
    labels.set(carrierPresetKey(p), p);
  }
  const cur = String(carrierField.value || "").trim();
  const curKey = carrierPresetKey(cur);
  if (curKey && !labels.has(curKey)) {
    labels.set(curKey, resolveCarrierPreset(cur));
  }
  return ["", ...labels.values()];
});

const methodSelectOptions = computed(() => {
  const key = carrierPresetKey(carrierField.value);
  const baseList = METHOD_OPTIONS_BY_CARRIER[key] || METHOD_PRESETS;
  const cur = String(methodField.value || "").trim();
  const out = ["", ...baseList];
  if (cur && !baseList.includes(cur) && !out.includes(cur)) {
    out.push(cur);
  }
  return out;
});

watch(carrierField, (newCar, oldCar) => {
  if (carrierPresetKey(newCar) === carrierPresetKey(oldCar)) return;
  const key = carrierPresetKey(newCar);
  const baseList = METHOD_OPTIONS_BY_CARRIER[key];
  if (!baseList) return;
  const m = String(methodField.value || "").trim();
  if (m !== "" && !baseList.includes(m)) {
    methodField.value = "";
  }
});

const notReadyBannerBullets = computed(() => {
  const o = order.value;
  if (!o) return [];
  const sub = String(o.not_ready_subtitle || "").trim();
  let text = sub;
  if (!text) {
    const hr = String(o.hold_reason || "").trim();
    if (hr) text = `Order has ${hr.toLowerCase()}.`;
    else text = "Order has a hold.";
  }
  const parts = text.split(/(?<=\.)\s+/).map((s) => s.trim()).filter(Boolean);
  return parts.length ? parts : [text];
});

function fmtDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString();
}

function fmtCreationDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString("en-US", {
    month: "2-digit",
    day: "2-digit",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
    hour12: true,
  });
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function sanitizeHistoryHtml(value) {
  const raw = String(value || "");
  if (!raw.trim()) return "—";
  if (typeof window === "undefined" || typeof DOMParser === "undefined") {
    return escapeHtml(raw);
  }

  const allowedTags = new Set(["P", "UL", "OL", "LI", "BR", "STRONG", "EM", "B", "I"]);
  const parser = new DOMParser();
  const doc = parser.parseFromString(raw, "text/html");
  const nodes = [doc.body];

  while (nodes.length > 0) {
    const current = nodes.pop();
    if (!current || !current.childNodes) continue;
    const children = Array.from(current.childNodes);
    for (const child of children) {
      if (child.nodeType === Node.ELEMENT_NODE) {
        const el = child;
        const tag = el.tagName.toUpperCase();
        if (!allowedTags.has(tag)) {
          const replacement = doc.createTextNode(el.textContent || "");
          el.replaceWith(replacement);
          continue;
        }
        Array.from(el.attributes || []).forEach((attr) => {
          el.removeAttribute(attr.name);
        });
        nodes.push(el);
      }
    }
  }

  const cleaned = (doc.body.innerHTML || "").trim();
  return cleaned !== "" ? cleaned : escapeHtml(raw);
}

function toggleItemSort(key) {
  if (itemSortKey.value === key) {
    itemSortDir.value = itemSortDir.value === "asc" ? "desc" : "asc";
    return;
  }
  itemSortKey.value = key;
  itemSortDir.value = "asc";
}

function sortIndicator(key) {
  if (itemSortKey.value !== key) return "↕";
  return itemSortDir.value === "asc" ? "↑" : "↓";
}

function extractErrorMessage(e) {
  const payload = e?.response?.data;
  if (payload && typeof payload === "object") {
    const title = typeof payload.title === "string" ? payload.title.trim() : "";
    const detail = typeof payload.detail === "string" ? payload.detail.trim() : "";
    const ownerAction =
      typeof payload.what_you_should_do === "string"
        ? payload.what_you_should_do.replace(/\*\*/g, "").trim()
        : "";
    if (title && ownerAction) return `${title} ${ownerAction}`;
    if (title && detail) return `${title} ${detail}`;
    if (title) return title;
    if (detail) return detail;
    if (ownerAction) return ownerAction;
  }
  const msg = e?.response?.data?.message;
  if (typeof msg === "string" && msg.trim() !== "") return msg;
  if (e?.message) return String(e.message);
  return "Could not load order details.";
}

function fallbackOrderSnapshot() {
  const accountId = resolveClientAccountIdForOrderContext();
  if (accountId <= 0 || !orderId.value) return null;
  const key = `orders.snapshot.${accountId}.${orderId.value}`;
  try {
    const raw = sessionStorage.getItem(key);
    if (!raw) return null;
    const row = JSON.parse(raw);
    if (!row || typeof row !== "object") return null;
    return {
      id: String(row.id || orderId.value),
      legacy_id: row.legacy_id ?? null,
      order_number: row.order_number || "",
      partner_order_id: "",
      status: row.status || "",
      hold_reason: row.hold_reason || null,
      holds: {
        fraud_hold: false,
        address_hold: false,
        shipping_method_hold: false,
        operator_hold: false,
        payment_hold: false,
        client_hold: false,
      },
      has_active_hold: !!(row.hold_reason && String(row.hold_reason).trim() !== ""),
      not_ready_subtitle: "",
      order_date: row.order_date || null,
      required_ship_date: null,
      account: row.account || "",
      email: row.email || "",
      shipping_carrier: row.shipping_carrier || "",
      method: row.method || "",
      shipping_cost: null,
      subtotal: null,
      total_tax: null,
      total_discounts: null,
      total_price: null,
      gift_invoice: false,
      allow_partial: false,
      require_signature: false,
      packing_note: null,
      gift_note: "",
      tags: [],
      attachments: [],
      shipping_line: {
        title: "Shipping",
        carrier: row.shipping_carrier || "",
        method: row.method || "",
        price: "0",
      },
      shipping_address: {
        first_name: "",
        last_name: "",
        company: "",
        address1: "",
        address2: "",
        city: "",
        state: "",
        state_code: "",
        zip: "",
        country: row.country || "",
        country_code: "",
        email: "",
        phone: "",
      },
      billing_address: {},
      items: [],
      history: [],
    };
  } catch (_) {
    return null;
  }
}

function orderDetailFetchedAtKey() {
  return `orders.detail.fetchedAt.${selectedAccountId.value}.${orderId.value}`;
}

function recordOrderDetailFetchedAt() {
  if (!selectedAccountId.value || !orderId.value) return;
  try {
    sessionStorage.setItem(orderDetailFetchedAtKey(), String(Date.now()));
  } catch (_) {
    // ignore quota errors
  }
}

function applyLastRefreshedFromResponse(data, didRefresh) {
  const iso = typeof data?.cached_at === "string" ? data.cached_at.trim() : "";
  if (iso) {
    const d = new Date(iso);
    if (!Number.isNaN(d.getTime())) {
      lastRefreshedAt.value = d;
      return;
    }
  }
  if (!data?.cached || didRefresh) {
    markRefreshed();
  }
}

async function loadOrder({ refresh = false } = {}) {
  loadError.value = "";
  loadNotice.value = "";
  const accountId = resolveClientAccountIdForOrderContext();
  if (accountId <= 0 || !orderId.value) {
    order.value = null;
    return;
  }
  if (!selectedAccountId.value) {
    selectedAccountId.value = String(accountId);
  }
  const requestKey = `${accountId}:${orderId.value}:${refresh ? "r" : "c"}`;
  if ((loading.value || refreshing.value) && activeLoadKey.value === requestKey) {
    return;
  }
  activeLoadKey.value = requestKey;
  const sameOrder = String(order.value?.id || "") === orderId.value;
  if (!sameOrder) {
  loading.value = true;
  order.value = null;
  } else if (refresh) {
    refreshing.value = true;
  } else if (!order.value) {
    loading.value = true;
  }
  itemMenuOpenId.value = null;
  try {
    const params = { client_account_id: accountId };
    if (refresh) params.refresh = 1;
    const { data } = await api.get(`/orders/${encodeURIComponent(orderId.value)}`, { params });
    applyOrderDetailAccountMeta(data);
    order.value = data?.order ?? null;
    if (order.value) {
      recordOrderDetailFetchedAt();
      if (isPortalUser.value || isUserPortalRoute.value) {
        applyLastRefreshedFromResponse(data, refresh);
      }
    }
    if (data?.fallback?.source) {
      loadNotice.value = "Live detail endpoint was temporarily unavailable. Showing summary data from orders list.";
    }
    if (!order.value) {
      loadError.value = "ShipHero returned no order for this id and account.";
      toast.error("Order not found.");
    }
  } catch (e) {
      const cached = fallbackOrderSnapshot();
      if (cached) {
        order.value = cached;
        loadNotice.value = "Live detail endpoint was temporarily unavailable. Showing cached summary from this browser.";
      } else {
        loadError.value = extractErrorMessage(e);
        order.value = null;
    }
    toast.errorFrom(e, "Could not load order details.");
  } finally {
    loading.value = false;
    refreshing.value = false;
    if (activeLoadKey.value === requestKey) {
      activeLoadKey.value = "";
    }
  }
}

async function refreshOrderDetail() {
  if (loading.value || refreshing.value) return;
  await loadOrder({ refresh: true });
}

async function submitReadyToShip() {
  if (!isDraftOrder.value || !canRunShipHeroActions.value || readyToShipBusy.value) return;
  const method = String(methodField.value || "").trim();
  if (!method || method.toLowerCase() === "select") {
    toast.error("Select a shipping method before marking this order Ready to Ship.");
    return;
  }
  const draft = draftId.value;
  if (draft <= 0) {
    toast.error("This draft order could not be identified.");
    return;
  }
  readyToShipBusy.value = true;
  try {
    const { data } = await api.post(`/order-drafts/${draft}/ready-to-ship`);
    const shipheroOrderId = String(data?.shiphero_order_id || "");
    const clientAccountId = Number(data?.client_account_id || resolveClientAccountIdForOrderContext());
    if (!shipheroOrderId) {
      toast.error("Order was submitted but ShipHero did not return an order id.");
      return;
    }
    toast.success("Order sent to ShipHero.");
    const detailName = isPortalUser.value || isUserPortalRoute.value ? "user-order-detail" : "order-detail";
    await router.replace({
      name: detailName,
      params: { shipheroOrderId },
      query: clientAccountId > 0 ? { client_account_id: String(clientAccountId) } : {},
    });
  } catch (e) {
    toast.errorFrom(e, "Could not send order to ShipHero.");
  } finally {
    readyToShipBusy.value = false;
  }
}

watch(
  () => [route.query.client_account_id, portalClientAccountId.value, isPortalUser.value],
  () => {
    const q = route.query.client_account_id;
    const fromQuery = normalizeAccountId(q);
    if (fromQuery > 0) {
      const next = String(fromQuery);
      if (next !== selectedAccountId.value) {
        selectedAccountId.value = next;
      }
      return;
    }
    if (isPortalUser.value && portalClientAccountId.value > 0) {
      const fallback = String(portalClientAccountId.value);
      if (selectedAccountId.value !== fallback) {
        selectedAccountId.value = fallback;
      }
      return;
    }
    if (selectedAccountId.value !== "") {
      selectedAccountId.value = "";
    }
  },
  { immediate: true },
);

watch(
  () => [orderId.value, selectedAccountId.value],
  () => {
    if (skipOrderLoadFromRouteWatch.value) {
      skipOrderLoadFromRouteWatch.value = false;
      return;
    }
    if (resolveClientAccountIdForOrderContext() > 0 && orderId.value) {
      const forceRefresh =
        route.query.refresh === "1" || route.query.refresh === 1 || route.query.refresh === true;
      void loadOrder({ refresh: forceRefresh }).then(() => {
        if (forceRefresh && isPortalUser.value) {
          skipOrderLoadFromRouteWatch.value = true;
          const q = { ...route.query };
          delete q.refresh;
          router.replace({ query: q });
        }
      });
    } else {
      order.value = null;
      loadError.value = "";
    }
  },
  { immediate: true },
);

function closeActionConfirms() {
  confirmFulfilledOpen.value = false;
  confirmCancelOpen.value = false;
  removeHoldsModalOpen.value = false;
  confirmDeleteLineOpen.value = false;
}

async function runMarkFulfilled() {
  if (!order.value || !selectedAccountId.value || !orderId.value) return;
  actionBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/mark-fulfilled`, {
      client_account_id: Number(selectedAccountId.value),
    });
    toast.success("Order marked fulfilled.");
    closeActionConfirms();
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not mark order fulfilled.");
  } finally {
    actionBusy.value = false;
  }
}

async function runCancelOrder() {
  if (!order.value || !selectedAccountId.value || !orderId.value) return;
  actionBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/cancel`, {
      client_account_id: Number(selectedAccountId.value),
    });
    toast.success("Order canceled.");
    closeActionConfirms();
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not cancel order.");
  } finally {
    actionBusy.value = false;
  }
}

function closeRemoveHoldsModal() {
  if (removeHoldsBusy.value) return;
  removeHoldsModalOpen.value = false;
}

async function onRemoveHoldsConfirm(payload) {
  if (!order.value || !selectedAccountId.value || !orderId.value) return;
  if (!payload?.holds_to_clear?.length) return;
  removeHoldsBusy.value = true;
  try {
    const body = {
      client_account_id: Number(selectedAccountId.value),
      holds_to_clear: payload.holds_to_clear,
    };
    if (payload.payment_hold_reason) {
      body.payment_hold_reason = payload.payment_hold_reason;
    }
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/remove-holds`, body);
    toast.success("Holds removed.");
    removeHoldsBusy.value = false;
    removeHoldsModalOpen.value = false;
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not remove holds.");
  } finally {
    removeHoldsBusy.value = false;
  }
}

function openPlaceHoldModal() {
  if (!canPlaceHold.value) return;
  placeHoldModalOpen.value = true;
}

function closePlaceHoldModal() {
  if (placeHoldBusy.value) return;
  placeHoldModalOpen.value = false;
}

async function submitPlaceHoldModal(flags) {
  if (!order.value || !selectedAccountId.value || !orderId.value || !canPlaceHold.value) return;
  if (!flags?.fraud_hold && !flags?.address_hold && !flags?.payment_hold && !flags?.client_hold) {
    toast.error("Select at least one hold type.");
    return;
  }
  placeHoldBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/set-holds`, {
      client_account_id: Number(selectedAccountId.value),
      fraud_hold: !!flags.fraud_hold,
      address_hold: !!flags.address_hold,
      payment_hold: !!flags.payment_hold,
      client_hold: !!flags.client_hold,
    });
    toast.success("Hold placed.");
    placeHoldModalOpen.value = false;
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not place hold.");
  } finally {
    placeHoldBusy.value = false;
  }
}

async function saveSignatureGiftNote() {
  if (!selectedAccountId.value || !orderId.value) return;
  optionsSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/signature-gift-note`, {
      client_account_id: Number(selectedAccountId.value),
      require_signature: requireSignatureLocal.value,
      gift_note: giftNoteLocal.value,
    });
    toast.success("Options updated.");
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not save options.");
  } finally {
    optionsSaveBusy.value = false;
  }
}

watch(
  () => order.value,
  (o) => {
    if (!o || typeof o !== "object") return;
    allowPartialLocal.value = !!o.allow_partial;
    requireSignatureLocal.value = !!o.require_signature;
    giftNoteLocal.value = String(o.gift_note ?? "");
    packingNoteLocal.value = o.packing_note != null ? String(o.packing_note) : "";
    tagsLocal.value = Array.isArray(o.tags) ? [...o.tags] : [];
    carrierField.value = resolveCarrierPreset(o.shipping_carrier);
    methodField.value = String(o.method || "");
  },
  { immediate: true, deep: true },
);

function openShippingModal() {
  const a = order.value?.shipping_address;
  const src = a && typeof a === "object" ? a : {};
  shippingForm.value = {
    first_name: String(src.first_name || ""),
    last_name: String(src.last_name || ""),
    company: String(src.company || ""),
    address1: String(src.address1 || ""),
    address2: String(src.address2 || ""),
    phone: String(src.phone || ""),
    city: String(src.city || ""),
    state: String(src.state || ""),
    country: String(src.country || ""),
    zip: String(src.zip || ""),
    email: String(src.email || order.value?.email || ""),
  };
  shippingModalOpen.value = true;
}

function closeShippingModal() {
  if (shippingSaveBusy.value) return;
  shippingModalOpen.value = false;
}

async function saveShippingAddress() {
  if (!selectedAccountId.value || !orderId.value) return;
  shippingSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/shipping-address`, {
      client_account_id: Number(selectedAccountId.value),
      ...shippingForm.value,
    });
    toast.success("Shipping address updated.");
    shippingModalOpen.value = false;
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not update shipping address.");
  } finally {
    shippingSaveBusy.value = false;
  }
}

async function saveShippingLines() {
  if (!selectedAccountId.value || !orderId.value) return;
  shippingLinesSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/shipping-lines`, {
      client_account_id: Number(selectedAccountId.value),
      carrier: carrierForApi(carrierField.value),
      method: methodField.value,
    });
    toast.success("Shipping carrier and method updated.");
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not update shipping lines.");
  } finally {
    shippingLinesSaveBusy.value = false;
  }
}

async function onAllowPartialChange() {
  if (!order.value || !selectedAccountId.value || !orderId.value) return;
  const prev = !!order.value.allow_partial;
  allowPartialSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/allow-partial`, {
      client_account_id: Number(selectedAccountId.value),
      allow_partial: allowPartialLocal.value,
    });
    toast.success("Allow partial updated.");
    await loadOrder({ refresh: true });
  } catch (e) {
    allowPartialLocal.value = prev;
    toast.errorFrom(e, "Could not update allow partial.");
  } finally {
    allowPartialSaveBusy.value = false;
  }
}

function addTagFromInput() {
  const chunks = String(tagInputValue.value || "")
    .split(/[,\n]/)
    .map((t) => t.trim())
    .filter(Boolean);
  if (!chunks.length) return;
  const merged = [...tagsLocal.value];
  for (const t of chunks) {
    if (!merged.includes(t)) merged.push(t);
  }
  tagsLocal.value = merged;
  tagInputValue.value = "";
}

function onTagInputKeydown(e) {
  if (e.key === "Enter" || e.key === ",") {
    e.preventDefault();
    addTagFromInput();
  }
}

function removeTag(idx) {
  tagsLocal.value = tagsLocal.value.filter((_, i) => i !== idx);
}

async function saveTags() {
  if (!selectedAccountId.value || !orderId.value) return;
  tagsSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/tags`, {
      client_account_id: Number(selectedAccountId.value),
      tags: tagsLocal.value,
    });
    toast.success("Order tags updated.");
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not update tags.");
  } finally {
    tagsSaveBusy.value = false;
  }
}

const editQtyPendingMax = computed(() => {
  const row = editLineRow.value;
  if (!row || typeof row !== "object") return 0;
  return Number(row.quantity ?? 0);
});

const deleteLineItemConfirmMessage = computed(() => {
  const row = deleteLineRow.value;
  if (!row) return "Remove this line item from the order in ShipHero?";
  const label = String(row.name || row.sku || "this item").trim() || "this item";
  return `Remove "${label}" from this order in ShipHero? This cannot be undone from here.`;
});

function lineItemStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase().trim();
  if (!s) return "bg-secondary-subtle text-secondary-emphasis";
  if (s.includes("cancel")) return "bg-danger-subtle text-danger-emphasis";
  if (s.includes("pending") || s.includes("await")) return "bg-primary-subtle text-primary-emphasis";
  if (s.includes("unfulfilled")) return "bg-secondary-subtle text-secondary-emphasis";
  if (s.includes("incomplete")) return "bg-secondary-subtle text-secondary-emphasis";
  if (s.includes("fulfilled") || s.includes("ship") || s.includes("complete")) {
    return "bg-success-subtle text-success-emphasis";
  }
  if (s.includes("partial")) return "bg-warning-subtle text-warning-emphasis";
  if (s.includes("backorder") || s.includes("back order") || s.includes("oos") || s.includes("out of stock")) {
    return "bg-danger-subtle text-danger-emphasis";
  }
  return "bg-secondary-subtle text-secondary-emphasis";
}

function closeItemMenu() {
  itemMenuOpenId.value = null;
}

function placeItemMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  const width = 180;
  const height = 92;
  let top = rect.bottom + 4;
  let left = rect.right - width;
  left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
  if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 4);
  itemMenuRect.value = { top, left };
}

function toggleItemMenu(row, e) {
  e.stopPropagation();
  const key = itemRowMenuKey(row);
  if (!key) return;
  if (itemMenuOpenId.value === key) {
    itemMenuOpenId.value = null;
    return;
  }
  const btn = e.currentTarget;
  itemMenuOpenId.value = key;
  requestAnimationFrame(() => {
    if (itemMenuOpenId.value === key && btn instanceof HTMLElement) {
      placeItemMenu(btn);
    }
  });
}

function onItemRowMenuDocClick(ev) {
  if (!itemMenuOpenId.value) return;
  if (!ev.target?.closest?.("[data-row-actions]")) {
    itemMenuOpenId.value = null;
  }
}

function onItemMenuEdit() {
  const row = itemMenuOpenRow.value;
  closeItemMenu();
  if (row) openEditLineItem(row);
}

function onItemMenuDelete() {
  const row = itemMenuOpenRow.value;
  closeItemMenu();
  if (row) openDeleteLineConfirm(row);
}

function openEditLineItem(item) {
  if (!canRunShipHeroActions.value) {
    toast.error("You do not have permission to edit line items.");
    return;
  }
  if (!item?.id) {
    toast.error("This line item has no id; refresh and try again.");
    return;
  }
  editLineRow.value = item;
  editQtyPending.value = Number(item.quantity_pending_fulfillment ?? 0);
  editLineModalOpen.value = true;
}

function closeEditLineModal() {
  if (editLineBusy.value) return;
  editLineModalOpen.value = false;
  editLineRow.value = null;
}

async function submitEditLinePending() {
  if (!selectedAccountId.value || !orderId.value || !editLineRow.value?.id) return;
  const max = editQtyPendingMax.value;
  let q = Number(editQtyPending.value);
  if (!Number.isFinite(q) || q < 0) q = 0;
  if (q > max) q = max;
  editLineBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/line-items/update`, {
      client_account_id: Number(selectedAccountId.value),
      line_item_id: String(editLineRow.value.id),
      quantity_pending_fulfillment: q,
    });
    toast.success("Quantity to ship updated.");
    editLineBusy.value = false;
    closeEditLineModal();
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity to ship.");
  } finally {
    editLineBusy.value = false;
  }
}

function openDeleteLineConfirm(item) {
  if (!canRunShipHeroActions.value) {
    toast.error("You do not have permission to remove line items.");
    return;
  }
  if (!item?.id) {
    toast.error("This line item has no id; refresh and try again.");
    return;
  }
  deleteLineRow.value = item;
  confirmDeleteLineOpen.value = true;
}

async function runRemoveLineItem() {
  if (!selectedAccountId.value || !orderId.value || !deleteLineRow.value?.id) return;
  lineDeleteBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/line-items/remove`, {
      client_account_id: Number(selectedAccountId.value),
      line_item_id: String(deleteLineRow.value.id),
    });
    toast.success("Line item removed.");
    confirmDeleteLineOpen.value = false;
    deleteLineRow.value = null;
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not remove line item.");
  } finally {
    lineDeleteBusy.value = false;
  }
}

async function savePackingNote() {
  if (!selectedAccountId.value || !orderId.value) return;
  packingNoteSaveBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/packing-note`, {
      client_account_id: Number(selectedAccountId.value),
      packing_note: packingNoteLocal.value,
    });
    toast.success("Warehouse note updated.");
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not save warehouse note.");
  } finally {
    packingNoteSaveBusy.value = false;
  }
}

function openAddItemsModal() {
  if (!canRunShipHeroActions.value) {
    toast.error("You do not have permission to add items.");
    return;
  }
  if (catalogClientAccountId.value <= 0) {
    toast.error("Client account is required to add items.");
    return;
  }
  if (!canViewProductCatalog.value) {
    toast.error(addItemsCatalogDeniedMessage.value);
    return;
  }
  addItemsModalOpen.value = true;
}

function openAddNewSkuModal() {
  addNewSkuName.value = "";
  addNewSkuSku.value = "";
  addNewSkuOpen.value = true;
}

function closeAddNewSkuModal() {
  if (addNewSkuBusy.value) return;
  addNewSkuOpen.value = false;
}

async function submitAddNewSku() {
  const accountId = catalogClientAccountId.value;
  if (accountId <= 0) {
    toast.error("Client account is required to create a SKU.");
    return;
  }
  const sku = addNewSkuSku.value.trim();
  const name = addNewSkuName.value.trim();
  if (!sku || !name) {
    toast.error("Enter product name and SKU.");
    return;
  }
  addNewSkuBusy.value = true;
  try {
    const body = { sku, name };
    if (accountId > 0) {
      body.client_account_id = accountId;
    }
    await api.post("/inventory/catalog-products", body);
    toast.success("SKU created in ShipHero.");
    addNewSkuOpen.value = false;
    addItemsCatalogPanelKey.value += 1;
  } catch (e) {
    toast.errorFrom(e, "Could not create SKU.");
  } finally {
    addNewSkuBusy.value = false;
  }
}

function layoutMoreActionsMenu() {
  const btn = moreActionsBtnRef.value;
  if (!btn || !moreActionsOpen.value) return;
  const r = btn.getBoundingClientRect();
  const menuWidth = 220;
  const left = Math.min(window.innerWidth - menuWidth - 8, Math.max(8, r.right - menuWidth));
  moreActionsMenuStyle.value = {
    position: "fixed",
    top: `${Math.floor(r.bottom + 6)}px`,
    left: `${Math.floor(left)}px`,
    minWidth: `${menuWidth}px`,
    zIndex: 20050,
    visibility: "visible",
  };
}

function onMoreActionsWindowLayout() {
  if (!moreActionsOpen.value) return;
  layoutMoreActionsMenu();
}

function bindMoreActionsLayoutListeners() {
  if (moreActionsLayoutBound.value) return;
  window.addEventListener("resize", onMoreActionsWindowLayout);
  window.addEventListener("scroll", onMoreActionsWindowLayout, true);
  moreActionsLayoutBound.value = true;
}

function unbindMoreActionsLayoutListeners() {
  if (!moreActionsLayoutBound.value) return;
  window.removeEventListener("resize", onMoreActionsWindowLayout);
  window.removeEventListener("scroll", onMoreActionsWindowLayout, true);
  moreActionsLayoutBound.value = false;
}

function closeMoreActionsMenu() {
  moreActionsOpen.value = false;
}

async function toggleMoreActionsMenu(ev) {
  ev?.stopPropagation?.();
  moreActionsOpen.value = !moreActionsOpen.value;
  if (moreActionsOpen.value) {
    await nextTick();
    layoutMoreActionsMenu();
  }
}

function onMoreActionsDocumentClick(ev) {
  if (!moreActionsOpen.value) return;
  const t = ev.target;
  if (moreActionsBtnRef.value?.contains(t) || moreActionsMenuRef.value?.contains(t)) {
    return;
  }
  moreActionsOpen.value = false;
}

function closeAddItemsModal() {
  if (addItemsBusy.value) return;
  addItemsModalOpen.value = false;
}

async function addOrderLineFromCatalog({ product, quantity }) {
  const p = product && typeof product === "object" ? product : null;
  const sku = String(p?.sku || "").trim();
  const qty = Math.max(1, parseInt(String(quantity ?? 1), 10) || 1);
  if (!sku) {
    toast.error("Invalid product.");
    return;
  }
  const accountId = catalogClientAccountId.value;
  if (accountId <= 0 || !orderId.value) return;
  addItemsBusy.value = true;
  try {
    const name = String(p?.name || "").trim();
    await api.post(`/orders/${encodeURIComponent(orderId.value)}/line-items`, {
      client_account_id: accountId,
      line_items: [
        {
          sku,
          quantity: qty,
          ...(name ? { product_name: name } : {}),
        },
      ],
    });
    toast.success("Item added.");
    await loadOrder({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not add item.");
  } finally {
    addItemsBusy.value = false;
  }
}

function triggerAttachFile() {
  if (!canRunShipHeroActions.value || attachmentUploadBusy.value) return;
  attachmentFileInput.value?.click?.();
}

async function onAttachmentFileChange(ev) {
  const input = ev.target;
  const file = input?.files?.[0];
  if (!file || !selectedAccountId.value || !orderId.value) return;
  const fd = new FormData();
  fd.append("client_account_id", String(Number(selectedAccountId.value)));
  fd.append("file", file);
  attachmentUploadBusy.value = true;
  try {
    const { data } = await api.post(`/orders/${encodeURIComponent(orderId.value)}/attachments`, fd);
    toast.success("Attachment added.");
    input.value = "";
    await loadOrder({ refresh: true });
    if (data?.attachment && order.value && (data.attachment.id || data.attachment.url)) {
      const key = String(data.attachment.id || data.attachment.url);
      const list = [...(order.value.attachments || [])];
      if (!list.some((a) => a && String(a.id || a.url || "") === key)) {
        list.push(data.attachment);
        order.value = { ...order.value, attachments: list };
      }
    }
  } catch (e) {
    toast.errorFrom(e, "Could not upload attachment.");
  } finally {
    attachmentUploadBusy.value = false;
  }
}

function modalEscHandler(e) {
  if (e.key !== "Escape") return;
  if (shippingSaveBusy.value || addItemsBusy.value || editLineBusy.value || addNewSkuBusy.value) return;
  if (addNewSkuOpen.value) addNewSkuOpen.value = false;
  if (shippingModalOpen.value) shippingModalOpen.value = false;
  if (addItemsModalOpen.value) addItemsModalOpen.value = false;
  if (editLineModalOpen.value) editLineModalOpen.value = false;
  if (moreActionsOpen.value) moreActionsOpen.value = false;
  if (itemMenuOpenId.value) itemMenuOpenId.value = null;
}

watch([shippingModalOpen, addItemsModalOpen, editLineModalOpen, addNewSkuOpen], ([s, a, e, n]) => {
  if (s || a || e || n) {
    document.addEventListener("keydown", modalEscHandler);
  } else {
    document.removeEventListener("keydown", modalEscHandler);
  }
});

watch(moreActionsOpen, (open) => {
  if (open) {
    setTimeout(() => {
      document.addEventListener("click", onMoreActionsDocumentClick);
    }, 0);
    void nextTick().then(() => {
      layoutMoreActionsMenu();
      bindMoreActionsLayoutListeners();
    });
  } else {
    document.removeEventListener("click", onMoreActionsDocumentClick);
    unbindMoreActionsLayoutListeners();
    moreActionsMenuStyle.value = { visibility: "hidden" };
  }
});

onUnmounted(() => {
  document.removeEventListener("keydown", modalEscHandler);
  document.removeEventListener("click", onMoreActionsDocumentClick);
  document.removeEventListener("click", onItemRowMenuDocClick);
  unbindMoreActionsLayoutListeners();
});

onMounted(async () => {
  document.addEventListener("click", onItemRowMenuDocClick);
  setCrmPageMeta({
    title: "Save Rack | Order Detail",
    description: "ShipHero order detail.",
  });
});

function goToOrdersList() {
  if (isReturnPreviewMode.value) {
    router.push({ name: "user-return-create-search" });
    return;
  }
  const q = selectedAccountId.value ? { client_account_id: selectedAccountId.value } : {};
  if (isPortalUser.value) {
    router.push({ name: "user-orders", query: q });
    return;
  }
  router.push({ name: "orders-search", query: q });
}
</script>

<template>
  <div :class="orderDetailRootClass">
    <div v-if="loading" class="order-detail-page__fullscreen-loading">
      <CrmLoadingSpinner message="Loading order detail..." :center="true" />
      </div>
    <template v-else>
    <div v-if="!hasOrderAccountContext" class="alert alert-light border mb-4" role="status">
      <span class="small"
        >This page needs a client account. Open the order from the Orders list, or add
        <code>?client_account_id=…</code> to the URL.</span>
    </div>
    <div v-else-if="loadError" class="alert alert-warning small mb-4" role="alert">
      {{ loadError }}
    </div>
    <div v-else-if="!order" class="alert alert-warning mb-4">
      No order data loaded. Check the order link and account, then try again.
    </div>
    <template v-else>
      <div class="staff-table-card staff-datatable-card staff-datatable-card--white order-detail-page__header-shell mb-4">
        <div class="p-4 pb-3">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div class="min-w-0">
              <div class="d-flex align-items-center flex-wrap gap-2">
                <h1 class="h4 mb-0 fw-bold text-body">Order {{ headingOrderNumber }}</h1>
                <a
                  v-if="shipheroAdminUrl && !isDraftOrder"
                  :href="shipheroAdminUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="small text-primary text-decoration-none order-detail-page__shopify-header-link"
                >
                  View in ShipHero
                </a>
                <span
                  v-if="showOrderHeaderBadge"
                  class="order-detail-page__status-pill"
                  :class="orderHeaderBadgeClass"
                >
                  {{ orderHeaderBadgeLabel }}
                </span>
          </div>
          <button
            type="button"
                class="btn btn-link btn-sm text-secondary px-0 py-0 mt-1 text-decoration-none"
                @click="goToOrdersList"
          >
                {{ isReturnPreviewMode ? "&lt; Create Return" : "&lt; Orders" }}
          </button>
        </div>
            <div
              v-if="canUseStaffOrderHeaderActions"
              class="d-flex flex-wrap gap-2 align-items-center flex-shrink-0"
            >
              <button
                v-if="isDraftOrder && canRunShipHeroActions"
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="readyToShipBusy || loading"
                @click="submitReadyToShip"
              >
                {{ readyToShipBusy ? "Sending…" : "Ready to Ship" }}
              </button>
              <template v-if="!isDraftOrder">
              <template v-if="isPortalUser">
                <p v-if="lastRefreshedLabel" class="small text-secondary mb-0">
                  Last refreshed: {{ lastRefreshedLabel }}
                </p>
                <button
                  type="button"
                  class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
                  :disabled="loading || refreshing"
                  title="Refresh"
                  aria-label="Refresh order from ShipHero"
                  @click="refreshOrderDetail"
                >
                  <svg
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                    />
                  </svg>
                  {{ refreshing ? "Refreshing…" : "Refresh" }}
                </button>
              </template>
              <div class="dropdown order-detail-page__more-actions position-relative">
                <button
                  ref="moreActionsBtnRef"
                  id="order-detail-more-actions"
                  type="button"
                  :class="moreActionsBtnClass"
                  aria-haspopup="true"
                  :aria-expanded="moreActionsOpen ? 'true' : 'false'"
                  @click.stop="toggleMoreActionsMenu"
                >
                  More Actions
                </button>
      </div>
              <button
                v-if="showRemoveHoldBtn"
                type="button"
                class="btn btn-danger text-white"
                :disabled="removeHoldsBusy"
                title="Remove holds"
                @click="removeHoldsModalOpen = true"
              >
                {{ removeHoldsBusy ? "Removing…" : "Remove Hold" }}
              </button>
              </template>
    </div>
    </div>
    </div>
        <div
          v-if="showNotReadyToShipBanner && !isReturnPreviewMode"
          class="order-detail-page__nrts-banner px-4 py-3 border-top border-warning border-2"
        >
          <div class="d-flex gap-3 align-items-start">
            <span class="order-detail-page__nrts-bell text-warning flex-shrink-0" aria-hidden="true">
              <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                <path
                  d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"
                />
              </svg>
            </span>
            <div class="min-w-0 flex-grow-1">
              <div class="fw-semibold text-body">This order is not ready to ship</div>
              <ul class="small mb-0 ps-3 mt-2 text-secondary order-detail-page__nrts-list">
                <li v-for="(line, idx) in notReadyBannerBullets" :key="'nrts-' + idx">{{ line }}</li>
              </ul>
    </div>
    </div>
        </div>
      </div>

      <div v-if="loadNotice" class="alert alert-warning small mb-4" role="status">
        {{ loadNotice }}
      </div>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
              <h2 class="h6 mb-0 fw-semibold">Items</h2>
              <button
                v-if="!isReturnPreviewMode"
                type="button"
                :class="addItemsBtnClass"
                :disabled="loading || !canRunShipHeroActions"
                :title="!canRunShipHeroActions ? 'Requires orders update permission' : undefined"
                @click="openAddItemsModal"
              >
                Add Items
              </button>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th order-detail-page__items-col">
                      <button class="order-detail-page__sort-btn" type="button" @click="toggleItemSort('name')">
                        Items <span class="order-detail-page__sort-icon">{{ sortIndicator("name") }}</span>
                      </button>
                    </th>
                    <th class="staff-table-head__th text-end">
                      <button class="order-detail-page__sort-btn order-detail-page__sort-btn--right" type="button" @click="toggleItemSort('quantity')">
                        Quantity <span class="order-detail-page__sort-icon">{{ sortIndicator("quantity") }}</span>
                      </button>
                    </th>
                    <th class="staff-table-head__th text-end">
                      <button class="order-detail-page__sort-btn order-detail-page__sort-btn--right" type="button" @click="toggleItemSort('quantity_pending_fulfillment')">
                        Quantity to ship <span class="order-detail-page__sort-icon">{{ sortIndicator("quantity_pending_fulfillment") }}</span>
                      </button>
                    </th>
                    <th v-if="!isReturnPreviewMode" class="staff-table-head__th text-center order-detail-page__items-actions-col">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in sortedItems" :key="item.id || item.sku">
                    <td class="order-detail-page__items-col">
                      <a
                        v-if="inventoryDetailHref(item)"
                        :href="inventoryDetailHref(item)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="order-detail-page__item-cell order-detail-page__item-cell--link text-decoration-none text-body"
                        :title="`View ${item.sku} inventory (opens in new tab)`"
                        :aria-label="`View inventory for SKU ${item.sku} in new tab`"
                        @click="openItemInventoryInNewTab(item, $event)"
                      >
                        <img
                          v-if="item.image_url"
                          :src="item.image_url"
                          alt=""
                          class="order-detail-page__item-thumb"
                          loading="lazy"
                        />
                        <div v-else class="order-detail-page__item-thumb order-detail-page__item-thumb--empty" aria-hidden="true"></div>
                        <div class="order-detail-page__item-copy">
                          <div
                            class="order-detail-page__item-name"
                            :title="item.name ? String(item.name) : undefined"
                          >
                            {{ item.name || "—" }}
                          </div>
                          <span
                            v-if="item.fulfillment_status"
                            class="badge rounded-pill fw-medium order-detail-page__item-line-status-badge"
                            :class="lineItemStatusBadgeClass(item.fulfillment_status)"
                            :title="String(item.fulfillment_status)"
                          >
                            {{ item.fulfillment_status }}
                          </span>
                          <div
                            class="order-detail-page__item-sku"
                            :title="item.sku ? `SKU ${item.sku}` : undefined"
                          >
                            SKU {{ item.sku || "—" }}
                          </div>
                        </div>
                      </a>
                      <div v-else class="order-detail-page__item-cell">
                        <img
                          v-if="item.image_url"
                          :src="item.image_url"
                          alt=""
                          class="order-detail-page__item-thumb"
                          loading="lazy"
                        />
                        <div v-else class="order-detail-page__item-thumb order-detail-page__item-thumb--empty" aria-hidden="true"></div>
                        <div class="order-detail-page__item-copy">
                          <div
                            class="order-detail-page__item-name order-detail-page__item-name--plain"
                            :title="item.name ? String(item.name) : undefined"
                          >
                            {{ item.name || "—" }}
                          </div>
                          <span
                            v-if="item.fulfillment_status"
                            class="badge rounded-pill fw-medium order-detail-page__item-line-status-badge"
                            :class="lineItemStatusBadgeClass(item.fulfillment_status)"
                            :title="String(item.fulfillment_status)"
                          >
                            {{ item.fulfillment_status }}
                          </span>
                          <div
                            class="order-detail-page__item-sku"
                            :title="item.sku ? `SKU ${item.sku}` : undefined"
                          >
                            SKU {{ item.sku || "—" }}
                          </div>
                        </div>
                      </div>
                    </td>
                    <td class="text-end">
                      <div>{{ item.quantity ?? 0 }}</div>
                      <div
                        v-if="Number(item.backorder_quantity || 0) > 0"
                        class="order-detail-page__backorder small mt-1"
                      >
                        {{ Number(item.backorder_quantity) }} on backorder
                      </div>
                    </td>
                    <td class="text-end">{{ item.quantity_pending_fulfillment ?? 0 }}</td>
                    <td v-if="!isReturnPreviewMode" class="text-center align-middle order-detail-page__items-actions-col">
                      <div
                        v-if="canRunShipHeroActions && itemRowMenuKey(item)"
                        data-row-actions
                        class="staff-actions-inner staff-actions-inner--single justify-content-center"
                        @click.stop
                      >
                        <button
                          type="button"
                          class="staff-action-btn staff-action-btn--more"
                          :class="{ 'is-open': itemMenuOpenId === itemRowMenuKey(item) }"
                          :aria-expanded="itemMenuOpenId === itemRowMenuKey(item) ? 'true' : 'false'"
                          aria-haspopup="true"
                          aria-label="Line item actions"
                          @click="toggleItemMenu(item, $event)"
                        >
                          <CrmIconRowActions variant="horizontal" />
                        </button>
                      </div>
                      <span v-else class="small text-secondary">—</span>
                    </td>
                  </tr>
                  <tr v-if="!sortedItems.length">
                    <td :colspan="isReturnPreviewMode ? 3 : 4" class="text-center text-secondary py-4">No items</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
              Scroll sideways or swipe to see all columns.
            </p>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <h2 class="h6 mb-0 fw-semibold">Note for warehouse packer</h2>
              <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="!canRunShipHeroActions || packingNoteSaveBusy"
                :title="!canRunShipHeroActions ? 'Requires orders update permission' : undefined"
                @click="savePackingNote"
              >
                {{ packingNoteSaveBusy ? "Updating…" : "Update" }}
              </button>
            </div>
            <textarea
              v-model="packingNoteLocal"
              class="form-control"
              rows="5"
              :disabled="!canRunShipHeroActions || packingNoteSaveBusy"
              autocomplete="off"
            ></textarea>
            <p v-if="!canRunShipHeroActions" class="small text-secondary mt-2 mb-0">
              You do not have permission to edit this note.
            </p>
            </div>
          </div>

        <div class="col-lg-4 d-flex flex-column gap-4 order-detail-page__side-column">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
              <h3 class="h6 fw-semibold mb-0">Shipping Address</h3>
              <button
                v-if="canUseStaffOrderHeaderActions"
                type="button"
                :class="shippingEditBtnClass"
                @click="openShippingModal"
              >
                Edit
              </button>
            </div>
            <div class="small mb-3 pb-3 border-bottom">
              <div class="order-detail-page__address-text order-detail-page__address-link--caps text-body">
                {{ shippingAddressDisplayCaps }}
              </div>
            </div>
            <template v-if="orderIsShipped">
              <div>
                <div class="form-label small text-secondary mb-2">Tracking Info</div>
                <div v-if="trackingLabels.length" class="order-detail-page__tracking-list">
                  <div
                    v-for="label in trackingLabels"
                    :key="label.id || `${label.service_label}-${label.tracking_number}`"
                    class="order-detail-page__tracking-line small text-body mb-2"
                  >
                    <template v-for="parts in [formatCarrierTrackingLine(label)]" :key="parts.trackingNumber">
                      <span class="fw-semibold">{{ parts.carrier }}</span>
                      <span class="text-secondary mx-1">|</span>
                      <a
                        v-if="parts.trackingUrl"
                        :href="parts.trackingUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-primary text-decoration-none"
                      >
                        {{ parts.trackingNumber }}
                      </a>
                      <span v-else>{{ parts.trackingNumber }}</span>
                    </template>
          </div>
                </div>
                <p v-else class="small text-secondary mb-0">No tracking information available.</p>
                <p v-if="trackingLabelCostDisplay" class="small text-secondary mb-0 mt-2">
                  Label cost: <span class="fw-semibold text-body">{{ trackingLabelCostDisplay }}</span>
                </p>
              </div>
            </template>
            <template v-else>
              <div class="mb-3 pb-3 border-bottom">
                <div class="form-label small text-secondary mb-1">Current Shipping Method</div>
                <div class="small fw-semibold text-body">{{ currentShippingMethodDisplay }}</div>
              </div>
              <div class="mb-3">
                <label class="form-label small text-secondary mb-1" for="order-detail-carrier">Shipping Carrier</label>
                <select
                  id="order-detail-carrier"
                  v-model="carrierField"
                  class="form-select form-select-sm"
                  :disabled="!canRunShipHeroActions"
                >
                  <option v-for="c in carrierSelectOptions" :key="'c-' + (c || 'empty')" :value="c">
                    {{ c === "" ? "—" : c }}
                  </option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small text-secondary mb-1" for="order-detail-method">Method</label>
                <select
                  id="order-detail-method"
                  v-model="methodField"
                  class="form-select form-select-sm"
                  :disabled="!canRunShipHeroActions"
                >
                  <option v-for="m in methodSelectOptions" :key="'m-' + (m || 'empty')" :value="m">
                    {{ m === "" ? "—" : m }}
                  </option>
                </select>
              </div>
              <button
                type="button"
                class="btn btn-primary btn-sm staff-page-primary"
                :disabled="!canRunShipHeroActions || shippingLinesSaveBusy"
                @click="saveShippingLines"
              >
                {{ shippingLinesSaveBusy ? "Saving…" : "Save Carrier & Method" }}
              </button>
            </template>
        </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Order details</h3>
            <dl class="small mb-0">
              <dt class="text-secondary">Customer</dt>
              <dd class="fw-semibold text-body">{{ customerDisplayName }}</dd>
              <dt class="text-secondary">Email</dt>
              <dd>{{ order.email || "—" }}</dd>
              <dt class="text-secondary">Phone</dt>
              <dd>{{ order.shipping_address?.phone || "—" }}</dd>
              <dt class="text-secondary mt-2">Creation date</dt>
              <dd>{{ fmtCreationDate(order.order_date) }}</dd>
              <dt class="text-secondary">Store</dt>
              <dd class="text-break">{{ order.account || "—" }}</dd>
              <template v-if="shipheroAdminUrl && !isDraftOrder">
                <dt class="text-secondary mt-2">ShipHero</dt>
                <dd>
                  <a
                    :href="shipheroAdminUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="small text-primary text-decoration-none"
                  >
                    View in ShipHero
                  </a>
                </dd>
              </template>
            </dl>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Options</h3>
            <div class="form-check mb-2">
              <input
                id="order-detail-allow-partial"
                v-model="allowPartialLocal"
                class="form-check-input"
                type="checkbox"
                :disabled="!canRunShipHeroActions || allowPartialSaveBusy"
                @change="onAllowPartialChange"
              />
              <label class="form-check-label small" for="order-detail-allow-partial">Allow Partial</label>
            </div>
            <div class="form-check mb-3">
              <input
                id="order-detail-req-sig"
                v-model="requireSignatureLocal"
                class="form-check-input"
                type="checkbox"
                :disabled="!canRunShipHeroActions || optionsSaveBusy"
              />
              <label class="form-check-label small" for="order-detail-req-sig">Require Signature</label>
            </div>
            <label class="form-label small text-secondary mb-1" for="order-gift-note">Gift note</label>
            <textarea
              id="order-gift-note"
              v-model="giftNoteLocal"
              class="form-control form-control-sm mb-3"
              rows="3"
              :disabled="!canRunShipHeroActions || optionsSaveBusy"
            ></textarea>
            <button
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="!canRunShipHeroActions || optionsSaveBusy"
              @click="saveSignatureGiftNote"
            >
              {{ optionsSaveBusy ? "Saving…" : "Save Options" }}
            </button>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Order tags</h3>
            <label class="form-label small text-secondary mb-1" for="order-tags-input">Tags</label>
            <input
              id="order-tags-input"
              v-model="tagInputValue"
              type="text"
              class="form-control form-control-sm mb-2"
              placeholder="Press enter or comma to add a tag."
              autocomplete="off"
              :disabled="!canRunShipHeroActions"
              @keydown="onTagInputKeydown"
            />
            <div v-if="tagsLocal.length" class="d-flex flex-wrap gap-1 mb-2">
              <span
                v-for="(t, idx) in tagsLocal"
                :key="t + '-' + idx"
                class="badge bg-secondary-subtle text-dark border d-inline-flex align-items-center gap-1"
              >
                {{ t }}
                <button
                  v-if="canRunShipHeroActions"
                  type="button"
                  class="btn btn-link btn-sm p-0 lh-1 text-decoration-none"
                  aria-label="Remove tag"
                  @click="removeTag(idx)"
                >
                  ×
                </button>
              </span>
            </div>
            <button
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="!canRunShipHeroActions || tagsSaveBusy"
              @click="saveTags"
            >
              {{ tagsSaveBusy ? "Saving…" : "Save Tags" }}
            </button>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h3 class="h6 fw-semibold mb-0">Attachments</h3>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary text-primary"
                :disabled="!canRunShipHeroActions || attachmentUploadBusy"
                @click="triggerAttachFile"
              >
                Attach File
              </button>
          </div>
            <input
              ref="attachmentFileInput"
              type="file"
              class="d-none"
              accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.csv,.doc,.docx,.xlsx,image/*,application/pdf"
              @change="onAttachmentFileChange"
            />
            <div v-if="(order.attachments || []).length" class="list-group list-group-flush">
              <a
                v-for="att in order.attachments"
                :key="att.id || att.url"
                :href="att.url || '#'"
                class="list-group-item list-group-item-action small py-2 px-0 border-0"
                target="_blank"
                rel="noopener noreferrer"
              >
                {{ att.filename || att.url || "Attachment" }}
              </a>
            </div>
            <div
              v-else
              class="order-detail-page__attachments-empty text-center text-secondary py-5 px-2 border rounded-2 bg-light-subtle"
            >
              <div class="order-detail-page__attachments-empty-icon mb-2 text-secondary" aria-hidden="true">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25">
                  <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19A4 4 0 1 1 19 13.12l-8.69 8.69a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                </svg>
              </div>
              <div>There are no attachments</div>
            </div>
          </div>

          <div
            v-if="showAdminSidebarHoldNote"
            class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel"
          >
            <h3 class="h6 fw-semibold mb-3">Note</h3>
            <p v-if="detailOnlyOperatorHold" class="small text-secondary mb-0">
              This order has a warehouse operator hold. Contact your account manager to release it.
            </p>
            <p v-else-if="detailOnlyCrmUserHold && showRemoveHoldBtn" class="small text-secondary mb-0">
              User hold is active — use Remove Hold to release.
            </p>
          </div>
        </div>
      </div>
    </template>
    </template>

    <Teleport to="body">
      <Transition name="modal-backdrop">
        <div
          v-if="shippingModalOpen"
          class="crm-vx-modal-overlay"
          aria-modal="true"
          role="dialog"
          aria-labelledby="order-shipping-modal-title"
        >
          <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="closeShippingModal" />
          <Transition name="modal-panel" appear>
            <div class="crm-vx-modal crm-vx-modal--shipping-form">
              <button
                type="button"
                class="crm-vx-modal__close"
                aria-label="Close"
                :disabled="shippingSaveBusy"
                @click="closeShippingModal"
              >
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <header class="crm-vx-modal__head">
                <h2 id="order-shipping-modal-title" class="crm-vx-modal__title">Shipping Information</h2>
              </header>
              <div class="crm-vx-modal__body pt-0">
                <div class="row g-2">
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-fn">First Name</label>
                    <input
                      id="ship-fn"
                      v-model="shippingForm.first_name"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-ln">Last Name</label>
                    <input
                      id="ship-ln"
                      v-model="shippingForm.last_name"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-co">Company</label>
                    <input
                      id="ship-co"
                      v-model="shippingForm.company"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-a1">Address</label>
                    <input
                      id="ship-a1"
                      v-model="shippingForm.address1"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-a2">Address 2</label>
                    <input
                      id="ship-a2"
                      v-model="shippingForm.address2"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-ph">Phone</label>
                    <input
                      id="ship-ph"
                      v-model="shippingForm.phone"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-city">City</label>
                    <input
                      id="ship-city"
                      v-model="shippingForm.city"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-st">State</label>
                    <input
                      id="ship-st"
                      v-model="shippingForm.state"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-ct">Country</label>
                    <input
                      id="ship-ct"
                      v-model="shippingForm.country"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-secondary" for="ship-zip">ZIP Code</label>
                    <input
                      id="ship-zip"
                      v-model="shippingForm.zip"
                      type="text"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                  <div class="col-12">
                    <label class="form-label small text-secondary" for="ship-em">Email</label>
                    <input
                      id="ship-em"
                      v-model="shippingForm.email"
                      type="email"
                      class="form-control form-control-sm"
                      :readonly="!canRunShipHeroActions"
                    />
                  </div>
                </div>
                <p v-if="!canRunShipHeroActions" class="small text-secondary mb-0 mt-2">
                  This address is read-only. Ask an administrator to grant <strong>Update inventory quantities</strong> to
                  edit.
                </p>
              </div>
              <footer class="crm-vx-modal__footer d-flex flex-wrap gap-2 justify-content-end align-items-center">
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                  :disabled="shippingSaveBusy"
                  @click="closeShippingModal"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                  :disabled="shippingSaveBusy || !canRunShipHeroActions"
                  :title="!canRunShipHeroActions ? 'You do not have permission to update shipping.' : undefined"
                  @click="saveShippingAddress"
                >
                  {{ shippingSaveBusy ? "Updating…" : "Update" }}
                </button>
              </footer>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <ul
        v-show="moreActionsOpen && !isDraftOrder"
        ref="moreActionsMenuRef"
        class="dropdown-menu dropdown-menu-end show shadow-sm border bg-body order-detail-page__more-actions-menu"
        :style="moreActionsMenuStyle"
        role="menu"
        aria-labelledby="order-detail-more-actions"
      >
        <li>
          <button
            type="button"
            class="dropdown-item"
            role="menuitem"
            :disabled="!canPlaceHold || placeHoldBusy"
            :title="
              !canRunShipHeroActions
                ? 'You do not have permission to change this order in ShipHero.'
                : orderIsTerminalFulfillment
                  ? 'Cannot place hold on a shipped or canceled order.'
                  : undefined
            "
            @click="
              closeMoreActionsMenu();
              openPlaceHoldModal();
            "
          >
            {{ placeHoldBusy ? "Placing Hold…" : "Place Hold" }}
          </button>
        </li>
        <li>
          <button
            type="button"
            class="dropdown-item"
            role="menuitem"
            :disabled="!canRunShipHeroActions"
            @click="
              closeMoreActionsMenu();
              if (canRunShipHeroActions) confirmFulfilledOpen = true;
            "
          >
            Mark As Fulfilled
          </button>
        </li>
        <li>
          <button
            type="button"
            class="dropdown-item text-danger"
            role="menuitem"
            :disabled="!canRunShipHeroActions"
            @click="
              closeMoreActionsMenu();
              if (canRunShipHeroActions) confirmCancelOpen = true;
            "
          >
            Cancel Order
          </button>
        </li>
      </ul>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="itemMenuOpenRow"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${itemMenuRect.top}px`,
          left: `${itemMenuRect.left}px`,
        }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="onItemMenuEdit">Edit</button>
        <button type="button" class="staff-row-menu__item staff-row-menu__item--danger" role="menuitem" @click="onItemMenuDelete">
          Delete
        </button>
      </div>
    </Teleport>

    <Teleport to="body">
      <Transition name="modal-backdrop">
        <div
          v-if="addItemsModalOpen"
          class="crm-vx-modal-overlay"
          aria-modal="true"
          role="dialog"
          aria-labelledby="order-add-items-modal-title"
        >
          <div
            class="crm-vx-modal-backdrop"
            aria-hidden="true"
            @click="closeAddItemsModal"
          />
          <Transition name="modal-panel" appear>
            <div class="crm-vx-modal">
              <button
                type="button"
                class="crm-vx-modal__close"
                aria-label="Close"
                :disabled="addItemsBusy"
                @click="closeAddItemsModal"
              >
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <header class="crm-vx-modal__head">
                <h2 id="order-add-items-modal-title" class="crm-vx-modal__title">Add Items</h2>
                <p class="crm-vx-modal__subtitle small text-secondary mb-0">
                  Search the account catalog and add SKUs to this order.
                </p>
              </header>
              <div class="crm-vx-modal__body p-0">
                <AsnProductCatalogPanel
                  :key="addItemsCatalogPanelKey"
                  :client-account-id="catalogClientAccountId"
                  :use-session-client-account="isUserPortalRoute || isPortalUser"
                  :active="addItemsModalOpen"
                  :busy="addItemsBusy"
                  :permission-denied-message="addItemsCatalogDeniedMessage"
                  :show-add-new-sku="canCreateCatalogSku"
                  :create-sku-route="addItemsCreateSkuRoute"
                  qty-label="Quantity"
                  search-input-id="order-add-items-catalog-search"
                  @add="addOrderLineFromCatalog"
                  @add-new-sku="openAddNewSkuModal"
                />
              </div>
              <footer class="crm-vx-modal__footer">
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                  :disabled="addItemsBusy"
                  @click="closeAddItemsModal"
                >
                  Close
                </button>
              </footer>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="addNewSkuOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          aria-labelledby="order-add-new-sku-title"
          @click.self="closeAddNewSkuModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="addNewSkuBusy"
              @click="closeAddNewSkuModal"
            >
              <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <header class="crm-vx-modal__head">
              <h2 id="order-add-new-sku-title" class="crm-vx-modal__title">Create SKU</h2>
            </header>
            <div class="crm-vx-modal__body">
              <p class="small text-secondary mb-3">
                Creates the SKU in ShipHero so you can add it to this order.
              </p>
              <label class="form-label small mb-1" for="order-new-sku-name">Product Name</label>
              <input
                id="order-new-sku-name"
                v-model="addNewSkuName"
                type="text"
                class="form-control form-control-sm mb-3"
                :disabled="addNewSkuBusy"
              />
              <label class="form-label small mb-1" for="order-new-sku-code">SKU</label>
              <input
                id="order-new-sku-code"
                v-model="addNewSkuSku"
                type="text"
                class="form-control form-control-sm"
                :disabled="addNewSkuBusy"
              />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="addNewSkuBusy"
                @click="closeAddNewSkuModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="addNewSkuBusy"
                @click="submitAddNewSku"
              >
                {{ addNewSkuBusy ? "Creating…" : "Create SKU" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="modal-backdrop">
        <div
          v-if="editLineModalOpen"
          class="crm-vx-modal-overlay"
          aria-modal="true"
          role="dialog"
          aria-labelledby="order-edit-line-modal-title"
        >
          <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="closeEditLineModal" />
          <Transition name="modal-panel" appear>
            <div class="crm-vx-modal crm-vx-modal--sm">
              <button
                type="button"
                class="crm-vx-modal__close"
                aria-label="Close"
                :disabled="editLineBusy"
                @click="closeEditLineModal"
              >
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <header class="crm-vx-modal__head">
                <h2 id="order-edit-line-modal-title" class="crm-vx-modal__title">Edit Quantity to Ship</h2>
              </header>
              <div class="crm-vx-modal__body pt-0">
                <p v-if="editLineRow" class="small text-secondary mb-2">
                  {{ editLineRow.name || "—" }}
                  <span v-if="editLineRow.sku" class="d-block">SKU {{ editLineRow.sku }}</span>
                </p>
                <label class="form-label small" for="edit-line-qty-pending">Quantity to ship</label>
                <input
                  id="edit-line-qty-pending"
                  v-model.number="editQtyPending"
                  type="number"
                  min="0"
                  :max="editQtyPendingMax"
                  step="1"
                  class="form-control form-control-sm"
                  :disabled="editLineBusy"
                />
                <p class="small text-secondary mb-0 mt-2">Ordered quantity: {{ editQtyPendingMax }} (maximum to ship).</p>
              </div>
              <footer class="crm-vx-modal__footer d-flex flex-wrap gap-2 justify-content-end align-items-center">
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                  :disabled="editLineBusy"
                  @click="closeEditLineModal"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                  :disabled="editLineBusy"
                  @click="submitEditLinePending"
                >
                  {{ editLineBusy ? "Saving…" : "Save" }}
                </button>
              </footer>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <OrdersRemoveHoldsModal
      :open="removeHoldsModalOpen"
      :busy="removeHoldsBusy"
      variant="single"
      :active-holds="detailHoldsForRemoveModal"
      @close="closeRemoveHoldsModal"
      @confirm="onRemoveHoldsConfirm"
    />
    <OrdersPlaceHoldModal
      :open="placeHoldModalOpen"
      :busy="placeHoldBusy"
      @close="closePlaceHoldModal"
      @confirm="submitPlaceHoldModal"
    />
    <ConfirmModal
      :open="confirmFulfilledOpen"
      title="Mark As Fulfilled"
      message="This updates fulfillment status in ShipHero only. It does not create a shipment or remove inventory via the full fulfillment flow. Continue?"
      confirm-label="Mark As Fulfilled"
      cancel-label="Cancel"
      :danger="false"
      :busy="actionBusy"
      @close="confirmFulfilledOpen = false"
      @confirm="runMarkFulfilled"
    />
    <ConfirmModal
      :open="confirmCancelOpen"
      title="Cancel Order"
      message="This cancels the order in ShipHero. If ShipHero rejects the request, you may need to adjust options (for example void on store) from ShipHero directly."
      confirm-label="Cancel Order"
      cancel-label="Back"
      danger
      :busy="actionBusy"
      @close="confirmCancelOpen = false"
      @confirm="runCancelOrder"
    />
    <ConfirmModal
      :open="confirmDeleteLineOpen"
      title="Remove Line Item"
      :message="deleteLineItemConfirmMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      danger
      :busy="lineDeleteBusy"
      @close="confirmDeleteLineOpen = false"
      @confirm="runRemoveLineItem"
    />
  </div>
</template>

<style scoped>
.order-detail-page__fullscreen-loading {
  min-height: 70vh;
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-detail-page__sort-btn {
  background: transparent;
  border: 0;
  padding: 0;
  font: inherit;
  color: inherit;
  text-align: left;
}

.order-detail-page__sort-icon {
  opacity: 0.7;
  font-size: 0.8em;
  margin-left: 0.2rem;
}

.order-detail-page__sort-btn--right {
  width: 100%;
  text-align: right;
}

.order-detail-page__item-cell {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  width: 100%;
}

.order-detail-page__item-cell--link {
  cursor: pointer;
  border-radius: 0.25rem;
}

.order-detail-page__item-cell--link:hover .order-detail-page__item-name,
.order-detail-page__item-cell--link:focus-visible .order-detail-page__item-name {
  text-decoration: underline;
}

.order-detail-page__item-name--plain {
  color: inherit;
  font-weight: 600;
}

.order-detail-page__item-copy {
  min-width: 0;
  flex: 1 1 auto;
  max-width: 100%;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
}

/* One line + ellipsis; full text on hover via native `title` on the element (see template). */
.order-detail-page__item-name {
  color: #2563eb;
  font-weight: 600;
  display: block;
  min-width: 0;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.35;
}

.order-detail-page__item-line-status-badge {
  max-width: min(14rem, 100%);
  overflow: hidden;
  text-overflow: ellipsis;
}

.order-detail-page__item-sku {
  color: #6c757d;
  display: block;
  min-width: 0;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.875rem;
  line-height: 1.3;
}

/*
 * Global staff styles use `width: max-content` on tables inside `.staff-table-wrap` so other
 * datatables can scroll horizontally on mobile. That defeats fixed layout here and stretches
 * the table to the full product string — horizontal scrollbar. Keep this table viewport-width.
 */
.order-detail-page :deep(.table-responsive.staff-table-wrap) {
  overflow-x: clip;
  max-width: 100%;
}

.order-detail-page :deep(.staff-table-wrap .table.staff-data-table) {
  width: 100%;
  min-width: 0;
  max-width: 100%;
  table-layout: fixed;
}

.order-detail-page__items-col {
  width: 44%;
  min-width: 0;
  vertical-align: middle;
}

.order-detail-page__item-thumb {
  width: 52px;
  height: 52px;
  border-radius: 0.35rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.order-detail-page__item-thumb--empty {
  background: rgba(0, 0, 0, 0.04);
}

.order-detail-page__backorder {
  color: #dc3545;
  font-size: 0.85rem;
  font-weight: 600;
}

.order-detail-page__history-html :deep(p) {
  margin-bottom: 0.4rem;
}

.order-detail-page__history-html :deep(ul),
.order-detail-page__history-html :deep(ol) {
  margin: 0.25rem 0 0.35rem 1.1rem;
  padding: 0;
}

.order-detail-page__history-html :deep(li) {
  margin-bottom: 0.2rem;
}

.order-detail-page__side-column {
  position: sticky;
  top: 1rem;
  align-self: flex-start;
  /* Single page scroll: avoid a nested scrollbar on the sidebar. */
  max-height: none;
  overflow: visible;
}

.order-detail-page__header-shell {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 0.5rem;
  overflow: visible;
  background: var(--bs-body-bg, #fff);
}

.order-detail-page__shopify-header-link {
  line-height: 1.35;
  white-space: nowrap;
}

.order-detail-page__tracking-line {
  line-height: 1.45;
  word-break: break-word;
}

.order-detail-page__tracking-line a:hover,
.order-detail-page__tracking-line a:focus-visible {
  text-decoration: underline !important;
}

.order-detail-page__shopify-header-link:hover,
.order-detail-page__shopify-header-link:focus-visible {
  text-decoration: underline !important;
}

.order-detail-page__status-pill {
  font-size: 0.8125rem;
  padding: 0.35rem 0.75rem;
  line-height: 1.25;
}

.order-detail-page :deep(.staff-table-wrap .table.staff-data-table) > thead > tr > th.order-detail-page__items-actions-col,
.order-detail-page :deep(.staff-table-wrap .table.staff-data-table) > tbody > tr > td.order-detail-page__items-actions-col {
  text-align: center !important;
  vertical-align: middle !important;
  width: 7rem;
  min-width: 7rem;
  white-space: nowrap;
}

.order-detail-page__add-items-btn.btn-outline-secondary:hover,
.order-detail-page__add-items-btn.btn-outline-secondary:focus-visible {
  background-color: var(--bs-body-bg, #fff);
  color: var(--bs-secondary);
  border-color: var(--bs-border-color);
}

[data-bs-theme="dark"] .order-detail-page__add-items-btn.btn-outline-secondary:hover,
[data-bs-theme="dark"] .order-detail-page__add-items-btn.btn-outline-secondary:focus-visible {
  background-color: var(--bs-body-bg);
  color: var(--bs-body-color);
  border-color: var(--bs-border-color);
}

.order-detail-page__more-actions .dropdown-menu {
  z-index: 1085;
  min-width: 11rem;
}

.order-detail-page__more-actions-toggle.btn-outline-secondary:hover,
.order-detail-page__more-actions-toggle.btn-outline-secondary:focus-visible {
  background-color: var(--bs-body-bg, #fff);
  color: var(--bs-secondary);
  border-color: var(--bs-border-color);
}

[data-bs-theme="dark"] .order-detail-page__more-actions-toggle.btn-outline-secondary:hover,
[data-bs-theme="dark"] .order-detail-page__more-actions-toggle.btn-outline-secondary:focus-visible {
  background-color: var(--bs-body-bg);
  color: var(--bs-body-color);
  border-color: var(--bs-border-color);
}

.order-detail-page__nrts-banner {
  background: #fffbeb;
}

[data-bs-theme="dark"] .order-detail-page__nrts-banner {
  background: rgba(250, 204, 21, 0.14);
}

.order-detail-page__nrts-list li {
  margin-bottom: 0.15rem;
}

.order-detail-page__side-panel {
  position: relative;
}

.order-detail-page__address-link--caps {
  white-space: pre-line;
  line-height: 1.4;
  text-transform: uppercase;
}

.order-detail-page__address-text {
  white-space: pre-line;
  line-height: 1.45;
}

.order-detail-page__attachments-empty-icon {
  opacity: 0.55;
}

.modal-backdrop-enter-active,
.modal-backdrop-leave-active {
  transition: opacity 0.2s ease;
}
.modal-backdrop-enter-active .crm-vx-modal-backdrop,
.modal-backdrop-leave-active .crm-vx-modal-backdrop {
  transition: inherit;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}

.modal-panel-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}

.order-detail-page--embedded {
  width: 100%;
  max-width: 100%;
  min-width: 0;
  background: transparent;
  padding: 0;
  margin: 0;
}
</style>
