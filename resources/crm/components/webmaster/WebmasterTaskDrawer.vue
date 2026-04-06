<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const toast = useToast();

const props = defineProps({
  open: { type: Boolean, default: false },
  users: { type: Array, default: () => [] },
  statuses: { type: Array, default: () => [] },
  priorities: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "saved"]);

const saving = ref(false);
const errorMsg = ref("");
const loadingSupport = ref(false);

const localUsers = ref([]);
const localStatuses = ref([]);
const localPriorities = ref([]);

const displayUsers = computed(() =>
  props.users.length ? props.users : localUsers.value,
);
const displayStatuses = computed(() =>
  props.statuses.length ? props.statuses : localStatuses.value,
);
const displayPriorities = computed(() =>
  props.priorities.length ? props.priorities : localPriorities.value,
);

const form = reactive({
  title: "",
  description: "",
  status: "pending",
  priority: "medium",
  price: "",
  due_date: "",
  assigned_to: "",
});

function resetForm() {
  form.title = "";
  form.description = "";
  form.status = "pending";
  form.priority = "medium";
  form.price = "";
  form.due_date = "";
  form.assigned_to = "";
  errorMsg.value = "";
}

async function ensureSupportData() {
  loadingSupport.value = true;
  try {
    if (!props.users.length) {
      const { data } = await api.get("/users", {
        params: { per_page: 100, page: 1 },
      });
      localUsers.value = data.data || [];
    } else {
      localUsers.value = [];
    }
    if (!props.statuses.length || !props.priorities.length) {
      const { data } = await api.get("/webmaster/tasks/meta");
      localStatuses.value = data.statuses || [];
      localPriorities.value = data.priorities || [];
    } else {
      localStatuses.value = [];
      localPriorities.value = [];
    }
  } catch {
    localUsers.value = [];
    localStatuses.value = [];
    localPriorities.value = [];
  } finally {
    loadingSupport.value = false;
  }
}

watch(
  () => props.open,
  async (isOpen) => {
    if (!isOpen) return;
    await ensureSupportData();
    resetForm();
  },
);

function onEsc(e) {
  if (e.key === "Escape" && props.open && !saving.value && !loadingSupport.value) {
    e.preventDefault();
    close();
  }
}

watch(
  () => props.open,
  (o) => {
    if (o) {
      document.addEventListener("keydown", onEsc);
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => {
  document.removeEventListener("keydown", onEsc);
});

function close() {
  emit("update:open", false);
}

async function onSubmit() {
  saving.value = true;
  errorMsg.value = "";
  try {
    const priceRaw =
      form.price === null || form.price === undefined || form.price === ""
        ? ""
        : String(form.price).trim();
    const payload = {
      title: form.title.trim(),
      description: form.description?.trim() || null,
      status: form.status,
      priority: form.priority,
      price: priceRaw === "" ? null : Number(priceRaw),
      due_date: form.due_date || null,
      assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
    };
    await api.post("/webmaster/tasks", payload);
    toast.success("Task Created.");
    emit("saved");
    close();
    resetForm();
  } catch (e) {
    errorMsg.value = errorMessage(e, "Could Not Save Task.");
    toast.errorFrom(e, "Could Not Save Task.");
  } finally {
    saving.value = false;
  }
}

function onBackdropClick() {
  if (!saving.value && !loadingSupport.value) close();
}
</script>

<template>
  <Teleport to="body">
    <Transition name="drawer-fade">
      <div
        v-if="open"
        class="fixed inset-0 z-[1200] flex h-[100dvh] max-h-[100dvh] justify-end overflow-hidden"
        aria-modal="true"
        role="dialog"
        aria-labelledby="webmaster-task-drawer-title"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="drawer-slide" appear>
          <aside
            class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:max-w-2xl"
          >
            <header
              class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2
                id="webmaster-task-drawer-title"
                class="text-lg font-semibold text-gray-900 dark:text-white"
              >
                Add Task
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
              <div
                v-if="loadingSupport"
                class="flex justify-center py-10 text-sm text-gray-500 dark:text-gray-400"
              >
                Loading…
              </div>
              <template v-else>
                <p
                  v-if="errorMsg"
                  class="mb-4 text-sm text-red-600 dark:text-red-400"
                >
                  {{ errorMsg }}
                </p>

                <form
                  id="webmaster-task-drawer-form"
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
                      rows="8"
                      class="min-h-[12rem] w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>
                  <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                        >Ticket Price</label
                      >
                      <input
                        v-model="form.price"
                        type="number"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </div>
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                        >Due Date</label
                      >
                      <input
                        v-model="form.due_date"
                        type="date"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </div>
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
                          v-for="s in displayStatuses"
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
                          v-for="p in displayPriorities"
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
                      >Assign To</label
                    >
                    <select
                      v-model="form.assigned_to"
                      class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    >
                      <option value="">Unassigned</option>
                      <option
                        v-for="u in displayUsers"
                        :key="u.id"
                        :value="String(u.id)"
                      >
                        {{ u.name }} ({{ u.email }})
                      </option>
                    </select>
                  </div>
                </form>
              </template>
            </div>

            <footer v-if="!loadingSupport" :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
              <button
                type="button"
                :class="CRM_BTN_SECONDARY"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="submit"
                form="webmaster-task-drawer-form"
                :disabled="saving"
                :class="CRM_BTN_PRIMARY"
              >
                {{ saving ? "Saving…" : "Save" }}
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
