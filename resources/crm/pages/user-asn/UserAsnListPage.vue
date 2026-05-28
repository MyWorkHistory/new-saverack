<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatAsnDisplay } from "../../utils/formatAsnDisplay.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const sortBy = ref("created_at");
const sortDir = ref("desc");

const selected = ref(new Set());
const bulkDeleteOpen = ref(false);
const bulkDeleteBusy = ref(false);

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 220;
const MENU_H = 120;

const rowDeleteOpen = ref(false);
const rowDeleteTarget = ref(null);
const rowDeleteBusy = ref(false);

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const tableColspan = 10;

const allSelected = computed(() => {
  if (rows.value.length === 0) return false;
  return rows.value.every((r) => selected.value.has(r.id));
});

const selectedCount = computed(() => selected.value.size);

function canDeleteRow(row) {
  const s = String(row?.status || "").toLowerCase();
  return s === "draft" || s === "pending";
}

const selectedDeletableIds = computed(() =>
  rows.value.filter((r) => selected.value.has(r.id) && canDeleteRow(r)).map((r) => r.id),
);

const bulkDeleteDisabled = computed(
  () => selectedDeletableIds.value.length === 0 || selectedDeletableIds.value.length !== selectedCount.value,
);

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = v.trim();
    meta.value.current_page = 1;
    load();
  }, 300);
});

function sortIndicator(column) {
  if (sortBy.value !== column) return "";
  return sortDir.value === "asc" ? "↑" : "↓";
}

function toggleSort(column) {
  if (sortBy.value !== column) {
    sortBy.value = column;
    sortDir.value = "asc";
  } else {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  }
  meta.value.current_page = 1;
  load();
}

function statusLabel(s) {
  if (s === "draft") return "Draft";
  if (s === "in_progress") return "In Progress";
  if (s === "completed") return "Completed";
  if (s === "pending") return "Pending";
  return "Pending";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "bg-warning-subtle text-warning-emphasis";
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "in_progress") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function clearSelection() {
  selected.value = new Set();
}

async function load() {
  if (!clientAccountId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get("/asns", {
      params: {
        client_account_id: clientAccountId.value,
        q: searchDebounced.value || undefined,
        page: meta.value.current_page,
        per_page: meta.value.per_page,
        sort_by: sortBy.value,
        sort_dir: sortDir.value,
      },
    });
    rows.value = data.data || [];
    if (data.meta) {
      meta.value = { ...meta.value, ...data.meta };
    }
    selected.value = new Set();
  } catch (e) {
    toast.errorFrom(e, "Could not load ASNs.");
  } finally {
    loading.value = false;
  }
}

function toggleAll() {
  if (allSelected.value) {
    selected.value = new Set();
  } else {
    selected.value = new Set(rows.value.map((r) => r.id));
  }
}

