<script setup>
import {
  computed,
  inject,
  nextTick,
  onMounted,
  onUnmounted,
  reactive,
  ref,
  watch,
} from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const locations = ref([]);
const loading = ref(true);
const saving = ref(false);
const deleteBusy = ref(false);
const printing = ref(false);
const importing = ref(false);
const selectedIds = ref([]);
const formOpen = ref(false);
const csvOpen = ref(false);
const editing = ref(null);
const deleteTarget = ref(null);
const bulkDeleteOpen = ref(false);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const csvFile = ref(null);
const csvInput = ref(null);

const query = reactive({
  search: "",
  page: 1,
  per_page: DEFAULT_PER_PAGE,
  sort_by: "barcode",
  sort_dir: "asc",
});

const form = reactive({
  barcode: "",
  display_name: "",
});

let searchDebounce = null;
let searchWatchLock = false;

const canMutate = computed(() => {
  const u = crmUser?.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  const keys = Array.isArray(u.permission_keys) ? u.permission_keys : [];
  return (
    keys.includes("inventory_location_labels.update") ||
    keys.includes("inventory_location_labels.create") ||
    keys.includes("inventory_location_labels.delete") ||
    keys.includes("inventory.update")
  );
});

const canCreate = computed(() => {
  const u = crmUser?.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  const keys = Array.isArray(u.permission_keys) ? u.permission_keys : [];
  return (
    keys.includes("inventory_location_labels.create") ||
    keys.includes("inventory_location_labels.update") ||
    keys.includes("inventory.update")
  );
});

const canDelete = computed(() => {
  const u = crmUser?.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  const keys = Array.isArray(u.permission_keys) ? u.permission_keys : [];
  return (
    keys.includes("inventory_location_labels.delete") ||
    keys.includes("inventory_location_labels.update") ||
    keys.includes("inventory.update")
  );
});

const tableColspan = computed(() => (canMutate.value ? 4 : 3));
const pagination = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: DEFAULT_PER_PAGE,
});

const showingFrom = computed(() => {
  const total = pagination.value.total;
  if (total === 0) return 0;
  return (pagination.value.current_page - 1) * query.per_page + 1;
});

const showingTo = computed(() => {
  const total = pagination.value.total;
  if (total === 0) return 0;
  return Math.min(pagination.value.current_page * query.per_page, total);
});

const pageItems = computed(() => {
  const last = pagination.value.last_page;
  const cur = pagination.value.current_page;
  if (last < 1) return [];
  if (last <= 7) return Array.from({ length: last }, (_, i) => ({ type: "page", value: i + 1 }));

  const items = [{ type: "page", value: 1 }];
  const start = Math.max(2, cur - 1);
  const end = Math.min(last - 1, cur + 1);
  if (start > 2) items.push({ type: "gap" });
  for (let i = start; i <= end; i += 1) items.push({ type: "page", value: i });
  if (end < last - 1) items.push({ type: "gap" });
  items.push({ type: "page", value: last });
  return items;
});

const allPageSelected = computed(() => {
  if (locations.value.length === 0) return false;
  return locations.value.every((row) => selectedIds.value.includes(row.id));
});

const manageMenuLocation = computed(
  () => locations.value.find((row) => row.id === manageOpenId.value) ?? null,
);

watch(
  () => query.search,
  () => {
    if (searchWatchLock) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      loadLocations();
    }, 300);
  },
);

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Inventory | Location Labels",
    description: "Warehouse location barcodes and printable labels.",
  });
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", closeManageMenu, true);
  window.addEventListener("resize", closeManageMenu);
  await loadLocations();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", closeManageMenu, true);
  window.removeEventListener("resize", closeManageMenu);
  clearTimeout(searchDebounce);
});

