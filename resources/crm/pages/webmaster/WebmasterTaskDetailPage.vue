<script setup>
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();
const loading = ref(true);
const errorMsg = ref("");
const task = ref(null);

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

function formatIso(iso) {
  if (!iso) return "—";
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return String(iso);
  }
}

function formatStatus(raw) {
  if (raw == null || raw === "") return "—";
  return String(raw).replace(/_/g, " ");
}

function priorityClass(p) {
  const x = String(p || "").toLowerCase();
  const map = {
    low: "bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200",
    medium:
      "bg-blue-50 text-blue-800 dark:bg-blue-500/10 dark:text-blue-200",
    high: "bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200",
    urgent: "bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-200",
  };
  return (
    map[x] ||
    "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
  );
}

onMounted(async () => {
  loading.value = true;
  errorMsg.value = "";
  task.value = null;
  try {
    const { data } = await api.get(`/webmaster/tasks/${props.id}`);
    task.value = data;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You don't have access to this task.";
    } else if (st === 404) {
      errorMsg.value = "Task not found.";
    } else {
      errorMsg.value = "Could not load task.";
    }
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <PageHeader
        title="Task details"
        subtitle="Full webmaster task record"
      />
      <div class="flex shrink-0 flex-wrap gap-2">
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
          @click="router.push('/webmaster')"
        >
          Back to board
        </button>
        <button
          v-if="task"
          type="button"
          class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
          @click="
            router.push({ path: '/webmaster', query: { edit: String(task.id) } })
          "
        >
          Edit task
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <CrmLoadingSpinner message="Loading task…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ errorMsg }}
      </p>
      <button
        type="button"
        class="text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400"
        @click="router.push('/webmaster')"
      >
        ← Back to board
      </button>
    </template>

    <div
      v-else-if="task"
      class="space-y-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
    >
      <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-6 dark:border-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
          {{ task.title }}
        </h2>
        <div class="flex flex-wrap items-center gap-2">
          <span
            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ring-gray-200 dark:ring-gray-600"
            :class="priorityClass(task.priority)"
          >
            {{ task.priority }}
          </span>
          <span
            class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium capitalize text-gray-800 dark:bg-gray-800 dark:text-gray-200"
          >
            {{ formatStatus(task.status) }}
          </span>
        </div>
      </div>

      <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
          Description
        </h3>
        <p
          class="whitespace-pre-wrap text-sm leading-relaxed text-gray-800 dark:text-gray-200"
        >
          {{ display(task.description) }}
        </p>
      </section>

      <dl class="grid gap-4 border-t border-gray-100 pt-6 dark:border-gray-800 sm:grid-cols-2">
        <div>
          <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Site / client
          </dt>
          <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
            {{ display(task.account_name) }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Due date
          </dt>
          <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
            {{ display(task.due_date) }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Created
          </dt>
          <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
            {{ formatIso(task.created_at) }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Updated
          </dt>
          <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
            {{ formatIso(task.updated_at) }}
          </dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Created by
          </dt>
          <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
            <template v-if="task.creator">
              {{ task.creator.name }} · {{ task.creator.email }}
            </template>
            <template v-else>—</template>
          </dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Assigned to
          </dt>
          <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">
            <template v-if="task.assignee">
              {{ task.assignee.name }} · {{ task.assignee.email }}
            </template>
            <template v-else>Unassigned</template>
          </dd>
        </div>
      </dl>
    </div>
  </div>
</template>
