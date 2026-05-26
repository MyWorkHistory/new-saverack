<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { usePortalDashboardCounts } from "../../composables/usePortalDashboardCounts.js";
import { usePortalOutOfStockPreview } from "../../composables/usePortalOutOfStockPreview.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const shipheroReady = computed(() => Boolean(crmUser.value?.shiphero_ready));

const { counts, loading, refreshing, loadCounts, refreshCounts, lastRefreshedLabel } = usePortalDashboardCounts(
  () => clientAccountId.value,
  {
    getShipheroReady: () => shipheroReady.value,
    onError: (e) => toast.errorFrom(e, "Could not load order counts."),
  },
);

const {
  loading: oosLoading,
  topRows: oosTopRows,
  loadPreview: loadOosPreview,
} = usePortalOutOfStockPreview(() => clientAccountId.value, {
  getShipheroReady: () => counts.value.shiphero_ready && shipheroReady.value,
  onError: (e) => toast.errorFrom(e, "Could not load out-of-stock inventory."),
});

/**
 * Material Symbols paths (24×24 viewBox), sourced from Iconify’s Material Symbols set
 * (same glyphs as Google Fonts Material Symbols).
 */
const DASHBOARD_ICON = {
  readyBox:
    "M5 22q-.825 0-1.412-.587T3 20V8.725q-.45-.275-.725-.712T2 7V4q0-.825.588-1.412T4 2h16q.825 0 1.413.588T22 4v3q0 .575-.275 1.013T21 8.724V20q0 .825-.587 1.413T19 22zM4 7h16V4H4zm5 7h6v-2H9z",
  readyCheck: "M10.6 16.6l7.05-7.05l-1.4-1.4l-5.65 5.65l-2.85-2.85l-1.4 1.4z",
  hourglass:
    "M8 20h8v-3q0-1.65-1.175-2.825T12 13t-2.825 1.175T8 17zm6.825-10.175Q16 8.65 16 7V4H8v3q0 1.65 1.175 2.825T12 11t2.825-1.175M4 22v-2h2v-3q0-1.525.713-2.863T8.7 12q-1.275-.8-1.987-2.137T6 7V4H4V2h16v2h-2v3q0 1.525-.712 2.863T15.3 12q1.275.8 1.988 2.138T18 17v3h2v2z",
  shelves: "M3 23V1h2v2h14V1h2v22h-2v-2H5v2zm2-12h2V7h6v4h6V5H5zm0 8h6v-4h6v4h2v-6H5z",
  truck:
    "M3.875 19.125Q3 18.25 3 17H1V6q0-.825.588-1.412T3 4h14v4h3l3 4v5h-2q0 1.25-.875 2.125T18 20t-2.125-.875T15 17H9q0 1.25-.875 2.125T6 20t-2.125-.875m2.838-1.412Q7 17.425 7 17t-.288-.712T6 16t-.712.288T5 17t.288.713T6 18t.713-.288m12 0Q19 17.426 19 17t-.288-.712T18 16t-.712.288T17 17t.288.713T18 18t.713-.288M17 13h4.25L19 10h-2z",
  chart:
    "M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z",
};

const OOS_PANEL_ICON_STYLE = { background: "#ffe4e6", color: "#be123c" };
const COMING_SOON_ICON_STYLE = { background: "#f3f4f6", color: "#6b7280" };

const accountDisplayName = computed(() => {
  const u = crmUser.value;
  if (!u) {
    return "";
  }
  const name = String(u.client_account_company_name || "").trim();
  if (name) {
    return name;
  }
  const id = Number(u.client_account_id || 0);
  if (id > 0) {
    return `Account #${id}`;
  }
  return "";
});

