<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import { marketplaceOptionsForValue } from "../../constants/storeMarketplaces.js";

const STORE_STATUSES = ["pending", "active", "inactive"];

const props = defineProps({
  open: { type: Boolean, default: false },
  selectedCount: { type: Number, default: 0 },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "apply"]);

const changeStatus = ref(false);
const status = ref("pending");
const changeMarketplace = ref(false);
const marketplace = ref("");

const marketplaceSelectOptions = computed(() =>
  marketplaceOptionsForValue(marketplace.value),
);

watch(
  () => props.open,
  (o) => {
    if (!o) return;
    changeStatus.value = false;
    status.value = "pending";
    changeMarketplace.value = false;
    marketplace.value = "";
  },
);

function close() {
  emit("update:open", false);
}

function onSubmit() {
  if (!changeStatus.value && !changeMarketplace.value) return;
  emit("apply", {
    apply_status: changeStatus.value,
    status: status.value,
    apply_marketplace: changeMarketplace.value,
    marketplace: changeMarketplace.value ? marketplace.value.trim() || null : null,
  });
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
        aria-labelledby="stores-bulk-edit-modal-title"
      >
        <div
          class="crm-vx-modal-backdrop"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal crm-vx-modal--sm">
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
              <h2 id="stores-bulk-edit-modal-title" class="crm-vx-modal__title">
                Bulk edit stores
              </h2>
              <p class="crm-vx-modal__subtitle">
                {{ selectedCount }} store(s) selected
              </p>
            </header>

            <div class="crm-vx-modal__body pt-0">
              <div class="form-check mb-3">
                <input
                  id="csb-change-status"
                  v-model="changeStatus"
                  type="checkbox"
                  class="form-check-input"
                />
                <label class="form-check-label fw-medium" for="csb-change-status">
                  Change status
                </label>
              </div>
              <div v-if="changeStatus" class="mb-4">
                <label class="form-label small mb-1 text-secondary" for="csb-status"
                  >Status</label
                >
                <select id="csb-status" v-model="status" class="form-select">
                  <option v-for="s in STORE_STATUSES" :key="s" :value="s">
                    {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                  </option>
                </select>
              </div>

              <div class="form-check mb-3">
                <input
                  id="csb-change-mp"
                  v-model="changeMarketplace"
                  type="checkbox"
                  class="form-check-input"
                />
                <label class="form-check-label fw-medium" for="csb-change-mp">
                  Change marketplace
                </label>
              </div>
              <div v-if="changeMarketplace">
                <CrmSearchableSelect
                  v-model="marketplace"
                  :options="marketplaceSelectOptions"
                  label="Marketplace"
                  placeholder="Select marketplace"
                  search-placeholder="Search…"
                  :allow-empty="true"
                  empty-label="— Clear marketplace —"
                  button-id="csb-mp-trigger"
                  listbox-id="csb-mp-list"
                />
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
                :disabled="busy || (!changeStatus && !changeMarketplace)"
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
