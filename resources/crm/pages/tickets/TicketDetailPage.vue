<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();
const loading = ref(true);
const ticket = ref(null);
const meta = ref({ statuses: [], priorities: [] });
const users = ref([]);
const commentBody = ref("");
const commentBusy = ref(false);
const saveBusy = ref(false);
const error = ref("");

const form = reactive({
  title: "",
  description: "",
  status: "",
  priority: "",
  due_date: "",
  assigned_to: "",
});

const statusLabel = (v) => {
  const s = meta.value.statuses.find((x) => x.value === v);
  return s ? s.label : v;
};

const fetchMeta = async () => {
  try {
    const { data } = await api.get("/tickets/meta");
    meta.value = data;
  } catch {
    meta.value = { statuses: [], priorities: [] };
  }
};

const fetchUsers = async () => {
  try {
    const { data } = await api.get("/users", { params: { per_page: 100, page: 1 } });
    users.value = data.data || [];
  } catch {
    users.value = [];
  }
};

const loadTicket = async (silent = false) => {
  if (!silent) {
    loading.value = true;
  }
  error.value = "";
  try {
    const { data } = await api.get(`/tickets/${props.id}`);
    ticket.value = data;
    form.title = data.title;
    form.description = data.description || "";
    form.status = data.status;
    form.priority = data.priority;
    form.due_date = data.due_date || "";
    form.assigned_to = data.assigned_to ? String(data.assigned_to) : "";
  } catch (e) {
    ticket.value = null;
    if (e.response?.status === 403) {
      router.replace("/dashboard");
    } else if (e.response?.status === 404) {
      error.value = "Ticket not found.";
    } else {
      error.value = "Could not load ticket.";
    }
  } finally {
    if (!silent) {
      loading.value = false;
    }
  }
};

const save = async () => {
  saveBusy.value = true;
  error.value = "";
  try {
    const payload = {
      title: form.title,
      description: form.description || null,
      status: form.status,
      priority: form.priority,
      due_date: form.due_date || null,
      assigned_to: form.assigned_to ? parseInt(form.assigned_to, 10) : null,
    };
    await api.patch(`/tickets/${props.id}`, payload);
    await loadTicket(true);
  } catch (e) {
    error.value = e.response?.data?.message || "Could not save.";
  } finally {
    saveBusy.value = false;
  }
};

const submitComment = async () => {
  if (!commentBody.value.trim()) return;
  commentBusy.value = true;
  try {
    const { data } = await api.post(`/tickets/${props.id}/comments`, {
      body: commentBody.value.trim(),
    });
    commentBody.value = "";
    if (ticket.value && ticket.value.comments) {
      ticket.value.comments = [...ticket.value.comments, data];
    } else if (ticket.value) {
      ticket.value.comments = [data];
    }
    await loadTicket(true);
  } catch {
    error.value = "Could not post comment.";
  } finally {
    commentBusy.value = false;
  }
};

const comments = computed(() => ticket.value?.comments || []);

const formatTime = (iso) => {
  if (!iso) return "";
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
};

onMounted(async () => {
  await fetchMeta();
  await fetchUsers();
  await loadTicket();
});
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <button
        type="button"
        class="text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400"
        @click="router.push('/tickets')"
      >
        ← Back to tickets
      </button>
    </div>

    <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
    <p v-else-if="error && !ticket" class="text-sm text-red-600">{{ error }}</p>

    <template v-else-if="ticket">
      <PageHeader
        :title="ticket.title"
        :subtitle="`#${ticket.id} · ${statusLabel(ticket.status)}`"
      />

      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

      <div
        class="grid gap-6 lg:grid-cols-3"
      >
        <div class="space-y-4 lg:col-span-2">
          <div
            class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
          >
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
              Description
            </h3>
            <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">
              {{ ticket.description || "No description." }}
            </p>
          </div>

          <div
            class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
          >
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
              Discussion
            </h3>
            <div class="mt-4 space-y-4">
              <div
                v-for="c in comments"
                :key="c.id"
                class="rounded-xl border border-gray-100 bg-gray-50/80 px-4 py-3 dark:border-gray-800 dark:bg-gray-900/50"
              >
                <div class="flex items-center justify-between text-xs text-gray-500">
                  <span class="font-medium text-gray-800 dark:text-gray-200">{{
                    c.user ? c.user.name : "User"
                  }}</span>
                  <span>{{ formatTime(c.created_at) }}</span>
                </div>
                <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">
                  {{ c.body }}
                </p>
              </div>
              <p
                v-if="!comments.length"
                class="text-sm text-gray-500"
              >
                No comments yet.
              </p>
            </div>

            <form class="mt-4" @submit.prevent="submitComment">
              <label class="text-xs font-medium text-gray-500 dark:text-gray-400"
                >Add comment</label
              >
              <textarea
                v-model="commentBody"
                rows="3"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                placeholder="Write an update…"
              />
              <button
                type="submit"
                class="mt-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-gray-900"
                :disabled="commentBusy || !commentBody.trim()"
              >
                {{ commentBusy ? "Posting…" : "Post comment" }}
              </button>
            </form>
          </div>
        </div>

        <div
          class="space-y-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
        >
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
            Details
          </h3>
          <form class="space-y-3" @submit.prevent="save">
            <div>
              <label class="text-xs text-gray-500">Title</label>
              <input
                v-model="form.title"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              />
            </div>
            <div>
              <label class="text-xs text-gray-500">Status</label>
              <select
                v-model="form.status"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              >
                <option
                  v-for="s in meta.statuses"
                  :key="s.value"
                  :value="s.value"
                >
                  {{ s.label }}
                </option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-500">Priority</label>
              <select
                v-model="form.priority"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              >
                <option
                  v-for="p in meta.priorities"
                  :key="p.value"
                  :value="p.value"
                >
                  {{ p.label }}
                </option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-500">Due date</label>
              <input
                v-model="form.due_date"
                type="date"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              />
            </div>
            <div>
              <label class="text-xs text-gray-500">Assignee</label>
              <select
                v-model="form.assigned_to"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              >
                <option value="">Unassigned</option>
                <option
                  v-for="u in users"
                  :key="u.id"
                  :value="String(u.id)"
                >
                  {{ u.name }}
                </option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-500">Description</label>
              <textarea
                v-model="form.description"
                rows="5"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              />
            </div>
            <button
              type="submit"
              class="w-full rounded-lg bg-emerald-600 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
              :disabled="saveBusy"
            >
              {{ saveBusy ? "Saving…" : "Save changes" }}
            </button>
          </form>
        </div>
      </div>
    </template>
  </div>
</template>
