<script setup>
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  ref,
  watch,
} from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmOutlineEditButton from "../../components/common/CrmOutlineEditButton.vue";
import UserEditModal from "../../components/users/UserEditModal.vue";
import { crmIsAdmin } from "../../utils/crmUser";
import {
  formatBirthdayUs,
  formatDateUs,
} from "../../utils/formatUserDates";
import { useToast } from "../../composables/useToast";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  id: { type: String, required: true },
});

const route = useRoute();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));
const editOpen = ref(false);
const toast = useToast();
const heroAvatarInput = ref(null);

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdateUsers = computed(() => userHasPerm("users.update"));
const showGearMenu = computed(() => userHasPerm("users.view"));
const canManagePermissions = computed(() => crmIsAdmin(crmUser.value));

const loading = ref(true);
const errorMsg = ref("");
const user = ref(null);

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

function roleLabels(roles) {
  const r = roles;
  if (!r || !r.length) return "—";
  return r.map((x) => x.label || x.name).join(", ");
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200";
  }
  if (s === "inactive") {
    return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300";
  }
  return "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300";
}

const avatarPalettes = [
  "bg-sky-100 text-sky-800 ring-sky-200 dark:bg-sky-500/20 dark:text-sky-200 dark:ring-sky-500/30",
  "bg-violet-100 text-violet-800 ring-violet-200 dark:bg-violet-500/20 dark:text-violet-200 dark:ring-violet-500/30",
  "bg-amber-100 text-amber-900 ring-amber-200 dark:bg-amber-500/20 dark:text-amber-200 dark:ring-amber-500/30",
  "bg-emerald-100 text-emerald-900 ring-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-200 dark:ring-emerald-500/30",
  "bg-rose-100 text-rose-900 ring-rose-200 dark:bg-rose-500/20 dark:text-rose-200 dark:ring-rose-500/30",
];

function avatarClassForEmail(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

async function loadProfile() {
  loading.value = true;
  errorMsg.value = "";
  user.value = null;
  try {
    const { data } = await api.get(`/users/${props.id}`);
    user.value = data;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You Don't Have Access To This Profile.";
    } else if (st === 404) {
      errorMsg.value = "User Not Found.";
    } else {
      errorMsg.value = "Could Not Load User.";
    }
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  loadProfile();
  document.addEventListener("click", onGearDocClick);
  window.addEventListener("resize", onGearWindowResize);
});
onUnmounted(() => {
  document.removeEventListener("click", onGearDocClick);
  window.removeEventListener("resize", onGearWindowResize);
});

function wantsEditQuery(q) {
  if (q === "1" || q === "true") return true;
  if (Array.isArray(q)) {
    return q[0] === "1" || q[0] === "true";
  }
  return false;
}

watch(
  [() => route.query.edit, canUpdateUsers, () => props.id],
  () => {
    if (!wantsEditQuery(route.query.edit)) return;
    if (!canUpdateUsers.value) {
      router.replace({ path: `/staff/${props.id}`, query: {} });
      return;
    }
    editOpen.value = true;
    router.replace({ path: `/staff/${props.id}`, query: {} });
  },
  { immediate: true },
);

const profile = computed(() =>
  user.value?.profile && typeof user.value.profile === "object"
    ? user.value.profile
    : {},
);

/** Role and login email only (no city/state/zip in hero — see Address card). */
const profileAboutSegments = computed(() => {
  const u = user.value;
  if (!u) return [];
  const segs = [];
  const rl = roleLabels(u.roles);
  if (rl && rl !== "—") {
    segs.push({ key: "role", text: rl, emphasis: true });
  }
  if (u.email) {
    segs.push({ key: "email", text: u.email, emphasis: false });
  }
  return segs;
});

const gearMenuOpen = ref(false);
const gearMenuRect = ref({ top: 0, left: 0 });

function placeGearMenu(buttonEl) {
  const MENU_W = 208;
  const MENU_H = 168;
  const r = buttonEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  gearMenuRect.value = { top, left };
}

function closeGearMenu() {
  gearMenuOpen.value = false;
}

function toggleGearMenu(e) {
  e.stopPropagation();
  if (gearMenuOpen.value) {
    closeGearMenu();
    return;
  }
  gearMenuOpen.value = true;
  const btn = e.currentTarget;
  if (btn instanceof HTMLElement) {
    placeGearMenu(btn);
  }
}

