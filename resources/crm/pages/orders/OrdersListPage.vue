<script setup>
import { Transition, computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import CrmSyncToolbar from "../../components/common/CrmSyncToolbar.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import OrdersRemoveHoldsModal from "../../components/orders/OrdersRemoveHoldsModal.vue";
import OrdersPlaceHoldModal from "../../components/orders/OrdersPlaceHoldModal.vue";
import OrderCreateDrawer from "../../components/orders/OrderCreateDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { canWriteShipHeroOrders } from "../../utils/crmShipHeroOrders";
import { crmIsPortalUser } from "../../utils/crmUser";
import {
  formatCarrierTrackingLine,
  formatCurrentShippingMethod,
  formatShipmentCarrier,
} from "../../utils/orderShippingDisplay.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";
import { useShipHeroRevisionPoll } from "../../composables/useShipHeroRevisionPoll.js";

const ORDER_QUEUE_TABS = new Set(["awaiting", "on_hold", "backorder", "shipped"]);

const props = defineProps({
  /** When true, hide account picker and link orders to portal detail route. */
  portalOrderList: { type: Boolean, default: false },
  /** Lock list to one account (embedded in account detail). */
  fixedClientAccountId: { type: [Number, String], default: null },
  /** Hide page heading when embedded in account detail. */
  embedded: { type: Boolean, default: false },
  /** Queue tab when embedded on account detail (awaiting, on_hold, backorder, shipped). */
  embeddedQueueTab: { type: String, default: "awaiting" },
});

const emit = defineEmits(["queue-refreshed"]);

import { RTS_ORDER_DATE_FROM } from "../../constants/fulfillmentSections.js";
const route = useRoute();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const ROUTE_HOLD_REASONS = new Set(["fraud", "address", "operator", "payment", "user"]);

function normalizeRouteHoldReason(value) {
  const raw = String(value || "").trim().toLowerCase();
  return ROUTE_HOLD_REASONS.has(raw) ? raw : "";
}

function normalizeRouteAccountId(value) {
  const id = Number(value || 0);
  return id > 0 ? String(id) : "";
}

function applyOrdersRouteQuery() {
  if (!isEmbeddedOrders.value && !isPortalUser.value) {
    const accountFromRoute = normalizeRouteAccountId(route.query.client_account_id);
    if (accountFromRoute) {
      selectedAccountId.value = accountFromRoute;
    }
  }
  const presetFromRoute = String(route.query.date_preset || "").trim();
  if (
    presetFromRoute === "today"
    || presetFromRoute === "all"
    || presetFromRoute === "last_7"
    || presetFromRoute === "last_30"
    || presetFromRoute === "since_may_1"
  ) {
    query.datePreset = presetFromRoute;
  }
  if (tabKey.value === "on_hold") {
    const holdFromRoute = normalizeRouteHoldReason(route.query.hold_reason);
    if (holdFromRoute) {
      query.holdReason = holdFromRoute;
    }
  }
}

const rows = ref([]);
const loading = ref(false);
const accountsLoading = ref(false);
const accounts = ref([]);
const selectedAccountId = ref(
  normalizeRouteAccountId(props.fixedClientAccountId || route.query.client_account_id),
);
/** Admin staff: last search ran without a specific account (all accounts / order # lookup). */
const crossAccountMode = ref(false);
const crossAccountScanTruncated = ref(false);
const hasSearched = ref(false);
const nextCursor = ref(null);
const hasNextPage = ref(false);
const orderQueueSyncedAt = ref("");
const orderQueueRefreshing = ref(false);
const orderQueueSyncPending = ref(false);
const orderQueueSyncMessage = ref("");
const orderQueueSyncStatus = ref("");
const orderQueueIndexHasRows = ref(false);
const orderQueueDateFilterExcludes = ref(false);
const queueTotal = ref(null);
const queueCountMetric = ref("orders");

const AUTO_SYNC_INTERVAL_MS = 30 * 60 * 1000;
const ORDER_QUEUE_POLL_MS = 3000;
let autoSyncTimer = null;
let orderQueuePollTimer = null;
let orderQueueUserRefresh = false;

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const filterMenuOpen = ref(false);
const committedOrderNumber = ref("");

const selectedOrderIds = ref(new Set());
const bulkBusy = ref(false);
const placeHoldModalOpen = ref(false);
const placeHoldTargetIds = ref([]);
const placeHoldBusy = ref(false);

const confirmBulkMarkFulfilledOpen = ref(false);
const confirmBulkCancelOpen = ref(false);
const confirmBulkAllowPartialOpen = ref(false);
const removeHoldsModalOpen = ref(false);
const removeHoldsModalVariant = ref("bulk");
const removeHoldsModalActiveHolds = ref({
  fraud_hold: false,
  address_hold: false,
  payment_hold: false,
  client_hold: false,
});
const removeHoldsSingleOrderId = ref("");

const createOrderModalOpen = ref(false);

const query = reactive({
  datePreset: "today",
  from: "",
  to: "",
  fulfillmentStatus: "",
  readyToShip: "",
  holdReason: normalizeRouteHoldReason(route.query.hold_reason),
  /** Manage tab only: passed to ShipHero `order_number` filter. */
  orderNumber: "",
});

const tabKey = computed(() => {
  if (props.embedded) {
    const embeddedTab = String(props.embeddedQueueTab || "awaiting").trim();
    return ORDER_QUEUE_TABS.has(embeddedTab) ? embeddedTab : "awaiting";
  }
  return String(route.meta?.orderTab || "manage");
});

/** Portal user routes set `meta.userPortal`; staff may pass `portal-order-list` explicitly. */
const isPortalOrderList = computed(() => props.portalOrderList === true || route.meta?.userPortal === true);

const isEmbeddedOrders = computed(() => props.embedded || Number(props.fixedClientAccountId || 0) > 0);

const isOrdersSearchPage = computed(() => tabKey.value === "search");

const tabTitle = computed(() => {
  if (isOrdersSearchPage.value) return "Search";
  if (tabKey.value === "awaiting") return "Ready to Ship";
  if (tabKey.value === "on_hold") return "On-Hold";
  if (tabKey.value === "backorder") return "Backorder";
  if (tabKey.value === "shipped") return "Shipped";
  return "Manage";
});

const showManageFilters = computed(() => !isOrdersSearchPage.value);
const showOrderQueueRefresh = computed(
  () => ORDER_QUEUE_TABS.has(tabKey.value) && Boolean(selectedAccountId.value) && !crossAccountMode.value,
);
const orderQueueSyncedLabel = computed(() => {
  if (!orderQueueSyncedAt.value) return "";
  return formatDateTimeUs(orderQueueSyncedAt.value);
});
const showOrderQueueSyncBanner = computed(
  () => showOrderQueueRefresh.value && (orderQueueSyncPending.value || orderQueueRefreshing.value),
);
const orderQueueSyncBannerText = computed(() => {
  if (orderQueueSyncMessage.value) return orderQueueSyncMessage.value;
  return "Syncing from ShipHero…";
});
const ordersEmptyMessage = computed(() => {
  if (displayedRows.value.length > 0) return "";
  if (orderQueueSyncPending.value || orderQueueRefreshing.value) return "";

  if (orderQueueDateFilterExcludes.value) {
    if (tabKey.value === "shipped") {
      return "Shipped orders exist in the index outside the selected date range. Try Last 7 Days in the date filter.";
    }
    return "Orders exist in the index outside the selected date range. Widen the date filter.";
  }

  if (tabKey.value === "shipped" && query.datePreset === "today") {
    return "No orders found for today. Shipped defaults to today — try Last 7 Days in the date filter.";
  }

  if (showOrderQueueRefresh.value) {
    if (orderQueueSyncStatus.value === "failed") {
      return "Order queue sync failed. Click Refresh or run orders:sync-queue-index --sync on the server.";
    }
    if (!orderQueueIndexHasRows.value && !orderQueueSyncedAt.value) {
      return "Order index is empty for this tab. Click Refresh or run orders:sync-queue-index --sync on the server.";
    }
    if (!orderQueueIndexHasRows.value) {
      return "No shipped orders in the index for this account yet. Click Refresh to sync from ShipHero.";
    }
  }

  return "No orders found.";
});
const ordersListCountLabel = computed(() => {
  if (queueTotal.value === null) {
    return `(${displayedRows.value.length} loaded)`;
  }
  const n = Number(queueTotal.value);
  const unit = queueCountMetric.value === "shipments"
    ? (n === 1 ? "shipment" : "shipments")
    : (n === 1 ? "order" : "orders");
  return `(${n} ${unit})`;
});

const isCustomDate = computed(() => query.datePreset === "custom");

const showAccountColumn = computed(() => !isEmbeddedOrders.value);
const tableColspan = computed(() => {
  const cols = isShippedTab.value ? 10 : 9;
  return showAccountColumn.value ? cols : cols - 1;
});

const displayedRows = computed(() => {
  return rows.value;
});

const manageMenuRow = computed(
  () => rows.value.find((row) => row.id === manageOpenId.value) ?? null,
);
const isPortalUser = computed(() => Number(crmUser.value?.client_account_id || 0) > 0);
const portalClientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const isAdminOrdersList = computed(() => !isPortalOrderList.value && !isEmbeddedOrders.value);

function effectiveClientAccountId(row = null) {
  const fromRow = Number(row?.client_account_id || 0);
  if (fromRow > 0) return fromRow;
  const fixed = Number(props.fixedClientAccountId || 0);
  if (fixed > 0) return fixed;
  return Number(selectedAccountId.value || 0);
}

const canWriteOrders = computed(() => canWriteShipHeroOrders(crmUser.value));

const showCreateOrderButton = computed(() => {
  if (isEmbeddedOrders.value || !canWriteOrders.value) return false;
  return true;
});

const isPortalModeForCreate = computed(
  () => isPortalOrderList.value || crmIsPortalUser(crmUser.value),
);

const createOrderDetailRouteName = computed(() =>
  isPortalModeForCreate.value ? "user-order-detail" : "order-detail",
);

const isShippedTab = computed(() => tabKey.value === "shipped");

const selectedCount = computed(() => selectedOrderIds.value.size);

/** Disable destructive / write bulk actions while a request runs or user cannot write. */
const bulkMutationDisabled = computed(() => bulkBusy.value || !canWriteOrders.value);

const allPageSelected = computed(() => {
  const ids = displayedRows.value.map((r) => String(r.id || "").trim()).filter(Boolean);
  if (!ids.length) return false;
  return ids.every((id) => selectedOrderIds.value.has(id));
});

const somePageSelected = computed(() => {
  const ids = displayedRows.value.map((r) => String(r.id || "").trim()).filter(Boolean);
  return ids.some((id) => selectedOrderIds.value.has(id));
});

/** Holds that “Remove Hold” can affect via ShipHero. */
function rowIsCrmUserHold(row) {
  if (row?.is_crm_user_hold === true) return true;
  const h = row?.holds && typeof row.holds === "object" ? row.holds : {};
  if (h.client_hold) return true;
  const tags = Array.isArray(row?.tags) ? row.tags : [];
  return !!(h.operator_hold && tags.includes("saverack:user_hold"));
}

function rowHasRemovableHolds(row) {
  const h = row?.holds && typeof row.holds === "object" ? row.holds : {};
  return !!(h.fraud_hold || h.address_hold || h.payment_hold || rowIsCrmUserHold(row));
}

const accountOptions = computed(() => {
  const source = isPortalUser.value
    ? (accounts.value || []).filter((a) => Number(a?.id || 0) === portalClientAccountId.value)
    : (accounts.value || []);
  return source
    .filter((a) => a?.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    }));
});