const cards = computed(() => [
  {
    key: "ready_to_ship",
    label: "Ready To Ship",
    sub: "Orders awaiting shipment",
    value: counts.value.ready_to_ship,
    to: "/users/orders/ready-to-ship",
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
  },
  {
    key: "on_hold",
    label: "On-Hold",
    sub: "On-Hold Orders needing your attention!",
    value: counts.value.on_hold,
    to: "/users/orders/on-hold",
    iconStyle: { background: "#fef3c7", color: "#b45309" },
  },
  {
    key: "backorder",
    label: "Backorder",
    sub: "Orders with items out of stock.",
    value: counts.value.backorder,
    to: "/users/orders/backorder",
    iconStyle: OOS_PANEL_ICON_STYLE,
  },
  {
    key: "shipped",
    label: "Shipped",
    sub: "Orders shipped today!",
    value: counts.value.shipped,
    to: "/users/orders/shipped",
    iconStyle: { background: "#dcfce7", color: "#166534" },
  },
]);

function inventoryDetailTo(sku) {
  const s = String(sku || "").trim();
  if (!s) {
    return { name: "user-inventory" };
  }
  return {
    name: "user-inventory-detail",
    params: { sku: s },
    query: { client_account_id: String(clientAccountId.value) },
  };
}

function syncPageMeta() {
  const name = accountDisplayName.value;
  setCrmPageMeta({
    title: name ? `Save Rack | ${name}` : "Save Rack",
    description: "",
  });
}

async function onRefreshDashboard() {
  await refreshCounts();
  await loadOosPreview({ bustCache: true });
}

watch(accountDisplayName, syncPageMeta, { immediate: true });

watch(
  () => [clientAccountId.value, counts.value.shiphero_ready, shipheroReady.value],
  ([id, countsReady, userReady]) => {
    if (id && countsReady && userReady) {
      loadOosPreview();
    }
  },
  { immediate: true },
);

