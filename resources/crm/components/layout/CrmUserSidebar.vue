<script setup>
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { useCrmSidebar } from "../../composables/useCrmSidebar";

defineProps({
  user: { type: Object, required: true },
});

const route = useRoute();
const ordersGroupOpen = ref(route.path.startsWith("/users/orders"));
const inventoryGroupOpen = ref(route.path.startsWith("/users/inventory"));

watch(
  () => route.path,
  (p) => {
    if (p.startsWith("/users/orders")) ordersGroupOpen.value = true;
    if (p.startsWith("/users/inventory")) inventoryGroupOpen.value = true;
  },
);

const { isExpanded, closeMobile, sidebarClass, toggleSidebar } = useCrmSidebar();
const markSrc = computed(() => BRAND_MARK_SRC());

function navActive(mode) {
  const p = route.path;
  if (mode === "orders") return p.startsWith("/users/orders");
  if (mode === "orders-index") return p === "/users/orders";
  if (mode === "orders-awaiting") return p.startsWith("/users/orders/ready-to-ship");
  if (mode === "orders-on-hold") return p.startsWith("/users/orders/on-hold");
  if (mode === "orders-backorder") return p.startsWith("/users/orders/backorder");
  if (mode === "orders-shipped") return p.startsWith("/users/orders/shipped");
  if (mode === "inventory") return p.startsWith("/users/inventory");
  return false;
}

function collapseNav() {
  if (typeof window !== "undefined" && window.innerWidth >= 992) {
    toggleSidebar();
  }
}
</script>

<template>
  <aside :class="sidebarClass">
    <div class="vx-sidebar__header">
      <RouterLink
        v-if="isExpanded"
        to="/users/orders"
        class="vx-sidebar__brand-link"
        @click="closeMobile"
      >
        <img :src="markSrc" alt="" class="crm-vertical-nav__brand-logo" width="40" height="40" />
        <span class="crm-vertical-nav__brand-text text-truncate">Save Rack</span>
      </RouterLink>
      <RouterLink
        v-else
        to="/users/orders"
        class="vx-sidebar__brand-link justify-content-center w-100"
        @click="closeMobile"
      >
        <img :src="markSrc" alt="" class="crm-vertical-nav__brand-logo" width="40" height="40" />
      </RouterLink>
      <button
        v-if="isExpanded"
        type="button"
        class="vx-sidebar__collapse-btn"
        aria-label="Collapse navigation"
        @click="collapseNav"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle cx="8" cy="12" r="3" stroke="currentColor" stroke-width="2" />
          <circle cx="16" cy="12" r="3" stroke="currentColor" stroke-width="2" />
        </svg>
      </button>
    </div>

    <nav class="vx-sidebar__scroll">
      <h2 v-if="isExpanded" class="vx-section-label">Menu</h2>
      <ul class="list-unstyled mb-0 pb-2">
        <li>
          <div v-if="isExpanded">
            <button
              type="button"
              class="vx-nav-link"
              :class="{ 'vx-nav-link--active': navActive('orders') }"
              :aria-expanded="ordersGroupOpen"
              @click="ordersGroupOpen = !ordersGroupOpen"
            >
              <span class="text-truncate">Orders</span>
            </button>
            <ul v-show="ordersGroupOpen" class="list-unstyled mb-0 mt-1">
              <li><RouterLink to="/users/orders" class="vx-nav-link vx-nav-sublink" :class="{ 'vx-nav-link--active': navActive('orders-index') }" @click="closeMobile">All</RouterLink></li>
              <li><RouterLink to="/users/orders/ready-to-ship" class="vx-nav-link vx-nav-sublink" :class="{ 'vx-nav-link--active': navActive('orders-awaiting') }" @click="closeMobile">Ready To Ship</RouterLink></li>
              <li><RouterLink to="/users/orders/on-hold" class="vx-nav-link vx-nav-sublink" :class="{ 'vx-nav-link--active': navActive('orders-on-hold') }" @click="closeMobile">On-Hold</RouterLink></li>
              <li><RouterLink to="/users/orders/backorder" class="vx-nav-link vx-nav-sublink" :class="{ 'vx-nav-link--active': navActive('orders-backorder') }" @click="closeMobile">Backorder</RouterLink></li>
              <li><RouterLink to="/users/orders/shipped" class="vx-nav-link vx-nav-sublink" :class="{ 'vx-nav-link--active': navActive('orders-shipped') }" @click="closeMobile">Shipped</RouterLink></li>
            </ul>
          </div>
          <RouterLink
            v-else
            to="/users/orders"
            class="vx-nav-link"
            title="Orders"
            @click="closeMobile"
          >
            <span>O</span>
          </RouterLink>
        </li>
        <li>
          <RouterLink
            to="/users/inventory"
            class="vx-nav-link"
            :class="{ 'vx-nav-link--active': navActive('inventory') }"
            :title="!isExpanded ? 'Inventory' : undefined"
            @click="closeMobile"
          >
            <span v-if="isExpanded">Inventory</span>
            <span v-else>I</span>
          </RouterLink>
        </li>
      </ul>
    </nav>
  </aside>
</template>
