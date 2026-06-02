<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const router = useRouter();

const POLL_MS = 3000;
const POLL_MAX_MS = 15 * 60 * 1000;

const restockRows = ref([]);
const restockLoading = ref(false);
const restockRefreshing = ref(false);
const restockMeta = ref({
  warehouse_id: null,
  computed_at: null,
  row_count: 0,
  status: null,
  error_message: null,
});

let pollTimer = null;
let pollStartedAt = 0;
let autoRefreshTriggered = false;

const isRunning = computed(() => restockMeta.value.status === "running");

const restockLastRunLabel = computed(() => {
  const raw = restockMeta.value.computed_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

function applyRestockPayload(data) {
  restockRows.value = Array.isArray(data?.rows) ? data.rows : [];
  restockMeta.value = {
    warehouse_id: data?.warehouse_id ?? null,
    computed_at: data?.computed_at ?? null,
    row_count: Number(data?.row_count || 0),
    status: data?.status ?? null,
    error_message: data?.error_message ?? null,
  };
}

function inventoryDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return "#";
  return router.resolve({
    name: "inventory-detail",
    params: { sku },
  }).href;
}

function stopPolling() {
  if (pollTimer !== null) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
  pollStartedAt = 0;
}

function finishRefreshUi(successMessage) {
  restockRefreshing.value = false;
  stopPolling();
  if (successMessage) {
    toast.success(successMessage);
  }
}

async function pollRestockOnce() {
  try {
    const { data } = await api.get("/inventory/restock");
    applyRestockPayload(data);
    const status = data?.status;
    if (status === "ok") {
      finishRefreshUi(`Restock report updated (${restockMeta.value.row_count} SKUs).`);
      return;
    }
    if (status === "failed") {
      restockRefreshing.value = false;
      stopPolling();
      const msg = data?.error_message || "Restock report refresh failed.";
      toast.error(msg);
      return;
    }
    if (pollStartedAt > 0 && Date.now() - pollStartedAt > POLL_MAX_MS) {
      restockRefreshing.value = false;
      stopPolling();
      toast.error("Restock refresh is taking longer than expected. Check back in a few minutes.");
    }
  } catch (e) {
    restockRefreshing.value = false;
    stopPolling();
    toast.errorFrom(e, "Could not load restock report.");
  }
}

function startPolling() {
  stopPolling();
  pollStartedAt = Date.now();
  pollTimer = setInterval(() => {
    pollRestockOnce();
  }, POLL_MS);
}

async function loadRestockReport() {
  restockLoading.value = true;
  try {
    const { data } = await api.get("/inventory/restock");
    applyRestockPayload(data);
    const hasNoRows = !Array.isArray(data?.rows) || data.rows.length === 0;
    if (!autoRefreshTriggered && !data?.computed_at && hasNoRows) {
      autoRefreshTriggered = true;
      await refreshRestockReport();
      return;
    }
    if (data?.status === "running") {
      restockRefreshing.value = true;
      startPolling();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load restock report.");
  } finally {
    restockLoading.value = false;
  }
}

async function refreshRestockReport() {
  restockRefreshing.value = true;
  try {
    const response = await api.post("/inventory/restock/refresh");
    const { data } = response;
    applyRestockPayload(data);
    if (response.status === 202 || data?.status === "running") {
      startPolling();
      await pollRestockOnce();
      return;
    }
    if (data?.status === "ok") {
      finishRefreshUi(`Restock report updated (${restockMeta.value.row_count} SKUs).`);
      return;
    }
    if (data?.status === "failed") {
      finishRefreshUi(null);
      toast.error(data?.error_message || "Restock report refresh failed.");
      return;
    }
    finishRefreshUi(`Restock report updated (${restockMeta.value.row_count} SKUs).`);
  } catch (e) {
    restockRefreshing.value = false;
    stopPolling();
    toast.errorFrom(e, "Could not refresh restock report.");
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory | Restock",
    description: "Warehouse restock report for pickable qty at or below replenishment minimum with backstock.",
  });
  loadRestockReport();
});

