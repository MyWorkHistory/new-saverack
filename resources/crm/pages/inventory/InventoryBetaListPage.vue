<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
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
const crossAccountMode = ref(false);
const crossAccountScanTruncated = ref(false);
const accountsLoading = ref(false);
const accounts = ref([]);
const hasSearched = ref(false);

const loading = ref(false);
const loadingMore = ref(false);
const searchAutoLoading = ref(false);
const refreshing = ref(false);
const currentSyncMode = ref("incremental");
const catalogSync = ref({
  inventory_catalog_synced_at: null,
  inventory_catalog_sync_status: "idle",
  inventory_catalog_product_count: 0,
});
const bulkBusy = ref(false);
const rows = ref([]);
const pageInfo = ref({ has_next_page: false, end_cursor: null });

const searchDraft = ref("");
const searchCommitted = ref("");
/** When searching, cumulative match offset for paging (from API next_search_skip). */
const searchSkipNext = ref(0);
let searchRunSeq = 0;
let refreshRunSeq = 0;
let accountLoadSeq = 0;

const REFRESH_MAX_PAGES = 500;

/** Auto-refresh catalog from ShipHero every 30 minutes for the selected account. */
const AUTO_SYNC_INTERVAL_MS = 30 * 60 * 1000;

/** ShipHero inventory list page size */
const LIST_PAGE_SIZE = 50;

const filterMenuOpen = ref(false);
const bulkEditMenuOpen = ref(false);

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

const catalogSyncedLabel = computed(() => {
  const raw = catalogSync.value?.inventory_catalog_synced_at;
  if (!raw) return "";
  return formatDateTimeUs(raw);
});

const catalogSyncRunning = computed(
  () => catalogSync.value?.inventory_catalog_sync_status === "running",
);

const showQtySnapshotHint = computed(
  () => !crossAccountMode.value && accountId.value > 0 && Boolean(catalogSyncedLabel.value),
);

function effectiveRowAccountId(row = null) {
  const fromRow = Number(row?.client_account_id || 0);
  if (fromRow > 0) return fromRow;
  return accountId.value;
}

const canLoadInventory = computed(() => {
  if (isPortalList.value) return accountId.value > 0;
  return (
    accountId.value > 0 ||
    crossAccountMode.value ||
    Boolean(searchCommitted.value.trim()) ||
    Boolean(searchDraft.value.trim())
  );
});

const tableColspan = computed(() => (crossAccountMode.value ? 9 : 8));

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
  const accountPart =
    crossAccountMode.value && Number(row?.client_account_id || 0) > 0
      ? `${row.client_account_id}\u0000`
      : "";
  return `${accountPart}${String(row?.sku || "")}\u0000${String(row?.warehouse_id ?? "")}`;
}

function normalizeRows(list) {
  return Array.isArray(list) ? list : [];
}

