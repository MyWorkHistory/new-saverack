<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
      <div>
        <h1 class="h4 mb-1">Shopify Inventory</h1>
        <p class="text-secondary small mb-0">
          Active Shopify products and location quantities (Shopify-only; not ShipHero stock).
        </p>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        :disabled="loading"
        @click="load"
      >
        Refresh
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar d-flex flex-wrap gap-2 p-3 border-bottom">
        <input
          v-model="q"
          type="search"
          class="form-control form-control-sm"
          style="max-width: 16rem"
          placeholder="Search SKU or title…"
          @keydown.enter.prevent="load"
        />
        <button
          type="button"
          class="btn btn-sm btn-primary"
          @click="load"
        >
          Search
        </button>
      </div>

      <div
        v-if="loading"
        class="p-4"
      >
        <CrmLoadingSpinner label="Loading Inventory…" />
      </div>
      <div
        v-else-if="!rows.length"
        class="p-4 text-secondary small"
      >
        No active Shopify variants imported yet.
      </div>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Product</th>
              <th>Variant</th>
              <th>Available</th>
              <th>Account</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in rows"
              :key="row.id"
            >
              <td class="fw-semibold">{{ row.sku || "—" }}</td>
              <td>{{ row.product_title || "—" }}</td>
              <td>{{ row.title || "—" }}</td>
              <td>{{ row.available_total }}</td>
              <td>{{ row.account_name || "—" }}</td>
              <td class="text-end">
                <RouterLink
                  class="btn btn-sm btn-outline-primary"
                  :to="{ name: 'webmaster-shopify-inventory-detail', params: { id: String(row.id) } }"
                >
                  Edit
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const toast = useToast();
const loading = ref(false);
const rows = ref([]);
const q = ref("");

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/shopify/inventory", {
      params: { q: q.value || undefined, per_page: 50 },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load Shopify inventory.");
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Inventory",
    description: "Shopify products and inventory levels.",
  });
  void load();
});
</script>