const ORDER_DETAIL_CACHE_TTL_MS = 30 * 60 * 1000;

function orderDetailFetchedAtKey(rowId, row = null) {
  return `orders.detail.fetchedAt.${effectiveClientAccountId(row)}.${String(rowId)}`;
}

function orderDetailShouldRefreshFromListForRow(row) {
  return orderDetailShouldRefreshFromList(row?.id, row);
}

function orderDetailShouldRefreshFromList(rowId, row = null) {
  try {
    const fetchedAt = Number(sessionStorage.getItem(orderDetailFetchedAtKey(rowId, row)) || 0);
    return !fetchedAt || Date.now() - fetchedAt > ORDER_DETAIL_CACHE_TTL_MS;
  } catch (_) {
    return true;
  }
}

function orderDetailQuery(row) {
  const accountId = effectiveClientAccountId(row);
  const query = { client_account_id: String(accountId) };
  if (row?.id && orderDetailShouldRefreshFromListForRow(row) && isPortalOrderList.value) {
    query.refresh = "1";
  }
  return query;
}

function cacheOrderSnapshotForDetail(row) {
  const accountId = effectiveClientAccountId(row);
  if (!row?.id || !accountId) return;
  const key = `orders.snapshot.${accountId}.${String(row.id)}`;
  try {
    sessionStorage.setItem(key, JSON.stringify(row));
  } catch (_) {
    // Best-effort cache for detail fallback.
  }
}

function onOrderNumberClick(event, row) {
  const accountId = effectiveClientAccountId(row);
  if (!row?.id || !accountId) {
    event.preventDefault();
    toast.error("This order has no account context.");
    return;
  }
  cacheOrderSnapshotForDetail(row);
}

function orderDetailHref(row) {
  const accountId = effectiveClientAccountId(row);
  if (!row?.id || !accountId) return "#";
  const name = isPortalOrderList.value ? "user-order-detail" : "order-detail";
  return router.resolve({
    name,
    params: { shipheroOrderId: String(row.id) },
    query: orderDetailQuery(row),
  }).href;
}

function openOrderViewNewTab(row) {
  const accountId = effectiveClientAccountId(row);
  if (!row?.id || !accountId) {
    toast.error("This order has no account context.");
    return;
  }
  cacheOrderSnapshotForDetail(row);
  const url = orderDetailHref(row);
  window.open(url, "_blank", "noopener,noreferrer");
  manageOpenId.value = null;
}

function statusClass(status) {
  const s = String(status || "").toLowerCase();
  if (s.includes("hold")) return "bg-danger-subtle text-danger-emphasis";
  if (s.includes("backorder") || s.includes("back order")) {
    return "bg-danger-subtle text-danger-emphasis";
  }
  if (s.includes("unfulfilled")) return "bg-secondary-subtle text-secondary-emphasis";
  if (s.includes("incomplete")) return "bg-secondary-subtle text-secondary-emphasis";
  if (s.includes("fulfilled") || s === "complete" || s.includes("complete")) {
    return "bg-success-subtle text-success-emphasis";
  }
  if (s.includes("ship")) return "bg-success-subtle text-success-emphasis";
  if (s.includes("ready")) return "bg-primary-subtle text-primary-emphasis";
  return "bg-secondary-subtle text-secondary-emphasis";
}

function normalizedHoldReasonLabel(value) {
  const v = String(value || "")
    .trim()
    .toLowerCase();
  if (v === "fraud" || v === "fraud hold") return "Fraud Hold";
  if (v === "address" || v === "address hold") return "Address Hold";
  if (v === "operator" || v === "operator hold") return "Operator Hold";
  if (v === "payment" || v === "payment hold") return "Payment Hold";
  if (v === "user" || v === "user hold" || v === "client hold") return "User Hold";
  if (v === "shipping" || v === "shipping hold" || v === "shipping method hold") return "Shipping Method Hold";
  return "";
}

