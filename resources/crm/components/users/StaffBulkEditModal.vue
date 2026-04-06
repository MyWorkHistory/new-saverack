<script setup>
import { onUnmounted, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  roles: { type: Array, default: () => [] },
  selectedCount: { type: Number, default: 0 },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "apply"]);

const changeStatus = ref(false);
const status = ref("active");
const changeRoles = ref(false);
const roleIds = ref([]);

watch(
  () => props.open,
  (o) => {
    if (!o) return;
    changeStatus.value = false;
    changeRoles.value = false;
    status.value = "active";
    roleIds.value = [];
  },
);

function close() {
  emit("update:open", false);
}

function toggleRole(id) {
  const n = Number(id);
  const i = roleIds.value.indexOf(n);
  if (i === -1) {
    roleIds.value = [...roleIds.value, n];
  } else {
    roleIds.value = roleIds.value.filter((x) => x !== n);
  }
}

function onSubmit() {
  if (!changeStatus.value && !changeRoles.value) {
    return;
  }
  const payload = {};
  if (changeStatus.value) {
    payload.status = status.value;
  }
  if (changeRoles.value) {
    payload.role_ids = [...roleIds.value];
  }
  emit("apply", payload);
}

function onBackdropClick() {
  if (!props.busy) close();
}

function onEsc(e) {
  if (e.key === "Escape" && props.open && !props.busy) {
    e.preventDefault();
    close();
  }
}

watch(
  () => props.open,
  (o) => {
    if (o) {
      document.addEventListener("keydown", onEsc);
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => {
  document.removeEventListener("keydown", onEsc);
});
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        aria-modal="true"
        role="dialog"
        aria-labelledby="staff-bulk-edit-modal-title"
      >
        <div
          class="crm-vx-modal-backdrop"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal">
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="busy"
              @click="close"
            >
              <svg
                width="20"
                height="20"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.75"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>

            <header class="crm-vx-modal__head">
              <h2 id="staff-bulk-edit-modal-title" class="crm-vx-modal__title">
                Bulk edit
              </h2>
              <p class="crm-vx-modal__subtitle">
                Update {{ selectedCount }} selected
                {{ selectedCount === 1 ? "person" : "people" }}
              </p>
            </header>

            <div class="crm-vx-modal__body pt-0">
              <div class="border rounded p-3 mb-3">
                <div class="form-check mb-2">
                  <input
                    id="sbe-status"
                    v-model="changeStatus"
                    type="checkbox"
                    class="form-check-input"
                  />
                  <label class="form-check-label fw-medium" for="sbe-status">
                    Status
                  </label>
                </div>
                <label class="form-label small mb-1 text-secondary" for="sbe-status-select"
                  >Account status</label
                >
                <select
                  id="sbe-status-select"
                  v-model="status"
                  class="form-select"
                  :disabled="!changeStatus"
                >
                  <option value="pending">Pending</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>

              <div class="border rounded p-3">
                <div class="form-check mb-2">
                  <input
                    id="sbe-roles"
                    v-model="changeRoles"
                    type="checkbox"
                    class="form-check-input"
                  />
                  <label class="form-check-label fw-medium" for="sbe-roles">
                    Roles
                  </label>
                </div>
                <p class="small text-secondary mb-2">
                  Replaces roles for all selected accounts.
                </p>
                <div class="d-flex flex-wrap gap-2">
                  <label
                    v-for="r in roles"
                    :key="r.id"
                    class="d-inline-flex align-items-center gap-2 border rounded px-2 py-1 small mb-0"
                    :class="
                      changeRoles ? 'cursor-pointer' : 'opacity-50 pointer-events-none'
                    "
                  >
                    <input
                      type="checkbox"
                      class="form-check-input mt-0"
                      :checked="roleIds.includes(Number(r.id))"
                      :disabled="!changeRoles"
                      @change="toggleRole(r.id)"
                    />
                    <span>{{ r.label || r.name }}</span>
                  </label>
                </div>
              </div>
            </div>

            <footer class="crm-vx-modal__footer">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="busy"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="busy || (!changeStatus && !changeRoles)"
                @click="onSubmit"
              >
                {{ busy ? "Applying…" : "Apply" }}
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-backdrop-enter-active,
.modal-backdrop-leave-active {
  transition: opacity 0.2s ease;
}
.modal-backdrop-enter-active .crm-vx-modal-backdrop,
.modal-backdrop-leave-active .crm-vx-modal-backdrop {
  transition: inherit;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}

.modal-panel-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}
</style>
