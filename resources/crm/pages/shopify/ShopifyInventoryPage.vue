<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyInventoryAddProductModal from "../../components/shopify/ShopifyInventoryAddProductModal.vue";
import ShopifyInventoryBulkEditModal from "../../components/shopify/ShopifyInventoryBulkEditModal.vue";
import ShopifyInventoryImportProductsModal from "../../components/shopify/ShopifyInventoryImportProductsModal.vue";
import ShopifyInventorySyncAccountModal from "../../components/shopify/ShopifyInventorySyncAccountModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const MENU_W = 160;
const MENU_H = 88;
const PER_PAGE = 50;

const router = useRouter();
const toast = useToast();

const loading = ref(false);
const rows = ref([]);
const accounts = ref([]);
const q = ref("");
const accountId = ref("");
const filterMenuOpen = ref(false);
const actionsMenuOpen = ref(false);
const selectedIds = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: PER_PAGE });

const filters = reactive({
  status: "active",
  bundle: "",
  allocated: "all",
  backorder: "all",
});

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const syncOpen = ref(false);
const importOpen = ref(false);
const bulkOpen = ref(false);
const addOpen = ref(false);

const manageMenuRow = computed(
  () => rows.value.find((r) => r.id === manageOpenId.value) ?? null,
);

const allSelected = computed(
  () => rows.value.length > 0 && rows.value.every((r) => selectedIds.value.includes(r.id)),
);

const hasActiveFilters = computed(() => {
  return (
    filters.status !== "active" ||
    filters.bundle === "yes" ||
    filters.allocated !== "all" ||
    filters.backorder !== "all" ||
    !!accountId.value ||
    !!q.value
  );
});

function filterParams() {
  return {
    q: q.value || undefined,
    client_account_id: accountId.value || undefined,
    status: filters.status || undefined,
    bundle: filters.bundle === "yes" ? "yes" : undefined,
    allocated: filters.allocated !== "all" ? filters.allocated : undefined,
    backorder: filters.backorder !== "all" ? filters.backorder : undefined,
    per_page: pagination.value.per_page || PER_PAGE,
    page: pagination.value.current_page || 1,
  };
}

async function loadAccounts() {
  try {
    const { data } = await api.get("/shopify/inventory/accounts");
    accounts.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    accounts.value = [];
    toast.errorFrom(e, "Could not load Shopify accounts.");
  }
}

async function load() {
  loading.value = true;
  manageOpenId.value = null;
  try {
    const { data } = await api.get("/shopify/inventory", { params: filterParams() });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.meta?.current_page || 1,
      last_page: data?.meta?.last_page || 1,
      total: data?.meta?.total || 0,
      per_page: data?.meta?.per_page || PER_PAGE,
    };
    selectedIds.value = selectedIds.value.filter((id) =>
      rows.value.some((r) => r.id === id),
    );
  } catch (e) {
    toast.errorFrom(e, "Could not load Shopify inventory.");
  } finally {
    loading.value = false;
  }
}

function commitSearch() {
  pagination.value.current_page = 1;
  void load();
}

function onAccountChange() {
  pagination.value.current_page = 1;
  void load();
}

function applyFilters() {
  filterMenuOpen.value = false;
  pagination.value.current_page = 1;
  void load();
}

function resetFilters() {
  filters.status = "active";
  filters.bundle = "";
  filters.allocated = "all";
  filters.backorder = "all";
  filterMenuOpen.value = false;
  pagination.value.current_page = 1;
  void load();
}

function clearFilters() {
  q.value = "";
  accountId.value = "";
  filters.status = "active";
  filters.bundle = "";
  filters.allocated = "all";
  filters.backorder = "all";
  pagination.value.current_page = 1;
  void load();
}

function openRow(row) {
  if (!row?.id) return;
  manageOpenId.value = null;
  router.push({ name: "shopify-inventory-detail", params: { id: String(row.id) } });
}

function toggleSelectAll() {
  if (allSelected.value) {
    selectedIds.value = [];
    return;
  }
  selectedIds.value = rows.value.map((r) => r.id);
}

function toggleSelect(id) {
  const idx = selectedIds.value.indexOf(id);
  if (idx >= 0) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id);
  } else {
    selectedIds.value = [...selectedIds.value, id];
  }
}

function isSelected(id) {
  return selectedIds.value.includes(id);
}

function csvEscape(val) {
  const s = String(val ?? "");
  if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
  return s;
}

