<script setup>
import { computed, ref } from "vue";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  existingUrl: { type: String, default: "" },
  disabled: { type: Boolean, default: false },
  required: { type: Boolean, default: true },
});

const emit = defineEmits(["select"]);
const toast = useToast();

const inputRef = ref(null);
const previewUrl = ref("");
const fileName = ref("");

const shownUrl = computed(() => previewUrl.value || props.existingUrl || "");

function isImage(file) {
  const name = String(file?.name || "").toLowerCase();
  return /\.(jpe?g|png|gif|webp|avif)$/i.test(name) || String(file?.type || "").startsWith("image/");
}

function onFile(event) {
  const file = event?.target?.files?.[0] || null;
  if (!file) return;
  if (!isImage(file)) {
    toast.error("Upload a JPG, PNG, GIF, WEBP, or AVIF photo.");
    emit("select", null);
    if (inputRef.value) inputRef.value.value = "";
    return;
  }
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = URL.createObjectURL(file);
  fileName.value = file.name;
  emit("select", file);
}

function clearFile() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = "";
  fileName.value = "";
  if (inputRef.value) inputRef.value.value = "";
  emit("select", null);
}

function browse() {
  if (props.disabled) return;
  inputRef.value?.click();
}
</script>

<template>
  <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
    <h3 class="h6 fw-semibold mb-1">Process Photo</h3>
    <p class="small text-secondary mb-3">
      {{ required ? "Required before you can process this return." : "Photo taken when this return was processed." }}
    </p>
    <img v-if="shownUrl" :src="shownUrl" alt="Return photo" class="return-process-photo__img mb-3" />
    <div v-if="required" class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn" :disabled="disabled" @click="browse">
        {{ fileName ? "Change Photo" : "Upload Photo" }}
      </button>
      <button v-if="fileName" type="button" class="btn btn-link btn-sm text-danger px-0" :disabled="disabled" @click="clearFile">
        Remove
      </button>
    </div>
    <input
      ref="inputRef"
      type="file"
      class="d-none"
      accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.avif"
      :disabled="disabled"
      @change="onFile"
    />
  </div>
</template>

<style scoped>
.return-process-photo__img {
  display: block;
  width: 100%;
  max-height: 16rem;
  object-fit: contain;
  border: 1px solid #e5e7eb;
  border-radius: 0.65rem;
  background: #f8fafc;
}
</style>
