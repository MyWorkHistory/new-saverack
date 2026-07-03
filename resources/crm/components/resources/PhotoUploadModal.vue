<script setup>
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  name: { type: String, default: "" },
  file: { type: Object, default: null },
});

const emit = defineEmits(["close", "submit", "update:name", "update:file"]);

function onFileChange(event) {
  emit("update:file", event.target.files?.[0] || null);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Add Photo</h2>
        </header>
        <div class="crm-vx-modal__body">
          <label class="form-label small" for="photo-upload-name">Name</label>
          <input
            id="photo-upload-name"
            :value="name"
            type="text"
            class="form-control mb-3"
            maxlength="255"
            :disabled="busy"
            @input="emit('update:name', $event.target.value)"
          />
          <label class="form-label small" for="photo-upload-file">Photo</label>
          <input
            id="photo-upload-file"
            type="file"
            accept="image/jpeg,image/png,image/gif,image/webp"
            class="form-control"
            :disabled="busy"
            @change="onFileChange"
          />
          <p class="small text-secondary mt-2 mb-0">JPEG, PNG, GIF, or WebP. Max 5 MB.</p>
        </div>
        <footer class="crm-vx-modal__footer">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="emit('close')">
            Cancel
          </button>
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="emit('submit')">
            {{ busy ? "Uploading…" : "Upload Photo" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