function exportSelected() {
  const selected = rows.value.filter((r) => selectedIds.value.includes(r.id));
  if (!selected.length) {
    toast.error("Select products to export.");
    return;
  }
  const header = ["SKU", "Product", "Account", "Bundle", "On Hand", "Allocated", "Backorder", "Barcode"];
  const lines = [header.join(",")];
  selected.forEach((r) => {
    lines.push(
      [
        csvEscape(r.sku),
        csvEscape(r.product_title || r.title),
        csvEscape(r.account_name),
        csvEscape(r.bundle ? "Yes" : "No"),
        csvEscape(r.on_hand ?? r.available_total ?? 0),
        csvEscape(r.allocated ?? 0),
        csvEscape(r.backorder ?? 0),
        csvEscape(r.barcode),
      ].join(","),
    );
  });
  const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `shopify-products-${Date.now()}.csv`;
  a.click();
  URL.revokeObjectURL(url);
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

function openActions(action) {
  actionsMenuOpen.value = false;
  if (action === "sync") syncOpen.value = true;
  if (action === "import") importOpen.value = true;
  if (action === "bulk") bulkOpen.value = true;
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-shopify-inventory-row-actions]")) {
    manageOpenId.value = null;
  }
  if (!e.target?.closest?.("[data-sip-actions]")) {
    actionsMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-sip-filters]")) {
    filterMenuOpen.value = false;
  }
}

