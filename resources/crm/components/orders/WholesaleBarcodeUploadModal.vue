<script setup>
import { computed, ref, watch } from "vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  lineLabel: { type: String, default: "" },
});

const emit = defineEmits(["close", "upload"]);

const fileInput = ref(null);
const selectedFile = ref(null);
const dragOver = ref(false);
const localError = ref("");

const selectedFileName = computed(() => selectedFile.value?.name || "");

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) {
      selectedFile.value = null;
      localError.value = "";
      dragOver.value = false;
      if (fileInput.value) fileInput.value.value = "";
    }
  },
);

function close() {
  if (props.busy) return;
  emit("close");
}

function isPdfFile(file) {
  if (!file) return false;
  const type = String(file.type || "").toLowerCase();
  const name = String(file.name || "").toLowerCase();
  return type === "application/pdf" || name.endsWith(".pdf");
}

function setFile(file) {
  localError.value = "";
  if (!file) {
    selectedFile.value = null;
    return;
  }
  if (!isPdfFile(file)) {
    selectedFile.value = null;
    localError.value = "Please choose a PDF file.";
    if (fileInput.value) fileInput.value.value = "";
    return;
  }
  selectedFile.value = file;
}

function onFileChange(event) {
  setFile(event.target.files?.[0] || null);
}

function onDragOver(event) {
  event.preventDefault();
  dragOver.value = true;
}

function onDragLeave() {
  dragOver.value = false;
}

function onDrop(event) {
  event.preventDefault();
  dragOver.value = false;
  const file = event.dataTransfer?.files?.[0] || null;
  setFile(file);
}

function chooseFile() {
  if (props.busy) return;
  fileInput.value?.click();
}

function submit() {
  if (!selectedFile.value || props.busy) return;
  if (!isPdfFile(selectedFile.value)) {
    localError.value = "Please choose a PDF file.";
    return;
  }
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
          <div class="crm-vx-modal wholesale-barcode-upload-modal">
            <header class="crm-vx-modal__head">
              <h2 id="wholesale-barcode-upload-title" class="crm-vx-modal__title mb-0">
                Upload Labels
              </h2>
              <button
                type="button"
                class="btn-close"
                aria-label="Close"
                :disabled="busy"
                @click="close"
              />
            </header>
            <div class="crm-vx-modal__body">
              <p v-if="lineLabel" class="small text-secondary mb-3">{{ lineLabel }}</p>

              <div class="wholesale-barcode-upload-modal__grid">
                <div class="wholesale-barcode-upload-modal__reqs">
                  <h3 class="wholesale-barcode-upload-modal__reqs-title">File Requirements</h3>
                  <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                    <li class="d-flex align-items-start gap-3">
                      <span class="wholesale-barcode-upload-modal__req-icon" aria-hidden="true">
                        <CrmMaterialIcon name="pictureAsPdf" :size="22" />
                      </span>
                      <div>
                        <div class="fw-semibold">File format:</div>
                        <div class="small text-secondary">PDF only</div>
                      </div>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                      <span class="wholesale-barcode-upload-modal__req-icon" aria-hidden="true">
                        <CrmMaterialIcon name="aspectRatio" :size="22" />
                      </span>
                      <div>
                        <div class="fw-semibold">Label size:</div>
                        <div class="small text-secondary">2&quot; x 1&quot; (5.08 cm x 2.54 cm)</div>
                      </div>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                      <span class="wholesale-barcode-upload-modal__req-icon" aria-hidden="true">
                        <CrmMaterialIcon name="filter1" :size="22" />
                      </span>
                      <div>
                        <div class="fw-semibold">Number of labels:</div>
                        <div class="small text-secondary">1 single label only</div>
                      </div>
                    </li>
                  </ul>
                </div>

                <div
                  class="wholesale-barcode-upload-modal__drop"
                  :class="{ 'is-dragover': dragOver }"
                  @dragover="onDragOver"
                  @dragleave="onDragLeave"
                  @drop="onDrop"
                >
                  <span class="wholesale-barcode-upload-modal__cloud" aria-hidden="true">
                    <CrmMaterialIcon name="cloudUpload" :size="40" />
                  </span>
                  <div class="fw-semibold mb-1">Drag &amp; drop your file here</div>
                  <div class="small text-secondary mb-3">or</div>
                  <button
                    type="button"
                    class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
                    :disabled="busy"
                    @click="chooseFile"
                  >
                    <CrmMaterialIcon name="upload" :size="18" />
                    Choose PDF File
                  </button>
                  <p class="small text-secondary mb-0 mt-3">
                    Your file must be a PDF with 1 label sized 2&quot; x 1&quot;.
                  </p>
                  <p v-if="selectedFileName" class="small fw-medium text-body mb-0 mt-2 text-truncate">
                    Selected: {{ selectedFileName }}
                  </p>
                  <p v-if="localError" class="small text-danger mb-0 mt-2">{{ localError }}</p>
                  <input
                    ref="fileInput"
                    type="file"
                    class="d-none"
                    accept="application/pdf,.pdf"
                    :disabled="busy"
                    @change="onFileChange"
                  />
                </div>
              </div>
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

<style scoped>
.wholesale-barcode-upload-modal {
  width: min(720px, calc(100vw - 2rem));
  max-width: 720px;
}

.wholesale-barcode-upload-modal__grid {
  display: grid;
  grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.15fr);
  gap: 1.25rem;
  align-items: stretch;
}

@media (max-width: 700px) {
  .wholesale-barcode-upload-modal__grid {
    grid-template-columns: 1fr;
  }
}

.wholesale-barcode-upload-modal__reqs-title {
  font-size: 1rem;
  font-weight: 700;
  margin-bottom: 1rem;
}

.wholesale-barcode-upload-modal__req-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.5rem;
  border: 1px solid #93c5fd;
  color: #2563eb;
  background: #eff6ff;
  flex-shrink: 0;
}

.wholesale-barcode-upload-modal__drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  min-height: 16rem;
  padding: 1.25rem;
  border: 2px dashed #93c5fd;
  border-radius: 0.75rem;
  background: #f8fbff;
}

.wholesale-barcode-upload-modal__drop.is-dragover {
  border-color: #2563eb;
  background: #eff6ff;
}

.wholesale-barcode-upload-modal__cloud {
  display: inline-flex;
  color: #60a5fa;
  margin-bottom: 0.75rem;
}
</style>
