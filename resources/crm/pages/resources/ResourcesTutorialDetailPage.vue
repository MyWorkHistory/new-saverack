<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLinkedText from "../../components/common/CrmLinkedText.vue";
import CrmNoteAuthorAvatar from "../../components/common/CrmNoteAuthorAvatar.vue";
import PhotoLightboxModal from "../../components/resources/PhotoLightboxModal.vue";
import PhotoUploadModal from "../../components/resources/PhotoUploadModal.vue";
import TutorialModal from "../../components/resources/TutorialModal.vue";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates.js";
import { noteAuthorFromRecord } from "../../utils/noteAuthor.js";
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
const actionsMenuOpen = ref(false);
const actionsMenuRect = ref({ top: 0, left: 0 });
const sendingSlack = ref(false);
const ACTIONS_MENU_W = 180;
const ACTIONS_MENU_H = 132;

const canShowActionsMenu = computed(() => Boolean(tutorial.value) && (canUpdate.value || canDelete.value));

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdate = computed(
  () => userHasPerm("resources_tutorials.update") || userHasPerm("resources.update"),
);
const canDelete = computed(
  () => userHasPerm("resources_tutorials.delete") || userHasPerm("resources.delete"),
);
const canCreatePhoto = computed(
  () =>
    userHasPerm("resources_photos.create") ||
    userHasPerm("resources_tutorials.update") ||
    userHasPerm("resources.create"),
);
const canDeletePhoto = computed(
  () =>
    userHasPerm("resources_photos.delete") ||
    userHasPerm("resources_tutorials.update") ||
    userHasPerm("resources.delete"),
);

const photos = computed(() => {
  const p = tutorial.value?.photos;
  return Array.isArray(p) ? p : [];
});

const comments = computed(() => {
  const c = tutorial.value?.comments;
  return Array.isArray(c) ? c : [];
});

const descriptionLooksLikeHtml = computed(() => {
  const raw = String(tutorial.value?.description || "");
  return /<[a-z][\s\S]*>/i.test(raw);
});

const hasDescription = computed(() => {
  const raw = String(tutorial.value?.description || "").trim();
  if (!raw) return false;
  if (!descriptionLooksLikeHtml.value) return true;
  return Boolean(raw.replace(/<[^>]+>/g, "").replace(/&nbsp;/gi, " ").trim());
});

const commentsExpanded = ref(false);
const NOTES_PREVIEW_LIMIT = 3;

const visibleComments = computed(() => {
  const list = comments.value;
  if (commentsExpanded.value || list.length <= NOTES_PREVIEW_LIMIT) {
    return list;
  }
  return list.slice(0, NOTES_PREVIEW_LIMIT);
});

const showSeeAllNotes = computed(
  () => !commentsExpanded.value && comments.value.length > NOTES_PREVIEW_LIMIT,
);

function commentAuthor(comment) {
  return noteAuthorFromRecord(comment);
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

function placeActionsMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  let top = rect.bottom + 4;
  let left = rect.right - ACTIONS_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - ACTIONS_MENU_W - 8));
  if (top + ACTIONS_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, rect.top - ACTIONS_MENU_H - 4);
  }
  actionsMenuRect.value = { top, left };
}

async function toggleActionsMenu(event) {
  event?.stopPropagation?.();
  if (actionsMenuOpen.value) {
    actionsMenuOpen.value = false;
    return;
  }
  const btn = event?.currentTarget;
  actionsMenuOpen.value = true;
  await nextTick();
  requestAnimationFrame(() => {
    placeActionsMenu(btn);
  });
}

function closeActionsMenu() {
  actionsMenuOpen.value = false;
}

function openEditFromMenu() {
  closeActionsMenu();
  editorOpen.value = true;
}

function openDeleteFromMenu() {
  closeActionsMenu();
  deleteTutorialOpen.value = true;
}

async function sendToSlack() {
  if (!tutorial.value?.id || sendingSlack.value) return;
  closeActionsMenu();
  sendingSlack.value = true;
  try {
    await api.post(`/resources/tutorials/${tutorial.value.id}/send-slack`);
    toast.success("Tutorial sent to #faq.");
  } catch (e) {
    toast.errorFrom(e, "Could not send tutorial to Slack.");
  } finally {
    sendingSlack.value = false;
  }
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-tutorial-actions]")) {
    actionsMenuOpen.value = false;
  }
}

function onWindowScrollOrResize() {
  actionsMenuOpen.value = false;
}

