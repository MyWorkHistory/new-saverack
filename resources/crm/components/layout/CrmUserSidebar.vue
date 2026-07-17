<script setup>
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { useCrmSidebar } from "../../composables/useCrmSidebar";

const props = defineProps({
  user: { type: Object, required: true },
});

const route = useRoute();
const ordersGroupOpen = ref(route.path.startsWith("/users/orders"));
const returnsGroupOpen = ref(route.path.startsWith("/users/returns"));
const inventoryGroupOpen = ref(
  route.path.startsWith("/users/inventory") ||
    route.path.startsWith("/users/asn"),
);
const myAccountGroupOpen = ref(route.path.startsWith("/users/my-account"));

const accountNavLabel = computed(() => {
  const name = String(props.user?.client_account_company_name || "").trim();
  if (name) return name;
  const id = Number(props.user?.client_account_id || 0);
  return id > 0 ? `Account #${id}` : "";
});

watch(
  () => route.path,
  (p) => {
    if (p.startsWith("/users/orders")) ordersGroupOpen.value = true;
    if (p.startsWith("/users/returns")) returnsGroupOpen.value = true;
    if (p.startsWith("/users/inventory") || p.startsWith("/users/asn")) {
      inventoryGroupOpen.value = true;
    }
    if (p.startsWith("/users/my-account")) myAccountGroupOpen.value = true;
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
  if (mode === "orders-create") return p === "/users/orders/create";
  if (mode === "inventory" || mode === "products") {
    return (
      p.startsWith("/users/inventory") ||
      p.startsWith("/users/asn")
    );
  }
  if (mode === "inventory-products" || mode === "products-inventory") {
    if (p === "/users/inventory/out-of-stock") return false;
    return p === "/users/inventory" || (p.startsWith("/users/inventory/") && !p.startsWith("/users/inventory/out-of-stock"));
  }
  if (mode === "inventory-out-of-stock" || mode === "products-out-of-stock") {
    return p === "/users/inventory/out-of-stock";
  }
  if (mode === "inventory-asn" || mode === "products-asn") return p.startsWith("/users/asn");
  if (mode === "returns") return p.startsWith("/users/returns");
  if (mode === "returns-orders") return p === "/users/returns/orders";
  if (mode === "returns-items") return p === "/users/returns/items";
  if (mode === "returns-create") return p.startsWith("/users/returns/create");
  if (mode === "billing") return p.startsWith("/users/billing");
  if (mode === "my-account") return p.startsWith("/users/my-account");
  if (mode === "my-account-pricing") return p === "/users/my-account/pricing";
  if (mode === "my-account-agreement") return p === "/users/my-account/fulfillment-agreement";
  if (mode === "my-account-shipping") return p === "/users/my-account/shipping-instructions";
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
            :title="!isExpanded ? 'Home' : undefined"
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
            <span v-if="isExpanded">Home</span>
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
              <li>
                <RouterLink
                  to="/users/orders/create"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('orders-create') }"
                  @click="closeMobile"
                >
                  Create Order
                </RouterLink>
              </li>
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
              :class="{ 'vx-nav-link--active': navActive('returns') }"
              :aria-expanded="returnsGroupOpen"
              @click="returnsGroupOpen = !returnsGroupOpen"
            >
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"
                />
              </svg>
              <span class="text-truncate">Returns</span>
              <svg
                class="ms-auto flex-shrink-0 transition"
                :class="returnsGroupOpen ? 'rotate-180' : ''"
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
            <ul v-show="returnsGroupOpen" class="list-unstyled mb-0 mt-1">
              <li>
                <RouterLink
                  to="/users/returns/orders"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('returns-orders') }"
                  @click="closeMobile"
                >
                  Return Orders
                </RouterLink>
              </li>
              <li>
                <RouterLink
                  to="/users/returns/items"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('returns-items') }"
                  @click="closeMobile"
                >
                  Return Items
                </RouterLink>
              </li>
              <li>
                <RouterLink
                  to="/users/returns/create"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('returns-create') }"
                  @click="closeMobile"
                >
                  Create Return
                </RouterLink>
              </li>
            </ul>
          </div>
          <RouterLink
            v-else
            to="/users/returns/orders"
            class="vx-nav-link"
            title="Returns"
            @click="closeMobile"
          >
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"
              />
            </svg>
          </RouterLink>
        </li>
        <li>
          <div v-if="isExpanded">
            <button
              type="button"
              class="vx-nav-link"
              :class="{ 'vx-nav-link--active': navActive('inventory') }"
              :aria-expanded="inventoryGroupOpen"
              @click="inventoryGroupOpen = !inventoryGroupOpen"
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
              <span class="text-truncate fw-bold">Inventory</span>
              <svg
                class="ms-auto flex-shrink-0 transition"
                :class="inventoryGroupOpen ? 'rotate-180' : ''"
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
            <ul v-show="inventoryGroupOpen" class="list-unstyled mb-0 mt-1">
              <li>
                <RouterLink
                  to="/users/inventory"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('inventory-products') }"
                  @click="closeMobile"
                >
                  Products
                </RouterLink>
              </li>
              <li>
                <RouterLink
                  to="/users/inventory/out-of-stock"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('inventory-out-of-stock') }"
                  @click="closeMobile"
                >
                  Out of Stock
                </RouterLink>
              </li>
              <li>
                <RouterLink
                  to="/users/asn"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('inventory-asn') }"
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
            title="Inventory"
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
        <li>
          <RouterLink
            to="/users/billing/invoices"
            class="vx-nav-link"
            :class="{ 'vx-nav-link--active': navActive('billing') }"
            :title="!isExpanded ? 'Billing' : undefined"
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
                d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h10.5M2.25 6.75h19.5a1.5 1.5 0 0 0 1.5-1.5v-1.5a1.5 1.5 0 0 0-1.5-1.5H2.25a1.5 1.5 0 0 0-1.5 1.5v1.5a1.5 1.5 0 0 0 1.5 1.5Z"
              />
            </svg>
            <span v-if="isExpanded">Billing</span>
          </RouterLink>
        </li>
        <li>
          <div v-if="isExpanded">
            <button
              type="button"
              class="vx-nav-link"
              :class="{ 'vx-nav-link--active': navActive('my-account') }"
              :aria-expanded="myAccountGroupOpen"
              @click="myAccountGroupOpen = !myAccountGroupOpen"
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
                  d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
                />
              </svg>
              <span class="text-truncate">My Account</span>
              <svg
                class="ms-auto flex-shrink-0 transition"
                :class="myAccountGroupOpen ? 'rotate-180' : ''"
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
            <p
              v-if="accountNavLabel"
              class="vx-nav-account-label small text-secondary text-truncate mb-0 px-3 mt-1"
            >
              {{ accountNavLabel }}
            </p>
            <ul v-show="myAccountGroupOpen" class="list-unstyled mb-0 mt-1">
              <li>
                <RouterLink
                  to="/users/my-account/pricing"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('my-account-pricing') }"
                  @click="closeMobile"
                >
                  Pricing
                </RouterLink>
              </li>
              <li>
                <RouterLink
                  to="/users/my-account/fulfillment-agreement"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('my-account-agreement') }"
                  @click="closeMobile"
                >
                  Fulfillment Agreement
                </RouterLink>
              </li>
              <li>
                <RouterLink
                  to="/users/my-account/shipping-instructions"
                  class="vx-nav-link vx-nav-sublink"
                  :class="{ 'vx-nav-link--active': navActive('my-account-shipping') }"
                  @click="closeMobile"
                >
                  Shipping Instructions
                </RouterLink>
              </li>
            </ul>
          </div>
          <RouterLink
            v-else
            to="/users/my-account/pricing"
            class="vx-nav-link"
            :class="{ 'vx-nav-link--active': navActive('my-account') }"
            title="My Account"
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
                d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
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

.vx-nav-account-label {
  max-width: 100%;
  padding-left: calc(0.75rem + 1.5rem);
}
</style>
