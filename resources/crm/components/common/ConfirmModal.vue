<script setup>
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
          class="relative z-[100001] w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-700 dark:bg-gray-900"
        >
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
            {{ title }}
          </h2>
          <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
            {{ message }}
          </p>
          <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button
              type="button"
              class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
              :disabled="busy"
              @click="$emit('close')"
            >
              {{ cancelLabel }}
            </button>
            <button
              type="button"
              class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
              :class="
                danger
                  ? 'bg-red-600 hover:bg-red-700'
                  : 'bg-brand-500 hover:bg-brand-600'
              "
              :disabled="busy"
              @click="$emit('confirm')"
            >
              {{ busy ? "Please wait…" : confirmLabel }}
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
