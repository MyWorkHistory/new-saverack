<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import LtlCreateDrawer from "./LtlCreateDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import {
  formatLtlMoney,
  ltlStatusBadgeClass,
  LTL_STATUSES,
} from "../../constants/ltlSections.js";

const props = defineProps({
  /** admin | portal */
  mode: { type: String, default: "admin" },
});

const toast = useToast();
const router = useRouter();
const isPortal = computed(() => props.mode === "portal");
const apiBase = computed(() => (isPortal.value ? "/ltl-shipments" : "/admin/ltl-shipments"));
const detailRoute = computed(() => (isPortal.value ? "user-ltl-detail" : "admin-ltl-detail"));
const detailPath = computed(() => (isPortal.value ? "/users/ltl" : "/admin/receiving/ltl"));

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });

const accountsLoading = ref(false);
const accountOptions = ref([]);
const accountFilter = ref("");
const statusFilter = ref("all");
const filterMenuOpen = ref(false);
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const createOpen = ref(false);
const createBusy = ref(false);

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);

const tableColspan = computed(() => (isPortal.value ? 6 : 7));

async function loadAccounts() {
  if (isPortal.value) return;
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    const list = Array.isArray(data?.accounts)
      ? data.accounts
      : Array.isArray(data?.data)
        ? data.data
        : Array.isArray(data)
          ? data
          : [];
    accountOptions.value = list.map((a) => ({
      id: a.id,
      name: a.company_name || a.label || `Account #${a.id}`,
    }));
  } catch {
    accountOptions.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

async function load(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      per_page: meta.value.per_page || 25,
    };
    if (statusFilter.value && statusFilter.value !== "all") {
      params.status = statusFilter.value;
    }
    if (!isPortal.value && accountFilter.value) {
      params.client_account_id = accountFilter.value;
    }
    if (searchDebounced.value.trim()) {
      params.q = searchDebounced.value.trim();
    }
    const { data } = await api.get(apiBase.value, { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    meta.value = {
      current_page: Number(data?.meta?.current_page || page),
      last_page: Number(data?.meta?.last_page || 1),
      per_page: Number(data?.meta?.per_page || 25),
      total: Number(data?.meta?.total || 0),
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load LTL shipments.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function onCreate(payload) {
  createBusy.value = true;
  try {
    const body = { ...payload };
    if (isPortal.value) delete body.client_account_id;
    const { data } = await api.post(apiBase.value, body);
    createOpen.value = false;
    toast.success("LTL created.");
    const id = data?.shipment?.id;
    if (id) {
      router.push({ name: detailRoute.value, params: { id: String(id) } });
    } else {
      await load();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create LTL.");
  } finally {
    createBusy.value = false;
  }
}

function openDetail(row) {
  router.push({ name: detailRoute.value, params: { id: String(row.id) } });
}

function resetFilters() {
  statusFilter.value = "all";
  filterMenuOpen.value = false;
}

function closeManageMenu() {
  manageOpenId.value = null;
}

function openManage(row, event) {
  const btn = event?.currentTarget;
  if (!(btn instanceof HTMLElement)) return;
  if (manageOpenId.value === row.id) {
    closeManageMenu();
    return;
  }
  const rect = btn.getBoundingClientRect();
  const menuWidth = 160;
  manageMenuRect.value = {
    top: rect.bottom + 4,
    left: Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8)),
  };
  manageOpenId.value = row.id;
}

function onDocClick(e) {
  const t = e.target;
  if (!(t instanceof Element)) return;
  if (t.closest("[data-toolbar-filter]")) return;
  if (filterMenuOpen.value) filterMenuOpen.value = false;
  if (t.closest("[data-row-actions]")) return;
  closeManageMenu();
}

function onWindowCloseMenus() {
  filterMenuOpen.value = false;
  closeManageMenu();
}

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = v;
  }, 300);
});

