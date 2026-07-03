<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmLinkedText from "../../components/common/CrmLinkedText.vue";
import TutorialModal from "../../components/resources/TutorialModal.vue";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const props = defineProps({
  id: { type: String, required: true },
});

const crmUser = inject("crmUser", ref(null));
const router = useRouter();
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

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdate = computed(() => userHasPerm("resources.update"));

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
  try {
    const { data } = await api.get(`/resources/tutorials/${encodeURIComponent(props.id)}`);
    tutorial.value = data;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) errorMsg.value = "You do not have access to this tutorial.";
    else if (st === 404) errorMsg.value = "Tutorial not found.";
    else errorMsg.value = "Could not load tutorial.";
  } finally {
    loading.value = false;
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
});
</script>

<template>
  <div class="staff-user-view staff-page--wide">
    <nav class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1" aria-label="Breadcrumb">
      <RouterLink to="/admin/home">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/admin/resources/tutorials">Tutorials</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">Tutorial</span>
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
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
              <h2 class="h5 fw-semibold text-body mb-0">{{ tutorial.title }}</h2>
              <span class="badge text-bg-secondary">{{ tutorial.category_label || "—" }}</span>
            </div>
          </div>
          <section class="p-4 p-md-5">
            <h3 class="small fw-semibold text-secondary text-uppercase mb-2">Description</h3>
            <CrmLinkedText :text="tutorial.description" class="whitespace-pre-wrap" />
          </section>
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
</style>