function normalizeOrderNumberInput(v) {
  return String(v || "")
    .trim()
    .replace(/^#+/, "");
}

function runListSearch() {
  const orderNum = normalizeOrderNumberInput(query.orderNumber);
  if (isOrdersSearchPage.value) {
    if (!orderNum) {
      toast.error("Enter an order number to search.");
      return;
    }
    committedOrderNumber.value = orderNum;
    crossAccountMode.value = isAdminOrdersList.value && !selectedAccountId.value;
    fetchOrders(true);
    return;
  }
  if (!isAdminOrdersList.value && !selectedAccountId.value) {
    toast.error("Select an account to load orders.");
    return;
  }
  committedOrderNumber.value = orderNum;
  crossAccountMode.value = isAdminOrdersList.value && !selectedAccountId.value;
  fetchOrders(true);
}

function holdReasonLabelsForRow(row) {
  const h = row?.holds && typeof row.holds === "object" ? row.holds : {};
  const labels = [];
  if (h.fraud_hold) labels.push("Fraud Hold");
  if (h.payment_hold) labels.push("Payment Hold");
  if (h.address_hold) labels.push("Address Hold");
  if (h.operator_hold) labels.push(rowIsCrmUserHold(row) ? "User Hold" : "Operator Hold");
  if (h.client_hold && !labels.includes("User Hold")) labels.push("User Hold");
  if (h.shipping_method_hold) labels.push("Shipping Method Hold");

  if (labels.length) return labels;

  const raw = String(row?.hold_reason || row?.display_status || "").trim();
  if (!raw) return [];

  return raw
    .split(",")
    .map((part) => part.trim())
    .filter(Boolean);
}

function firstHoldReasonLabel(row) {
  const labels = holdReasonLabelsForRow(row);
  return labels[0] || "";
}

/** Prefer API display_status; tab overrides remain for legacy rows without it. */
function displayOrderStatus(row) {
  const fromApi = String(row?.display_status || "").trim();
  if (fromApi && fromApi !== "—") {
    return fromApi;
  }
  if (tabKey.value === "awaiting") {
    return "Ready To Ship";
  }
  if (tabKey.value === "on_hold") {
    const selected = normalizedHoldReasonLabel(query.holdReason);
    if (selected) return selected;
    return firstHoldReasonLabel(row) || row.status || "—";
  }
  if (tabKey.value === "backorder") {
    return "Backorder";
  }
  const legacy = String(row?.status || row?.raw_fulfillment_status || "").trim();
  return legacy !== "" ? legacy : "—";
}

function statusClassForRow(row) {
  return statusClass(displayOrderStatus(row));
}

function formatDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString();
}

function rowDisplayDate(row) {
  if (isShippedTab.value) {
    return formatDate(row.ship_date || row.order_date);
  }
  return formatDate(row.order_date);
}

const orderDateColumnLabel = computed(() => (isShippedTab.value ? "Ship Date" : "Order Date"));


function rowTrackingLabels(row) {
  const labels = row?.tracking_labels;
  return Array.isArray(labels) ? labels : [];
}

function toDateInput(d) {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function dateRangeFromPreset() {
  const now = new Date();
  const today = toDateInput(now);
  if (query.datePreset === "all") return { from: null, to: null };
  if (query.datePreset === "since_may_1") return { from: RTS_ORDER_DATE_FROM, to: today };
  if (query.datePreset === "today") return { from: today, to: today };
  if (query.datePreset === "last_7") {
    const d = new Date(now);
    d.setDate(d.getDate() - 6);
    return { from: toDateInput(d), to: today };
  }
  if (query.datePreset === "last_30") {
    const d = new Date(now);
    d.setDate(d.getDate() - 29);
    return { from: toDateInput(d), to: today };
  }
  return {
    from: query.from || null,
    to: query.to || null,
  };
}

/** ShipHero date params for list API. Shipped tab uses ship date; other tabs use order placement date. */
function orderDateParamsForRequest() {
  if (committedOrderNumber.value) {
    return {};
  }
  if (query.datePreset === "all") {
    return {};
  }
  const range = dateRangeFromPreset();
  const out = {};
  if (range.from) out.order_date_from = range.from;
  if (range.to) out.order_date_to = range.to;
  return out;
}

function buildParams(withCursor = false) {
  const apiTab = tabKey.value === "search" ? "manage" : tabKey.value;
    const params = {
    tab: apiTab,
    first: crossAccountMode.value ? 25 : 50,
    ...orderDateParamsForRequest(),
  };
  if (!crossAccountMode.value && selectedAccountId.value) {
    params.client_account_id = Number(selectedAccountId.value);
  }
  if (query.fulfillmentStatus) params.fulfillment_status = query.fulfillmentStatus;
  if (query.readyToShip !== "") params.ready_to_ship = query.readyToShip === "yes";
  if (tabKey.value === "on_hold" && query.holdReason) params.hold_reason = query.holdReason;
  if (committedOrderNumber.value) {
    params.order_number = committedOrderNumber.value;
  }
  if (withCursor && nextCursor.value && !crossAccountMode.value) {
    params.after = nextCursor.value;
  }
  return params;
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  } finally {
    accountsLoading.value = false;
  }
}

async function fetchOrders(reset = true, options = {}) {
  if (isAdminOrdersList.value && isOrdersSearchPage.value && !selectedAccountId.value) {
    crossAccountMode.value = true;
  } else if (!isOrdersSearchPage.value) {
    crossAccountMode.value = false;
  }
  const canLoad = isOrdersSearchPage.value
    ? Boolean(committedOrderNumber.value)
    : Boolean(selectedAccountId.value);
  if (!canLoad) {
    if (reset) {
      rows.value = [];
      hasSearched.value = false;
      hasNextPage.value = false;
      nextCursor.value = null;
      clearRowSelection();
      stopOrderQueuePoll();
      orderQueueSyncPending.value = false;
      orderQueueSyncMessage.value = "";
      orderQueueSyncStatus.value = "";
      orderQueueIndexHasRows.value = false;
      orderQueueDateFilterExcludes.value = false;
    }
    return;
  }
  if (!options.poll && !options.refresh) {
    loading.value = true;
  }
  if (reset && !options.poll && !options.refresh) {
    rows.value = [];
    nextCursor.value = null;
    hasNextPage.value = false;
    queueTotal.value = null;
    clearRowSelection();
  }
  try {
    const params = buildParams(!reset);
    if (options.refresh) {
      params.refresh = 1;
    }
    const { data } = await api.get("/orders", {
      params,
    });
    const incoming = Array.isArray(data?.rows) ? data.rows : [];
    rows.value = reset ? incoming : [...rows.value, ...incoming];
    const crossAccount = Boolean(data?.meta?.cross_account);
    if (crossAccount) {
      crossAccountMode.value = true;
    }
    crossAccountScanTruncated.value = Boolean(data?.meta?.scan_truncated);
    if (crossAccount && data?.meta?.scan_truncated) {
      if (!incoming.length && committedOrderNumber.value) {
        toast.error(
          "No match in the accounts searched (scan capped for speed). Select an account and search again.",
        );
      } else if (!incoming.length && !committedOrderNumber.value) {
        toast.error(
          "No orders returned from the accounts searched (scan capped for speed). Select an account or try again.",
        );
      }
    } else {
      crossAccountScanTruncated.value = false;
    }
    hasNextPage.value = crossAccount ? false : Boolean(data?.pagination?.has_next_page);
    nextCursor.value = crossAccount ? null : data?.pagination?.end_cursor || null;
    if (data?.meta?.order_queue_synced_at) {
      orderQueueSyncedAt.value = data.meta.order_queue_synced_at;
      if (props.embedded) {
        emit("queue-refreshed", data.meta.order_queue_synced_at);
      }
    }
    applyOrderQueueSyncMeta(data?.meta, incoming.length, options);
    if (data?.meta && typeof data.meta.queue_total === "number") {
      queueTotal.value = data.meta.queue_total;
      queueCountMetric.value = data.meta.queue_count_metric === "shipments" ? "shipments" : "orders";
    } else {
      queueTotal.value = null;
      queueCountMetric.value = tabKey.value === "shipped" ? "shipments" : "orders";
    }
  } catch (e) {
    if (!options.poll) {
      toast.errorFrom(e, "Could not load orders.");
    }
  } finally {
    if (!options.poll && !options.refresh) {
      loading.value = false;
    }
    /** Always set after an attempt so the table never sits in a blank state (no row matched v-if / v-for). */
    hasSearched.value = true;
  }
}