watch([statusFilter, accountFilter, searchDebounced], () => {
  load(1);
});

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | LTL",
    description: "LTL freight quotes and shipments.",
  });
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowCloseMenus, true);
  window.addEventListener("resize", onWindowCloseMenus);
  await loadAccounts();
  await load(1);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowCloseMenus, true);
  window.removeEventListener("resize", onWindowCloseMenus);
  clearTimeout(searchTimer);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">LTL</h1>
        <p class="text-secondary small mb-0">
          Search by LTL #, company, carrier, or account.
        </p>
      </div>
      <button type="button" class="btn btn-primary staff-page-primary" @click="createOpen = true">
        Create LTL
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 ltl-list-table">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row ltl-toolbar-row">
          <div v-if="!isPortal" class="ltl-toolbar-account">
            <CrmSearchableSelect
              v-model="accountFilter"
              class="staff-toolbar-search staff-toolbar-search--inline w-100"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="All accounts"
              empty-label="All accounts"
              search-placeholder="Search accounts…"
            />
          </div>
          <div class="ltl-toolbar-search">
            <input
              id="ltl-list-search"
              v-model="search"
              type="search"
              class="form-control staff-toolbar-search staff-toolbar-search--inline w-100"
              placeholder="Search LTL #, company, or carrier"
              autocomplete="off"
              aria-label="Search LTL"
              @keydown.enter.prevent="load(1)"
            />
          </div>
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
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
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="LTL filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="resetFilters"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="ltl-filter-status">Status</label>
                <select
                  id="ltl-filter-status"
                  v-model="statusFilter"
                  class="form-select staff-datatable-filters__select"
                >
                  <option value="all">All</option>
                  <option v-for="(sMeta, key) in LTL_STATUSES" :key="key" :value="key">
                    {{ sMeta.label }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="loading" class="p-5 d-flex justify-content-center">
        <CrmLoadingSpinner message="Loading LTL…" />
      </div>
      <div v-else class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th text-center" scope="col">LTL #</th>
              <th v-if="!isPortal" class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th" scope="col">Destination</th>
              <th class="staff-table-head__th text-center" scope="col">Carrier</th>
              <th class="staff-table-head__th text-center" scope="col">Price</th>
              <th class="staff-table-head__th staff-actions-col text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rows.length === 0">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                No LTL shipments yet. Use Create LTL to get started.
              </td>
            </tr>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="align-middle cursor-pointer"
              @click="openDetail(row)"
            >
              <td class="text-center">
                <span :class="ltlStatusBadgeClass(row.status)">{{ row.status_label || row.status }}</span>
              </td>
              <td class="text-center">
                <RouterLink
                  class="fw-semibold text-decoration-none"
                  :to="{ name: detailRoute, params: { id: String(row.id) } }"
                  @click.stop
                >
                  {{ row.number }}
                </RouterLink>
              </td>
              <td v-if="!isPortal" class="text-secondary">{{ row.account_name || "—" }}</td>
              <td>{{ row.destination_label || "—" }}</td>
              <td class="text-center">{{ row.quote_carrier || "—" }}</td>
              <td class="text-center">{{ formatLtlMoney(row.quote_amount_cents) }}</td>
              <td class="staff-actions-cell text-center" @click.stop>
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
                    aria-label="Row Actions"
                    @click="(e) => openManage(row, e)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="staff-table-mobile-scroll-cue d-md-none px-3 pb-2 mb-0" aria-hidden="true">
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
            @click="load(meta.current_page - 1)"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="load(meta.current_page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
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
        <RouterLink
          class="staff-row-menu__item"
          role="menuitem"
          :to="`${detailPath}/${manageMenuRow.id}`"
          @click="closeManageMenu"
        >
          View
        </RouterLink>
      </div>
    </Teleport>

    <LtlCreateDrawer
      v-model:open="createOpen"
      :portal="isPortal"
      :busy="createBusy"
      :account-options="accountOptions"
      @submit="onCreate"
    />
  </div>
</template>

<style scoped>
.ltl-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.ltl-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.ltl-toolbar-search {
  flex: 0 0 auto;
  width: min(18rem, 100%);
}

.cursor-pointer {
  cursor: pointer;
}
</style>
