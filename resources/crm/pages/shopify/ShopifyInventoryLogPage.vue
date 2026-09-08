<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const PER_PAGE = 25;

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(false);
const variant = ref(null);
const rows = ref([]);
const typeOptions = ref([]);
const filterMenuOpen = ref(false);
const q = ref("");
const sort = ref("newest");
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: PER_PAGE });
const filters = reactive({
  date_from: "",
  date_to: "",
  changed_by: "",
  type: "",
});

const variantId = computed(() => String(route.params.id || ""));
const productTitle = computed(
  () => variant.value?.product_title || variant.value?.title || "Product",
);
const productSku = computed(() => variant.value?.sku || "—");
const hasActiveFilters = computed(
  () => !!(filters.date_from || filters.date_to || filters.changed_by || filters.type),
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

function formatDate(iso) {
  if (!iso) return { date: "—", time: "" };
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return { date: "—", time: "" };
  return {
    date: d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }),
    time: d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" }),
  };
}

function deltaLabel(delta) {
  const n = Number(delta) || 0;
  return n > 0 ? `+${n}` : String(n);
}

function filterParams() {
  return {
    q: q.value || undefined,
    date_from: filters.date_from || undefined,
    date_to: filters.date_to || undefined,
    changed_by: filters.changed_by || undefined,
    type: filters.type || undefined,
    sort: sort.value || "newest",
    per_page: pagination.value.per_page || PER_PAGE,
    page: pagination.value.current_page || 1,
  };
}

async function loadMeta() {
  try {
    const { data } = await api.get("/shopify/inventory/logs/meta");
    typeOptions.value = Array.isArray(data?.types) ? data.types : ["Transfer"];
  } catch {
    typeOptions.value = ["Transfer"];
  }
}

async function load() {
  if (!variantId.value) return;
  loading.value = true;
  try {
    const { data } = await api.get(`/shopify/inventory/${variantId.value}/logs`, {
      params: filterParams(),
    });
    variant.value = data?.variant || null;
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.meta?.current_page || 1,
      last_page: data?.meta?.last_page || 1,
      total: data?.meta?.total || 0,
      per_page: data?.meta?.per_page || PER_PAGE,
    };
  } catch (e) {
    rows.value = [];
    variant.value = null;
    toast.errorFrom(e, "Could not load inventory log.");
  } finally {
    loading.value = false;
  }
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
  filters.date_from = "";
  filters.date_to = "";
  filters.changed_by = "";
  filters.type = "";
}

function clearFilters() {
  resetFilters();
  pagination.value.current_page = 1;
  filterMenuOpen.value = false;
  void load();
}

function onSortChange() {
  pagination.value.current_page = 1;
  void load();
}

function goPage(page) {
  if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
  pagination.value.current_page = page;
  void load();
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-sil-filters]")) {
    filterMenuOpen.value = false;
  }
}

