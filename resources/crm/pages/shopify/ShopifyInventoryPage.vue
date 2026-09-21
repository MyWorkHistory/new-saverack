<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyInventoryAddProductModal from "../../components/shopify/ShopifyInventoryAddProductModal.vue";
import ShopifyInventoryBulkEditModal from "../../components/shopify/ShopifyInventoryBulkEditModal.vue";
import ShopifyInventoryImportProductsModal from "../../components/shopify/ShopifyInventoryImportProductsModal.vue";
import ShopifyInventoryLocationCell from "../../components/shopify/ShopifyInventoryLocationCell.vue";
import ShopifyInventoryPackagingModal from "../../components/shopify/ShopifyInventoryPackagingModal.vue";
import ShopifyInventorySyncAccountModal from "../../components/shopify/ShopifyInventorySyncAccountModal.vue";
import ShopifyInventoryViewBulkModal from "../../components/shopify/ShopifyInventoryViewBulkModal.vue";
import ShopifyInventoryViewEditModal from "../../components/shopify/ShopifyInventoryViewEditModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import CrmListTableFooter from "../../components/common/CrmListTableFooter.vue";
import { LIST_PAGE_SIZE_DEFAULT } from "../../constants/pagination.js";

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
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: LIST_PAGE_SIZE_DEFAULT });

const filters = reactive({
  status: "active",
  bundle: "",
  allocated: "all",
  backorder: "all",
});

const syncOpen = ref(false);
const importOpen = ref(false);
const bulkOpen = ref(false);
const viewBulkOpen = ref(false);
const addOpen = ref(false);
const viewType = ref("inventory");
const editBusy = ref(false);
const packagingEditOpen = ref(false);
const viewEditOpen = ref(false);
const editRow = ref(null);
const rowMenu = ref(null);
const rowMenuRect = ref({ top: 0, left: 0 });

const VIEW_OPTIONS = [
  { value: "inventory", label: "Inventory" },
  { value: "locations", label: "Locations" },
  { value: "packaging", label: "Packaging" },
  { value: "weights", label: "Weights" },
  { value: "dimensions", label: "Dimensions" },
];