async function loadLocations() {
  loading.value = true;
  try {
    const params = {
      page: query.page,
      per_page: query.per_page,
      sort_by: query.sort_by,
      sort_dir: query.sort_dir,
    };
    if (query.search.trim()) params.q = query.search.trim();

    const { data } = await api.get("/inventory/location-labels", { params });
    locations.value = Array.isArray(data?.locations) ? data.locations : [];
    pagination.value = {
      current_page: Number(data?.pagination?.current_page ?? query.page),
      last_page: Number(data?.pagination?.last_page ?? 1),
      per_page: Number(data?.pagination?.per_page ?? query.per_page),
      total: Number(data?.pagination?.total ?? locations.value.length),
    };
    const pageIds = new Set(locations.value.map((row) => row.id));
    selectedIds.value = selectedIds.value.filter((id) => pageIds.has(id));
  } catch (e) {
    toast.errorFrom(e, "Could not load location labels.");
  } finally {
    loading.value = false;
  }
}

function applySearch() {
  clearTimeout(searchDebounce);
  query.page = 1;
  loadLocations();
}

function clearSearch() {
  clearTimeout(searchDebounce);
  searchWatchLock = true;
  query.search = "";
  query.page = 1;
  loadLocations().finally(() => {
    searchWatchLock = false;
  });
}

function toggleSort(column) {
  if (query.sort_by === column) {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  } else {
    query.sort_by = column;
    query.sort_dir = "asc";
  }
  query.page = 1;
  loadLocations();
}

function sortIndicator(column) {
  if (query.sort_by !== column) return "";
  return query.sort_dir === "asc" ? "↑" : "↓";
}

function thAriaSort(column) {
  return query.sort_by === column
    ? query.sort_dir === "asc"
      ? "ascending"
      : "descending"
    : "none";
}

function goPage(page) {
  const next = Math.min(Math.max(Number(page) || 1, 1), pagination.value.last_page || 1);
  if (next === query.page) return;
  query.page = next;
  loadLocations();
}

function goFirstPage() {
  goPage(1);
}

function goLastPage() {
  goPage(pagination.value.last_page);
}

function onPerPageChange(e) {
  query.per_page = Number(e.target.value) || DEFAULT_PER_PAGE;
  query.page = 1;
  loadLocations();
}

function toggleSelect(id) {
  const index = selectedIds.value.indexOf(id);
  if (index >= 0) selectedIds.value.splice(index, 1);
  else selectedIds.value.push(id);
}

function toggleSelectAllPage() {
  if (allPageSelected.value) {
    const pageIds = new Set(locations.value.map((row) => row.id));
    selectedIds.value = selectedIds.value.filter((id) => !pageIds.has(id));
    return;
  }
  const merged = new Set(selectedIds.value);
  locations.value.forEach((row) => merged.add(row.id));
  selectedIds.value = Array.from(merged);
}

function openCreateModal() {
  editing.value = null;
  form.barcode = "";
  form.display_name = "";
  formOpen.value = true;
}

function openEditModal(row) {
  closeManageMenu();
  editing.value = row;
  form.barcode = row.barcode || "";
  form.display_name = row.display_name || "";
  formOpen.value = true;
}

function closeFormModal() {
  if (saving.value) return;
  formOpen.value = false;
  editing.value = null;
}

async function saveLocation() {
  if (!form.barcode.trim() || !form.display_name.trim()) {
    toast.error("Barcode and Display Name are required.");
    return;
  }
  saving.value = true;
  try {
    const payload = {
      barcode: form.barcode.trim(),
      display_name: form.display_name.trim(),
    };
    if (editing.value) {
      await api.patch(`/inventory/location-labels/${editing.value.id}`, payload);
      toast.success("Location Updated.");
    } else {
      await api.post("/inventory/location-labels", payload);
      toast.success("Location Created.");
    }
    formOpen.value = false;
    editing.value = null;
    await loadLocations();
  } catch (e) {
    toast.errorFrom(e, editing.value ? "Could not update location." : "Could not create location.");
  } finally {
    saving.value = false;
  }
}

function openDeleteModal(row) {
  closeManageMenu();
  deleteTarget.value = row;
}

function closeDeleteModal() {
  if (deleteBusy.value) return;
  deleteTarget.value = null;
}

