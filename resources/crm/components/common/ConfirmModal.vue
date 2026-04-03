<script setup>
import { CRM_BTN_SECONDARY } from "../../constants/dialogFooter.js";

defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: "Confirm" },
  message: { type: String, default: "" },
  confirmLabel: { type: String, default: "Delete" },
  cancelLabel: { type: String, default: "Cancel" },
  danger: { type: Boolean, default: true },
  busy: { type: Boolean, default: false },
});

defineEmits(["close", "confirm"]);
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
          class="absolute inset-0 bg-gray-900/50 backdrop-blur-[2px]"
          aria-hidden="true"
          @click="$emit('close')"
        />
        <div
          class="relative z-[100001] w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-2xl dark:border-gray-700 dark:bg-gray-900"
        >
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
            {{ title }}
          </h2>
          <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
            {{ message }}
          </p>
          <div
            class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800 sm:gap-3"
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