function applyOrderQueueSyncMeta(meta, rowCount, options = {}) {
  if (!showOrderQueueRefresh.value) {
    orderQueueSyncPending.value = false;
    orderQueueSyncMessage.value = "";
    orderQueueSyncStatus.value = "";
    orderQueueIndexHasRows.value = false;
    orderQueueDateFilterExcludes.value = false;
    stopOrderQueuePoll();
    return;
  }

  const syncStatus = String(meta?.order_queue_sync_status || "");
  orderQueueSyncStatus.value = syncStatus;
  orderQueueIndexHasRows.value = Boolean(meta?.index_has_rows);
  orderQueueDateFilterExcludes.value = Boolean(meta?.date_filter_excludes_index);
  const pending = Boolean(meta?.refresh_pending);
  orderQueueSyncPending.value = pending;
  orderQueueSyncMessage.value = String(meta?.message || "").trim();

  if (pending) {
    startOrderQueuePoll();
    return;
  }

  stopOrderQueuePoll();
  orderQueueRefreshing.value = false;

  if (!options.userRefresh) {
    return;
  }

  orderQueueUserRefresh = false;
  if (rowCount > 0 || syncStatus === "idle") {
    toast.success("Order queue refreshed.");
  } else if (syncStatus === "failed") {
    toast.error("Order queue sync failed. Try again.");
  }
}

function stopOrderQueuePoll() {
  if (orderQueuePollTimer !== null) {
    window.clearInterval(orderQueuePollTimer);
    orderQueuePollTimer = null;
  }
}

async function pollOrderQueue() {
  if (!showOrderQueueRefresh.value || !selectedAccountId.value) {
    stopOrderQueuePoll();
    return;
  }
  try {
    await fetchOrders(true, { poll: true, userRefresh: orderQueueUserRefresh });
  } catch {
    /* keep polling on transient errors */
  }
}

function startOrderQueuePoll() {
  if (orderQueuePollTimer !== null) {
    return;
  }
  orderQueuePollTimer = window.setInterval(() => {
    void pollOrderQueue();
  }, ORDER_QUEUE_POLL_MS);
  void pollOrderQueue();
}

function stopAutoSyncTimer() {
  if (autoSyncTimer !== null) {
    window.clearInterval(autoSyncTimer);
    autoSyncTimer = null;
  }
}

function startAutoSyncTimer() {
  stopAutoSyncTimer();
  if (!showOrderQueueRefresh.value) {
    return;
  }
  autoSyncTimer = window.setInterval(() => {
    if (!loading.value && !orderQueueRefreshing.value && selectedAccountId.value) {
      void fetchOrders(true, { refresh: true });
    }
  }, AUTO_SYNC_INTERVAL_MS);
}

async function refreshOrderQueue() {
  if (!showOrderQueueRefresh.value) {
    return;
  }
  orderQueueRefreshing.value = true;
  orderQueueUserRefresh = true;
  try {
    await fetchOrders(true, { refresh: true, userRefresh: true });
  } catch {
    /* toast handled in fetchOrders */
    orderQueueRefreshing.value = false;
    orderQueueUserRefresh = false;
  }
}

function openOrder(row) {
  openOrderViewNewTab(row);
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) manageOpenId.value = null;
  if (!e.target?.closest?.("[data-toolbar-filter]")) filterMenuOpen.value = false;
}

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  const width = 200;
  const height = 360;
  let top = rect.bottom + 4;
  let left = rect.right - width;
  left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
  if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 4);
  manageMenuRect.value = { top, left };
}

