<script setup>
import { computed, ref } from "vue";
import AccountDetailSectionHead from "../clients/AccountDetailSectionHead.vue";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const props = defineProps({
  comments: { type: Array, default: () => [] },
  canUpdate: { type: Boolean, default: false },
  submitting: { type: Boolean, default: false },
  imagePreviewUrls: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["submit", "delete", "download"]);

const body = defineModel("body", { type: String, default: "" });
const file = defineModel("file", { type: [Object, null], default: null });

const fileInput = ref(null);
const error = ref("");

const ordered = computed(() => {
  const list = Array.isArray(props.comments) ? [...props.comments] : [];
  return list.sort((a, b) => Number(b.id) - Number(a.id));
});

function initials(name) {
  const parts = String(name || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

function avatarClass(label) {
  const s = String(label || "");
  let hash = 0;
  for (let i = 0; i < s.length; i += 1) {
    hash = (hash + s.charCodeAt(i) * (i + 1)) % 5;
  }
  const classes = [
    "bg-primary-subtle text-primary",
    "bg-success-subtle text-success",
    "bg-warning-subtle text-warning",
    "bg-info-subtle text-info",
    "bg-secondary-subtle text-secondary",
  ];
  return classes[hash] || classes[0];
}

function isImageMime(mime) {
  return String(mime || "").startsWith("image/");
}

function formatFileSize(size) {
  const n = Number(size);
  if (!Number.isFinite(n) || n <= 0) return "";
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function onFileChange(e) {
  file.value = e?.target?.files?.[0] || null;
}

function submit() {
  error.value = "";
  const text = String(body.value || "").trim();
  if (!text) {
    error.value = "Note text is required.";
    return;
  }
  emit("submit");
}

function clearFileInput() {
  if (fileInput.value) fileInput.value.value = "";
}

defineExpose({ clearFileInput });
</script>

<template>
  <div class="staff-surface p-3 p-md-4 mb-4">
    <AccountDetailSectionHead title="Notes" icon="notes" head-class="mb-3" />

    <ul v-if="ordered.length" class="list-unstyled mb-0">
      <li
        v-for="c in ordered"
        :key="c.id"
        class="d-flex gap-3 border-bottom pb-3 mb-3"
      >
        <img
          v-if="c.user?.avatar_url"
          :src="c.user.avatar_url"
          alt=""
          class="rounded-circle flex-shrink-0 object-fit-cover"
          width="36"
          height="36"
        />
        <span
          v-else
          class="rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
          style="width: 36px; height: 36px; font-size: 0.6875rem"
          :class="avatarClass(c.user?.name || c.user?.email)"
          aria-hidden="true"
        >{{ initials(c.user?.name) }}</span>
        <div class="min-w-0 flex-grow-1">
          <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2">
            <div class="fw-semibold small">{{ c.user?.name || "User" }}</div>
            <div class="d-flex align-items-center gap-2">
              <time class="small text-secondary" :datetime="c.created_at">
                {{ formatDateTimeUs(c.created_at) }}
              </time>
              <button
                v-if="canUpdate"
                type="button"
                class="btn btn-link btn-sm text-danger text-decoration-none p-0"
                @click="emit('delete', c)"
              >
                Delete
              </button>
            </div>
          </div>
          <p class="mt-1 mb-0 small text-body" style="white-space: pre-wrap">{{ c.body }}</p>
          <div v-if="c.attachment" class="mt-3">
            <img
              v-if="isImageMime(c.attachment.mime) && imagePreviewUrls[c.id]"
              :src="imagePreviewUrls[c.id]"
              alt=""
              class="img-fluid rounded border"
              style="max-height: 12rem"
            />
            <button
              type="button"
              class="btn btn-link btn-sm text-decoration-none p-0 mt-2 d-inline-flex align-items-center gap-1"
              @click="emit('download', c.id)"
            >
              <span>{{ c.attachment.original_name || "Download attachment" }}</span>
              <span v-if="formatFileSize(c.attachment.size)" class="text-secondary">
                ({{ formatFileSize(c.attachment.size) }})
              </span>
            </button>
          </div>
        </div>
      </li>
    </ul>
    <p v-else class="text-secondary small border-bottom pb-4 mb-0">No notes yet.</p>

    <div v-if="canUpdate" class="pt-4">
      <label class="form-label small text-secondary" for="lead-add-note">Add Note</label>
      <textarea
        id="lead-add-note"
        v-model="body"
        rows="3"
        class="form-control"
        placeholder="Write an update…"
      />
      <div class="mt-3 d-flex flex-wrap align-items-center gap-2">
        <input
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.txt,.doc,.docx"
          class="form-control form-control-sm flex-grow-1"
          style="min-width: 12rem; max-width: 100%"
          @change="onFileChange"
        />
        <button
          type="button"
          class="btn btn-primary staff-page-primary text-nowrap flex-shrink-0"
          :disabled="submitting"
          @click="submit"
        >
          {{ submitting ? "Adding…" : "Add Note" }}
        </button>
      </div>
      <p v-if="error" class="text-danger small mt-2 mb-0">{{ error }}</p>
      <p class="text-secondary small mt-2 mb-0">
        Optional attachment: image, PDF, or small document (max 5 MB).
      </p>
    </div>
  </div>
</template>