watch(
  () => route.params.id,
  () => {
    pagination.value.current_page = 1;
    void load();
  },
);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory Log",
    description: "Track every inventory change by location.",
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
    <button
      type="button"
      class="btn btn-link text-decoration-none px-0 mb-2 fw-semibold"
      @click="router.push({ name: 'shopify-inventory-detail', params: { id: variantId } })"
    >
      ← Back to Product
    </button>

    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Inventory Log</h1>
        <p class="mb-1 text-body">
          <span class="fw-semibold">{{ productTitle }}</span>
          <span class="text-secondary mx-1">|</span>
          <span class="text-primary fw-semibold">{{ productSku }}</span>
        </p>
        <p class="small text-secondary mb-0">Track every inventory change by location</p>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row sil-toolbar">
          <div class="sil-search flex-grow-1">
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
                placeholder="Search by location…"
                autocomplete="off"
                aria-label="Search by location"
                :disabled="loading"
                @keydown.enter.prevent="applySearch"
              >
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

          <div class="position-relative flex-shrink-0" data-sil-filters>
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
              aria-label="Inventory log filters"
              style="position: absolute; top: calc(100% + 0.25rem); right: 0; z-index: 1090"
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
                <label class="form-label" for="sil-date-from">Date From</label>
                <input id="sil-date-from" v-model="filters.date_from" type="date" class="form-control mb-3">

                <label class="form-label" for="sil-date-to">Date To</label>
                <input id="sil-date-to" v-model="filters.date_to" type="date" class="form-control mb-3">

                <label class="form-label" for="sil-changed-by">Changed By</label>
                <input
                  id="sil-changed-by"
                  v-model="filters.changed_by"
                  type="text"
                  class="form-control mb-3"
                  placeholder="Name…"
                >

                <label class="form-label" for="sil-type">Type</label>
                <select id="sil-type" v-model="filters.type" class="form-select mb-3">
                  <option value="">All Types</option>
                  <option v-for="t in typeOptions" :key="t" :value="t">{{ t }}</option>
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

          <select
            v-model="sort"
            class="form-select sil-sort-select flex-shrink-0"
            aria-label="Sort order"
            :disabled="loading"
            @change="onSortChange"
          >
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
          </select>

          <button
            v-if="hasActiveFilters"
            type="button"
            class="btn btn-link btn-sm text-decoration-none d-inline-flex align-items-center gap-1"
            :disabled="loading"
            @click="clearFilters"
          >
            Clear Filters
          </button>
        </div>
      </div>

      <div v-if="loading" class="p-5 d-flex justify-content-center">
        <CrmLoadingSpinner message="Loading…" />
      </div>

      <template v-else>
        <div class="table-responsive staff-table-wrap">
          <table class="table table-hover align-middle mb-0 staff-data-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th class="staff-table-head__th">Date &amp; Time</th>
                <th class="staff-table-head__th">Location</th>
                <th class="staff-table-head__th">Changed by</th>
                <th class="staff-table-head__th text-end">Old On Hand</th>
                <th class="staff-table-head__th text-end">New On Hand</th>
                <th class="staff-table-head__th">Note</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="rows.length === 0">
                <td colspan="6" class="text-secondary text-center py-4">No inventory log entries yet.</td>
              </tr>
              <tr v-for="row in rows" :key="row.id">
                <td>
                  <div class="fw-semibold text-body">{{ formatDate(row.created_at).date }}</div>
                  <div class="small text-secondary">{{ formatDate(row.created_at).time }}</div>
                </td>
                <td>
                  <span class="sil-loc-pill">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    </svg>
                    {{ row.location_name }}
                  </span>
                </td>
                <td>
                  <div class="d-inline-flex align-items-center gap-2">
                    <span
                      v-if="row.changed_by?.is_system"
                      class="sil-avatar sil-avatar--system"
                      aria-hidden="true"
                    >
                      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                    </span>
                    <span v-else class="sil-avatar">{{ row.changed_by?.initials || "?" }}</span>
                    <span>{{ row.changed_by?.name || "System" }}</span>
                  </div>
                </td>
                <td class="text-end fw-semibold">{{ row.old_on_hand }}</td>
                <td class="text-end fw-semibold">{{ row.new_on_hand }}</td>
                <td>
                  <div class="d-flex flex-wrap align-items-center gap-2">
                    <span>{{ row.note }}</span>
                    <span
                      class="sil-delta"
                      :class="Number(row.quantity_delta) >= 0 ? 'sil-delta--plus' : 'sil-delta--minus'"
                    >
                      {{ deltaLabel(row.quantity_delta) }}
                    </span>
                  </div>
                  <div v-if="row.transfer_group" class="small text-secondary mt-1">
                    {{ row.transfer_group }} • {{ row.direction_label }}
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="staff-table-footer d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 px-md-4 py-3">
          <div class="small text-secondary">
            Showing
            <span class="fw-semibold text-body">{{ showingFrom }}</span>
            to
            <span class="fw-semibold text-body">{{ showingTo }}</span>
            of
            <span class="fw-semibold text-body">{{ pagination.total }}</span>
            entries
          </div>
          <nav class="staff-page-pager d-inline-flex align-items-center gap-1" aria-label="Pagination">
            <button
              type="button"
              class="staff-page-pager-tile staff-page-pager-tile--nav"
              :disabled="pagination.current_page <= 1"
              @click="goPage(pagination.current_page - 1)"
            >
              ‹
            </button>
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
            <button
              type="button"
              class="staff-page-pager-tile staff-page-pager-tile--nav"
              :disabled="pagination.current_page >= pagination.last_page"
              @click="goPage(pagination.current_page + 1)"
            >
              ›
            </button>
          </nav>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.sil-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem;
}
.sil-search {
  min-width: 14rem;
  max-width: 28rem;
}
.sil-sort-select {
  width: auto;
  min-width: 9.5rem;
}
.sil-loc-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 600;
  font-size: 0.85rem;
}
.sil-avatar {
  width: 1.65rem;
  height: 1.65rem;
  border-radius: 999px;
  background: #dbeafe;
  color: #1d4ed8;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.65rem;
  font-weight: 700;
  flex-shrink: 0;
}
.sil-avatar--system {
  background: #e2e8f0;
  color: #475569;
}
.sil-delta {
  display: inline-flex;
  align-items: center;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 700;
}
.sil-delta--plus {
  background: #dcfce7;
  color: #15803d;
}
.sil-delta--minus {
  background: #fee2e2;
  color: #b91c1c;
}
</style>
