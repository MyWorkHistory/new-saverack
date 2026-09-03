<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ShopifyOrderCancelConfirmModal from "../../components/shopify/ShopifyOrderCancelConfirmModal.vue";
import ShopifyOrderFulfillModal from "../../components/shopify/ShopifyOrderFulfillModal.vue";
import ShopifyOrderHoldModal from "../../components/shopify/ShopifyOrderHoldModal.vue";
import ShopifyOrderReprocessModal from "../../components/shopify/ShopifyOrderReprocessModal.vue";
import ShopifyOrderReshipModal from "../../components/shopify/ShopifyOrderReshipModal.vue";
import ShopifyOrderStatusPickerModal from "../../components/shopify/ShopifyOrderStatusPickerModal.vue";
import {
  displayStatusClass,
  displayStatusLabel,
  formatShopifyOrderName,
  isCancelledStatus,
  isFulfilledStatus,
  useShopifyOrderActions,
} from "../../composables/useShopifyOrderActions.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const MENU_W = 220;
const MENU_H = 360;
const PER_PAGE = 25;

const router = useRouter();
const toast = useToast();
const loading = ref(false);
const metaLoading = ref(false);
const rows = ref([]);
const meta = reactive({
  countries: [],
  shipping_methods: [],
  statuses: [],
  accounts: [],
});
const q = ref("");
const selectedAccountId = ref("");
const filters = reactive({
  status: "",
  shipping_method: "",
  country: "",
  created_from: "",
  created_to: "",
});
const draftFilters = reactive({
  status: "",
  shipping_method: "",
  country: "",
  created_from: "",
  created_to: "",
  date_preset: "",
});
const filterMenuOpen = ref(false);
const bulkMenuOpen = ref(false);
const selectedIds = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: PER_PAGE });
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const holdModalOpen = ref(false);
const cancelModalOpen = ref(false);
const fulfillModalOpen = ref(false);
const reshipModalOpen = ref(false);
const reprocessModalOpen = ref(false);
const statusPickerOpen = ref(false);
const actionTargetIds = ref([]);
const actionOrder = ref(null);
const actionLineItems = ref([]);

let searchTimer = null;

const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);
const allSelected = computed(
  () => rows.value.length > 0 && rows.value.every((r) => selectedIds.value.includes(r.id)),
);
const selectedCount = computed(() => selectedIds.value.length);
const accountOptions = computed(() =>
  (meta.accounts || []).map((a) => ({
    id: a.id,
    name: a.name || `Account #${a.id}`,
    email: "",
  })),
);
const statusOptions = computed(() => [
  { value: "", label: "Select status" },
  ...(meta.statuses || []).map((s) => ({ value: s.value, label: s.label })),
]);
const shippingOptions = computed(() => [
  { value: "", label: "Select shipping method" },
  ...(meta.shipping_methods || []).map((m) => ({ value: m, label: m })),
]);
const countryOptions = computed(() => [
  { value: "", label: "Select country" },
  ...(meta.countries || []).map((c) => ({ value: c, label: c })),
]);
const holdTargetCount = computed(() => actionTargetIds.value.length || 1);
const cancelTargetCount = computed(() => actionTargetIds.value.length || 1);

function canChangeOrderActions(status) {
  return !isFulfilledStatus(status) && !isCancelledStatus(status);
}

const DATE_PRESETS = [
  { value: "today", label: "Today" },
  { value: "last_7", label: "Last 7 Days" },
  { value: "last_30", label: "Last 30 Days" },
  { value: "custom", label: "Custom" },
];

const hasActiveFilters = computed(
  () =>
    Boolean(filters.status)
    || Boolean(filters.shipping_method)
    || Boolean(filters.country)
    || Boolean(filters.created_from)
    || Boolean(filters.created_to),
);

function isoDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function applyDatePreset(preset) {
  draftFilters.date_preset = preset;
  if (!preset) {
    draftFilters.created_from = "";
    draftFilters.created_to = "";
    return;
  }
  const today = new Date();
  today.setHours(12, 0, 0, 0);
  if (preset === "today") {
    const v = isoDate(today);
    draftFilters.created_from = v;
    draftFilters.created_to = v;
    return;
  }
  if (preset === "last_7") {
    const from = new Date(today);
    from.setDate(from.getDate() - 6);
    draftFilters.created_from = isoDate(from);
    draftFilters.created_to = isoDate(today);
    return;
  }
  if (preset === "last_30") {
    const from = new Date(today);
    from.setDate(from.getDate() - 29);
    draftFilters.created_from = isoDate(from);
    draftFilters.created_to = isoDate(today);
    return;
  }
}

function onCustomDateChange() {
  draftFilters.date_preset = "custom";
}

function stubCreateOrder() {
  toast.warning("Create Order is coming soon.");
}

function mergeUpdatedRow(updated) {
  if (!updated?.id) return;
  const idx = rows.value.findIndex((r) => r.id === updated.id);
  if (idx >= 0) rows.value[idx] = { ...rows.value[idx], ...updated };
}

const actions = useShopifyOrderActions({
  onUpdated: (payload) => {
    if (payload?.id) {
      mergeUpdatedRow(payload);
      return;
    }
    if (Array.isArray(payload?.updated)) {
      payload.updated.forEach(mergeUpdatedRow);
    }
    void load();
  },
});

function filterParams() {
  return {
    q: q.value || undefined,
    client_account_id: selectedAccountId.value || undefined,
    status: filters.status || undefined,
    shipping_method: filters.shipping_method || undefined,
    country: filters.country || undefined,
    created_from: filters.created_from || undefined,
    created_to: filters.created_to || undefined,
    per_page: pagination.value.per_page,
    page: pagination.value.current_page,
  };
}

async function loadMeta() {
  metaLoading.value = true;
  try {
    const { data } = await api.get("/shopify/orders/meta");
    meta.countries = Array.isArray(data?.countries) ? data.countries : [];
    meta.shipping_methods = Array.isArray(data?.shipping_methods) ? data.shipping_methods : [];
    meta.statuses = Array.isArray(data?.statuses) ? data.statuses : [];
    meta.accounts = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load filter options.");
  } finally {
    metaLoading.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/shopify/orders", { params: filterParams() });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.meta?.current_page || 1,
      last_page: data?.meta?.last_page || 1,
      total: data?.meta?.total || 0,
      per_page: data?.meta?.per_page || PER_PAGE,
    };
    selectedIds.value = selectedIds.value.filter((id) => rows.value.some((r) => r.id === id));
  } catch (e) {
    toast.errorFrom(e, "Could not load Shopify orders.");
  } finally {
    loading.value = false;
  }
}

function scheduleSearch() {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    pagination.value.current_page = 1;
    void load();
  }, 350);
}

function openFilters() {
  draftFilters.status = filters.status;
  draftFilters.shipping_method = filters.shipping_method;
  draftFilters.country = filters.country;
  draftFilters.created_from = filters.created_from;
  draftFilters.created_to = filters.created_to;
  draftFilters.date_preset = inferDatePreset(filters.created_from, filters.created_to);
  filterMenuOpen.value = !filterMenuOpen.value;
}

function inferDatePreset(from, to) {
  if (!from && !to) return "";
  const today = isoDate(new Date());
  if (from === today && to === today) return "today";
  const d = new Date();
  d.setHours(12, 0, 0, 0);
  const last7 = new Date(d);
  last7.setDate(last7.getDate() - 6);
  if (from === isoDate(last7) && to === today) return "last_7";
  const last30 = new Date(d);
  last30.setDate(last30.getDate() - 29);
  if (from === isoDate(last30) && to === today) return "last_30";
  return "custom";
}

function applyFilters() {
  filters.status = draftFilters.status;
  filters.shipping_method = draftFilters.shipping_method;
  filters.country = draftFilters.country;
  filters.created_from = draftFilters.created_from;
  filters.created_to = draftFilters.created_to;
  filterMenuOpen.value = false;
  pagination.value.current_page = 1;
  void load();
}

function clearFilters() {
  draftFilters.status = "";
  draftFilters.shipping_method = "";
  draftFilters.country = "";
  draftFilters.created_from = "";
  draftFilters.created_to = "";
  draftFilters.date_preset = "";
}

