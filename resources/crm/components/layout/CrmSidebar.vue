<script setup>
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { useCrmSidebar } from "../../composables/useCrmSidebar";
import { crmIsAdmin } from "../../utils/crmUser";

const props = defineProps({
  user: { type: Object, required: true },
});

const route = useRoute();

const canViewUsers = computed(
  () =>
    crmIsAdmin(props.user) ||
    !!props.user?.is_crm_owner ||
    (Array.isArray(props.user?.permission_keys) &&
      props.user.permission_keys.includes("users.view")),
);

const canViewWebmaster = computed(
  () =>
    !!props.user?.is_crm_owner ||
    crmIsAdmin(props.user) ||
    (Array.isArray(props.user?.permission_keys) &&
      props.user.permission_keys.includes("webmaster.view")),
);

const canViewClients = computed(() => {
  if (crmIsAdmin(props.user) || props.user?.is_crm_owner) return true;
  const k = props.user?.permission_keys;
  if (!Array.isArray(k)) return false;
  return k.includes("clients.view") || k.includes("client_users.view");
});

const canViewBilling = computed(() => {
  if (crmIsAdmin(props.user) || props.user?.is_crm_owner) return true;
  const k = props.user?.permission_keys;
  if (!Array.isArray(k)) return false;
  return k.includes("billing.view");
});

const canViewInventory = computed(() => {
  if (crmIsAdmin(props.user) || props.user?.is_crm_owner) return true;
  const k = props.user?.permission_keys;
  if (!Array.isArray(k)) return false;
  return k.includes("inventory.view");
});

const clientsGroupOpen = ref(route.path.startsWith("/clients"));
const billingGroupOpen = ref(route.path.startsWith("/billing"));
watch(
  () => route.path,
  (p) => {
    if (p.startsWith("/clients")) {
      clientsGroupOpen.value = true;
    }
    if (p.startsWith("/billing")) {
      billingGroupOpen.value = true;
    }
  },
);

const { isExpanded, isMobileOpen, closeMobile, sidebarClass, toggleSidebar } =
  useCrmSidebar();

const markSrc = computed(() => BRAND_MARK_SRC());

