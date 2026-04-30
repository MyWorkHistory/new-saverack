<script setup>
import { computed, inject, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const route = useRoute();
const router = useRouter();
inject("crmUser", ref(null));

const rows = ref([]);
const loading = ref(false);
const accountsLoading = ref(false);
const accounts = ref([]);
const selectedAccountId = ref("");
const hasSearched = ref(false);
const nextCursor = ref(null);
const hasNextPage = ref(false);
const readySummaryLoading = ref(false);
const readySummary = ref({
  ready_to_ship_total: 0,
  ready_to_ship_by_account: [],
  late_orders_total: 0,
  priority_orders_total: 0,
});
const READY_SUMMARY_CACHE_KEY = "orders.manage.readySummary.v1";

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const filterMenuOpen = ref(false);

const query = reactive({
  datePreset: "today",
  from: "",
  to: "",
  fulfillmentStatus: "",
  readyToShip: "",
});

const tabKey = computed(() => String(route.meta?.orderTab || "manage"));
const tabTitle = computed(() => {
  if (tabKey.value === "awaiting") return "Ready to Ship";
  if (tabKey.value === "on_hold") return "On-Hold";
  if (tabKey.value === "shipped") return "Shipped";
  return "Manage";
});

const showManageFilters = computed(() => true);
const isCustomDate = computed(() => query.datePreset === "custom");

const displayedRows = computed(() => {
  return rows.value;
});

const manageMenuRow = computed(
  () => rows.value.find((row) => row.id === manageOpenId.value) ?? null,
);

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => a?.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

function orderDetailHref(row) {
  if (!row?.id || !selectedAccountId.value) return "#";
  return router.resolve({
    name: "order-detail",
    params: { shipheroOrderId: String(row.id) },
    query: { client_account_id: String(selectedAccountId.value) },
  }).href;
}

function openOrderViewNewTab(row) {
  if (!row?.id || !selectedAccountId.value) {
    toast.error("Select an account first.");
    return;
  }
  const key = `orders.snapshot.${selectedAccountId.value}.${String(row.id)}`;
  try {
    sessionStorage.setItem(key, JSON.stringify(row));
  } catch (_) {
    // Best-effort cache for detail fallback.
  }
  const url = orderDetailHref(row);
  window.open(url, "_blank", "noopener,noreferrer");
  manageOpenId.value = null;
}

function statusClass(status) {
  const s = String(status || "").toLowerCase();
  if (s.includes("hold")) return "bg-danger-subtle text-danger-emphasis";
  if (s.includes("ship")) return "bg-success-subtle text-success-emphasis";
  if (s.includes("ready")) return "bg-primary-subtle text-primary-emphasis";
  return "bg-secondary-subtle text-secondary-emphasis";
}

function formatDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString();
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

function buildParams(withCursor = false) {
  const params = {
    client_account_id: Number(selectedAccountId.value),
    tab: tabKey.value,
    first: 100,
  };
  const range = dateRangeFromPreset();
  if (range.from) params.order_date_from = range.from;
  if (range.to) params.order_date_to = range.to;
  if (query.fulfillmentStatus) params.fulfillment_status = query.fulfillmentStatus;
  if (query.readyToShip !== "") params.ready_to_ship = query.readyToShip === "yes";
  if (withCursor && nextCursor.value) params.after = nextCursor.value;
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

async function fetchOrders(reset = true) {
  if (!selectedAccountId.value) {
    if (reset) {
      rows.value = [];
      hasSearched.value = false;
      hasNextPage.value = false;
      nextCursor.value = null;
    }
    return;
  }
  loading.value = true;
  if (reset) {
    rows.value = [];
    nextCursor.value = null;
    hasNextPage.value = false;
  }
  try {
    const { data } = await api.get("/orders", {
      params: buildParams(!reset),
    });
    const incoming = Array.isArray(data?.rows) ? data.rows : [];
    rows.value = reset ? incoming : [...rows.value, ...incoming];
    hasNextPage.value = Boolean(data?.pagination?.has_next_page);
    nextCursor.value = data?.pagination?.end_cursor || null;
    hasSearched.value = true;
  } catch (e) {
    toast.errorFrom(e, "Could not load orders.");
  } finally {
    loading.value = false;
  }
}

async function fetchReadySummary() {
  if (!showManageFilters.value || tabKey.value !== "manage") return;
  readySummaryLoading.value = true;
  try {
    const range = dateRangeFromPreset();
    const { data } = await api.get("/orders/summary", {
      params: {
        order_date_from: range.from || undefined,
        order_date_to: range.to || undefined,
      },
    });
    readySummary.value = {
      ready_to_ship_total: Number(data?.ready_to_ship_total || 0),
      ready_to_ship_by_account: Array.isArray(data?.ready_to_ship_by_account) ? data.ready_to_ship_by_account : [],
      late_orders_total: Number(data?.late_orders_total || 0),
      priority_orders_total: Number(data?.priority_orders_total || 0),
    };
    try {
      sessionStorage.setItem(READY_SUMMARY_CACHE_KEY, JSON.stringify(readySummary.value));
    } catch (_) {
      // no-op
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load Ready to Ship summary.");
  } finally {
    readySummaryLoading.value = false;
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
  const width = 180;
  const height = 54;
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

watch(
  () => [selectedAccountId.value, tabKey.value],
  () => {
    fetchOrders(true);
    if (tabKey.value === "manage") fetchReadySummary();
  },
);

watch(
  () => [query.datePreset, query.from, query.to, query.fulfillmentStatus, query.readyToShip],
  () => {
    if (!showManageFilters.value) return;
    if (
      query.fulfillmentStatus !== ""
      || query.readyToShip !== ""
      || query.datePreset !== "custom"
    ) {
      fetchOrders(true);
      if (tabKey.value === "manage") fetchReadySummary();
    }
  },
);

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  setCrmPageMeta({
    title: `Save Rack | Orders | ${tabTitle.value}`,
    description: "ShipHero customer orders.",
  });
  try {
    const cached = sessionStorage.getItem(READY_SUMMARY_CACHE_KEY);
    if (cached) {
      const parsed = JSON.parse(cached);
      readySummary.value = {
        ready_to_ship_total: Number(parsed?.ready_to_ship_total || 0),
        ready_to_ship_by_account: Array.isArray(parsed?.ready_to_ship_by_account) ? parsed.ready_to_ship_by_account : [],
        late_orders_total: Number(parsed?.late_orders_total || 0),
        priority_orders_total: Number(parsed?.priority_orders_total || 0),
      };
    }
  } catch (_) {
    // no-op
  }
  await loadAccounts();
  if (tabKey.value === "manage") fetchReadySummary();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex align-items-start justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Orders - {{ tabTitle }}</h1>
        <p class="staff-page__intro mb-0">ShipHero orders for selected client account.</p>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <div class="flex-grow-1" style="min-width: 280px">
            <label class="form-label small text-secondary mb-1" for="orders-list-account-trigger">Account</label>
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="Select account to load orders"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="Select account to load orders"
              button-id="orders-list-account-trigger"
            />
          </div>

          <template v-if="showManageFilters">
            <div class="position-relative flex-shrink-0" data-toolbar-filter>
              <button
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
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
                  <button
                    type="button"
                    class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                    @click="
                      query.datePreset = 'today';
                      query.from = '';
                      query.to = '';
                      query.fulfillmentStatus = '';
                      query.readyToShip = '';
                      filterMenuOpen = false;
                    "
                  >
                    Reset
                  </button>
                </div>
                <div class="staff-toolbar-filter-dropdown__body">
                  <label class="form-label" for="orders-filter-date-preset">Date Range</label>
                  <select
                    id="orders-filter-date-preset"
                    v-model="query.datePreset"
                    class="form-select staff-datatable-filters__select mb-3"
                    :disabled="loading"
                  >
                    <option value="today">Today</option>
                    <option value="last_7">Last 7 days</option>
                    <option value="last_30">Last 30 days</option>
                    <option value="custom">Custom range</option>
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
                </div>
              </div>
            </div>
          </template>
        </div>
        <p class="small text-secondary mb-0 mt-2 px-1">Only accounts with a ShipHero customer ID appear here.</p>
      </div>

      <div v-if="showManageFilters" class="px-3 px-md-4 pb-2">
        <p class="mb-1 fw-semibold">
          <template v-if="readySummaryLoading">Loading summary...</template>
          <template v-else>{{ readySummary.ready_to_ship_total }} Ready to Ship Orders</template>
        </p>
        <div v-if="!readySummaryLoading && readySummary.ready_to_ship_by_account.length" class="small text-secondary">
          <span
            v-for="(row, idx) in readySummary.ready_to_ship_by_account"
            :key="`${row.account_id}-${idx}`"
            class="me-3 d-inline-block"
          >
            {{ row.account_name }}: {{ row.orders_count }} orders
          </span>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th">Status</th>
              <th class="staff-table-head__th">Order #</th>
              <th class="staff-table-head__th">Order Date</th>
              <th class="staff-table-head__th">Account</th>
              <th class="staff-table-head__th">Country</th>
              <th class="staff-table-head__th">Shipping Carrier</th>
              <th class="staff-table-head__th">Method</th>
              <th class="staff-table-head__th text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="8" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading orders..." />
                </div>
              </td>
            </tr>
            <tr v-else-if="!selectedAccountId">
              <td colspan="8" class="text-center text-secondary py-5">
                Select an account to load orders.
              </td>
            </tr>
            <tr v-else-if="hasSearched && displayedRows.length === 0">
              <td colspan="8" class="text-center text-secondary py-5">
                No orders found.
              </td>
            </tr>
            <tr v-for="row in displayedRows" :key="row.id" class="align-middle">
              <td>
                <span class="badge rounded-pill fw-medium" :class="statusClass(row.status)">
                  {{ row.status || "—" }}
                </span>
              </td>
              <td class="fw-semibold">
                <a
                  v-if="selectedAccountId"
                  :href="orderDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-decoration-none"
                >
                  {{ row.order_number || "—" }}
                </a>
                <span v-else :title="'Select an account'">{{ row.order_number || "—" }}</span>
              </td>
              <td>{{ formatDate(row.order_date) }}</td>
              <td>{{ row.account || "—" }}</td>
              <td>{{ row.country || "—" }}</td>
              <td>{{ row.shipping_carrier || "—" }}</td>
              <td>{{ row.method || "—" }}</td>
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

      <div
        class="staff-table-footer card-footer d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-between gap-3"
      >
        <div class="small text-secondary order-2 order-lg-1">
          Loaded <span class="fw-semibold text-body">{{ rows.length }}</span> order{{ rows.length === 1 ? "" : "s" }}.
        </div>
        <button
          type="button"
          class="btn btn-outline-secondary order-1 order-lg-2 ms-lg-auto"
          :disabled="loading || !hasNextPage || !selectedAccountId"
          @click="fetchOrders(false)"
        >
          {{ hasNextPage ? "Load More" : "No more orders" }}
        </button>
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
        <button class="staff-row-menu__item" role="menuitem" @click="openOrder(manageMenuRow)">
          View
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
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
</style>

