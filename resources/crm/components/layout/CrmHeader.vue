<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { RouterLink } from "vue-router";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { useCrmSidebar } from "../../composables/useCrmSidebar";
import UserEditModal from "../users/UserEditModal.vue";
import { crmIsAdmin } from "../../utils/crmUser";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  user: { type: Object, required: true },
});

const emit = defineEmits(["logout", "refresh-user"]);

const { isMobileOpen, toggleSidebar } = useCrmSidebar();
const markSrc = computed(() => BRAND_MARK_SRC());

const menuOpen = ref(false);
const menuRoot = ref(null);
const editProfileModalOpen = ref(false);

const canEditOwnProfile = computed(() => {
  const u = props.user;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return (
    Array.isArray(u.permission_keys) && u.permission_keys.includes("users.update")
  );
});

function openEditProfileModal() {
  editProfileModalOpen.value = true;
  closeMenu();
}

const accountRoot = ref(null);
const accountOpen = ref(false);
const accountFilter = ref("");
const selectedAccountId = ref("workspace");

/** Primary workspace row; extend with API-driven accounts when multi-tenant is added. */
const accountOptions = computed(() => [
  {
    id: "workspace",
    title: "Save Rack",
    subtitle: props.user?.email || "Primary workspace",
  },
]);

const selectedAccount = computed(() => {
  const list = accountOptions.value;
  return (
    list.find((a) => a.id === selectedAccountId.value) ?? list[0] ?? null
  );
});

const filteredAccounts = computed(() => {
  const q = accountFilter.value.trim().toLowerCase();
  const list = accountOptions.value;
  if (!q) return list;
  return list.filter(
    (a) =>
      a.title.toLowerCase().includes(q) ||
      String(a.subtitle ?? "")
        .toLowerCase()
        .includes(q),
  );
});

function toggleAccountPanel() {
  menuOpen.value = false;
  accountOpen.value = !accountOpen.value;
}

function selectAccount(opt) {
  selectedAccountId.value = opt.id;
  accountOpen.value = false;
  accountFilter.value = "";
}

const firstName = computed(() => {
  const n = props.user?.name;
  if (!n || typeof n !== "string") return "";
  return n.trim().split(/\s+/)[0] ?? "";
});

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

const avatarPalettes = [
  "bg-sky-200 text-sky-900 ring-sky-300/80",
  "bg-violet-200 text-violet-900 ring-violet-300/80",
  "bg-amber-200 text-amber-900 ring-amber-300/80",
  "bg-emerald-200 text-emerald-900 ring-emerald-300/80",
  "bg-rose-200 text-rose-900 ring-rose-300/80",
];

function avatarClass(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return `${avatarPalettes[h % avatarPalettes.length]} ring-2 ring-inset`;
}

function toggleTheme(e) {
  e?.preventDefault();
  e?.stopPropagation();
  const el = document.documentElement;
  const nextDark = !el.classList.contains("dark");
  if (nextDark) {
    el.classList.add("dark");
    localStorage.setItem("theme", "dark");
  } else {
    el.classList.remove("dark");
    localStorage.setItem("theme", "light");
  }
}

function closeMenu() {
  menuOpen.value = false;
}

function onDocClick(e) {
  if (!menuRoot.value?.contains(e.target)) {
    menuOpen.value = false;
  }
  if (!accountRoot.value?.contains(e.target)) {
    accountOpen.value = false;
  }
}

function signOut() {
  menuOpen.value = false;
  emit("logout");
}

