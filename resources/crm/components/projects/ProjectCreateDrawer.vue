<script setup>
import { computed, ref, watch } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  accountOptions: { type: Array, default: () => [] },
  accountsLoading: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "submit"]);

const clientAccountId = ref("");
const name = ref("");
const description = ref("");
const errorMsg = ref("");

const canSubmit = computed(
  () => !!clientAccountId.value && String(name.value || "").trim() !== "" && !props.busy,
);

watch(
  () => props.open,
  (open) => {
    if (open) {
      clientAccountId.value = "";
      name.value = "";
      description.value = "";
      errorMsg.value = "";
    }
  },
);

function close() {
  if (!props.busy) emit("update:open", false);
}

function submit() {
  if (!canSubmit.value) {
    errorMsg.value = "Account and project name are required.";
    return;
  }
  errorMsg.value = "";
  emit("submit", {
    client_account_id: Number(clientAccountId.value),
    name: String(name.value).trim(),
    description: String(description.value || "").trim() || null,
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
        aria-labelledby="add-project-title"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px]"
          aria-hidden="true"
          @click="close"
        />
        <aside
          class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl sm:max-w-lg"
        >
          <header
            class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4"
          >
            <h2 id="add-project-title" class="text-lg font-semibold text-gray-900 mb-0">
              Add Project
            </h2>
            <button
              type="button"
              class="rounded-lg p-2 text-gray-500"
              aria-label="Close"
              :disabled="busy"
              @click="close"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </header>

          <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
            <p v-if="errorMsg" class="small text-danger">{{ errorMsg }}</p>
            <label class="form-label">Account Name</label>
            <CrmSearchableSelect
              v-model="clientAccountId"
              class="mb-3"
              appearance="staff"
              :options="accountOptions"
              placeholder="Search accounts…"
              :disabled="busy"
              :allow-empty="false"
              teleport-panel
            />
            <label class="form-label" for="add-project-name">Project Name</label>
            <input
              id="add-project-name"
              v-model="name"
              type="text"
              class="form-control mb-3"
              :disabled="busy"
              autocomplete="off"
            />
            <label class="form-label" for="add-project-desc">Description</label>
            <textarea
              id="add-project-desc"
              v-model="description"
              class="form-control"
              rows="4"
              :disabled="busy"
            />
          </div>

          <footer
            class="flex shrink-0 justify-end gap-2 border-t border-gray-200 px-5 py-4"
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
              :disabled="!canSubmit"
              @click="submit"
            >
              <CrmLoadingSpinner v-if="busy" small class="me-1" />
              Create Project
            </button>
          </footer>
        </aside>
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
</style>
