<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmStatusUpdateModal from "../../components/common/CrmStatusUpdateModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";

const MENU_W = 160;
const MENU_H = 96;

const toast = useToast();
const loading = ref(false);
const busy = ref(false);
const rows = ref([]);
const users = ref([]);
const q = ref("");
const userId = ref("");
const filterMenuOpen = ref(false);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: DEFAULT_PER_PAGE });

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const addOpen = ref(false);
const updateOpen = ref(false);
const editOpen = ref(false);
const addLines = ref("");
const updateLines = ref("");
const editNumber = ref("");
const editTarget = ref(null);

const statusOpen = ref(false);
const statusValue = ref("pending");
const statusTarget = ref(null);
const statusBusy = ref(false);

const deleteOpen = ref(false);
const deleteTarget = ref(null);

const STATUS_OPTIONS = [
  { value: "pending", label: "Pending" },
  { value: "completed", label: "Completed" },
];

const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);

const showingFrom = computed(() => {
  if (!pagination.value.total) return 0;
  return (pagination.value.current_page - 1) * pagination.value.per_page + 1;
});
const showingTo = computed(() => {
  if (!pagination.value.total) return 0;
  return Math.min(pagination.value.current_page * pagination.value.per_page, pagination.value.total);
});

function statusLabel(status) {
  return status === "completed" ? "Completed" : "Pending";
}

function statusBadgeClass(status) {
  return status === "completed"
    ? "bg-success-subtle text-success-emphasis"
    : "bg-secondary-subtle text-secondary-emphasis";
}

async function loadMeta() {
  try {
    const { data } = await api.get("/order-batches/meta");
    users.value = Array.isArray(data?.users) ? data.users : [];
  } catch (e) {
    users.value = [];
    toast.errorFrom(e, "Could not load batch filters.");
  }
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/order-batches", {
      params: {
        q: q.value || undefined,
        user_id: userId.value || undefined,
        per_page: pagination.value.per_page,
        page: pagination.value.current_page,
      },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.meta?.current_page || 1,
      last_page: data?.meta?.last_page || 1,
      total: data?.meta?.total || 0,
      per_page: data?.meta?.per_page || pagination.value.per_page,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load batches.");
  } finally {
    loading.value = false;
  }
}

function applySearch() {
  pagination.value.current_page = 1;
  void load();
}

function onUserFilterChange() {
  pagination.value.current_page = 1;
  void load();
}

function resetToolbarFilters() {
  userId.value = "";
  filterMenuOpen.value = false;
  pagination.value.current_page = 1;
  void load();
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  pagination.value.current_page = p;
  void load();
}

function onPerPageChange(e) {
  pagination.value.per_page = Number(e.target.value) || DEFAULT_PER_PAGE;
  pagination.value.current_page = 1;
  void load();
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
  if (!e.target?.closest?.("[data-order-batch-row-actions]")) {
    manageOpenId.value = null;
  }
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
}

function openAdd() {
  addLines.value = "";
  addOpen.value = true;
}

function openUpdate() {
  updateLines.value = "";
  updateOpen.value = true;
}

function openEdit(row) {
  manageOpenId.value = null;
  editTarget.value = row;
  editNumber.value = row?.batch_number || "";
  editOpen.value = true;
}

function openStatus(row) {
  statusTarget.value = row;
  statusValue.value = row?.status === "completed" ? "completed" : "pending";
  statusOpen.value = true;
}

function openDelete(row) {
  manageOpenId.value = null;
  deleteTarget.value = row;
  deleteOpen.value = true;
}

async function submitAdd() {
  busy.value = true;
  try {
    const { data } = await api.post("/order-batches", { lines: addLines.value });
    const created = Number(data?.created || 0);
    const skipped = Number(data?.skipped || 0);
    toast.success(
      skipped > 0
        ? `Created ${created}, skipped ${skipped} duplicate${skipped === 1 ? "" : "s"}.`
        : `Created ${created} batch${created === 1 ? "" : "es"}.`,
    );
    addOpen.value = false;
    pagination.value.current_page = 1;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not add batches.");
  } finally {
    busy.value = false;
  }
}

async function submitUpdateBatch() {
  busy.value = true;
  try {
    const { data } = await api.post("/order-batches/complete", { lines: updateLines.value });
    const updated = Number(data?.updated || 0);
    const missing = Number(data?.missing_count || 0);
    const already = Number(data?.already_completed || 0);
    const parts = [`Updated ${updated}`];
    if (already > 0) parts.push(`${already} already completed`);
    if (missing > 0) parts.push(`${missing} missing`);
    toast.success(parts.join("; ") + ".");
    updateOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not update batches.");
  } finally {
    busy.value = false;
  }
}

