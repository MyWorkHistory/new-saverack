<script setup>
import { computed } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: "" },
  subtitle: { type: String, default: "" },
  busy: { type: Boolean, default: false },
  /** Tailwind max-width token: xl | 2xl */
  maxWidth: { type: String, default: "2xl" },
  /** When set, wraps default slot in a form with this id (for footer submit buttons). */
  formId: { type: String, default: "" },
});

const emit = defineEmits(["update:open", "close", "submit"]);

const asideMaxWidthClass = computed(() => {
  if (props.maxWidth === "xl") {
    return "max-w-xl";
  }
  return "max-w-xl sm:max-w-2xl";
});

function close() {
  if (props.busy) return;
  emit("update:open", false);
  emit("close");
}

function onBackdropClick() {
  close();
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
        :aria-label="title || undefined"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="drawer-slide" appear>
          <aside
            class="relative flex h-full max-h-full min-h-0 w-full flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"
            :class="asideMaxWidthClass"
          >
            <header
              class="flex shrink-0 items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <div class="min-w-0">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                  {{ title }}
                </h2>
                <p
                  v-if="subtitle"
                  class="mt-1 text-sm text-gray-500 dark:text-gray-400"
                >
                  {{ subtitle }}
                </p>
              </div>
              <button
                type="button"
                class="shrink-0 rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-white/10"
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

            <form
              v-if="formId"
              :id="formId"
              class="flex min-h-0 flex-1 flex-col"
              @submit.prevent="emit('submit', $event)"
            >
              <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <slot />
              </div>
              <footer v-if="$slots.footer" class="shrink-0">
                <slot name="footer" />
              </footer>
            </form>
            <div v-else class="flex min-h-0 flex-1 flex-col">
              <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <slot />
              </div>
              <footer v-if="$slots.footer" class="shrink-0">
                <slot name="footer" />
              </footer>
            </div>
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
