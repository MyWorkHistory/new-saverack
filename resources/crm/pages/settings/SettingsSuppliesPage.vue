<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import SupplyFormDrawer from "../../components/settings/SupplyFormDrawer.vue";
import { supplyTypeLabel } from "../../constants/supplies.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const canManage = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  const keys = Array.isArray(u.permission_keys) ? u.permission_keys : [];
  return (
    keys.includes("resources_supplies.update") ||
    keys.includes("resources_supplies.create") ||
    keys.includes("resources_supplies.delete") ||
    keys.includes("resources.update") ||
    keys.includes("resources.create") ||
    keys.includes("resources.delete")
  );
});

const loading = ref(true);
const rows = ref([]);
const drawerOpen = ref(false);
const drawerBusy = ref(false);
const editing = ref(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteTarget = ref(null);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

setCrmPageMeta({
  title: "Save Rack | Supplies",
  description: "Manage the staff supplies catalog.",
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/settings/supplies");
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load supplies.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  editing.value = null;
  manageOpenId.value = null;
  drawerOpen.value = true;
}

function openManage(row, event) {
  const btn = event?.currentTarget;
  const rect = btn?.getBoundingClientRect?.();
  const MENU_W = 180;
  if (rect) {
    let left = Math.round(rect.right - MENU_W);
    left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
    manageMenuRect.value = {
      top: Math.round(rect.bottom + 4),
      left,
    };
  }
  manageOpenId.value = manageOpenId.value === row.id ? null : row.id;
}

function openEdit(row) {
  editing.value = row;
  manageOpenId.value = null;
  drawerOpen.value = true;
}

function requestDelete(row) {
  deleteTarget.value = row;
  manageOpenId.value = null;
  deleteOpen.value = true;
}

async function saveSupply(payload) {
  drawerBusy.value = true;
  try {
    if (editing.value?.id) {
      await api.patch(`/settings/supplies/${editing.value.id}`, payload);
      toast.success("Supply updated.");
    } else {
      await api.post("/settings/supplies", payload);
      toast.success("Supply added.");
    }
    drawerOpen.value = false;
    editing.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not save supply.");
  } finally {
    drawerBusy.value = false;
  }
}

async function confirmDelete() {
  if (!deleteTarget.value?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/settings/supplies/${deleteTarget.value.id}`);
    toast.success("Supply deleted.");
    deleteOpen.value = false;
    deleteTarget.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete supply.");
  } finally {
    deleteBusy.value = false;
  }
}

function truncateLink(url) {
  const s = String(url || "").trim();
  if (!s) return "—";
  if (s.length <= 48) return s;
  return `${s.slice(0, 45)}…`;
}

function onDocClick(event) {
  if (event.target.closest?.("[data-row-actions]")) return;
  manageOpenId.value = null;
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
  load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Supplies</h1>
        <p class="text-secondary small mb-0">
          Catalog items staff can order from Resources → Supplies.
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="btn btn-primary staff-page-primary fw-semibold"
        @click="openCreate"
      >
        Add Supply
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div v-if="loading" class="p-5 d-flex justify-content-center">
        <CrmLoadingSpinner message="Loading supplies…" />
      </div>
      <div v-else class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Item Name</th>
              <th class="staff-table-head__th" scope="col">Type</th>
              <th class="staff-table-head__th" scope="col">Link</th>
              <th
                v-if="canManage"
                class="staff-table-head__th text-center staff-actions-col"
                scope="col"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td :colspan="canManage ? 4 : 3" class="text-center text-secondary py-4">
                No supplies yet.
              </td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td class="fw-semibold">{{ row.name || "—" }}</td>
              <td>{{ row.type_label || supplyTypeLabel(row.type) }}</td>
              <td>
                <a
                  v-if="row.link"
                  :href="row.link"
                  class="text-decoration-none"
                  target="_blank"
                  rel="noopener noreferrer"
                  :title="row.link"
                >
                  {{ truncateLink(row.link) }}
                </a>
                <span v-else class="text-secondary">—</span>
              </td>
              <td v-if="canManage" class="staff-actions-cell text-center">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="(e) => openManage(row, e)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageOpenId != null"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${manageMenuRect.top}px`,
          left: `${manageMenuRect.left}px`,
        }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openEdit(rows.find((r) => r.id === manageOpenId))"
        >
          Edit
        </button>
        <button
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="requestDelete(rows.find((r) => r.id === manageOpenId))"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <SupplyFormDrawer
      v-model:open="drawerOpen"
      :busy="drawerBusy"
      :supply="editing"
      @save="saveSupply"
    />

    <ConfirmModal
      :open="deleteOpen"
      title="Delete Supply"
      :message="
        deleteTarget
          ? `Delete “${deleteTarget.name}”? Order history will keep a snapshot of this item.`
          : ''
      "
      confirm-label="Delete"
      :busy="deleteBusy"
      danger
      @close="deleteOpen = false"
      @confirm="confirmDelete"
    />
  </div>
</template>
