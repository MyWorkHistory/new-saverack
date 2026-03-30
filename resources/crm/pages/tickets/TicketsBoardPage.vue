<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";

const router = useRouter();
const loading = ref(true);
const tickets = ref([]);
const meta = ref({ statuses: [] });

const columns = computed(() => meta.value.statuses || []);

const byStatus = computed(() => {
  const map = {};
  for (const c of columns.value) {
    map[c.value] = [];
  }
  for (const t of tickets.value) {
    if (!map[t.status]) {
      map[t.status] = [];
    }
    map[t.status].push(t);
  }
  return map;
});

const priorityStripe = (p) => {
  const x = String(p || "").toLowerCase();
  if (x === "urgent") return "border-l-red-500";
  if (x === "high") return "border-l-amber-500";
  if (x === "medium") return "border-l-blue-500";
  return "border-l-slate-400";
};

const fetchMeta = async () => {
  try {
    const { data } = await api.get("/tickets/meta");
    meta.value = data;
  } catch {
    meta.value = { statuses: [] };
  }
};

const fetchBoard = async () => {
  loading.value = true;
  try {
    const { data } = await api.get("/tickets", {
      params: {
        per_page: 500,
        page: 1,
        sort_by: "position",
        sort_dir: "asc",
      },
    });
    tickets.value = data.data || [];
  } catch (e) {
    tickets.value = [];
    if (e.response?.status === 403) {
      router.replace("/dashboard");
    }
  } finally {
    loading.value = false;
  }
};

const openCard = (id) => {
  router.push(`/tickets/${id}`);
};

onMounted(async () => {
  await fetchMeta();
  await fetchBoard();
});
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      title="Ticket board"
      subtitle="Columns by status — click a card for detail and comments"
    />
    <div class="flex flex-wrap items-center gap-3">
      <router-link
        to="/tickets"
        class="text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400"
      >
        ← Back to list
      </router-link>
      <button
        type="button"
        class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-600"
        :disabled="loading"
        @click="fetchBoard"
      >
        Refresh
      </button>
    </div>

    <p v-if="loading" class="text-sm text-gray-500">Loading board…</p>

    <div
      v-else
      class="flex gap-4 overflow-x-auto pb-4"
    >
      <div
        v-for="col in columns"
        :key="col.value"
        class="w-72 shrink-0 rounded-2xl border border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-900/40"
      >
        <div
          class="border-b border-gray-200 px-3 py-2 text-sm font-semibold text-gray-800 dark:border-gray-700 dark:text-gray-100"
        >
          {{ col.label }}
          <span class="ml-1 text-xs font-normal text-gray-500">
            ({{ (byStatus[col.value] || []).length }})
          </span>
        </div>
        <div class="space-y-2 p-2">
          <button
            v-for="t in byStatus[col.value] || []"
            :key="t.id"
            type="button"
            class="w-full rounded-xl border border-gray-200 bg-white p-3 text-left shadow-sm transition hover:border-emerald-400/60 dark:border-gray-700 dark:bg-gray-900"
            :class="['border-l-4', priorityStripe(t.priority)]"
            @click="openCard(t.id)"
          >
            <div class="text-sm font-medium text-gray-900 dark:text-white">
              {{ t.title }}
            </div>
            <div class="mt-1 flex items-center justify-between text-xs text-gray-500">
              <span class="uppercase">{{ t.priority }}</span>
              <span v-if="t.assignee">{{ t.assignee.name }}</span>
            </div>
          </button>
          <p
            v-if="!(byStatus[col.value] || []).length"
            class="px-1 py-4 text-center text-xs text-gray-400"
          >
            Empty
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
