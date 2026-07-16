<script setup>
import { ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  errorMsg: { type: String, default: "" },
  name: { type: String, default: "" },
  description: { type: String, default: "" },
});

const emit = defineEmits(["update:open", "submit"]);

const nameDraft = ref("");
const descriptionDraft = ref("");

watch(
  () => props.open,
  (open) => {
    if (open) {
      nameDraft.value = props.name || "";
      descriptionDraft.value = props.description || "";
    }
  },
);

function close() {
  if (!props.busy) emit("update:open", false);
}

function submit() {
  emit("submit", {
    name: String(nameDraft.value || "").trim(),
    description: String(descriptionDraft.value || "").trim() || null,
  });
}
</script>

<template>
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="project-edit-title"
        @click.self="close"
      >
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
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <header class="crm-vx-modal__head">
            <h2 id="project-edit-title" class="crm-vx-modal__title">Edit Project</h2>
          </header>

          <div class="crm-vx-modal__body">
            <p v-if="errorMsg" class="small text-danger text-center mb-3">{{ errorMsg }}</p>
            <form class="text-start" @submit.prevent="submit">
              <label class="form-label" for="project-edit-name">Project Name</label>
              <input
                id="project-edit-name"
                v-model="nameDraft"
                type="text"
                class="form-control mb-3"
                :disabled="busy"
                autocomplete="off"
              />

              <label class="form-label" for="project-edit-desc">Description</label>
              <textarea
                id="project-edit-desc"
                v-model="descriptionDraft"
                class="form-control"
                rows="8"
                :disabled="busy"
              />
            </form>
          </div>

          <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="busy"
              @click="close"
            >
              Cancel
            </button>
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="busy || !String(nameDraft || '').trim()"
              @click="submit"
            >
              {{ busy ? "Saving…" : "Save Changes" }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.crm-vx-confirm-enter-active,
.crm-vx-confirm-leave-active {
  transition: opacity 0.2s ease;
}
.crm-vx-confirm-enter-from,
.crm-vx-confirm-leave-to {
  opacity: 0;
}
</style>
