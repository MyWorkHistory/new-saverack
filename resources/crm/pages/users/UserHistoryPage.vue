<script setup>
import { ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatDateTimeUs } from "../../utils/formatUserDates";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();

const loading = ref(true);
const errorMsg = ref("");
const subjectName = ref("");
const items = ref([]);

async function load() {
  loading.value = true;
  errorMsg.value = "";
  items.value = [];
  try {
    const [userRes, histRes] = await Promise.all([
      api.get(`/users/${props.id}`),
      api.get(`/users/${props.id}/history`),
    ]);
    subjectName.value =
      userRes.data?.name && typeof userRes.data.name === "string"
        ? userRes.data.name
        : "";
    const list = histRes.data?.items;
    items.value = Array.isArray(list) ? list : [];
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You Don't Have Access To This History.";
    } else if (st === 404) {
      errorMsg.value = "User Not Found.";
    } else {
      errorMsg.value = "Could Not Load History.";
    }
  } finally {
    loading.value = false;
  }
}

watch(
  () => subjectName.value,
  (name) => {
    if (name) {
      setCrmPageMeta({
        title: `Save Rack | History: ${name}`,
        description: `Activity History For ${name}.`,
      });
    }
  },
);

function avatarClass(seed) {
  const palettes = [
    "bg-sky-100 text-sky-800 dark:bg-sky-500/25 dark:text-sky-100",
    "bg-indigo-100 text-indigo-800 dark:bg-indigo-500/25 dark:text-indigo-100",
    "bg-violet-100 text-violet-800 dark:bg-violet-500/25 dark:text-violet-100",
  ];
  let h = 0;
  const s = String(seed || "");
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return palettes[h % palettes.length];
}

load();
</script>

<template>
  <div class="w-full">
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
      <RouterLink
        :to="`/staff/${id}`"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Profile
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <span class="font-medium text-gray-800 dark:text-gray-200">
        History
      </span>
    </nav>

    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
        <template v-if="subjectName">
          History: {{ subjectName }}
        </template>
        <template v-else>
          History
        </template>
      </h1>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <CrmLoadingSpinner message="Loading History…" />
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

    <div
      v-else
      class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900"
    >
      <div
        class="border-b border-gray-200 px-4 py-5 dark:border-gray-800 sm:px-6"
      >
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">
          History
        </h2>
        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
          Changes To This Staff Profile
        </p>
      </div>

      <div class="divide-y divide-gray-200 dark:divide-gray-700">
        <div
          v-for="row in items"
          :key="row.id"
          class="flex gap-3 px-4 py-2.5 sm:gap-4 sm:px-6 sm:py-3"
        >
          <img
            v-if="row.actor_avatar_url"
            :src="resolvePublicUrl(row.actor_avatar_url) || row.actor_avatar_url"
            alt=""
            class="h-11 w-11 shrink-0 rounded-full object-cover"
            width="44"
            height="44"
          />
          <div
            v-else
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
            :class="avatarClass(row.actor_name || row.actor_initials)"
          >
            {{ row.actor_initials || "?" }}
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              <span class="font-semibold text-gray-900 dark:text-white">
                {{ row.actor_name || "System" }}
              </span>
              <span class="text-sm text-gray-500 dark:text-gray-400">
                {{ formatDateTimeUs(row.created_at) }}
              </span>
            </div>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
              {{ row.body || row.line }}
            </p>
          </div>
        </div>
        <div
          v-if="items.length === 0"
          class="px-4 py-14 text-center text-sm text-gray-500 dark:text-gray-400 sm:px-6"
        >
          No History Yet.
        </div>
      </div>

      <div
        class="border-t border-gray-100 px-4 py-4 dark:border-gray-800 sm:px-6"
      >
        <button
          type="button"
          class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700/70"
          @click="router.push(`/staff/${id}`)"
        >
          Back To Profile
        </button>
      </div>
    </div>
  </div>
</template>
