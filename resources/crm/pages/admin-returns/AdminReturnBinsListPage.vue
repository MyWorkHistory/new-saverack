<script setup>
import { Transition, computed, inject, onMounted, onUnmounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const MENU_W = 160;
const MENU_H = 140;

const loading = ref(true);
const rows = ref([]);
const actionBusy = ref(false);

const menuRowId = ref(null);
const menuRect = ref({ top: 0, left: 0 });

const formOpen = ref(false);
const formEditing = ref(null);
const formName = ref("");
const formBusy = ref(false);

const clearTarget = ref(null);
const clearBusy = ref(false);
const deleteTarget = ref(null);
const deleteBusy = ref(false);

const canManage = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  const keys = Array.isArray(u.permission_keys) ? u.permission_keys : [];
  return (
    keys.includes("returns_bins.update") ||
    keys.includes("returns.update") ||
    keys.includes("returns.create")
  );
});

const menuRow = computed(() => {
  const id = menuRowId.value;
  if (id == null) return null;
  return rows.value.find((r) => Number(r.id) === Number(id)) ?? null;
});

const tableColspan = computed(() => (canManage.value ? 3 : 2));

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/admin/returns/bins");
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load return bins.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function openBin(row) {
  const binId = Number(row?.id || 0);
  if (binId <= 0) return;
  router.push({ name: "admin-return-bin-detail", params: { binId: String(binId) } });
}

function closeMenu() {
  menuRowId.value = null;
}

function onRowMenuClick(row, event) {
  const target = event?.currentTarget;
  if (!target) return;
  const rect = target.getBoundingClientRect();
  const left = Math.min(rect.left, window.innerWidth - MENU_W - 8);
  const top = Math.min(rect.bottom + 4, window.innerHeight - MENU_H - 8);
  menuRowId.value = Number(row.id);
  menuRect.value = { top, left };
}

function onDocumentClick(event) {
  if (!menuRowId.value) return;
  const el = event?.target;
  if (el instanceof Element && el.closest("[data-return-bin-list-actions]")) return;
  closeMenu();
}

function openAddModal() {
  closeMenu();
  formEditing.value = null;
  formName.value = "";
  formOpen.value = true;
}

function openEditModal(row) {
  closeMenu();
  formEditing.value = row;
  formName.value = String(row?.name || "");
  formOpen.value = true;
}

function closeFormModal() {
  if (formBusy.value) return;
  formOpen.value = false;
  formEditing.value = null;
  formName.value = "";
}

async function saveBin() {
  const name = String(formName.value || "").trim();
  if (!name) {
    toast.error("Enter a bin name.");
    return;
  }
  formBusy.value = true;
  try {
    if (formEditing.value?.id) {
      await api.patch(`/admin/returns/bins/${formEditing.value.id}`, { name });
      toast.success("Bin Updated.");
    } else {
      await api.post("/admin/returns/bins", { name });
      toast.success("Bin Added.");
    }
    formOpen.value = false;
    formEditing.value = null;
    formName.value = "";
    await load();
  } catch (e) {
    toast.errorFrom(e, formEditing.value ? "Could not rename bin." : "Could not add bin.");
  } finally {
    formBusy.value = false;
  }
}

function openClearModal(row) {
  closeMenu();
  clearTarget.value = row;
}

function closeClearModal() {
  if (clearBusy.value) return;
  clearTarget.value = null;
}

async function confirmClear() {
  const row = clearTarget.value;
  if (!row?.id) return;
  clearBusy.value = true;
  actionBusy.value = true;
  try {
    await api.post(`/admin/returns/bins/${row.id}/clear`);
    toast.success("Bin Cleared.");
    clearTarget.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not clear bin.");
  } finally {
    clearBusy.value = false;
    actionBusy.value = false;
  }
}

function openDeleteModal(row) {
  closeMenu();
  deleteTarget.value = row;
}

function closeDeleteModal() {
  if (deleteBusy.value) return;
  deleteTarget.value = null;
}

