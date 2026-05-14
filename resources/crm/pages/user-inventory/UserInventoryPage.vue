<script setup>
import { computed, inject, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const loading = ref(false);
const loadingMore = ref(false);
const bulkBusy = ref(false);
const rows = ref([]);
const pageInfo = ref({ has_next_page: false, end_cursor: null });

const searchDraft = ref("");
const searchCommitted = ref("");
const filterMenuOpen = ref(false);

const filters = reactive({
  kits: "all",
  activeStatus: "active",
});

const sortKey = ref("on_hand");
const sortDir = ref("desc");

const selected = ref(new Set());

const accountId = computed(() => Number(crmUser.value?.client_account_id || 0));

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

async function fetchPage(append) {
  if (!accountId.value) return;
  const params = {
    client_account_id: accountId.value,
    first: 200,
    kits: filters.kits,
    active_status: filters.activeStatus,
  };
  if (append && pageInfo.value?.end_cursor) {
    params.after = pageInfo.value.end_cursor;
  }
  const { data } = await api.get("/inventory/list", { params });
  const chunk = normalizeRows(data?.rows);
  pageInfo.value = {
    has_next_page: Boolean(data?.page_info?.has_next_page),
    end_cursor: data?.page_info?.end_cursor ?? null,
  };
  if (append) {
    const seen = new Set(rows.value.map(rowKey));
    for (const r of chunk) {
      const k = rowKey(r);
      if (!seen.has(k)) {
        seen.add(k);
        rows.value.push(r);
      }
    }
  } else {
    rows.value = chunk;
  }
}

async function loadRows(reset) {
  if (!accountId.value) return;
  if (reset) {
    loading.value = true;
    pageInfo.value = { has_next_page: false, end_cursor: null };
    selected.value = new Set();
  } else {
    loadingMore.value = true;
  }
  try {
    await fetchPage(!reset);
  } catch (e) {
    toast.errorFrom(e, "Could not load inventory.");
  } finally {
    loading.value = false;
    loadingMore.value = false;
  }
}

function loadMore() {
  if (!pageInfo.value.has_next_page || loadingMore.value || loading.value) return;
  loadRows(false);
}

const filteredRows = computed(() => {
  const q = searchCommitted.value.trim().toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter(
    (row) =>
      String(row?.sku || "")
        .toLowerCase()
        .includes(q) ||
      String(row?.name || "")
        .toLowerCase()
        .includes(q),
  );
});

const sortedRows = computed(() => {
  const list = [...filteredRows.value];
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

const allVisibleSelected = computed(() => {
  if (!displayRows.value.length) return false;
  return displayRows.value.every((r) => selected.value.has(rowKey(r)));
});

const someVisibleSelected = computed(() =>
  displayRows.value.some((r) => selected.value.has(rowKey(r))),
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

function toggleSelectAllVisible() {
  if (allVisibleSelected.value) {
    const next = new Set(selected.value);
    for (const r of displayRows.value) next.delete(rowKey(r));
    selected.value = next;
  } else {
    const next = new Set(selected.value);
    for (const r of displayRows.value) next.add(rowKey(r));
    selected.value = next;
  }
}

function toggleRow(row) {
  const k = rowKey(row);
  const next = new Set(selected.value);
  if (next.has(k)) next.delete(k);
  else next.add(k);
  selected.value = next;
}

function isRowSelected(row) {
  return selected.value.has(rowKey(row));
}

const selectedRows = computed(() =>
  displayRows.value.filter((r) => selected.value.has(rowKey(r))),
);

const bulkEligibleRows = computed(() =>
  selectedRows.value.filter((r) => String(r?.warehouse_id || "").trim() !== ""),
);

function commitSearch() {
  searchCommitted.value = searchDraft.value.trim();
}

function applyFilters() {
  filterMenuOpen.value = false;
  loadRows(true);
}

function resetFilters() {
  filters.kits = "all";
  filters.activeStatus = "active";
  filterMenuOpen.value = false;
  loadRows(true);
}

function exportCsv(useSelected) {
  const source = useSelected ? selectedRows.value : displayRows.value;
  if (!source.length) {
    toast.error("Nothing to export.");
    return;
  }
  const headers = [
    "SKU",
    "Name",
    "Warehouse ID",
    "Product Active",
    "Kit",
    "Warehouse Active",
    "On Hand",
    "Allocated",
    "Backorder",
  ];
  const lines = [headers.join(",")];
  for (const r of source) {
    const cells = [
      r.sku,
      r.name,
      r.warehouse_id ?? "",
      r.product_active ? "yes" : "no",
      r.kit ? "yes" : "no",
      r.warehouse_active ? "yes" : "no",
      r.on_hand,
      r.allocated,
      r.backorder,
    ].map((c) => {
      const s = String(c ?? "").replace(/"/g, '""');
      return `"${s}"`;
    });
    lines.push(cells.join(","));
  }
  const blob = new Blob([lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `inventory-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
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
    selected.value = new Set();
    await loadRows(true);
  } catch (e) {
    toast.errorFrom(e, "Bulk update failed.");
  } finally {
    bulkBusy.value = false;
  }
}

function openDetail(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return;
  const href = router.resolve({
    name: "user-inventory-detail",
    params: { sku },
    query: { client_account_id: String(accountId.value) },
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
}

function clearSelection() {
  selected.value = new Set();
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
}

watch(
  () => accountId.value,
  (id) => {
    if (id) loadRows(true);
  },
);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Products | Inventory",
    description: "Your account inventory.",
  });
  document.addEventListener("click", onDocClick);
  loadRows(true);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Products</h1>
      <p class="text-secondary small mb-0">Inventory</p>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <div class="user-inv-search-wrap flex-shrink-0">
            <label class="form-label small text-secondary mb-1" for="user-inv-search">Search</label>
            <div class="input-group orders-toolbar-search-group">
              <input
                id="user-inv-search"
                v-model.trim="searchDraft"
                type="search"
                class="form-control"
                placeholder="Search by SKU or Product Name"
                autocomplete="off"
                enterkeyhint="search"
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
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
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
        v-if="selectedRows.length > 0"
        class="d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3 border-bottom bg-body-tertiary"
      >
        <span class="small fw-semibold text-body me-md-1">{{ selectedRows.length }} selected</span>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
          :disabled="bulkBusy"
          @click="exportCsv(true)"
        >
          Export Selected
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
          :disabled="bulkBusy"
          @click="exportCsv(false)"
        >
          Export Visible
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
          :disabled="bulkBusy"
          @click="clearSelection"
        >
          Clear Selection
        </button>
        <template v-if="canInventoryUpdate">
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn"
            :disabled="bulkBusy"
            @click="bulkSetActive(true)"
          >
            Set Active
          </button>
          <button
            type="button"
            class="btn btn-outline-danger btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn orders-toolbar-outline-btn--danger"
            :disabled="bulkBusy"
            @click="bulkSetActive(false)"
          >
            Set Inactive
          </button>
        </template>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table user-inv-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th user-inv-table__select text-center" style="width: 3rem">
                <input
                  class="form-check-input user-inv-check"
                  type="checkbox"
                  :checked="allVisibleSelected"
                  :indeterminate="someVisibleSelected && !allVisibleSelected"
                  aria-label="Select all visible rows"
                  @click.prevent="toggleSelectAllVisible"
                />
              </th>
              <th class="staff-table-head__th text-center">Image</th>
              <th
                class="staff-table-head__th text-center staff-table-head__th--sortable"
                :aria-sort="thAriaSort('sku')"
                role="columnheader"
              >
                <button
                  type="button"
                  class="btn btn-link staff-table-sort-btn user-inv-sort-btn p-0 text-decoration-none"
                  @click="toggleSort('sku')"
                >
                  SKU
                </button>
              </th>
              <th
                class="staff-table-head__th text-center staff-table-head__th--sortable"
                :aria-sort="thAriaSort('name')"
                role="columnheader"
              >
                <button
                  type="button"
                  class="btn btn-link staff-table-sort-btn user-inv-sort-btn p-0 text-decoration-none"
                  @click="toggleSort('name')"
                >
                  Name
                </button>
              </th>
              <th
                class="staff-table-head__th text-center staff-table-head__th--sortable"
                :aria-sort="thAriaSort('kit')"
                role="columnheader"
              >
                <button
                  type="button"
                  class="btn btn-link staff-table-sort-btn user-inv-sort-btn p-0 text-decoration-none"
                  @click="toggleSort('kit')"
                >
                  Kit
                </button>
              </th>
              <th
                class="staff-table-head__th text-center staff-table-head__th--sortable"
                :aria-sort="thAriaSort('on_hand')"
                role="columnheader"
              >
                <button
                  type="button"
                  class="btn btn-link staff-table-sort-btn user-inv-sort-btn p-0 text-decoration-none"
                  @click="toggleSort('on_hand')"
                >
                  On Hand
                </button>
              </th>
              <th
                class="staff-table-head__th text-center staff-table-head__th--sortable"
                :aria-sort="thAriaSort('allocated')"
                role="columnheader"
              >
                <button
                  type="button"
                  class="btn btn-link staff-table-sort-btn user-inv-sort-btn p-0 text-decoration-none"
                  @click="toggleSort('allocated')"
                >
                  Allocated
                </button>
              </th>
              <th
                class="staff-table-head__th text-center staff-table-head__th--sortable"
                :aria-sort="thAriaSort('backorder')"
                role="columnheader"
              >
                <button
                  type="button"
                  class="btn btn-link staff-table-sort-btn user-inv-sort-btn p-0 text-decoration-none"
                  @click="toggleSort('backorder')"
                >
                  Backorder
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="8" class="text-center text-secondary py-5">Loading inventory...</td>
            </tr>
            <tr v-else-if="!displayRows.length">
              <td colspan="8" class="text-center text-secondary py-5">No inventory rows found.</td>
            </tr>
            <tr v-for="row in displayRows" :key="rowKey(row)">
              <td class="user-inv-table__select text-center">
                <input
                  class="form-check-input user-inv-check"
                  type="checkbox"
                  :checked="isRowSelected(row)"
                  :aria-label="`Select ${row.sku}`"
                  @click.prevent="toggleRow(row)"
                />
              </td>
              <td class="text-center">
                <img
                  v-if="row.image_url"
                  :src="row.image_url"
                  alt=""
                  class="user-inventory-thumb"
                  loading="lazy"
                />
                <div v-else class="user-inventory-thumb user-inventory-thumb--empty" />
              </td>
              <td class="text-center">
                <button type="button" class="btn btn-link p-0 text-decoration-none fw-semibold" @click="openDetail(row)">
                  {{ row.sku || "—" }}
                </button>
              </td>
              <td class="text-center">
                <button type="button" class="btn btn-link p-0 text-decoration-none" @click="openDetail(row)">
                  {{ row.name || "—" }}
                </button>
              </td>
              <td class="text-center">{{ (row.kit || row.kit_build) ? "Yes" : "No" }}</td>
              <td class="text-center">{{ Number(row.on_hand || 0) }}</td>
              <td class="text-center">{{ Number(row.allocated || 0) }}</td>
              <td class="text-center">{{ Number(row.backorder || 0) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="pageInfo.has_next_page" class="p-3 border-top text-center">
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? "Loading…" : "Load More" }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.user-inv-search-wrap {
  width: 100%;
  max-width: min(100%, 22rem);
}

.user-inv-table th,
.user-inv-table td {
  text-align: center;
  vertical-align: middle;
}

.user-inv-table__select {
  width: 3rem;
}

.user-inv-check {
  width: 1.125rem;
  height: 1.125rem;
  cursor: pointer;
  border-width: 2px;
  margin: 0;
}

.user-inv-sort-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  text-align: center;
}

.user-inventory-thumb {
  width: 34px;
  height: 34px;
  border-radius: 0.35rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
}

.user-inventory-thumb--empty {
  display: inline-block;
  background: rgba(0, 0, 0, 0.05);
}

.staff-table-sort-btn {
  color: inherit;
  font-weight: 600;
}
</style>
