<script setup>
import { computed, inject, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import TutorialDrawer from "../../components/resources/TutorialDrawer.vue";
import TutorialModal from "../../components/resources/TutorialModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("resources.create"));
const canUpdate = computed(() => userHasPerm("resources.update"));
const canDelete = computed(() => userHasPerm("resources.delete"));
const showActionsCol = computed(() => canUpdate.value || canDelete.value);

const tableColspan = computed(() => (showActionsCol.value ? 5 : 4));

const loading = ref(true);
const rows = ref([]);
const categories = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const drawerOpen = ref(false);
const filterMenuOpen = ref(false);
const editModalOpen = ref(false);
const editingTutorial = ref(null);
const deleteTarget = ref(null);
const deleteBusy = ref(false);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const MENU_W = 160;
const MENU_H = 88;

const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);

const query = reactive({
  search: "",
  category: "",
  sort_by: "created_at",
  sort_dir: "desc",
  page: 1,
  per_page: DEFAULT_PER_PAGE,
});

let searchDebounce = null;

const showingFrom = computed(() => {
  const t = pagination.value.total;
  if (t === 0) return 0;
  return (pagination.value.current_page - 1) * query.per_page + 1;
});

const showingTo = computed(() => {
  const t = pagination.value.total;
  if (t === 0) return 0;
  return Math.min(pagination.value.current_page * query.per_page, t);
});

const pageItems = computed(() => {
  const last = pagination.value.last_page;
  const cur = pagination.value.current_page;
  if (last < 1) return [];
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => ({ type: "page", value: i + 1 }));
  }
  const nums = new Set([1, last, cur, cur - 1, cur + 1, cur - 2, cur + 2]);
  const sorted = [...nums].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const items = [];
  for (let i = 0; i < sorted.length; i++) {
    if (i > 0 && sorted[i] - sorted[i - 1] > 1) {
      items.push({ type: "ellipsis", value: null });
    }
    items.push({ type: "page", value: sorted[i] });
  }
  return items;
});

const hasActiveFilters = computed(() => !!query.category);

function sortIndicator(column) {
  if (query.sort_by !== column) return "";
  return query.sort_dir === "asc" ? "↑" : "↓";
}

function thAriaSort(column) {
  if (query.sort_by !== column) return "none";
  return query.sort_dir === "asc" ? "ascending" : "descending";
}

function toggleSort(column) {
  if (query.sort_by !== column) {
    query.sort_by = column;
    query.sort_dir = "asc";
  } else {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  }
  query.page = 1;
  load();
}

async function loadMeta() {
  try {
    const { data } = await api.get("/resources/tutorials/meta");
    categories.value = Array.isArray(data?.categories) ? data.categories : [];
  } catch {
    categories.value = [];
  }
}

