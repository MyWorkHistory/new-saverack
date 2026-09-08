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
const filterOpen = ref(false);
const q = ref("");
const sort = ref("newest");
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: PER_PAGE });
const filters = reactive({
  date_from: "",
  date_to: "",
  changed_by: "",
  type: "",
});

let searchTimer = null;

const variantId = computed(() => String(route.params.id || ""));
const productTitle = computed(
  () => variant.value?.product_title || variant.value?.title || "Product",
);
const productSku = computed(() => variant.value?.sku || "—");
const showingLabel = computed(() => {
  const total = pagination.value.total || 0;
  const page = pagination.value.current_page || 1;
  const per = pagination.value.per_page || PER_PAGE;
  if (total === 0) return "Showing 0 of 0 entries";
  const from = (page - 1) * per + 1;
  const to = Math.min(page * per, total);
  return `Showing ${from}–${to} of ${total} entries`;
});
const hasActiveFilters = computed(
  () => !!(filters.date_from || filters.date_to || filters.changed_by || filters.type),
);

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

function applyFilters() {
  filterOpen.value = false;
  pagination.value.current_page = 1;
  void load();
}

function clearFilters() {
  filters.date_from = "";
  filters.date_to = "";
  filters.changed_by = "";
  filters.type = "";
  pagination.value.current_page = 1;
  filterOpen.value = false;
  void load();
}

function goPage(page) {
  if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
  pagination.value.current_page = page;
  void load();
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-sil-filters]")) {
    filterOpen.value = false;
  }
}

watch(q, () => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    pagination.value.current_page = 1;
    void load();
  }, 250);
});

watch(sort, () => {
  pagination.value.current_page = 1;
  void load();
});

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
  if (searchTimer) clearTimeout(searchTimer);
});
</script>

<template>
  <div class="staff-page staff-page--wide sil">
    <button
      type="button"
      class="sil-back"
      @click="router.push({ name: 'shopify-inventory-detail', params: { id: variantId } })"
    >
      ← Back to Product
    </button>

    <header class="sil-header mb-3">
      <h1 class="sil-title">Inventory Log</h1>
      <div class="sil-product">
        <span class="sil-product__name">{{ productTitle }}</span>
        <span class="sil-product__sep" aria-hidden="true">|</span>
        <span class="sil-product__sku">{{ productSku }}</span>
      </div>
      <p class="sil-subtitle mb-0">Track every inventory change by location</p>
    </header>

    <div class="sil-toolbar mb-3">
      <div class="sil-search">
        <svg class="sil-search__icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
        <input
          v-model="q"
          type="search"
          class="form-control"
          placeholder="Search by location…"
          aria-label="Search by location"
        >
      </div>

      <div class="sil-toolbar__right">
        <div class="sil-filter-wrap" data-sil-filters>
          <button
            type="button"
            class="btn btn-outline-secondary sil-filter-btn"
            :class="{ 'sil-filter-btn--active': filterOpen || hasActiveFilters }"
            @click.stop="filterOpen = !filterOpen"
          >
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z" />
            </svg>
            Filter
          </button>
          <div v-if="filterOpen" class="sil-filter-panel" @click.stop>
            <label class="form-label">Date From</label>
            <input v-model="filters.date_from" type="date" class="form-control mb-2">
            <label class="form-label">Date To</label>
            <input v-model="filters.date_to" type="date" class="form-control mb-2">
            <label class="form-label">Changed By</label>
            <input v-model="filters.changed_by" type="text" class="form-control mb-2" placeholder="Name…">
            <label class="form-label">Type</label>
            <select v-model="filters.type" class="form-select mb-3">
              <option value="">All Types</option>
              <option v-for="t in typeOptions" :key="t" :value="t">{{ t }}</option>
            </select>
            <div class="d-flex gap-2 justify-content-end">
              <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearFilters">Clear</button>
              <button type="button" class="btn btn-sm btn-primary staff-page-primary" @click="applyFilters">Apply</button>
            </div>
          </div>
        </div>

        <select v-model="sort" class="form-select sil-sort" aria-label="Sort order">
          <option value="newest">Newest first</option>
          <option value="oldest">Oldest first</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="p-5 d-flex justify-content-center">
      <CrmLoadingSpinner message="Loading…" />
    </div>

    <template v-else>
      <div class="table-responsive sil-table-wrap">
        <table class="table align-middle mb-0 sil-table">
          <thead>
            <tr>
              <th>Date &amp; Time</th>
              <th>Location</th>
              <th>Changed by</th>
              <th class="text-end">Old On Hand</th>
              <th class="text-end">New On Hand</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rows.length === 0">
              <td colspan="6" class="text-secondary text-center py-4">No inventory log entries yet.</td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <div class="sil-datetime__date">{{ formatDate(row.created_at).date }}</div>
                <div class="sil-datetime__time">{{ formatDate(row.created_at).time }}</div>
              </td>
              <td>
                <span class="sil-loc">
                  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                  </svg>
                  {{ row.location_name }}
                </span>
              </td>
              <td>
                <div class="sil-actor">
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
                <div class="sil-note">
                  <span>{{ row.note }}</span>
                  <span
                    class="sil-delta"
                    :class="Number(row.quantity_delta) >= 0 ? 'sil-delta--plus' : 'sil-delta--minus'"
                  >
                    {{ deltaLabel(row.quantity_delta) }}
                  </span>
                </div>
                <div v-if="row.transfer_group" class="sil-note__sub">
                  {{ row.transfer_group }} • {{ row.direction_label }}
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="sil-footer mt-3">
        <div class="text-secondary small">{{ showingLabel }}</div>
        <div class="sil-pager">
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :disabled="pagination.current_page <= 1"
            @click="goPage(pagination.current_page - 1)"
          >
            Previous
          </button>
          <span class="sil-pager__page">{{ pagination.current_page }}</span>
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
    </template>
  </div>
