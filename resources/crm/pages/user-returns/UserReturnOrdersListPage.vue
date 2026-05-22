<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
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

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const tableColspan = 7;

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

async function load() {
  if (!clientAccountId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get("/returns", {
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
    if (data.meta) meta.value = { ...meta.value, ...data.meta };
  } catch (e) {
    toast.errorFrom(e, "Could not load returned orders.");
  } finally {
    loading.value = false;
  }
}

function openRow(r) {
  router.push({ name: "user-return-detail", params: { id: String(r.id) } });
}

function goCreate() {
  router.push({ name: "user-return-create-search" });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Returned Orders",
    description: "Returns created for your account.",
  });
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Returned Orders</h1>
        <p class="staff-page__intro mb-0">RMA returns for your account. Search by order #, name, or RMA #.</p>
      </div>
      <button type="button" class="btn btn-primary staff-page-primary" @click="goCreate">Create a Return</button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <input
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search order #, name, or RMA #"
            autocomplete="off"
            aria-label="Search returns"
            @keydown.enter.prevent="load"
          />
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('status')">
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{ sortIndicator("status") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('order_number')">
                  Order #
                  <span v-if="sortIndicator('order_number')" class="staff-sort-ind">{{ sortIndicator("order_number") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('customer_name')">
                  Name
                  <span v-if="sortIndicator('customer_name')" class="staff-sort-ind">{{ sortIndicator("customer_name") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('rma_number')">
                  RMA #
                  <span v-if="sortIndicator('rma_number')" class="staff-sort-ind">{{ sortIndicator("rma_number") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('items_count')">
                  Items
                  <span v-if="sortIndicator('items_count')" class="staff-sort-ind">{{ sortIndicator("items_count") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('return_type')">
                  Type
                  <span v-if="sortIndicator('return_type')" class="staff-sort-ind">{{ sortIndicator("return_type") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-actions-col text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading returns…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">No returned orders yet.</td>
            </tr>
            <tr v-for="r in rows" v-else :key="r.id" class="align-middle">
              <td class="text-center">
                <span class="badge rounded-pill fw-medium" :class="returnStatusBadgeClass(r.status)">
                  {{ returnStatusLabel(r.status) }}
                </span>
              </td>
              <td class="text-center fw-semibold">{{ r.order_number || "—" }}</td>
              <td class="text-center">{{ r.customer_name || "—" }}</td>
              <td class="text-center fw-semibold">{{ formatRmaLabel(r.rma_number) }}</td>
              <td class="text-center">{{ Number(r.items_count ?? 0).toLocaleString() }}</td>
              <td class="text-center">{{ returnTypeLabel(r.return_type) }}</td>
              <td class="text-center">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
                  @click="openRow(r)"
                >
                  View
                </button>
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
    </div>
  </div>
</template>
