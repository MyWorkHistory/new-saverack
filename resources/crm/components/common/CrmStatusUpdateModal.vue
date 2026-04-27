<script setup>
import { onUnmounted, watch } from "vue";

const props = defineProps({
  title: { type: String, default: "Update status" },
  subtitle: { type: String, default: "Choose a new status." },
  label: { type: String, default: "Status" },
  statuses: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const open = defineModel("open", { type: Boolean, default: false });
const status = defineModel("status", { type: String, default: "" });

const emit = defineEmits(["save"]);

function displayStatus(value) {
  return String(value || "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

function close() {
  if (!props.busy) open.value = false;
}

function onEsc(e) {
  if (e.key === "Escape" && open.value && !props.busy) {
    e.preventDefault();
    close();
  }
}

watch(open, (o) => {
  if (o) {
    document.addEventListener("keydown", onEsc);
  } else {
    document.removeEventListener("keydown", onEsc);
  }
});

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
        aria-labelledby="crm-status-update-modal-title"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="close" />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="busy"
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
              <h2 id="crm-status-update-modal-title" class="crm-vx-modal__title">
                {{ title }}
              </h2>
              <p v-if="subtitle" class="crm-vx-modal__subtitle">
                {{ subtitle }}
              </p>
            </header>

            <div class="crm-vx-modal__body">
              <form
                id="crm-status-update-modal-form"
                class="d-flex flex-column gap-3"
                @submit.prevent="$emit('save')"
              >
                <div>
                  <label class="form-label small mb-1 text-secondary" for="crm-status-update-value">
                    {{ label }}
                  </label>
                  <select
                    id="crm-status-update-value"
                    v-model="status"
                    class="form-select text-capitalize"
                    :disabled="busy"
                    required
                  >
                    <option v-for="st in statuses" :key="st" :value="st">
                      {{ displayStatus(st) }}
                    </option>
                  </select>
                </div>
              </form>
            </div>

            <footer class="crm-vx-modal__footer">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="busy"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="submit"
                form="crm-status-update-modal-form"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="busy"
              >
                {{ busy ? "Saving..." : "Save" }}
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