function toggleOne(id) {
  const next = new Set(selected.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  selected.value = next;
}

async function createAsn() {
  if (!clientAccountId.value) return;
  try {
    const { data } = await api.post("/asns", { client_account_id: clientAccountId.value });
    toast.success("ASN created.");
    const href = router.resolve({
      name: "user-asn-detail",
      params: { id: String(data.id) },
    }).href;
    window.open(href, "_blank", "noopener,noreferrer");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not create ASN.");
  }
}

function printPackingSlipForRow(row) {
  const href = router.resolve({
    name: "user-asn-print-packing-slip",
    params: { id: String(row.id) },
    query: { client_account_id: String(clientAccountId.value) },
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
}

async function confirmBulkDelete() {
  if (!clientAccountId.value || selectedDeletableIds.value.length === 0) return;
  bulkDeleteBusy.value = true;
  try {
    await api.post("/asns/bulk-delete", {
      client_account_id: clientAccountId.value,
      ids: selectedDeletableIds.value,
    });
    toast.success("Deleted selected ASNs.");
    bulkDeleteOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Bulk delete failed.");
  } finally {
    bulkDeleteBusy.value = false;
  }
}

function openRow(r) {
  router.push({ name: "user-asn-detail", params: { id: String(r.id) } });
}

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

function closeManageMenu() {
  manageOpenId.value = null;
}

async function toggleManageMenu(rowId, e) {
  e.stopPropagation();
  if (manageOpenId.value === rowId) {
    closeManageMenu();
    return;
  }
  const btn = e.currentTarget;
  manageOpenId.value = rowId;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

const manageMenuRow = computed(() => rows.value.find((r) => r.id == manageOpenId.value) ?? null);

function onDocClickManage(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    manageOpenId.value = null;
  }
}

function onWindowCloseManageMenu() {
  manageOpenId.value = null;
}

function goEditAsnFromMenu() {
  const row = manageMenuRow.value;
  if (!row) return;
  closeManageMenu();
  router.push({
    name: "user-asn-detail",
    params: { id: String(row.id) },
    hash: "#user-asn-items",
  });
}

function printPackingSlipFromMenu() {
  const row = manageMenuRow.value;
  if (!row) return;
  closeManageMenu();
  printPackingSlipForRow(row);
}

function openRowDeleteModalFromMenu() {
  const row = manageMenuRow.value;
  if (!row) return;
  if (!canDeleteRow(row)) {
    closeManageMenu();
    toast.error("Only draft or pending ASNs can be removed.");
    return;
  }
  closeManageMenu();
  rowDeleteTarget.value = row;
  rowDeleteOpen.value = true;
}

async function confirmRowDelete() {
  const row = rowDeleteTarget.value;
  if (!row?.id) return;
  rowDeleteBusy.value = true;
  try {
    await api.delete(`/asns/${row.id}`);
    toast.success("ASN removed.");
    rowDeleteOpen.value = false;
    rowDeleteTarget.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not remove ASN.");
  } finally {
    rowDeleteBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Advanced Shipment Notice",
    description: "Advanced shipment notices for your account.",
  });
  document.addEventListener("click", onDocClickManage);
  window.addEventListener("scroll", onWindowCloseManageMenu, true);
  window.addEventListener("resize", onWindowCloseManageMenu);
  load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickManage);
  window.removeEventListener("scroll", onWindowCloseManageMenu, true);
  window.removeEventListener("resize", onWindowCloseManageMenu);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Advanced Shipment Notice</h1>
        <p class="staff-page__intro user-asn-list__subtitle mb-0">Search by ASN # or tracking #.</p>
      </div>
      <button type="button" class="btn btn-primary staff-page-primary" @click="createAsn">Create ASN</button>
    </div>

    <div class="user-asn-list staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <input
            id="asn-list-search"
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search ASN # or tracking #"
            autocomplete="off"
            aria-label="Search ASN"
            @keydown.enter.prevent="load"
          />
        </div>
      </div>

      <div
        v-if="selectedCount > 0"
        class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
      >
        <span class="small staff-bulk-selection-bar__count me-md-1">{{ selectedCount }} selected</span>
        <button
          type="button"
          class="btn btn-outline-danger btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn orders-toolbar-outline-btn--danger"
          :disabled="bulkDeleteDisabled || loading"
          @click="bulkDeleteOpen = true"
        >
          Delete Selected
        </button>
        <button
          type="button"
          class="btn btn-link btn-sm staff-bulk-clear-link text-decoration-none px-1"
          @click="clearSelection"
        >
          Clear Selection
        </button>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--select text-center" scope="col" style="width: 2.75rem">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check m-0"
                  :checked="allSelected"
                  :disabled="loading || !rows.length"
                  aria-label="Select all on page"
                  @change="toggleAll"
                />
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('status')">
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{ sortIndicator("status") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('asn_number')">
                  ASN #
                  <span v-if="sortIndicator('asn_number')" class="staff-sort-ind">{{ sortIndicator("asn_number") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('created_at')">
                  Date Created
                  <span v-if="sortIndicator('created_at')" class="staff-sort-ind">{{ sortIndicator("created_at") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('expected_qty')">
                  Expected QTY
                  <span v-if="sortIndicator('expected_qty')" class="staff-sort-ind">{{ sortIndicator("expected_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('accepted_qty')">
                  Accepted QTY
                  <span v-if="sortIndicator('accepted_qty')" class="staff-sort-ind">{{ sortIndicator("accepted_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('rejected_qty')">
                  Rejected QTY
                  <span v-if="sortIndicator('rejected_qty')" class="staff-sort-ind">{{ sortIndicator("rejected_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('total_boxes')">
                  Total Boxes
                  <span v-if="sortIndicator('total_boxes')" class="staff-sort-ind">{{ sortIndicator("total_boxes") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th text-center" scope="col">Tracking</th>
              <th class="staff-table-head__th staff-actions-col text-center user-asn-list-actions-col" scope="col">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading ASNs…" />
                </div>
              </td>
            </tr>
            <template v-else>
              <tr v-for="r in rows" :key="r.id" class="align-middle cursor-pointer" @click="openRow(r)">
                <td class="text-center staff-table-cell--tight-check" @click.stop>
                  <input
                    type="checkbox"
                    class="form-check-input staff-table-head__check m-0"
                    :checked="selected.has(r.id)"
                    :aria-label="`Select ASN ${r.asn_number}`"
                    @change="toggleOne(r.id)"
                  />
                </td>
                <td class="text-center">
                  <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(r.status)">
                    {{ statusLabel(r.status) }}
                  </span>
                </td>
                <td class="text-center fw-semibold user-asn-list-asn-col">{{ formatAsnDisplay(r.asn_number) }}</td>
                <td class="text-center small text-secondary">{{ formatDateUs(r.created_at) }}</td>
                <td class="text-center">{{ Number(r.expected_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(r.accepted_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(r.rejected_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(r.total_boxes ?? 0).toLocaleString() }}</td>
                <td class="text-center small text-secondary user-asn-list-tracking-col">
                  <span class="user-asn-list-tracking-text">{{ r.tracking_display || "—" }}</span>
                </td>
                <td class="staff-actions-cell text-center user-asn-list-actions-cell" @click.stop>
                  <div
                    data-row-actions
                    class="staff-actions-inner staff-actions-inner--single user-asn-list-actions-inner"
                  >
                    <button
                      type="button"
                      class="staff-action-btn staff-action-btn--more"
                      :class="{ 'is-open': manageOpenId == r.id }"
                      :aria-expanded="manageOpenId == r.id ? 'true' : 'false'"
                      aria-haspopup="true"
                      aria-label="Row actions"
                      @click="toggleManageMenu(r.id, $event)"
                    >
                      <CrmIconRowActions variant="horizontal" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="rows.length === 0">
                <td :colspan="tableColspan" class="text-center text-secondary py-5">
                  No ASNs yet. Use Create ASN to get started.
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>

      <div
        v-if="!loading && meta.last_page > 1"
        class="staff-table-footer card-footer d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2"
      >
        <span class="small text-secondary">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
        <div class="btn-group btn-group-sm ms-sm-auto">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page <= 1"
            @click="
              meta.current_page--;
              load();
            "
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="
              meta.current_page++;
              load();
            "
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Delete Selected ASNs"
      message="Only draft or pending ASNs will be removed. This cannot be undone."
      confirm-label="Delete"
      :busy="bulkDeleteBusy"
      danger
      @close="bulkDeleteOpen = false"
      @confirm="confirmBulkDelete"
    />

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
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
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="goEditAsnFromMenu">Edit ASN</button>
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="printPackingSlipFromMenu">
            Print Packing Slip
          </button>
          <button
            v-if="manageMenuRow && canDeleteRow(manageMenuRow)"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openRowDeleteModalFromMenu"
          >
            Remove
          </button>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="rowDeleteOpen"
      title="Remove ASN"
      :message="
        rowDeleteTarget
          ? `Remove ${rowDeleteTarget.asn_number}? Only draft or pending ASNs can be deleted. This cannot be undone.`
          : ''
      "
      confirm-label="Remove"
      :busy="rowDeleteBusy"
      danger
      @close="
        rowDeleteOpen = false;
        rowDeleteTarget = null;
      "
      @confirm="confirmRowDelete"
    />
  </div>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

.user-asn-list :deep(.staff-table-head__th--sort .staff-sort-btn) {
  justify-content: center;
  width: 100%;
  text-align: center;
}

.user-asn-list :deep(.staff-table-footer .btn-outline-secondary:hover:not(:disabled)),
.user-asn-list :deep(.staff-table-footer .btn-outline-secondary:focus-visible) {
  background-color: rgba(115, 103, 240, 0.06);
  border-color: rgba(115, 103, 240, 0.35);
  color: var(--bs-body-color);
}

[data-bs-theme="dark"] .user-asn-list :deep(.staff-table-footer .btn-outline-secondary:hover:not(:disabled)),
[data-bs-theme="dark"] .user-asn-list :deep(.staff-table-footer .btn-outline-secondary:focus-visible) {
  background-color: rgba(115, 103, 240, 0.12);
  border-color: rgba(186, 175, 255, 0.35);
  color: var(--bs-body-color);
}
.user-asn-list :deep(.table.staff-data-table > thead > tr > th.user-asn-list-actions-col),
.user-asn-list :deep(.table.staff-data-table > tbody > tr > td.user-asn-list-actions-cell) {
  text-align: center !important;
}

.user-asn-list :deep(.user-asn-list-actions-inner) {
  justify-content: center !important;
}

.user-asn-list :deep(.user-asn-list-tracking-col) {
  min-width: 9rem;
}

.user-asn-list :deep(.user-asn-list-actions-col) {
  min-width: 5.5rem;
}

.user-asn-list :deep(.user-asn-list-asn-col) {
  min-width: 6.5rem;
}

.user-asn-list-tracking-text {
  display: inline-block;
  max-width: 14rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
}

[data-bs-theme="dark"] .user-asn-list__subtitle {
  color: #fff !important;
}
</style>