</template>

<style scoped>
.sil-back {
  border: 0;
  background: transparent;
  color: #2563eb;
  font-weight: 600;
  padding: 0;
  margin-bottom: 0.75rem;
}
.sil-title {
  margin: 0 0 0.35rem;
  font-size: 1.75rem;
  font-weight: 700;
  color: #0f172a;
}
.sil-product {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.45rem;
  margin-bottom: 0.2rem;
}
.sil-product__name {
  font-weight: 600;
  color: #1e293b;
}
.sil-product__sep {
  color: #cbd5e1;
}
.sil-product__sku {
  color: #3b82f6;
  font-weight: 500;
}
.sil-subtitle {
  color: #64748b;
  font-size: 0.9rem;
}
.sil-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  justify-content: space-between;
}
.sil-search {
  position: relative;
  flex: 1 1 16rem;
  max-width: 28rem;
}
.sil-search__icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  pointer-events: none;
}
.sil-search .form-control {
  padding-left: 2.25rem;
}
.sil-toolbar__right {
  display: flex;
  gap: 0.55rem;
  align-items: center;
}
.sil-filter-wrap {
  position: relative;
}
.sil-filter-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.sil-filter-btn--active {
  border-color: #2563eb;
  color: #2563eb;
}
.sil-filter-panel {
  position: absolute;
  right: 0;
  top: calc(100% + 0.35rem);
  z-index: 20;
  width: 17rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 0.65rem;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
  padding: 0.85rem;
}
.sil-sort {
  width: auto;
  min-width: 9.5rem;
}
.sil-table-wrap {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  overflow: hidden;
}
.sil-table thead th {
  background: #f8fafc;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #64748b;
  border-bottom: 1px solid #e5e7eb;
  white-space: nowrap;
}
.sil-datetime__date {
  font-weight: 700;
  color: #0f172a;
}
.sil-datetime__time {
  font-size: 0.8rem;
  color: #94a3b8;
}
.sil-loc {
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
.sil-actor {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
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
.sil-note {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
}
.sil-note__sub {
  margin-top: 0.15rem;
  font-size: 0.78rem;
  color: #94a3b8;
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
.sil-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  justify-content: space-between;
}
.sil-pager {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}
.sil-pager__page {
  min-width: 2rem;
  height: 2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.4rem;
  background: #2563eb;
  color: #fff;
  font-weight: 600;
  font-size: 0.85rem;
}
</style>