async function fetchPage(append, forceRefresh = false, syncMode = "incremental") {
  if (isStaffPickerMode.value && !selectedAccountId.value) {
    crossAccountMode.value = true;
  }
  if (!canLoadInventory.value) return;
  const params = {
    first: LIST_PAGE_SIZE,
    kits: filters.kits,
    active_status: filters.activeStatus,
  };
  if (!crossAccountMode.value && accountId.value > 0) {
    params.client_account_id = accountId.value;
  }
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
    params.sync_mode = syncMode;
    currentSyncMode.value = syncMode;
  }
  const { data } = await api.get("/inventory-beta/list", { params });
  if (data?.catalog_sync && typeof data.catalog_sync === "object") {
    catalogSync.value = { ...catalogSync.value, ...data.catalog_sync };
  }
  const chunk = normalizeRows(data?.rows);
  if (Boolean(data?.meta?.cross_account)) {
    crossAccountMode.value = true;
  }
  crossAccountScanTruncated.value = Boolean(data?.meta?.scan_truncated);
  pageInfo.value = {
    has_next_page: q
      ? Boolean(data?.page_info?.has_next_page)
      : crossAccountMode.value
        ? false
        : Boolean(data?.page_info?.has_next_page),
    end_cursor: q || crossAccountMode.value ? null : data?.page_info?.end_cursor ?? null,
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
  if (isStaffPickerMode.value && !selectedAccountId.value) {
    crossAccountMode.value = true;
  }
  if (!canLoadInventory.value) return;
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
    await fetchPage(true, true, currentSyncMode.value);
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
  return sortDir.value === "asc" ? "Γåæ" : "Γåô";
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

async function commitSearch() {
  if (isStaffPickerMode.value) {
    crossAccountMode.value = !selectedAccountId.value;
    hasSearched.value = true;
  }
  searchCommitted.value = searchDraft.value.trim();
  if (!searchCommitted.value && isStaffPickerMode.value && !selectedAccountId.value) {
    return;
  }
  loadRows(true);
}

function clearSearch() {
  if (!searchDraft.value && !searchCommitted.value) return;
  if (isStaffPickerMode.value && !canLoadInventory.value) {
    searchDraft.value = "";
    searchCommitted.value = "";
    return;
  }
  searchDraft.value = "";
  searchCommitted.value = "";
  loadRows(true);
}

async function syncAccountRows(syncMode = "incremental") {
  if (!canLoadInventory.value || loading.value || loadingMore.value || refreshing.value) return;
  if (catalogSyncRunning.value) return;
  if (crossAccountMode.value) {
    toast.error("Select an account to sync a single catalog.");
    return;
  }
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
    await fetchPage(false, true, syncMode);
    await continueRefreshSync(refreshId);
    if (refreshId !== refreshRunSeq) return;
    pageInfo.value = { has_next_page: false, end_cursor: null };
    rows.value = [];
    searchSkipNext.value = 0;
    await fetchPage(false, false);
    toast.success(
      syncMode === "full"
        ? "Products synced from ShipHero."
        : "Inventory refreshed from ShipHero.",
    );
    startAutoSyncTimer();
  } catch (e) {
    if (e.response?.status === 409) {
      if (e.response?.data?.catalog_sync) {
        catalogSync.value = { ...catalogSync.value, ...e.response.data.catalog_sync };
      }
      startCatalogSyncPoll();
      return;
    }
    rows.value = previousRows;
    toast.errorFrom(e, "Could not sync inventory catalog.");
  } finally {
    if (refreshId === refreshRunSeq) {
      refreshing.value = false;
    }
  }
}

async function refreshRows() {
  await syncAccountRows("incremental");
}

async function rebuildCatalogRows() {
  if (
    !window.confirm(
      "Sync all products from ShipHero? This clears cached catalog data for this account.",
    )
  ) {
    return;
  }
  await syncAccountRows("full");
}

function applyFilters() {
  filterMenuOpen.value = false;
  if (isStaffPickerMode.value) {
    crossAccountMode.value = !selectedAccountId.value;
    hasSearched.value = true;
    loadRows(true);
    return;
  }
  loadRows(true);
}

function resetFilters() {
  filters.kits = "all";
  filters.activeStatus = "active";
  filterMenuOpen.value = false;
  if (isStaffPickerMode.value) {
    crossAccountMode.value = !selectedAccountId.value;
    hasSearched.value = true;
    loadRows(true);
    return;
  }
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
  if (!accountId.value) {
    toast.error("Select an account for bulk updates.");
    return;
  }
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

const accountNameById = computed(() => {
  const map = new Map();
  for (const a of accounts.value || []) {
    const id = Number(a?.id || 0);
    if (id > 0) {
      map.set(id, a.company_name || `Account #${id}`);
    }
  }
  return map;
});

function rowAccountLabel(row) {
  const fromRow = String(row?.client_account_company_name || "").trim();
  if (fromRow) return fromRow;
  const id = effectiveRowAccountId(row);
  if (id > 0) {
    return accountNameById.value.get(id) || `Account #${id}`;
  }
  return "—";
}

function clientAccountHref(row) {
  const id = effectiveRowAccountId(row);
  if (id <= 0) return "";
  return router.resolve({ name: "client-account-detail", params: { id: String(id) } }).href;
}

function inventoryDetailTo(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) {
    return { name: isPortalList.value ? "user-inventory-beta" : "inventory-beta" };
  }
  const rowAccountId = effectiveRowAccountId(row);
  const query = rowAccountId > 0 ? { client_account_id: String(rowAccountId) } : {};
  return {
    name: isPortalList.value ? "user-inventory-beta-detail" : "inventory-beta-detail",
    params: { sku },
    query,
  };
}

function inventoryDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  const rowAccountId = effectiveRowAccountId(row);
  if (!sku || rowAccountId <= 0) return "#";
  return router.resolve(inventoryDetailTo(row)).href;
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
    if (id) loadRows(true);
  },
);


let catalogSyncPollTimer = null;

function stopCatalogSyncPoll() {
  if (catalogSyncPollTimer !== null) {
    clearInterval(catalogSyncPollTimer);
    catalogSyncPollTimer = null;
  }
}

async function pollCatalogSyncStatus() {
  if (accountId.value <= 0 || crossAccountMode.value) {
    stopCatalogSyncPoll();
    return;
  }
  try {
    const { data } = await api.get("/inventory-beta/list", {
      params: {
        client_account_id: accountId.value,
        first: 1,
        kits: filters.kits,
        active_status: filters.activeStatus,
      },
    });
    if (data?.catalog_sync && typeof data.catalog_sync === "object") {
      catalogSync.value = { ...catalogSync.value, ...data.catalog_sync };
    }
    if (catalogSync.value?.inventory_catalog_sync_status !== "running") {
      stopCatalogSyncPoll();
      refreshing.value = false;
      await loadRows(true);
    }
  } catch {
    // ignore transient poll errors
  }
}

function startCatalogSyncPoll() {
  stopCatalogSyncPoll();
  refreshing.value = true;
  catalogSyncPollTimer = setInterval(pollCatalogSyncStatus, 3000);
  pollCatalogSyncStatus();
}

let autoSyncTimer = null;

function catalogSyncIsStale() {
  const raw = catalogSync.value?.inventory_catalog_synced_at;
  if (!raw) return true;
  const syncedAt = new Date(raw).getTime();
  if (Number.isNaN(syncedAt)) return true;
  return Date.now() - syncedAt >= AUTO_SYNC_INTERVAL_MS;
}

function stopAutoSyncTimer() {
  if (autoSyncTimer !== null) {
    clearInterval(autoSyncTimer);
    autoSyncTimer = null;
  }
  stopCatalogSyncPoll();
}

function startAutoSyncTimer() {
  stopAutoSyncTimer();
  if (accountId.value <= 0 || crossAccountMode.value) return;
  autoSyncTimer = setInterval(() => {
    if (document.visibilityState !== "visible") return;
    if (loading.value || refreshing.value || loadingMore.value || catalogSyncRunning.value) return;
    syncAccountRows("incremental");
  }, AUTO_SYNC_INTERVAL_MS);
}

function onPageVisibilityChange() {
  if (document.visibilityState !== "visible") return;
  if (accountId.value <= 0 || crossAccountMode.value) return;
  if (!catalogSyncIsStale()) return;
  if (loading.value || refreshing.value || loadingMore.value || catalogSyncRunning.value) return;
  syncAccountRows("incremental");
}

async function ensureAccountCatalogSynced(loadId) {
  if (loadId !== accountLoadSeq) return;
  if (accountId.value <= 0 || crossAccountMode.value) return;
  if (!catalogSyncIsStale()) {
    startAutoSyncTimer();
    return;
  }
  if (loading.value || refreshing.value) return;
  await syncAccountRows("incremental");
  if (loadId === accountLoadSeq) {
    startAutoSyncTimer();
  }
}

watch(
  () => accountId.value,
  async (id) => {
    if (!isPortalList.value || !id) return;
    const loadId = ++accountLoadSeq;
    hasSearched.value = true;
    await loadRows(true);
    await ensureAccountCatalogSynced(loadId);
  },
);

