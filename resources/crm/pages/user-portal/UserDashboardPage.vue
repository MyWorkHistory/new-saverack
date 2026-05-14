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

/** Paths: Material Icons 24px (fill), same stroke style as billing invoice sidebar cards */
const cards = computed(() => [
  {
    key: "ready_to_ship",
    label: "Ready To Ship",
    sub: "Open orders ready to fulfill",
    value: counts.value.ready_to_ship,
    to: "/users/orders/ready-to-ship",
    iconClass: "text-white",
    iconStyle: { background: "#2563eb" },
    iconPath:
      "M20 8h-3V4H3v13h3c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5S5.17 15.5 6 15.5s1.5.67 1.5 1.5S6.83 18.5 6 18.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z",
  },
  {
    key: "on_hold",
    label: "On-Hold",
    sub: "Paused in ShipHero",
    value: counts.value.on_hold,
    to: "/users/orders/on-hold",
    iconClass: "text-white",
    iconStyle: { background: "#d97706" },
    iconPath:
      "M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z",
  },
  {
    key: "backorder",
    label: "Backorder",
    sub: "Awaiting inventory or allocation",
    value: counts.value.backorder,
    to: "/users/orders/backorder",
    iconClass: "text-white",
    iconStyle: { background: "#dc2626" },
    iconPath:
      "M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z",
  },
  {
    key: "shipped",
    label: "Shipped",
    sub: "Last 30 days",
    value: counts.value.shipped,
    to: "/users/orders/shipped",
    iconClass: "bg-success-subtle text-success",
    iconStyle: {},
    iconPath: "M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z",
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
    description: "Order queue summary for your account.",
  });
  loadCounts();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Dashboard</h1>
        <p class="staff-page__intro mb-0">
          ShipHero order queues for your account. Select a card to open that list.
        </p>
      </div>
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
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
      <p v-if="counts.truncated" class="small text-warning mb-3">
        One or more totals may be capped at the maximum page scan; use the order list for the full queue.
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
              class="staff-stat-card__icon"
              :class="c.iconClass"
              :style="c.iconStyle"
              aria-hidden="true"
            >
              <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                <path :d="c.iconPath" />
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

.user-dashboard-summary-link .staff-stat-card__icon {
  width: 6.2rem;
  height: 6.2rem;
  border-radius: 0.6rem;
}

.user-dashboard-summary-link .staff-stat-card__icon svg {
  width: 1.85rem;
  height: 1.85rem;
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