function resetAll() {
  q.value = "";
  selectedAccountId.value = "";
  filters.status = "";
  filters.shipping_method = "";
  filters.country = "";
  filters.created_from = "";
  filters.created_to = "";
  clearFilters();
  filterMenuOpen.value = false;
  bulkMenuOpen.value = false;
  selectedIds.value = [];
  pagination.value.current_page = 1;
  void load();
}

function toggleSelectAll() {
  if (allSelected.value) {
    selectedIds.value = [];
    return;
  }
  selectedIds.value = rows.value.map((r) => r.id);
}

function toggleSelect(id) {
  if (selectedIds.value.includes(id)) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id);
    return;
  }
  selectedIds.value = [...selectedIds.value, id];
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  pagination.value.current_page = p;
  void load();
}

function openRow(row) {
  if (!row?.id) return;
  manageOpenId.value = null;
  router.push({ name: "shopify-order-detail", params: { id: String(row.id) } });
}

function placeManageMenu(btn) {
  if (!(btn instanceof HTMLElement)) return;
  const r = btn.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

async function toggleManageMenu(row, e) {
  e?.stopPropagation?.();
  const id = row?.id;
  if (manageOpenId.value === id) {
    manageOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  manageOpenId.value = id;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function formatOrderDate(iso) {
  if (!iso) return { date: "—", time: "" };
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return { date: "—", time: "" };
  return {
    date: d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }),
    time: d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" }),
  };
}

async function exportCsv(selectedOnly = false) {
  bulkMenuOpen.value = false;
  try {
    const params = filterParams();
    delete params.page;
    delete params.per_page;
    if (selectedOnly && selectedIds.value.length) {
      params.ids = selectedIds.value.join(",");
    }
    const { data } = await api.get("/shopify/orders/export", { params, responseType: "blob" });
    const url = URL.createObjectURL(data);
    const a = document.createElement("a");
    a.href = url;
    a.download = "shopify-orders.csv";
    a.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    toast.errorFrom(e, "Could not export orders.");
  }
}

async function loadActionOrder(id) {
  const { data } = await api.get(`/shopify/orders/${id}`);
  actionOrder.value = data?.order || null;
  actionLineItems.value = Array.isArray(data?.order?.line_items) ? data.order.line_items : [];
}

function openHoldModal(ids) {
  actionTargetIds.value = [...ids];
  holdModalOpen.value = true;
  manageOpenId.value = null;
  bulkMenuOpen.value = false;
}

function openCancelModal(ids) {
  actionTargetIds.value = [...ids];
  cancelModalOpen.value = true;
  manageOpenId.value = null;
  bulkMenuOpen.value = false;
}

async function openFulfillModal(ids) {
  actionTargetIds.value = [...ids];
  manageOpenId.value = null;
  bulkMenuOpen.value = false;
  if (ids.length === 1) {
    try {
      await loadActionOrder(ids[0]);
    } catch (e) {
      toast.errorFrom(e, "Could not load order items.");
      return;
    }
  } else {
    actionOrder.value = null;
    actionLineItems.value = [];
  }
  fulfillModalOpen.value = true;
}

function openBulkReship() {
  const selected = rows.value.filter((r) => selectedIds.value.includes(r.id));
  const fulfilled = selected.filter((r) => isFulfilledStatus(r.display_status));
  if (fulfilled.length !== 1) {
    toast.error("Select one fulfilled order to re-ship.");
    bulkMenuOpen.value = false;
    return;
  }
  void openReshipModal(fulfilled[0]);
}

async function openReshipModal(row) {
  if (!isFulfilledStatus(row?.display_status)) {
    toast.error("Re-Ship is only available for fulfilled orders.");
    return;
  }
  manageOpenId.value = null;
  bulkMenuOpen.value = false;
  actionTargetIds.value = [row.id];
  try {
    await loadActionOrder(row.id);
  } catch (e) {
    toast.errorFrom(e, "Could not load order items.");
    return;
  }
  reshipModalOpen.value = true;
}

function openReprocessModal(ids) {
  const targets = rows.value.filter((r) => ids.includes(r.id));
  const eligible = targets.filter((r) => canChangeOrderActions(r.display_status));
  if (eligible.length !== targets.length) {
    toast.error("Cannot change shipped order status.");
  }
  if (!eligible.length) {
    manageOpenId.value = null;
    bulkMenuOpen.value = false;
    return;
  }
  actionTargetIds.value = eligible.map((r) => r.id);
  actionOrder.value = eligible[0] || null;
  reprocessModalOpen.value = true;
  manageOpenId.value = null;
  bulkMenuOpen.value = false;
}

function openStatusPicker(row) {
  actionTargetIds.value = [row.id];
  actionOrder.value = row;
  statusPickerOpen.value = true;
}

async function confirmHold(reasons) {
  const ids = actionTargetIds.value.length ? actionTargetIds.value : [];
  if (!ids.length) return;
  const result = await actions.holdOrder(ids, reasons);
  if (result) {
    holdModalOpen.value = false;
    actionTargetIds.value = [];
    if (ids.length > 1) selectedIds.value = [];
    else void load();
  }
}

async function confirmCancel({ cancelInShopify } = {}) {
  const ids = actionTargetIds.value.length ? actionTargetIds.value : [];
  if (!ids.length) return;
  const result = await actions.cancelOrder(ids, Boolean(cancelInShopify));
  if (result) {
    cancelModalOpen.value = false;
    actionTargetIds.value = [];
    selectedIds.value = selectedIds.value.filter((id) => !ids.includes(id));
    void load();
  }
}

async function confirmFulfill({ trackingNumber, deductLineIds } = {}) {
  const ids = actionTargetIds.value;
  if (!ids.length) return;
  const result = await actions.fulfillOrder(ids, {
    trackingNumber,
    deductLineIds: ids.length === 1 ? deductLineIds : null,
  });
  if (result) {
    fulfillModalOpen.value = false;
    actionTargetIds.value = [];
    void load();
  }
}

async function confirmReship(lineItemIds) {
  const id = actionTargetIds.value[0];
  if (!id) return;
  const result = await actions.reshipOrder(id, lineItemIds);
  if (result) {
    reshipModalOpen.value = false;
    void load();
  }
}

async function confirmReprocess() {
  const ids = actionTargetIds.value;
  if (!ids.length) return;
  const result = await actions.reprocessOrder(ids);
  if (result) {
    reprocessModalOpen.value = false;
    void load();
  }
}

async function onStatusPicked(status) {
  const ids = actionTargetIds.value;
  if (!ids.length) return;
  if (status === "on_hold") {
    statusPickerOpen.value = false;
    openHoldModal(ids);
    return;
  }
  if (status === "fulfilled") {
    statusPickerOpen.value = false;
    await openFulfillModal(ids);
    return;
  }
  const result = await actions.applyDisplayStatus(ids, status);
  if (result) {
    statusPickerOpen.value = false;
    void load();
  }
}

function runRowAction(fn, row) {
  manageOpenId.value = null;
  fn(row);
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-shopify-orders-filter]")) filterMenuOpen.value = false;
  if (!e.target?.closest?.("[data-shopify-orders-row-actions]")) manageOpenId.value = null;
  if (!e.target?.closest?.("[data-shopify-orders-bulk-actions]")) bulkMenuOpen.value = false;
}

