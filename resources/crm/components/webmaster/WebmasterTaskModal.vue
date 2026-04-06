<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";

const toast = useToast();

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Task row from list/detail; must include `id` for PUT */
  task: { type: Object, default: null },
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

function applyTask(t) {
  if (!t || !t.id) {
    return;
  }
  form.title = t.title || "";
  form.description = t.description || "";
  form.status = t.status || "pending";
  form.priority = t.priority || "medium";
  form.price =
    t.price !== null && t.price !== undefined && t.price !== ""
      ? String(t.price)
      : "";
  form.due_date = t.due_date || "";
  form.assigned_to = t.assigned_to ? String(t.assigned_to) : "";
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
  () => [props.open, props.task],
  async ([isOpen, task]) => {
    if (!isOpen || !task || !task.id) return;
    await ensureSupportData();
    applyTask(task);
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

function onBackdropClick() {
  if (!saving.value && !loadingSupport.value) close();
}

async function onSubmit() {
  const t = props.task;
  if (!t || !t.id) return;
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
    await api.put(`/webmaster/tasks/${t.id}`, payload);
    toast.success("Task saved.");
    emit("saved");
    close();
  } catch (e) {
    errorMsg.value = errorMessage(e, "Could not save task.");
    toast.errorFrom(e, "Could not save task.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open && task && task.id"
        class="crm-vx-modal-overlay"
        aria-modal="true"
        role="dialog"
        aria-labelledby="webmaster-task-modal-title"
      >
        <div
          class="crm-vx-modal-backdrop"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal">
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="saving"
              @click="close"
            >
              <svg
                width="20"
                height="20"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.75"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>

            <header class="crm-vx-modal__head">
              <h2 id="webmaster-task-modal-title" class="crm-vx-modal__title">
                Edit task
              </h2>
              <p class="crm-vx-modal__subtitle">
                Update ticket details, assignment, and schedule.
              </p>
            </header>

            <div class="crm-vx-modal__body">
              <div v-if="loadingSupport" class="d-flex justify-content-center py-5">
                <CrmLoadingSpinner message="Loading…" />
              </div>
              <template v-else>
                <p
                  v-if="errorMsg"
                  class="small text-danger mb-3 text-center"
                >
                  {{ errorMsg }}
                </p>

                <form
                  id="webmaster-task-modal-form"
                  class="d-flex flex-column gap-3"
                  @submit.prevent="onSubmit"
                >
                  <div>
                    <label class="form-label small mb-1 text-secondary" for="wtm-title"
                      >Title <span class="text-danger">*</span></label
                    >
                    <input
                      id="wtm-title"
                      v-model="form.title"
                      type="text"
                      class="form-control"
                      required
                      maxlength="255"
                    />
                  </div>
                  <div>
                    <label class="form-label small mb-1 text-secondary" for="wtm-desc"
                      >Description</label
                    >
                    <textarea
                      id="wtm-desc"
                      v-model="form.description"
                      rows="6"
                      class="form-control"
                      style="min-height: 8rem"
                    />
                  </div>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label small mb-1 text-secondary" for="wtm-price"
                        >Ticket price</label
                      >
                      <input
                        id="wtm-price"
                        v-model="form.price"
                        type="number"
                        class="form-control"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                      />
                    </div>
                    <div class="col-md-6">
                      <label class="form-label small mb-1 text-secondary" for="wtm-due"
                        >Due date</label
                      >
                      <input
                        id="wtm-due"
                        v-model="form.due_date"
                        type="date"
                        class="form-control"
                      />
                    </div>
                  </div>
                  <div class="row g-3">
                    <div class="col-sm-6">
                      <label class="form-label small mb-1 text-secondary" for="wtm-status"
                        >Status</label
                      >
                      <select id="wtm-status" v-model="form.status" class="form-select">
                        <option
                          v-for="s in displayStatuses"
                          :key="s.value"
                          :value="s.value"
                        >
                          {{ s.label }}
                        </option>
                      </select>
                    </div>
                    <div class="col-sm-6">
                      <label
                        class="form-label small mb-1 text-secondary"
                        for="wtm-priority"
                        >Priority</label
                      >
                      <select
                        id="wtm-priority"
                        v-model="form.priority"
                        class="form-select"
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
                    <label class="form-label small mb-1 text-secondary" for="wtm-assign"
                      >Assign to</label
                    >
                    <select id="wtm-assign" v-model="form.assigned_to" class="form-select">
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

            <footer
              v-if="!loadingSupport"
              class="crm-vx-modal__footer"
            >
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="submit"
                form="webmaster-task-modal-form"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="saving"
              >
                {{ saving ? "Saving…" : "Save" }}
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-backdrop-enter-active,
.modal-backdrop-leave-active {
  transition: opacity 0.2s ease;
}
.modal-backdrop-enter-active .crm-vx-modal-backdrop,
.modal-backdrop-leave-active .crm-vx-modal-backdrop {
  transition: inherit;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}

.modal-panel-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}
</style>
