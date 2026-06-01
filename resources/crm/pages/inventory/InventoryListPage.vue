<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { usePortalLastRefreshed } from "../../composables/usePortalLastRefreshed.js";
import { useToast } from "../../composables/useToast.js";
import { exportPortalInventoryCsv } from "../../utils/portalInventoryExport.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const route = useRoute();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const isPortalList = computed(() => route.meta?.userPortal === true);
const isStaffPickerMode = computed(() => !isPortalList.value);

const selectedAccountId = ref("");
const accountsLoading = ref(false);
const accounts = ref([]);
const hasSearched = ref(false);
const { markRefreshed, lastRefreshedLabel } = usePortalLastRefreshed();

const loading = ref(false);
const loadingMore = ref(false);
const searchAutoLoading = ref(false);
const refreshing = ref(false);
const bulkBusy = ref(false);
const rows = ref([]);
const pageInfo = ref({ has_next_page: false, end_cursor: null });

const searchDraft = ref("");
const searchCommitted = ref("");
/** When searching, cumulative match offset for paging (from API next_search_skip). */
const searchSkipNext = ref(0);
let searchRunSeq = 0;
let refreshRunSeq = 0;

const REFRESH_MAX_PAGES = 500;

/** ShipHero inventory list page size */
const LIST_PAGE_SIZE = 50;

const filterMenuOpen = ref(false);
const bulkEditMenuOpen = ref(false);

/** Admin staff: list vs warehouse restock report. */
const viewMode = ref("list");
const restockRows = ref([]);
const restockLoading = ref(false);
const restockRefreshing = ref(false);
const restockMeta = ref({
  warehouse_id: null,
  computed_at: null,
  row_count: 0,
  status: null,
  error_message: null,
});

const showRestockMode = computed(() => isStaffPickerMode.value && viewMode.value === "restock");

