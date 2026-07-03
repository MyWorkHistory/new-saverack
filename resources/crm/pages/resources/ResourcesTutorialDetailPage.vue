<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLinkedText from "../../components/common/CrmLinkedText.vue";
import PhotoLightboxModal from "../../components/resources/PhotoLightboxModal.vue";
import PhotoUploadModal from "../../components/resources/PhotoUploadModal.vue";
import TutorialModal from "../../components/resources/TutorialModal.vue";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const props = defineProps({
  id: { type: String, required: true },
});

const crmUser = inject("crmUser", ref(null));
const router = useRouter();
const toast = useToast();
const loading = ref(true);
const errorMsg = ref("");
const tutorial = ref(null);
const categories = ref([]);
const commentBody = ref("");
const commentFile = ref(null);
const commentFileInput = ref(null);
const commentSubmitting = ref(false);
const commentError = ref("");
const editorOpen = ref(false);
const deleteTutorialOpen = ref(false);
const deleteTutorialBusy = ref(false);
const uploadOpen = ref(false);
const uploadBusy = ref(false);
const uploadName = ref("");
const uploadFile = ref(null);
const lightboxOpen = ref(false);
const lightboxName = ref("");
const lightboxUrl = ref("");
const deletePhotoTarget = ref(null);
const deletePhotoBusy = ref(false);
const thumbUrls = ref({});

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdate = computed(() => userHasPerm("resources.update"));
const canDelete = computed(() => userHasPerm("resources.delete"));
const canCreatePhoto = computed(() => userHasPerm("resources.create"));
const canDeletePhoto = computed(() => userHasPerm("resources.delete"));

const photos = computed(() => {
  const p = tutorial.value?.photos;
  return Array.isArray(p) ? p : [];
});

const comments = computed(() => {
  const c = tutorial.value?.comments;
  return Array.isArray(c) ? c : [];
});

const avatarPalettes = [
  "bg-sky-100 text-sky-800",
  "bg-violet-100 text-violet-800",
  "bg-amber-100 text-amber-900",
];

function avatarClassForUser(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

function isImageMime(mime) {
  return typeof mime === "string" && mime.startsWith("image/");
}

watch(
  () => tutorial.value?.title,
  (title) => {
    if (title) {
      setCrmPageMeta({
        title: `Save Rack | Tutorial: ${title}`,
        description: `Tutorial: ${title}.`,
      });
    }
  },
);

async function loadTutorial() {
  loading.value = true;
  errorMsg.value = "";
  tutorial.value = null;
  revokeThumbUrls();
  try {
    const { data } = await api.get(`/resources/tutorials/${encodeURIComponent(props.id)}`);
    tutorial.value = data;
    await loadThumbnails();
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) errorMsg.value = "You do not have access to this tutorial.";
    else if (st === 404) errorMsg.value = "Tutorial not found.";
    else errorMsg.value = "Could not load tutorial.";
  } finally {
    loading.value = false;
  }
}

function revokeThumbUrls() {
  for (const url of Object.values(thumbUrls.value)) {
    if (typeof url === "string") window.URL.revokeObjectURL(url);
  }
  thumbUrls.value = {};
}

async function loadThumbnails() {
  const next = { ...thumbUrls.value };
  for (const photo of photos.value) {
    if (!photo?.id || next[photo.id]) continue;
    try {
      const res = await api.get(
        `/resources/tutorials/${props.id}/photos/${photo.id}/file`,
        { responseType: "blob" },
      );
      next[photo.id] = window.URL.createObjectURL(res.data);
    } catch {
      /* skip */
    }
  }
  thumbUrls.value = next;
}

function openPhotoUpload() {
  uploadName.value = "";
  uploadFile.value = null;
  uploadOpen.value = true;
}

async function submitPhotoUpload() {
  const name = uploadName.value.trim();
  if (!name) {
    toast.error("Enter a name.");
    return;
  }
  if (!uploadFile.value) {
    toast.error("Select a photo.");
    return;
  }
  uploadBusy.value = true;
  try {
    const fd = new FormData();
    fd.append("name", name);
    fd.append("photo", uploadFile.value);
    await api.post(`/resources/tutorials/${props.id}/photos`, fd);
    uploadOpen.value = false;
    toast.success("Photo uploaded.");
    await loadTutorial();
  } catch (e) {
    toast.errorFrom(e, "Could not upload photo.");
  } finally {
    uploadBusy.value = false;
  }
}

