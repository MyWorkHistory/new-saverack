<script setup>
import { computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import PageHeader from "../../components/common/PageHeader.vue";
import UserFormFields from "../../components/users/UserFormFields.vue";
import { useUserForm } from "../../composables/useUserForm";

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === "users-edit");
const userId = computed(() => (isEdit.value ? String(route.params.id) : null));

const title = computed(() => (isEdit.value ? "Edit user" : "Add user"));

const {
  loading,
  saving,
  errorMsg,
  roles,
  form,
  loadRoles,
  loadUser,
  resetForCreate,
  submit,
  toggleRole,
  clearFieldError,
  firstError,
} = useUserForm();

onMounted(async () => {
  loading.value = true;
  errorMsg.value = "";
  try {
    await loadRoles();
    if (isEdit.value && userId.value) {
      await loadUser(userId.value);
    } else {
      resetForCreate();
    }
  } catch {
    errorMsg.value = "Could not load user.";
  } finally {
    loading.value = false;
  }
});

async function onSubmit() {
  const ok = await submit({
    isEdit: isEdit.value,
    userId: userId.value,
  });
  if (ok) {
    await router.push("/users");
  }
}
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <PageHeader
      :title="title"
      subtitle="Account, profile, and roles — all saved to the user record"
    />

    <p v-if="errorMsg" class="text-sm text-red-600 dark:text-red-400">
      {{ errorMsg }}
    </p>

    <form
      v-if="!loading"
      class="space-y-5 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
      @submit.prevent="onSubmit"
    >
      <UserFormFields
        :form="form"
        :roles="roles"
        :is-edit="isEdit"
        :saving="saving"
        :first-error="firstError"
        :clear-field-error="clearFieldError"
        :toggle-role="toggleRole"
      />

      <div class="flex flex-wrap gap-3 pt-2">
        <button
          type="submit"
          :disabled="saving"
          class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-50"
        >
          {{ saving ? "Saving…" : "Save" }}
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
          :disabled="saving"
          @click="router.push('/users')"
        >
          Cancel
        </button>
      </div>
    </form>

    <p v-else class="text-sm text-gray-500">Loading…</p>
  </div>
</template>
