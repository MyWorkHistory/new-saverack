<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyInventorySubnav from "../../components/shopify/ShopifyInventorySubnav.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const MENU_W = 168;
const MENU_H = 120;
const PER_PAGE_OPTIONS = [10, 25, 50, 100];

const router = useRouter();
const toast = useToast();
const loading = ref(false);
const busy = ref(false);
const rows = ref([]);
const types = ref([]);
const q = ref("");
const filters = reactive({ type: "all", pickable: "all", sellable: "all" });
const filterMenuOpen = ref(false);
const actionsMenuOpen = ref(false);
const selectedIds = ref([]);
const sort = ref("name");
const dir = ref("asc");
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 10 });
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const importInput = ref(null);

const addOpen = ref(false);
const editOpen = ref(false);
const bulkOpen = ref(false);
const form = reactive({ name: "", type: "Large Bin", pickable: true, sellable: true });
const editingId = ref(null);
const bulkForm = reactive({ field: "type", type: "Large Bin", pickable: true, sellable: true });

const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);
const allSelected = computed(
  () => rows.value.length > 0 && rows.value.every((r) => selectedIds.value.includes(r.id)),
);
const showingFrom = computed(() => {
  if (!pagination.value.total) return 0;
  return (pagination.value.current_page - 1) * pagination.value.per_page + 1;
});
const showingTo = computed(() => {
  if (!pagination.value.total) return 0;
  return Math.min(pagination.value.current_page * pagination.value.per_page, pagination.value.total);
});
const pageItems = computed(() => {
  const last = pagination.value.last_page;
  const cur = pagination.value.current_page;
  const pages = [];
  const start = Math.max(1, cur - 1);
  const end = Math.min(last, start + 2);
  for (let i = start; i <= end; i += 1) pages.push(i);
  return pages;
});

function filterParams() {
  return {
    q: q.value || undefined,
    type: filters.type !== "all" ? filters.type : undefined,
    pickable: filters.pickable !== "all" ? filters.pickable : undefined,
    sellable: filters.sellable !== "all" ? filters.sellable : undefined,
    sort: sort.value,
    dir: dir.value,
    per_page: pagination.value.per_page,
    page: pagination.value.current_page,
  };
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/shopify/locations", { params: filterParams() });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.meta?.current_page || 1,
      last_page: data?.meta?.last_page || 1,
      total: data?.meta?.total || 0,
      per_page: data?.meta?.per_page || pagination.value.per_page,
    };
    selectedIds.value = selectedIds.value.filter((id) => rows.value.some((r) => r.id === id));
  } catch (e) {
    toast.errorFrom(e, "Could not load locations.");
  } finally {
    loading.value = false;
  }
}

function toggleSort(col) {
  if (sort.value === col) {
    dir.value = dir.value === "asc" ? "desc" : "asc";
  } else {
    sort.value = col;
    dir.value = "asc";
  }
  pagination.value.current_page = 1;
  void load();
}

function applySearch() {
  pagination.value.current_page = 1;
  void load();
}

function applyFilters() {
  filterMenuOpen.value = false;
  pagination.value.current_page = 1;
  void load();
}

function resetFilters() {
  filters.type = "all";
  filters.pickable = "all";
  filters.sellable = "all";
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  pagination.value.current_page = p;
  void load();
}

