<script setup>
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  ref,
  watch,
} from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import WebmasterTaskModal from "../../components/webmaster/WebmasterTaskModal.vue";
import { crmIsAdmin } from "../../utils/crmUser";
import { errorMessage } from "../../utils/apiError";
import { formatUsdPriceOrDash } from "../../utils/formatPrice";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const props = defineProps({
  id: { type: String, required: true },
});

const crmUser = inject("crmUser", ref(null));
const router = useRouter();
const loading = ref(true);
const errorMsg = ref("");
const task = ref(null);

const commentBody = ref("");
const commentFile = ref(null);
const commentFileInput = ref(null);
const commentSubmitting = ref(false);
const commentError = ref("");
const taskEditorOpen = ref(false);

const canMutateWebmasterTasks = computed(() => {
  const u = crmUser?.value;
  if (!u) return false;
  return !!u.is_crm_owner || crmIsAdmin(u);
});

const comments = computed(() => {
  const c = task.value?.comments;
  return Array.isArray(c) ? c : [];
});

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

function formatStatus(raw) {
  if (raw == null || raw === "") return "—";
  return String(raw).replace(/_/g, " ");
}

function priorityClass(p) {
  const x = String(p || "").toLowerCase();
  const map = {
    low: "bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200",
    medium:
      "bg-blue-50 text-blue-800 dark:bg-blue-500/10 dark:text-blue-200",
    high: "bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200",
    urgent: "bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-200",
  };
  return (
    map[x] ||
    "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
  );
}

const avatarPalettes = [
  "bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200",
  "bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200",
  "bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200",
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

function formatFileSize(n) {
  if (n == null || n === "") return "";
  const x = Number(n);
  if (Number.isNaN(x) || x <= 0) return "";
  if (x < 1024) return `${x} B`;
  if (x < 1024 * 1024) return `${(x / 1024).toFixed(1)} KB`;
  return `${(x / (1024 * 1024)).toFixed(1)} MB`;
}

function isImageMime(mime) {
  return typeof mime === "string" && mime.startsWith("image/");
}

watch(
  () => task.value?.title,
  (title) => {
    if (title && typeof title === "string") {
      setCrmPageMeta({
        title: `Save Rack | Webmaster: ${title}`,
        description: `Webmaster Task: ${title}.`,
      });
    }
  },
);

async function loadTask() {
  const id = props.id;
  loading.value = true;
  errorMsg.value = "";
  task.value = null;
  try {
    const { data } = await api.get("/webmaster/tasks/" + encodeURIComponent(id));
    task.value = data;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You Don't Have Access To This Task.";
    } else if (st === 404) {
      errorMsg.value = "Task Not Found.";
    } else {
      errorMsg.value = "Could Not Load Task.";
    }
  } finally {
    loading.value = false;
  }
}

async function submitComment() {
  const id = props.id;
  const body = commentBody.value?.trim() || "";
  if (!body) {
    commentError.value = "Write a comment first.";
    return;
  }
  commentSubmitting.value = true;
  commentError.value = "";
  const fd = new FormData();
  fd.append("body", body);
  const f = commentFile.value;
  if (f) fd.append("attachment", f);
  try {
    const { data } = await api.post(
      "/webmaster/tasks/" + encodeURIComponent(id) + "/comments",
      fd,
      {
        headers: { "Content-Type": undefined },
      },
    );
    if (task.value) {
      const list = Array.isArray(task.value.comments) ? [...task.value.comments] : [];
      list.push(data);
      task.value = { ...task.value, comments: list };
    }
    commentBody.value = "";
    commentFile.value = null;
    if (commentFileInput.value) commentFileInput.value.value = "";
  } catch (e) {
    commentError.value = errorMessage(e, "Could Not Post Comment.");
  } finally {
    commentSubmitting.value = false;
  }
}

