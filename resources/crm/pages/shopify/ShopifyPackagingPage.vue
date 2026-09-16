<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyPackagingFormModal from "../../components/shopify/ShopifyPackagingFormModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { formatCents } from "../../utils/formatMoney.js";

const CATEGORIES = [
  { value: "packaging", label: "Packaging" },
  { value: "packaging_materials", label: "Packaging Materials" },
];

const TYPES = {
  packaging: [
    { value: "box", label: "Box" },
    { value: "poly_mailer", label: "Poly Mailer" },
    { value: "bubble_mailer", label: "Bubble Mailer" },
    { value: "kraft_mailer", label: "Kraft Mailer" },
  ],
  packaging_materials: [
    { value: "kraft_paper", label: "Kraft Paper" },
    { value: "bubble_wrap", label: "Bubble Wrap" },
    { value: "peanuts", label: "Peanuts" },
    { value: "tissue_paper", label: "Tissue Paper" },
  ],
};

const MENU_W = 140;
const MENU_H = 52;
const PER_PAGE_OPTIONS = [10, 25, 50, 100];

const router = useRouter();
const toast = useToast();
const loading = ref(false);
const saveBusy = ref(false);
const deleteBusy = ref(false);
const rows = ref([]);
const q = ref("");
const appliedQ = ref("");
const category = ref("");
const type = ref("");
const addOpen = ref(false);
const deleteOpen = ref(false);
const deleteTarget = ref(null);
const manageOpenId = ref(null);
const manageMenuRow = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 50 });

const form = reactive(emptyForm());

const typeOptions = computed(() => {
  if (category.value && TYPES[category.value]) return TYPES[category.value];
  return [...TYPES.packaging, ...TYPES.packaging_materials];
});

const showingFrom = computed(() => {
  if (!pagination.value.total) return 0;
  return (pagination.value.current_page - 1) * pagination.value.per_page + 1;
});
const showingTo = computed(() => Math.min(pagination.value.current_page * pagination.value.per_page, pagination.value.total));
const pageItems = computed(() => {
  const last = pagination.value.last_page || 1;
  const current = pagination.value.current_page || 1;
  const start = Math.max(1, current - 2);
  const end = Math.min(last, start + 4);
  const pages = [];
  for (let i = Math.max(1, end - 4); i <= end; i += 1) pages.push(i);
  return pages;
});

function emptyForm() {
  return {
    name: "",
    sku: "",
    category: "packaging",
    type: "box",
    cost: "",
    price: "",
    on_hand: "0",
    length: "",
    width: "",
    height: "",
    weight: "",
  };
}

function detailHref(row) {
  return router.resolve({ name: "shopify-packaging-detail", params: { id: String(row.id) } }).href;
}

function openRow(row) {
  if (!row?.id) return;
  manageOpenId.value = null;
  window.open(detailHref(row), "_blank", "noopener,noreferrer");
}

function placeManageMenu(btn) {
  if (!(btn instanceof HTMLElement)) return;
  const r = btn.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) top = Math.max(8, r.top - MENU_H - 4);
  manageMenuRect.value = { top, left };
}

async function toggleManageMenu(row, e) {
  e?.stopPropagation?.();
  if (manageOpenId.value === row?.id) {
    manageOpenId.value = null;
    manageMenuRow.value = null;
    return;
  }
  const btn = e?.currentTarget;
  manageOpenId.value = row.id;
  manageMenuRow.value = row;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-packaging-row-actions]")) {
    manageOpenId.value = null;
    manageMenuRow.value = null;
  }
}

function commitSearch() {
  appliedQ.value = q.value.trim();
  load(1);
}

function onCategoryChange() {
  const allowed = typeOptions.value.map((opt) => opt.value);
  if (type.value && !allowed.includes(type.value)) type.value = "";
  load(1);
}

function promptDelete(row) {
  deleteTarget.value = row;
  deleteOpen.value = true;
  manageOpenId.value = null;
  manageMenuRow.value = null;
}

function openAdd() {
  Object.assign(form, emptyForm());
  addOpen.value = true;
}

