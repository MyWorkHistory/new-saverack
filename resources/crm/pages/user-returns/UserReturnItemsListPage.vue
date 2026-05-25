<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import {
  formatRmaLabel,
  returnStatusBadgeClass,
  returnStatusLabel,
  returnTypeLabel,
} from "../../utils/formatReturnDisplay.js";

const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const tableColspan = 8;

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
});
</script>

<template>
  <div class="staff-page staff-page--wide user-return-page">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Return Items</h1>
      <p class="staff-page__intro mb-0">Items included on returns. Search by SKU, item name, order #, or RMA #.</p>
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
              <th class="staff-table-head__th text-center" scope="col">RMA #</th>
              <th class="staff-table-head__th text-center" scope="col">Qty</th>
              <th class="staff-table-head__th text-center" scope="col">Type</th>
              <th class="staff-table-head__th text-center" scope="col">Reason</th>
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
              <td class="text-center fw-semibold">{{ r.order_number || "—" }}</td>
              <td class="text-center small fw-semibold">{{ r.sku || "—" }}</td>
              <td class="text-center small">{{ r.name || "—" }}</td>
              <td class="text-center fw-semibold">{{ formatRmaLabel(r.rma_number) }}</td>
              <td class="text-center">{{ Number(r.return_qty ?? 0).toLocaleString() }}</td>
              <td class="text-center">{{ returnTypeLabel(r.return_type) }}</td>
              <td class="text-center small">{{ r.return_reason_label || "—" }}</td>
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
    </div>
  </div>
</template>