async function confirmDelete() {
  const row = deleteTarget.value;
  if (!row?.id) return;
  if (Number(row.items_count || 0) > 0) {
    toast.error("Clear all items from this bin before deleting it.");
    return;
  }
  deleteBusy.value = true;
  actionBusy.value = true;
  try {
    await api.delete(`/admin/returns/bins/${row.id}`);
    toast.success("Bin Deleted.");
    deleteTarget.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete bin.");
  } finally {
    deleteBusy.value = false;
    actionBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Return Bins",
    description: "Physical return bins awaiting restock.",
  });
  document.addEventListener("click", onDocumentClick);
  load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocumentClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-return-bins-page">
    <div
      class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1 text-center text-md-start w-100">
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Return Bins</h1>
        <p class="staff-page__intro mb-0">
          Items staged in return bins before restock to pick locations.
        </p>
      </div>
      <div
        class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-end gap-2 flex-shrink-0"
      >
        <button
          v-if="canManage"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2 text-nowrap"
          :disabled="loading || actionBusy"
          @click="openAddModal"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Bin
        </button>
        <CrmRefreshToolbarButton
          :disabled="loading || actionBusy"
          :loading="loading"
          title="Refresh list"
          @click="load"
        />
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Bin Name</th>
              <th class="staff-table-head__th text-center" scope="col" style="width: 7rem">Items</th>
              <th
                v-if="canManage"
                class="staff-table-head__th staff-actions-col return-bins-actions-col"
                scope="col"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading return bins…" />
                </div>
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="`bin-${row.id}`"
              class="align-middle admin-return-bins-page__row"
              role="button"
              tabindex="0"
              @click="openBin(row)"
              @keydown.enter.prevent="openBin(row)"
            >
              <td class="fw-semibold text-body text-start">{{ row.name || "—" }}</td>
              <td class="text-center text-body">{{ row.items_count ?? 0 }}</td>
              <td
                v-if="canManage"
                class="staff-actions-cell return-bins-actions-cell"
                @click.stop
              >
                <div
                  data-return-bin-list-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': menuRowId === Number(row.id) }"
                    aria-haspopup="true"
                    :aria-expanded="menuRowId === Number(row.id) ? 'true' : 'false'"
                    aria-label="Row actions"
                    :disabled="actionBusy"
                    @click.stop="onRowMenuClick(row, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && !rows.length">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">
                No return bins yet. Add a bin to get started.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>
    </div>

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="menuRow"
          data-return-bin-list-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${menuRect.top}px`, left: `${menuRect.left}px` }"
          @click.stop
        >
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openEditModal(menuRow)">
            Edit
          </button>
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openClearModal(menuRow)">
            Clear
          </button>
          <button
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openDeleteModal(menuRow)"
          >
            Delete
          </button>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="formOpen"
      :title="formEditing ? 'Edit Bin' : 'Add Bin'"
      confirm-label="Save Bin"
      cancel-label="Cancel"
      :danger="false"
      :busy="formBusy"
      :form="true"
      @close="closeFormModal"
      @confirm="saveBin"
    >
      <label class="form-label small" for="return-bin-name-input">Bin Name</label>
      <input
        id="return-bin-name-input"
        v-model="formName"
        type="text"
        class="form-control"
        maxlength="255"
        placeholder="e.g. Returns A"
        :disabled="formBusy"
        autocomplete="off"
      />
    </ConfirmModal>

    <ConfirmModal
      :open="Boolean(clearTarget)"
      title="Clear Bin"
      :message="
        clearTarget
          ? `Clear all items from ${clearTarget.name}? Remaining quantities will be set to zero.`
          : ''
      "
      confirm-label="Clear Bin"
      cancel-label="Cancel"
      :danger="true"
      :busy="clearBusy"
      @close="closeClearModal"
      @confirm="confirmClear"
    />

    <ConfirmModal
      :open="Boolean(deleteTarget)"
      title="Delete Bin"
      :message="
        deleteTarget
          ? Number(deleteTarget.items_count || 0) > 0
            ? `${deleteTarget.name} still has items. Clear the bin before deleting.`
            : `Delete ${deleteTarget.name}? This cannot be undone.`
          : ''
      "
      confirm-label="Delete Bin"
      cancel-label="Cancel"
      :danger="true"
      :busy="deleteBusy"
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />
  </div>
</template>

<style scoped>
.admin-return-bins-page__row {
  cursor: pointer;
}
</style>
