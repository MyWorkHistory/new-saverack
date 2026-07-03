<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PhotoUploadModal from "../../components/resources/PhotoUploadModal.vue";
import PhotoLightboxModal from "../../components/resources/PhotoLightboxModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("resources.create"));
const canDelete = computed(() => userHasPerm("resources.delete"));

const loading = ref(true);
const photos = ref([]);
const thumbUrls = ref({});
const uploadOpen = ref(false);
const uploadBusy = ref(false);
const uploadName = ref("");
const uploadFile = ref(null);
const lightboxOpen = ref(false);
const lightboxName = ref("");
const lightboxUrl = ref("");
const deleteTarget = ref(null);
const deleteBusy = ref(false);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/resources/photos");
    photos.value = Array.isArray(data?.data) ? data.data : [];
    await loadThumbnails();
  } catch (e) {
    toast.errorFrom(e, "Could not load photos.");
    photos.value = [];
  } finally {
    loading.value = false;
  }
}

async function loadThumbnails() {
  const next = { ...thumbUrls.value };
  for (const photo of photos.value) {
    if (!photo?.id || next[photo.id]) continue;
    try {
      const res = await api.get(`/resources/photos/${photo.id}/file`, { responseType: "blob" });
      next[photo.id] = window.URL.createObjectURL(res.data);
    } catch {
      /* skip */
    }
  }
  thumbUrls.value = next;
}

function openUpload() {
  uploadName.value = "";
  uploadFile.value = null;
  uploadOpen.value = true;
}

async function submitUpload() {
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
    await api.post("/resources/photos", fd);
    uploadOpen.value = false;
    toast.success("Photo uploaded.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not upload photo.");
  } finally {
    uploadBusy.value = false;
  }
}

function openLightbox(photo) {
  const url = thumbUrls.value[photo.id];
  if (!url) return;
  lightboxName.value = photo.name || "";
  lightboxUrl.value = url;
  lightboxOpen.value = true;
}

function confirmDelete(photo) {
  deleteTarget.value = photo;
}

async function doDelete() {
  const photo = deleteTarget.value;
  if (!photo?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/resources/photos/${photo.id}`);
    if (thumbUrls.value[photo.id]) {
      window.URL.revokeObjectURL(thumbUrls.value[photo.id]);
      const next = { ...thumbUrls.value };
      delete next[photo.id];
      thumbUrls.value = next;
    }
    deleteTarget.value = null;
    toast.success("Photo deleted.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete photo.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Photos",
    description: "Reference photos for staff training.",
  });
  load();
});

onUnmounted(() => {
  for (const url of Object.values(thumbUrls.value)) {
    if (typeof url === "string") window.URL.revokeObjectURL(url);
  }
  if (lightboxUrl.value) window.URL.revokeObjectURL(lightboxUrl.value);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Photos</h1>
        <p class="staff-page__intro mb-0">Reference images for training and procedures.</p>
      </div>
      <button
        v-if="canCreate"
        type="button"
        class="btn btn-primary staff-page-primary"
        @click="openUpload"
      >
        Add Photo
      </button>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading photos…" />
    </div>

    <p v-else-if="!photos.length" class="text-secondary">No photos yet.</p>

    <div v-else class="row g-4 resources-photo-grid">
      <div v-for="photo in photos" :key="photo.id" class="col-6 col-sm-4 col-md-3 col-xl-2">
        <div class="resources-photo-card staff-table-card h-100 p-2 position-relative">
          <button
            v-if="canDelete"
            type="button"
            class="btn btn-sm btn-outline-danger resources-photo-card__delete"
            aria-label="Delete photo"
            @click.stop="confirmDelete(photo)"
          >
            ×
          </button>
          <button
            type="button"
            class="resources-photo-card__thumb-btn w-100 border-0 bg-transparent p-0"
            @click="openLightbox(photo)"
          >
            <img
              v-if="thumbUrls[photo.id]"
              :src="thumbUrls[photo.id]"
              :alt="photo.name"
              class="resources-photo-card__thumb rounded"
            />
            <div v-else class="resources-photo-card__placeholder rounded bg-light" />
          </button>
          <p class="small text-center mb-0 mt-2 fw-medium text-truncate" :title="photo.name">{{ photo.name }}</p>
        </div>
      </div>
    </div>

    <PhotoUploadModal
      v-model:open="uploadOpen"
      v-model:name="uploadName"
      v-model:file="uploadFile"
      :busy="uploadBusy"
      @close="uploadOpen = false"
      @submit="submitUpload"
    />

    <PhotoLightboxModal
      :open="lightboxOpen"
      :name="lightboxName"
      :image-url="lightboxUrl"
      @close="lightboxOpen = false"
    />

    <ConfirmModal
      :open="!!deleteTarget"
      title="Delete Photo"
      :message="deleteTarget ? `Delete “${deleteTarget.name}”?` : ''"
      confirm-label="Delete"
      confirm-variant="danger"
      :busy="deleteBusy"
      @cancel="deleteTarget = null"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.resources-photo-card__thumb-btn {
  cursor: pointer;
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

.resources-photo-card__delete {
  position: absolute;
  top: 0.35rem;
  right: 0.35rem;
  z-index: 2;
  line-height: 1;
  padding: 0.1rem 0.45rem;
}
</style>
