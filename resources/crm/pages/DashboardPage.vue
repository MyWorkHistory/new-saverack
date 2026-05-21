<script setup>
import { computed, inject, ref } from "vue";
import { RouterLink } from "vue-router";

const crmUser = inject("crmUser", ref(null));

const welcomeName = computed(() => {
  const name = String(crmUser.value?.name || "").trim();
  return name !== "" ? name.split(/\s+/)[0] : "";
});

/** No API calls on load — shortcuts to areas that load their own data. */
const quickNavCards = [
  {
    key: "staff",
    label: "Staff",
    description: "Manage users, roles, and permissions",
    to: "/admin/staff",
    accent: "primary",
  },
  {
    key: "billing",
    label: "Billing",
    description: "Invoices, balances, and payments",
    to: "/admin/billing/summary",
    accent: "success",
  },
  {
    key: "clients",
    label: "Client Accounts",
    description: "3PL accounts and portal users",
    to: "/admin/clients/accounts",
    accent: "info",
  },
];

const secondaryLinks = [
  { label: "All Invoices", to: "/admin/billing/invoices" },
  { label: "Orders", to: "/admin/orders/manage" },
  { label: "Inventory", to: "/admin/inventory" },
];
</script>

<template>
  <div class="vx-dashboard">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Dashboard</h1>
      <p class="small text-body-secondary mb-0">
        <template v-if="welcomeName">Welcome back, {{ welcomeName }}. </template>
        Open a section below — counts and lists load on those pages.
      </p>
    </div>

    <div class="row g-4 mb-4">
      <div
        v-for="card in quickNavCards"
        :key="card.key"
        class="col-12 col-sm-6 col-xl-4"
      >
        <RouterLink
          :to="card.to"
          class="vx-dashboard-nav-card vx-card p-4 h-100 text-decoration-none text-body d-block"
        >
          <p class="small fw-medium text-secondary mb-1">
            {{ card.label }}
          </p>
          <p class="fs-3 fw-bold mb-2">Open</p>
          <p class="small text-body-secondary mb-0">
            {{ card.description }}
          </p>
          <span
            class="vx-dashboard-nav-card__chevron mt-3 d-inline-flex align-items-center small fw-semibold"
            :class="`text-${card.accent}`"
          >
            Go to {{ card.label }}
            <svg
              width="16"
              height="16"
              class="ms-1"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 5l7 7-7 7"
              />
            </svg>
          </span>
        </RouterLink>
      </div>
    </div>

    <div class="vx-card p-4">
      <h2 class="vx-card-title mb-1">More</h2>
      <p class="vx-card-sub mb-3">Other admin areas</p>
      <div class="d-flex flex-wrap gap-2">
        <RouterLink
          v-for="link in secondaryLinks"
          :key="link.to"
          :to="link.to"
          class="btn btn-outline-secondary btn-sm"
        >
          {{ link.label }}
        </RouterLink>
      </div>
    </div>
  </div>
</template>

<style scoped>
.vx-dashboard-nav-card {
  transition:
    box-shadow 0.15s ease,
    border-color 0.15s ease,
    transform 0.15s ease;
}

.vx-dashboard-nav-card:hover {
  box-shadow: 0 0.25rem 1rem rgba(47, 43, 61, 0.08);
  transform: translateY(-1px);
}

.vx-dashboard-nav-card:focus-visible {
  outline: 2px solid var(--bs-primary);
  outline-offset: 2px;
}
</style>
