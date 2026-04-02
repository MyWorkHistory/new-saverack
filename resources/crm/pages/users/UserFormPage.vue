<script setup>
import { onMounted } from "vue";
import { useRouter } from "vue-router";
import PageHeader from "../../components/common/PageHeader.vue";
import CrmBackLink from "../../components/common/CrmBackLink.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import UserFormFields from "../../components/users/UserFormFields.vue";
import { useUserForm } from "../../composables/useUserForm";

const router = useRouter();

const {
  loading,
  saving,
  errorMsg,
  roles,
  form,
  loadRoles,
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
    resetForCreate();
  } catch {
    errorMsg.value = "Could not load form.";
  } finally {
    loading.value = false;
  }
});

async function onSubmit() {
  const ok = await submit({ isEdit: false, userId: null });
  if (ok) {
    await router.push("/staff");
  }
}
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <PageHeader
        title="Add Staff"
        subtitle="Account, profile, and roles — all saved to the user record"
      />
      <div class="flex shrink-0 flex-wrap gap-x-4 gap-y-2">
        <CrmBackLink to="/staff" label="Back to staff" />
        <CrmBackLink to="/dashboard" label="Back to dashboard" />
      </div>
    </div>

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
        :is-edit="false"
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
          class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
          :disabled="saving"
          @click="router.push('/staff')"
        >
          <svg
            class="h-4 w-4 shrink-0"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M15 19l-7-7 7-7"
            />
          </svg>
          Cancel
        </button>
      </div>
    </form>

    <div v-else class="flex justify-center py-12">
      <CrmLoadingSpinner message="Loading..." />
    </div>
  </div>
</template>