async function confirmDelete() {
  if (!deleteTarget.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/inventory/location-labels/${deleteTarget.value.id}`);
    toast.success("Location Deleted.");
    selectedIds.value = selectedIds.value.filter((id) => id !== deleteTarget.value.id);
    deleteTarget.value = null;
    await loadLocations();
  } catch (e) {
    toast.errorFrom(e, "Could not delete location.");
  } finally {
    deleteBusy.value = false;
  }
}

function openBulkDelete() {
  if (selectedIds.value.length === 0) {
    toast.error("Select at least one location.");
    return;
  }
  bulkDeleteOpen.value = true;
}

async function confirmBulkDelete() {
  if (selectedIds.value.length === 0) return;
  deleteBusy.value = true;
  try {
    await api.post("/inventory/location-labels/bulk-delete", { ids: selectedIds.value });
    toast.success("Locations Deleted.");
    selectedIds.value = [];
    bulkDeleteOpen.value = false;
    await loadLocations();
  } catch (e) {
    toast.errorFrom(e, "Could not delete locations.");
  } finally {
    deleteBusy.value = false;
  }
}

async function printLabels(ids, labelType) {
  const list = Array.isArray(ids) ? ids.filter((id) => Number(id) > 0) : [];
  if (list.length === 0) {
    toast.error("Select at least one location.");
    return;
  }
  printing.value = true;
  closeManageMenu();
  try {
    await openApiPdfBlob(api, "/inventory/location-labels/print", {
      method: "post",
      data: { ids: list, label_type: labelType },
    });
    toast.success(labelType === "small" ? "Small Labels Ready." : "Large Labels Ready.");
  } catch (e) {
    toast.errorFrom(e, "Could not print location labels.");
  } finally {
    printing.value = false;
  }
}

function openCsvModal() {
  csvFile.value = null;
  csvOpen.value = true;
  nextTick(() => {
    if (csvInput.value) csvInput.value.value = "";
  });
}

function closeCsvModal() {
  if (importing.value) return;
  csvOpen.value = false;
  csvFile.value = null;
}

function onCsvChange(e) {
  const file = e.target?.files?.[0] || null;
  csvFile.value = file;
}

async function submitCsv() {
  if (!csvFile.value) {
    toast.error("Choose a CSV file to upload.");
    return;
  }
  importing.value = true;
  try {
    const body = new FormData();
    body.append("file", csvFile.value);
    const { data } = await api.post("/inventory/location-labels/import", body, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    const imported = Number(data?.imported ?? 0);
    const skipped = Number(data?.skipped ?? 0);
    toast.success(`Imported ${imported}. Skipped ${skipped}.`);
    if (Array.isArray(data?.errors) && data.errors.length) {
      toast.error(data.errors[0]);
    }
    csvOpen.value = false;
    csvFile.value = null;
    query.page = 1;
    await loadLocations();
  } catch (e) {
    toast.errorFrom(e, "Could not upload CSV.");
  } finally {
    importing.value = false;
  }
}

async function toggleManageMenu(id, event) {
  if (manageOpenId.value === id) {
    closeManageMenu();
    return;
  }
  manageOpenId.value = id;
  await nextTick();
  const btn = event?.currentTarget;
  if (!btn) return;
  const rect = btn.getBoundingClientRect();
  manageMenuRect.value = {
    top: Math.min(rect.bottom + 4, window.innerHeight - 180),
    left: Math.max(8, Math.min(rect.right - 180, window.innerWidth - 188)),
  };
}

function closeManageMenu() {
  manageOpenId.value = null;
}

function onDocClick(e) {
  if (!e.target.closest("[data-row-actions]")) closeManageMenu();
}
</script>

<template>
  <div class="staff-page staff-page--wide staff-directory-page">
    <div
      class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1 text-center text-md-start w-100">
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">
          Location Labels
        </h1>
        <p class="staff-page__intro mb-0">
          Search, create, and print warehouse location barcodes
        </p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 justify-content-center justify-content-md-end">
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-outline-secondary staff-toolbar-btn text-nowrap"
          :disabled="loading || importing"
          @click="openCsvModal"
        >
          Upload CSV
        </button>
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2 text-nowrap"
          :disabled="loading"
          @click="openCreateModal"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Create Location
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            id="location-labels-search"
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search barcode or display name"
            autocomplete="off"
            @keydown.enter.prevent="applySearch"
          />
          <div class="staff-toolbar-row-actions d-flex align-items-center gap-2 ms-md-auto flex-wrap">
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn"
              :disabled="loading || selectedIds.length === 0 || printing"
              @click="printLabels(selectedIds, 'large')"
            >
              Print Large
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn"
              :disabled="loading || selectedIds.length === 0 || printing"
              @click="printLabels(selectedIds, 'small')"
            >
              Print Small
            </button>
            <button
              v-if="canDelete"
              type="button"
              class="btn btn-outline-danger staff-toolbar-btn"
              :disabled="loading || selectedIds.length === 0 || deleteBusy"
              @click="openBulkDelete"
            >
              Delete
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn"
              :disabled="loading"
              @click="clearSearch"
            >
              Clear
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn"
              :disabled="loading"
              @click="loadLocations"
            >
              Refresh
            </button>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col" style="width: 3.5rem">
                <input
                  type="checkbox"
                  class="form-check-input"
                  :checked="allPageSelected"
                  :disabled="loading || locations.length === 0"
                  aria-label="Select all on page"
                  @change="toggleSelectAllPage"
                />
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('barcode')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('barcode')"
                >
                  Barcode
                  <span v-if="sortIndicator('barcode')" class="staff-sort-ind">{{
                    sortIndicator("barcode")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('display_name')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('display_name')"
                >
                  Display Name
                  <span v-if="sortIndicator('display_name')" class="staff-sort-ind">{{
                    sortIndicator("display_name")
                  }}</span>
                </button>
              </th>
              <th
                v-if="canMutate"
                class="staff-table-head__th staff-actions-col"
                scope="col"
                aria-sort="none"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading location labels..." />
                </div>
              </td>
            </tr>
            <tr v-for="row in locations" v-else :key="row.id" class="align-middle">
              <td class="text-center">
                <input
                  type="checkbox"
                  class="form-check-input"
                  :checked="selectedIds.includes(row.id)"
                  :aria-label="`Select ${row.barcode}`"
                  @change="toggleSelect(row.id)"
                />
              </td>
              <td class="fw-semibold text-body text-nowrap">{{ row.barcode }}</td>
              <td class="text-body">{{ row.display_name || "—" }}</td>
              <td v-if="canMutate" class="staff-actions-cell text-center">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    :disabled="printing || deleteBusy"
                    @click="toggleManageMenu(row.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && locations.length === 0">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">
                No location labels found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>

      <div
        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer"
      >
        <div
          class="d-flex flex-column flex-sm-row align-items-sm-center gap-2 gap-sm-4 flex-wrap order-2 order-lg-1 justify-content-center justify-content-lg-start"
        >
          <p class="small text-secondary mb-0 text-center text-sm-start">
            Showing <span class="fw-semibold text-body">{{ showingFrom }}</span>
            to <span class="fw-semibold text-body">{{ showingTo }}</span>
            of <span class="fw-semibold text-body">{{ pagination.total }}</span> entries
            <span v-if="selectedIds.length" class="ms-1">
              · <span class="fw-semibold text-body">{{ selectedIds.length }}</span> selected
            </span>
          </p>
          <div class="d-flex align-items-center gap-2 justify-content-center justify-content-sm-start">
            <label class="small text-secondary text-nowrap mb-0" for="location-labels-per-page">
              Rows per page
            </label>
            <select
              id="location-labels-per-page"
              class="form-select form-select-sm staff-table-footer-per-page"
              :value="query.per_page"
              :disabled="loading"
              @change="onPerPageChange"
            >
              <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
            </select>
          </div>
        </div>
        <nav
          class="order-1 order-lg-2 d-flex justify-content-center justify-content-lg-end ms-lg-auto flex-shrink-0"
          aria-label="Location labels pages"
        >
          <div class="staff-page-pager staff-page-pager--cluster">
            <div class="staff-page-pager__start">
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page <= 1"
                aria-label="First page"
                @click="goFirstPage"
              >
                <span aria-hidden="true">«</span>
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page <= 1"
                aria-label="Previous page"
                @click="goPage(pagination.current_page - 1)"
              >
                <span aria-hidden="true">‹</span>
              </button>
            </div>
            <div class="staff-page-pager__pages">
              <div class="staff-page-pager-inner d-flex align-items-center">
                <template v-for="(item, idx) in pageItems" :key="'pi-' + idx">
                  <span v-if="item.type === 'gap'" class="px-1 small text-secondary user-select-none"
                    >…</span
                  >
                  <button
                    v-else
                    type="button"
                    class="staff-page-pager-tile"
                    :class="{ 'staff-page-pager-tile--active': item.value === pagination.current_page }"
                    :disabled="loading"
                    @click="goPage(item.value)"
                  >
                    {{ item.value }}
                  </button>
                </template>
              </div>
            </div>
            <div class="staff-page-pager__end">
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page >= pagination.last_page"
                aria-label="Next page"
                @click="goPage(pagination.current_page + 1)"
              >
                <span aria-hidden="true">›</span>
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page >= pagination.last_page"
                aria-label="Last page"
                @click="goLastPage"
              >
                <span aria-hidden="true">»</span>
              </button>
            </div>
          </div>
        </nav>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageOpenId !== null && manageMenuLocation"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        :style="{ top: manageMenuRect.top + 'px', left: manageMenuRect.left + 'px' }"
        role="menu"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openEditModal(manageMenuLocation)"
        >
          Edit
        </button>
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          :disabled="printing"
          @click="printLabels([manageMenuLocation.id], 'large')"
        >
          Print Large
        </button>
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          :disabled="printing"
          @click="printLabels([manageMenuLocation.id], 'small')"
        >
          Print Small
        </button>
        <button
          v-if="canDelete"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteModal(manageMenuLocation)"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <ConfirmModal
      :open="formOpen"
      :title="editing ? 'Edit Location' : 'Create Location'"
      confirm-label="Save Location"
      cancel-label="Cancel"
      :danger="false"
      :busy="saving"
      form
      @close="closeFormModal"
      @confirm="saveLocation"
    >
      <div class="mb-3">
        <label class="form-label" for="location-label-barcode">Barcode</label>
        <input
          id="location-label-barcode"
          v-model="form.barcode"
          type="text"
          class="form-control"
          maxlength="255"
          required
          :disabled="saving"
        />
      </div>
      <div>
        <label class="form-label" for="location-label-display">Display Name</label>
        <input
          id="location-label-display"
          v-model="form.display_name"
          type="text"
          class="form-control"
          maxlength="255"
          required
          :disabled="saving"
        />
      </div>
    </ConfirmModal>

    <ConfirmModal
      :open="csvOpen"
      title="Upload CSV"
      subtitle="CSV columns: Barcode, Display Name"
      confirm-label="Upload CSV"
      cancel-label="Cancel"
      :danger="false"
      :busy="importing"
      form
      @close="closeCsvModal"
      @confirm="submitCsv"
    >
      <p class="small text-secondary mb-3">
        Upload a .csv file with Barcode and Display Name as the first two columns.
        Existing barcodes are skipped.
      </p>
      <input
        ref="csvInput"
        type="file"
        class="form-control"
        accept=".csv,text/csv,text/plain"
        :disabled="importing"
        @change="onCsvChange"
      />
    </ConfirmModal>

    <ConfirmModal
      :open="deleteTarget !== null"
      title="Delete Location"
      :message="
        deleteTarget
          ? `Delete ${deleteTarget.display_name || deleteTarget.barcode}? This can be restored only by re-creating it.`
          : ''
      "
      confirm-label="Delete Location"
      :busy="deleteBusy"
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />

    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Delete Locations"
      :message="`Delete ${selectedIds.length} selected location${selectedIds.length === 1 ? '' : 's'}?`"
      confirm-label="Delete Locations"
      :busy="deleteBusy"
      @close="bulkDeleteOpen = false"
      @confirm="confirmBulkDelete"
    />
  </div>
</template>
