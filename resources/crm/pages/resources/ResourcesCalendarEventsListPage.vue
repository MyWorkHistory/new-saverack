<script setup>
import { computed, inject, onMounted, reactive, ref } from "vue";
import { RouterLink } from "vue-router";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useResourceCalendarEvents } from "../../composables/useResourceCalendarEvents.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { canManageCalendarEvent } from "../../utils/calendarEventPermissions.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const { loading, deleting, loadList, deleteEvent, bulkDeleteEvents } = useResourceCalendarEvents();

const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: DEFAULT_PER_PAGE });
const selectedIds = ref([]);
const searchDraft = ref("");
const searchCommitted = ref("");
const query = reactive({
  page: 1,
  per_page: DEFAULT_PER_PAGE,
});

const bulkDeleteOpen = ref(false);
const rowDeleteTarget = ref(null);
const rowDeleteBusy = ref(false);

const showCheckboxCol = computed(() => true);

const isAllPageSelected = computed(
  () => rows.value.length > 0 && rows.value.every((r) => selectedIds.value.includes(r.id)),
);

const tableColspan = computed(() => (showCheckboxCol.value ? 5 : 4));

function formatEventDate(row) {
  const start = formatDateUs(row.start_date) || "—";
  const end = formatDateUs(row.end_date) || start;
  if (!row.end_date || row.end_date === row.start_date) return start;
  return `${start} – ${end}`;
}

function canDeleteRow(row) {
  return canManageCalendarEvent(crmUser.value, row);
}

async function fetchRows() {
  selectedIds.value = [];
  try {
    const result = await loadList({
      page: query.page,
      perPage: query.per_page,
      query: searchCommitted.value,
    });
    rows.value = result.rows;
    pagination.value = {
      current_page: result.meta.current_page || 1,
      last_page: result.meta.last_page || 1,
      total: result.meta.total || 0,
      per_page: result.meta.per_page || query.per_page,
    };
  } catch {
    rows.value = [];
  }
}

function commitSearch() {
  searchCommitted.value = searchDraft.value.trim();
  query.page = 1;
  fetchRows();
}

function clearSearch() {
  if (!searchDraft.value && !searchCommitted.value) return;
  searchDraft.value = "";
  searchCommitted.value = "";
  query.page = 1;
  fetchRows();
}

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  fetchRows();
}

function goPage(page) {
  const p = Number(page);
  if (!p || p < 1 || p > pagination.value.last_page || p === query.page) return;
  query.page = p;
  fetchRows();
}

function toggleSelectAll(e) {
  if (e.target.checked) {
    selectedIds.value = rows.value.filter(canDeleteRow).map((r) => r.id);
  } else {
    selectedIds.value = [];
  }
}

function toggleSelect(id) {
  const i = selectedIds.value.indexOf(id);
  if (i === -1) {
    selectedIds.value = [...selectedIds.value, id];
  } else {
    selectedIds.value = selectedIds.value.filter((x) => x !== id);
  }
}

function openBulkDelete() {
  if (!selectedIds.value.length) {
    toast.error("Select one or more rows.");
    return;
  }
  bulkDeleteOpen.value = true;
}

function closeBulkDelete() {
  if (!deleting.value) bulkDeleteOpen.value = false;
}

const bulkDeleteMessage = computed(() => {
  const n = selectedIds.value.length;
  return n ? `Delete ${n} event${n === 1 ? "" : "s"}? This cannot be undone.` : "";
});

async function confirmBulkDelete() {
  if (!selectedIds.value.length) return;
  try {
    await bulkDeleteEvents(selectedIds.value);
    bulkDeleteOpen.value = false;
    selectedIds.value = [];
    await fetchRows();
  } catch {
    /* toasted */
  }
}

function openRowDelete(row) {
  if (!canDeleteRow(row)) return;
  rowDeleteTarget.value = row;
}

function closeRowDelete() {
  if (!rowDeleteBusy.value) rowDeleteTarget.value = null;
}

