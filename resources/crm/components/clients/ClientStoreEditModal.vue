<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import {
  marketplaceOptionsForValue,
  normalizeMarketplaceValue,
} from "../../constants/storeMarketplaces.js";
import { CLIENT_STORE_STATUS_OPTIONS } from "../../constants/clientStoreStatuses.js";
const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {{ id: number, name?: string, website?: string, marketplace?: string } | null} */
  store: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  name: "",
  website: "",
  marketplace: "",
  status: "pending",
});

const statusOptions = CLIENT_STORE_STATUS_OPTIONS;

const marketplaceOptions = computed(() =>
  marketplaceOptionsForValue(form.marketplace),
);

function close() {
  emit("update:open", false);
}

function onBackdropClick() {
  if (!saving.value) close();
}

function onEsc(e) {
  if (e.key === "Escape" && props.open && !saving.value) {
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

watch(
  () => [props.open, props.store],
  () => {
    if (!props.open || !props.store?.id) return;
    errorMsg.value = "";
    form.name = props.store.name || "";
    form.website = props.store.website || "";
    form.marketplace = normalizeMarketplaceValue(props.store.marketplace || "");
    form.status = props.store.status || "pending";
  },
);

async function onSubmit() {
  if (!props.store?.id) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    await api.patch(`/client-stores/${props.store.id}`, {
      name: form.name.trim(),
      website: form.website.trim() || null,
      marketplace: form.marketplace.trim() || null,
      status: form.status || "pending",
    });
    toast.success("Store updated.");
    emit("saved");
    close();
  } catch (e) {
    errorMsg.value = "Could not save.";
    toast.errorFrom(e, "Could not save store.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        aria-modal="true"
        role="dialog"
        aria-labelledby="client-store-edit-modal-title"
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
              :disabled="saving"
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
              <h2 id="client-store-edit-modal-title" class="crm-vx-modal__title">
                Edit store
              </h2>
              <p class="crm-vx-modal__subtitle">
                Update store name, website, status, and marketplace for this account.
              </p>
            </header>

            <div class="crm-vx-modal__body pt-0">
              <p
                v-if="errorMsg"
                class="small text-danger mb-3 text-center"
              >
                {{ errorMsg }}
              </p>
              <form
                id="client-store-edit-modal-form"
                class="d-flex flex-column gap-3"
                @submit.prevent="onSubmit"
              >
                <div>
                  <label class="form-label small mb-1 text-secondary" for="cse-name"
                    >Store name</label
                  >
                  <input
                    id="cse-name"
                    v-model="form.name"
                    type="text"
                    class="form-control"
                    required
                  />
                </div>
                <div>
                  <label class="form-label small mb-1 text-secondary" for="cse-web"
                    >Website</label
                  >
                  <input
                    id="cse-web"
                    v-model="form.website"
                    type="text"
                    class="form-control"
                  />
                </div>
                <div>
                  <label
                    class="form-label small mb-1 text-secondary"
                    for="cse-status"
                    >Status</label
                  >
                  <select
                    id="cse-status"
                    v-model="form.status"
                    class="form-select"
                  >
                    <option
                      v-for="opt in statusOptions"
                      :key="opt.value"
                      :value="opt.value"
                    >
                      {{ opt.label }}
                    </option>
                  </select>
                </div>
                <div>
                  <CrmSearchableSelect
                    v-model="form.marketplace"
                    :options="marketplaceOptions"
                    label="Marketplace"
                    placeholder="Select marketplace"
                    search-placeholder="Search marketplaces…"
                    :allow-empty="true"
                    empty-label="— None —"
                    button-id="cse-mp"
                    listbox-id="cse-mp-list"
                  />
                </div>
              </form>
            </div>

            <footer class="crm-vx-modal__footer">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="submit"
                form="client-store-edit-modal-form"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="saving || !store?.id"
              >
                {{ saving ? "Saving…" : "Save" }}
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
