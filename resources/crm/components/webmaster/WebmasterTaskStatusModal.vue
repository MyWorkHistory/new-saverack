<script setup>
import { onUnmounted, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  task: { type: Object, default: null },
  statuses: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "save"]);

const status = ref("pending");

watch(
  () => props.open,
  (o) => {
    if (!o) return;
    status.value = String(props.task?.status || "pending");
  },
);

function close() {
  emit("update:open", false);
}

function onSubmit() {
  if (!props.task) return;
  emit("save", { task: props.task, status: status.value });
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
        aria-labelledby="wm-task-status-title"
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
              <h2 id="wm-task-status-title" class="crm-vx-modal__title">Update Status</h2>
              <p v-if="task?.title" class="crm-vx-modal__subtitle text-truncate">
                {{ task.title }}
              </p>
            </header>
            <div class="crm-vx-modal__body pt-0">
              <label class="form-label" for="wm-task-status-select">Status</label>
              <select
                id="wm-task-status-select"
                v-model="status"
                class="form-select"
                :disabled="busy"
              >
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <footer class="crm-vx-modal__foot">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="close">
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="busy || !task || status === task.status"
                @click="onSubmit"
              >
                {{ busy ? "Saving…" : "Save" }}
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