async function confirmRowDelete() {
  const row = rowDeleteTarget.value;
  if (!row) return;
  rowDeleteBusy.value = true;
  try {
    await deleteEvent(row.id);
    rowDeleteTarget.value = null;
    await fetchRows();
  } catch {
    /* toasted */
  } finally {
    rowDeleteBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Calendar Events",
    description: "List and manage staff calendar events.",
  });
  fetchRows();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Delete Events?"
      :message="bulkDeleteMessage"
      confirm-label="Delete"
      :busy="deleting"
      danger
      @close="closeBulkDelete"
      @confirm="confirmBulkDelete"
    />
    <ConfirmModal
      :open="!!rowDeleteTarget"
      title="Delete Event?"
      :message="rowDeleteTarget ? `Delete “${rowDeleteTarget.title}”? This cannot be undone.` : ''"
      confirm-label="Delete"
      :busy="rowDeleteBusy"
      danger
      @close="closeRowDelete"
      @confirm="confirmRowDelete"
    />

    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4">
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Events</h1>
        <p class="text-secondary small mb-0">All calendar events in list view.</p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 ms-md-auto flex-shrink-0">
        <RouterLink
          to="/admin/resources/calendar"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
        >
          Calendar
        </RouterLink>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <div class="flex-grow-1" style="max-width: 22rem">
            <label class="form-label small text-secondary mb-1" for="calendar-events-search">Search</label>
            <div class="input-group orders-toolbar-search-group">
              <input
                id="calendar-events-search"
                v-model.trim="searchDraft"
                type="search"
                class="form-control"
                placeholder="Search by name"
                autocomplete="off"
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
          <button
            type="button"
            class="btn btn-outline-danger staff-toolbar-btn"
            :disabled="!selectedIds.length || loading || deleting"
            @click="openBulkDelete"
          >
            Bulk Delete
          </button>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                v-if="showCheckboxCol"
                class="staff-table-head__th staff-table-head__th--select"
                scope="col"
              >
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0"
                  :checked="isAllPageSelected"
                  :disabled="loading || !rows.length"
                  aria-label="Select all on page"
                  @change="toggleSelectAll"
                />
              </th>
              <th class="staff-table-head__th" scope="col">Name</th>
              <th class="staff-table-head__th" scope="col">Event Type</th>
              <th class="staff-table-head__th" scope="col">Date</th>
              <th class="staff-table-head__th text-end" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="text-center py-5">
                <CrmLoadingSpinner />
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                No events found.
              </td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td v-if="showCheckboxCol" class="text-center">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0"
                  :checked="selectedIds.includes(row.id)"
                  :disabled="!canDeleteRow(row)"
                  :aria-label="`Select ${row.title}`"
                  @change="toggleSelect(row.id)"
                />
              </td>
              <td class="fw-medium">{{ row.title || "—" }}</td>
              <td>
                <span class="d-inline-flex align-items-center gap-2">
                  <span
                    class="rounded-circle flex-shrink-0"
                    :style="{
                      width: '0.65rem',
                      height: '0.65rem',
                      backgroundColor: row.category_color || '#6b7280',
                    }"
                    aria-hidden="true"
                  />
                  {{ row.category_label || "—" }}
                </span>
              </td>
              <td>{{ formatEventDate(row) }}</td>
              <td class="text-end">
                <button
                  v-if="canDeleteRow(row)"
                  type="button"
                  class="btn btn-link btn-sm text-danger text-decoration-none px-1"
                  @click="openRowDelete(row)"
                >
                  Delete
                </button>
                <span v-else class="text-secondary small">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="pagination.last_page > 1 || pagination.total > 0"
        class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-3 border-top"
      >
        <div class="small text-secondary">
          {{ pagination.total }} event{{ pagination.total === 1 ? "" : "s" }}
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <label class="small text-secondary mb-0" for="calendar-events-per-page">Per page</label>
          <select
            id="calendar-events-per-page"
            class="form-select form-select-sm"
            style="width: auto"
            :value="query.per_page"
            :disabled="loading"
            @change="onPerPageChange"
          >
            <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
          </select>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm"
            :disabled="loading || query.page <= 1"
            @click="goPage(query.page - 1)"
          >
            Prev
          </button>
          <span class="small text-secondary">
            Page {{ pagination.current_page }} / {{ pagination.last_page }}
          </span>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm"
            :disabled="loading || query.page >= pagination.last_page"
            @click="goPage(query.page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