async function submitEdit() {
  if (!editTarget.value?.id) return;
  busy.value = true;
  try {
    await api.patch(`/order-batches/${editTarget.value.id}`, {
      batch_number: editNumber.value,
    });
    toast.success("Batch updated.");
    editOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not edit batch.");
  } finally {
    busy.value = false;
  }
}

async function saveStatus() {
  if (!statusTarget.value?.id) return;
  statusBusy.value = true;
  try {
    await api.patch(`/order-batches/${statusTarget.value.id}`, {
      status: statusValue.value,
    });
    toast.success("Status updated.");
    statusOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    statusBusy.value = false;
  }
}

async function confirmDelete() {
  if (!deleteTarget.value?.id) return;
  busy.value = true;
  try {
    await api.delete(`/order-batches/${deleteTarget.value.id}`);
    toast.success("Batch deleted.");
    deleteOpen.value = false;
    deleteTarget.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete batch.");
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Batch",
    description: "Track order batch numbers.",
  });
  document.addEventListener("click", onDocClick);
  void loadMeta();
  void load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Batch</h1>
        <p class="small text-secondary mb-0">Track batch numbers as pending or completed.</p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <button
          type="button"
          class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
          @click="openUpdate"
        >
          Update Batch
        </button>
        <button
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="openAdd"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Batch
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row order-batch-toolbar">
          <div class="order-batch-search flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                v-model="q"
                type="search"
                class="form-control"
                placeholder="Search by Batch #"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search by batch number"
                :disabled="loading"
                @keydown.enter.prevent="applySearch"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn fw-semibold"
                :disabled="loading"
                @click="applySearch"
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
              aria-haspopup="true"
              aria-controls="order-batch-filter-panel"
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
              id="order-batch-filter-panel"
              class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Batch filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  :disabled="loading"
                  @click="resetToolbarFilters"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="order-batch-filter-user">User</label>
                <select
                  id="order-batch-filter-user"
                  v-model="userId"
                  class="form-select staff-datatable-filters__select"
                  :disabled="loading"
                  @change="onUserFilterChange"
                >
                  <option value="">All Users</option>
                  <option v-for="u in users" :key="u.id" :value="String(u.id)">
                    {{ u.name }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap d-none d-lg-block">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Status</th>
              <th class="staff-table-head__th" scope="col">Batch #</th>
              <th class="staff-table-head__th" scope="col">User</th>
              <th class="staff-table-head__th staff-actions-col text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="4" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading Batches…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="4" class="px-4 py-5 text-center text-secondary">
                No batches yet. Add Batch to create entries.
              </td>
            </tr>
            <tr v-for="row in rows" v-else :key="row.id" class="align-middle">
              <td>
                <button
                  type="button"
                  class="badge rounded-pill border-0 staff-status-badge"
                  :class="statusBadgeClass(row.status)"
                  @click="openStatus(row)"
                >
                  {{ statusLabel(row.status) }}
                </button>
              </td>
              <td class="fw-semibold text-body">{{ row.batch_number }}</td>
              <td class="text-body">{{ row.user_name || "—" }}</td>
              <td class="staff-actions-cell text-center" @click.stop>
                <div
                  data-order-batch-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
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

      <div class="crm-mobile-item-cards d-lg-none" aria-label="Batches">
        <div v-if="loading" class="crm-mobile-item-card__empty">
          <div class="d-flex justify-content-center py-3">
            <CrmLoadingSpinner message="Loading Batches…" />
          </div>
        </div>
        <div v-else-if="!rows.length" class="crm-mobile-item-card__empty">
          No batches yet. Add Batch to create entries.
        </div>
        <template v-else>
          <article v-for="row in rows" :key="`m-${row.id}`" class="crm-mobile-item-card">
            <div class="crm-mobile-item-card__head">
              <div class="crm-mobile-item-card__head-start">
                <button
                  type="button"
                  class="badge rounded-pill border-0 staff-status-badge"
                  :class="statusBadgeClass(row.status)"
                  @click="openStatus(row)"
                >
                  {{ statusLabel(row.status) }}
                </button>
              </div>
              <div
                class="crm-mobile-item-card__head-end"
                data-order-batch-row-actions
                @click.stop
              >
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
            </div>
            <div class="crm-mobile-item-card__product">
              <div class="crm-mobile-item-card__name">{{ row.batch_number }}</div>
            </div>
            <div class="crm-mobile-item-card__meta">
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">User</span>
                <span class="crm-mobile-item-card__meta-value">{{ row.user_name || "—" }}</span>
              </div>
            </div>
          </article>
        </template>
      </div>

      <div
        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer"
      >
        <p class="small text-secondary mb-0">
          Showing
          <span class="fw-semibold text-body">{{ showingFrom }}</span>
          to
          <span class="fw-semibold text-body">{{ showingTo }}</span>
          of
          <span class="fw-semibold text-body">{{ pagination.total }}</span>
          batches.
        </p>
        <div class="d-flex align-items-center gap-3 flex-wrap justify-content-end">
          <select
            class="form-select form-select-sm staff-table-footer-per-page"
            :value="pagination.per_page"
            @change="onPerPageChange"
          >
            <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }} per page</option>
          </select>
          <div class="d-flex gap-1">
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :disabled="pagination.current_page <= 1"
              @click="goPage(pagination.current_page - 1)"
            >
              Prev
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :disabled="pagination.current_page >= pagination.last_page"
              @click="goPage(pagination.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-order-batch-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openEdit(manageMenuRow)">
          Edit
        </button>
        <button
          type="button"
          class="staff-row-menu__item text-danger"
          role="menuitem"
          @click="openDelete(manageMenuRow)"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="addOpen" class="crm-vx-modal-overlay" @click.self="addOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button type="button" class="crm-vx-modal__close" aria-label="Close" @click="addOpen = false">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">Add Batch</h2>
            <p class="crm-vx-modal__subtitle mb-0">
              Enter one batch number per line. “Batch 7763953” or “7763953” both work.
            </p>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="order-batch-add-lines">Batch Numbers</label>
            <textarea
              id="order-batch-add-lines"
              v-model="addLines"
              class="form-control"
              rows="8"
              placeholder="Batch 7763953&#10;Batch 7763954&#10;7763924"
              :disabled="busy"
            />
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="addOpen = false">
              Cancel
            </button>
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="submitAdd">
              {{ busy ? "Please Wait…" : "Add Batch" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="updateOpen" class="crm-vx-modal-overlay" @click.self="updateOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button type="button" class="crm-vx-modal__close" aria-label="Close" @click="updateOpen = false">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">Update Batch</h2>
            <p class="crm-vx-modal__subtitle mb-0">
              Enter batch numbers to mark as Completed. You will be registered as the user.
            </p>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="order-batch-update-lines">Batch Numbers</label>
            <textarea
              id="order-batch-update-lines"
              v-model="updateLines"
              class="form-control"
              rows="8"
              placeholder="7763953&#10;7763954&#10;7763924"
              :disabled="busy"
            />
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="updateOpen = false">
              Cancel
            </button>
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="submitUpdateBatch">
              {{ busy ? "Please Wait…" : "Update Batch" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="editOpen" class="crm-vx-modal-overlay" @click.self="editOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button type="button" class="crm-vx-modal__close" aria-label="Close" @click="editOpen = false">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">Edit Batch</h2>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="order-batch-edit-number">Batch #</label>
            <input
              id="order-batch-edit-number"
              v-model="editNumber"
              type="text"
              class="form-control"
              :disabled="busy"
            />
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="editOpen = false">
              Cancel
            </button>
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="submitEdit">
              {{ busy ? "Please Wait…" : "Save" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <CrmStatusUpdateModal
      v-model:open="statusOpen"
      v-model:status="statusValue"
      title="Update Status"
      subtitle="Choose a new status for this batch."
      :statuses="STATUS_OPTIONS"
      :busy="statusBusy"
      :show-reason-when-status="[]"
      @save="saveStatus"
    />

    <ConfirmModal
      :open="deleteOpen"
      title="Delete Batch"
      :message="deleteTarget ? `Delete batch ${deleteTarget.batch_number}?` : 'Delete this batch?'"
      confirm-label="Delete"
      cancel-label="Cancel"
      :danger="true"
      :busy="busy"
      @close="deleteOpen = false"
      @confirm="confirmDelete"
    />
  </div>
</template>

<style scoped>
.order-batch-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}
.order-batch-search {
  width: min(22rem, 100%);
}
.staff-status-badge {
  cursor: pointer;
  font-weight: 600;
}
</style>