watch(q, () => scheduleSearch());
watch(selectedAccountId, () => {
  pagination.value.current_page = 1;
  void load();
});

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Orders",
    description: "Search, filter, and manage Shopify orders.",
  });
  document.addEventListener("click", onDocClick);
  void loadMeta();
  void load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  if (searchTimer) clearTimeout(searchTimer);
});
</script>

<template>
  <div class="staff-page staff-page--wide shopify-orders-page">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h3 mb-2 fw-bold text-body shopify-orders-page__title">Orders</h1>
        <p class="text-secondary mb-0 shopify-orders-page__subtitle">
          Search, filter, and manage all your orders in one place.
        </p>
      </div>
      <button
        type="button"
        class="btn btn-primary staff-page-primary fw-semibold d-inline-flex align-items-center gap-2 flex-shrink-0"
        @click="stubCreateOrder"
      >
        <span aria-hidden="true">+</span>
        Create Order
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 orders-page-toolbar">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row orders-toolbar-row shopify-orders-toolbar-row">
          <div class="orders-search-wrap shopify-orders-search-wrap">
            <div class="shopify-orders-search-field">
              <svg
                class="shopify-orders-search-field__icon"
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
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                />
              </svg>
              <input
                v-model="q"
                type="search"
                class="form-control shopify-orders-search-field__input"
                placeholder="Search by Order # or Recipient"
                :disabled="loading"
                autocomplete="off"
                aria-label="Search by order number or recipient"
              >
            </div>
          </div>

          <div class="orders-toolbar-account flex-shrink-0">
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="All Accounts"
              :options="accountOptions"
              :disabled="metaLoading || loading"
              placeholder="All Accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="All Accounts"
              button-id="shopify-orders-account-trigger"
            />
          </div>

          <div
            class="position-relative flex-shrink-0"
            data-shopify-orders-filter
          >
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              @click.stop="openFilters"
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
              class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown shopify-orders-filter-dropdown"
              role="dialog"
              aria-label="Order filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="shopify-orders-filter-date-preset">Order Date</label>
                <select
                  id="shopify-orders-filter-date-preset"
                  v-model="draftFilters.date_preset"
                  class="form-select staff-datatable-filters__select mb-2"
                  @change="applyDatePreset(draftFilters.date_preset)"
                >
                  <option value="">Select date range</option>
                  <option v-for="p in DATE_PRESETS" :key="p.value" :value="p.value">
                    {{ p.label }}
                  </option>
                </select>
                <div class="shopify-orders-date-range mb-3">
                  <div class="shopify-orders-date-range__pickers">
                    <input
                      v-model="draftFilters.created_from"
                      type="date"
                      class="form-control form-control-sm"
                      aria-label="Order date from"
                      :disabled="draftFilters.date_preset !== 'custom'"
                      @change="onCustomDateChange"
                    >
                    <span class="shopify-orders-date-range__sep">to</span>
                    <input
                      v-model="draftFilters.created_to"
                      type="date"
                      class="form-control form-control-sm"
                      aria-label="Order date to"
                      :disabled="draftFilters.date_preset !== 'custom'"
                      @change="onCustomDateChange"
                    >
                  </div>
                </div>

                <label class="form-label" for="shopify-orders-filter-status">Order Status</label>
                <select
                  id="shopify-orders-filter-status"
                  v-model="draftFilters.status"
                  class="form-select staff-datatable-filters__select mb-3"
                >
                  <option
                    v-for="opt in statusOptions"
                    :key="opt.value || 'all'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>

                <label class="form-label" for="shopify-orders-filter-shipping">Shipping Method</label>
                <select
                  id="shopify-orders-filter-shipping"
                  v-model="draftFilters.shipping_method"
                  class="form-select staff-datatable-filters__select mb-3"
                >
                  <option
                    v-for="opt in shippingOptions"
                    :key="opt.value || 'all-ship'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>

                <label class="form-label" for="shopify-orders-filter-country">Country</label>
                <select
                  id="shopify-orders-filter-country"
                  v-model="draftFilters.country"
                  class="form-select staff-datatable-filters__select mb-0"
                >
                  <option
                    v-for="opt in countryOptions"
                    :key="opt.value || 'all-country'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
              </div>
              <div class="shopify-orders-filter-dropdown__footer">
                <button
                  type="button"
                  class="btn btn-link btn-sm staff-bulk-clear-link text-decoration-none px-0"
                  @click="clearFilters"
                >
                  Clear Filters
                </button>
                <button
                  type="button"
                  class="btn btn-primary staff-page-primary btn-sm fw-semibold"
                  @click="applyFilters"
                >
                  Apply Filters
                </button>
              </div>
            </div>
          </div>

          <button
            type="button"
            class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2 ms-auto flex-shrink-0"
            :disabled="loading"
            @click="resetAll"
          >
            <svg
              width="16"
              height="16"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
              />
            </svg>
            Reset
          </button>
        </div>
      </div>

      <div
        v-if="selectedCount"
        class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
      >
        <input
          type="checkbox"
          class="form-check-input m-0"
          :checked="allSelected"
          aria-label="Select all orders on page"
          @change="toggleSelectAll"
        >
        <span class="small staff-bulk-selection-bar__count">
          {{ selectedCount }} order{{ selectedCount === 1 ? "" : "s" }} selected
        </span>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn orders-bulk-toolbar-btn d-inline-flex align-items-center gap-2"
          @click="exportCsv(true)"
        >
          <svg
            width="14"
            height="14"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
            />
          </svg>
          Export
        </button>
        <div
          class="position-relative staff-toolbar-bulk-dropdown"
          data-shopify-orders-bulk-actions
        >
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn orders-bulk-toolbar-btn d-inline-flex align-items-center gap-2"
            @click.stop="bulkMenuOpen = !bulkMenuOpen"
          >
            Bulk Actions
            <svg
              width="14"
              height="14"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M19 9l-7 7-7-7"
              />
            </svg>
          </button>
          <div
            v-if="bulkMenuOpen"
            class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown shopify-orders-bulk-menu"
            role="menu"
            @click.stop
          >
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openHoldModal(selectedIds)"
            >
              Hold Order
            </button>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openCancelModal(selectedIds)"
            >
              Cancel Order
            </button>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openFulfillModal(selectedIds)"
            >
              Mark Fulfilled
            </button>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openBulkReship"
            >
              Re-Ship Order
            </button>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openReprocessModal(selectedIds)"
            >
              Reprocess Order
            </button>
          </div>
        </div>
      </div>

      <div
        v-else-if="hasActiveFilters || q"
        class="shopify-orders-toolbar-export-row px-3 px-md-4 py-2 border-bottom d-flex justify-content-end"
      >
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn orders-bulk-toolbar-btn d-inline-flex align-items-center gap-2"
          :disabled="loading"
          @click="exportCsv(false)"
        >
          <svg
            width="14"
            height="14"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
            />
          </svg>
          Export All
        </button>
      </div>

      <div
        v-if="loading && !rows.length"
        class="p-5 d-flex justify-content-center"
      >
        <CrmLoadingSpinner message="Loading orders…" />
      </div>

      <div
        v-else
        class="table-responsive staff-table-wrap"
      >
        <table class="table table-hover align-middle mb-0 staff-data-table shopify-orders-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                class="staff-table-head__th staff-table-head__th--select"
                scope="col"
              >
                <input
                  class="form-check-input staff-table-head__check m-0"
                  type="checkbox"
                  :checked="allSelected"
                  :disabled="!rows.length"
                  aria-label="Select all orders"
                  @change="toggleSelectAll"
                >
              </th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Status</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Order #</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Recipient</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Order Date</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Country</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Shipping Method</th>
              <th
                class="staff-table-head__th text-center shopify-orders-actions-col"
                scope="col"
              >Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td
                colspan="8"
                class="px-4 py-5 text-center text-secondary"
              >
                No orders found.
              </td>
            </tr>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="align-middle shopify-orders-row"
            >
              <td class="staff-table-cell--tight-check">
                <input
                  class="form-check-input staff-table-head__check m-0"
                  type="checkbox"
                  :checked="selectedIds.includes(row.id)"
                  :aria-label="`Select order ${row.name}`"
                  @change="toggleSelect(row.id)"
                >
              </td>
              <td>
                <button
                  type="button"
                  class="badge rounded-pill fw-medium shopify-order-status shopify-order-status--clickable border-0"
                  :class="displayStatusClass(row.display_status)"
                  @click="openStatusPicker(row)"
                >
                  {{ displayStatusLabel(row.display_status) }}
                </button>
              </td>
              <td>
                <button
                  type="button"
                  class="btn btn-link p-0 shopify-orders-order-link fw-semibold text-decoration-none"
                  @click="openRow(row)"
                >
                  {{ formatShopifyOrderName(row.name) || "—" }}
                </button>
              </td>
              <td class="shopify-orders-recipient">{{ row.recipient_name || "—" }}</td>
              <td class="text-nowrap shopify-orders-date-cell">
                <div class="shopify-orders-date-cell__date">{{ formatOrderDate(row.shopify_created_at).date }}</div>
                <div class="shopify-orders-date-cell__time">{{ formatOrderDate(row.shopify_created_at).time }}</div>
              </td>
              <td>{{ row.country || "—" }}</td>
              <td class="shopify-orders-shipping">{{ row.shipping_method || "—" }}</td>
              <td
                class="text-center staff-actions-cell shopify-orders-actions-cell"
                data-shopify-orders-row-actions
              >
                <div class="staff-actions-inner staff-actions-inner--single shopify-orders-actions-inner justify-content-center">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="pagination.total > pagination.per_page"
        class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 px-md-4 py-3 border-top"
      >
        <span class="small text-secondary">
          Page {{ pagination.current_page }} of {{ pagination.last_page }} ({{ pagination.total }} orders)
        </span>
        <div class="btn-group btn-group-sm">
          <button
            type="button"
            class="btn btn-outline-secondary orders-toolbar-outline-btn"
            :disabled="pagination.current_page <= 1 || loading"
            @click="goPage(pagination.current_page - 1)"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary orders-toolbar-outline-btn"
            :disabled="pagination.current_page >= pagination.last_page || loading"
            @click="goPage(pagination.current_page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageOpenId && manageMenuRow"
        class="staff-row-menu"
        data-shopify-orders-row-actions
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px`, minWidth: `${MENU_W}px` }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="runRowAction(actions.viewInShopify, manageMenuRow)"
        >
          View in Shopify
        </button>
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="runRowAction((r) => actions.syncOrder(r), manageMenuRow)"
        >
          Sync From Shopify
        </button>
        <button
          v-if="canChangeOrderActions(manageMenuRow.display_status)"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openHoldModal([manageMenuRow.id])"
        >
          Hold Order
        </button>
        <button
          v-if="canChangeOrderActions(manageMenuRow.display_status)"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openCancelModal([manageMenuRow.id])"
        >
          Cancel Order
        </button>
        <button
          v-if="canChangeOrderActions(manageMenuRow.display_status)"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openFulfillModal([manageMenuRow.id])"
        >
          Mark Fulfilled
        </button>
        <button
          v-if="isFulfilledStatus(manageMenuRow.display_status)"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openReshipModal(manageMenuRow)"
        >
          Re-Ship Order
        </button>
        <button
          v-if="canChangeOrderActions(manageMenuRow.display_status)"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openReprocessModal([manageMenuRow.id])"
        >
          Reprocess Order
        </button>
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="runRowAction(actions.viewPackingSlip, manageMenuRow)"
        >
          View Packing Slip
        </button>
      </div>
    </Teleport>

    <ShopifyOrderHoldModal
      :open="holdModalOpen"
      :busy="actions.busy.value"
      :order-count="holdTargetCount"
      @close="holdModalOpen = false"
      @confirm="confirmHold"
    />
    <ShopifyOrderCancelConfirmModal
      :open="cancelModalOpen"
      :busy="actions.busy.value"
      :order-count="cancelTargetCount"
      @close="cancelModalOpen = false"
      @confirm="confirmCancel"
    />
    <ShopifyOrderFulfillModal
      :open="fulfillModalOpen"
      :busy="actions.busy.value"
      :order="actionOrder"
      :line-items="actionLineItems"
      @close="fulfillModalOpen = false"
      @confirm="confirmFulfill"
    />
    <ShopifyOrderReshipModal
      :open="reshipModalOpen"
      :busy="actions.busy.value"
      :order="actionOrder"
      :line-items="actionLineItems"
      @close="reshipModalOpen = false"
      @confirm="confirmReship"
    />
    <ShopifyOrderReprocessModal
      :open="reprocessModalOpen"
      :busy="actions.busy.value"
      :order="actionOrder"
      :order-count="actionTargetIds.length || 1"
      @close="reprocessModalOpen = false"
      @confirm="confirmReprocess"
    />
    <ShopifyOrderStatusPickerModal
      :open="statusPickerOpen"
      :busy="actions.busy.value"
      :order="actionOrder"
      @close="statusPickerOpen = false"
      @pick="onStatusPicked"
    />
  </div>
</template>

<style scoped>
.orders-page-toolbar .staff-table-toolbar--row.shopify-orders-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.625rem;
}

.shopify-orders-page__title {
  letter-spacing: -0.02em;
}

.shopify-orders-page__subtitle {
  font-size: 0.9375rem;
}

.shopify-orders-search-wrap {
  flex: 1 1 26rem;
  min-width: min(26rem, 100%);
  max-width: 40rem;
}

.shopify-orders-search-field {
  position: relative;
  width: 100%;
}

.shopify-orders-search-field__icon {
  position: absolute;
  left: 0.875rem;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  pointer-events: none;
}

.shopify-orders-search-field__input {
  min-height: 2.5rem;
  padding-left: 2.5rem;
  border-color: #dbeafe;
  border-radius: 0.5rem;
  box-shadow: none;
}

.shopify-orders-search-field__input:focus {
  border-color: #93c5fd;
  box-shadow: 0 0 0 0.15rem rgba(37, 99, 235, 0.12);
}

.orders-toolbar-account {
  flex: 0 0 auto;
  width: min(11.5rem, 100%);
}

.shopify-orders-filter-dropdown {
  position: absolute;
  top: calc(100% + 0.35rem);
  left: 50%;
  transform: translateX(-50%);
  min-width: 20rem;
}

.shopify-orders-filter-dropdown__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 1rem 1rem;
  border-top: 1px solid var(--vx-nav-border, #ececee);
}

.shopify-orders-date-range {
  position: relative;
}

.shopify-orders-date-range__display {
  padding-right: 2.5rem;
  background: #fff;
  cursor: default;
}

.shopify-orders-date-range__icon {
  position: absolute;
  right: 0.75rem;
  top: 0.65rem;
  color: #94a3b8;
  pointer-events: none;
}

.shopify-orders-date-range__pickers {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.shopify-orders-date-range__sep {
  color: #64748b;
  font-size: 0.8125rem;
}

.staff-toolbar-bulk-dropdown .shopify-orders-bulk-menu {
  position: absolute;
  top: calc(100% + 0.25rem);
  left: 0;
  min-width: 12rem;
  z-index: 20;
  background: #fff !important;
  padding: 0.35rem 0 !important;
}

.shopify-orders-order-link {
  color: #2563eb !important;
}

.shopify-orders-recipient {
  color: #1f2937;
  font-weight: 400;
}

.shopify-orders-date-cell__date {
  color: #1f2937;
  font-size: 0.9375rem;
}

.shopify-orders-date-cell__time {
  color: #64748b;
  font-size: 0.8125rem;
  line-height: 1.2;
}

.shopify-orders-shipping {
  color: #334155;
  font-size: 0.9375rem;
}

/* Mock: ACTION column kebab centered (override global end-alignment). */
.shopify-orders-table :deep(th.shopify-orders-actions-col),
.shopify-orders-table :deep(td.shopify-orders-actions-cell) {
  text-align: center !important;
}

.shopify-orders-actions-inner {
  justify-content: center !important;
  width: 100%;
}

.shopify-order-status {
  font-size: 0.75rem;
  padding: 0.35rem 0.65rem;
}

.shopify-order-status--ready {
  background: #dcfce7;
  color: #166534;
}

.shopify-order-status--hold {
  background: #ffedd5;
  color: #c2410c;
}

.shopify-order-status--backorder {
  background: #ede9fe;
  color: #6d28d9;
}

.shopify-order-status--shipped {
  background: #dbeafe;
  color: #1d4ed8;
}

.shopify-order-status--cancelled {
  background: #fee2e2;
  color: #b91c1c;
}

.shopify-order-status--clickable {
  cursor: pointer;
}

@media (max-width: 991.98px) {
  .shopify-orders-filter-dropdown {
    left: auto;
    right: 0;
    transform: none;
  }
}
</style>
