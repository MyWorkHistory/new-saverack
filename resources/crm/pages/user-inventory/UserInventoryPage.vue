<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));
const loading = ref(false);
const rows = ref([]);
const search = ref("");

const accountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const sortedRows = computed(() =>
  [...rows.value].sort((a, b) => Number(b?.on_hand || 0) - Number(a?.on_hand || 0)),
);

const filteredRows = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return sortedRows.value;
  return sortedRows.value.filter((row) =>
    String(row?.sku || "").toLowerCase().includes(q)
    || String(row?.name || "").toLowerCase().includes(q),
  );
});

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

function openDetail(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return;
  const href = router.resolve({
    name: "user-inventory-detail",
    params: { sku },
    query: { client_account_id: String(accountId.value) },
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
}
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Inventory</h1>
      <p class="text-secondary small mb-0">Sorted by highest On Hand first.</p>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <div class="flex-grow-1" style="min-width: 280px">
            <label class="form-label small text-secondary mb-1">Search</label>
            <input
              v-model="search"
              type="text"
              class="form-control"
              placeholder="Search by SKU or Product Name"
              autocomplete="off"
            />
          </div>
        </div>
      </div>
      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center">Image</th>
              <th class="staff-table-head__th">SKU</th>
              <th class="staff-table-head__th">Name</th>
              <th class="staff-table-head__th text-end">On Hand</th>
              <th class="staff-table-head__th text-end">Allocated</th>
              <th class="staff-table-head__th text-end">Backorder</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="text-center text-secondary py-5">Loading inventory...</td>
            </tr>
            <tr v-else-if="!filteredRows.length">
              <td colspan="6" class="text-center text-secondary py-5">No inventory rows found.</td>
            </tr>
            <tr v-for="row in filteredRows" :key="`${row.sku}-${row.warehouse_id || ''}`">
              <td class="text-center">
                <img
                  v-if="row.image_url"
                  :src="row.image_url"
                  alt=""
                  class="user-inventory-thumb"
                  loading="lazy"
                />
                <div v-else class="user-inventory-thumb user-inventory-thumb--empty" />
              </td>
              <td>
                <button type="button" class="btn btn-link p-0 text-decoration-none fw-semibold" @click="openDetail(row)">
                  {{ row.sku || "—" }}
                </button>
              </td>
              <td>
                <button type="button" class="btn btn-link p-0 text-decoration-none text-start" @click="openDetail(row)">
                  {{ row.name || "—" }}
                </button>
              </td>
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

<style scoped>
.user-inventory-thumb {
  width: 34px;
  height: 34px;
  border-radius: 0.35rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
}

.user-inventory-thumb--empty {
  display: inline-block;
  background: rgba(0, 0, 0, 0.05);
}
</style>