function openPermissionsInNewTab() {
  if (!crmIsAdmin(crmUser.value)) return;
  const href = router.resolve({
    name: "staff-permissions",
    params: { id: props.id },
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
  closeGearMenu();
}

function openHistoryInNewTab() {
  const href = router.resolve({
    name: "staff-history",
    params: { id: props.id },
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
  closeGearMenu();
}

function onGearDocClick(e) {
  if (!e.target.closest("[data-page-gear]")) {
    gearMenuOpen.value = false;
  }
}

function onGearWindowResize() {
  if (gearMenuOpen.value) {
    closeGearMenu();
  }
}

function openHeroAvatarPicker() {
  heroAvatarInput.value?.click();
}

watch(
  () => user.value?.name,
  (name) => {
    if (name && typeof name === "string") {
      setCrmPageMeta({
        title: `Save Rack | Staff: ${name}`,
        description: `Profile For ${name}.`,
      });
    }
  },
);

async function onHeroAvatarChange(e) {
  const input = e.target;
  const file = input.files?.[0];
  input.value = "";
  if (!file || !canUpdateUsers.value) return;
  const fd = new FormData();
  fd.append("avatar", file);
  try {
    await api.post(`/users/${props.id}/avatar`, fd);
    await loadProfile();
    toast.success("Photo updated.");
  } catch (err) {
    toast.errorFrom(err, "Could not upload photo.");
  }
}
</script>

<template>
  <div class="w-full">
    <!-- Breadcrumb (TailAdmin-style) -->
    <nav class="mb-4 flex flex-wrap items-center gap-1.5 text-sm">
      <RouterLink
        to="/dashboard"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Home
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <RouterLink
        to="/staff"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Staff
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <span class="font-medium text-gray-800 dark:text-gray-200">
        Profile
      </span>
    </nav>

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
        User Profile
      </h1>
      <div
        v-if="showGearMenu && !loading && !errorMsg"
        class="relative shrink-0"
        data-page-gear
      >
        <button
          type="button"
          class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:bg-white/10 dark:hover:text-white"
          :aria-expanded="gearMenuOpen"
          aria-haspopup="true"
          aria-label="Page Actions"
          @click="toggleGearMenu"
        >
          <svg
            class="h-5 w-5"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
            />
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
            />
          </svg>
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <CrmLoadingSpinner message="Loading Profile…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ errorMsg }}
      </p>
      <RouterLink
        to="/staff"
        class="mt-2 inline-block text-sm font-medium text-[#2563eb] hover:underline dark:text-blue-400"
      >
        Back To Directory
      </RouterLink>
    </template>

    <div v-else-if="user" class="space-y-6">
      <!-- Profile hero -->
      <div
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <div
          class="flex flex-col gap-6 border-b border-gray-100 p-6 dark:border-gray-800 sm:flex-row sm:items-start sm:justify-between"
        >
          <div class="flex min-w-0 flex-1 items-start gap-5">
            <input
              ref="heroAvatarInput"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              class="hidden"
              @change="onHeroAvatarChange"
            />
            <button
              v-if="canUpdateUsers"
              type="button"
              class="shrink-0 rounded-full focus:outline-none focus:ring-2 focus:ring-[#2563eb]/40 dark:focus:ring-blue-400/40"
              :title="'Change Photo'"
              @click="openHeroAvatarPicker"
            >
              <img
                v-if="profile.avatar_url"
                :src="resolvePublicUrl(profile.avatar_url)"
                alt=""
                class="h-20 w-20 rounded-full object-cover ring-2 ring-white dark:ring-gray-900"
              />
              <span
                v-else
                class="flex h-20 w-20 items-center justify-center rounded-full text-xl font-bold ring-2 ring-white dark:ring-gray-900"
                :class="avatarClassForEmail(user.email)"
              >
                {{ initials(user.name) }}
              </span>
            </button>
            <div
              v-else
              class="shrink-0"
            >
              <img
                v-if="profile.avatar_url"
                :src="resolvePublicUrl(profile.avatar_url)"
                alt=""
                class="h-20 w-20 rounded-full object-cover ring-2 ring-white dark:ring-gray-900"
              />
              <span
                v-else
                class="flex h-20 w-20 items-center justify-center rounded-full text-xl font-bold ring-2 ring-white dark:ring-gray-900"
                :class="avatarClassForEmail(user.email)"
              >
                {{ initials(user.name) }}
              </span>
            </div>
            <div class="min-w-0 flex-1">
              <h2 class="truncate text-xl font-semibold text-gray-900 dark:text-white">
                {{ user.name }}
              </h2>
              <p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">
                {{ display(profile.job_position) }}
              </p>
              <!-- About: role | email (horizontal) -->
              <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm sm:gap-x-3">
                <template v-if="profileAboutSegments.length">
                  <template
                    v-for="(seg, idx) in profileAboutSegments"
                    :key="seg.key"
                  >
                    <span
                      v-if="idx > 0"
                      class="text-gray-300 dark:text-gray-600"
                      aria-hidden="true"
                      >|</span
                    >
                    <span
                      :class="
                        seg.emphasis
                          ? 'font-medium text-gray-700 dark:text-gray-300'
                          : 'text-gray-500 dark:text-gray-400'
                      "
                      >{{ seg.text }}</span
                    >
                  </template>
                </template>
                <span v-else class="text-gray-500 dark:text-gray-400">—</span>
              </div>
              <div class="mt-3 flex flex-wrap items-center gap-2">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                  :class="statusBadgeClass(user.status)"
                >
                  {{ user.status }}
                </span>
              </div>
            </div>
          </div>
          <div
            v-if="canUpdateUsers"
            class="flex w-full shrink-0 flex-col self-end sm:w-auto"
          >
            <CrmOutlineEditButton @click="editOpen = true" />
          </div>
        </div>
      </div>

      <div class="space-y-6">
        <section
          class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
        >
          <h3 class="mb-5 border-b border-gray-100 pb-3 text-lg font-semibold text-gray-900 dark:border-gray-800 dark:text-white">
            Personal Information
          </h3>
          <div
            class="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-0 lg:divide-x lg:divide-gray-100 dark:lg:divide-gray-800"
          >
            <div class="space-y-4 lg:pr-6">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Full Name
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(user.name) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Login Email
                  </dt>
                  <dd class="mt-1 break-all text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(user.email) }}
                  </dd>
                </div>
              </dl>
            </div>
            <div class="space-y-4 lg:px-6">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Phone
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(profile.phone) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Email
                  </dt>
                  <dd class="mt-1 break-all text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(profile.personal_email) }}
                  </dd>
                </div>
              </dl>
            </div>
            <div class="space-y-4 lg:pl-6">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Birthday
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ formatBirthdayUs(profile.birthday) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Bio
                  </dt>
                  <dd class="mt-1 whitespace-pre-wrap text-sm font-semibold text-gray-900 dark:text-gray-200">
                    {{ display(profile.bio) }}
                  </dd>
                </div>
              </dl>
            </div>
          </div>
        </section>

        <section
          class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
        >
          <h3 class="mb-5 border-b border-gray-100 pb-3 text-lg font-semibold text-gray-900 dark:border-gray-800 dark:text-white">
            Address
          </h3>
          <div
            class="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-0 lg:divide-x lg:divide-gray-100 dark:lg:divide-gray-800"
          >
            <div class="space-y-4 lg:pr-6">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Street
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(profile.address) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    City
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(profile.city) }}
                  </dd>
                </div>
              </dl>
            </div>
            <div class="space-y-4 lg:px-6">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    State
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(profile.state) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    ZIP
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(profile.zip) }}
                  </dd>
                </div>
              </dl>
            </div>
            <div class="space-y-4 lg:pl-6">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Country
                  </dt>
                  <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ display(profile.region) }}
                  </dd>
                </div>
              </dl>
            </div>
          </div>
        </section>
      </div>

      <!-- Employment full width -->
      <section
        class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <h3 class="mb-5 border-b border-gray-100 pb-3 text-lg font-semibold text-gray-900 dark:border-gray-800 dark:text-white">
          Employment
        </h3>
        <div
          class="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-0 lg:divide-x lg:divide-gray-100 dark:lg:divide-gray-800"
        >
          <div class="space-y-4 lg:pr-6">
            <dl class="space-y-4">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Employment Type
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(profile.employee_type) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Position
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(profile.job_position) }}
                </dd>
              </div>
            </dl>
          </div>
          <div class="space-y-4 lg:px-6">
            <dl class="space-y-4">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Hire Date
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ formatDateUs(profile.hire_date) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Termination Date
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ formatDateUs(profile.terminate_date) }}
                </dd>
              </div>
            </dl>
          </div>
          <div
            class="hidden min-h-0 lg:block lg:pl-6"
            aria-hidden="true"
          />
        </div>
      </section>
    </div>

    <UserEditModal
      v-model:open="editOpen"
      :user-id="String(props.id)"
      @saved="loadProfile"
    />

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="transform opacity-0 scale-95"
        enter-to-class="transform opacity-100 scale-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="transform opacity-100 scale-100"
        leave-to-class="transform opacity-0 scale-95"
      >
        <div
          v-if="gearMenuOpen"
          data-page-gear
          class="fixed z-[300] w-52 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg ring-1 ring-black/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10"
          role="menu"
          :style="{
            top: `${gearMenuRect.top}px`,
            left: `${gearMenuRect.left}px`,
          }"
          @click.stop
        >
          <button
            v-if="canManagePermissions"
            type="button"
            class="flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-gray-800 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
            role="menuitem"
            @click="openPermissionsInNewTab"
          >
            Permissions
          </button>
          <button
            type="button"
            class="flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-gray-800 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
            :class="
              canManagePermissions
                ? 'border-t border-gray-100 dark:border-gray-800'
                : ''
            "
            role="menuitem"
            @click="openHistoryInNewTab"
          >
            History
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