onBeforeUnmount(() => {
  stopPolling();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Restock</h1>
        <p class="text-secondary small mb-0">
          SKUs with pickable qty at or below replenishment minimum and stock in non-pickable locations. Refreshes automatically at 7:00 AM, 12:00 PM, and 2:30 PM (US Eastern).
        </p>
      </div>
      <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-md-auto">
        <p v-if="restockLastRunLabel" class="small text-secondary mb-0">
          Last run: {{ restockLastRunLabel }}
        </p>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
          :disabled="restockLoading || restockRefreshing"
          title="Refresh restock report"
          aria-label="Refresh restock report from ShipHero"
          @click="refreshRestockReport"
        >
          <svg
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
          {{ restockRefreshing ? "Refreshing…" : "Refresh" }}
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div
        v-if="restockRefreshing || isRunning"
        class="user-inv-sync-banner small text-secondary px-3 py-2 border-bottom bg-body-tertiary"
        role="status"
        aria-live="polite"
      >
        Building restock report from ShipHero…
      </div>
      <div
        class="table-responsive staff-table-wrap"
        :class="{ 'user-inv-table--syncing': restockRefreshing || isRunning }"
      >
        <table class="table table-hover align-middle mb-0 staff-data-table user-inv-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th user-inv-table__text-col" scope="col">SKU</th>
              <th class="staff-table-head__th user-inv-table__text-col" scope="col">Name</th>
              <th class="staff-table-head__th" scope="col">Pick Location</th>
              <th class="staff-table-head__th text-center" scope="col">Pick QTY</th>
              <th class="staff-table-head__th text-center" scope="col">Replenishment Min</th>
              <th class="staff-table-head__th text-center" scope="col">Backstock</th>
              <th class="staff-table-head__th" scope="col">Backstock Locations</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="restockLoading">
              <td colspan="7" class="py-5 text-center text-secondary">Loading restock report…</td>
            </tr>
            <tr v-else-if="!restockLastRunLabel && !restockRows.length && !isRunning">
              <td colspan="7" class="py-5 text-center text-secondary">
                No restock report yet. Click Refresh to build the first snapshot.
              </td>
            </tr>
            <tr v-else-if="restockRows.length === 0 && !isRunning">
              <td colspan="7" class="py-5 text-center text-secondary">Nothing to restock right now.</td>
            </tr>
            <tr v-else-if="restockRows.length === 0 && isRunning">
              <td colspan="7" class="py-5 text-center text-secondary">Refresh in progress…</td>
            </tr>
            <tr v-for="row in restockRows" :key="row.sku" class="align-middle">
              <td class="fw-semibold user-inv-table__sku-col">
                <a
                  :href="inventoryDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__sku-link"
                >
                  {{ row.sku }}
                </a>
              </td>
              <td class="user-inv-table__name-col">
                <span class="user-inv-table__name-text">{{ row.name || "—" }}</span>
              </td>
              <td>{{ row.pick_location || "—" }}</td>
              <td class="text-center">{{ row.pick_qty ?? "—" }}</td>
              <td class="text-center">{{ row.replenishment_minimum ?? "—" }}</td>
              <td class="text-center">{{ row.backstock_qty ?? "—" }}</td>
              <td>{{ row.backstock_location || "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.user-inv-table--syncing {
  opacity: 0.55;
  pointer-events: none;
}

.user-inv-table {
  table-layout: fixed;
  width: 100%;
  min-width: 48rem;
}

.user-inv-table__text-col,
.user-inv-table__sku-col,
.user-inv-table__name-col {
  text-align: start;
  vertical-align: middle;
}

.user-inv-table__sku-link {
  color: inherit;
  text-decoration: none;
  font-weight: 600;
}

.user-inv-table__sku-link:hover {
  text-decoration: underline;
}

.user-inv-table__name-text {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
