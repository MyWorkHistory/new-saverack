<script setup>
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();

const loading = ref(true);
const rows = ref([]);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/admin/returns/bins");
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load return bins.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function openBin(row) {
  const binNumber = Number(row?.bin_number || 0);
  if (binNumber <= 0) return;
  router.push({ name: "admin-return-bin-detail", params: { binNumber: String(binNumber) } });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Return Bins",
    description: "Physical return bins awaiting restock.",
  });
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-returns-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Return Bins</h1>
        <p class="small admin-returns-list__subtitle mb-0">
          Items staged in return bins before restock to pick locations.
        </p>
      </div>
    </div>

    <div class="admin-returns-list staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">Bin #</th>
              <th class="staff-table-head__th text-center" scope="col">Items</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="2" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading return bins…" />
                </div>
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="`bin-${row.bin_number}`"
              class="align-middle admin-returns-result-row"
              role="button"
              tabindex="0"
              @click="openBin(row)"
              @keydown.enter.prevent="openBin(row)"
            >
              <td class="text-center fw-semibold">{{ row.bin_number }}</td>
              <td class="text-center">{{ row.items_count ?? 0 }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-returns-list__subtitle {
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--bs-secondary-color, #6c757d);
}

.admin-returns-result-row {
  cursor: pointer;
}
</style>