async function load(page = pagination.value.current_page) {
  loading.value = true;
  try {
    const { data } = await api.get("/shopify/packaging", {
      params: {
        q: appliedQ.value || undefined,
        category: category.value || undefined,
        type: type.value || undefined,
        page,
        per_page: pagination.value.per_page,
      },
    });
    rows.value = data?.data || [];
    pagination.value = {
      current_page: data?.meta?.current_page || 1,
      last_page: data?.meta?.last_page || 1,
      total: data?.meta?.total || 0,
      per_page: data?.meta?.per_page || pagination.value.per_page,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load packaging.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function saveCreate(payload) {
  saveBusy.value = true;
  try {
    await api.post("/shopify/packaging", payload);
    toast.success("Packaging created.");
    addOpen.value = false;
    await load(1);
  } catch (e) {
    toast.errorFrom(e, "Could not create packaging.");
  } finally {
    saveBusy.value = false;
  }
}

async function confirmDelete() {
  if (!deleteTarget.value?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/shopify/packaging/${deleteTarget.value.id}`);
    toast.success("Packaging deleted.");
    deleteOpen.value = false;
    deleteTarget.value = null;
    await load(pagination.value.current_page);
  } catch (e) {
    toast.errorFrom(e, "Could not delete packaging.");
  } finally {
    deleteBusy.value = false;
  }
}

function onPerPageChange(e) {
  pagination.value.per_page = Number(e.target.value) || 50;
  load(1);
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Packaging",
    description: "Shopify packaging and packaging materials.",
  });
  document.addEventListener("click", onDocClick);
  load(1);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide sip">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Packaging</h1>
        <p class="small text-secondary mb-0">View and manage packaging and packaging materials.</p>
      </div>
      <button
        type="button"
        class="btn btn-primary staff-page-primary fw-semibold d-inline-flex align-items-center gap-1"
        @click="openAdd"
      >
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Add Packaging
      </button>
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
                placeholder="Search by name"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search packaging by name"
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
            v-model="category"
            class="form-select sip-account-select"
            aria-label="Filter by category"
            :disabled="loading"
            @change="onCategoryChange"
          >
            <option value="">All Categories</option>
            <option v-for="opt in CATEGORIES" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>

          <select
            v-model="type"
            class="form-select sip-account-select"
            aria-label="Filter by type"
            :disabled="loading"
            @change="load(1)"
          >
            <option value="">All Types</option>
            <template v-if="category">
              <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </template>
            <template v-else>
              <optgroup label="Packaging">
                <option v-for="opt in TYPES.packaging" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </optgroup>
              <optgroup label="Packaging Materials">
                <option v-for="opt in TYPES.packaging_materials" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </optgroup>
            </template>
          </select>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Name</th>
              <th class="staff-table-head__th" scope="col">Category</th>
              <th class="staff-table-head__th" scope="col">Type</th>
              <th class="staff-table-head__th" scope="col">Cost</th>
              <th class="staff-table-head__th" scope="col">Price</th>
              <th class="staff-table-head__th" scope="col">On Hand</th>
              <th class="staff-table-head__th text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="7" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading Packaging…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="7" class="px-4 py-5 text-center text-secondary">No packaging found.</td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle sip-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td>
                <div class="sip-product-cell">
                  <div class="sip-product-cell__img">
                    <img v-if="row.image_url" :src="row.image_url" :alt="row.name || 'Packaging'" />
                    <span v-else class="sip-product-cell__img-empty" aria-hidden="true">
                      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                      </svg>
                    </span>
                  </div>
                  <div class="sip-product-cell__name text-truncate">{{ row.name || "—" }}</div>
                </div>
              </td>
              <td class="text-body">{{ row.category_label || "—" }}</td>
              <td class="text-body">{{ row.type_label || "—" }}</td>
              <td class="text-body">{{ formatCents(row.cost_cents) }}</td>
              <td class="text-body">{{ formatCents(row.price_cents) }}</td>
              <td class="text-body">{{ Number(row.on_hand || 0).toLocaleString("en-US") }}</td>
              <td class="staff-actions-cell text-center" @click.stop>
                <div data-packaging-row-actions class="staff-actions-inner staff-actions-inner--single justify-content-center">
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
          packaging items.
        </p>
        <div class="d-flex align-items-center gap-3">
          <select class="form-select form-select-sm staff-table-footer-per-page" :value="pagination.per_page" @change="onPerPageChange">
            <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }} per page</option>
          </select>
          <nav class="staff-page-pager staff-page-pager--cluster" aria-label="Packaging pages">
            <button type="button" class="staff-page-pager-tile staff-page-pager-tile--nav" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">‹</button>
            <button
              v-for="p in pageItems"
              :key="p"
              type="button"
              class="staff-page-pager-tile"
              :class="{ 'staff-page-pager-tile--active': p === pagination.current_page }"
              @click="load(p)"
            >
              {{ p }}
            </button>
            <button type="button" class="staff-page-pager-tile staff-page-pager-tile--nav" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">›</button>
          </nav>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-packaging-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item text-danger" role="menuitem" @click="promptDelete(manageMenuRow)">Delete</button>
      </div>
    </Teleport>

    <ShopifyPackagingFormModal
      :open="addOpen"
      title="Add Packaging"
      :busy="saveBusy"
      :item="form"
      @close="addOpen = false"
      @save="saveCreate"
    />

    <ConfirmModal
      :open="deleteOpen"
      title="Delete Packaging?"
      :message="deleteTarget ? `Delete “${deleteTarget.name}”? This cannot be undone.` : 'Delete this packaging?'"
      confirm-label="Delete"
      :busy="deleteBusy"
      danger
      @close="deleteOpen = false"
      @confirm="confirmDelete"
    />
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
.sip-row {
  cursor: pointer;
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
  font-size: 0.92rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
@media (max-width: 991.98px) {
  .sip-account-select,
  .sip-search-wrap {
    max-width: none;
    width: 100%;
  }
}
</style>
