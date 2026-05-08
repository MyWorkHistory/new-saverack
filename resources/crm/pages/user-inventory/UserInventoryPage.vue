<script setup>
import { computed, inject, onMounted, ref } from "vue";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const crmUser = inject("crmUser", ref(null));
const loading = ref(false);
const rows = ref([]);

const accountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const sortedRows = computed(() =>
  [...rows.value].sort((a, b) => Number(b?.on_hand || 0) - Number(a?.on_hand || 0)),
);

async function loadRows() {
  if (!accountId.value) return;
  loading.value = true;
  try {
    const { data } = await api.get("/inventory/list", {
      params: { client_account_id: accountId.value },
    });
    rows.value = Array.isArray(data?.rows) ? data.rows : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load inventory.");
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory",
    description: "Your account inventory.",
  });
  loadRows();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Inventory</h1>
      <p class="text-secondary small mb-0">Sorted by highest On Hand first.</p>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th">SKU</th>
              <th class="staff-table-head__th">Name</th>
              <th class="staff-table-head__th text-end">On Hand</th>
              <th class="staff-table-head__th text-end">Allocated</th>
              <th class="staff-table-head__th text-end">Backorder</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="5" class="text-center text-secondary py-5">Loading inventory...</td>
            </tr>
            <tr v-else-if="!sortedRows.length">
              <td colspan="5" class="text-center text-secondary py-5">No inventory rows found.</td>
            </tr>
            <tr v-for="row in sortedRows" :key="`${row.sku}-${row.warehouse_id || ''}`">
              <td>{{ row.sku || "—" }}</td>
              <td>{{ row.name || "—" }}</td>
              <td class="text-end">{{ Number(row.on_hand || 0) }}</td>
              <td class="text-end">{{ Number(row.allocated || 0) }}</td>
              <td class="text-end">{{ Number(row.backorder || 0) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
