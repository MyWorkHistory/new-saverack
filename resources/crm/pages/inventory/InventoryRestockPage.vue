<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const router = useRouter();

const POLL_MS = 5000;
const STALE_CLIENT_MS = 20 * 60 * 1000;

const restockRows = ref([]);
const restockLoading = ref(false);
const restockRefreshing = ref(false);
const pollingActive = ref(false);
const restockPreviewLoading = ref(false);
const restockMeta = ref({
  warehouse_id: null,
  computed_at: null,
  refresh_started_at: null,
  row_count: 0,
  status: null,
  error_message: null,
  progress_page: null,
  scan_stats: null,
});

let pollTimer = null;

const isFailed = computed(() => restockMeta.value.status === "failed");

const showRunningBanner = computed(
  () => pollingActive.value && restockMeta.value.status === "running"
);

const isRefreshStuck = computed(() => {
  if (restockMeta.value.status !== "running") return false;
  const raw = restockMeta.value.refresh_started_at;
  if (!raw) return false;
  const started = new Date(raw);
  if (Number.isNaN(started.getTime())) return false;
  return Date.now() - started.getTime() > STALE_CLIENT_MS;
});

const restockLastRunLabel = computed(() => {
  const raw = restockMeta.value.computed_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

const refreshStartedLabel = computed(() => {
  const raw = restockMeta.value.refresh_started_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

const scanStatsLabel = computed(() => {
  const stats = restockMeta.value.scan_stats;
  if (!stats || typeof stats !== "object") return null;
  const scanned = Number(stats.products_scanned || 0);
  const matched = Number(stats.products_matched ?? restockMeta.value.row_count ?? 0);
  const maxQty = stats.max_pickable_qty ?? 2;
  if (scanned <= 0) return null;
  return `Scanned ${scanned.toLocaleString()} products — ${matched.toLocaleString()} had pickable qty 0–${maxQty}.`;
});

function applyRestockMeta(data) {
  restockMeta.value = {
    warehouse_id: data?.warehouse_id ?? null,
    computed_at: data?.computed_at ?? null,
    refresh_started_at: data?.refresh_started_at ?? null,
    row_count: Number(data?.row_count || 0),
    status: data?.status ?? null,
    error_message: data?.error_message ?? null,
    progress_page: data?.progress_page ?? null,
    scan_stats: data?.scan_stats ?? null,
  };
}

function applyRestockPayload(data) {
  if (Array.isArray(data?.rows) && data.rows.length > 0) {
    restockRows.value = data.rows;
  }
  applyRestockMeta(data);
}

/** Status-only poll — never downloads the full rows JSON blob. */
async function fetchRestockMeta() {
  const { data } = await api.get("/inventory/restock");
  applyRestockMeta(data);
  return data;
}

/** Full snapshot with SKU rows (can be large — only after refresh completes). */
async function fetchRestockFull() {
  const { data } = await api.get("/inventory/restock", { params: { full: 1 } });
  applyRestockPayload(data);
  return data;
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
  pollingActive.value = false;
  if (pollTimer !== null) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
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
    const data = await fetchRestockMeta();
    const status = data?.status;
    if (status === "ok") {
      await fetchRestockFull();
      finishRefreshUi(`Restock report updated (${restockMeta.value.row_count} SKUs).`);
      return;
    }
    if (status === "failed") {
      restockRefreshing.value = false;
      stopPolling();
      toast.error(data?.error_message || "Restock report refresh failed.");
    }
  } catch (e) {
    restockRefreshing.value = false;
    stopPolling();
    toast.errorFrom(e, "Could not load restock report.");
  }
}

function startPolling() {
  stopPolling();
  pollingActive.value = true;
  pollTimer = setInterval(() => {
    pollRestockOnce();
  }, POLL_MS);
}

async function loadRestockReport() {
  restockLoading.value = true;
  try {
    const data = await fetchRestockMeta();
    if (data?.status === "ok" && Number(data?.row_count || 0) > 0) {
      await fetchRestockFull();
    } else if (data?.status !== "running") {
      restockRows.value = [];
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

async function previewRestockReport() {
  restockPreviewLoading.value = true;
  try {
    const { data } = await api.get("/inventory/restock/preview", { params: { max_pages: 10 } });
    const count = Number(data?.match_count || 0);
    const scanned = Number(data?.products_scanned || 0);
    const partial = data?.partial ? " (partial — first 10 pages only)" : "";
    toast.success(`Preview: ${count} matches from ${scanned} products scanned${partial}.`);
  } catch (e) {
    toast.errorFrom(e, "Could not preview restock report.");
  } finally {
    restockPreviewLoading.value = false;
  }
}

async function refreshRestockReport() {
  restockRefreshing.value = true;
  try {
    const response = await api.post("/inventory/restock/refresh");
    const { data } = response;
    applyRestockMeta(data);
    restockRows.value = [];
    if (response.status === 202 || data?.status === "running") {
      startPolling();
      await pollRestockOnce();
      return;
    }
    if (data?.status === "ok") {
      await fetchRestockFull();
      finishRefreshUi(`Restock report updated (${restockMeta.value.row_count} SKUs).`);
      return;
    }
    if (data?.status === "failed") {
      finishRefreshUi(null);
      toast.error(data?.error_message || "Restock report refresh failed.");
      return;
    }
    await fetchRestockFull();
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
    description: "SKUs with pickable qty 0–2 in pickable warehouse locations.",
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
        <h1 class="h4 fw-semibold text-body mb-1">Restock</h1>
        <p class="text-secondary small mb-0">
          SKUs with pickable qty 0–2 in pickable locations. Refreshes automatically at 7:00 AM, 12:00 PM, and 2:30 PM (US Eastern).
        </p>
      </div>
      <div class="d-flex flex-column align-items-md-end gap-2 flex-shrink-0 ms-md-auto">
        <p v-if="restockLastRunLabel" class="small text-secondary mb-0">
          Last successful run: {{ restockLastRunLabel }}
        </p>
        <p v-if="refreshStartedLabel && showRunningBanner" class="small text-secondary mb-0">
          Refresh started: {{ refreshStartedLabel }}
        </p>
        <div class="d-flex flex-wrap gap-2 justify-content-md-end">
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
            :disabled="restockLoading || restockPreviewLoading || (restockRefreshing && showRunningBanner)"
            title="Preview match count from first 10 pages"
            aria-label="Preview restock match count"
            @click="previewRestockReport"
          >
            {{ restockPreviewLoading ? "Previewing…" : "Preview" }}
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
            :disabled="restockLoading || (restockRefreshing && showRunningBanner)"
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
          {{ restockRefreshing && showRunningBanner ? "Refreshing…" : "Refresh" }}
          </button>
        </div>
      </div>
    </div>

    <div
      v-if="scanStatsLabel && !showRunningBanner"
      class="alert alert-info py-2 px-3 small mb-3"
      role="status"
    >
      {{ scanStatsLabel }}
    </div>

    <div
      v-if="isFailed && restockMeta.error_message"
      class="alert alert-danger py-2 px-3 small mb-3"
      role="alert"
    >
      {{ restockMeta.error_message }}
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div
        v-if="showRunningBanner"
        class="user-inv-sync-banner small text-secondary px-3 py-2 border-bottom bg-body-tertiary"
        role="status"
        aria-live="polite"
      >
        <template v-if="isRefreshStuck">
          Refresh appears stuck — click Refresh to retry (ensure a queue worker is running).
        </template>
        <template v-else>
          Building restock report from ShipHero…
          <span v-if="restockMeta.progress_page"> (page {{ restockMeta.progress_page }})</span>
        </template>
      </div>
      <div
        class="table-responsive staff-table-wrap"
        :class="{ 'user-inv-table--syncing': showRunningBanner }"
      >
        <table class="table table-hover align-middle mb-0 staff-data-table user-inv-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th user-inv-table__text-col" scope="col">SKU</th>
              <th class="staff-table-head__th user-inv-table__text-col" scope="col">Name</th>
              <th class="staff-table-head__th" scope="col">Pick Location</th>
              <th class="staff-table-head__th text-center" scope="col">Pick QTY</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="restockLoading">
              <td colspan="4" class="py-5 text-center text-secondary">Loading restock report…</td>
            </tr>
            <tr v-else-if="!restockLastRunLabel && !restockRows.length && !showRunningBanner && !isFailed">
              <td colspan="4" class="py-5 text-center text-secondary">
                No restock report yet. Click Preview or Refresh to scan ShipHero.
              </td>
            </tr>
            <tr v-else-if="restockRows.length === 0 && !showRunningBanner">
              <td colspan="4" class="py-5 text-center text-secondary">Nothing to restock right now.</td>
            </tr>
            <tr v-else-if="restockRows.length === 0 && showRunningBanner">
              <td colspan="4" class="py-5 text-center text-secondary">Refresh in progress…</td>
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
  min-width: 36rem;
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