async function downloadAttachment(commentId) {
  const id = props.id;
  try {
    const res = await api.get(
      "/webmaster/tasks/" +
        encodeURIComponent(id) +
        "/comments/" +
        encodeURIComponent(commentId) +
        "/attachment",
      { responseType: "blob" },
    );
    const cd = res.headers?.["content-disposition"];
    let name = "download";
    if (cd && typeof cd === "string") {
      const m = /filename\*?=(?:UTF-8'')?["']?([^"'\s;]+)/i.exec(cd);
      if (m?.[1]) name = decodeURIComponent(m[1].replace(/["']/g, ""));
    }
    const c = comments.value.find((x) => x.id === commentId);
    if (c?.attachment?.original_name) name = c.attachment.original_name;
    const url = window.URL.createObjectURL(res.data);
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    a.click();
    window.URL.revokeObjectURL(url);
  } catch {
    /* toast optional */
  }
}

async function loadImagePreview(commentId) {
  const id = props.id;
  const res = await api.get(
    "/webmaster/tasks/" +
      encodeURIComponent(id) +
      "/comments/" +
      encodeURIComponent(commentId) +
      "/attachment",
    { responseType: "blob" },
  );
  return window.URL.createObjectURL(res.data);
}

const imagePreviewUrls = ref({});

async function ensureImagePreview(comment) {
  if (!comment?.attachment || !isImageMime(comment.attachment.mime)) return;
  const id = comment.id;
  if (imagePreviewUrls.value[id]) return;
  try {
    imagePreviewUrls.value = {
      ...imagePreviewUrls.value,
      [id]: await loadImagePreview(id),
    };
  } catch {
    /* ignore */
  }
}

watch(
  () => task.value?.comments,
  (list) => {
    if (!Array.isArray(list)) return;
    for (const c of list) {
      if (c.attachment && isImageMime(c.attachment.mime)) {
        ensureImagePreview(c);
      }
    }
  },
  { deep: true },
);

onMounted(loadTask);

onUnmounted(() => {
  for (const url of Object.values(imagePreviewUrls.value)) {
    if (typeof url === "string") window.URL.revokeObjectURL(url);
  }
});
</script>

<template>
  <div class="staff-user-view staff-page--wide">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/dashboard">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/webmaster">Webmaster</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">Task</span>
    </nav>

    <div
      class="staff-user-view__title-row d-flex flex-wrap align-items-start justify-content-between gap-2 mb-4"
    >
      <div class="min-w-0">
        <h1 class="staff-user-view__title">Task</h1>
        <p class="text-secondary small mb-0">Details and activity</p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
        <RouterLink
          to="/webmaster"
          class="btn btn-outline-secondary btn-sm"
        >
          Back to board
        </RouterLink>
        <button
          v-if="task && canMutateWebmasterTasks"
          type="button"
          class="btn btn-primary staff-page-primary btn-sm"
          @click="taskEditorOpen = true"
        >
          Edit
        </button>
      </div>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading task…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-danger small mb-2">
        {{ errorMsg }}
      </p>
      <RouterLink to="/webmaster" class="small">Back to board</RouterLink>
    </template>

    <div v-else-if="task" class="row g-4">
      <div class="col-12 col-lg-8 d-flex flex-column gap-4">
        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom">
            <div
              class="d-flex flex-wrap align-items-start justify-content-between gap-3"
            >
              <h2 class="h5 fw-semibold text-body mb-0">
                {{ task.title }}
              </h2>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span
                  class="rounded-pill px-2 py-1 small fw-medium text-capitalize"
                  :class="priorityClass(task.priority)"
                >
                  {{ task.priority }}
                </span>
                <span class="badge text-bg-secondary text-capitalize">
                  {{ formatStatus(task.status) }}
                </span>
              </div>
            </div>
          </div>
          <section class="p-4 p-md-5">
            <h3 class="small fw-semibold text-secondary text-uppercase mb-2">
              Description
            </h3>
            <p class="mb-0 text-body small whitespace-pre-wrap lh-lg">
              {{ display(task.description) }}
            </p>
          </section>
        </div>

        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom">
            <h3 class="h6 fw-semibold text-body mb-0">Activity</h3>
          </div>
          <div class="p-4 p-md-5">
            <ul
              v-if="comments.length"
              class="list-unstyled mb-0 pb-4 border-bottom"
            >
              <li
                v-for="c in comments"
                :key="c.id"
                class="d-flex gap-3 mb-4"
              >
                <span
                  class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0 small fw-semibold wm-task-detail-avatar"
                  :class="avatarClassForUser(c.user?.email)"
                >
                  {{ initials(c.user?.name) }}
                </span>
                <div class="min-w-0 flex-grow-1">
                  <div class="d-flex flex-wrap align-items-baseline gap-2">
                    <span class="small fw-medium text-body">{{
                      c.user?.name || "User"
                    }}</span>
                    <span class="small text-secondary">{{
                      formatDateTimeUs(c.created_at)
                    }}</span>
                  </div>
                  <p class="mt-1 mb-0 small text-body whitespace-pre-wrap">
                    {{ c.body }}
                  </p>
                  <div
                    v-if="c.attachment"
                    class="mt-3"
                  >
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
                      @click="downloadAttachment(c.id)"
                    >
                      <span v-if="c.attachment.original_name">{{
                        c.attachment.original_name
                      }}</span>
                      <span v-else>Download attachment</span>
                      <span
                        v-if="formatFileSize(c.attachment.size)"
                        class="text-secondary"
                      >({{ formatFileSize(c.attachment.size) }})</span>
                    </button>
                  </div>
                </div>
              </li>
            </ul>
            <p
              v-else
              class="text-secondary small border-bottom pb-4 mb-0"
            >
              No comments yet.
            </p>

            <div class="pt-4">
              <label class="form-label small text-secondary" for="wm-task-comment">
                Add comment
              </label>
              <textarea
                id="wm-task-comment"
                v-model="commentBody"
                rows="3"
                class="form-control"
                placeholder="Write an update…"
              />
              <div
                class="mt-3 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2"
              >
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
                  {{ commentSubmitting ? "Posting…" : "Post comment" }}
                </button>
              </div>
              <p
                v-if="commentError"
                class="text-danger small mt-2 mb-0"
              >
                {{ commentError }}
              </p>
              <p class="text-secondary small mt-2 mb-0">
                Optional attachment: image, PDF, or small document (max 5 MB).
              </p>
            </div>
          </div>
        </div>
      </div>

      <aside class="col-12 col-lg-4">
        <div class="staff-table-card overflow-hidden p-4 p-md-5">
          <h3 class="small fw-semibold text-secondary text-uppercase mb-3">
            Details
          </h3>
          <dl class="row small mb-0 gy-3">
            <div class="col-12">
              <dt class="text-secondary mb-1">Ticket price</dt>
              <dd class="mb-0 fw-medium text-body">
                {{ formatUsdPriceOrDash(task.price) }}
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Due date</dt>
              <dd class="mb-0 text-body">
                {{ formatDateUs(task.due_date) }}
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Created</dt>
              <dd class="mb-0 text-body">
                {{ formatDateTimeUs(task.created_at) }}
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Updated</dt>
              <dd class="mb-0 text-body">
                {{ formatDateTimeUs(task.updated_at) }}
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Created by</dt>
              <dd class="mb-0 text-body">
                <template v-if="task.creator">
                  {{ task.creator.name }}
                </template>
                <template v-else>—</template>
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Assigned to</dt>
              <dd class="mb-0 text-body">
                <template v-if="task.assignee">
                  {{ task.assignee.name }}
                </template>
                <template v-else>Unassigned</template>
              </dd>
            </div>
          </dl>
        </div>
      </aside>
    </div>

    <WebmasterTaskModal
      v-if="task"
      v-model:open="taskEditorOpen"
      :task="task"
      :users="[]"
      :statuses="[]"
      :priorities="[]"
      @saved="loadTask"
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
  font-size: 0.6875rem;
}
</style>
