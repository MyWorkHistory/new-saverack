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

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const query = reactive({
  datePreset: "today",
  from: "",
  to: "",
  sortBy: "order_date",
  sortDir: "desc",
});

const tabKey = computed(() => String(route.meta?.orderTab || "manage"));
const tabTitle = computed(() => {
  if (tabKey.value === "awaiting") return "Awaiting Shipment";
  if (tabKey.value === "on_hold") return "On-Hold";
  if (tabKey.value === "shipped") return "Shipped";
  return "Manage";
});

const showManageFilters = computed(() => tabKey.value === "manage");
const isCustomDate = computed(() => query.datePreset === "custom");

const displayedRows = computed(() => {
  const list = [...rows.value];
  if (query.sortBy === "account") {
    list.sort((a, b) =>
      query.sortDir === "asc"
        ? String(a.account || "").localeCompare(String(b.account || ""))
        : String(b.account || "").localeCompare(String(a.account || "")),
    );
  } else {
    list.sort((a, b) => {
      const av = Date.parse(a.order_date || "") || 0;
      const bv = Date.parse(b.order_date || "") || 0;
      return query.sortDir === "asc" ? av - bv : bv - av;
    });
  }
  return list;
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
    first: 20,
  };
  if (showManageFilters.value) {
    const range = dateRangeFromPreset();
    if (range.from) params.order_date_from = range.from;
    if (range.to) params.order_date_to = range.to;
  }
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

function openOrder(row) {
  openOrderViewNewTab(row);
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) manageOpenId.value = null;
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
  },
);

watch(
  () => [query.datePreset, query.from, query.to],
  () => {
    if (!showManageFilters.value) return;
    if (query.datePreset !== "custom") fetchOrders(true);
  },
);

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  setCrmPageMeta({
    title: `Save Rack | Orders | ${tabTitle.value}`,
    description: "ShipHero customer orders.",
  });
  await loadAccounts();
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

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <div class="flex-grow-1" style="min-width: 220px; max-width: 420px">
            <label class="form-label small text-secondary mb-1" for="orders-list-account-trigger">Account</label>
            <CrmSearchableSelect
              v-model="selectedAccountId"
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
            <select v-model="query.datePreset" class="form-select staff-toolbar-btn" :disabled="loading">
              <option value="today">Today</option>
              <option value="last_7">Last 7 days</option>
              <option value="last_30">Last 30 days</option>
              <option value="custom">Custom range</option>
            </select>
            <input
              v-if="isCustomDate"
              v-model="query.from"
              type="date"
              class="form-control staff-toolbar-btn"
              :disabled="loading"
            />
            <input
              v-if="isCustomDate"
              v-model="query.to"
              type="date"
              class="form-control staff-toolbar-btn"
              :disabled="loading"
            />
            <button
              v-if="isCustomDate"
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn"
              :disabled="loading || !selectedAccountId"
              @click="fetchOrders(true)"
            >
              Apply
            </button>
            <select v-model="query.sortBy" class="form-select staff-toolbar-btn" :disabled="loading">
              <option value="order_date">Order Date</option>
              <option value="account">Account</option>
            </select>
            <select v-model="query.sortDir" class="form-select staff-toolbar-btn" :disabled="loading">
              <option value="desc">Desc</option>
              <option value="asc">Asc</option>
            </select>
          </template>
        </div>
        <p class="small text-secondary mb-0 mt-2 px-1">
          Only accounts with a ShipHero customer ID appear here.
        </p>
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
            <tr v-for="row in displayedRows" :key="row.id">
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

      <div class="staff-table-footer d-flex justify-content-end">
        <button
          type="button"
          class="btn btn-outline-secondary"
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