async function load() {
  loading.value = true;
  try {
    const params = {
      page: query.page,
      per_page: query.per_page,
      sort_by: query.sort_by,
      sort_dir: query.sort_dir,
    };
    if (query.search.trim()) params.search = query.search.trim();
    if (query.category) params.category = query.category;
    const { data } = await api.get("/resources/tutorials", { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
      total: data?.total ?? rows.value.length,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load tutorials.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function openRow(row) {
  if (!row?.id) return;
  router.push({ name: "resources-tutorial-detail", params: { id: String(row.id) } });
}

function applySearch() {
  query.page = 1;
  load();
}

function clearFilters() {
  query.category = "";
  query.page = 1;
  load();
}

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  load();
}

function descriptionPreview(text) {
  const s = String(text || "").trim();
  if (!s) return "";
  return s.length > 120 ? `${s.slice(0, 120)}…` : s;
}

function openEdit(row) {
  if (!row?.id || !canUpdate.value) return;
  closeManageMenu();
  editingTutorial.value = { ...row };
  editModalOpen.value = true;
}

function confirmDelete(row) {
  if (!row?.id || !canDelete.value) return;
  closeManageMenu();
  deleteTarget.value = row;
}

async function doDelete() {
  const row = deleteTarget.value;
  if (!row?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/resources/tutorials/${row.id}`);
    toast.success("Tutorial deleted.");
    deleteTarget.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete tutorial.");
  } finally {
    deleteBusy.value = false;
  }
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

function toggleManageMenu(rowId, e) {
  if (manageOpenId.value === rowId) {
    closeManageMenu();
    return;
  }
  manageOpenId.value = rowId;
  placeManageMenu(e?.currentTarget);
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  load();
}

function goFirstPage() {
  goPage(1);
}

function goLastPage() {
  goPage(pagination.value.last_page);
}

function onDocClick(e) {
  const t = e.target;
  if (filterMenuOpen.value) {
    if (!(t instanceof Element && t.closest("[data-toolbar-filter]"))) {
      filterMenuOpen.value = false;
    }
  }
  if (manageOpenId.value) {
    if (!(t instanceof Element && t.closest("[data-row-actions]"))) {
      closeManageMenu();
    }
  }
}

watch(
  () => query.search,
  () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      load();
    }, 300);
  },
);

onMounted(() => {
  document.addEventListener("click", onDocClick);
  setCrmPageMeta({
    title: "Save Rack | Tutorials",
    description: "Staff training tutorials.",
  });
  loadMeta();
  load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="staff-page staff-page--wide resources-page">
    <TutorialDrawer v-model:open="drawerOpen" :categories="categories" @saved="load" />
    <TutorialModal
      v-if="editingTutorial"
      v-model:open="editModalOpen"
      :tutorial="editingTutorial"
      :categories="categories"
      @saved="load"
    />
    <ConfirmModal
      :open="!!deleteTarget"
      title="Delete Tutorial"
      :message="deleteTarget ? `Delete “${deleteTarget.title}”?` : ''"
      confirm-label="Delete"
      confirm-variant="danger"
      :busy="deleteBusy"
      @cancel="deleteTarget = null"
      @confirm="doDelete"
    />

    <div
      class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1 text-center text-md-start w-100">
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Tutorials</h1>
        <p class="staff-page__intro mb-0">Training guides for warehouse and CRM workflows.</p>
      </div>
      <div
        class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-end gap-2 flex-shrink-0"
      >
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="drawerOpen = true"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Tutorial
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
          :disabled="loading"
          title="Refresh"
          aria-label="Refresh list"
          @click="load"
        >
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
          Refresh
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            id="resources-tutorials-search"
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search tutorials"
            autocomplete="off"
            @keydown.enter.prevent="applySearch"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :class="{ active: hasActiveFilters }"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="resources-tutorials-filter-panel"
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
              id="resources-tutorials-filter-panel"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Table filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  :disabled="loading"
                  @click="
                    clearFilters();
                    filterMenuOpen = false;
                  "
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="resources-filter-category">Category</label>
                <select
                  id="resources-filter-category"
                  v-model="query.category"
                  class="form-select staff-datatable-filters__select"
                  :disabled="loading"
                  @change="applySearch"
                >
                  <option value="">All categories</option>
                  <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('title')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('title')">
                  Title
                  <span v-if="sortIndicator('title')" class="staff-sort-ind">{{ sortIndicator("title") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('category')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('category')">
                  Category
                  <span v-if="sortIndicator('category')" class="staff-sort-ind">{{ sortIndicator("category") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('created_at')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('created_at')">
                  Created
                  <span v-if="sortIndicator('created_at')" class="staff-sort-ind">{{ sortIndicator("created_at") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th" scope="col">Created By</th>
              <th
                v-if="showActionsCol"
                class="staff-table-head__th staff-actions-col text-center"
                scope="col"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading tutorials…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">No tutorials found.</td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle resources-tutorial-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td>
                <span class="fw-semibold text-body d-block text-truncate" style="max-width: 24rem" :title="row.title">
                  {{ row.title }}
                </span>
                <span
                  v-if="descriptionPreview(row.description)"
                  class="small text-secondary d-block resources-tutorial-row__subtitle"
                  :title="row.description"
                >
                  {{ descriptionPreview(row.description) }}
                </span>
              </td>
              <td class="text-body staff-table-cell__meta">
                {{ row.category_label || "—" }}
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ formatDateUs(row.created_at) || "—" }}
              </td>
              <td class="text-body staff-table-cell__meta text-truncate" style="max-width: 12rem">
                {{ row.creator?.name || "—" }}
              </td>
              <td v-if="showActionsCol" class="staff-actions-cell text-center" @click.stop>
                <div data-row-actions class="staff-actions-inner staff-actions-inner--single justify-content-center">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id ? 'true' : 'false'"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

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
            <button
              v-if="canUpdate"
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openEdit(manageMenuRow)"
            >
              Edit
            </button>
            <button
              v-if="canDelete"
              type="button"
              class="staff-row-menu__item text-danger"
              role="menuitem"
              @click="confirmDelete(manageMenuRow)"
            >
              Delete
            </button>
          </div>
        </Transition>
      </Teleport>

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
            Showing
            <span class="fw-semibold text-body">{{ showingFrom }}</span>
            to
            <span class="fw-semibold text-body">{{ showingTo }}</span>
            of
            <span class="fw-semibold text-body">{{ pagination.total }}</span>
            entries
          </p>
          <div class="d-flex align-items-center gap-2 justify-content-center justify-content-sm-start">
            <label class="small text-secondary text-nowrap mb-0" for="resources-tutorials-per-page">Rows per page</label>
            <select
              id="resources-tutorials-per-page"
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
          v-if="pagination.last_page > 1"
          class="order-1 order-lg-2 d-flex justify-content-center justify-content-lg-end ms-lg-auto flex-shrink-0"
          aria-label="Tutorial pages"
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
                «
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page <= 1"
                aria-label="Previous page"
                @click="goPage(pagination.current_page - 1)"
              >
                ‹
              </button>
            </div>
            <div class="staff-page-pager__pages">
              <template v-for="(item, idx) in pageItems" :key="`p-${idx}-${item.value}`">
                <span v-if="item.type === 'ellipsis'" class="staff-page-pager-tile staff-page-pager-tile--ellipsis" aria-hidden="true">…</span>
                <button
                  v-else
                  type="button"
                  class="staff-page-pager-tile"
                  :class="{ 'staff-page-pager-tile--active': item.value === pagination.current_page }"
                  :disabled="loading"
                  :aria-label="`Page ${item.value}`"
                  :aria-current="item.value === pagination.current_page ? 'page' : undefined"
                  @click="goPage(item.value)"
                >
                  {{ item.value }}
                </button>
              </template>
            </div>
            <div class="staff-page-pager__end">
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page >= pagination.last_page"
                aria-label="Next page"
                @click="goPage(pagination.current_page + 1)"
              >
                ›
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page >= pagination.last_page"
                aria-label="Last page"
                @click="goLastPage"
              >
                »
              </button>
            </div>
          </div>
        </nav>
      </div>
    </div>
  </div>
</template>

<style scoped>
.resources-tutorial-row {
  cursor: pointer;
}

.resources-tutorial-row__subtitle {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  max-width: 24rem;
  font-weight: 400;
  margin-top: 0.15rem;
}
</style>
