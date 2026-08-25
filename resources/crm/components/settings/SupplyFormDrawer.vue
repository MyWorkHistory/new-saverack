<script setup>
import { computed, reactive, ref, watch } from "vue";
import { SUPPLY_TYPES, supplyTypeLabel } from "../../constants/supplies.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  supply: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "save"]);

const form = reactive({
  type: "box",
  name: "",
  link: "",
});
const localError = ref("");

const isEdit = computed(() => !!props.supply?.id);
const title = computed(() => (isEdit.value ? "Edit Supply" : "Add Supply"));
const submitLabel = computed(() =>
  props.busy ? "Saving…" : isEdit.value ? "Save Supply" : "Add Supply",
);

function reset() {
  form.type = props.supply?.type || "box";
  form.name = props.supply?.name || "";
  form.link = props.supply?.link || "";
  localError.value = "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) reset();
  },
);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}

function submit() {
  if (!SUPPLY_TYPES.includes(form.type)) {
    localError.value = "Select a type.";
    return;
  }
  if (!String(form.name || "").trim()) {
    localError.value = "Item name is required.";
    return;
  }
  localError.value = "";
  emit("save", {
    type: form.type,
    name: form.name.trim(),
    link: String(form.link || "").trim() || null,
  });
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
        aria-labelledby="supply-drawer-title"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
          aria-hidden="true"
          @click="close"
        />
        <Transition name="drawer-slide" appear>
          <aside
            class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:max-w-lg"
          >
            <header
              class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2
                id="supply-drawer-title"
                class="text-lg font-semibold text-gray-900 dark:text-white"
              >
                {{ title }}
              </h2>
              <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-white/10 dark:hover:text-white"
                aria-label="Close"
                :disabled="busy"
                @click="close"
              >
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
              <p v-if="localError" class="alert alert-danger py-2 small mb-3">{{ localError }}</p>

              <label class="form-label small" for="supply-type">Type</label>
              <select
                id="supply-type"
                v-model="form.type"
                class="form-select mb-3"
                :disabled="busy"
              >
                <option v-for="t in SUPPLY_TYPES" :key="t" :value="t">
                  {{ supplyTypeLabel(t) }}
                </option>
              </select>

              <label class="form-label small" for="supply-name">Item Name</label>
              <input
                id="supply-name"
                v-model="form.name"
                type="text"
                class="form-control mb-3"
                maxlength="255"
                :disabled="busy"
                placeholder="9x9x4"
              />

              <label class="form-label small" for="supply-link">Link</label>
              <input
                id="supply-link"
                v-model="form.link"
                type="url"
                class="form-control mb-3"
                maxlength="2048"
                :disabled="busy"
                placeholder="https://…"
              />
            </div>

            <footer
              class="flex shrink-0 items-center justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="busy"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="busy"
                @click="submit"
              >
                {{ submitLabel }}
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
  transition: opacity 0.18s ease;
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
  opacity: 0;
}
.drawer-slide-enter-active,
.drawer-slide-leave-active {
  transition: transform 0.22s ease;
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(100%);
}
</style>
