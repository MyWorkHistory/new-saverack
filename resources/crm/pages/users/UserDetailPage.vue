<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmOutlinePillLink from "../../components/common/CrmOutlinePillLink.vue";
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
      errorMsg.value = "You don't have access to this profile.";
    } else if (st === 404) {
      errorMsg.value = "User not found.";
    } else {
      errorMsg.value = "Could not load user.";
    }
  } finally {
    loading.value = false;
  }
}

onMounted(loadProfile);

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

const profileLocationLine = computed(() => {
  const p = profile.value;
  if (!p || typeof p !== "object") return "";
  const city = p.city ? String(p.city).trim() : "";
  const state = p.state ? String(p.state).trim() : "";
  const zip = p.zip ? String(p.zip).trim() : "";
  const parts = [];
  if (city) parts.push(city);
  if (state && state !== city) parts.push(state);
  if (zip) parts.push(zip);
  return parts.filter(Boolean).join(", ");
});

function openHeroAvatarPicker() {
  heroAvatarInput.value?.click();
}

watch(
  () => user.value?.name,
  (name) => {
    if (name && typeof name === "string") {
      setCrmPageMeta({
        title: `SaveRack | Staff: ${name}`,
        description: `Profile for ${name}.`,
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
    await api.post(`/users/${props.id}/avatar`, fd, {
      transformRequest: [
        (body, headers) => {
          if (headers && typeof headers.delete === "function") {
            headers.delete("Content-Type");
          }
          return body;
        },
      ],
    });
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
        class="font-medium text-gray-500 transition hover:text-[#206ba4] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Home
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <RouterLink
        to="/staff"
        class="font-medium text-gray-500 transition hover:text-[#206ba4] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Staff
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <span class="font-medium text-gray-800 dark:text-gray-200">
        Profile
      </span>
    </nav>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
        User Profile
      </h1>
      <CrmOutlinePillLink to="/dashboard" label="Back to dashboard" />
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <CrmLoadingSpinner message="Loading profile…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ errorMsg }}
      </p>
      <RouterLink
        to="/staff"
        class="mt-2 inline-block text-sm font-medium text-[#206ba4] hover:underline dark:text-blue-400"
      >
        Back to directory
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
              class="shrink-0 rounded-full focus:outline-none focus:ring-2 focus:ring-[#206ba4]/40 dark:focus:ring-blue-400/40"
              :title="'Change photo'"
              @click="openHeroAvatarPicker"
            >
              <img
                v-if="profile.avatar_url"
                :src="profile.avatar_url"
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
                :src="profile.avatar_url"
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
            <div class="min-w-0">
              <h2 class="truncate text-xl font-semibold text-gray-900 dark:text-white">
                {{ user.name }}
              </h2>
              <p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">
                {{ display(profile.job_position) }}
              </p>
              <p class="mt-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                {{ roleLabels(user.roles) }}
              </p>
              <p
                class="mt-1 text-sm text-gray-500 dark:text-gray-400"
              >
                <template v-if="profileLocationLine">{{ profileLocationLine }}</template>
                <template v-if="profileLocationLine && user.email"> · </template>
                <template v-if="user.email">{{ user.email }}</template>
                <template v-if="!profileLocationLine && !user.email">—</template>
              </p>
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
          <div class="space-y-8">
            <div class="space-y-4 border-b border-gray-100 pb-6 dark:border-gray-800">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Full name
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ display(user.name) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Login email
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ display(user.email) }}
                  </dd>
                </div>
              </dl>
            </div>
            <div class="space-y-4 border-b border-gray-100 pb-6 dark:border-gray-800">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Phone
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ display(profile.phone) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Personal email
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ display(profile.personal_email) }}
                  </dd>
                </div>
              </dl>
            </div>
            <div class="space-y-4">
              <dl class="space-y-4">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Birthday
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ formatBirthdayUs(profile.birthday) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Bio
                  </dt>
                  <dd class="mt-1 whitespace-pre-wrap text-sm font-medium text-gray-900 dark:text-gray-200">
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
          <div class="space-y-8">
            <div class="space-y-4 border-b border-gray-100 pb-6 dark:border-gray-800">
              <dl>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Street
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ display(profile.address) }}
                  </dd>
                </div>
              </dl>
            </div>
            <div class="space-y-4 border-b border-gray-100 pb-6 dark:border-gray-800">
              <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    City
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ display(profile.city) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    State
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ display(profile.state) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Zip
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                    {{ display(profile.zip) }}
                  </dd>
                </div>
              </dl>
            </div>
            <div class="space-y-4">
              <dl>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Country
                  </dt>
                  <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
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
        <div class="space-y-8">
          <div class="space-y-4 border-b border-gray-100 pb-6 dark:border-gray-800">
            <dl class="grid gap-4 sm:grid-cols-2">
              <div class="sm:col-span-2">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Employment type
                </dt>
                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                  {{ display(profile.employee_type) }}
                </dd>
              </div>
              <div class="sm:col-span-2">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Position
                </dt>
                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                  {{ display(profile.job_position) }}
                </dd>
              </div>
            </dl>
          </div>
          <div class="space-y-4">
            <dl class="grid gap-4 sm:grid-cols-2">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Hire date
                </dt>
                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                  {{ formatDateUs(profile.hire_date) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Termination date
                </dt>
                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                  {{ formatDateUs(profile.terminate_date) }}
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </section>
    </div>

    <UserEditModal
      v-model:open="editOpen"
      :user-id="String(props.id)"
      @saved="loadProfile"
    />
  </div>
</template>
