<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
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
const search = ref("");
const searchDebounced = ref("");
const uploadOpen = ref(false);
const uploadBusy = ref(false);
const uploadName = ref("");
const uploadFile = ref(null);
const lightboxOpen = ref(false);
const lightboxName = ref("");
const lightboxUrl = ref("");
const deleteTarget = ref(null);
const deleteBusy = ref(false);

let searchTimer = null;

const filteredPhotos = computed(() => {
  const q = searchDebounced.value.trim().toLowerCase();
  if (!q) return photos.value;
  return photos.value.filter((p) => String(p.name || "").toLowerCase().includes(q));
});

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = String(v).trim();
  }, 300);
});

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
  clearTimeout(searchTimer);
  for (const url of Object.values(thumbUrls.value)) {
    if (typeof url === "string") window.URL.revokeObjectURL(url);
  }
  if (lightboxUrl.value) window.URL.revokeObjectURL(lightboxUrl.value);
});
</script>

<template>
  <div class="staff-page staff-page--wide resources-page">
    <div
      class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1 text-center text-md-start w-100">
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Photos</h1>
        <p class="staff-page__intro mb-0">Reference images for training and procedures.</p>
      </div>
      <div
        class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-end gap-2 flex-shrink-0"
      >
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="openUpload"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Photo
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
          :disabled="loading"
          title="Refresh"
          aria-label="Refresh gallery"
          @click="load"
        >
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
          Refresh
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            id="resources-photos-search"
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search photos by name"
            autocomplete="off"
          />
        </div>
      </div>

      <div v-if="loading" class="d-flex justify-content-center py-5">
        <CrmLoadingSpinner message="Loading photos…" />
      </div>

      <p v-else-if="!photos.length" class="text-secondary text-center px-4 py-5 mb-0">No photos yet.</p>

      <p v-else-if="!filteredPhotos.length" class="text-secondary text-center px-4 py-5 mb-0">
        No photos match your search.
      </p>

      <div v-else class="resources-photo-grid p-3 p-md-4">
        <div class="row g-3 g-md-4">
          <div
            v-for="photo in filteredPhotos"
            :key="photo.id"
            class="col-6 col-sm-4 col-md-3 col-xl-2"
          >
            <div class="resources-photo-card h-100 position-relative">
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
              <p class="small text-center mb-0 mt-2 fw-medium text-truncate px-1" :title="photo.name">
                {{ photo.name }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <div
        v-if="!loading && photos.length"
        class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-top staff-table-footer px-3 py-3"
      >
        <p class="small text-secondary mb-0">
          Showing
          <span class="fw-semibold text-body">{{ filteredPhotos.length }}</span>
          of
          <span class="fw-semibold text-body">{{ photos.length }}</span>
          photos
        </p>
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
.resources-photo-card {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 0.5rem;
  padding: 0.5rem;
  background: var(--bs-body-bg, #fff);
}

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
