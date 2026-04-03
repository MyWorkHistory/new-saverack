<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {{ id: number, name?: string, website?: string, marketplace?: string } | null} */
  store: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  name: "",
  website: "",
  marketplace: "",
});

function close() {
  emit("update:open", false);
}

function onBackdrop() {
  if (!saving.value) close();
}

watch(
  () => [props.open, props.store],
  () => {
    if (!props.open || !props.store?.id) return;
    errorMsg.value = "";
    form.name = props.store.name || "";
    form.website = props.store.website || "";
    form.marketplace = props.store.marketplace || "";
  },
);

async function onSubmit() {
  if (!props.store?.id) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    await api.patch(`/client-stores/${props.store.id}`, {
      name: form.name.trim(),
      website: form.website.trim() || null,
      marketplace: form.marketplace.trim() || null,
    });
    toast.success("Store updated.");
    emit("saved");
    close();
  } catch (e) {
    errorMsg.value = "Could not save.";
    toast.errorFrom(e, "Could not save store.");
  } finally {
    saving.value = false;
  }
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
            class="relative z-10 max-h-[min(90dvh,520px)] w-full max-w-md overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"
          >
            <header
              class="sticky top-0 z-10 border-b border-gray-100 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-900"
            >
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                Edit Store
              </h2>
            </header>

            <form class="space-y-4 px-5 py-4" @submit.prevent="onSubmit">
              <p
                v-if="errorMsg"
                class="text-sm text-red-600 dark:text-red-400"
              >
                {{ errorMsg }}
              </p>
              <div>
                <label
                  class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                  >Store name</label
                >
                <input
                  v-model="form.name"
                  type="text"
                  required
                  class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                />
              </div>
              <div>
                <label
                  class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                  >Website</label
                >
                <input
                  v-model="form.website"
                  type="url"
                  class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                />
              </div>
              <div>
                <label
                  class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                  >Marketplace</label
                >
                <input
                  v-model="form.marketplace"
                  type="text"
                  class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                />
              </div>
              <div class="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                  :disabled="saving"
                  @click="close"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  class="rounded-lg bg-[#2563eb] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                  :disabled="saving"
                >
                  {{ saving ? "Saving…" : "Save" }}
                </button>
              </div>
            </form>
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
