<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const saving = ref(false);
const errorMsg = ref("");
const fieldErrors = ref({});

const isEdit = computed(() => route.name === "users-edit");
const userId = computed(() => (isEdit.value ? String(route.params.id) : null));

const roles = ref([]);

const form = reactive({
  name: "",
  email: "",
  password: "",
  phone: "",
  status: "pending",
  role_ids: [],
});

const title = computed(() =>
  isEdit.value ? "Edit user" : "Add user",
);

const loadRoles = async () => {
  try {
    const { data } = await api.get("/roles");
    roles.value = Array.isArray(data) ? data : [];
  } catch {
    roles.value = [];
  }
};

const loadUser = async () => {
  if (!isEdit.value || !userId.value) return;
  const { data } = await api.get(`/users/${userId.value}`);
  form.name = data.name || "";
  form.email = data.email || "";
  form.password = "";
  form.phone = data.profile?.phone || data.phone || "";
  form.status = data.status || "pending";
  const ids = (data.roles || []).map((r) => r.id);
  form.role_ids = ids;
};

const toggleRole = (id) => {
  const n = Number(id);
  const i = form.role_ids.indexOf(n);
  if (i === -1) {
    form.role_ids.push(n);
  } else {
    form.role_ids.splice(i, 1);
  }
};

const roleChecked = (id) => form.role_ids.includes(Number(id));

const clearFieldError = (key) => {
  if (!fieldErrors.value[key]) return;
  const next = { ...fieldErrors.value };
  delete next[key];
  fieldErrors.value = next;
};

const firstError = (key) => {
  const v = fieldErrors.value[key];
  return Array.isArray(v) && v.length ? v[0] : "";
};

const submit = async () => {
  saving.value = true;
  errorMsg.value = "";
  fieldErrors.value = {};
  try {
    const payload = {
      name: form.name.trim(),
      email: form.email.trim(),
      status: form.status,
      phone: form.phone?.trim() || null,
      role_ids: form.role_ids,
    };
    if (isEdit.value) {
      if (form.password.trim()) {
        payload.password = form.password;
      }
      await api.put(`/users/${userId.value}`, payload);
    } else {
      payload.password = form.password;
      await api.post("/users", payload);
    }
    await router.push("/users");
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.errors) {
      fieldErrors.value = e.response.data.errors;
    } else {
      const msg =
        e.response?.data?.message ||
        e.response?.data?.error ||
        "Could not save user.";
      errorMsg.value = typeof msg === "string" ? msg : "Could not save user.";
    }
  } finally {
    saving.value = false;
  }
};

onMounted(async () => {
  loading.value = true;
  errorMsg.value = "";
  try {
    await loadRoles();
    if (isEdit.value) {
      await loadUser();
    }
  } catch {
    errorMsg.value = "Could not load user.";
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6">
    <PageHeader
      :title="title"
      subtitle="Account details and role assignment"
    />

    <p v-if="errorMsg" class="text-sm text-red-600 dark:text-red-400">
      {{ errorMsg }}
    </p>

    <form
      v-if="!loading"
      class="space-y-5 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
      @submit.prevent="submit"
    >
      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Name</label
        >
        <input
          v-model="form.name"
          type="text"
          required
          autocomplete="name"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('name')"
        />
        <p v-if="firstError('name')" class="mt-1 text-xs text-red-600">
          {{ firstError("name") }}
        </p>
      </div>

      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Email</label
        >
        <input
          v-model="form.email"
          type="email"
          required
          autocomplete="email"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('email')"
        />
        <p v-if="firstError('email')" class="mt-1 text-xs text-red-600">
          {{ firstError("email") }}
        </p>
      </div>

      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >{{
            isEdit ? "New password (optional)" : "Password"
          }}</label
        >
        <input
          v-model="form.password"
          type="password"
          :required="!isEdit"
          autocomplete="new-password"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('password')"
        />
        <p v-if="firstError('password')" class="mt-1 text-xs text-red-600">
          {{ firstError("password") }}
        </p>
      </div>

      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Phone</label
        >
        <input
          v-model="form.phone"
          type="text"
          autocomplete="tel"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @input="clearFieldError('phone')"
        />
        <p v-if="firstError('phone')" class="mt-1 text-xs text-red-600">
          {{ firstError("phone") }}
        </p>
      </div>

      <div>
        <label
          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Status</label
        >
        <select
          v-model="form.status"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @change="clearFieldError('status')"
        >
          <option value="pending">Pending</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        <p v-if="firstError('status')" class="mt-1 text-xs text-red-600">
          {{ firstError("status") }}
        </p>
      </div>

      <div>
        <span
          class="mb-2 block text-xs font-medium text-gray-500 dark:text-gray-400"
          >Roles</span
        >
        <div class="flex flex-wrap gap-3">
          <label
            v-for="r in roles"
            :key="r.id"
            class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600"
          >
            <input
              type="checkbox"
              :checked="roleChecked(r.id)"
              class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
              @change="toggleRole(r.id)"
            />
            <span>{{ r.label || r.name }}</span>
          </label>
        </div>
        <p v-if="firstError('role_ids')" class="mt-1 text-xs text-red-600">
          {{ firstError("role_ids") }}
        </p>
      </div>

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