function openPhotoLightbox(photo) {
  const url = thumbUrls.value[photo.id];
  if (!url) return;
  lightboxName.value = photo.name || "";
  lightboxUrl.value = url;
  lightboxOpen.value = true;
}

function confirmDeletePhoto(photo) {
  deletePhotoTarget.value = photo;
}

async function doDeletePhoto() {
  const photo = deletePhotoTarget.value;
  if (!photo?.id) return;
  deletePhotoBusy.value = true;
  try {
    await api.delete(`/resources/tutorials/${props.id}/photos/${photo.id}`);
    if (thumbUrls.value[photo.id]) {
      window.URL.revokeObjectURL(thumbUrls.value[photo.id]);
      const next = { ...thumbUrls.value };
      delete next[photo.id];
      thumbUrls.value = next;
    }
    deletePhotoTarget.value = null;
    toast.success("Photo deleted.");
    await loadTutorial();
  } catch (e) {
    toast.errorFrom(e, "Could not delete photo.");
  } finally {
    deletePhotoBusy.value = false;
  }
}

async function deleteTutorial() {
  deleteTutorialBusy.value = true;
  try {
    await api.delete(`/resources/tutorials/${props.id}`);
    toast.success("Tutorial deleted.");
    router.push({ name: "resources-tutorials" });
  } catch (e) {
    toast.errorFrom(e, "Could not delete tutorial.");
  } finally {
    deleteTutorialBusy.value = false;
    deleteTutorialOpen.value = false;
  }
}

async function loadMeta() {
  try {
    const { data } = await api.get("/resources/tutorials/meta");
    categories.value = data.categories || [];
  } catch {
    categories.value = [];
  }
}

async function submitComment() {
  const body = commentBody.value?.trim() || "";
  if (!body) {
    commentError.value = "Write a comment first.";
    return;
  }
  commentSubmitting.value = true;
  commentError.value = "";
  const fd = new FormData();
  fd.append("body", body);
  if (commentFile.value) fd.append("attachment", commentFile.value);
  try {
    const { data } = await api.post(`/resources/tutorials/${props.id}/comments`, fd);
    if (!Array.isArray(tutorial.value.comments)) tutorial.value.comments = [];
    tutorial.value.comments.push(data);
    commentBody.value = "";
    commentFile.value = null;
    if (commentFileInput.value) commentFileInput.value.value = "";
  } catch (e) {
    commentError.value = "Could not post comment.";
  } finally {
    commentSubmitting.value = false;
  }
}

async function downloadAttachment(commentId) {
  try {
    const res = await api.get(
      `/resources/tutorials/${props.id}/comments/${commentId}/attachment`,
      { responseType: "blob" },
    );
    const url = window.URL.createObjectURL(res.data);
    const a = document.createElement("a");
    a.href = url;
    a.download = "attachment";
    a.click();
    window.URL.revokeObjectURL(url);
  } catch {
    /* ignore */
  }
}

const imagePreviewUrls = ref({});

async function loadImagePreview(commentId) {
  const res = await api.get(
    `/resources/tutorials/${props.id}/comments/${commentId}/attachment`,
    { responseType: "blob" },
  );
  return window.URL.createObjectURL(res.data);
}

async function ensureImagePreview(comment) {
  if (!comment?.attachment || !isImageMime(comment.attachment.mime)) return;
  const id = comment.id;
  if (imagePreviewUrls.value[id]) return;
  try {
    imagePreviewUrls.value = { ...imagePreviewUrls.value, [id]: await loadImagePreview(id) };
  } catch {
    /* ignore */
  }
}

watch(
  () => tutorial.value?.photos,
  () => {
    void loadThumbnails();
  },
  { deep: true },
);

watch(
  () => tutorial.value?.comments,
  (list) => {
    if (!Array.isArray(list)) return;
    for (const c of list) {
      if (c.attachment && isImageMime(c.attachment.mime)) ensureImagePreview(c);
    }
  },
  { deep: true },
);

onMounted(() => {
  loadMeta();
  loadTutorial();
});

onUnmounted(() => {
  for (const url of Object.values(imagePreviewUrls.value)) {
    if (typeof url === "string") window.URL.revokeObjectURL(url);
  }
  revokeThumbUrls();
  if (lightboxUrl.value) window.URL.revokeObjectURL(lightboxUrl.value);
});
</script>

