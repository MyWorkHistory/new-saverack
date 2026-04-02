<script setup>
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  ref,
  watch,
} from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";
import CrmOutlinePillLink from "../../components/common/CrmOutlinePillLink.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmOutlineEditButton from "../../components/common/CrmOutlineEditButton.vue";
import WebmasterTaskDrawer from "../../components/webmaster/WebmasterTaskDrawer.vue";
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
        description: `Webmaster task: ${title}.`,
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
      errorMsg.value = "You don't have access to this task.";
    } else if (st === 404) {
      errorMsg.value = "Task not found.";
    } else {
      errorMsg.value = "Could not load task.";
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
    commentError.value = errorMessage(e, "Could not post comment.");
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
  <div class="w-full space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <PageHeader
        title="Task"
        subtitle="Details and activity"
      />
      <div class="flex shrink-0 flex-wrap items-center gap-2">
        <CrmOutlinePillLink to="/webmaster" label="Back to board" />
        <CrmOutlineEditButton
          v-if="task && canMutateWebmasterTasks"
          @click="taskEditorOpen = true"
        />
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <CrmLoadingSpinner message="Loading task…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ errorMsg }}
      </p>
      <div class="mt-2">
        <CrmOutlinePillLink to="/webmaster" label="Back to board" />
      </div>
    </template>

    <div
      v-else-if="task"
      class="grid gap-6 lg:grid-cols-3"
    >
      <div class="space-y-6 lg:col-span-2">
        <div
          class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
        >
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-4 dark:border-gray-800">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
              {{ task.title }}
            </h2>
            <div class="flex flex-wrap items-center gap-2">
              <span
                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ring-gray-200 dark:ring-gray-600"
                :class="priorityClass(task.priority)"
              >
                {{ task.priority }}
              </span>
              <span
                class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium capitalize text-gray-800 dark:bg-gray-800 dark:text-gray-200"
              >
                {{ formatStatus(task.status) }}
              </span>
            </div>
          </div>

          <section class="mt-6">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
              Description
            </h3>
            <p
              class="whitespace-pre-wrap text-sm leading-relaxed text-gray-800 dark:text-gray-200"
            >
              {{ display(task.description) }}
            </p>
          </section>
        </div>

        <div
          class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
        >
          <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">
            Activity
          </h3>

          <ul
            v-if="comments.length"
            class="space-y-6 border-b border-gray-100 pb-6 dark:border-gray-800"
          >
            <li
              v-for="c in comments"
              :key="c.id"
              class="flex gap-3"
            >
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                :class="avatarClassForUser(c.user?.email)"
              >
                {{ initials(c.user?.name) }}
              </span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                  <span class="text-sm font-medium text-gray-900 dark:text-white">{{
                    c.user?.name || "User"
                  }}</span>
                  <span class="text-xs text-gray-500 dark:text-gray-400">{{
                    formatDateTimeUs(c.created_at)
                  }}</span>
                </div>
                <p
                  class="mt-1 whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200"
                >
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
                    class="max-h-48 max-w-full rounded-lg border border-gray-200 object-contain dark:border-gray-700"
                  />
                  <button
                    type="button"
                    class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-[#2563eb] hover:underline"
                    @click="downloadAttachment(c.id)"
                  >
                    <span v-if="c.attachment.original_name">{{
                      c.attachment.original_name
                    }}</span>
                    <span v-else>Download attachment</span>
                    <span
                      v-if="formatFileSize(c.attachment.size)"
                      class="text-gray-500 dark:text-gray-400"
                    >({{ formatFileSize(c.attachment.size) }})</span>
                  </button>
                </div>
              </div>
            </li>
          </ul>
          <p
            v-else
            class="border-b border-gray-100 pb-6 text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400"
          >
            No comments yet.
          </p>

          <div class="pt-6">
            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
              Add comment
            </label>
            <textarea
              v-model="commentBody"
              rows="3"
              class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
              placeholder="Write an update…"
            />
            <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <input
                ref="commentFileInput"
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.txt,.doc,.docx"
                class="block w-full text-xs text-gray-600 file:mr-2 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-gray-800 hover:file:bg-gray-200 dark:text-gray-400 dark:file:bg-gray-800 dark:file:text-gray-200"
                @change="commentFile = $event.target.files?.[0] || null"
              />
              <button
                type="button"
                class="mt-2 inline-flex shrink-0 justify-center rounded-xl bg-[#2563eb] px-4 py-2 text-sm font-semibold text-white hover:opacity-95 disabled:opacity-50 sm:mt-0"
                :disabled="commentSubmitting"
                @click="submitComment"
              >
                {{ commentSubmitting ? "Posting…" : "Post comment" }}
              </button>
            </div>
            <p
              v-if="commentError"
              class="mt-2 text-xs text-red-600 dark:text-red-400"
            >
              {{ commentError }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Optional attachment: image, PDF, or small document (max 5 MB).
            </p>
          </div>
        </div>
      </div>

      <aside
        class="space-y-4 lg:col-span-1"
      >
        <div
          class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
        >
          <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Details
          </h3>
          <dl class="space-y-3 text-sm">
            <div>
              <dt class="text-xs text-gray-500 dark:text-gray-400">
                Ticket price
              </dt>
              <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">
                {{ formatUsdPriceOrDash(task.price) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-gray-400">
                Due date
              </dt>
              <dd class="mt-0.5 text-gray-900 dark:text-white">
                {{ formatDateUs(task.due_date) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-gray-400">
                Created
              </dt>
              <dd class="mt-0.5 text-gray-900 dark:text-white">
                {{ formatDateTimeUs(task.created_at) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-gray-400">
                Updated
              </dt>
              <dd class="mt-0.5 text-gray-900 dark:text-white">
                {{ formatDateTimeUs(task.updated_at) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-gray-400">
                Created by
              </dt>
              <dd class="mt-0.5 text-gray-900 dark:text-white">
                <template v-if="task.creator">
                  {{ task.creator.name }}
                </template>
                <template v-else>—</template>
              </dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-gray-400">
                Assigned to
              </dt>
              <dd class="mt-0.5 text-gray-900 dark:text-white">
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

    <WebmasterTaskDrawer
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
