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
const loadingMore = ref(false);
const rows = ref([]);
const pageInfo = ref({ has_next_page: false, end_cursor: null });

const accountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const oversoldRows = computed(() =>
  rows.value.filter((r) => Number(r?.backorder || 0) > 0),
);

function normalizeRows(list) {
  return Array.isArray(list) ? list : [];
}

async function fetchPage(append) {
  if (!accountId.value) return;
  const params = {
    client_account_id: accountId.value,
    first: 200,
    kits: "all",
    active_status: "active",
  };
  if (append && pageInfo.value?.end_cursor) {
    params.after = pageInfo.value.end_cursor;
  }
  const { data } = await api.get("/inventory/list", { params });
  const chunk = normalizeRows(data?.rows);
  pageInfo.value = {
    has_next_page: Boolean(data?.page_info?.has_next_page),
    end_cursor: data?.page_info?.end_cursor ?? null,
  };
  if (append) {
    const seen = new Set(rows.value.map((r) => `${String(r?.sku || "")}\u0000${String(r?.warehouse_id ?? "")}`));
    for (const r of chunk) {
      const k = `${String(r?.sku || "")}\u0000${String(r?.warehouse_id ?? "")}`;
      if (!seen.has(k)) {
        seen.add(k);
        rows.value.push(r);
      }
    }
  } else {
    rows.value = chunk;
  }
}

async function loadRows(reset) {
  if (!accountId.value) return;
  if (reset) {
    loading.value = true;
    pageInfo.value = { has_next_page: false, end_cursor: null };
  } else {
    loadingMore.value = true;
  }
  try {
    await fetchPage(!reset);
  } catch (e) {
    toast.errorFrom(e, "Could not load inventory.");
  } finally {
    loading.value = false;
    loadingMore.value = false;
  }
}

function loadMore() {
  if (!pageInfo.value.has_next_page || loadingMore.value || loading.value) return;
  loadRows(false);
}

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

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Products | Out of Stock",
    description: "Inventory with oversold quantity.",
  });
  loadRows(true);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Products</h1>
      <p class="text-secondary small mb-0">Out of Stock</p>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th">Product</th>
              <th class="staff-table-head__th">SKU</th>
              <th class="staff-table-head__th text-end">Oversold</th>
              <th class="staff-table-head__th text-end">On Hand</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="4" class="text-center text-secondary py-5">Loading…</td>
            </tr>
            <tr v-else-if="!oversoldRows.length">
              <td colspan="4" class="text-center text-secondary py-5">No out-of-stock rows in the loaded inventory.</td>
            </tr>
            <tr v-for="row in oversoldRows" :key="`${row.sku}-${row.warehouse_id || ''}`">
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img
                    v-if="row.image_url"
                    :src="row.image_url"
                    alt=""
                    class="user-inventory-thumb"
                    loading="lazy"
                  />
                  <div v-else class="user-inventory-thumb user-inventory-thumb--empty flex-shrink-0" />
                  <button
                    type="button"
                    class="btn btn-link p-0 text-decoration-none text-start"
                    @click="openDetail(row)"
                  >
                    {{ row.name || "—" }}
                  </button>
                </div>
              </td>
              <td>
                <button type="button" class="btn btn-link p-0 text-decoration-none fw-semibold" @click="openDetail(row)">
                  {{ row.sku || "—" }}
                </button>
              </td>
              <td class="text-end">{{ Number(row.backorder || 0) }}</td>
              <td class="text-end">{{ Number(row.on_hand || 0) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="pageInfo.has_next_page" class="p-3 border-top text-center">
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? "Loading…" : "Load More" }}
        </button>
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