function onPerPageChange(e) {
  pagination.value.per_page = Number(e.target.value) || 10;
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

function openRow(row) {
  if (!row?.id) return;
  manageOpenId.value = null;
  router.push({ name: "shopify-location-detail", params: { id: String(row.id) } });
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
  if (manageOpenId.value === row?.id) {
    manageOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  manageOpenId.value = row.id;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-shopify-loc-row-actions]")) manageOpenId.value = null;
  if (!e.target?.closest?.("[data-shopify-loc-filters]")) filterMenuOpen.value = false;
  if (!e.target?.closest?.("[data-shopify-loc-actions]")) actionsMenuOpen.value = false;
}

function openAdd() {
  form.name = "";
  form.type = types.value[0] || "Large Bin";
  form.pickable = true;
  form.sellable = true;
  addOpen.value = true;
}

function openEdit(row) {
  editingId.value = row.id;
  form.name = row.name || "";
  form.type = row.type || types.value[0] || "Large Bin";
  form.pickable = Boolean(row.pickable);
  form.sellable = Boolean(row.sellable);
  editOpen.value = true;
  manageOpenId.value = null;
}

async function saveLocation(isEdit) {
  const name = String(form.name || "").trim();
  if (!name) {
    toast.error("Location name is required.");
    return;
  }
  busy.value = true;
  try {
    if (isEdit) {
      await api.patch(`/shopify/locations/${editingId.value}`, { ...form, name });
      toast.success("Location updated.");
      editOpen.value = false;
    } else {
      await api.post("/shopify/locations", { ...form, name });
      toast.success("Location added.");
      addOpen.value = false;
    }
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not save location.");
  } finally {
    busy.value = false;
  }
}

async function toggleFlag(row, field) {
  const next = !row[field];
  row[field] = next;
  try {
    await api.patch(`/shopify/locations/${row.id}`, { [field]: next });
  } catch (e) {
    row[field] = !next;
    toast.errorFrom(e, "Could not update location.");
  }
}

async function deleteRow(row) {
  if (!window.confirm(`Delete location ${row.name}?`)) return;
  manageOpenId.value = null;
  try {
    await api.delete(`/shopify/locations/${row.id}`);
    toast.success("Location deleted.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete location.");
  }
}

async function applyBulk() {
  if (!selectedIds.value.length) return;
  const payload = { ids: selectedIds.value };
    if (bulkForm.field === "type") payload.type = bulkForm.type;
    if (bulkForm.field === "pickable") {
      payload.pickable = bulkForm.pickable === true || bulkForm.pickable === "true";
    }
    if (bulkForm.field === "sellable") {
      payload.sellable = bulkForm.sellable === true || bulkForm.sellable === "true";
    }
  busy.value = true;
  try {
    await api.post("/shopify/locations/bulk", payload);
    toast.success("Locations updated.");
    bulkOpen.value = false;
    selectedIds.value = [];
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not bulk edit locations.");
  } finally {
    busy.value = false;
  }
}

async function exportCsv(selectedOnly) {
  actionsMenuOpen.value = false;
  try {
    const params = filterParams();
    if (selectedOnly && selectedIds.value.length) {
      params.ids = selectedIds.value.join(",");
    }
    const { data } = await api.get("/shopify/locations/export", { params, responseType: "blob" });
    const url = URL.createObjectURL(data);
    const a = document.createElement("a");
    a.href = url;
    a.download = "shopify-locations.csv";
    a.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    toast.errorFrom(e, "Could not export locations.");
  }
}

async function onImportFile(e) {
  const file = e.target?.files?.[0];
  e.target.value = "";
  if (!file) return;
  const fd = new FormData();
  fd.append("file", file);
  busy.value = true;
  try {
    const { data } = await api.post("/shopify/locations/import", fd);
    toast.success(`Imported ${data?.created || 0} locations (${data?.updated || 0} updated).`);
    await load();
  } catch (err) {
    toast.errorFrom(err, "Could not import CSV.");
  } finally {
    busy.value = false;
    actionsMenuOpen.value = false;
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Locations",
    description: "Manage Shopify warehouse locations.",
  });
  document.addEventListener("click", onDocClick);
  try {
    const { data } = await api.get("/shopify/locations/meta");
    types.value = Array.isArray(data?.types) ? data.types : [];
  } catch (_) {
    types.value = ["Large Bin", "Medium Bin", "Small Bin", "Large Pallet", "Medium Pallet", "Small Pallet"];
  }
  void load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Locations</h1>
        <p class="small text-secondary mb-0">Manage and organize all your warehouse locations.</p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <button
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="openAdd"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Location
        </button>
        <div class="position-relative" data-shopify-loc-actions>
          <button
            type="button"
            class="btn btn-outline-secondary orders-toolbar-outline-btn dropdown-toggle"
            :aria-expanded="actionsMenuOpen"
            @click.stop="actionsMenuOpen = !actionsMenuOpen"
          >
            Actions
          </button>
          <div
            v-if="actionsMenuOpen"
            class="dropdown-menu dropdown-menu-end show shadow border py-1"
            style="position: absolute; top: calc(100% + 0.25rem); right: 0; z-index: 1090; min-width: 12.5rem"
          >
            <button type="button" class="dropdown-item small d-flex align-items-center gap-2" @click="importInput?.click()">
              Import Locations
            </button>
            <button type="button" class="dropdown-item small d-flex align-items-center gap-2" @click="exportCsv(false)">
              Export Locations
            </button>
          </div>
        </div>
        <input ref="importInput" type="file" accept=".csv,text/csv" class="d-none" @change="onImportFile" />
      </div>
    </div>

    <ShopifyInventorySubnav locations-active />

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row shopify-loc-toolbar">
          <div class="shopify-loc-search">
            <div class="input-group orders-toolbar-search-group">
              <span class="input-group-text bg-white">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z" />
                </svg>
              </span>
              <input
                v-model="q"
                type="search"
                class="form-control"
                placeholder="Search by location name..."
                autocomplete="off"
                :disabled="loading"
                @keydown.enter.prevent="applySearch"
              />
            </div>
          </div>
          <div class="position-relative flex-shrink-0" data-shopify-loc-filters>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4" />
              </svg>
              Filters
            </button>
            <div
              v-if="filterMenuOpen"
              class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown"
              style="position: absolute; top: calc(100% + 0.25rem); left: 0; z-index: 1090"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button type="button" class="btn btn-link btn-sm staff-bulk-clear-link text-decoration-none p-0" @click="resetFilters">
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="loc-filter-type">Type</label>
                <select id="loc-filter-type" v-model="filters.type" class="form-select mb-3">
                  <option value="all">All</option>
                  <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                </select>
                <label class="form-label" for="loc-filter-pickable">Pickable</label>
                <select id="loc-filter-pickable" v-model="filters.pickable" class="form-select mb-3">
                  <option value="all">All</option>
                  <option value="1">Yes</option>
                  <option value="0">No</option>
                </select>
                <label class="form-label" for="loc-filter-sellable">Sellable</label>
                <select id="loc-filter-sellable" v-model="filters.sellable" class="form-select mb-3">
                  <option value="all">All</option>
                  <option value="1">Yes</option>
                  <option value="0">No</option>
                </select>
                <button type="button" class="btn btn-primary btn-sm w-100" @click="applyFilters">Apply</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div
        v-if="selectedIds.length > 0"
        class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
      >
        <span class="small staff-bulk-selection-bar__count">{{ selectedIds.length }} locations selected</span>
        <button type="button" class="btn btn-sm staff-page-primary" @click="bulkOpen = true">Bulk Edit</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="exportCsv(true)">Export</button>
        <button type="button" class="btn btn-link btn-sm staff-bulk-clear-link ms-auto text-decoration-none" @click="selectedIds = []">
          Clear Selection
        </button>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" style="width: 2.5rem">
                <input type="checkbox" class="form-check-input" :checked="allSelected" @change="toggleSelectAll" />
              </th>
              <th class="staff-table-head__th">
                <button type="button" class="staff-sort-btn" @click="toggleSort('name')">Location Name</button>
              </th>
              <th class="staff-table-head__th">
                <button type="button" class="staff-sort-btn" @click="toggleSort('type')">Type</button>
              </th>
              <th class="staff-table-head__th">
                <button type="button" class="staff-sort-btn" @click="toggleSort('pickable')">Pickable</button>
              </th>
              <th class="staff-table-head__th">
                <button type="button" class="staff-sort-btn" @click="toggleSort('sellable')">Sellable</button>
              </th>
              <th class="staff-table-head__th staff-actions-col text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading Locations…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="6" class="px-4 py-5 text-center text-secondary">No locations yet. Add a location or import a CSV.</td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle shopify-loc-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td @click.stop>
                <input
                  type="checkbox"
                  class="form-check-input"
                  :checked="selectedIds.includes(row.id)"
                  @change="toggleSelect(row.id)"
                />
              </td>
              <td class="fw-semibold text-body">{{ row.name }}</td>
              <td>{{ row.type || "—" }}</td>
              <td @click.stop>
                <button
                  type="button"
                  class="inventory-detail__toggle"
                  :class="row.pickable ? 'inventory-detail__toggle--on' : 'inventory-detail__toggle--off'"
                  @click="toggleFlag(row, 'pickable')"
                >
                  <span class="inventory-detail__toggle-track"><span class="inventory-detail__toggle-thumb" /></span>
                </button>
              </td>
              <td @click.stop>
                <button
                  type="button"
                  class="inventory-detail__toggle"
                  :class="row.sellable ? 'inventory-detail__toggle--on' : 'inventory-detail__toggle--off'"
                  @click="toggleFlag(row, 'sellable')"
                >
                  <span class="inventory-detail__toggle-track"><span class="inventory-detail__toggle-thumb" /></span>
                </button>
              </td>
              <td class="staff-actions-cell text-center" @click.stop>
                <div data-shopify-loc-row-actions class="staff-actions-inner staff-actions-inner--single justify-content-center">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
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

      <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer">
        <p class="small text-secondary mb-0">
          Showing
          <span class="fw-semibold text-body">{{ showingFrom }}</span>
          to
          <span class="fw-semibold text-body">{{ showingTo }}</span>
          of
          <span class="fw-semibold text-body">{{ pagination.total }}</span>
          locations.
        </p>
        <div class="d-flex align-items-center gap-3">
          <select class="form-select form-select-sm staff-table-footer-per-page" :value="pagination.per_page" @change="onPerPageChange">
            <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }} per page</option>
          </select>
          <nav class="staff-page-pager staff-page-pager--cluster" aria-label="Locations pages">
            <button type="button" class="staff-page-pager-tile staff-page-pager-tile--nav" :disabled="pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">‹</button>
            <button
              v-for="p in pageItems"
              :key="p"
              type="button"
              class="staff-page-pager-tile"
              :class="{ 'staff-page-pager-tile--active': p === pagination.current_page }"
              @click="goPage(p)"
            >
              {{ p }}
            </button>
            <button type="button" class="staff-page-pager-tile staff-page-pager-tile--nav" :disabled="pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">›</button>
          </nav>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-shopify-loc-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openRow(manageMenuRow)">View</button>
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openEdit(manageMenuRow)">Edit</button>
        <button type="button" class="staff-row-menu__item text-danger" role="menuitem" @click="deleteRow(manageMenuRow)">Delete</button>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="addOpen || editOpen" class="crm-vx-modal-overlay" @click.self="addOpen = false; editOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm shopify-loc-modal" @click.stop>
          <button type="button" class="crm-vx-modal__close" aria-label="Close" @click="addOpen = false; editOpen = false">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">{{ editOpen ? "Edit Location" : "Add Location" }}</h2>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="loc-name">Location Name</label>
            <input id="loc-name" v-model="form.name" type="text" class="form-control mb-3" placeholder="A-01-042" />
            <label class="form-label" for="loc-type">Type</label>
            <select id="loc-type" v-model="form.type" class="form-select mb-3">
              <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
            </select>
            <label class="form-label d-flex align-items-center justify-content-between">
              Pickable
              <input v-model="form.pickable" type="checkbox" class="form-check-input" />
            </label>
            <label class="form-label d-flex align-items-center justify-content-between mb-0">
              Sellable
              <input v-model="form.sellable" type="checkbox" class="form-check-input" />
            </label>
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="addOpen = false; editOpen = false">
              Cancel
            </button>
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="saveLocation(editOpen)">
              {{ busy ? "Please Wait…" : editOpen ? "Save" : "Add Location" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="bulkOpen" class="crm-vx-modal-overlay" @click.self="bulkOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">Bulk Edit</h2>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="bulk-field">Field</label>
            <select id="bulk-field" v-model="bulkForm.field" class="form-select mb-3">
              <option value="type">Type</option>
              <option value="pickable">Pickable</option>
              <option value="sellable">Sellable</option>
            </select>
            <template v-if="bulkForm.field === 'type'">
              <label class="form-label" for="bulk-type">Type</label>
              <select id="bulk-type" v-model="bulkForm.type" class="form-select">
                <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
              </select>
            </template>
            <template v-else-if="bulkForm.field === 'pickable'">
              <label class="form-label" for="bulk-pickable">Pickable</label>
              <select id="bulk-pickable" v-model="bulkForm.pickable" class="form-select">
                <option :value="true">Yes</option>
                <option :value="false">No</option>
              </select>
            </template>
            <template v-else>
              <label class="form-label" for="bulk-sellable">Sellable</label>
              <select id="bulk-sellable" v-model="bulkForm.sellable" class="form-select">
                <option :value="true">Yes</option>
                <option :value="false">No</option>
              </select>
            </template>
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="bulkOpen = false">Cancel</button>
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="applyBulk">
              {{ busy ? "Please Wait…" : "Apply" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.shopify-loc-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}
.shopify-loc-search {
  width: min(22rem, 100%);
}
.shopify-loc-row {
  cursor: pointer;
}
.inventory-detail__toggle {
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 999px;
  padding: 0.15rem;
  display: inline-flex;
  background: #fff;
}
.inventory-detail__toggle-track {
  width: 34px;
  height: 20px;
  border-radius: 999px;
  background: #d1d5db;
  position: relative;
}
.inventory-detail__toggle-thumb {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #fff;
  position: absolute;
  left: 3px;
  top: 3px;
  transition: transform 0.15s ease;
}
.inventory-detail__toggle--on .inventory-detail__toggle-track {
  background: #22c55e;
}
.inventory-detail__toggle--on .inventory-detail__toggle-thumb {
  transform: translateX(14px);
}
.inventory-detail__toggle--off .inventory-detail__toggle-track {
  background: #ef4444;
}
</style>