const restockLastRunLabel = computed(() => {
  const raw = restockMeta.value.computed_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

const filters = reactive({
  kits: "all",
  activeStatus: "active",
});

const sortKey = ref("on_hand");
const sortDir = ref("desc");

/** Row keys (sku\\0warehouse_id); array so :checked updates reliably when selecting all. */
const selectedKeys = ref([]);
const selectAllCheckboxRef = ref(null);

const accountId = computed(() => {
  if (isStaffPickerMode.value) return Number(selectedAccountId.value || 0);
  return Number(crmUser.value?.client_account_id || 0);
});

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => a?.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

const canInventoryUpdate = computed(() => {
  const u = crmUser.value;
  if (!u || !Array.isArray(u.permission_keys)) return false;
  return u.permission_keys.includes("inventory.update");
});

function rowKey(row) {
  return `${String(row?.sku || "")}\u0000${String(row?.warehouse_id ?? "")}`;
}

function normalizeRows(list) {
  return Array.isArray(list) ? list : [];
}

async function fetchPage(append, forceRefresh = false) {
  if (!accountId.value) return;
  const params = {
    client_account_id: accountId.value,
    first: LIST_PAGE_SIZE,
    kits: filters.kits,
    active_status: filters.activeStatus,
  };
  if (append && pageInfo.value?.end_cursor) {
    params.after = pageInfo.value.end_cursor;
  }
  const q = searchCommitted.value.trim();
  if (q) {
    params.query = q;
    params.search_skip = searchSkipNext.value;
  }
  if (forceRefresh) {
    params.refresh = 1;
  }
  const { data } = await api.get("/inventory/list", { params });
  const chunk = normalizeRows(data?.rows);
  pageInfo.value = {
    has_next_page: Boolean(data?.page_info?.has_next_page),
    end_cursor: data?.page_info?.end_cursor ?? null,
  };
  if (q && typeof data?.page_info?.next_search_skip === "number") {
    searchSkipNext.value = Number(data.page_info.next_search_skip);
  }
  const dest = [];
  const seen = new Set();
  if (append) {
    for (const r of rows.value) {
      const k = rowKey(r);
      seen.add(k);
      dest.push(r);
    }
  }
  for (const r of chunk) {
    const k = rowKey(r);
    if (seen.has(k)) continue;
    seen.add(k);
    dest.push(r);
  }
  rows.value = dest;
  return chunk.length;
}

async function loadRows(reset, forceRefresh = false) {
  if (!accountId.value) return;
  const runId = reset ? ++searchRunSeq : searchRunSeq;
  const previousRows = forceRefresh ? rows.value : [];
  if (reset) {
    loading.value = !forceRefresh;
    refreshing.value = forceRefresh;
    searchAutoLoading.value = false;
    pageInfo.value = { has_next_page: false, end_cursor: null };
    rows.value = [];
    selectedKeys.value = [];
    searchSkipNext.value = 0;
  } else {
    loadingMore.value = true;
  }
  try {
    await fetchPage(!reset, forceRefresh);
    if (reset) {
      markRefreshed();
    }
  } catch (e) {
    if (forceRefresh) {
      rows.value = previousRows;
    }
    toast.errorFrom(e, "Could not load inventory.");
  } finally {
    loading.value = false;
    loadingMore.value = false;
    refreshing.value = false;
  }
  if (reset && searchCommitted.value.trim() && pageInfo.value.has_next_page) {
    continueSearchInBackground(runId);
  }
}

async function continueRefreshSync(refreshId) {
  let guard = 0;
  while (refreshId === refreshRunSeq && pageInfo.value.has_next_page && guard < REFRESH_MAX_PAGES) {
    guard += 1;
    await fetchPage(true, true);
    await nextTick();
  }
}

function loadMore() {
  if (!pageInfo.value.has_next_page || loadingMore.value || loading.value || searchAutoLoading.value) return;
  loadRows(false);
}

async function continueSearchInBackground(runId) {
  if (searchAutoLoading.value) return;
  searchAutoLoading.value = true;
  try {
    let guard = 0;
    while (
      runId === searchRunSeq &&
      searchCommitted.value.trim() &&
      pageInfo.value.has_next_page &&
      guard < 200
    ) {
      guard += 1;
      await fetchPage(true);
      await nextTick();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not finish searching inventory.");
  } finally {
    if (runId === searchRunSeq) {
      searchAutoLoading.value = false;
    }
  }
}

const sortedRows = computed(() => {
  const list = [...rows.value];
  const key = sortKey.value;
  const dir = sortDir.value === "asc" ? 1 : -1;
  const num = (v) => Number(v ?? 0);
  const str = (v) => String(v ?? "").toLowerCase();
  list.sort((a, b) => {
    let cmp = 0;
    if (key === "sku") cmp = str(a.sku).localeCompare(str(b.sku));
    else if (key === "name") cmp = str(a.name).localeCompare(str(b.name));
    else if (key === "on_hand") cmp = num(a.on_hand) - num(b.on_hand);
    else if (key === "allocated") cmp = num(a.allocated) - num(b.allocated);
    else if (key === "backorder") cmp = num(a.backorder) - num(b.backorder);
    else if (key === "kit") cmp = (a.kit || a.kit_build ? 1 : 0) - (b.kit || b.kit_build ? 1 : 0);
    else cmp = str(a.name).localeCompare(str(b.name));
    return cmp * dir;
  });
  return list;
});

const displayRows = sortedRows;

function isKeySelected(k) {
  return selectedKeys.value.includes(k);
}

const allVisibleSelected = computed(() => {
  if (!displayRows.value.length) return false;
  return displayRows.value.every((r) => isKeySelected(rowKey(r)));
});

const someVisibleSelected = computed(() =>
  displayRows.value.some((r) => isKeySelected(rowKey(r))),
);

function toggleSort(col) {
  if (sortKey.value === col) {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  } else {
    sortKey.value = col;
    sortDir.value = col === "name" || col === "sku" ? "asc" : "desc";
  }
}

function thAriaSort(col) {
  if (sortKey.value !== col) return "none";
  return sortDir.value === "asc" ? "ascending" : "descending";
}

function sortIndicator(col) {
  if (sortKey.value !== col) return "";
  return sortDir.value === "asc" ? "↑" : "↓";
}

function onSelectAllCheckboxChange(ev) {
  const el = ev?.target;
  if (!(el instanceof HTMLInputElement)) return;
  const wantChecked = el.checked;
  const visibleKeys = displayRows.value.map(rowKey);
  if (wantChecked) {
    selectedKeys.value = Array.from(new Set([...selectedKeys.value, ...visibleKeys]));
  } else {
    const drop = new Set(visibleKeys);
    selectedKeys.value = selectedKeys.value.filter((k) => !drop.has(k));
  }
  nextTick(syncSelectAllCheckbox);
}

function onRowCheckboxChange(ev, row) {
  const checked = Boolean(ev?.target?.checked);
  const k = rowKey(row);
  if (checked) {
    if (!selectedKeys.value.includes(k)) {
      selectedKeys.value = [...selectedKeys.value, k];
    }
  } else {
    selectedKeys.value = selectedKeys.value.filter((x) => x !== k);
  }
}

function isRowSelected(row) {
  return isKeySelected(rowKey(row));
}

const selectedRows = computed(() =>
  displayRows.value.filter((r) => isKeySelected(rowKey(r))),
);

const bulkEligibleRows = computed(() =>
  selectedRows.value.filter((r) => String(r?.warehouse_id || "").trim() !== ""),
);

function commitSearch() {
  if (isStaffPickerMode.value && !selectedAccountId.value) {
    toast.error("Select an account to load inventory.");
    return;
  }
  searchCommitted.value = searchDraft.value.trim();
  if (isStaffPickerMode.value) hasSearched.value = true;
  loadRows(true);
}

function clearSearch() {
  if (!searchDraft.value && !searchCommitted.value) return;
  if (isStaffPickerMode.value && !selectedAccountId.value) {
    searchDraft.value = "";
    return;
  }
  searchDraft.value = "";
  searchCommitted.value = "";
  loadRows(true);
}

async function refreshRows() {
  if (!accountId.value || loading.value || loadingMore.value || refreshing.value) return;
  if (isStaffPickerMode.value && !selectedAccountId.value) return;
  const previousRows = rows.value;
  const refreshId = ++refreshRunSeq;
  ++searchRunSeq;
  refreshing.value = true;
  searchAutoLoading.value = false;
  loading.value = false;
  loadingMore.value = false;
  try {
    pageInfo.value = { has_next_page: false, end_cursor: null };
    rows.value = [];
    selectedKeys.value = [];
    searchSkipNext.value = 0;
    await fetchPage(false, true);
    await continueRefreshSync(refreshId);
    if (refreshId !== refreshRunSeq) return;
    pageInfo.value = { has_next_page: false, end_cursor: null };
    rows.value = [];
    searchSkipNext.value = 0;
    await fetchPage(false, false);
    markRefreshed();
    toast.success("Inventory refreshed from ShipHero.");
  } catch (e) {
    rows.value = previousRows;
    toast.errorFrom(e, "Could not refresh inventory.");
  } finally {
    if (refreshId === refreshRunSeq) {
      refreshing.value = false;
    }
  }
}

function applyFilters() {
  filterMenuOpen.value = false;
  if (isStaffPickerMode.value && !selectedAccountId.value) return;
  if (isStaffPickerMode.value) hasSearched.value = true;
  loadRows(true);
}

function resetFilters() {
  filters.kits = "all";
  filters.activeStatus = "active";
  filterMenuOpen.value = false;
  if (isStaffPickerMode.value && !selectedAccountId.value) return;
  if (isStaffPickerMode.value) hasSearched.value = true;
  loadRows(true);
}

function exportCsv(useSelected) {
  const source = useSelected ? selectedRows.value : displayRows.value;
  if (!exportPortalInventoryCsv(source, "inventory")) {
    toast.error("Nothing to export.");
    return;
  }
  toast.success("Export started.");
}

async function bulkSetActive(active) {
  const items = bulkEligibleRows.value.map((r) => ({
    sku: String(r.sku || ""),
    warehouse_id: String(r.warehouse_id || ""),
  }));
  if (!items.length) {
    toast.error("Select rows with a warehouse to update status.");
    return;
  }
  const skipped = selectedRows.value.length - items.length;
  bulkBusy.value = true;
  try {
    const { data } = await api.post("/inventory/warehouse-products/bulk-active", {
      client_account_id: accountId.value,
      active,
      items,
    });
    const updated = Number(data?.updated ?? 0);
    const errs = Array.isArray(data?.errors) ? data.errors : [];
    const parts = [];
    if (errs.length) {
      parts.push(`Updated ${updated}; ${errs.length} error(s). First: ${errs[0]?.message || "unknown"}`);
    } else {
      parts.push(`Updated ${updated} warehouse product(s).`);
    }
    if (skipped > 0) {
      parts.push(`${skipped} skipped (no warehouse).`);
    }
    if (errs.length) {
      toast.error(parts.join(" "));
    } else {
      toast.success(parts.join(" "));
    }
    selectedKeys.value = [];
    await loadRows(true);
  } catch (e) {
    toast.errorFrom(e, "Bulk update failed.");
  } finally {
    bulkBusy.value = false;
  }
}

function inventoryDetailTo(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) {
    return { name: isPortalList.value ? "user-inventory" : "inventory" };
  }
  return {
    name: isPortalList.value ? "user-inventory-detail" : "inventory-detail",
    params: { sku },
    query: { client_account_id: String(accountId.value) },
  };
}

function inventoryDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return "#";
  if (!accountId.value && !showRestockMode.value) return "#";
  if (showRestockMode.value) {
    return router.resolve({
      name: "inventory-detail",
      params: { sku },
    }).href;
  }
  return router.resolve(inventoryDetailTo(row)).href;
}

function setViewMode(mode) {
  if (viewMode.value === mode) return;
  viewMode.value = mode;
  if (mode === "restock") {
    loadRestockReport();
  }
}

async function loadRestockReport() {
  restockLoading.value = true;
  try {
    const { data } = await api.get("/inventory/restock");
    restockRows.value = Array.isArray(data?.rows) ? data.rows : [];
    restockMeta.value = {
      warehouse_id: data?.warehouse_id ?? null,
      computed_at: data?.computed_at ?? null,
      row_count: Number(data?.row_count || 0),
      status: data?.status ?? null,
      error_message: data?.error_message ?? null,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load restock report.");
  } finally {
    restockLoading.value = false;
  }
}

async function refreshRestockReport() {
  restockRefreshing.value = true;
  try {
    const { data } = await api.post("/inventory/restock/refresh");
    restockRows.value = Array.isArray(data?.rows) ? data.rows : [];
    restockMeta.value = {
      warehouse_id: data?.warehouse_id ?? null,
      computed_at: data?.computed_at ?? null,
      row_count: Number(data?.row_count || 0),
      status: data?.status ?? null,
      error_message: data?.error_message ?? null,
    };
    toast.success(`Restock report updated (${restockMeta.value.row_count} SKUs).`);
  } catch (e) {
    toast.errorFrom(e, "Could not refresh restock report.");
  } finally {
    restockRefreshing.value = false;
  }
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

function clearSelection() {
  selectedKeys.value = [];
  bulkEditMenuOpen.value = false;
}

function editFirstSelected() {
  const first = selectedRows.value[0];
  if (!first?.sku) {
    toast.error("Select a row to edit.");
    return;
  }
  router.push(inventoryDetailTo(first));
}

function closeBulkEditMenu() {
  bulkEditMenuOpen.value = false;
}

function runBulkExport(useSelected) {
  closeBulkEditMenu();
  exportCsv(useSelected);
}

function runBulkEdit() {
  closeBulkEditMenu();
  editFirstSelected();
}

async function runBulkSetActive(active) {
  closeBulkEditMenu();
  await bulkSetActive(active);
}

function syncSelectAllCheckbox() {
  const el = selectAllCheckboxRef.value;
  if (!el || !(el instanceof HTMLInputElement)) return;
  el.indeterminate = someVisibleSelected.value && !allVisibleSelected.value;
  el.checked = allVisibleSelected.value;
}

watch([selectedKeys, () => displayRows.value.length, allVisibleSelected, someVisibleSelected], () => {
  nextTick(syncSelectAllCheckbox);
});

function onDocClick(e) {
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-bulk-edit-menu]")) {
    bulkEditMenuOpen.value = false;
  }
}

watch(
  () => accountId.value,
  (id) => {
    if (isPortalList.value && id) loadRows(true);
  },
);

watch(
  () => selectedAccountId.value,
  (accountIdVal, prev) => {
    if (!isStaffPickerMode.value) return;
    if (prev && accountIdVal !== prev) {
      searchDraft.value = "";
      searchCommitted.value = "";
    }
    selectedKeys.value = [];
    searchSkipNext.value = 0;
    if (!accountIdVal) {
      rows.value = [];
      pageInfo.value = { has_next_page: false, end_cursor: null };
      hasSearched.value = false;
      return;
    }
    hasSearched.value = true;
    loadRows(true);
  },
);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory",
    description: isPortalList.value ? "Your account inventory." : "ShipHero inventory by account.",
  });
  document.addEventListener("click", onDocClick);
  if (isStaffPickerMode.value) {
    loadAccounts();
  } else {
    loadRows(true);
  }
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Inventory</h1>
        <p class="text-secondary small mb-0 user-inv-load-hint">
          <template v-if="showRestockMode">
            SKUs with pickable qty ≤ 2 and stock in non-pickable locations. Refreshes automatically at 7:00 AM, 12:00 PM, and 2:30 PM (US Eastern).
          </template>
          <template v-else-if="isPortalList">
            Showing {{ LIST_PAGE_SIZE }} products per load. Search checks your full ShipHero catalog (not only this page).
          </template>
          <template v-else>
            Select an account to load inventory using your current filters. Use Search to filter by SKU or product name.
          </template>
        </p>
      </div>
      <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-md-auto">
        <p v-if="showRestockMode && restockLastRunLabel" class="small text-secondary mb-0">
          Last run: {{ restockLastRunLabel }}
        </p>
        <p v-else-if="!showRestockMode && lastRefreshedLabel" class="small text-secondary mb-0">
          Last refreshed: {{ lastRefreshedLabel }}
        </p>
        <button
          v-if="showRestockMode"
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
          :disabled="restockLoading || restockRefreshing"
          title="Refresh restock report"
          aria-label="Refresh restock report from ShipHero"
          @click="refreshRestockReport"
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
          {{ restockRefreshing ? "Refreshing…" : "Refresh" }}
        </button>
        <button
        v-else
        type="button"
        class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
        :disabled="loading || loadingMore || refreshing || (isStaffPickerMode && !selectedAccountId)"
        title="Refresh"
        aria-label="Refresh inventory from ShipHero"
        @click="refreshRows"
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
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 inventory-list-toolbar">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row inventory-toolbar-row">
          <div v-if="isStaffPickerMode" class="btn-group flex-shrink-0" role="group" aria-label="Inventory view">
            <button
              type="button"
              class="btn btn-sm"
              :class="viewMode === 'list' ? 'btn-primary' : 'btn-outline-secondary'"
              @click="setViewMode('list')"
            >
              Search
            </button>
            <button
              type="button"
              class="btn btn-sm"
              :class="viewMode === 'restock' ? 'btn-primary' : 'btn-outline-secondary'"
              @click="setViewMode('restock')"
            >
              Restock
            </button>
          </div>
          <div
            v-if="isStaffPickerMode && !showRestockMode"
            class="inventory-toolbar-account flex-shrink-0"
          >
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="Select account"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="Select account"
              button-id="inventory-list-account-trigger"
            />
          </div>
          <div v-if="!showRestockMode" class="user-inv-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                id="user-inv-search"
                v-model.trim="searchDraft"
                type="search"
                class="form-control"
                placeholder="Search by SKU or Product Name"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search by SKU or product name"
                :disabled="loading || (isStaffPickerMode && !selectedAccountId)"
                @keydown.enter.prevent="commitSearch"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn"
                :disabled="loading || (isStaffPickerMode && !selectedAccountId)"
                @click="commitSearch"
              >
                Search
              </button>
              <button
                v-if="searchDraft || searchCommitted"
                type="button"
                class="btn btn-outline-secondary orders-toolbar-search-btn"
                :disabled="loading || (isStaffPickerMode && !selectedAccountId)"
                @click="clearSearch"
              >
                Clear
              </button>
            </div>
          </div>
          <div v-if="!showRestockMode" class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              :disabled="loading || (isStaffPickerMode && !selectedAccountId)"
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
              class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Inventory filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm staff-bulk-clear-link text-decoration-none p-0"
                  @click="resetFilters"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="user-inv-filter-kits">Kits</label>
                <select id="user-inv-filter-kits" v-model="filters.kits" class="form-select staff-datatable-filters__select mb-3">
                  <option value="all">All</option>
                  <option value="yes">Yes (kits only)</option>
                  <option value="no">No (exclude kits)</option>
                </select>
                <label class="form-label" for="user-inv-filter-active">Product status</label>
                <select
                  id="user-inv-filter-active"
                  v-model="filters.activeStatus"
                  class="form-select staff-datatable-filters__select mb-3"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                  <option value="all">All</option>
                </select>
                <button type="button" class="btn btn-primary btn-sm w-100" @click="applyFilters">Apply</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <template v-if="showRestockMode">
        <div
          v-if="restockRefreshing"
          class="user-inv-sync-banner small text-secondary px-3 py-2 border-bottom bg-body-tertiary"
          role="status"
          aria-live="polite"
        >
          Building restock report from ShipHero…
        </div>
        <div class="table-responsive staff-table-wrap">
          <table class="table table-hover align-middle mb-0 staff-data-table user-inv-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th class="staff-table-head__th text-center user-inv-table__image-col" scope="col">Image</th>
                <th class="staff-table-head__th user-inv-table__text-col" scope="col">SKU</th>
                <th class="staff-table-head__th user-inv-table__text-col" scope="col">Name</th>
                <th class="staff-table-head__th" scope="col">Pick Location</th>
                <th class="staff-table-head__th text-center" scope="col">Pick QTY</th>
                <th class="staff-table-head__th text-center" scope="col">Backstock</th>
                <th class="staff-table-head__th" scope="col">Backstock Locations</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="restockLoading">
                <td colspan="7" class="py-5 text-center text-secondary">Loading restock report…</td>
              </tr>
              <tr v-else-if="!restockLastRunLabel && !restockRows.length">
                <td colspan="7" class="py-5 text-center text-secondary">
                  No restock report yet. Click Refresh to build the first snapshot.
                </td>
              </tr>
              <tr v-else-if="restockRows.length === 0">
                <td colspan="7" class="py-5 text-center text-secondary">Nothing to restock right now.</td>
              </tr>
              <tr v-for="row in restockRows" :key="row.sku" class="align-middle">
                <td class="text-center user-inv-table__image-col">
                  <a
                    v-if="row.image_url"
                    :href="inventoryDetailHref(row)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="user-inv-table__image-link"
                  >
                    <img :src="row.image_url" alt="" class="user-inventory-thumb" loading="lazy" />
                  </a>
                  <span v-else class="text-secondary">—</span>
                </td>
                <td class="fw-semibold user-inv-table__sku-col">
                  <a
                    :href="inventoryDetailHref(row)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="user-inv-table__sku-link"
                  >
                    {{ row.sku }}
                  </a>
                </td>
                <td class="user-inv-table__name-col">
                  <span class="user-inv-table__name-text">{{ row.name || "—" }}</span>
                </td>
                <td>{{ row.pick_location || "—" }}</td>
                <td class="text-center">{{ row.pick_qty ?? "—" }}</td>
                <td class="text-center">{{ row.backstock_qty ?? "—" }}</td>
                <td>{{ row.backstock_location || "—" }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else>
      <div
        v-if="selectedRows.length > 0"
        class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
      >
        <span class="small staff-bulk-selection-bar__count me-md-1">{{ selectedRows.length }} selected</span>
        <div
          class="position-relative d-inline-flex flex-wrap align-items-center gap-2"
          data-bulk-edit-menu
          @click.stop
        >
          <button
            type="button"
            class="btn btn-sm staff-page-primary orders-bulk-toolbar-btn dropdown-toggle"
            :aria-expanded="bulkEditMenuOpen"
            :disabled="bulkBusy"
            @click.stop="bulkEditMenuOpen = !bulkEditMenuOpen"
          >
            Bulk Edit
          </button>
          <div
            v-if="bulkEditMenuOpen"
            class="dropdown-menu show shadow border px-0 py-1 staff-toolbar-bulk-dropdown"
            style="position: absolute; top: calc(100% + 0.25rem); left: 0; z-index: 1090"
            role="menu"
            aria-label="Bulk edit"
            @click.stop
          >
            <button
              type="button"
              class="dropdown-item small"
              role="menuitem"
              :disabled="bulkBusy"
              @click="runBulkExport(true)"
            >
              Export Selected
            </button>
            <button
              type="button"
              class="dropdown-item small"
              role="menuitem"
              :disabled="bulkBusy"
              @click="runBulkExport(false)"
            >
              Export Visible
            </button>
            <button type="button" class="dropdown-item small" role="menuitem" :disabled="bulkBusy" @click="runBulkEdit">
              Edit
            </button>
            <template v-if="canInventoryUpdate">
              <div class="dropdown-divider" />
              <button
                type="button"
                class="dropdown-item small"
                role="menuitem"
                :disabled="bulkBusy"
                @click="runBulkSetActive(true)"
              >
                Set Active
              </button>
              <button
                type="button"
                class="dropdown-item small text-danger"
                role="menuitem"
                :disabled="bulkBusy"
                @click="runBulkSetActive(false)"
              >
                Set Inactive
              </button>
            </template>
          </div>
        </div>
        <button
          type="button"
          class="btn btn-link btn-sm staff-bulk-clear-link text-decoration-none px-1"
          :disabled="bulkBusy"
          @click="clearSelection"
        >
          Clear Selection
        </button>
      </div>

      <div class="position-relative">
        <div
          v-if="refreshing"
          class="user-inv-sync-banner small text-secondary px-3 py-2 border-bottom bg-body-tertiary"
          role="status"
          aria-live="polite"
        >
          Syncing inventory from ShipHero…
        </div>
        <div class="table-responsive staff-table-wrap" :class="{ 'user-inv-table--syncing': refreshing }">
        <table class="table table-hover align-middle mb-0 staff-data-table user-inv-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--select text-center" scope="col">
                <input
                  ref="selectAllCheckboxRef"
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0 user-inv-check"
                  :checked="allVisibleSelected"
                  aria-label="Select all visible rows"
                  @change="onSelectAllCheckboxChange"
                />
              </th>
              <th class="staff-table-head__th text-center user-inv-table__image-col" scope="col">Image</th>
              <th
                class="staff-table-head__th staff-table-head__th--sort user-inv-table__text-col"
                scope="col"
                :aria-sort="thAriaSort('sku')"
              >
                <button type="button" class="staff-sort-btn user-inv-table__sort-start" :disabled="loading" @click="toggleSort('sku')">
                  SKU
                  <span v-if="sortIndicator('sku')" class="staff-sort-ind">{{ sortIndicator("sku") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort user-inv-table__text-col"
                scope="col"
                :aria-sort="thAriaSort('name')"
              >
                <button type="button" class="staff-sort-btn user-inv-table__sort-start" :disabled="loading" @click="toggleSort('name')">
                  Name
                  <span v-if="sortIndicator('name')" class="staff-sort-ind">{{ sortIndicator("name") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort text-center user-inv-table__num-col"
                scope="col"
                :aria-sort="thAriaSort('kit')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('kit')">
                  Kit
                  <span v-if="sortIndicator('kit')" class="staff-sort-ind">{{ sortIndicator("kit") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort text-center user-inv-table__num-col"
                scope="col"
                :aria-sort="thAriaSort('on_hand')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('on_hand')">
                  On Hand
                  <span v-if="sortIndicator('on_hand')" class="staff-sort-ind">{{ sortIndicator("on_hand") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort text-center user-inv-table__num-col"
                scope="col"
                :aria-sort="thAriaSort('allocated')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('allocated')">
                  Allocated
                  <span v-if="sortIndicator('allocated')" class="staff-sort-ind">{{ sortIndicator("allocated") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort text-center user-inv-table__num-col"
                scope="col"
                :aria-sort="thAriaSort('backorder')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('backorder')">
                  Backorder
                  <span v-if="sortIndicator('backorder')" class="staff-sort-ind">{{ sortIndicator("backorder") }}</span>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="8" class="text-center text-secondary py-5">
                Loading inventory…
              </td>
            </tr>
            <tr v-else-if="isStaffPickerMode && !selectedAccountId">
              <td colspan="8" class="text-center text-secondary py-5">Select an account to load inventory.</td>
            </tr>
            <tr v-else-if="!displayRows.length">
              <td colspan="8" class="text-center text-secondary py-5">No inventory rows found.</td>
            </tr>
            <tr v-for="row in displayRows" :key="rowKey(row)">
              <td class="staff-table-cell--tight-check text-center">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0 user-inv-check"
                  :checked="isRowSelected(row)"
                  :aria-label="`Select ${row.sku}`"
                  @change="onRowCheckboxChange($event, row)"
                />
              </td>
              <td class="text-center user-inv-table__image-col">
                <a
                  :href="inventoryDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__image-link"
                  :aria-label="`View ${row.sku || 'product'}`"
                >
                  <img
                    v-if="row.image_url"
                    :src="row.image_url"
                    alt=""
                    class="user-inventory-thumb"
                    loading="lazy"
                  />
                  <div v-else class="user-inventory-thumb user-inventory-thumb--empty" />
                </a>
              </td>
              <td class="user-inv-table__sku-col">
                <a
                  :href="inventoryDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__sku-link"
                >
                  {{ row.sku || "—" }}
                </a>
              </td>
              <td class="user-inv-table__name-col">
                <a
                  :href="inventoryDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__sku-link user-inv-table__name-link"
                >
                  <span class="user-inv-table__name-text">{{ row.name || "—" }}</span>
                </a>
              </td>
              <td class="text-center user-inv-table__num-col">{{ (row.kit || row.kit_build) ? "Yes" : "No" }}</td>
              <td class="text-center user-inv-table__num-col">{{ Number(row.on_hand || 0) }}</td>
              <td class="text-center user-inv-table__num-col">{{ Number(row.allocated || 0) }}</td>
              <td class="text-center user-inv-table__num-col">{{ Number(row.backorder || 0) }}</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
      <div
        v-if="pageInfo.has_next_page && (!isStaffPickerMode || selectedAccountId)"
        class="p-3 border-top text-center"
      >
        <div v-if="searchAutoLoading" class="small text-secondary py-1" aria-live="polite">
          Searching More Matches…
        </div>
        <button
          v-else
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
          :disabled="loadingMore"
          @click="loadMore"
        >
          {{ loadingMore ? "Loading…" : "Load 50 More" }}
        </button>
      </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.inventory-list-toolbar .staff-table-toolbar--row.inventory-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

@media (max-width: 767.98px) {
  .inventory-list-toolbar .staff-table-toolbar--row.inventory-toolbar-row {
    display: flex;
  }
}

.inventory-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.user-inv-search-wrap {
  flex: 0 0 auto;
  width: min(22rem, 100%);
}

.user-inv-table--syncing {
  opacity: 0.55;
  pointer-events: none;
}

.user-inv-table__image-col {
  width: 1%;
  min-width: 4.5rem;
  text-align: center;
  vertical-align: middle;
}

.user-inv-table th.text-center,
.user-inv-table td.text-center {
  text-align: center;
  vertical-align: middle;
}

.user-inv-table {
  table-layout: fixed;
  width: 100%;
  min-width: 52rem;
}

.user-inv-table__text-col,
.user-inv-table__sku-col,
.user-inv-table__name-col {
  text-align: start;
  vertical-align: middle;
}

.user-inv-table__sku-col {
  width: 10rem;
  min-width: 8rem;
}

.user-inv-table__name-col {
  width: auto;
  max-width: min(16rem, 28vw);
}

.user-inv-table__image-link {
  display: inline-block;
  line-height: 0;
  text-decoration: none;
}

.user-inv-table__sku-link {
  color: #2563eb;
  font-weight: 600;
  text-decoration: none;
  user-select: text;
  cursor: pointer;
}

.user-inv-table__sku-link:hover {
  color: #1d4ed8;
  text-decoration: underline;
}

.user-inv-table__name-link {
  font-weight: 400;
}

.user-inv-table__name-text {
  display: block;
  white-space: normal;
  word-break: break-word;
  line-height: 1.4;
  user-select: text;
}

.user-inv-table__num-col {
  min-width: 5.5rem;
  width: 5.5rem;
}

.user-inv-table__sort-start {
  justify-content: flex-start;
  width: 100%;
  text-align: start;
}

.user-inv-table thead .staff-table-head__th--sort:not(.user-inv-table__text-col) .staff-sort-btn {
  justify-content: center;
  width: 100%;
}

.user-inv-check {
  cursor: pointer;
  border-width: 2px;
  accent-color: #2563eb;
  flex-shrink: 0;
}

.user-inventory-thumb {
  width: 52px;
  height: 52px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
}

.user-inventory-thumb--empty {
  display: inline-block;
  background: rgba(0, 0, 0, 0.05);
}

.user-inv-load-hint {
  max-width: 42rem;
  line-height: 1.45;
}
</style>
