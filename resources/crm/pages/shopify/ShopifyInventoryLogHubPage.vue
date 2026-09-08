<script setup>
import { onMounted, ref, watch } from "vue";
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
let searchTimer = null;

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

function openLog(row) {
  if (!row?.id) return;
  router.push({ name: "shopify-inventory-log", params: { id: String(row.id) } });
}

watch(q, () => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => void load(), 250);
});

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory Log",
    description: "Track Shopify warehouse inventory changes by product.",
  });
  void load();
});
</script>

<template>
  <div class="staff-page staff-page--wide sil-hub">
    <header class="mb-3">
      <h1 class="sil-hub__title">Inventory Log</h1>
      <p class="text-secondary mb-0">Select a product to view location inventory changes.</p>
    </header>

    <div class="sil-hub__search mb-3">
      <svg class="sil-hub__search-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
      </svg>
      <input
        v-model="q"
        type="search"
        class="form-control"
        placeholder="Search by product name or SKU…"
        aria-label="Search products"
      >
    </div>

    <div v-if="loading" class="p-5 d-flex justify-content-center">
      <CrmLoadingSpinner message="Loading…" />
    </div>

    <div v-else class="table-responsive sil-hub__table-wrap">
      <table class="table align-middle mb-0 sil-hub__table">
        <thead>
          <tr>
            <th>Product</th>
            <th>SKU</th>
            <th class="text-end">On Hand</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="4" class="text-secondary text-center py-4">No products found.</td>
          </tr>
          <tr
            v-for="row in rows"
            :key="row.id"
            class="sil-hub__row"
            @click="openLog(row)"
          >
            <td>
              <div class="sil-hub__product">
                <img
                  v-if="row.image_url"
                  :src="row.image_url"
                  :alt="row.product_title || row.sku || 'Product'"
                  class="sil-hub__thumb"
                >
                <div v-else class="sil-hub__thumb sil-hub__thumb--empty" />
                <span class="fw-semibold">{{ row.product_title || row.title || "—" }}</span>
              </div>
            </td>
            <td class="text-secondary">{{ row.sku || "—" }}</td>
            <td class="text-end fw-semibold">{{ Number(row.on_hand || 0).toLocaleString() }}</td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-outline-primary" @click.stop="openLog(row)">
                View Log
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.sil-hub__title {
  margin: 0 0 0.25rem;
  font-size: 1.65rem;
  font-weight: 700;
  color: #0f172a;
}
.sil-hub__search {
  position: relative;
  max-width: 28rem;
}
.sil-hub__search-icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  pointer-events: none;
}
.sil-hub__search .form-control {
  padding-left: 2.25rem;
}
.sil-hub__table-wrap {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  overflow: hidden;
}
.sil-hub__table thead th {
  background: #f8fafc;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #64748b;
  border-bottom: 1px solid #e5e7eb;
}
.sil-hub__row {
  cursor: pointer;
}
.sil-hub__row:hover {
  background: #f8fafc;
}
.sil-hub__product {
  display: flex;
  align-items: center;
  gap: 0.65rem;
}
.sil-hub__thumb {
  width: 36px;
  height: 36px;
  border-radius: 0.4rem;
  object-fit: cover;
  background: #f3f4f6;
}
.sil-hub__thumb--empty {
  border: 1px solid #e5e7eb;
}
</style>
