<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  returnId: { type: [Number, String], default: null },
  files: { type: Array, default: () => [] },
  editable: { type: Boolean, default: false },
});

const emit = defineEmits(["updated"]);

const toast = useToast();
const localFiles = ref([]);
const fileInput = ref(null);
const uploading = ref(false);
const uploadError = ref("");

watch(
  () => props.files,
  (value) => {
    localFiles.value = Array.isArray(value) ? [...value] : [];
  },
  { immediate: true, deep: true },
);

const canUpload = computed(() => props.editable && props.returnId);

async function onFilesSelected(event) {
  const files = Array.from(event?.target?.files || []);
  if (fileInput.value) fileInput.value.value = "";
  if (!files.length || !canUpload.value) return;

  uploadError.value = "";
  uploading.value = true;
  try {
    let latest = null;
    for (const file of files) {
      const fd = new FormData();
      fd.append("file", file);
      const { data } = await api.post(`/admin/returns/${props.returnId}/attachments`, fd);
      latest = data;
      localFiles.value = Array.isArray(data?.files) ? [...data.files] : localFiles.value;
    }
    toast.success(files.length === 1 ? "File uploaded." : "Files uploaded.");
    if (latest) emit("updated", latest);
  } catch (err) {
    uploadError.value = err?.response?.data?.message || "Could not upload file.";
    toast.errorFrom(err, "Could not upload file.");
  } finally {
    uploading.value = false;
  }
}

async function viewFile(file) {
  if (file?.url) {
    window.open(file.url, "_blank", "noopener,noreferrer");
  }
}

async function deleteFile(file) {
  if (!file?.id || !canUpload.value) return;
  try {
    const { data } = await api.delete(`/admin/returns/${props.returnId}/attachments/${file.id}`);
    localFiles.value = Array.isArray(data?.files) ? [...data.files] : localFiles.value.filter((f) => f.id !== file.id);
    toast.success("File deleted.");
    emit("updated", data);
  } catch (err) {
    toast.errorFrom(err, "Could not delete file.");
  }
}
</script>

<template>
  <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="return-files-card__icon" aria-hidden="true">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
          />
        </svg>
      </span>
      <div class="min-w-0">
        <h3 class="h6 fw-semibold mb-0">Files</h3>
        <p class="small text-secondary mb-0">Staff-only image uploads</p>
      </div>
    </div>

    <ul v-if="localFiles.length" class="list-unstyled mb-0 pb-3 border-bottom">
      <li
        v-for="file in localFiles"
        :key="file.id"
        class="d-flex align-items-center gap-2 mb-2"
      >
        <button
          type="button"
          class="btn btn-link btn-sm text-decoration-none p-0 text-start flex-grow-1 min-w-0"
          @click="viewFile(file)"
        >
          <span class="text-truncate d-inline-block mw-100">{{ file.original_name }}</span>
        </button>
        <button
          v-if="canUpload"
          type="button"
          class="btn btn-link btn-sm text-danger text-decoration-none p-0 flex-shrink-0"
          @click="deleteFile(file)"
        >
          Delete
        </button>
      </li>
    </ul>
    <p v-else class="text-secondary small border-bottom pb-3 mb-0">No files yet.</p>

    <div v-if="canUpload" class="pt-3">
      <label class="form-label small text-secondary" for="return-upload-files">Upload Images</label>
      <input
        id="return-upload-files"
        ref="fileInput"
        type="file"
        accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.avif"
        class="form-control form-control-sm"
        multiple
        :disabled="uploading"
        @change="onFilesSelected"
      />
      <p v-if="uploading" class="small text-secondary mt-2 mb-0">Uploading…</p>
      <p v-if="uploadError" class="text-danger small mt-2 mb-0">{{ uploadError }}</p>
      <p class="text-secondary small mt-2 mb-0">JPEG, PNG, GIF, WEBP, or AVIF (max 10 MB each).</p>
    </div>
  </div>
</template>

<style scoped>
.return-files-card__icon {
  width: 2rem;
  height: 2rem;
  border-radius: 0.55rem;
  background: #eff6ff;
  color: #2563eb;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
</style>