watch(
  () => selectedAccountId.value,
  async (accountIdVal, prev) => {
    if (!isStaffPickerMode.value) return;
    const loadId = ++accountLoadSeq;
    crossAccountMode.value = false;
    crossAccountScanTruncated.value = false;
    if (prev && accountIdVal !== prev) {
      searchDraft.value = "";
      searchCommitted.value = "";
    }
    selectedKeys.value = [];
    searchSkipNext.value = 0;
    if (!accountIdVal) {
      stopAutoSyncTimer();
      rows.value = [];
      pageInfo.value = { has_next_page: false, end_cursor: null };
      hasSearched.value = false;
      return;
    }
    hasSearched.value = true;
    await loadRows(true);
    await ensureAccountCatalogSynced(loadId);
  },
);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory (Beta)",
    description: isPortalList.value
      ? "Your account product catalog."
      : "CRM-stored product catalog with incremental account sync.",
  });
  document.addEventListener("click", onDocClick);
  document.addEventListener("visibilitychange", onPageVisibilityChange);
  if (isStaffPickerMode.value) {
    loadAccounts();
  } else {
    const loadId = ++accountLoadSeq;
    hasSearched.value = true;
    loadRows(true).then(() => ensureAccountCatalogSynced(loadId));
  }
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  document.removeEventListener("visibilitychange", onPageVisibilityChange);
  stopAutoSyncTimer();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
        <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-md-auto flex-wrap justify-content-md-end w-100 w-md-auto">
        <p v-if="catalogSyncedLabel && accountId > 0 && !crossAccountMode" class="small text-secondary mb-0">
          Catalog synced: {{ catalogSyncedLabel }}
        </p>
        <button
          v-if="accountId > 0 && !crossAccountMode"
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
          :disabled="loading || loadingMore || refreshing || catalogSyncRunning"
          @click="rebuildCatalogRows"
        >
          Sync Products
        </button>
        <button
        type="button"
        class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
        :disabled="loading || loadingMore || refreshing || catalogSyncRunning || (isStaffPickerMode && !canLoadInventory)"
        title="Refresh Inventory"
        aria-label="Refresh inventory catalog from ShipHero"
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
        {{ refreshing || catalogSyncRunning ? "Syncing…" : "Refresh Inventory" }}
      </button>


    <div
      v-if="crossAccountMode && crossAccountScanTruncated && hasSearched && displayRows.length > 0"
      class="alert alert-warning small py-2 mb-3"
      role="status"
    >
      Showing partial results — not all accounts were scanned within the time limit. Select an account for a complete
      catalog.
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 inventory-list-toolbar">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row inventory-toolbar-row">
          <div
            v-if="isStaffPickerMode"
            class="inventory-toolbar-account flex-shrink-0"
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
              button-id="inventory-list-account-trigger"
            />
          </div>
          <div class="user-inv-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                id="user-inv-search"
                v-model.trim="searchDraft"
                type="search"
                class="form-control"
                placeholder="Search by SKU, barcode, or product name"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search by SKU, barcode, or product name"
                :disabled="loading"
                @keydown.enter.prevent="commitSearch"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn"
                :disabled="loading"
                @click="commitSearch"
              >
                Search
              </button>
              <button
                v-if="searchDraft || searchCommitted"
                type="button"
                class="btn btn-outline-secondary orders-toolbar-search-btn"
                :disabled="loading"
                @click="clearSearch"
              >
                Clear
              </button>
            </div>
          </div>
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              :disabled="loading"
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

      <div
        v-if="isStaffPickerMode && selectedRows.length > 0"
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
          Syncing inventory catalog from ShipHero…
        </div>
        <p
          v-if="showQtySnapshotHint"
          class="small text-secondary px-3 pt-2 mb-0"
        >
          On Hand, Allocated, and Backorder are snapshots from the last account sync. Open a product for live quantities.
        </p>
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
              <th v-if="crossAccountMode" class="staff-table-head__th user-inv-table__text-col" scope="col">
                Account
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
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                Loading inventory…
              </td>
            </tr>
            <tr v-else-if="isStaffPickerMode && !hasSearched">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                Enter a SKU or barcode and press Search — account is optional. Select an account to filter the catalog.
              </td>
            </tr>
            <tr v-else-if="!displayRows.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">No inventory rows found.</td>
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
              <td v-if="crossAccountMode" class="user-inv-table__text-col">
                <a
                  v-if="clientAccountHref(row)"
                  :href="clientAccountHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__sku-link"
                >
                  {{ rowAccountLabel(row) }}
                </a>
                <span v-else class="text-secondary">{{ rowAccountLabel(row) }}</span>
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
        v-if="pageInfo.has_next_page && (accountId > 0 || crossAccountMode)"
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
