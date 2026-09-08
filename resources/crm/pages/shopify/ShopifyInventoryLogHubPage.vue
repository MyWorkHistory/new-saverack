<script setup>
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const router = useRouter();
const toast = useToast();

const loading = ref(false);
const q = ref("");
const rows = ref([]);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/shopify/inventory", {
      params: {
        q: q.value || undefined,
        status: "active",
        per_page: 25,
        page: 1,
      },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    rows.value = [];
    toast.errorFrom(e, "Could not load products.");
  } finally {
    loading.value = false;
  }
}

function applySearch() {
  void load();
}

function openLog(row) {
  if (!row?.id) return;
  router.push({ name: "shopify-inventory-log", params: { id: String(row.id) } });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory Log",
    description: "Track Shopify warehouse inventory changes by product.",
  });
  void load();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Inventory Log</h1>
        <p class="small text-secondary mb-0">Select a product to view location inventory changes.</p>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <div class="flex-grow-1" style="max-width: 28rem">
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
                placeholder="Search by product name or SKU…"
                autocomplete="off"
                aria-label="Search products"
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
        </div>
      </div>

      <div v-if="loading" class="p-5 d-flex justify-content-center">
        <CrmLoadingSpinner message="Loading…" />
      </div>

      <div v-else class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th">Product</th>
              <th class="staff-table-head__th">SKU</th>
              <th class="staff-table-head__th text-end">On Hand</th>
              <th class="staff-table-head__th text-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rows.length === 0">
              <td colspan="4" class="text-secondary text-center py-4">No products found.</td>
            </tr>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="sil-hub-row"
              @click="openLog(row)"
            >
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img
                    v-if="row.image_url"
                    :src="row.image_url"
                    :alt="row.product_title || row.sku || 'Product'"
                    class="sil-hub-thumb"
                  >
                  <div v-else class="sil-hub-thumb sil-hub-thumb--empty" />
                  <span class="fw-semibold">{{ row.product_title || row.title || "—" }}</span>
                </div>
              </td>
              <td class="text-secondary">{{ row.sku || "—" }}</td>
              <td class="text-end fw-semibold">{{ Number(row.on_hand || 0).toLocaleString() }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-primary staff-toolbar-btn"
                  @click.stop="openLog(row)"
                >
                  View Log
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sil-hub-row {
  cursor: pointer;
}
.sil-hub-thumb {
  width: 36px;
  height: 36px;
  border-radius: 0.4rem;
  object-fit: cover;
  background: #f3f4f6;
  flex-shrink: 0;
}
.sil-hub-thumb--empty {
  border: 1px solid #e5e7eb;
}
</style>
