<script setup>
import { ref, watch } from "vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  selectedIds: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open"]);

const toast = useToast();
const file = ref(null);
const fileInput = ref(null);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    file.value = null;
    if (fileInput.value) fileInput.value.value = "";
  },
);

function close() {
  emit("update:open", false);
}

function onFileChange(e) {
  const f = e?.target?.files?.[0] || null;
  file.value = f;
}

function submit() {
  if (!file.value) {
    toast.error("Choose a CSV file for bulk edit.");
    return;
  }
  toast.warning("Bulk edit CSV format will be available soon. No products were updated.");
  close();
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="sip-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="sip-bulk-title"
      @click.self="close"
    >
      <div class="sip-modal" @click.stop>
        <header class="sip-modal__head">
          <h2 id="sip-bulk-title" class="sip-modal__title">Bulk Edit</h2>
          <button type="button" class="sip-modal__close" aria-label="Close" @click="close">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>

        <div class="sip-modal__body">
          <p class="sip-modal__lead">
            Upload a CSV to edit product info. Changes will update Shopify once the format is configured.
          </p>
          <p v-if="selectedIds.length" class="small text-secondary mb-3">
            {{ selectedIds.length }} product{{ selectedIds.length === 1 ? "" : "s" }} selected on the list (CSV mapping coming soon).
          </p>
          <label class="form-label" for="sip-bulk-file">CSV File</label>
          <input
            id="sip-bulk-file"
            ref="fileInput"
            type="file"
            class="form-control"
            accept=".csv,text/csv"
            @change="onFileChange"
          />
          <p v-if="file" class="small text-secondary mt-2 mb-0">
            Selected: {{ file.name }}
          </p>
        </div>

        <footer class="sip-modal__foot">
          <button type="button" class="btn btn-outline-primary" @click="close">
            Cancel
          </button>
          <button type="button" class="btn btn-primary staff-page-primary fw-semibold" @click="submit">
            Apply Bulk Edit
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.sip-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(2px);
}
.sip-modal {
  width: 100%;
  max-width: 28rem;
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 0.75rem;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
  overflow: hidden;
}
.sip-modal__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #e5e7eb;
}
.sip-modal__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 700;
  color: #111827;
}
.sip-modal__close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border: 0;
  border-radius: 0.4rem;
  background: transparent;
  color: #6b7280;
}
.sip-modal__close:hover {
  background: #f3f4f6;
  color: #111827;
}
.sip-modal__body {
  padding: 1.1rem 1.25rem;
}
.sip-modal__lead {
  margin: 0 0 1rem;
  font-size: 0.9rem;
  color: #6b7280;
}
.sip-modal__foot {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.55rem;
  padding: 0.95rem 1.25rem;
  border-top: 1px solid #e5e7eb;
  background: #fafafa;
}
</style>
