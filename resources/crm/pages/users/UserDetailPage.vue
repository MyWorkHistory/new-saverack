<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { crmIsAdmin } from "../../utils/crmUser";

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();
const crmUser = inject("crmUser", ref(null));

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

function formatDate(val) {
  if (val == null || val === "") return "—";
  const s = String(val);
  const iso = s.match(/^(\d{4}-\d{2}-\d{2})/);
  return iso ? iso[1] : s;
}

function roleLabels(roles) {
  const r = roles;
  if (!r || !r.length) return "—";
  return r.map((x) => x.label || x.name).join(", ");
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-emerald-50 text-emerald-800 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30";
  }
  if (s === "inactive") {
    return "bg-gray-100 text-gray-700 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-500/40";
  }
  return "bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300";
}

onMounted(async () => {
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
});

const profile = computed(() =>
  user.value?.profile && typeof user.value.profile === "object"
    ? user.value.profile
    : {},
);
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <PageHeader
        title="User profile"
        subtitle="Read-only directory record — use Edit to change account data"
      />
      <div class="flex shrink-0 flex-wrap gap-2">
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
          @click="router.push('/users')"
        >
          Back to users
        </button>
        <button
          v-if="canUpdateUsers && user"
          type="button"
          class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
          @click="router.push(`/users/${id}/edit`)"
        >
          Edit user
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <CrmLoadingSpinner message="Loading profile…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ errorMsg }}
      </p>
      <RouterLink
        to="/users"
        class="inline-block text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400"
      >
                ← Back to users
      </RouterLink>
    </template>

    <div
      v-else-if="user"
      class="space-y-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
    >
      <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 pb-6 dark:border-gray-800">
        <span
          class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset"
          :class="statusBadgeClass(user.status)"
        >
          {{ user.status }}
        </span>
        <span class="text-sm text-gray-500 dark:text-gray-400">
          {{ roleLabels(user.roles) }}
        </span>
      </div>

      <section class="space-y-4">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
          Account
        </h3>
        <dl class="grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Name
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(user.name) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Login email
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(user.email) }}
            </dd>
          </div>
        </dl>
      </section>

      <section class="space-y-4 border-t border-gray-100 pt-8 dark:border-gray-800">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
          Contact &amp; location
        </h3>
        <dl class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Phone
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(profile.phone) }}
            </dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Personal email
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(profile.personal_email) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Birthday
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ formatDate(profile.birthday) }}
            </dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Street address
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(profile.address) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              City
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(profile.city) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              State / province
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(profile.state) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Postal code
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(profile.zip) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Region
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(profile.region) }}
            </dd>
          </div>
        </dl>
      </section>

      <section class="space-y-4 border-t border-gray-100 pt-8 dark:border-gray-800">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
          Employment
        </h3>
        <dl class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Employment type
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ display(profile.employee_type) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Hire date
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ formatDate(profile.hire_date) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
              Termination date
            </dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
              {{ formatDate(profile.terminate_date) }}
            </dd>
          </div>
        </dl>
      </section>

      <section class="space-y-4 border-t border-gray-100 pt-8 dark:border-gray-800">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
          Notes
        </h3>
        <div>
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Bio
          </p>
          <p
            class="mt-1 whitespace-pre-wrap text-sm text-gray-900 dark:text-gray-200"
          >
            {{ display(profile.bio) }}
          </p>
        </div>
      </section>
    </div>
  </div>
</template>
