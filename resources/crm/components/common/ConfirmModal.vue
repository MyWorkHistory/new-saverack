<script setup>
import { CRM_BTN_SECONDARY } from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: "Confirm" },
  message: { type: String, default: "" },
  confirmLabel: { type: String, default: "Delete" },
  cancelLabel: { type: String, default: "Cancel" },
  danger: { type: Boolean, default: true },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "confirm"]);

function onBackdrop() {
  if (!props.busy) emit("close");
}
</script>

<template>
  <Teleport to="body">
    <Transition name="crm-modal">
      <div
        v-if="open"
        class="fixed inset-0 z-[100000] flex items-center justify-center overflow-y-auto px-4 py-8"
        role="dialog"
        aria-modal="true"
      >
        <div
          class="absolute inset-0 bg-gray-900/50 backdrop-blur-[2px] dark:bg-black/55"
          aria-hidden="true"
          @click="onBackdrop"
        />
        <div
          class="relative z-[100001] w-full max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"
        >
          <div
            class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800"
          >
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
              {{ title }}
            </h2>
            <button
              type="button"
              class="shrink-0 rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 disabled:opacity-50 dark:hover:bg-white/10 dark:hover:text-white"
              aria-label="Close"
              :disabled="busy"
              @click="$emit('close')"
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
          </div>
          <p class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300">
            {{ message }}
          </p>
          <div
            class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 px-5 py-4 dark:border-gray-800 sm:gap-3"
          >
            <button
              type="button"
              :class="CRM_BTN_SECONDARY"
              :disabled="busy"
              @click="$emit('close')"
            >
              {{ cancelLabel }}
            </button>
            <button
              type="button"
              class="inline-flex min-h-[2.75rem] items-center justify-center rounded-xl px-5 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
              :class="
                danger
                  ? 'bg-red-600 hover:bg-red-700 focus:ring-red-500/40'
                  : 'bg-[#2563eb] hover:opacity-95 focus:ring-[#2563eb]/40'
              "
              :disabled="busy"
              @click="$emit('confirm')"
            >
              {{ busy ? "Please Wait…" : confirmLabel }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.crm-modal-enter-active,
.crm-modal-leave-active {
  transition: opacity 0.15s ease;
}
.crm-modal-enter-from,
.crm-modal-leave-to {
  opacity: 0;
}
</style>
