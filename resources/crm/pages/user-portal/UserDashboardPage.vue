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

const cards = computed(() => [
  {
    key: "ready_to_ship",
    label: "Ready To Ship",
    value: counts.value.ready_to_ship,
    to: "/users/orders/ready-to-ship",
  },
  {
    key: "on_hold",
    label: "On-Hold",
    value: counts.value.on_hold,
    to: "/users/orders/on-hold",
  },
  {
    key: "backorder",
    label: "Backorder",
    value: counts.value.backorder,
    to: "/users/orders/backorder",
  },
  {
    key: "shipped",
    label: "Shipped",
    value: counts.value.shipped,
    to: "/users/orders/shipped",
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
  <div class="user-dashboard px-2 px-md-3 py-4">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
      <div>
        <h1 class="h4 mb-1 text-body">Dashboard</h1>
        <p class="text-body-secondary small mb-0">
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

    <div v-if="loading" class="py-5">
      <CrmLoadingSpinner message="Loading counts…" />
    </div>

    <template v-else>
      <p v-if="counts.truncated" class="small text-warning mb-3">
        One or more totals may be capped at the maximum page scan; use the order list for the full queue.
      </p>

      <div class="user-dashboard__stat-grid">
        <RouterLink
          v-for="c in cards"
          :key="c.key"
          :to="c.to"
          class="user-dashboard__stat-card vx-card text-decoration-none text-body"
        >
          <div class="user-dashboard__stat-label text-body-secondary small">{{ c.label }}</div>
          <div class="user-dashboard__stat-value">{{ c.value.toLocaleString() }}</div>
        </RouterLink>
      </div>

      <section class="vx-card mt-4 p-4 user-dashboard__analytics">
        <div class="vx-card-header-row mb-0">
          <div>
            <h2 class="vx-card-title mb-0">Analytics</h2>
            <p class="vx-card-sub mb-0">Order trends and charts will appear here in a future update.</p>
          </div>
        </div>
        <div
          class="user-dashboard__chart-placeholder d-flex align-items-center justify-content-center text-body-secondary small rounded mt-3"
        >
          Chart coming soon
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.user-dashboard {
  max-width: 1200px;
  margin-inline: auto;
}

.user-dashboard__stat-grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(1, minmax(0, 1fr));
}

@media (min-width: 576px) {
  .user-dashboard__stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 992px) {
  .user-dashboard__stat-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

.user-dashboard__stat-card {
  display: block;
  padding: 1.25rem 1.25rem 1.35rem;
  transition: box-shadow 0.15s ease, transform 0.12s ease;
}

.user-dashboard__stat-card:hover {
  box-shadow: 0 4px 18px rgba(47, 43, 61, 0.1);
  transform: translateY(-1px);
}

.user-dashboard__stat-label {
  font-weight: 500;
  letter-spacing: 0.02em;
}

.user-dashboard__stat-value {
  font-size: 1.75rem;
  font-weight: 700;
  line-height: 1.2;
  margin-top: 0.35rem;
}

.user-dashboard__chart-placeholder {
  min-height: 220px;
  border: 1px dashed var(--vx-nav-border, #e6e4ea);
  background: var(--bs-body-bg, #fff);
}
</style>