const hasRowActions = computed(() => viewType.value !== "inventory");
const tableColspan = computed(() => {
  if (viewType.value === "locations") return 7;
  if (viewType.value === "packaging") return 6;
  if (viewType.value === "weights") return 5;
  if (viewType.value === "dimensions") return 8;
  return 7;
});

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
    per_page: pagination.value.per_page || LIST_PAGE_SIZE_DEFAULT,
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
  try {
    const { data } = await api.get("/shopify/inventory", { params: filterParams() });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.meta?.current_page || 1,
      last_page: data?.meta?.last_page || 1,
      total: data?.meta?.total || 0,
      per_page: data?.meta?.per_page || LIST_PAGE_SIZE_DEFAULT,
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

function inventoryDetailHref(row) {
  if (!row?.id) return "#";
  return router.resolve({ name: "shopify-inventory-detail", params: { id: String(row.id) } }).href;
}

function openRow(row) {
  if (!row?.id) return;
  rowMenu.value = null;
  window.open(inventoryDetailHref(row), "_blank", "noopener,noreferrer");
}

function packagingListLabel(row, listKey, singleKey) {
  const list = Array.isArray(row?.[listKey]) ? row[listKey] : [];
  const labels = list.map((item) => item?.label || item?.name).filter(Boolean);
  if (labels.length) return labels.join(", ");
  return row?.[singleKey]?.label || "—";
}

function locationGroup(row, key) {
  const group = (row?.location_groups || []).find((item) => item.key === key);
  return Array.isArray(group?.locations) ? group.locations : [];
}

function locationGroupLabel(key) {
  if (key === "backstock") return "Backstock Locations";
  if (key === "other") return "Picking Cart";
  return "Pick Locations";
}

function weightLabel(row) {
  if (row?.weight == null || row.weight === "") return "—";
  const unit = String(row.weight_unit || "POUNDS").toUpperCase();
  const suffix = unit === "OUNCES" ? "oz" : unit === "GRAMS" ? "g" : unit === "KILOGRAMS" ? "kg" : "lbs";
  return `${Number(row.weight).toLocaleString("en-US", { maximumFractionDigits: 3 })} ${suffix}`;
}

function dimLabel(value, unit) {
  if (value == null || value === "") return "—";
  const suffix = String(unit || "").toUpperCase() === "CENTIMETERS" ? "cm" : "in";
  return `${Number(value).toLocaleString("en-US", { maximumFractionDigits: 3 })} ${suffix}`;
}

function cubicFeetLabel(row) {
  const l = Number(row?.length);
  const w = Number(row?.width);
  const h = Number(row?.height);
  if (![l, w, h].every((n) => Number.isFinite(n) && n > 0)) return "—";
  let inchesL = l;
  let inchesW = w;
  let inchesH = h;
  if (String(row?.dimension_unit || "").toUpperCase() === "CENTIMETERS") {
    inchesL /= 2.54;
    inchesW /= 2.54;
    inchesH /= 2.54;
  }
  return (inchesL * inchesW * inchesH / 1728).toLocaleString("en-US", { maximumFractionDigits: 3 });
}

async function toggleRowMenu(row, e) {
  e?.stopPropagation?.();
  if (rowMenu.value?.id === row?.id) {
    rowMenu.value = null;
    return;
  }
  const btn = e?.currentTarget;
  rowMenu.value = row;
  await nextTick();
  requestAnimationFrame(() => {
    if (!(btn instanceof HTMLElement)) return;
    const r = btn.getBoundingClientRect();
    const menuW = 140;
    const menuH = 52;
    let top = r.bottom + 4;
    let left = Math.max(8, Math.min(r.right - menuW, window.innerWidth - menuW - 8));
    if (top + menuH > window.innerHeight - 8) top = Math.max(8, r.top - menuH - 4);
    rowMenuRect.value = { top, left };
  });
}

function openViewEdit(row) {
  editRow.value = row;
  rowMenu.value = null;
  if (viewType.value === "packaging") packagingEditOpen.value = true;
  else viewEditOpen.value = true;
}

async function savePackaging(payload) {
  if (!editRow.value?.id) return;
  editBusy.value = true;
  try {
    await api.patch(`/shopify/inventory/${editRow.value.id}/packaging`, payload);
    toast.success("Packaging updated.");
    packagingEditOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not update packaging.");
  } finally {
    editBusy.value = false;
  }
}

async function saveViewEdit(payload) {
  if (!editRow.value?.id) return;
  editBusy.value = true;
  try {
    if (viewType.value === "locations") {
      const changes = payload.changes || [];
      if (!changes.length) {
        viewEditOpen.value = false;
        return;
      }
      if (!payload.reason) {
        toast.error("Select a reason.");
        return;
      }
      await Promise.all(
        changes.map((change) =>
          api.patch(`/shopify/locations/${change.location_id}/items/${change.item_id}`, {
            available: change.available,
            reason: payload.reason,
          }),
        ),
      );
      toast.success("Locations updated.");
    } else {
      await api.patch(`/shopify/inventory/${editRow.value.id}`, payload);
      toast.success(viewType.value === "weights" ? "Weight updated." : "Dimensions updated.");
    }
    viewEditOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not save changes.");
  } finally {
    editBusy.value = false;
  }
}

function openSelectionBulk() {
  if (viewType.value === "inventory") {
    bulkOpen.value = true;
    return;
  }
  viewBulkOpen.value = true;
}

async function saveViewBulk(payload) {
  if (!selectedIds.value.length) return;
  if (payload.mode === "locations" && !payload.location_id) {
    toast.error("Select a location.");
    return;
  }
  if (payload.mode === "locations" && !payload.reason) {
    toast.error("Select a reason.");
    return;
  }
  if (payload.mode === "weights" && (payload.weight == null || Number.isNaN(payload.weight))) {
    toast.error("Enter a weight.");
    return;
  }
  if (payload.mode === "dimensions" && [payload.length, payload.width, payload.height].some((n) => n == null || Number.isNaN(n))) {
    toast.error("Enter length, width, and height.");
    return;
  }
  editBusy.value = true;
  try {
    const { data } = await api.post("/shopify/inventory/bulk-view-edit", {
      ...payload,
      ids: selectedIds.value,
    });
    toast.success(data?.message || "Updated.");
    viewBulkOpen.value = false;
    selectedIds.value = [];
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not update the selected products.");
  } finally {
    editBusy.value = false;
  }
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

function openActions(action) {
  actionsMenuOpen.value = false;
  if (action === "sync") syncOpen.value = true;
  if (action === "import") importOpen.value = true;
  if (action === "bulk") bulkOpen.value = true;
}

// CSV work runs after the response, so refresh once it has had time to land.
function onCsvQueued() {
  window.setTimeout(() => {
    void load();
  }, 4000);
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-sip-actions]")) {
    actionsMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-sip-filters]")) {
    filterMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-sip-row-actions]")) {
    rowMenu.value = null;
  }
}