onMounted(() => {
  const el = document.documentElement;
  const saved = localStorage.getItem("theme");
  if (saved === "dark") {
    el.classList.add("dark");
  } else if (saved === "light") {
    el.classList.remove("dark");
  } else if (window.matchMedia("(prefers-color-scheme: dark)").matches) {
    el.classList.add("dark");
  } else {
    el.classList.remove("dark");
  }
  document.addEventListener("click", onDocClick);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <header
    class="sticky top-0 z-[99] border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"
  >
    <div
      class="flex flex-col border-b border-gray-200 dark:border-gray-800 lg:border-b-0 lg:px-6"
    >
      <div
        class="flex w-full items-center gap-2 px-3 py-3 sm:gap-3 lg:px-0 lg:py-4"
      >
        <div class="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
          <button
            type="button"
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/5 lg:h-11 lg:w-11"
            aria-label="Toggle sidebar"
            @click="toggleSidebar"
          >
            <svg
              v-if="isMobileOpen"
              class="h-6 w-6"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
            <svg
              v-else
              class="h-5 w-5"
              viewBox="0 0 16 12"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
            >
              <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25L1.33325 5.25Z"
                fill="currentColor"
              />
            </svg>
          </button>

          <div
            ref="accountRoot"
            class="relative ml-1 min-w-0 flex-1 sm:ml-2 lg:ml-3 lg:max-w-lg"
          >
            <button
              type="button"
              class="flex h-11 w-full min-w-0 items-center gap-2.5 rounded-xl border border-gray-200 bg-white px-3 text-left shadow-sm transition hover:border-gray-300 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0ea5e9]/25 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600 dark:hover:bg-gray-800/80"
              :aria-expanded="accountOpen"
              aria-haspopup="listbox"
              @click.stop="toggleAccountPanel"
            >
              <span
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#1e3a5f]/10 text-[#1e3a5f] dark:bg-sky-500/15 dark:text-sky-300"
              >
                <svg
                  class="h-4 w-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                  />
                </svg>
              </span>
              <span class="min-w-0 flex-1">
                <span
                  class="block text-[10px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500"
                  >Account</span
                >
                <span
                  class="block truncate text-sm font-semibold text-gray-900 dark:text-white"
                >
                  {{ selectedAccount?.title ?? "Save Rack" }}
                </span>
              </span>
              <svg
                class="h-4 w-4 shrink-0 text-gray-400 transition dark:text-gray-500"
                :class="{ 'rotate-180': accountOpen }"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M19 9l-7 7-7-7"
                />
              </svg>
            </button>

            <div
              v-if="accountOpen"
              class="absolute left-0 right-0 z-[120] mt-2 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900"
              role="listbox"
            >
              <div class="border-b border-gray-100 p-2 dark:border-gray-800">
                <input
                  v-model="accountFilter"
                  type="search"
                  autocomplete="off"
                  placeholder="Search accounts…"
                  class="h-10 w-full rounded-lg border border-gray-200 bg-gray-50 px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[#0ea5e9] focus:outline-none focus:ring-2 focus:ring-[#0ea5e9]/20 dark:border-gray-600 dark:bg-gray-800/80 dark:text-white dark:placeholder:text-gray-500"
                  @click.stop
                />
              </div>
              <ul
                class="max-h-56 overflow-y-auto py-1"
                role="presentation"
              >
                <li v-for="opt in filteredAccounts" :key="opt.id">
                  <button
                    type="button"
                    class="flex w-full flex-col items-start gap-0.5 px-3 py-2.5 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-white/5"
                    :class="[
                      opt.id === selectedAccountId
                        ? 'bg-gray-50 dark:bg-white/[0.06]'
                        : '',
                    ]"
                    role="option"
                    :aria-selected="opt.id === selectedAccountId"
                    @click.stop="selectAccount(opt)"
                  >
                    <span class="font-medium text-gray-900 dark:text-white">{{
                      opt.title
                    }}</span>
                    <span
                      class="text-xs text-gray-500 dark:text-gray-400"
                      >{{ opt.subtitle }}</span
                    >
                  </button>
                </li>
              </ul>
            </div>
          </div>

          <RouterLink
            to="/dashboard"
            class="hidden shrink-0 items-center gap-2 sm:flex lg:hidden"
          >
            <img
              :src="markSrc"
              alt=""
              class="h-11 w-11 object-contain sm:h-12 sm:w-12"
              width="48"
              height="48"
            />
            <span
              class="max-w-[5.5rem] truncate text-lg font-bold tracking-tight text-[#1e3a5f] dark:text-white"
            >
              Save Rack
            </span>
          </RouterLink>
        </div>

        <div class="flex shrink-0 items-center gap-1.5 sm:gap-2">
          <button
            type="button"
            class="relative flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
            aria-label="Toggle dark mode"
            @click.stop="toggleTheme"
          >
            <svg
              class="hidden h-5 w-5 dark:block"
              fill="currentColor"
              viewBox="0 0 20 20"
            >
              <path
                d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"
              />
            </svg>
            <svg
              class="h-5 w-5 dark:hidden"
              fill="currentColor"
              viewBox="0 0 20 20"
            >
              <path
                fill-rule="evenodd"
                d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
                clip-rule="evenodd"
              />
            </svg>
          </button>

          <button
            type="button"
            class="relative flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition hover:bg-gray-100 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800"
            aria-label="Notifications"
          >
            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
              <path
                fill-rule="evenodd"
                d="M10.75 2.292a.75.75 0 00-1.5 0v.543a6.5 6.5 0 00-4.41 5.903V14.46H2.58a.75.75 0 000 1.5h14.84a.75.75 0 000-1.5h-2.17V8.738a6.5 6.5 0 00-4.41-5.902V2.292zM14.25 8.738v5.722H5.75V8.738a5 5 0 0110 0zm-5.25 8.208a.75.75 0 01.75.75v.75a.75.75 0 01-1.5 0v-.75a.75.75 0 01.75-.75z"
                clip-rule="evenodd"
              />
            </svg>
            <span
              class="absolute right-2 top-1.5 flex h-2 w-2 rounded-full bg-orange-400 ring-2 ring-white dark:ring-gray-900"
            />
          </button>

          <div ref="menuRoot" class="relative">
            <button
              type="button"
              class="flex items-center gap-2 rounded-lg py-1 pl-1 pr-1.5 text-left transition hover:bg-gray-100 dark:hover:bg-white/5 sm:pr-2"
              :aria-expanded="menuOpen"
              aria-haspopup="true"
              @click.stop="
                accountOpen = false;
                menuOpen = !menuOpen;
              "
            >
              <span class="relative h-11 w-11 shrink-0">
                <img
                  v-if="user.profile?.avatar_url"
                  :src="resolvePublicUrl(user.profile.avatar_url)"
                  alt=""
                  class="h-11 w-11 rounded-full object-cover"
                />
                <span
                  v-else
                  class="flex h-11 w-11 items-center justify-center rounded-full text-sm font-bold"
                  :class="avatarClass(user.email)"
                >
                  {{ initials(user.name) }}
                </span>
              </span>
              <span
                class="hidden max-w-[7rem] truncate text-sm font-medium text-gray-800 dark:text-white/90 lg:inline"
              >
                {{ firstName || user.name }}
              </span>
              <svg
                class="hidden h-4 w-4 shrink-0 text-gray-500 transition sm:block dark:text-gray-400"
                :class="{ 'rotate-180': menuOpen }"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M19 9l-7 7-7-7"
                />
              </svg>
            </button>

            <div
              v-if="menuOpen"
              class="absolute right-0 z-[120] mt-2 w-[260px] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg dark:border-gray-800 dark:bg-gray-900"
              role="menu"
            >
              <div
                class="border-b border-gray-100 px-4 pb-3 pt-4 dark:border-gray-800"
              >
                <p class="font-medium text-gray-800 dark:text-white/90">
                  {{ user.name }}
                </p>
                <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                  {{ user.email }}
                </p>
              </div>
              <ul class="py-1">
                <li v-if="canEditOwnProfile">
                  <button
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                    @click="openEditProfileModal"
                  >
                    <svg
                      class="h-5 w-5 text-gray-500"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      stroke-width="1.5"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                      />
                    </svg>
                    Edit profile
                  </button>
                </li>
                <li v-else>
                  <RouterLink
                    :to="`/staff/${user.id}`"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                    @click="closeMenu"
                  >
                    <svg
                      class="h-5 w-5 text-gray-500"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      stroke-width="1.5"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                      />
                    </svg>
                    View profile
                  </RouterLink>
                </li>
                <li>
                  <RouterLink
                    to="/dashboard"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                    @click="closeMenu"
                  >
                    <svg
                      class="h-5 w-5 text-gray-500"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      stroke-width="1.5"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                      />
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                      />
                    </svg>
                    Account settings
                  </RouterLink>
                </li>
                <li>
                  <a
                    href="mailto:support@saverack.com"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                    @click="closeMenu"
                  >
                    <svg
                      class="h-5 w-5 text-gray-500"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      stroke-width="1.5"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                      />
                    </svg>
                    Support
                  </a>
                </li>
              </ul>
              <div class="border-t border-gray-100 dark:border-gray-800" />
              <button
                type="button"
                class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                role="menuitem"
                @click="signOut"
              >
                <svg
                  class="h-5 w-5 text-gray-500"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="1.5"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"
                  />
                </svg>
                Sign out
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <UserEditModal
    v-if="user?.id"
    v-model:open="editProfileModalOpen"
    :user-id="String(user.id)"
    @saved="$emit('refresh-user')"
  />
</template>
