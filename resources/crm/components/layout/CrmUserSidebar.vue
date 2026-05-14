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
const productsGroupOpen = ref(
  route.path.startsWith("/users/inventory") || route.path.startsWith("/users/asn"),
);

watch(
  () => route.path,
  (p) => {
    if (p.startsWith("/users/orders")) ordersGroupOpen.value = true;
    if (p.startsWith("/users/inventory") || p.startsWith("/users/asn")) productsGroupOpen.value = true;
  },
);

const { isExpanded, closeMobile, sidebarClass, toggleSidebar } = useCrmSidebar();
const markSrc = computed(() => BRAND_MARK_SRC());

function navActive(mode) {
  const p = route.path;
  if (mode === "dashboard") return p === "/users/dashboard";
  if (mode === "orders") return p.startsWith("/users/orders");
  if (mode === "orders-index") return p === "/users/orders";
  if (mode === "orders-awaiting") return p.startsWith("/users/orders/ready-to-ship");
  if (mode === "orders-on-hold") return p.startsWith("/users/orders/on-hold");
  if (mode === "orders-backorder") return p.startsWith("/users/orders/backorder");
  if (mode === "orders-shipped") return p.startsWith("/users/orders/shipped");
  if (mode === "products") return p.startsWith("/users/inventory") || p.startsWith("/users/asn");
  if (mode === "products-inventory") return p.startsWith("/users/inventory");
  if (mode === "products-asn") return p.startsWith("/users/asn");
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
        to="/users/dashboard"
        class="vx-sidebar__brand-link"
        @click="closeMobile"
      >
        <img :src="markSrc" alt="" class="crm-vertical-nav__brand-logo" width="40" height="40" />
        <span class="crm-vertical-nav__brand-text text-truncate">Save Rack</span>
      </RouterLink>
      <RouterLink
        v-else
        to="/users/dashboard"
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
          <RouterLink
            to="/users/dashboard"
            class="vx-nav-link"
            :class="{ 'vx-nav-link--active': navActive('dashboard') }"
            :title="!isExpanded ? 'Dashboard' : undefined"
            @click="closeMobile"
          >
            <svg
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.5"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6Zm9.75-2.25A2.25 2.25 0 0 1 15.75 6v2.25a2.25 2.25 0 0 1-2.25 2.25H12a2.25 2.25 0 0 1-2.25-2.25V6A2.25 2.25 0 0 1 12 3.75h2.25ZM6 13.5a2.25 2.25 0 0 1 2.25-2.25H10.5A2.25 2.25 0 0 1 12.75 15v2.25A2.25 2.25 0 0 1 10.5 19.5H8.25A2.25 2.25 0 0 1 6 17.25V13.5Zm9.75-2.25A2.25 2.25 0 0 1 18 13.5V15a2.25 2.25 0 0 1-2.25 2.25H15a2.25 2.25 0 0 1-2.25-2.25v-1.5a2.25 2.25 0 0 1 2.25-2.25H15Z"
              />
            </svg>
            <span v-if="isExpanded">Dashboard</span>
          </RouterLink>
        </li>
        <li>
          <div v-if="isExpanded">
            <button
              type="button"
              class="vx-nav-link"
              :class="{ 'vx-nav-link--active': navActive('orders') }"
              :aria-expanded="ordersGroupOpen"
              @click="ordersGroupOpen = !ordersGroupOpen"
            >
              <svg
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3 6.75A2.25 2.25 0 0 1 5.25 4.5h13.5A2.25 2.25 0 0 1 21 6.75v10.5A2.25 2.25 0 0 1 18.75 19.5H5.25A2.25 2.25 0 0 1 3 17.25V6.75Zm3 1.5h12M7.5 12h3m-3 3h6"
                />
              </svg>
              <span class="text-truncate">Orders</span>
              <svg
                class="ms-auto flex-shrink-0 transition"
                :class="ordersGroupOpen ? 'rotate-180' : ''"
                style="width: 1rem; height: 1rem"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
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
            <svg
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.5"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3 6.75A2.25 2.25 0 0 1 5.25 4.5h13.5A2.25 2.25 0 0 1 21 6.75v10.5A2.25 2.25 0 0 1 18.75 19.5H5.25A2.25 2.25 0 0 1 3 17.25V6.75Zm3 1.5h12M7.5 12h3m-3 3h6"
              />
            </svg>
          </RouterLink>
        </li>
        <li>
          <div v-if="isExpanded">
            <button
              type="button"
              class="vx-nav-link"
              :class="{ 'vx-nav-link--active': navActive('products') }"
              :aria-expanded="productsGroupOpen"
              @click="productsGroupOpen = !productsGroupOpen"
            >
              <svg
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"
                />
              </svg>
              <span class="text-truncate">Products</span>
              <svg
                class="ms-auto flex-shrink-0 transition"
                :class="productsGroupOpen ? 'rotate-180' : ''"
                style="width: 1rem; height: 1rem"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <ul v-show="productsGroupOpen" class="list-unstyled mb-0 mt-1">
              <li>
                <RouterLink
                  to="/users/inventory"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('products-inventory') }"
                  @click="closeMobile"
                >
                  Inventory
                </RouterLink>
              </li>
              <li>
                <RouterLink
                  to="/users/asn"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('products-asn') }"
                  @click="closeMobile"
                >
                  ASN
                </RouterLink>
              </li>
            </ul>
          </div>
          <RouterLink
            v-else
            to="/users/inventory"
            class="vx-nav-link"
            title="Products"
            @click="closeMobile"
          >
            <svg
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.5"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"
              />
            </svg>
          </RouterLink>
        </li>
      </ul>
    </nav>
  </aside>
</template>

<style scoped>
.rotate-180 {
  transform: rotate(180deg);
}
</style>
