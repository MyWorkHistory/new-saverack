<script setup>
import { onUnmounted, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  statuses: { type: Array, default: () => [] },
  priorities: { type: Array, default: () => [] },
  users: { type: Array, default: () => [] },
  selectedCount: { type: Number, default: 0 },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "apply"]);

const changeStatus = ref(false);
const status = ref("pending");
const changePriority = ref(false);
const priority = ref("medium");
const changeAssignee = ref(false);
const assignedTo = ref("");

watch(
  () => props.open,
  (o) => {
    if (!o) return;
    changeStatus.value = false;
    changePriority.value = false;
    changeAssignee.value = false;
    status.value = "pending";
    priority.value = "medium";
    assignedTo.value = "";
  },
);

function close() {
  emit("update:open", false);
}

function onSubmit() {
  if (!changeStatus.value && !changePriority.value && !changeAssignee.value) {
    return;
  }
  const payload = {};
  if (changeStatus.value) {
    payload.status = status.value;
  }
  if (changePriority.value) {
    payload.priority = priority.value;
  }
  if (changeAssignee.value) {
    payload.assigned_to =
      assignedTo.value === "" || assignedTo.value == null
        ? null
        : Number(assignedTo.value);
  }
  emit("apply", payload);
}

function onBackdropClick() {
  if (!props.busy) close();
}

function onEsc(e) {
  if (e.key === "Escape" && props.open && !props.busy) {
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
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        aria-modal="true"
        role="dialog"
        aria-labelledby="wm-bulk-edit-title"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="onBackdropClick" />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal crm-vx-modal--sm">
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="busy"
              @click="close"
            >
              <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <header class="crm-vx-modal__head">
              <h2 id="wm-bulk-edit-title" class="crm-vx-modal__title">Bulk Edit</h2>
            </header>
            <div class="crm-vx-modal__body pt-0">
              <p class="small text-secondary mb-3">
                Apply to <strong>{{ selectedCount }}</strong> task{{ selectedCount === 1 ? "" : "s" }}. Only checked
                fields are updated.
              </p>
              <div class="form-check mb-2">
                <input id="wm-bulk-status-on" v-model="changeStatus" class="form-check-input" type="checkbox" />
                <label class="form-check-label" for="wm-bulk-status-on">Update status</label>
              </div>
              <select
                v-if="changeStatus"
                v-model="status"
                class="form-select form-select-sm mb-3"
                :disabled="busy"
              >
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
              <div class="form-check mb-2">
                <input id="wm-bulk-priority-on" v-model="changePriority" class="form-check-input" type="checkbox" />
                <label class="form-check-label" for="wm-bulk-priority-on">Update priority</label>
              </div>
              <select
                v-if="changePriority"
                v-model="priority"
                class="form-select form-select-sm mb-3"
                :disabled="busy"
              >
                <option v-for="p in priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
              </select>
              <div class="form-check mb-2">
                <input id="wm-bulk-assignee-on" v-model="changeAssignee" class="form-check-input" type="checkbox" />
                <label class="form-check-label" for="wm-bulk-assignee-on">Update assignee</label>
              </div>
              <select
                v-if="changeAssignee"
                v-model="assignedTo"
                class="form-select form-select-sm mb-0"
                :disabled="busy"
              >
                <option value="">Unassigned</option>
                <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
              </select>
            </div>
            <footer class="crm-vx-modal__foot">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="close">
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="busy || (!changeStatus && !changePriority && !changeAssignee)"
                @click="onSubmit"
              >
                {{ busy ? "Saving…" : "Apply" }}
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
