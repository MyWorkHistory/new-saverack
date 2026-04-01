<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";

const toast = useToast();

const props = defineProps({
  open: { type: Boolean, default: false },
  task: { type: Object, default: null },
  users: { type: Array, default: () => [] },
  statuses: { type: Array, default: () => [] },
  priorities: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "saved"]);

const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  title: "",
  description: "",
  status: "pending",
  priority: "medium",
  account_name: "",
  due_date: "",
  assigned_to: "",
});

function resetForm() {
  form.title = "";
  form.description = "";
  form.status = "pending";
  form.priority = "medium";
  form.account_name = "";
  form.due_date = "";
  form.assigned_to = "";
  errorMsg.value = "";
}

function applyTask(t) {
  if (!t) {
    resetForm();
    return;
  }
  form.title = t.title || "";
  form.description = t.description || "";
  form.status = t.status || "pending";
  form.priority = t.priority || "medium";
  form.account_name = t.account_name || "";
  form.due_date = t.due_date || "";
  form.assigned_to = t.assigned_to ? String(t.assigned_to) : "";
  errorMsg.value = "";
}

watch(
  () => [props.open, props.task],
  () => {
    if (!props.open) return;
    applyTask(props.task);
  },
);

function close() {
  emit("update:open", false);
}

async function onSubmit() {
  saving.value = true;
  errorMsg.value = "";
  try {
    const payload = {
      title: form.title.trim(),
      description: form.description?.trim() || null,
      status: form.status,
      priority: form.priority,
      account_name: form.account_name?.trim() || null,
      due_date: form.due_date || null,
      assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
    };
    if (props.task?.id) {
      await api.put(`/webmaster/tasks/${props.task.id}`, payload);
      toast.success("Task saved.");
    } else {
      await api.post("/webmaster/tasks", payload);
      toast.success("Task created.");
    }
    emit("saved");
    close();
    resetForm();
  } catch (e) {
    errorMsg.value = errorMessage(e, "Could not save task.");
    toast.errorFrom(e, "Could not save task.");
  } finally {
    saving.value = false;
  }
}

function onBackdropClick() {
  if (!saving.value) close();
}
</script>

<template>
  <Teleport to="body">
    <Transition name="drawer-fade">
      <div
        v-if="open"
        class="fixed inset-0 z-[200] flex h-[100dvh] max-h-[100dvh] justify-end overflow-hidden"
        aria-modal="true"
        role="dialog"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="drawer-slide" appear>
          <aside
            class="relative flex h-full w-full max-w-md flex-col border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:max-w-lg"
          >
            <header
              class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                {{ task?.id ? "Edit task" : "Add task" }}
              </h2>
              <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-white/10 dark:hover:text-white"
                aria-label="Close"
                :disabled="saving"
                @click="close"
              >
                <svg
                  class="h-5 w-5"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </header>

            <div
              class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden overscroll-y-contain px-5 py-4 [scrollbar-gutter:stable]"
            >
              <p
                v-if="errorMsg"
                class="mb-4 text-sm text-red-600 dark:text-red-400"
              >
                {{ errorMsg }}
              </p>

              <form
                id="webmaster-task-form"
                class="space-y-4"
                @submit.prevent="onSubmit"
              >
                <div>
                  <label
                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                    >Title<span class="text-red-500">*</span></label
                  >
                  <input
                    v-model="form.title"
                    type="text"
                    required
                    maxlength="255"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  />
                </div>
                <div>
                  <label
                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                    >Description</label
                  >
                  <textarea
                    v-model="form.description"
                    rows="3"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  />
                </div>
                <div>
                  <label
                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                    >Site / client</label
                  >
                  <input
                    v-model="form.account_name"
                    type="text"
                    placeholder="e.g. Client site name"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  />
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label
                      class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                      >Status</label
                    >
                    <select
                      v-model="form.status"
                      class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    >
                      <option
                        v-for="s in statuses"
                        :key="s.value"
                        :value="s.value"
                      >
                        {{ s.label }}
                      </option>
                    </select>
                  </div>
                  <div>
                    <label
                      class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                      >Priority</label
                    >
                    <select
                      v-model="form.priority"
                      class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    >
                      <option
                        v-for="p in priorities"
                        :key="p.value"
                        :value="p.value"
                      >
                        {{ p.label }}
                      </option>
                    </select>
                  </div>
                </div>
                <div>
                  <label
                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                    >Due date</label
                  >
                  <input
                    v-model="form.due_date"
                    type="date"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  />
                </div>
                <div>
                  <label
                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                    >Assign to</label
                  >
                  <select
                    v-model="form.assigned_to"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  >
                    <option value="">Unassigned</option>
                    <option v-for="u in users" :key="u.id" :value="String(u.id)">
                      {{ u.name }} ({{ u.email }})
                    </option>
                  </select>
                </div>
              </form>
            </div>

            <footer
              class="flex shrink-0 gap-3 border-t border-gray-200 bg-gray-50/80 px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom,0px))] dark:border-gray-800 dark:bg-gray-900/80"
            >
              <button
                type="submit"
                form="webmaster-task-form"
                :disabled="saving"
                class="flex min-h-[2.75rem] min-w-0 flex-1 basis-0 items-center justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
              >
                {{ saving ? "Saving…" : "Save" }}
              </button>
              <button
                type="button"
                class="flex min-h-[2.75rem] min-w-0 flex-1 basis-0 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
            </footer>
          </aside>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.drawer-fade-enter-active,
.drawer-fade-leave-active {
  transition: opacity 0.2s ease;
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
  opacity: 0;
}
.drawer-slide-enter-active,
.drawer-slide-leave-active {
  transition: transform 0.25s ease;
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(100%);
}
</style>