<template>
  <div class="staff-user-view staff-page--wide">
    <nav class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1" aria-label="Breadcrumb">
      <RouterLink to="/admin/home">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">Resources</span>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/admin/resources/tutorials">Tutorials</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary text-truncate" style="max-width: 14rem">{{ tutorial?.title || "Tutorial" }}</span>
    </nav>

    <div class="staff-user-view__title-row d-flex flex-wrap align-items-start justify-content-between gap-2 mb-4">
      <div class="min-w-0">
        <h1 class="staff-user-view__title">Tutorial</h1>
        <p class="text-secondary small mb-0">Training guide details and discussion</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <RouterLink to="/admin/resources/tutorials" class="btn btn-outline-secondary btn-sm">Back to list</RouterLink>
        <button
          v-if="tutorial && canUpdate"
          type="button"
          class="btn btn-primary staff-page-primary btn-sm"
          @click="editorOpen = true"
        >
          Edit
        </button>
        <button
          v-if="tutorial && canDelete"
          type="button"
          class="btn btn-outline-danger btn-sm"
          @click="deleteTutorialOpen = true"
        >
          Delete
        </button>
      </div>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading tutorial…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-danger small">{{ errorMsg }}</p>
      <RouterLink to="/admin/resources/tutorials" class="small">Back to tutorials</RouterLink>
    </template>

    <div v-else-if="tutorial" class="row g-4">
      <div class="col-12 col-lg-8 d-flex flex-column gap-4">
        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom">
            <h2 class="h5 fw-semibold text-body mb-0">{{ tutorial.title }}</h2>
          </div>
          <section class="p-4 p-md-5">
            <h3 class="small fw-semibold text-secondary text-uppercase mb-2">Description</h3>
            <CrmLinkedText :text="tutorial.description" class="whitespace-pre-wrap" />
          </section>
        </div>

        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h3 class="h6 fw-semibold text-body mb-0">Photos</h3>
            <button
              v-if="canCreatePhoto"
              type="button"
              class="btn btn-primary staff-page-primary btn-sm"
              @click="openPhotoUpload"
            >
              Add Photo
            </button>
          </div>
          <div class="p-4 p-md-5">
            <p v-if="!photos.length" class="text-secondary small mb-0">No photos yet.</p>
            <div v-else class="resources-photo-grid">
              <div class="row g-3 g-md-4">
                <div
                  v-for="photo in photos"
                  :key="photo.id"
                  class="col-6 col-sm-4 col-md-3"
                >
                  <div class="resources-photo-card h-100 position-relative">
                    <button
                      v-if="canDeletePhoto"
                      type="button"
                      class="btn btn-sm btn-outline-danger resources-photo-card__delete"
                      aria-label="Delete photo"
                      @click.stop="confirmDeletePhoto(photo)"
                    >
                      ×
                    </button>
                    <button
                      type="button"
                      class="resources-photo-card__thumb-btn w-100 border-0 bg-transparent p-0"
                      @click="openPhotoLightbox(photo)"
                    >
                      <img
                        v-if="thumbUrls[photo.id]"
                        :src="thumbUrls[photo.id]"
                        :alt="photo.name"
                        class="resources-photo-card__thumb rounded"
                      />
                      <div v-else class="resources-photo-card__placeholder rounded bg-light" />
                    </button>
                    <p class="small text-center mb-0 mt-2 fw-medium text-truncate px-1" :title="photo.name">
                      {{ photo.name }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom">
            <h3 class="h6 fw-semibold text-body mb-0">Activity</h3>
          </div>
          <div class="p-4 p-md-5">
            <ul v-if="comments.length" class="list-unstyled mb-0 pb-4 border-bottom">
              <li v-for="c in comments" :key="c.id" class="d-flex gap-3 mb-4">
                <span
                  class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0 small fw-semibold wm-task-detail-avatar"
                  :class="avatarClassForUser(c.user?.email)"
                >
                  {{ initials(c.user?.name) }}
                </span>
                <div class="min-w-0 flex-grow-1">
                  <div class="d-flex flex-wrap align-items-baseline gap-2">
                    <span class="small fw-medium text-body">{{ c.user?.name || "User" }}</span>
                    <span class="small text-secondary">{{ formatDateTimeUs(c.created_at) }}</span>
                  </div>
                  <CrmLinkedText :text="c.body" class="mt-1 whitespace-pre-wrap" />
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
                      class="btn btn-link btn-sm text-decoration-none p-0 mt-2"
                      @click="downloadAttachment(c.id)"
                    >
                      {{ c.attachment.original_name || "Download attachment" }}
                    </button>
                  </div>
                </div>
              </li>
            </ul>
            <p v-else class="text-secondary small border-bottom pb-4 mb-0">No comments yet.</p>

            <div class="pt-4">
              <label class="form-label small text-secondary" for="tutorial-comment">Add comment</label>
              <textarea
                id="tutorial-comment"
                v-model="commentBody"
                rows="3"
                class="form-control"
                placeholder="Write an update…"
              />
              <div class="mt-3 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2">
                <input
                  ref="commentFileInput"
                  type="file"
                  accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.txt,.doc,.docx"
                  class="form-control form-control-sm"
                  @change="commentFile = $event.target.files?.[0] || null"
                />
                <button
                  type="button"
                  class="btn btn-primary staff-page-primary"
                  :disabled="commentSubmitting"
                  @click="submitComment"
                >
                  {{ commentSubmitting ? "Posting…" : "Post Comment" }}
                </button>
              </div>
              <p v-if="commentError" class="text-danger small mt-2 mb-0">{{ commentError }}</p>
            </div>
          </div>
        </div>
      </div>

      <aside class="col-12 col-lg-4">
        <div class="staff-table-card overflow-hidden p-4 p-md-5">
          <h3 class="small fw-semibold text-secondary text-uppercase mb-3">Details</h3>
          <dl class="row small mb-0 gy-3">
            <div class="col-12">
              <dt class="text-secondary mb-1">Category</dt>
              <dd class="mb-0 text-body">{{ tutorial.category_label || "—" }}</dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Created Date</dt>
              <dd class="mb-0 text-body">{{ formatDateUs(tutorial.created_at) || "—" }}</dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Created By</dt>
              <dd class="mb-0 text-body">{{ tutorial.creator?.name || "—" }}</dd>
            </div>
          </dl>
        </div>
      </aside>
    </div>

    <TutorialModal
      v-if="tutorial"
      v-model:open="editorOpen"
      :tutorial="tutorial"
      :categories="categories"
      @saved="loadTutorial"
    />

    <PhotoUploadModal
      v-model:open="uploadOpen"
      v-model:name="uploadName"
      v-model:file="uploadFile"
      :busy="uploadBusy"
      @close="uploadOpen = false"
      @submit="submitPhotoUpload"
    />

    <PhotoLightboxModal
      :open="lightboxOpen"
      :name="lightboxName"
      :image-url="lightboxUrl"
      @close="lightboxOpen = false"
    />

    <ConfirmModal
      :open="!!deletePhotoTarget"
      title="Delete Photo"
      :message="deletePhotoTarget ? `Delete “${deletePhotoTarget.name}”?` : ''"
      confirm-label="Delete"
      confirm-variant="danger"
      :busy="deletePhotoBusy"
      @cancel="deletePhotoTarget = null"
      @confirm="doDeletePhoto"
    />

    <ConfirmModal
      :open="deleteTutorialOpen"
      title="Delete Tutorial"
      :message="tutorial ? `Delete “${tutorial.title}”?` : ''"
      confirm-label="Delete"
      confirm-variant="danger"
      :busy="deleteTutorialBusy"
      @cancel="deleteTutorialOpen = false"
      @confirm="deleteTutorial"
    />
  </div>
</template>

<style scoped>
.whitespace-pre-wrap {
  white-space: pre-wrap;
}
.wm-task-detail-avatar {
  width: 2.25rem;
  height: 2.25rem;
}
.resources-photo-card {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 0.5rem;
  padding: 0.5rem;
}
.resources-photo-card__delete {
  position: absolute;
  top: 0.35rem;
  right: 0.35rem;
  z-index: 2;
  line-height: 1;
  padding: 0.1rem 0.45rem;
}
.resources-photo-card__thumb {
  width: 100%;
  aspect-ratio: 1;
  object-fit: cover;
  display: block;
}
.resources-photo-card__placeholder {
  width: 100%;
  aspect-ratio: 1;
}
.resources-photo-card__thumb-btn {
  cursor: pointer;
}
</style>
