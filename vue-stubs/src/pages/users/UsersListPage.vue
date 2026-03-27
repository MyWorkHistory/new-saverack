<script setup>
import { onMounted, reactive, ref } from "vue";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";

const loading = ref(false);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const query = reactive({
  search: "",
  per_page: 10,
  page: 1,
});

const fetchUsers = async () => {
  loading.value = true;
  try {
    const { data } = await api.get("/users", { params: query });
    rows.value = data.data;
    pagination.value = {
      current_page: data.current_page,
      last_page: data.last_page,
      total: data.total,
    };
  } finally {
    loading.value = false;
  }
};

const removeUser = async (id) => {
  if (!window.confirm("Delete this user?")) return;
  await api.delete(`/users/${id}`);
  await fetchUsers();
};

onMounted(fetchUsers);
</script>

<template>
  <div class="space-y-4">
    <PageHeader title="Users" subtitle="Manage Admin and Staff accounts" />
    <div class="flex items-center justify-between">
      <div />
      <a href="/users/new" class="inline-flex rounded-lg bg-brand-500 px-4 py-2 text-white hover:bg-brand-600">New User</a>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
      <div class="mb-3">
        <input v-model="query.search" @keyup.enter="fetchUsers" placeholder="Search name/email/phone..." class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-gray-800 md:w-96 dark:border-gray-700 dark:text-white/90" />
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b text-left dark:border-gray-800">
            <th class="py-2">Name</th>
            <th class="py-2">Email</th>
            <th class="py-2">Role</th>
            <th class="py-2">Status</th>
            <th class="py-2 w-28">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="5" class="py-4 text-center text-slate-500">Loading...</td>
          </tr>
          <tr v-for="user in rows" :key="user.id" class="border-b dark:border-gray-800">
            <td class="py-2">{{ user.name }}</td>
            <td class="py-2">{{ user.email }}</td>
            <td class="py-2">{{ user.role?.label || "-" }}</td>
            <td class="py-2">{{ user.status }}</td>
            <td class="py-2">
              <a :href="`/users/${user.id}/edit`" class="text-blue-600 mr-3">Edit</a>
              <button class="text-red-600" @click="removeUser(user.id)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="mt-4 text-sm text-slate-600">
        Total: {{ pagination.total }} | Page {{ pagination.current_page }} / {{ pagination.last_page }}
      </div>
    </div>
  </div>
</template>

