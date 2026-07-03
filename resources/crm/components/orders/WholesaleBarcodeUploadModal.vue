<script setup>
import { ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  lineLabel: { type: String, default: "" },
});

const emit = defineEmits(["close", "upload"]);

const fileInput = ref(null);
const selectedFile = ref(null);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) {
      selectedFile.value = null;
      if (fileInput.value) fileInput.value.value = "";
    }
  },
);

function close() {
  if (props.busy) return;
  emit("close");
}

function onFileChange(event) {
  selectedFile.value = event.target.files?.[0] || null;
}

function submit() {
  if (!selectedFile.value) return;
  emit("upload", selectedFile.value);
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="wholesale-barcode-upload-title"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="close" />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal crm-vx-modal--sm">
            <header class="crm-vx-modal__head">
              <h2 id="wholesale-barcode-upload-title" class="crm-vx-modal__title mb-0">Upload Barcode</h2>
            </header>
            <div class="crm-vx-modal__body">
              <p v-if="lineLabel" class="small text-secondary mb-3">{{ lineLabel }}</p>
              <label class="form-label" for="wholesale-barcode-file">Barcode label</label>
              <input
                id="wholesale-barcode-file"
                ref="fileInput"
                type="file"
                class="form-control"
                accept="application/pdf,image/jpeg,image/png,image/gif,image/webp"
                :disabled="busy"
                @change="onFileChange"
              />
              <p class="form-text mb-0">Upload a PDF or image barcode label.</p>
            </div>
            <footer class="crm-vx-modal__footer d-flex flex-wrap gap-2 justify-content-end align-items-center">
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
                :disabled="busy || !selectedFile"
                @click="submit"
              >
                {{ busy ? "Uploading…" : "Upload" }}
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
