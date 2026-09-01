<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ShopifyOrderCancelConfirmModal from "../../components/shopify/ShopifyOrderCancelConfirmModal.vue";
import ShopifyOrderHoldModal from "../../components/shopify/ShopifyOrderHoldModal.vue";
import {
  displayStatusClass,
  displayStatusLabel,
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
});
const filterMenuOpen = ref(false);
const bulkMenuOpen = ref(false);
const selectedIds = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: PER_PAGE });
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const holdModalOpen = ref(false);
const cancelModalOpen = ref(false);
const actionTargetIds = ref([]);

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
  filterMenuOpen.value = !filterMenuOpen.value;
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

async function confirmCancel() {
  const ids = actionTargetIds.value.length ? actionTargetIds.value : [];
  if (!ids.length) return;
  const result = await actions.cancelOrder(ids);
  if (result) {
    cancelModalOpen.value = false;
    actionTargetIds.value = [];
    selectedIds.value = selectedIds.value.filter((id) => !ids.includes(id));
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
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Orders</h1>
        <p class="small text-secondary mb-0">
          Search, filter, and manage all your orders in one place.
        </p>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row orders-toolbar-row">
          <div class="orders-search-wrap flex-grow-1">
            <div class="input-group orders-toolbar-search-group">
              <span class="input-group-text bg-white border-end-0 text-secondary">
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
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                  />
                </svg>
              </span>
              <input
                v-model="q"
                type="search"
                class="form-control border-start-0"
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
              aria-label="All accounts"
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
              class="orders-filter-popover shadow-sm"
              @click.stop
            >
              <div class="mb-3">
                <label class="form-label">Order Date</label>
                <div class="d-flex gap-2">
                  <input
                    v-model="draftFilters.created_from"
                    type="date"
                    class="form-control form-control-sm"
                    aria-label="Order date from"
                  >
                  <input
                    v-model="draftFilters.created_to"
                    type="date"
                    class="form-control form-control-sm"
                    aria-label="Order date to"
                  >
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Order Status</label>
                <select
                  v-model="draftFilters.status"
                  class="form-select form-select-sm"
                >
                  <option
                    v-for="opt in statusOptions"
                    :key="opt.value || 'all'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Shipping Method</label>
                <select
                  v-model="draftFilters.shipping_method"
                  class="form-select form-select-sm"
                >
                  <option
                    v-for="opt in shippingOptions"
                    :key="opt.value || 'all-ship'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Country</label>
                <select
                  v-model="draftFilters.country"
                  class="form-select form-select-sm"
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
              <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary px-0"
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
            class="btn btn-link btn-sm text-secondary d-inline-flex align-items-center gap-1 ms-auto"
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

          <button
            v-if="!selectedCount"
            type="button"
            class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2 flex-shrink-0"
            :disabled="loading"
            @click="exportCsv(false)"
          >
            Export All
          </button>
        </div>
      </div>

      <div
        v-if="selectedCount"
        class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
      >
        <label class="form-check mb-0 d-flex align-items-center gap-2">
          <input
            class="form-check-input"
            type="checkbox"
            :checked="allSelected"
            @change="toggleSelectAll"
          >
          <span class="small staff-bulk-selection-bar__count">{{ selectedCount }} orders selected</span>
        </label>
        <button
          type="button"
          class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn btn-sm d-inline-flex align-items-center gap-2"
          @click="exportCsv(true)"
        >
          Export
        </button>
        <div
          class="position-relative"
          data-shopify-orders-bulk-actions
        >
          <button
            type="button"
            class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn btn-sm d-inline-flex align-items-center gap-2"
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
            class="dropdown-menu show shadow-sm"
            style="position: absolute; top: 100%; left: 0; min-width: 12rem; z-index: 20"
            @click.stop
          >
            <button
              type="button"
              class="dropdown-item"
              @click="openHoldModal(selectedIds)"
            >
              Hold Order
            </button>
            <button
              type="button"
              class="dropdown-item"
              @click="openCancelModal(selectedIds)"
            >
              Cancel Order
            </button>
            <button
              type="button"
              class="dropdown-item"
              @click="actions.fulfillOrder(selectedIds); bulkMenuOpen = false"
            >
              Mark Fulfilled
            </button>
            <button
              type="button"
              class="dropdown-item"
              @click="actions.stubAction('Re-Ship Order'); bulkMenuOpen = false"
            >
              Re-Ship Order
            </button>
            <button
              type="button"
              class="dropdown-item"
              @click="actions.stubAction('Reprocess Order'); bulkMenuOpen = false"
            >
              Reprocess Order
            </button>
          </div>
        </div>
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
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                class="staff-table-head__th"
                style="width: 2.5rem"
                scope="col"
              >
                <input
                  class="form-check-input"
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
                class="staff-table-head__th text-end"
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
              class="align-middle"
            >
              <td>
                <input
                  class="form-check-input"
                  type="checkbox"
                  :checked="selectedIds.includes(row.id)"
                  :aria-label="`Select order ${row.name}`"
                  @change="toggleSelect(row.id)"
                >
              </td>
              <td>
                <span
                  class="badge rounded-pill fw-medium shopify-order-status"
                  :class="displayStatusClass(row.display_status)"
                >
                  {{ displayStatusLabel(row.display_status) }}
                </span>
              </td>
              <td>
                <button
                  type="button"
                  class="btn btn-link p-0 text-primary fw-semibold text-decoration-none"
                  @click="openRow(row)"
                >
                  {{ row.name || "—" }}
                </button>
              </td>
              <td class="text-body fw-normal">{{ row.recipient_name || "—" }}</td>
              <td class="text-nowrap">
                <div>{{ formatOrderDate(row.shopify_created_at).date }}</div>
                <div class="small text-secondary">{{ formatOrderDate(row.shopify_created_at).time }}</div>
              </td>
              <td>{{ row.country || "—" }}</td>
              <td>{{ row.shipping_method || "—" }}</td>
              <td
                class="text-end"
                data-shopify-orders-row-actions
              >
                <CrmIconRowActions
                  :active="manageOpenId === row.id"
                  @toggle="toggleManageMenu(row, $event)"
                />
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
            class="btn btn-outline-secondary"
            :disabled="pagination.current_page <= 1 || loading"
            @click="goPage(pagination.current_page - 1)"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
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
        class="dropdown-menu show shadow-sm"
        data-shopify-orders-row-actions
        :style="{ position: 'fixed', top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px`, minWidth: `${MENU_W}px`, zIndex: 2000 }"
        @click.stop
      >
        <button
          type="button"
          class="dropdown-item"
          @click="runRowAction(actions.viewInShopify, manageMenuRow)"
        >
          View in Shopify
        </button>
        <button
          type="button"
          class="dropdown-item"
          @click="runRowAction((r) => actions.syncOrder(r), manageMenuRow)"
        >
          Sync From Shopify
        </button>
        <button
          type="button"
          class="dropdown-item"
          @click="openHoldModal([manageMenuRow.id])"
        >
          Hold Order
        </button>
        <button
          type="button"
          class="dropdown-item"
          @click="openCancelModal([manageMenuRow.id])"
        >
          Cancel Order
        </button>
        <button
          type="button"
          class="dropdown-item"
          @click="runRowAction((r) => actions.fulfillOrder([r.id]), manageMenuRow)"
        >
          Mark Fulfilled
        </button>
        <button
          type="button"
          class="dropdown-item"
          @click="runRowAction(() => actions.stubAction('Re-Ship Order'), manageMenuRow)"
        >
          Re-Ship Order
        </button>
        <button
          type="button"
          class="dropdown-item"
          @click="runRowAction(() => actions.stubAction('Reprocess Order'), manageMenuRow)"
        >
          Reprocess Order
        </button>
        <button
          type="button"
          class="dropdown-item"
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
  </div>
</template>

<style scoped>
.orders-filter-popover {
  position: absolute;
  top: calc(100% + 0.35rem);
  right: 0;
  z-index: 30;
  width: min(22rem, 92vw);
  padding: 1rem;
  background: #fff;
  border: 1px solid var(--bs-border-color);
  border-radius: 0.5rem;
}

.shopify-order-status {
  font-size: 0.75rem;
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
</style>