function goPage(page) {
  if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
  pagination.value.current_page = page;
  void load();
}

function onPerPageChange(size) {
  pagination.value.per_page = Number(size) || LIST_PAGE_SIZE_DEFAULT;
  pagination.value.current_page = 1;
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
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4 sip-page-head">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Products</h1>
        <p class="small text-secondary mb-0">
          View and manage your product inventory across all accounts.
        </p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 sip-page-head__actions">
        <button
          type="button"
          class="btn btn-primary staff-page-primary fw-semibold d-inline-flex align-items-center justify-content-center gap-1 sip-add-btn"
          aria-label="Add Product"
          @click="addOpen = true"
        >
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
          <span class="sip-add-btn__label">Add Product</span>
        </button>
        <div class="position-relative d-none d-lg-block" data-sip-actions>
          <button
            type="button"
            class="btn btn-outline-primary staff-toolbar-btn d-inline-flex align-items-center justify-content-center gap-2 sip-actions-btn"
            :aria-expanded="actionsMenuOpen"
            aria-label="Actions"
            @click.stop="actionsMenuOpen = !actionsMenuOpen"
          >
            <span class="sip-actions-btn__label">Actions</span>
            <svg class="sip-actions-btn__chevron" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
      <div class="staff-table-toolbar sip-toolbar">
        <div class="sip-search-wrap">
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
              placeholder="Search by name, SKU, or barcode"
              autocomplete="off"
              enterkeyhint="search"
              aria-label="Search products"
              :disabled="loading"
              @keydown.enter.prevent="commitSearch"
            />
            <button
              type="button"
              class="btn btn-primary staff-page-primary orders-toolbar-search-btn fw-semibold sip-search-btn"
              :disabled="loading"
              @click="commitSearch"
            >
              Search
            </button>
          </div>
        </div>

        <div class="sip-toolbar__controls">
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
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
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
            class="btn btn-link btn-sm text-decoration-none d-inline-flex align-items-center gap-1 sip-clear-filters d-none d-lg-inline-flex"
            :disabled="loading"
            @click="clearFilters"
          >
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
            </svg>
            Clear Filters
          </button>

          <div class="sip-view-type">
            <select
              id="sip-view-type"
              v-model="viewType"
              class="form-select sip-view-select"
              aria-label="View"
              :disabled="loading"
            >
              <option v-for="opt in VIEW_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>

          <div class="position-relative flex-shrink-0 d-lg-none" data-sip-actions>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn sip-toolbar-more"
              :aria-expanded="actionsMenuOpen"
              aria-label="Actions"
              @click.stop="actionsMenuOpen = !actionsMenuOpen"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <circle cx="5" cy="12" r="1.75" />
                <circle cx="12" cy="12" r="1.75" />
                <circle cx="19" cy="12" r="1.75" />
              </svg>
            </button>
            <div
              v-if="actionsMenuOpen"
              class="dropdown-menu show shadow border p-1 sip-actions-menu"
              role="menu"
              @click.stop
            >
              <button type="button" class="dropdown-item d-flex align-items-center gap-2" role="menuitem" @click="openActions('sync')">
                Sync Account
              </button>
              <button type="button" class="dropdown-item d-flex align-items-center gap-2" role="menuitem" @click="openActions('import')">
                Import Products
              </button>
              <button type="button" class="dropdown-item d-flex align-items-center gap-2" role="menuitem" @click="openActions('bulk')">
                Bulk Edit
              </button>
            </div>
          </div>
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
          class="btn btn-outline-primary staff-toolbar-btn d-inline-flex align-items-center gap-2"
          @click="openSelectionBulk"
        >
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 16.323a4.5 4.5 0 01-1.897 1.13L2.25 18l.547-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" />
          </svg>
          Bulk Edit
        </button>
        <button
          type="button"
          class="btn btn-outline-primary staff-toolbar-btn d-inline-flex align-items-center gap-2"
          @click="exportSelected"
        >
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
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
              <template v-if="viewType === 'inventory'">
                <th class="staff-table-head__th" scope="col">Bundle</th>
                <th class="staff-table-head__th text-end" scope="col">On Hand</th>
                <th class="staff-table-head__th text-end" scope="col">Allocated</th>
                <th class="staff-table-head__th text-end" scope="col">Backorder</th>
              </template>
              <template v-else-if="viewType === 'locations'">
                <th class="staff-table-head__th" scope="col">Pick Locations</th>
                <th class="staff-table-head__th" scope="col">Backstock Locations</th>
                <th class="staff-table-head__th" scope="col">Picking Cart</th>
              </template>
              <template v-else-if="viewType === 'packaging'">
                <th class="staff-table-head__th" scope="col">Default Packaging</th>
                <th class="staff-table-head__th" scope="col">Packaging Materials</th>
              </template>
              <template v-else-if="viewType === 'weights'">
                <th class="staff-table-head__th" scope="col">Weight</th>
              </template>
              <template v-else>
                <th class="staff-table-head__th" scope="col">Length</th>
                <th class="staff-table-head__th" scope="col">Width</th>
                <th class="staff-table-head__th" scope="col">Height</th>
                <th class="staff-table-head__th" scope="col">Cubic Ft</th>
              </template>
              <th v-if="hasRowActions" class="staff-table-head__th text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading Products…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">
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
              <template v-if="viewType === 'inventory'">
                <td class="text-body">{{ row.bundle ? "Yes" : "No" }}</td>
                <td class="text-end text-body">{{ Number(row.on_hand ?? row.available_total ?? 0).toLocaleString("en-US") }}</td>
                <td class="text-end text-body">{{ Number(row.allocated ?? 0).toLocaleString("en-US") }}</td>
                <td class="text-end text-body">{{ Number(row.backorder ?? 0).toLocaleString("en-US") }}</td>
              </template>
              <template v-else-if="viewType === 'locations'">
                <td @click.stop>
                  <ShopifyInventoryLocationCell :locations="locationGroup(row, 'pick')" :label="locationGroupLabel('pick')" />
                </td>
                <td @click.stop>
                  <ShopifyInventoryLocationCell :locations="locationGroup(row, 'backstock')" :label="locationGroupLabel('backstock')" />
                </td>
                <td @click.stop>
                  <ShopifyInventoryLocationCell :locations="locationGroup(row, 'other')" :label="locationGroupLabel('other')" />
                </td>
              </template>
              <template v-else-if="viewType === 'packaging'">
                <td class="text-body">{{ packagingListLabel(row, "packaging_items", "packaging") }}</td>
                <td class="text-body">{{ packagingListLabel(row, "packaging_materials", "packaging_material") }}</td>
              </template>
              <template v-else-if="viewType === 'weights'">
                <td class="text-body">{{ weightLabel(row) }}</td>
              </template>
              <template v-else>
                <td class="text-body">{{ dimLabel(row.length, row.dimension_unit) }}</td>
                <td class="text-body">{{ dimLabel(row.width, row.dimension_unit) }}</td>
                <td class="text-body">{{ dimLabel(row.height, row.dimension_unit) }}</td>
                <td class="text-body">{{ cubicFeetLabel(row) }}</td>
              </template>
              <td v-if="hasRowActions" class="staff-actions-cell text-center" @click.stop>
                <div data-sip-row-actions class="staff-actions-inner staff-actions-inner--single justify-content-center">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': rowMenu?.id === row.id }"
                    aria-label="Row actions"
                    @click="toggleRowMenu(row, $event)"
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
            class="crm-mobile-item-card sip-mobile-card"
            @click="openRow(row)"
          >
            <div class="crm-mobile-item-card__head">
              <div class="crm-mobile-item-card__head-start" @click.stop>
                <input
                  type="checkbox"
                  class="form-check-input m-0 crm-mobile-item-card__check"
                  :checked="isSelected(row.id)"
                  :aria-label="`Select ${row.sku || row.product_title || 'product'}`"
                  @change="toggleSelect(row.id)"
                >
              </div>
              <div class="crm-mobile-item-card__head-end" data-sip-row-actions @click.stop>
                <button
                  type="button"
                  class="staff-action-btn staff-action-btn--more"
                  :class="{ 'is-open': rowMenu?.id === row.id }"
                  aria-label="Row actions"
                  @click="toggleRowMenu(row, $event)"
                >
                  <CrmIconRowActions variant="horizontal" />
                </button>
              </div>
            </div>

            <div class="sip-mobile-card__product">
              <div class="sip-mobile-card__thumb" aria-hidden="true">
                <img
                  v-if="row.image_url"
                  :src="row.image_url"
                  alt=""
                >
                <span v-else class="sip-mobile-card__thumb-empty">
                  <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                  </svg>
                </span>
              </div>
              <div class="sip-mobile-card__copy min-w-0">
                <div class="sip-mobile-card__name text-break">
                  {{ row.product_title || row.title || "—" }}
                </div>
                <div class="sip-mobile-card__sku text-break">{{ row.sku || "—" }}</div>
                <div class="sip-mobile-card__meta-grid">
                  <div class="sip-mobile-card__meta-pair">
                    <span class="sip-mobile-card__meta-label">Account</span>
                    <span class="sip-mobile-card__meta-value">{{ row.account_name || "—" }}</span>
                  </div>
                  <div v-if="viewType === 'inventory'" class="sip-mobile-card__meta-pair">
                    <span class="sip-mobile-card__meta-label">Bundle</span>
                    <span class="sip-mobile-card__meta-value">{{ row.bundle ? "Yes" : "No" }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="viewType === 'inventory'" class="sip-mobile-card__stats">
              <div class="sip-mobile-card__stat">
                <span class="sip-mobile-card__stat-label">On Hand</span>
                <span class="sip-mobile-card__stat-value">{{ Number(row.on_hand ?? row.available_total ?? 0).toLocaleString("en-US") }}</span>
              </div>
              <div class="sip-mobile-card__stat">
                <span class="sip-mobile-card__stat-label">Allocated</span>
                <span class="sip-mobile-card__stat-value">{{ Number(row.allocated ?? 0).toLocaleString("en-US") }}</span>
              </div>
              <div class="sip-mobile-card__stat">
                <span class="sip-mobile-card__stat-label">Backorder</span>
                <span class="sip-mobile-card__stat-value">{{ Number(row.backorder ?? 0).toLocaleString("en-US") }}</span>
              </div>
            </div>

            <div v-else class="crm-mobile-item-card__meta sip-mobile-card__extra-meta">
              <template v-if="viewType === 'locations'">
                <div class="crm-mobile-item-card__meta-row" @click.stop>
                  <span class="crm-mobile-item-card__meta-label">Pick</span>
                  <span class="crm-mobile-item-card__meta-value">
                    <ShopifyInventoryLocationCell :locations="locationGroup(row, 'pick')" :label="locationGroupLabel('pick')" />
                  </span>
                </div>
                <div class="crm-mobile-item-card__meta-row" @click.stop>
                  <span class="crm-mobile-item-card__meta-label">Backstock</span>
                  <span class="crm-mobile-item-card__meta-value">
                    <ShopifyInventoryLocationCell :locations="locationGroup(row, 'backstock')" :label="locationGroupLabel('backstock')" />
                  </span>
                </div>
                <div class="crm-mobile-item-card__meta-row" @click.stop>
                  <span class="crm-mobile-item-card__meta-label">Picking Cart</span>
                  <span class="crm-mobile-item-card__meta-value">
                    <ShopifyInventoryLocationCell :locations="locationGroup(row, 'other')" :label="locationGroupLabel('other')" />
                  </span>
                </div>
              </template>
              <template v-else-if="viewType === 'packaging'">
                <div class="crm-mobile-item-card__meta-row">
                  <span class="crm-mobile-item-card__meta-label">Packaging</span>
                  <span class="crm-mobile-item-card__meta-value">{{ packagingListLabel(row, "packaging_items", "packaging") }}</span>
                </div>
                <div class="crm-mobile-item-card__meta-row">
                  <span class="crm-mobile-item-card__meta-label">Materials</span>
                  <span class="crm-mobile-item-card__meta-value">{{ packagingListLabel(row, "packaging_materials", "packaging_material") }}</span>
                </div>
              </template>
              <template v-else-if="viewType === 'weights'">
                <div class="crm-mobile-item-card__meta-row">
                  <span class="crm-mobile-item-card__meta-label">Weight</span>
                  <span class="crm-mobile-item-card__meta-value">{{ weightLabel(row) }}</span>
                </div>
              </template>
              <template v-else>
                <div class="crm-mobile-item-card__meta-row">
                  <span class="crm-mobile-item-card__meta-label">Length</span>
                  <span class="crm-mobile-item-card__meta-value">{{ dimLabel(row.length, row.dimension_unit) }}</span>
                </div>
                <div class="crm-mobile-item-card__meta-row">
                  <span class="crm-mobile-item-card__meta-label">Width</span>
                  <span class="crm-mobile-item-card__meta-value">{{ dimLabel(row.width, row.dimension_unit) }}</span>
                </div>
                <div class="crm-mobile-item-card__meta-row">
                  <span class="crm-mobile-item-card__meta-label">Height</span>
                  <span class="crm-mobile-item-card__meta-value">{{ dimLabel(row.height, row.dimension_unit) }}</span>
                </div>
                <div class="crm-mobile-item-card__meta-row">
                  <span class="crm-mobile-item-card__meta-label">Cubic Ft</span>
                  <span class="crm-mobile-item-card__meta-value">{{ cubicFeetLabel(row) }}</span>
                </div>
              </template>
            </div>
          </article>
        </template>
      </div>

      <CrmListTableFooter
        :total="pagination.total"
        :current-page="pagination.current_page"
        :last-page="pagination.last_page"
        :per-page="pagination.per_page"
        :loading="loading"
        noun="products"
        @page="goPage"
        @per-page="onPerPageChange"
      />
    </div>

    <ShopifyInventorySyncAccountModal
      v-model:open="syncOpen"
      :accounts="accounts"
      @pushed="load"
    />
    <ShopifyInventoryImportProductsModal
      v-model:open="importOpen"
      :accounts="accounts"
      :client-account-id="accountId"
      @queued="onCsvQueued"
    />
    <ShopifyInventoryBulkEditModal
      v-model:open="bulkOpen"
      :client-account-id="accountId"
      @queued="onCsvQueued"
    />
    <ShopifyInventoryAddProductModal v-model:open="addOpen" />
    <ShopifyInventoryPackagingModal
      :open="packagingEditOpen"
      :busy="editBusy"
      :variant="editRow"
      @close="packagingEditOpen = false"
      @save="savePackaging"
    />
    <ShopifyInventoryViewBulkModal
      :open="viewBulkOpen"
      :busy="editBusy"
      :mode="viewType"
      :count="selectedIds.length"
      @close="viewBulkOpen = false"
      @save="saveViewBulk"
    />
    <ShopifyInventoryViewEditModal
      :open="viewEditOpen"
      :busy="editBusy"
      :mode="viewType === 'dimensions' ? 'dimensions' : viewType === 'locations' ? 'locations' : 'weight'"
      :row="editRow"
      @close="viewEditOpen = false"
      @save="saveViewEdit"
    />

    <Teleport to="body">
      <div
        v-if="rowMenu"
        data-sip-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${rowMenuRect.top}px`, left: `${rowMenuRect.left}px` }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openRow(rowMenu)">
          View Product
        </button>
        <button
          v-if="hasRowActions"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openViewEdit(rowMenu)"
        >
          Edit
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.sip-toolbar {
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
.sip-toolbar__controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  flex: 1 1 auto;
  min-width: 0;
}
.sip-account-select,
.sip-view-select {
  width: auto;
  min-width: 11rem;
  max-width: 16rem;
}
.sip-clear-filters {
  color: #2563eb;
  white-space: nowrap;
}
.sip-view-type {
  display: flex;
  align-items: center;
  margin-left: auto;
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
.sip-mobile-card__product {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  min-width: 0;
  margin-bottom: 0.7rem;
}
.sip-mobile-card__thumb {
  width: 3.25rem;
  height: 3.25rem;
  border-radius: 0.45rem;
  overflow: hidden;
  background: #f3f4f6;
  border: 1px solid #eceff3;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sip-mobile-card__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.sip-mobile-card__thumb-empty {
  color: #c0c4cc;
  display: inline-flex;
}
.sip-mobile-card__name {
  font-size: 0.9rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.3;
  margin-bottom: 0.1rem;
}
.sip-mobile-card__sku {
  font-size: 0.8125rem;
  font-weight: 600;
  color: #2563eb;
  line-height: 1.3;
  margin-bottom: 0.4rem;
}
.sip-mobile-card__meta-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.3rem 0.65rem;
}
.sip-mobile-card__meta-pair {
  min-width: 0;
}
.sip-mobile-card__meta-label {
  display: block;
  font-size: 0.68rem;
  font-weight: 500;
  color: #94a3b8;
  line-height: 1.2;
}
.sip-mobile-card__meta-value {
  display: block;
  font-size: 0.78rem;
  font-weight: 600;
  color: #1e293b;
  word-break: break-word;
}
.sip-mobile-card__stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  border-top: 1px solid #eef0f3;
  margin: 0 -1rem -0.75rem;
  padding: 0.65rem 0.25rem;
}
.sip-mobile-card__stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.15rem;
  padding: 0 0.3rem;
  border-right: 1px solid #eef0f3;
}
.sip-mobile-card__stat:last-child {
  border-right: 0;
}
.sip-mobile-card__stat-label {
  font-size: 0.62rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #94a3b8;
}
.sip-mobile-card__stat-value {
  font-size: 1rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.2;
}
.sip-mobile-card__extra-meta {
  margin-bottom: 0;
  border-top: 1px solid #eef0f3;
  padding-top: 0.75rem;
}
@media (max-width: 991.98px) {
  .sip-page-head {
    align-items: flex-start !important;
    margin-bottom: 0.85rem !important;
  }
  .sip-page-head__actions {
    flex-shrink: 0;
  }
  .sip-add-btn {
    width: 2.5rem;
    height: 2.5rem;
    padding: 0;
    border-radius: 0.55rem;
  }
  .sip-add-btn__label {
    display: none;
  }
  .sip-toolbar {
    flex-direction: column;
    align-items: stretch;
    gap: 0.55rem;
  }
  .sip-search-wrap {
    max-width: none;
    flex: none;
    width: 100%;
    min-width: 0;
  }
  .sip-search-btn {
    display: none;
  }
  .sip-toolbar__controls {
    flex-wrap: nowrap;
    gap: 0.35rem;
    width: 100%;
  }
  .sip-account-select,
  .sip-view-select {
    flex: 1 1 0;
    min-width: 0;
    max-width: none;
    width: auto;
    height: 2.25rem;
    padding: 0.25rem 1.75rem 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1.2;
    border-radius: 0.45rem;
  }
  .sip-view-type {
    margin-left: 0;
    flex: 1 1 0;
    min-width: 0;
  }
  .sip-view-type .sip-view-select {
    width: 100%;
  }
  .sip-toolbar__controls > [data-sip-filters],
  .sip-toolbar__controls > [data-sip-actions] {
    flex: 0 0 auto;
  }
  .sip-toolbar__controls .staff-toolbar-btn {
    height: 2.25rem;
    min-height: 2.25rem;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 0.45rem;
  }
  .sip-toolbar-more {
    width: 2.25rem;
    padding-inline: 0 !important;
    justify-content: center;
  }
}
</style>
