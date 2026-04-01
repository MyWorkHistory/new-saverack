<script setup>
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { useCrmSidebar } from "../../composables/useCrmSidebar";

const props = defineProps({
  user: { type: Object, required: true },
});

const route = useRoute();

const canViewTickets = computed(
  () =>
    Array.isArray(props.user?.permission_keys) &&
    props.user.permission_keys.includes("tickets.view"),
);

const canViewWebmaster = computed(
  () =>
    !!props.user?.is_crm_owner ||
    (Array.isArray(props.user?.permission_keys) &&
      props.user.permission_keys.includes("webmaster.view")),
);
const { isExpanded, isMobileOpen, closeMobile, sidebarWidthClass } =
  useCrmSidebar();

const markSrc = computed(() => BRAND_MARK_SRC());

function itemJustify() {
  return isExpanded.value ? "" : "lg:justify-center lg:px-2";
}

function navActive(mode) {
  const p = route.path;
  if (mode === "dashboard") return p.startsWith("/dashboard");
  if (mode === "users") return p.startsWith("/users");
  if (mode === "tickets")
    return p.startsWith("/tickets") && !p.includes("/board");
  if (mode === "board") return p.includes("/tickets/board");
  if (mode === "webmaster") return p.startsWith("/webmaster");
  return false;
}

function navClass(mode) {
  const active = navActive(mode);
  return [
    "menu-item group w-full",
    active ? "menu-item-active" : "menu-item-inactive",
  ];
}

function iconClass(mode) {
  return navActive(mode) ? "menu-item-icon-active" : "menu-item-icon-inactive";
}
</script>

<template>
  <aside
    :class="[
      'fixed left-0 top-0 z-[100] flex h-screen flex-col border-r border-gray-200 bg-white transition-all duration-300 ease-in-out dark:border-gray-800 dark:bg-gray-900',
      sidebarWidthClass,
      isMobileOpen
        ? 'w-[290px] translate-x-0'
        : '-translate-x-full w-[290px] lg:translate-x-0',
    ]"
  >
    <div
      :class="[
        'flex border-b border-gray-200 py-5 dark:border-gray-800',
        isExpanded ? 'items-center justify-start px-4' : 'justify-center px-2',
      ]"
    >
      <RouterLink
        to="/dashboard"
        :class="[
          'flex min-w-0 items-center rounded-lg outline-none ring-[#206ba4] transition hover:opacity-95 focus-visible:ring-2',
          isExpanded ? 'gap-3' : 'justify-center',
        ]"
        @click="closeMobile"
      >
        <img
          :src="markSrc"
          alt=""
          class="h-14 w-14 shrink-0 object-contain sm:h-16 sm:w-16"
          width="64"
          height="64"
        />
        <span
          v-if="isExpanded"
          class="select-none text-3xl font-bold leading-none tracking-tight text-[#1e3a5f] dark:text-white sm:text-[2rem]"
        >
          SaveRack
        </span>
      </RouterLink>
    </div>

    <nav
      class="no-scrollbar flex-1 overflow-y-auto py-6"
      :class="isExpanded ? 'px-4' : 'px-2'"
    >
      <h2
        v-if="isExpanded"
        class="mb-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400"
      >
        Menu
      </h2>
      <ul class="flex flex-col gap-1">
        <li>
          <RouterLink
            to="/dashboard"
            :class="[navClass('dashboard'), itemJustify()]"
            @click="closeMobile"
          >
            <span :class="iconClass('dashboard')">
              <svg
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"
                />
              </svg>
            </span>
            <span v-if="isExpanded">Dashboard</span>
          </RouterLink>
        </li>
        <li>
          <RouterLink
            to="/users"
            :class="[navClass('users'), itemJustify()]"
            @click="closeMobile"
          >
            <span :class="iconClass('users')">
              <svg
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                />
              </svg>
            </span>
            <span v-if="isExpanded">Users</span>
          </RouterLink>
        </li>
        <li v-if="canViewWebmaster">
          <RouterLink
            to="/webmaster"
            :class="[navClass('webmaster'), itemJustify()]"
            @click="closeMobile"
          >
            <span :class="iconClass('webmaster')">
              <svg
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
            </span>
            <span v-if="isExpanded">Webmaster</span>
          </RouterLink>
        </li>
        <template v-if="user.is_crm_owner">
          <li>
            <RouterLink
              to="/tickets"
              :class="[navClass('tickets'), itemJustify()]"
              @click="closeMobile"
            >
              <span :class="iconClass('tickets')">
                <svg
                  class="h-5 w-5"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                  />
                </svg>
              </span>
              <span v-if="isExpanded">Ticket management</span>
            </RouterLink>
          </li>
          <li>
            <RouterLink
              to="/tickets/board"
              :class="[navClass('board'), itemJustify()]"
              @click="closeMobile"
            >
              <span :class="iconClass('board')">
                <svg
                  class="h-5 w-5"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"
                  />
                </svg>
              </span>
              <span v-if="isExpanded">Board</span>
            </RouterLink>
          </li>
        </template>
      </ul>
    </nav>
  </aside>
</template>