async function toggleManageMenu(id, e) {
  e.stopPropagation();
  if (manageOpenId.value === id) {
    manageOpenId.value = null;
    return;
  }
  const btn = e.currentTarget;
  manageOpenId.value = id;
  requestAnimationFrame(() => {
    if (manageOpenId.value === id && btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function clearRowSelection() {
  selectedOrderIds.value = new Set();
}

const selectAllCheckboxRef = ref(null);

watch([allPageSelected, somePageSelected, displayedRows], () => {
  nextTick(() => {
    const el = selectAllCheckboxRef.value;
    if (el && typeof el.indeterminate !== "undefined") {
      el.indeterminate = somePageSelected.value && !allPageSelected.value;
    }
  });
});

function defaultDatePresetForCurrentTab() {
  if (tabKey.value === "awaiting") {
    return "since_may_1";
  }
  if (tabKey.value === "on_hold" || tabKey.value === "backorder") {
    return "all";
  }
  return "today";
}

function resetToolbarFiltersFromMenu() {
  query.datePreset = defaultDatePresetForCurrentTab();
  query.from = "";
  query.to = "";
  query.fulfillmentStatus = "";
  query.readyToShip = "";
  query.holdReason = "";
  filterMenuOpen.value = false;
}

function toggleSelectAllPage() {
  const ids = displayedRows.value.map((r) => String(r.id || "").trim()).filter(Boolean);
  if (!ids.length) return;
  if (allPageSelected.value) {
    const next = new Set(selectedOrderIds.value);
    ids.forEach((id) => next.delete(id));
    selectedOrderIds.value = next;
    return;
  }
  const next = new Set(selectedOrderIds.value);
  ids.forEach((id) => next.add(id));
  selectedOrderIds.value = next;
}

function toggleRowSelected(row) {
  const id = String(row?.id || "").trim();
  if (!id) return;
  const next = new Set(selectedOrderIds.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  selectedOrderIds.value = next;
}

function isRowSelected(row) {
  return selectedOrderIds.value.has(String(row?.id || ""));
}

function selectedRowsList() {
  const want = selectedOrderIds.value;
  return displayedRows.value.filter((r) => want.has(String(r.id || "")));
}

async function onDraftOrderCreated(data) {
  createOrderModalOpen.value = false;
  const draftRouteId = String(data?.draft_route_id || "");
  const clientAccountId = Number(data?.client_account_id || 0);
  if (!draftRouteId || clientAccountId <= 0) {
    toast.error("Draft was created but the response was incomplete.");
    return;
  }
  toast.success("Order draft created.");
  await router.push({
    name: createOrderDetailRouteName.value,
    params: { shipheroOrderId: draftRouteId },
    query: { client_account_id: String(clientAccountId) },
  });
}

function openPlaceHoldModalForIds(ids) {
  if (!canWriteOrders.value) {
    toast.error("You do not have permission to update orders.");
    return;
  }
  const clean = [...new Set(ids.map((x) => String(x || "").trim()).filter(Boolean))];
  if (!clean.length) return;
  manageOpenId.value = null;
  placeHoldTargetIds.value = clean;
  placeHoldModalOpen.value = true;
}

function closePlaceHoldModal() {
  if (placeHoldBusy.value) return;
  placeHoldModalOpen.value = false;
  placeHoldTargetIds.value = [];
}

async function submitPlaceHoldModal(flags) {
  if (!selectedAccountId.value) {
    toast.error("Select an account first.");
    return;
  }
  if (!placeHoldTargetIds.value.length) return;
  if (!flags?.fraud_hold && !flags?.address_hold && !flags?.payment_hold && !flags?.client_hold) {
    toast.error("Select at least one hold type.");
    return;
  }
  placeHoldBusy.value = true;
  try {
    const { data } = await api.post("/orders/bulk/set-holds", {
      client_account_id: Number(selectedAccountId.value),
      order_ids: placeHoldTargetIds.value,
      fraud_hold: !!flags.fraud_hold,
      address_hold: !!flags.address_hold,
      payment_hold: !!flags.payment_hold,
      client_hold: !!flags.client_hold,
    });
    const ok = Number(data?.summary?.ok ?? 0);
    const failed = Number(data?.summary?.failed ?? 0);
    const firstErr = (data?.results || []).find((r) => r && r.ok === false)?.message;
    if (failed > 0) {
      toast.error(firstErr || `Holds applied: ${ok} succeeded, ${failed} failed.`);
    } else {
      toast.success(`Holds applied: ${ok} succeeded.`);
    }
    placeHoldModalOpen.value = false;
    placeHoldTargetIds.value = [];
    manageOpenId.value = null;
    clearRowSelection();
    await fetchOrders(true);
  } catch (e) {
    toast.errorFrom(e, "Could not place holds.");
  } finally {
    placeHoldBusy.value = false;
  }
}

function csvEscapeCell(v) {
  const s = String(v ?? "");
  if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
  return s;
}

function exportRowsToCsv(rowList) {
  const headers = [
    "Status",
    "Order #",
    "Name",
    orderDateColumnLabel.value,
    ...(showAccountColumn.value ? ["Account"] : []),
    "Country",
    "Current Shipping Method",
    "Email",
  ];
  const lines = [headers.join(",")];
  for (const row of rowList) {
    const status = displayOrderStatus(row);
    lines.push(
      [
        csvEscapeCell(status),
        csvEscapeCell(row.order_number || ""),
        csvEscapeCell(row.recipient_name || "—"),
        csvEscapeCell(rowDisplayDate(row)),
        ...(showAccountColumn.value ? [csvEscapeCell(row.account || "")] : []),
        csvEscapeCell(row.country || "—"),
        csvEscapeCell(row.country || ""),
        csvEscapeCell(
          formatCurrentShippingMethod(row.shipping_carrier, row.method, row.shipping_method_title),
        ),
        csvEscapeCell(row.email || ""),
      ].join(","),
    );
  }
  const blob = new Blob(["\ufeff" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `orders-export-${tabKey.value}-${Date.now()}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

function exportSelectedCsv() {
  const list = selectedRowsList();
  if (!list.length) {
    toast.error("Select at least one order.");
    return;
  }
  exportRowsToCsv(list);
  toast.success("Export started.");
}

function exportOneRow(row) {
  exportRowsToCsv([row]);
  manageOpenId.value = null;
  toast.success("Export started.");
}

async function runBulkMarkFulfilled() {
  if (!selectedAccountId.value) return;
  const ids = selectedRowsList()
    .map((r) => String(r.id))
    .filter(Boolean);
  if (!ids.length) return;
  bulkBusy.value = true;
  try {
    const { data } = await api.post("/orders/bulk/mark-fulfilled", {
      client_account_id: Number(selectedAccountId.value),
      order_ids: ids,
    });
    toast.success(`Marked fulfilled: ${data?.summary?.ok ?? 0} ok, ${data?.summary?.failed ?? 0} failed.`);
    confirmBulkMarkFulfilledOpen.value = false;
    clearRowSelection();
    await fetchOrders(true);
  } catch (e) {
    toast.errorFrom(e, "Bulk mark fulfilled failed.");
  } finally {
    bulkBusy.value = false;
  }
}

async function runBulkCancel() {
  if (!selectedAccountId.value) return;
  const ids = selectedRowsList()
    .map((r) => String(r.id))
    .filter(Boolean);
  if (!ids.length) return;
  bulkBusy.value = true;
  try {
    const { data } = await api.post("/orders/bulk/cancel", {
      client_account_id: Number(selectedAccountId.value),
      order_ids: ids,
    });
    toast.success(`Canceled: ${data?.summary?.ok ?? 0} ok, ${data?.summary?.failed ?? 0} failed.`);
    confirmBulkCancelOpen.value = false;
    clearRowSelection();
    await fetchOrders(true);
  } catch (e) {
    toast.errorFrom(e, "Bulk cancel failed.");
  } finally {
    bulkBusy.value = false;
  }
}

async function runBulkAllowPartial() {
  if (!selectedAccountId.value) return;
  const ids = selectedRowsList()
    .map((r) => String(r.id))
    .filter(Boolean);
  if (!ids.length) return;
  bulkBusy.value = true;
  try {
    const { data } = await api.post("/orders/bulk/allow-partial", {
      client_account_id: Number(selectedAccountId.value),
      order_ids: ids,
      allow_partial: true,
    });
    toast.success(`Allow partial: ${data?.summary?.ok ?? 0} ok, ${data?.summary?.failed ?? 0} failed.`);
    confirmBulkAllowPartialOpen.value = false;
    clearRowSelection();
    await fetchOrders(true);
  } catch (e) {
    toast.errorFrom(e, "Bulk allow partial failed.");
  } finally {
    bulkBusy.value = false;
  }
}

function normalizeRowHoldsForRemoveModal(row) {
  const h = row?.holds && typeof row.holds === "object" ? row.holds : {};
  return {
    fraud_hold: !!h.fraud_hold,
    address_hold: !!h.address_hold,
    payment_hold: !!h.payment_hold,
    client_hold: rowIsCrmUserHold(row),
  };
}

function closeRemoveHoldsModal() {
  if (bulkBusy.value) return;
  removeHoldsModalOpen.value = false;
  removeHoldsSingleOrderId.value = "";
}

function openBulkRemoveHoldsModal() {
  removeHoldsModalVariant.value = "bulk";
  removeHoldsModalOpen.value = true;
}

function openSingleRemoveHoldsModal(row) {
  if (!effectiveClientAccountId(row) || !row?.id) return;
  manageOpenId.value = null;
  removeHoldsModalVariant.value = "single";
  removeHoldsModalActiveHolds.value = normalizeRowHoldsForRemoveModal(row);
  removeHoldsSingleOrderId.value = String(row.id).trim();
  removeHoldsModalOpen.value = true;
}

function removeHoldsSingleRow() {
  const oid = removeHoldsSingleOrderId.value;
  if (!oid) return null;
  return displayedRows.value.find((r) => String(r.id) === oid) ?? null;
}

async function onRemoveHoldsModalConfirm(payload) {
  const accountId =
    removeHoldsModalVariant.value === "single"
      ? effectiveClientAccountId(removeHoldsSingleRow())
      : Number(selectedAccountId.value);
  if (!accountId || !payload?.holds_to_clear?.length) return;
  if (removeHoldsModalVariant.value === "bulk") {
    const ids = selectedRowsList()
      .map((r) => String(r.id))
      .filter(Boolean);
    if (!ids.length) return;
  }
  bulkBusy.value = true;
  try {
    const bodyCommon = {
      holds_to_clear: payload.holds_to_clear,
    };
    if (payload.payment_hold_reason) {
      bodyCommon.payment_hold_reason = payload.payment_hold_reason;
    }
    if (removeHoldsModalVariant.value === "bulk") {
      const ids = selectedRowsList()
        .map((r) => String(r.id))
        .filter(Boolean);
      const { data } = await api.post("/orders/bulk/clear-holds", {
        client_account_id: accountId,
        order_ids: ids,
        ...bodyCommon,
      });
      toast.success(`Remove hold: ${data?.summary?.ok ?? 0} ok, ${data?.summary?.failed ?? 0} failed.`);
      removeHoldsModalOpen.value = false;
      removeHoldsSingleOrderId.value = "";
      clearRowSelection();
      await fetchOrders(true);
    } else {
      const oid = removeHoldsSingleOrderId.value;
      if (!oid) {
        bulkBusy.value = false;
        return;
      }
      await api.post(`/orders/${encodeURIComponent(oid)}/remove-holds`, {
        client_account_id: accountId,
        ...bodyCommon,
      });
      toast.success("Holds cleared.");
      removeHoldsModalOpen.value = false;
      removeHoldsSingleOrderId.value = "";
      await fetchOrders(true);
    }
  } catch (e) {
    toast.errorFrom(
      e,
      removeHoldsModalVariant.value === "bulk" ? "Bulk remove holds failed." : "Could not remove holds.",
    );
  } finally {
    bulkBusy.value = false;
  }
}

async function runSingleMarkFulfilled(row) {
  const accountId = effectiveClientAccountId(row);
  if (!accountId || !row?.id) return;
  manageOpenId.value = null;
  bulkBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(String(row.id))}/mark-fulfilled`, {
      client_account_id: accountId,
    });
    toast.success("Order marked fulfilled.");
    await fetchOrders(true);
  } catch (e) {
    toast.errorFrom(e, "Could not mark fulfilled.");
  } finally {
    bulkBusy.value = false;
  }
}

async function runSingleAllowPartial(row) {
  const accountId = effectiveClientAccountId(row);
  if (!accountId || !row?.id) return;
  manageOpenId.value = null;
  bulkBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(String(row.id))}/allow-partial`, {
      client_account_id: accountId,
      allow_partial: true,
    });
    toast.success("Allow partial updated.");
    await fetchOrders(true);
  } catch (e) {
    toast.errorFrom(e, "Could not update allow partial.");
  } finally {
    bulkBusy.value = false;
  }
}

async function runSingleCancel(row) {
  const accountId = effectiveClientAccountId(row);
  if (!accountId || !row?.id) return;
  manageOpenId.value = null;
  bulkBusy.value = true;
  try {
    await api.post(`/orders/${encodeURIComponent(String(row.id))}/cancel`, {
      client_account_id: accountId,
    });
    toast.success("Order canceled.");
    await fetchOrders(true);
  } catch (e) {
    toast.errorFrom(e, "Could not cancel order.");
  } finally {
    bulkBusy.value = false;
  }
}

watch(
  () => [isPortalOrderList.value, portalClientAccountId.value],
  ([portal, id]) => {
    if (portal && Number(id) > 0) {
      selectedAccountId.value = String(id);
    }
  },
  { immediate: true },
);

watch(
  tabKey,
  (_newTab, oldTab) => {
    clearRowSelection();
    /** Ready to Ship tab defaults to last 7 days of order date; other tabs default to today (bounded window; ShipHero was often empty with no dates). */
    query.datePreset = defaultDatePresetForCurrentTab();
    query.from = "";
    query.to = "";
    query.fulfillmentStatus = "";
    query.readyToShip = "";
    if (oldTab !== undefined) {
      query.holdReason = "";
    } else if (tabKey.value === "on_hold") {
      applyOrdersRouteQuery();
    }
    query.orderNumber = "";
    committedOrderNumber.value = "";
    crossAccountMode.value = false;
  },
  { immediate: true },
);

watch(
  () => [selectedAccountId.value, tabKey.value],
  ([accountId], oldVal) => {
    const prevAccountId = oldVal?.[0];
    if (prevAccountId && accountId !== prevAccountId) {
      query.orderNumber = "";
      committedOrderNumber.value = "";
    }
    clearRowSelection();
    crossAccountMode.value = false;
    if (tabKey.value === "search") {
      rows.value = [];
      hasSearched.value = false;
      hasNextPage.value = false;
      nextCursor.value = null;
      return;
    }
    if (!accountId) {
      rows.value = [];
      hasSearched.value = false;
      hasNextPage.value = false;
      nextCursor.value = null;
      return;
    }
    fetchOrders(true);
  },
  { immediate: true },
);

watch(
  () => [query.datePreset, query.from, query.to, query.fulfillmentStatus, query.readyToShip, query.holdReason],
  () => {
    if (!showManageFilters.value) return;
    if (!selectedAccountId.value && !crossAccountMode.value && !hasSearched.value) return;
    fetchOrders(true);
  },
);

watch(
  () => [route.query.client_account_id, route.query.hold_reason],
  () => {
    applyOrdersRouteQuery();
    if (!selectedAccountId.value || tabKey.value === "search") return;
    fetchOrders(true);
  },
);

watch(selectedAccountId, (id) => {
  if (id) {
    crossAccountMode.value = false;
  }
});

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  if (!isEmbeddedOrders.value) {
    setCrmPageMeta({
      title: `Save Rack | Orders | ${tabTitle.value}`,
      description: route.meta?.userPortal ? "Your account orders." : "ShipHero customer orders.",
    });
  }
  await loadAccounts();
  applyOrdersRouteQuery();
  const fixedId = Number(props.fixedClientAccountId || 0);
  if (fixedId > 0) {
    selectedAccountId.value = String(fixedId);
    crossAccountMode.value = false;
    await runListSearch();
  } else if (isPortalUser.value && portalClientAccountId.value > 0) {
    selectedAccountId.value = String(portalClientAccountId.value);
  }
  startAutoSyncTimer();
});

useShipHeroRevisionPoll({
  getClientAccountId: () => Number(selectedAccountId.value || 0),
  pollQueueCounts: () => showOrderQueueRefresh.value,
  onQueueRevision: () => fetchOrders(true),
});

watch(showOrderQueueRefresh, () => {
  startAutoSyncTimer();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  stopAutoSyncTimer();
  stopOrderQueuePoll();
});
</script>

<template>
  <div class="staff-page staff-page--wide" :class="{ 'orders-list--embedded': isEmbeddedOrders }">
    <div v-if="!isEmbeddedOrders" class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">
          <span>Orders - {{ tabTitle }}</span>
          <span
            v-if="(selectedAccountId || crossAccountMode) && hasSearched"
            class="small text-secondary fw-normal ms-1"
          >
            {{ ordersListCountLabel }}
          </span>
        </h1>
        <p v-if="isOrdersSearchPage" class="text-secondary small mb-0">
          Optionally select an account, enter an order number, and click Search. Results appear only after you
          search. ShipHero also matches storefront IDs on partner order ID when applicable.
        </p>
        <p v-else-if="!isPortalOrderList" class="text-secondary small mb-0">
          Select an account to load orders. Leave order # blank and click Search to load recent orders (up to
          100, may be partial).
        </p>
      </div>
      <button
        v-if="showCreateOrderButton"
        type="button"
        class="btn btn-primary btn-sm staff-page-primary flex-shrink-0 align-self-start"
        @click="createOrderModalOpen = true"
      >
        Create Order
      </button>
    </div>

    <div
      v-if="crossAccountMode && crossAccountScanTruncated && hasSearched && displayedRows.length > 0"
      class="alert alert-warning small py-2 mb-3"
      role="status"
    >
      Showing partial results — not all accounts were scanned within the time limit. Select an account for a
      complete list.
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 orders-page-toolbar">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row orders-toolbar-row">
          <div
            v-if="isAdminOrdersList"
            class="orders-toolbar-account flex-shrink-0"
          >
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="All accounts"
              button-id="orders-list-account-trigger"
            />
          </div>

          <div class="orders-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                id="orders-order-number-search"
                v-model.trim="query.orderNumber"
                type="search"
                class="form-control"
                placeholder="Search by Order #"
                :disabled="loading"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search by order number"
                @keydown.enter.prevent="runListSearch"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn"
                :disabled="loading"
                @click="runListSearch"
              >
                Search
              </button>
            </div>
          </div>

          <template v-if="showManageFilters">
            <div class="position-relative flex-shrink-0" data-toolbar-filter>
              <button
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
                  :aria-expanded="filterMenuOpen"
                  @click.stop="filterMenuOpen = !filterMenuOpen"
                >
                  <svg
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                    />
                  </svg>
                  <span class="staff-toolbar-filter-text">Filters</span>
                </button>
                <div
                  v-if="filterMenuOpen"
                  class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
                  role="dialog"
                  aria-label="Order filters"
                  @click.stop
                >
                  <div class="staff-toolbar-filter-dropdown__head">
                    <span>Filters</span>
                    <button type="button" class="btn btn-link btn-sm text-secondary text-decoration-none p-0" @click="resetToolbarFiltersFromMenu">
                      Reset
                    </button>
                  </div>
                  <div class="staff-toolbar-filter-dropdown__body">
                    <p class="small text-secondary mb-2">
                      <template v-if="tabKey === 'manage'">
                        <template v-if="!isPortalOrderList">
                          This tab is the broad list (all queues at once, filtered only by what you set below).
                          <strong>Order date</strong> defaults to <strong>today</strong>. Searching by
                          <strong>order #</strong> ignores the date range so you can open a specific order.
                        </template>
                        <template v-else>
                          <strong>Order date</strong> defaults to <strong>today</strong>. Searching by
                          <strong>order #</strong> ignores the date range so you can open a specific order.
                        </template>
                      </template>
                      <template v-else-if="tabKey === 'shipped'">
                        <strong>Shipped</strong> defaults to <strong>today</strong> by <strong>ship date</strong> (when the label was created). Widen the date range if you need older fulfilled orders.
                      </template>
                      <template v-else-if="tabKey === 'awaiting'">
                        This tab lists <strong>orders awaiting shipment</strong>. The default window matches the
                        dashboard (<strong>May 1 through today</strong> by order date). Choose a different preset or
                        custom range if you need another window.
                      </template>
                      <template v-else>
                        Defaults to <strong>today</strong> by order date. Use <strong>Any Order Date</strong> or a custom range if the list looks empty.
                      </template>
                    </p>
                    <label class="form-label" for="orders-filter-date-preset">Date Range</label>
                    <select
                      id="orders-filter-date-preset"
                      v-model="query.datePreset"
                      class="form-select staff-datatable-filters__select mb-3"
                      :disabled="loading"
                    >
                      <option v-if="tabKey === 'awaiting'" value="since_may_1">Since May 1 (Dashboard)</option>
                      <option value="all">Any Order Date</option>
                      <option value="today">Today</option>
                      <option value="last_7">Last 7 Days</option>
                      <option value="last_30">Last 30 Days</option>
                      <option value="custom">Custom Range</option>
                    </select>
                    <template v-if="isCustomDate">
                      <label class="form-label" for="orders-filter-from">From</label>
                      <input
                        id="orders-filter-from"
                        v-model="query.from"
                        type="date"
                        class="form-control staff-datatable-filters__select mb-3"
                        :disabled="loading"
                      />
                      <label class="form-label" for="orders-filter-to">To</label>
                      <input
                        id="orders-filter-to"
                        v-model="query.to"
                        type="date"
                        class="form-control staff-datatable-filters__select mb-3"
                        :disabled="loading"
                      />
                    </template>
                    <label class="form-label" for="orders-filter-fulfillment-status">Fulfillment Status</label>
                    <select
                      id="orders-filter-fulfillment-status"
                      v-model="query.fulfillmentStatus"
                      class="form-select staff-datatable-filters__select mb-3"
                      :disabled="loading"
                    >
                      <option value="">All</option>
                      <option value="unfulfilled">Unfulfilled</option>
                      <option value="fulfilled">Fulfilled</option>
                      <option value="shipped">Shipped</option>
                    </select>
                    <label class="form-label" for="orders-filter-ready-to-ship">Ready to Ship</label>
                    <select
                      id="orders-filter-ready-to-ship"
                      v-model="query.readyToShip"
                      class="form-select staff-datatable-filters__select"
                      :disabled="loading"
                    >
                      <option value="">All</option>
                      <option value="yes">Yes</option>
                      <option value="no">No</option>
                    </select>
                    <template v-if="tabKey === 'on_hold'">
                      <label class="form-label mt-3" for="orders-filter-hold-reason">Hold Reason</label>
                      <select
                        id="orders-filter-hold-reason"
                        v-model="query.holdReason"
                        class="form-select staff-datatable-filters__select"
                        :disabled="loading"
                      >
                        <option value="">All Hold Reasons</option>
                        <option value="fraud">Fraud Hold</option>
                        <option value="address">Address Hold</option>
                        <option value="operator">Operator Hold</option>
                        <option value="payment">Payment Hold</option>
                        <option value="user">User Hold</option>
                      </select>
                    </template>
                  </div>
                </div>
              </div>
          </template>

          <CrmSyncToolbar
            v-if="showOrderQueueRefresh"
            :last-synced-label="orderQueueSyncedLabel"
            class="ms-auto flex-shrink-0"
          >
            <CrmRefreshToolbarButton
              :disabled="orderQueueRefreshing || !selectedAccountId"
              :loading="orderQueueRefreshing"
              title="Refresh order queue from ShipHero"
              @click="refreshOrderQueue"
            />
          </CrmSyncToolbar>
        </div>
        <p v-if="!isPortalOrderList" class="small text-secondary mb-0 mt-2 px-1">Only accounts with a ShipHero customer ID appear here.</p>
      </div>

      <div
        v-if="selectedAccountId && !crossAccountMode && selectedCount > 0"
        class="d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3 border-bottom bg-body-tertiary"
      >
        <span class="small fw-semibold text-body me-md-1">{{ selectedCount }} selected</span>
        <template v-if="isShippedTab">
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkBusy"
            @click="exportSelectedCsv"
          >
            Export
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkBusy"
            @click="clearRowSelection"
          >
            Clear Selection
          </button>
        </template>
        <template v-else>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkMutationDisabled"
            @click="openPlaceHoldModalForIds([...selectedOrderIds])"
          >
            Place Hold
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkMutationDisabled"
            @click="confirmBulkMarkFulfilledOpen = true"
          >
            Mark As Fulfilled
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkMutationDisabled"
            @click="confirmBulkAllowPartialOpen = true"
          >
            Allow Partial
          </button>
          <button
            type="button"
            class="btn btn-outline-danger btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn orders-toolbar-outline-btn--danger"
            :disabled="bulkMutationDisabled"
            @click="confirmBulkCancelOpen = true"
          >
            Cancel Orders
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkMutationDisabled"
            @click="openBulkRemoveHoldsModal"
          >
            Remove Hold
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkBusy"
            @click="exportSelectedCsv"
          >
            Export
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkBusy"
            @click="clearRowSelection"
          >
            Clear Selection
          </button>
        </template>
      </div>

      <div class="position-relative">
        <div
          v-if="showOrderQueueSyncBanner"
          class="user-inv-sync-banner small text-secondary px-3 py-2 border-bottom bg-body-tertiary"
          role="status"
          aria-live="polite"
        >
          {{ orderQueueSyncBannerText }}
        </div>
      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" style="width: 2.75rem">
                <input
                  ref="selectAllCheckboxRef"
                  type="checkbox"
                  class="form-check-input m-0"
                  :checked="allPageSelected"
                  :disabled="loading || !displayedRows.length || !selectedAccountId || crossAccountMode"
                  :aria-label="allPageSelected ? 'Deselect all on this page' : 'Select all on this page'"
                  @change="toggleSelectAllPage"
                />
              </th>
              <th class="staff-table-head__th">{{ tabKey === "on_hold" ? "Hold Reason" : "Status" }}</th>
              <th class="staff-table-head__th">Order #</th>
              <th class="staff-table-head__th">Name</th>
              <th class="staff-table-head__th">{{ orderDateColumnLabel }}</th>
              <th v-if="showAccountColumn" class="staff-table-head__th">Account</th>
              <th class="staff-table-head__th">Country</th>
              <template v-if="isShippedTab">
                <th class="staff-table-head__th">Carrier</th>
                <th class="staff-table-head__th">Tracking</th>
              </template>
              <th v-else class="staff-table-head__th">Current Shipping Method</th>
              <th class="staff-table-head__th text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading orders..." />
                </div>
              </td>
            </tr>
            <tr v-else-if="isOrdersSearchPage && !hasSearched">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                <template v-if="isAdminOrdersList && !selectedAccountId">
                  Enter an order number and click Search to look up across all accounts, or select an account to
                  filter.
                </template>
                <template v-else>Enter an order number and click Search.</template>
              </td>
            </tr>
            <tr v-else-if="!isOrdersSearchPage && !selectedAccountId">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                Select an account to load orders.
              </td>
            </tr>
            <tr v-else-if="hasSearched && displayedRows.length === 0">
              <td :colspan="tableColspan" class="text-center text-secondary py-5 px-3">
                {{ ordersEmptyMessage }}
              </td>
            </tr>
            <tr v-for="row in displayedRows" :key="row.id" class="align-middle">
              <td class="text-center">
                <input
                  type="checkbox"
                  class="form-check-input m-0"
                  :checked="isRowSelected(row)"
                  :disabled="!row.id || crossAccountMode || !selectedAccountId"
                  :aria-label="`Select order ${row.order_number || row.id}`"
                  @change="toggleRowSelected(row)"
                />
              </td>
              <td>
                <template v-if="tabKey === 'on_hold' && holdReasonLabelsForRow(row).length">
                  <div
                    v-for="(label, holdIdx) in holdReasonLabelsForRow(row)"
                    :key="`${row.id}-hold-${holdIdx}`"
                    class="mb-1 last:mb-0"
                  >
                    <span class="badge rounded-pill fw-medium" :class="statusClass(label)">
                      {{ label }}
                    </span>
                  </div>
                </template>
                <span v-else class="badge rounded-pill fw-medium" :class="statusClassForRow(row)">
                  {{ displayOrderStatus(row) }}
                </span>
              </td>
              <td class="fw-semibold">
                <a
                  v-if="effectiveClientAccountId(row)"
                  :href="orderDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-decoration-none"
                  @click="onOrderNumberClick($event, row)"
                >
                  {{ row.order_number || "—" }}
                </a>
                <span v-else>{{ row.order_number || "—" }}</span>
              </td>
              <td>{{ row.recipient_name || "—" }}</td>
              <td>{{ rowDisplayDate(row) }}</td>
              <td v-if="showAccountColumn">{{ row.client_account_company_name || row.account || "—" }}</td>
              <td>{{ row.country || "—" }}</td>
              <template v-if="isShippedTab">
                <td>
                  <template v-if="rowTrackingLabels(row).length">
                    <div
                      v-for="label in rowTrackingLabels(row)"
                      :key="`c-${label.id || label.tracking_number}`"
                      class="small text-body mb-1 last:mb-0"
                    >
                      {{ formatShipmentCarrier(label) }}
                    </div>
                  </template>
                  <span v-else class="text-secondary">—</span>
                </td>
                <td>
                  <template v-if="rowTrackingLabels(row).length">
                    <template v-for="label in rowTrackingLabels(row)" :key="`t-${label.id || label.tracking_number}`">
                      <div
                        v-for="parts in [formatCarrierTrackingLine(label)]"
                        :key="parts.trackingNumber"
                        class="small text-body mb-1 last:mb-0"
                      >
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
                      </div>
                    </template>
                  </template>
                  <span v-else class="text-secondary">—</span>
                </td>
              </template>
              <td v-else>
                {{
                  formatCurrentShippingMethod(
                    row.shipping_carrier,
                    row.method,
                    row.shipping_method_title,
                  )
                }}
              </td>
              <td class="text-center">
                <div data-row-actions class="staff-actions-inner staff-actions-inner--single justify-content-center">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>

      <div class="staff-table-footer card-footer d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-end gap-3">
        <button
          type="button"
          class="btn btn-outline-secondary order-1 order-lg-2 ms-lg-auto"
          :disabled="loading || !hasNextPage || !selectedAccountId || crossAccountMode"
          @click="fetchOrders(false)"
        >
          {{ hasNextPage ? "Load More" : "No more orders" }}
        </button>
      </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${manageMenuRect.top}px`,
          left: `${manageMenuRect.left}px`,
        }"
        @click.stop
      >
        <button class="staff-row-menu__item" role="menuitem" @click="openOrder(manageMenuRow)">View</button>
        <template v-if="isShippedTab">
          <button class="staff-row-menu__item" role="menuitem" @click="exportOneRow(manageMenuRow)">Export</button>
        </template>
        <template v-else>
          <button
            v-if="canWriteOrders"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openPlaceHoldModalForIds([String(manageMenuRow.id)])"
          >
            Place Hold
          </button>
          <button v-if="canWriteOrders" class="staff-row-menu__item" role="menuitem" @click="runSingleMarkFulfilled(manageMenuRow)">
            Mark As Fulfilled
          </button>
          <button v-if="canWriteOrders" class="staff-row-menu__item" role="menuitem" @click="runSingleAllowPartial(manageMenuRow)">
            Allow Partial
          </button>
          <button v-if="canWriteOrders" class="staff-row-menu__item staff-row-menu__item--danger" role="menuitem" @click="runSingleCancel(manageMenuRow)">
            Cancel Order
          </button>
          <button
            v-if="canWriteOrders && rowHasRemovableHolds(manageMenuRow)"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openSingleRemoveHoldsModal(manageMenuRow)"
          >
            Remove Hold
          </button>
          <button class="staff-row-menu__item" role="menuitem" @click="exportOneRow(manageMenuRow)">Export</button>
        </template>
      </div>
    </Teleport>

    <ConfirmModal
      :open="confirmBulkMarkFulfilledOpen"
      title="Mark Orders Fulfilled?"
      :message="`Mark ${selectedCount} order${selectedCount === 1 ? '' : 's'} as fulfilled in ShipHero?`"
      confirm-label="Mark As Fulfilled"
      cancel-label="Cancel"
      :busy="bulkBusy"
      :danger="false"
      @close="confirmBulkMarkFulfilledOpen = false"
      @confirm="runBulkMarkFulfilled"
    />
    <ConfirmModal
      :open="confirmBulkCancelOpen"
      title="Cancel Orders?"
      :message="`Cancel ${selectedCount} order${selectedCount === 1 ? '' : 's'} in ShipHero? This cannot be undone.`"
      confirm-label="Cancel Orders"
      cancel-label="Close"
      :busy="bulkBusy"
      danger
      @close="confirmBulkCancelOpen = false"
      @confirm="runBulkCancel"
    />
    <ConfirmModal
      :open="confirmBulkAllowPartialOpen"
      title="Allow Partial?"
      :message="`Allow partial fulfillment for ${selectedCount} order${selectedCount === 1 ? '' : 's'}?`"
      confirm-label="Allow Partial"
      cancel-label="Cancel"
      :busy="bulkBusy"
      :danger="false"
      @close="confirmBulkAllowPartialOpen = false"
      @confirm="runBulkAllowPartial"
    />
    <OrdersRemoveHoldsModal
      :open="removeHoldsModalOpen"
      :busy="bulkBusy"
      :variant="removeHoldsModalVariant"
      :active-holds="removeHoldsModalActiveHolds"
      @close="closeRemoveHoldsModal"
      @confirm="onRemoveHoldsModalConfirm"
    />
    <OrdersPlaceHoldModal
      :open="placeHoldModalOpen"
      :busy="placeHoldBusy"
      @close="closePlaceHoldModal"
      @confirm="submitPlaceHoldModal"
    />
    <OrderCreateDrawer
      v-if="showCreateOrderButton"
      v-model:open="createOrderModalOpen"
      :portal-mode="isPortalModeForCreate"
      :portal-account-id="portalClientAccountId"
      :accounts="accounts"
      :accounts-loading="accountsLoading"
      :initial-account-id="String(selectedAccountId || route.query.client_account_id || '')"
      @created="onDraftOrderCreated"
    />
  </div>
</template>

<style scoped>
.orders-list--embedded.staff-page {
  padding-top: 0;
}

.staff-toolbar-filter-dropdown {
  position: absolute;
  top: calc(100% + 0.375rem);
  right: 0;
  left: auto;
  width: min(22rem, calc(100vw - 1.25rem));
  max-width: calc(100vw - 1.25rem);
  min-width: 16rem;
  margin-top: 0 !important;
  z-index: 1200;
}

/* Match billing detail primary actions: solid brand fill + white label (search only). */
.orders-toolbar-search-btn {
  --bs-btn-color: #fff;
  --bs-btn-hover-color: #fff;
  --bs-btn-active-color: #fff;
  --bs-btn-disabled-color: rgba(255, 255, 255, 0.65);
}

/* Search field + Search button share default input-group height (matches Filters outline button). */
.orders-toolbar-search-group .orders-toolbar-search-btn {
  font-weight: 600;
}

/* Keep account, order # search, and Filters on one row (override staff mobile grid). */
.orders-page-toolbar .staff-table-toolbar--row.orders-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

@media (max-width: 767.98px) {
  .orders-page-toolbar .staff-table-toolbar--row.orders-toolbar-row {
    display: flex;
  }
}

.orders-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.orders-search-wrap {
  flex: 0 0 auto;
  width: min(18rem, 100%);
}

</style>