function onDocKeydown(e) {
  if (e.key === "Escape") {
    actionsMenuOpen.value = false;
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
  document.addEventListener("click", onDocClick);
  document.addEventListener("keydown", onDocKeydown);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  document.removeEventListener("keydown", onDocKeydown);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
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
      <div v-if="canShowActionsMenu" class="staff-detail-tab-bar-actions" data-tutorial-actions>
          <button
            type="button"
            class="staff-detail-tab-btn"
            :class="{ 'staff-detail-tab-btn--active': actionsMenuOpen }"
            :aria-expanded="actionsMenuOpen"
            aria-label="Actions"
            @click="toggleActionsMenu"
          >
            <svg
              class="staff-detail-tab-btn__icon"
              width="26"
              height="26"
              fill="none"
              stroke="currentColor"
              stroke-width="1.75"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span class="staff-detail-tab-btn__label">Actions</span>
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
            <div
              v-if="hasDescription && descriptionLooksLikeHtml"
              class="tutorial-description-html"
              v-html="tutorial.description"
            />
            <CrmLinkedText
              v-else-if="hasDescription"
              :text="tutorial.description"
              class="whitespace-pre-wrap"
            />
            <p v-else class="text-secondary small mb-0">No description.</p>
          </section>
        </div>

        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom">
            <div class="d-flex align-items-center justify-content-between gap-2">
              <h3 class="h6 fw-semibold text-body mb-0">Activity</h3>
              <button
                v-if="showSeeAllNotes"
                type="button"
                class="btn btn-link btn-sm p-0 text-decoration-none"
                @click="commentsExpanded = true"
              >
                See All Notes
              </button>
            </div>
          </div>
          <div class="p-4 p-md-5">
            <ul v-if="comments.length" class="list-unstyled mb-0 pb-4 border-bottom">
              <li v-for="c in visibleComments" :key="c.id" class="d-flex gap-3 mb-4">
                <CrmNoteAuthorAvatar
                  :name="commentAuthor(c).name"
                  :email="commentAuthor(c).email"
                  :avatar-url="commentAuthor(c).avatarUrl"
                />
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

      <aside class="col-12 col-lg-4 d-flex flex-column gap-4">
        <div class="staff-table-card overflow-hidden p-4 p-md-5">
          <h3 class="small fw-semibold text-secondary text-uppercase mb-3">Details</h3>
          <div class="tutorial-detail-fields">
            <div class="tutorial-detail-field">
              <span class="tutorial-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 12V7a4 4 0 014-4z"
                  />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="tutorial-detail-field__label">Category</p>
                <p class="tutorial-detail-field__value">{{ tutorial.category_label || "—" }}</p>
              </div>
            </div>

            <div class="tutorial-detail-field">
              <span class="tutorial-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"
                  />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="tutorial-detail-field__label">Created Date</p>
                <p class="tutorial-detail-field__value">{{ formatDateUs(tutorial.created_at) || "—" }}</p>
              </div>
            </div>

            <div class="tutorial-detail-field">
              <span class="tutorial-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                  />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="tutorial-detail-field__label">Created By</p>
                <p class="tutorial-detail-field__value">{{ tutorial.creator?.name || "—" }}</p>
              </div>
            </div>
          </div>
        </div>

        <div class="staff-table-card overflow-hidden p-4 p-md-5">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h3 class="small fw-semibold text-secondary text-uppercase mb-0">Photos</h3>
            <button
              v-if="canCreatePhoto"
              type="button"
              class="btn btn-primary staff-page-primary btn-sm"
              @click="openPhotoUpload"
            >
              Add Photo
            </button>
          </div>
          <p v-if="!photos.length" class="text-secondary small mb-0">No photos yet.</p>
          <div v-else class="row g-2">
            <div
              v-for="photo in photos"
              :key="photo.id"
              class="col-6"
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
      </aside>
    </div>

    <Teleport to="body">
      <div
        v-if="actionsMenuOpen"
        data-tutorial-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${actionsMenuRect.top}px`,
          left: `${actionsMenuRect.left}px`,
          minWidth: `${ACTIONS_MENU_W}px`,
        }"
        @click.stop
      >
        <button
          v-if="canUpdate"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openEditFromMenu"
        >
          Edit
        </button>
        <button
          v-if="canUpdate"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          :disabled="sendingSlack"
          @click="sendToSlack"
        >
          {{ sendingSlack ? "Sending…" : "Send to Slack" }}
        </button>
        <button
          v-if="canDelete"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteFromMenu"
        >
          Delete
        </button>
      </div>
    </Teleport>

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
.tutorial-description-html {
  font-size: 0.925rem;
  line-height: 1.55;
  color: var(--bs-body-color);
}
.tutorial-description-html :deep(p) {
  margin: 0 0 0.65rem;
}
.tutorial-description-html :deep(ul),
.tutorial-description-html :deep(ol) {
  margin: 0 0 0.65rem;
  padding-left: 1.25rem;
}
.tutorial-description-html :deep(h2),
.tutorial-description-html :deep(h3),
.tutorial-description-html :deep(h4) {
  margin: 0.85rem 0 0.45rem;
  font-size: 1.05rem;
  line-height: 1.3;
}
.tutorial-description-html :deep(blockquote) {
  margin: 0 0 0.85rem;
  padding-left: 0.85rem;
  border-left: 3px solid rgba(47, 43, 61, 0.2);
  color: #555;
}
.tutorial-description-html :deep(a) {
  color: #696cff;
}
.tutorial-detail-fields {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.tutorial-detail-field {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  min-width: 0;
}
.tutorial-detail-field__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.75rem;
  background: #eef2ff;
  color: #2563eb;
  flex-shrink: 0;
}
.tutorial-detail-field__icon svg {
  width: 1.3rem;
  height: 1.3rem;
}
.tutorial-detail-field__label {
  margin: 0 0 0.15rem;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--bs-secondary-color);
}
.tutorial-detail-field__value {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  color: #1e293b;
  word-break: break-word;
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
