<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { returnStatusBadgeClass, returnStatusLabel } from "../../utils/formatReturnDisplay.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const tableColspan = 10;
const actionOpenId = ref(null);

function applySearch() {
  if (searchTimer) {
    clearTimeout(searchTimer);
    searchTimer = null;
  }
  searchDebounced.value = search.value.trim().replace(/^#+/, "");
  meta.value.current_page = 1;
  load();
}

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = String(v).trim().replace(/^#+/, "");
    meta.value.current_page = 1;
    load();
  }, 300);
});

function returnDetailHref(r) {
  if (!r?.return_id) return "";
  return router.resolve({ name: "user-return-detail", params: { id: String(r.return_id) } }).href;
}

function goCreate() {
  router.push({ name: "user-return-create-search" });
}

function canDeleteReturn(r) {
  return String(r?.status || "").toLowerCase() === "pending";
}

function closeRowActionMenu() {
  actionOpenId.value = null;
}

function toggleRowActionMenu(r, event) {
  event.stopPropagation();
  actionOpenId.value = actionOpenId.value === r.id ? null : r.id;
}

function onDocClickActions(event) {
  if (!event.target?.closest?.("[data-return-row-actions]")) {
    closeRowActionMenu();
  }
}

function onEscCloseActions(event) {
  if (event.key === "Escape") {
    closeRowActionMenu();
  }
}

async function deleteReturn(r) {
  if (!r?.return_id || !canDeleteReturn(r)) return;
  const ok = window.confirm("Delete Return?");
  if (!ok) return;
  try {
    await api.delete(`/returns/${r.return_id}`);
    toast.success("Return deleted.");
    closeRowActionMenu();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete return.");
  }
}

async function load() {
  if (!clientAccountId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get("/returns/items", {
      params: {
        client_account_id: clientAccountId.value,
        q: searchDebounced.value || undefined,
        page: meta.value.current_page,
        per_page: meta.value.per_page,
      },
    });
    rows.value = data.data || [];
    if (data.meta) meta.value = { ...meta.value, ...data.meta };
  } catch (e) {
    toast.errorFrom(e, "Could not load returned items.");
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Return Items",
    description: "Line items on returns for your account.",
  });
  load();
  document.addEventListener("click", onDocClickActions);
  document.addEventListener("keydown", onEscCloseActions);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickActions);
  document.removeEventListener("keydown", onEscCloseActions);
});
</script>

<template>
  <div class="staff-page staff-page--wide user-return-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Return Items</h1>
        <p class="text-secondary small mb-0">
          Items included on returns. Search by SKU, item name, order #, or RMA #.
        </p>
      </div>
      <button type="button" class="btn btn-primary staff-page-primary" @click="goCreate">Create Return</button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <input
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search SKU, item, order #, or RMA #"
            autocomplete="off"
            aria-label="Search return items"
            @keydown.enter.prevent="applySearch"
          />
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="loading"
            @click="applySearch"
          >
            Search
          </button>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th text-center" scope="col">Order #</th>
              <th class="staff-table-head__th text-center" scope="col">SKU</th>
              <th class="staff-table-head__th text-center" scope="col">Item</th>
              <th class="staff-table-head__th text-center" scope="col">Created Date</th>
              <th class="staff-table-head__th text-center" scope="col">RMA #</th>
              <th class="staff-table-head__th text-center" scope="col">Processed Date</th>
              <th class="staff-table-head__th text-center" scope="col">Qty</th>
              <th class="staff-table-head__th text-center" scope="col">Reason</th>
              <th class="staff-table-head__th text-center staff-actions-col" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading items…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                {{ searchDebounced ? "No items match your search." : "No return items yet." }}
              </td>
            </tr>
            <tr v-for="r in rows" v-else :key="r.id" class="align-middle">
              <td class="text-center">
                <span class="badge rounded-pill fw-medium" :class="returnStatusBadgeClass(r.status)">
                  {{ returnStatusLabel(r.status) }}
                </span>
              </td>
              <td class="text-center">
                <a
                  v-if="returnDetailHref(r)"
                  :href="returnDetailHref(r)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-return-page__order-link"
                >
                  {{ r.order_number || "—" }}
                </a>
                <span v-else>—</span>
              </td>
              <td class="text-center small fw-semibold">{{ r.sku || "—" }}</td>
              <td class="text-center small">{{ r.name || "—" }}</td>
              <td class="text-center small text-secondary">{{ formatDateUs(r.created_at) || "—" }}</td>
              <td class="text-center fw-semibold">{{ r.rma_number || "—" }}</td>
              <td class="text-center small text-secondary">{{ formatDateUs(r.processed_at) || "—" }}</td>
              <td class="text-center">{{ Number(r.return_qty ?? 0).toLocaleString() }}</td>
              <td class="text-center small">{{ r.return_reason_label || "—" }}</td>
              <td class="text-center staff-actions-cell position-relative">
                <div class="user-return-page__action-inner" data-return-row-actions>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary orders-toolbar-outline-btn px-2 py-1"
                    :aria-expanded="actionOpenId === r.id"
                    aria-label="Open Row Actions"
                    @click="toggleRowActionMenu(r, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                  <div v-if="actionOpenId === r.id" class="user-return-row-menu shadow-sm">
                    <a
                      v-if="returnDetailHref(r)"
                      :href="returnDetailHref(r)"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="dropdown-item"
                    >
                      View
                    </a>
                    <button
                      type="button"
                      class="dropdown-item text-danger"
                      :disabled="!canDeleteReturn(r)"
                      @click="deleteReturn(r)"
                    >
                      Delete Return
                    </button>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="!loading && meta.last_page > 1"
        class="d-flex justify-content-between align-items-center px-3 py-3 border-top flex-wrap gap-2"
      >
        <span class="small text-secondary">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
        <div class="btn-group btn-group-sm">
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
      <p class="staff-table-mobile-scroll-cue d-md-none px-3 pb-2 mb-0" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>
    </div>
  </div>
</template>

<style scoped>
.user-return-row-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 0.25rem);
  min-width: 10.5rem;
  border: 1px solid rgba(47, 43, 61, 0.16);
  border-radius: 0.5rem;
  background: var(--bs-body-bg, #fff);
  z-index: 10;
  overflow: hidden;
}

.user-return-row-menu .dropdown-item {
  display: block;
  width: 100%;
  border: 0;
  text-align: left;
  background: transparent;
  font-size: 0.875rem;
  padding: 0.45rem 0.75rem;
  text-decoration: none;
  color: inherit;
}

[data-bs-theme="dark"] .user-return-row-menu {
  border-color: rgba(255, 255, 255, 0.16);
}
</style>
