<script setup>
import { ref, watch } from "vue";
import { RouterLink } from "vue-router";
import UserPermissionsPanel from "../../components/users/UserPermissionsPanel.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import api from "../../services/api";

const props = defineProps({
  id: { type: String, required: true },
});

const subjectName = ref("");

watch(
  () => props.id,
  async (uid) => {
    subjectName.value = "";
    if (!uid) return;
    try {
      const { data } = await api.get(`/users/${uid}`);
      if (data?.name) subjectName.value = String(data.name);
    } catch {
      subjectName.value = "";
    }
  },
  { immediate: true },
);

watch(
  () => subjectName.value,
  (name) => {
    if (name && typeof name === "string") {
      setCrmPageMeta({
        title: `Save Rack | User Permissions: ${name}`,
        description: `Permissions For ${name}.`,
      });
    }
  },
);
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
        User Permissions
      </span>
    </nav>

    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
        <template v-if="subjectName">
          User Permissions: {{ subjectName }}
        </template>
        <template v-else>
          User Permissions
        </template>
      </h1>
    </div>

    <UserPermissionsPanel :user-id="id" />
  </div>
</template>