onMounted(() => {
  loadCounts();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <h1 class="h4 mb-0 fw-semibold text-body">{{ accountDisplayName || "Home" }}</h1>
      <div class="d-flex align-items-center gap-2 flex-shrink-0">
        <p v-if="lastRefreshedLabel" class="small text-secondary mb-0">
          Last refreshed: {{ lastRefreshedLabel }}
        </p>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
          :disabled="loading || refreshing"
          title="Refresh"
          aria-label="Refresh dashboard"
          @click="onRefreshDashboard"
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
          {{ refreshing ? "Refreshing…" : "Refresh" }}
        </button>
        <span v-if="refreshing" class="small text-secondary">Updating…</span>
      </div>
    </div>

    <div class="user-dashboard__content position-relative">
      <div
        v-if="loading"
        class="user-dashboard__loading-overlay d-flex align-items-center justify-content-center"
        aria-busy="true"
        aria-live="polite"
      >
        <CrmLoadingSpinner message="Loading counts…" :center="true" />
      </div>

      <template v-if="!loading">
        <div
          v-if="!counts.shiphero_ready"
          class="alert alert-info small mb-3"
          role="status"
        >
          {{ counts.message || "Your warehouse connection is still being set up." }}
          <RouterLink to="/users/welcome" class="ms-1">Learn more</RouterLink>
        </div>
        <div v-if="counts.stale && counts.message" class="alert alert-warning small mb-3" role="status">
          {{ counts.message }}
        </div>
        <p v-if="counts.truncated" class="small text-secondary mb-3">
          One or more totals may be capped at the maximum page scan; open the order list for the full queue.
        </p>

        <div class="row g-3 mb-4">
          <div v-for="c in cards" :key="c.key" class="col-12 col-sm-6 col-xl-3">
            <RouterLink
              :to="c.to"
              class="staff-stat-card user-dashboard-summary-link h-100 text-start text-decoration-none text-body d-block"
            >
              <p class="staff-stat-card__label">{{ c.label }}</p>
              <p class="staff-stat-card__value">{{ c.value.toLocaleString() }}</p>
              <p class="staff-stat-card__sub">{{ c.sub }}</p>
              <div
                class="staff-stat-card__icon user-dashboard-stat-icon"
                :style="c.iconStyle"
                aria-hidden="true"
              >
                <svg
                  v-if="c.key === 'ready_to_ship'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path :d="DASHBOARD_ICON.readyBox" />
                  <path
                    transform="translate(10.25 9.25) scale(0.48)"
                    :d="DASHBOARD_ICON.readyCheck"
                  />
                </svg>
                <svg
                  v-else-if="c.key === 'on_hold'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path :d="DASHBOARD_ICON.hourglass" />
                </svg>
                <svg
                  v-else-if="c.key === 'backorder'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path :d="DASHBOARD_ICON.shelves" />
                </svg>
                <svg
                  v-else-if="c.key === 'shipped'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path :d="DASHBOARD_ICON.truck" />
                </svg>
              </div>
            </RouterLink>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12 col-lg-6 d-flex">
            <section class="staff-surface p-3 p-md-4 user-dashboard-panel h-100 w-100 d-flex flex-column">
              <div class="user-dashboard-panel__header d-flex align-items-start gap-3 mb-3">
                <div
                  class="user-dashboard-panel__icon flex-shrink-0"
                  :style="OOS_PANEL_ICON_STYLE"
                  aria-hidden="true"
                >
                  <svg class="user-dashboard-stat-svg" fill="currentColor" viewBox="0 0 24 24">
                    <path :d="DASHBOARD_ICON.shelves" />
                  </svg>
                </div>
                <div class="min-w-0">
                  <h2 class="staff-user-section-title mb-1">Out of Stock</h2>
                  <p class="small text-secondary mb-0">
                    Inventory that is currently out of stock with orders on hold.
                  </p>
                </div>
              </div>

              <div class="user-dashboard-panel__body flex-grow-1 position-relative">
                <div
                  v-if="oosLoading"
                  class="user-dashboard-panel__loading d-flex align-items-center justify-content-center py-4"
                  aria-busy="true"
                >
                  <CrmLoadingSpinner message="Loading…" :center="true" />
                </div>
                <template v-else-if="!counts.shiphero_ready">
                  <p class="small text-secondary mb-0 py-3">
                    Out-of-stock inventory will appear here once your warehouse connection is ready.
                  </p>
                </template>
                <template v-else>
                  <div class="table-responsive user-dashboard-oos-table-wrap">
                    <table class="table table-sm align-middle mb-0 staff-data-table user-dashboard-oos-table">
                      <thead class="table-light staff-table-head">
                        <tr>
                          <th class="staff-table-head__th text-center user-dashboard-oos-table__image-col" scope="col">
                            Image
                          </th>
                          <th class="staff-table-head__th user-dashboard-oos-table__sku-col" scope="col">SKU</th>
                          <th class="staff-table-head__th" scope="col">Name</th>
                          <th class="staff-table-head__th text-center" scope="col">Oversold</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-if="!oosTopRows.length">
                          <td colspan="4" class="text-center text-secondary py-4 small">
                            No out-of-stock items right now.
                          </td>
                        </tr>
                        <tr v-for="row in oosTopRows" :key="`${row.sku}-${row.warehouse_id}`">
                          <td class="text-center user-dashboard-oos-table__image-col">
                            <RouterLink
                              :to="inventoryDetailTo(row.sku)"
                              class="user-inv-table__image-link text-decoration-none"
                              :aria-label="`View ${row.sku || 'product'}`"
                            >
                              <img
                                v-if="row.image_url"
                                :src="row.image_url"
                                alt=""
                                class="user-inventory-thumb"
                                loading="lazy"
                              />
                              <div v-else class="user-inventory-thumb user-inventory-thumb--empty" />
                            </RouterLink>
                          </td>
                          <td class="user-dashboard-oos-table__sku-col">
                            <RouterLink
                              :to="inventoryDetailTo(row.sku)"
                              class="user-inv-table__sku-link text-decoration-none"
                            >
                              {{ row.sku || "—" }}
                            </RouterLink>
                          </td>
                          <td>
                            <RouterLink
                              :to="inventoryDetailTo(row.sku)"
                              class="user-inv-table__sku-link user-inv-table__name-link text-decoration-none"
                            >
                              <span class="user-inv-table__name-text">{{ row.name || "—" }}</span>
                            </RouterLink>
                          </td>
                          <td class="text-center fw-semibold">{{ Number(row.backorder || 0) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </template>
              </div>

              <div class="pt-3 mt-auto border-top">
                <RouterLink
                  to="/users/inventory/out-of-stock"
                  class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
                >
                  View All
                </RouterLink>
              </div>
            </section>
          </div>

          <div class="col-12 col-lg-6 d-flex">
            <section class="staff-surface p-3 p-md-4 user-dashboard-panel h-100 w-100 d-flex flex-column">
              <div class="user-dashboard-panel__header d-flex align-items-start gap-3 mb-3">
                <div
                  class="user-dashboard-panel__icon flex-shrink-0"
                  :style="COMING_SOON_ICON_STYLE"
                  aria-hidden="true"
                >
                  <svg class="user-dashboard-stat-svg" fill="currentColor" viewBox="0 0 24 24">
                    <path :d="DASHBOARD_ICON.chart" />
                  </svg>
                </div>
                <div class="min-w-0">
                  <h2 class="staff-user-section-title mb-1">Coming Soon</h2>
                  <p class="small text-secondary mb-0">
                    More insights and tools for your account will appear here.
                  </p>
                </div>
              </div>
              <div
                class="user-dashboard__chart-placeholder flex-grow-1 d-flex align-items-center justify-content-center text-secondary small rounded"
              >
                Coming soon
              </div>
            </section>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.user-dashboard-summary-link {
  width: 100%;
  cursor: pointer;
  text-align: left;
  font: inherit;
  color: inherit;
  border: 1px solid rgba(47, 43, 61, 0.14) !important;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    transform 0.15s ease;
}

.user-dashboard-summary-link:hover {
  border-color: rgba(115, 103, 240, 0.35) !important;
  box-shadow: 0 0.45rem 1rem rgba(47, 43, 61, 0.12);
  transform: translateY(-1px);
}

.user-dashboard-summary-link .user-dashboard-stat-icon {
  top: 50%;
  bottom: auto;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.875rem;
  height: 2.875rem;
  border-radius: 0.4375rem;
}

.user-dashboard-panel__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.875rem;
  height: 2.875rem;
  border-radius: 0.4375rem;
}

.user-dashboard-stat-svg {
  width: 1.4375rem;
  height: 1.4375rem;
  flex-shrink: 0;
  display: block;
  overflow: visible;
}

.user-dashboard__chart-placeholder {
  min-height: 180px;
  border: 1px dashed rgba(47, 43, 61, 0.18);
  background: var(--bs-body-bg, #fff);
}

[data-bs-theme="dark"] .user-dashboard__chart-placeholder {
  border-color: rgba(255, 255, 255, 0.12);
}

.user-dashboard__content {
  min-height: 22rem;
}

.user-dashboard__loading-overlay {
  position: absolute;
  inset: 0;
  z-index: 5;
  background: rgba(248, 247, 250, 0.88);
  backdrop-filter: blur(2px);
  border-radius: 0.5rem;
}

[data-bs-theme="dark"] .user-dashboard__loading-overlay {
  background: rgba(22, 22, 26, 0.88);
}

.user-dashboard-oos-table-wrap {
  margin: 0 -0.25rem;
}

.user-dashboard-oos-table {
  font-size: 0.875rem;
}

.user-dashboard-oos-table__image-col {
  width: 1%;
  min-width: 3.5rem;
}

.user-dashboard-oos-table__sku-col {
  white-space: nowrap;
}

.user-inventory-thumb {
  width: 44px;
  height: 44px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
}

.user-inventory-thumb--empty {
  display: inline-block;
  background: rgba(0, 0, 0, 0.05);
}

[data-bs-theme="dark"] .user-inventory-thumb {
  border-color: rgba(255, 255, 255, 0.12);
  background: rgba(255, 255, 255, 0.04);
}

[data-bs-theme="dark"] .user-inventory-thumb--empty {
  background: rgba(255, 255, 255, 0.08);
}

.user-inv-table__image-link {
  display: inline-block;
  line-height: 0;
}

.user-inv-table__sku-link {
  color: var(--bs-primary, #2563eb);
  font-weight: 600;
}

.user-inv-table__sku-link:hover {
  color: var(--bs-primary, #2563eb);
  text-decoration: underline !important;
}

.user-inv-table__name-link {
  font-weight: 400;
}

.user-inv-table__name-text {
  display: block;
  white-space: normal;
  word-break: break-word;
}
</style>
