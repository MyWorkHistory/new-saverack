<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const counts = ref({
  ready_to_ship: 0,
  on_hold: 0,
  backorder: 0,
  shipped: 0,
  truncated: false,
});

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

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

/**
 * Counts from GET /api/orders/queue-counts (OrderController::queueCounts):
 * — Ready to Ship: awaiting tab, order_date last 7 calendar days through today.
 * — On-Hold / Backorder: respective tabs, order_date today (local day bounds).
 * — Shipped: fulfilled in today’s window (optional order_date_from/to override on API).
 * Defaults align with the portal orders list for each tab.
 */
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
    iconStyle: { background: "#ffe4e6", color: "#be123c" },
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

async function loadCounts() {
  if (!clientAccountId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get("/orders/queue-counts", {
      params: { client_account_id: clientAccountId.value },
    });
    counts.value = {
      ready_to_ship: Number(data?.ready_to_ship ?? 0),
      on_hold: Number(data?.on_hold ?? 0),
      backorder: Number(data?.backorder ?? 0),
      shipped: Number(data?.shipped ?? 0),
      truncated: Boolean(data?.truncated),
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load order counts.");
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Dashboard",
    description: "",
  });
  loadCounts();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex align-items-start justify-content-between gap-3 mb-4 flex-wrap">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Dashboard</h1>
        <p v-if="accountDisplayName" class="mb-0 small text-secondary">
          {{ accountDisplayName }}
        </p>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary staff-toolbar-btn btn-sm flex-shrink-0"
        :disabled="loading"
        @click="loadCounts"
      >
        Refresh
      </button>
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
                  <path
                    d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"
                  />
                  <path
                    transform="translate(11.25 10.25) scale(0.42)"
                    d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"
                  />
                </svg>
                <svg
                  v-else-if="c.key === 'on_hold'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    d="M6 2v6h.15c.54 1.38 1.69 2.45 3.15 2.83V20H4v2h16v-2h-5.3v-9.17c1.46-.38 2.61-1.45 3.15-2.83H18V2H6zm8 10.7V4H7v6.7c.58.26 1.23.42 1.92.47.12.01.24.02.37.02.13 0 .25-.01.37-.02.69-.05 1.34-.21 1.92-.47z"
                  />
                </svg>
                <svg
                  v-else-if="c.key === 'backorder'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path d="M4 7h16v2H4V7zm0 6h16v2H4v-2zm0 6h10v2H4v-2z" />
                </svg>
                <svg
                  v-else-if="c.key === 'shipped'"
                  class="user-dashboard-stat-svg"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    d="M20 8h-3V4H3v13h3c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5S5.17 15.5 6 15.5s1.5.67 1.5 1.5S6.83 18.5 6 18.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"
                  />
                </svg>
              </div>
            </RouterLink>
          </div>
        </div>

        <section class="staff-surface p-4 user-dashboard__analytics">
          <h2 class="h6 fw-semibold mb-1">Analytics</h2>
          <p class="small text-secondary mb-0">
            Order trends and charts will appear here in a future update.
          </p>
          <div
            class="user-dashboard__chart-placeholder d-flex align-items-center justify-content-center text-secondary small rounded mt-3"
          >
            Chart coming soon
          </div>
        </section>
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

.user-dashboard-stat-icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.4375rem;
}

.user-dashboard-stat-svg {
  width: 1.25rem;
  height: 1.25rem;
  display: block;
}

.user-dashboard__chart-placeholder {
  min-height: 220px;
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
</style>