function navActive(mode) {
  const p = route.path;
  if (mode === "dashboard") return p.startsWith("/dashboard");
  if (mode === "users") return p.startsWith("/staff") || p.startsWith("/users");
  if (mode === "webmaster") return p.startsWith("/webmaster");
  if (mode === "clients") return p.startsWith("/clients");
  if (mode === "clients-accounts") return p.startsWith("/clients/accounts");
  if (mode === "clients-users") return p.startsWith("/clients/users");
  if (mode === "inventory") return p.startsWith("/inventory");
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
        to="/dashboard"
        class="vx-sidebar__brand-link"
        @click="closeMobile"
      >
        <img
          :src="markSrc"
          alt=""
          class="crm-vertical-nav__brand-logo"
          width="40"
          height="40"
        />
        <span class="crm-vertical-nav__brand-text text-truncate">Save Rack</span>
      </RouterLink>
      <RouterLink
        v-else
        to="/dashboard"
        class="vx-sidebar__brand-link justify-content-center w-100"
        @click="closeMobile"
      >
        <img
          :src="markSrc"
          alt=""
          class="crm-vertical-nav__brand-logo"
          width="40"
          height="40"
        />
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

    <!-- Collapsed: show expand control -->
    <div v-if="!isExpanded" class="px-2 pb-2 d-none d-lg-block">
      <button
        type="button"
        class="vx-sidebar__collapse-btn w-100"
        aria-label="Expand navigation"
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
            to="/dashboard"
            class="vx-nav-link"
            :title="!isExpanded ? 'Dashboard' : undefined"
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
                d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"
              />
            </svg>
            <span v-if="isExpanded">Dashboard</span>
          </RouterLink>
        </li>
        <li v-if="canViewUsers">
          <RouterLink
            to="/staff"
            class="vx-nav-link"
            :title="!isExpanded ? 'Staff' : undefined"
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
                d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
              />
            </svg>
            <span v-if="isExpanded">Staff</span>
          </RouterLink>
        </li>
        <li v-if="canViewClients">
          <template v-if="isExpanded">
            <div>
              <button
                type="button"
                class="vx-nav-link"
                :aria-expanded="clientsGroupOpen"
                @click="clientsGroupOpen = !clientsGroupOpen"
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
                    d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h1.5m-1.5 3h1.5m-1.5 3h1.5M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"
                  />
                </svg>
                <span class="text-truncate">Clients</span>
                <svg
                  class="ms-auto flex-shrink-0 transition"
                  :class="clientsGroupOpen ? 'rotate-180' : ''"
                  style="width: 1rem; height: 1rem"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="1.5"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M19 9l-7 7-7-7"
                  />
                </svg>
              </button>
              <ul v-show="clientsGroupOpen" class="list-unstyled mb-0 mt-1">
                <li>
                  <RouterLink
                    to="/clients/accounts"
                    class="vx-nav-link vx-nav-sublink"
                    :class="{ 'vx-nav-link--active': navActive('clients-accounts') }"
                    @click="closeMobile"
                  >
                    Accounts
                  </RouterLink>
                </li>
                <li>
                  <RouterLink
                    to="/clients/users"
                    class="vx-nav-link vx-nav-sublink"
                    :class="{ 'vx-nav-link--active': navActive('clients-users') }"
                    @click="closeMobile"
                  >
                    Users
                  </RouterLink>
                </li>
              </ul>
            </div>
          </template>
          <RouterLink
            v-else
            to="/clients/accounts"
            class="vx-nav-link"
            title="Accounts"
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
                d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h1.5m-1.5 3h1.5m-1.5 3h1.5M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"
              />
            </svg>
          </RouterLink>
        </li>
        <li v-if="canViewBilling">
          <template v-if="isExpanded">
            <div>
              <button
                type="button"
                class="vx-nav-link"
                :aria-expanded="billingGroupOpen"
                @click="billingGroupOpen = !billingGroupOpen"
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
                    d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"
                  />
                </svg>
                <span class="text-truncate">Billing</span>
                <svg
                  class="ms-auto flex-shrink-0 transition"
                  :class="billingGroupOpen ? 'rotate-180' : ''"
                  style="width: 1rem; height: 1rem"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="1.5"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M19 9l-7 7-7-7"
                  />
                </svg>
              </button>
              <ul v-show="billingGroupOpen" class="list-unstyled mb-0 mt-1">
                <li>
                  <RouterLink
                    to="/billing/summary"
                    class="vx-nav-link vx-nav-sublink"
                    :class="{ 'vx-nav-link--active': navActive('billing-summary') }"
                    @click="closeMobile"
                  >
                    Summary
                  </RouterLink>
                </li>
                <li>
                  <RouterLink
                    to="/billing/invoices"
                    class="vx-nav-link vx-nav-sublink"
                    :class="{ 'vx-nav-link--active': navActive('billing-invoices') }"
                    @click="closeMobile"
                  >
                    Invoices
                  </RouterLink>
                </li>
              </ul>
            </div>
          </template>
          <RouterLink
            v-else
            to="/billing/summary"
            class="vx-nav-link"
            title="Billing"
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
                d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"
              />
            </svg>
          </RouterLink>
        </li>
        <li v-if="canViewInventory">
          <RouterLink
            to="/inventory"
            class="vx-nav-link"
            :class="{ 'vx-nav-link--active': navActive('inventory') }"
            :title="!isExpanded ? 'Inventory' : undefined"
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
            <span v-if="isExpanded">Inventory</span>
          </RouterLink>
        </li>
        <li v-if="canViewWebmaster">
          <RouterLink
            to="/webmaster"
            class="vx-nav-link"
            :title="!isExpanded ? 'Webmaster' : undefined"
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
                d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.224 2.3c.307.575.21 1.278-.234 1.733l-.793.792c-.39.39-.601.918-.601 1.467v.224c0 .99.66 1.86 1.617 2.12l1.218.304c.517.129.88.596.88 1.114v2.593c0 .55-.398 1.02-.94 1.11l-1.281.213a1.125 1.125 0 0 1-.87.645l-.135.045a1.125 1.125 0 0 0-.53.315l-.792.793a1.125 1.125 0 0 1-1.733-.234l-1.224-2.3a1.125 1.125 0 0 0-.49-.37l-.286-.107a1.125 1.125 0 0 1-.633-1.326l.302-.774a1.125 1.125 0 0 0-.216-.883l-.792-.792a1.125 1.125 0 0 0-.883-.216l-.774.302a1.125 1.125 0 0 1-1.326-.633l-.107-.286a1.125 1.125 0 0 0-.37-.49l-2.3-1.224a1.125 1.125 0 0 1-.234-1.733l.793-.792c.196-.324.257-.72.124-1.075l-.456-1.217a1.125 1.125 0 0 1 .49-1.37l2.3-1.224c.162-.086.312-.2.444-.324L9.594 3.94ZM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
              />
            </svg>
            <span v-if="isExpanded">Webmaster</span>
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
