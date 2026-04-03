<script setup>
import { ref, watch } from "vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  selectedCount: { type: Number, default: 0 },
  busy: { type: Boolean, default: false },
  statuses: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "apply"]);

const changeStatus = ref(false);
const status = ref("pending");

watch(
  () => props.open,
  (o) => {
    if (!o) return;
    changeStatus.value = false;
    status.value = "pending";
  },
);

function close() {
  emit("update:open", false);
}

function onSubmit() {
  if (!changeStatus.value) return;
  emit("apply", { status: status.value });
}

function onBackdrop() {
  if (!props.busy) close();
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="fixed inset-0 z-[240] flex items-center justify-center p-4 sm:p-6"
        aria-modal="true"
        role="dialog"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[2px] dark:bg-black/55"
          aria-hidden="true"
          @click="onBackdrop"
        />
        <Transition name="modal-panel" appear>
          <div
            class="relative z-10 max-h-[min(90dvh,640px)] w-full max-w-sm overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"
          >
            <header
              class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800"
            >
              <div class="min-w-0">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                  Bulk Edit
                </h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                  {{ selectedCount }} account(s) selected
                </p>
              </div>
              <button
                type="button"
                class="shrink-0 rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 disabled:opacity-50 dark:hover:bg-white/10 dark:hover:text-white"
                aria-label="Close"
                :disabled="busy"
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
            <div class="space-y-4 px-5 py-4">
              <label class="flex cursor-pointer items-center gap-2">
                <input
                  v-model="changeStatus"
                  type="checkbox"
                  class="h-4 w-4 rounded border-gray-300 text-[#2563eb] focus:ring-[#2563eb]/30"
                />
                <span class="text-sm font-medium text-gray-800 dark:text-gray-200">
                  Change status
                </span>
              </label>
              <div v-if="changeStatus">
                <label
                  class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                  >Status</label
                >
                <select
                  v-model="status"
                  class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                >
                  <option v-for="s in statuses" :key="s" :value="s">
                    {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                  </option>
                </select>
              </div>
            </div>
            <footer :class="CRM_DIALOG_FOOTER_CLASS">
              <button
                type="button"
                :class="CRM_BTN_SECONDARY"
                :disabled="busy"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="button"
                :class="CRM_BTN_PRIMARY"
                :disabled="busy || !changeStatus"
                @click="onSubmit"
              >
                {{ busy ? "Applying…" : "Apply" }}
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
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}
.modal-panel-enter-active,
.modal-panel-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.98);
}
</style>