function goPage(page) {
  if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
  pagination.value.current_page = page;
  void load();
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Products",
    description: "View and manage your product inventory across all accounts.",
  });
  document.addEventListener("click", onDocClick);
  void loadAccounts();
  void load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide sip">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Products</h1>
        <p class="small text-secondary mb-0">
          View and manage your product inventory across all accounts.
        </p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <button
          type="button"
          class="btn btn-primary staff-page-primary fw-semibold d-inline-flex align-items-center gap-1"
          @click="addOpen = true"
        >
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
          Add Product
        </button>
        <div class="position-relative" data-sip-actions>
          <button
            type="button"
            class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
            :aria-expanded="actionsMenuOpen"
            @click.stop="actionsMenuOpen = !actionsMenuOpen"
          >
            Actions
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
          </button>
          <div
            v-if="actionsMenuOpen"
            class="dropdown-menu show shadow border p-1 sip-actions-menu"
            role="menu"
            @click.stop
          >
            <button type="button" class="dropdown-item d-flex align-items-center gap-2" role="menuitem" @click="openActions('sync')">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
              </svg>
              Sync Account
            </button>
            <button type="button" class="dropdown-item d-flex align-items-center gap-2" role="menuitem" @click="openActions('import')">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
              </svg>
              Import Products
            </button>
            <button type="button" class="dropdown-item d-flex align-items-center gap-2" role="menuitem" @click="openActions('bulk')">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 16.323a4.5 4.5 0 01-1.897 1.13L2.25 18l.547-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" />
              </svg>
              Bulk Edit
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row sip-toolbar-row">
          <div class="sip-search-wrap flex-grow-1">
            <div class="input-group orders-toolbar-search-group">
              <span class="input-group-text bg-white border-end-0 text-secondary">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
              </span>
              <input
                v-model="q"
                type="search"
                class="form-control border-start-0"
                placeholder="Search by name, SKU, or barcode."
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search products"
                :disabled="loading"
                @keydown.enter.prevent="commitSearch"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn fw-semibold"
                :disabled="loading"
                @click="commitSearch"
              >
                Search
              </button>
            </div>
          </div>

          <select
            v-model="accountId"
            class="form-select sip-account-select"
            aria-label="Filter by account"
            :disabled="loading"
            @change="onAccountChange"
          >
            <option value="">All Accounts</option>
            <option
              v-for="a in accounts"
              :key="a.id"
              :value="String(a.id)"
            >
              {{ a.company_name || `Account #${a.id}` }}
            </option>
          </select>

          <div class="position-relative flex-shrink-0" data-sip-filters>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              :disabled="loading"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
              </svg>
              <span class="staff-toolbar-filter-text">Filters</span>
            </button>
            <div
              v-if="filterMenuOpen"
              class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Product filters"
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
                <label class="form-label" for="sip-filter-status">Status</label>
                <select id="sip-filter-status" v-model="filters.status" class="form-select mb-3">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>

                <label class="form-label" for="sip-filter-bundle">Bundle</label>
                <select id="sip-filter-bundle" v-model="filters.bundle" class="form-select mb-3">
                  <option value="">All</option>
                  <option value="yes">Yes</option>
                </select>

                <label class="form-label" for="sip-filter-allocated">Allocated</label>
                <select id="sip-filter-allocated" v-model="filters.allocated" class="form-select mb-3">
                  <option value="all">All</option>
                  <option value="show">Show Allocated</option>
                  <option value="hide">Hide Allocated</option>
                </select>

                <label class="form-label" for="sip-filter-backorder">Backorder</label>
                <select id="sip-filter-backorder" v-model="filters.backorder" class="form-select mb-3">
                  <option value="all">All</option>
                  <option value="show">Show Backorder</option>
                  <option value="hide">Hide Backorder</option>
                </select>

                <button
                  type="button"
                  class="btn btn-primary staff-page-primary w-100 fw-semibold"
                  @click="applyFilters"
                >
                  Apply Filters
                </button>
              </div>
            </div>
          </div>

          <button
            v-if="hasActiveFilters"
            type="button"
            class="btn btn-link btn-sm text-decoration-none d-inline-flex align-items-center gap-1 sip-clear-filters"
            :disabled="loading"
            @click="clearFilters"
          >
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
            </svg>
            Clear Filters
          </button>
        </div>
      </div>

      <div
        v-if="selectedIds.length"
        class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
      >
        <input
          type="checkbox"
          class="form-check-input m-0"
          :checked="allSelected"
          aria-label="Select all on page"
          @change="toggleSelectAll"
        />
        <span class="small staff-bulk-selection-bar__count">
          {{ selectedIds.length }} product{{ selectedIds.length === 1 ? "" : "s" }} selected
        </span>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1"
          @click="bulkOpen = true"
        >
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 16.323a4.5 4.5 0 01-1.897 1.13L2.25 18l.547-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" />
          </svg>
          Bulk Edit
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1"
          @click="exportSelected"
        >
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
          </svg>
          Export
        </button>
        <button
          type="button"
          class="btn btn-link btn-sm staff-bulk-clear-link ms-auto text-decoration-none"
          @click="selectedIds = []"
        >
          Clear
        </button>
      </div>

      <div class="table-responsive staff-table-wrap d-none d-lg-block">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th sip-check-col" scope="col">
                <input
                  type="checkbox"
                  class="form-check-input m-0"
                  :checked="allSelected"
                  :disabled="!rows.length || loading"
                  aria-label="Select all"
                  @change="toggleSelectAll"
                />
              </th>
              <th class="staff-table-head__th" scope="col">Product</th>
              <th class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th" scope="col">Bundle</th>
              <th class="staff-table-head__th text-end" scope="col">On Hand</th>
              <th class="staff-table-head__th text-end" scope="col">Allocated</th>
              <th class="staff-table-head__th text-end" scope="col">Backorder</th>
              <th class="staff-table-head__th staff-actions-col text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="8" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading Products…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="8" class="px-4 py-5 text-center text-secondary">
                No products found.
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle sip-row"
              :class="{ 'sip-row--selected': isSelected(row.id) }"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td class="sip-check-col" @click.stop>
                <input
                  type="checkbox"
                  class="form-check-input m-0"
                  :checked="isSelected(row.id)"
                  :aria-label="`Select ${row.sku || row.id}`"
                  @change="toggleSelect(row.id)"
                />
              </td>
              <td>
                <div class="sip-product-cell">
                  <div class="sip-product-cell__img">
                    <img
                      v-if="row.image_url"
                      :src="row.image_url"
                      :alt="row.product_title || row.sku || 'Product'"
                    />
                    <span v-else class="sip-product-cell__img-empty" aria-hidden="true">
                      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                      </svg>
                    </span>
                  </div>
                  <div class="min-w-0">
                    <div class="sip-product-cell__name text-truncate">
                      {{ row.product_title || row.title || "—" }}
                    </div>
                    <div class="sip-product-cell__sku">{{ row.sku || "—" }}</div>
                  </div>
                </div>
              </td>
              <td class="text-body">{{ row.account_name || "—" }}</td>
              <td class="text-body">{{ row.bundle ? "Yes" : "No" }}</td>
              <td class="text-end text-body">{{ Number(row.on_hand ?? row.available_total ?? 0).toLocaleString("en-US") }}</td>
              <td class="text-end text-body">{{ Number(row.allocated ?? 0).toLocaleString("en-US") }}</td>
              <td class="text-end text-body">{{ Number(row.backorder ?? 0).toLocaleString("en-US") }}</td>
              <td class="staff-actions-cell text-center" @click.stop>
                <div
                  data-shopify-inventory-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id ? 'true' : 'false'"
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

      <div class="crm-mobile-item-cards d-lg-none" aria-label="Products">
        <div v-if="loading" class="crm-mobile-item-card__empty">
          <div class="d-flex justify-content-center py-3">
            <CrmLoadingSpinner message="Loading Products…" />
          </div>
        </div>
        <div v-else-if="!rows.length" class="crm-mobile-item-card__empty">
          No products found.
        </div>
        <template v-else>
          <article
            v-for="row in rows"
            :key="`mobile-${row.id}`"
            class="crm-mobile-item-card"
            @click="openRow(row)"
          >
            <div class="crm-mobile-item-card__head">
              <div class="crm-mobile-item-card__head-start d-flex align-items-center gap-2" @click.stop>
                <input
                  type="checkbox"
                  class="form-check-input m-0"
                  :checked="isSelected(row.id)"
                  @change="toggleSelect(row.id)"
                />
                <span class="crm-mobile-item-card__sku crm-mobile-item-card__sku--plain">
                  {{ row.sku || "—" }}
                </span>
              </div>
              <div
                class="crm-mobile-item-card__head-end"
                data-shopify-inventory-row-actions
                @click.stop
              >
                <button
                  type="button"
                  class="staff-action-btn staff-action-btn--more"
                  :class="{ 'is-open': manageOpenId === row.id }"
                  :aria-expanded="manageOpenId === row.id ? 'true' : 'false'"
                  aria-haspopup="true"
                  aria-label="Row actions"
                  @click="toggleManageMenu(row, $event)"
                >
                  <CrmIconRowActions variant="horizontal" />
                </button>
              </div>
            </div>
            <div class="crm-mobile-item-card__product">
              <div class="crm-mobile-item-card__copy">
                <div class="crm-mobile-item-card__name">
                  {{ row.product_title || row.title || "—" }}
                </div>
                <div class="small text-secondary">{{ row.account_name || "—" }}</div>
              </div>
            </div>
            <div class="crm-mobile-item-card__meta">
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">On Hand</span>
                <span class="crm-mobile-item-card__meta-value">{{ row.on_hand ?? 0 }}</span>
              </div>
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Allocated</span>
                <span class="crm-mobile-item-card__meta-value">{{ row.allocated ?? 0 }}</span>
              </div>
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Backorder</span>
                <span class="crm-mobile-item-card__meta-value">{{ row.backorder ?? 0 }}</span>
              </div>
            </div>
          </article>
        </template>
      </div>

      <div
        v-if="pagination.last_page > 1"
        class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 px-md-4 py-3 border-top"
      >
        <div class="small text-secondary">
          Page {{ pagination.current_page }} of {{ pagination.last_page }}
          ({{ pagination.total.toLocaleString("en-US") }} total)
        </div>
        <div class="btn-group">
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm"
            :disabled="loading || pagination.current_page <= 1"
            @click="goPage(pagination.current_page - 1)"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm"
            :disabled="loading || pagination.current_page >= pagination.last_page"
            @click="goPage(pagination.current_page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-shopify-inventory-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openRow(manageMenuRow)"
        >
          View
        </button>
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openRow(manageMenuRow)"
        >
          Edit
        </button>
      </div>
    </Teleport>

    <ShopifyInventorySyncAccountModal
      v-model:open="syncOpen"
      :accounts="accounts"
      @pushed="load"
    />
    <ShopifyInventoryImportProductsModal v-model:open="importOpen" />
    <ShopifyInventoryBulkEditModal
      v-model:open="bulkOpen"
      :selected-ids="selectedIds"
    />
    <ShopifyInventoryAddProductModal v-model:open="addOpen" />
  </div>
</template>

<style scoped>
.sip-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}
.sip-search-wrap {
  flex: 1 1 16rem;
  min-width: 12rem;
  max-width: 28rem;
}
.sip-account-select {
  width: auto;
  min-width: 11rem;
  max-width: 16rem;
}
.sip-clear-filters {
  color: #2563eb;
  white-space: nowrap;
}
.sip-actions-menu {
  right: 0;
  left: auto;
  min-width: 12.5rem;
}
.sip-check-col {
  width: 2.5rem;
  text-align: center;
}
.sip-row {
  cursor: pointer;
}
.sip-row--selected {
  background: #f8fbff;
}
.sip-product-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}
.sip-product-cell__img {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.4rem;
  overflow: hidden;
  background: #f3f4f6;
  border: 1px solid #eceff3;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sip-product-cell__img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.sip-product-cell__img-empty {
  color: #c0c4cc;
}
.sip-product-cell__name {
  font-size: 0.8rem;
  color: #6b7280;
  line-height: 1.25;
}
.sip-product-cell__sku {
  font-size: 0.92rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
@media (max-width: 991.98px) {
  .sip-account-select {
    max-width: none;
    width: 100%;
  }
  .sip-search-wrap {
    max-width: none;
  }
}
</style>
