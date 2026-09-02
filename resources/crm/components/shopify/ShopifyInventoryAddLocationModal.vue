<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  variantId: { type: [Number, String], default: null },
  clientAccountId: { type: [Number, String], default: null },
  addItemReasons: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const locationOptions = ref([]);
const locationsLoading = ref(false);
const submitBusy = ref(false);

const form = reactive({
  location_id: "",
  available: 1,
  reason: "Account Setup",
});

async function loadLocations() {
  locationsLoading.value = true;
  try {
    const { data } = await api.get("/shopify/locations/options");
    locationOptions.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    locationOptions.value = [];
    toast.errorFrom(e, "Could not load locations.");
  } finally {
    locationsLoading.value = false;
  }
}

function resetForm() {
  form.location_id = "";
  form.available = 1;
  form.reason = props.addItemReasons[0] || "Account Setup";
}

function close() {
  emit("update:open", false);
}

async function submit() {
  const locationId = Number(form.location_id || 0);
  const variantId = Number(props.variantId || 0);
  const accountId = Number(props.clientAccountId || 0);
  if (locationId <= 0) {
    toast.error("Select a location.");
    return;
  }
  if (variantId <= 0) {
    toast.error("Missing product for this action.");
    return;
  }
  if (accountId <= 0) {
    toast.error("Missing account for this product.");
    return;
  }
  if (!String(form.reason || "").trim()) {
    toast.error("Select a reason for add.");
    return;
  }
  const qty = Math.max(1, Number(form.available) || 1);

  submitBusy.value = true;
  try {
    await api.post(`/shopify/locations/${locationId}/items`, {
      client_account_id: accountId,
      shopify_variant_id: variantId,
      available: qty,
      reason: form.reason,
    });
    toast.success("Item added to location.");
    emit("saved");
    close();
  } catch (e) {
    toast.errorFrom(e, "Could not add item to location.");
  } finally {
    submitBusy.value = false;
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      resetForm();
      void loadLocations();
    }
  },
);
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="close">
      <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
        <button type="button" class="crm-vx-modal__close" aria-label="Close" :disabled="busy || submitBusy" @click="close">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <header class="crm-vx-modal__head" style="text-align: left">
          <h2 class="crm-vx-modal__title">Add Location</h2>
          <p class="crm-vx-modal__sub small text-secondary mb-0">Add inventory for this product at a warehouse location.</p>
        </header>
        <div class="crm-vx-modal__body">
          <label class="form-label" for="sid-add-loc-select">Location</label>
          <CrmSearchableSelect
            id="sid-add-loc-select"
            v-model="form.location_id"
            class="mb-3"
            appearance="staff"
            aria-label="Select location"
            :options="locationOptions"
            :disabled="locationsLoading || busy || submitBusy"
            :allow-empty="false"
            placeholder="Select Location"
            empty-label="Select Location"
            search-placeholder="Search locations…"
            teleport-panel
          />

          <label class="form-label" for="sid-add-loc-reason">Reason for Add</label>
          <select
            id="sid-add-loc-reason"
            v-model="form.reason"
            class="form-select mb-3"
            :disabled="busy || submitBusy"
          >
            <option v-for="reason in addItemReasons" :key="reason" :value="reason">{{ reason }}</option>
          </select>

          <label class="form-label" for="sid-add-loc-qty">QTY</label>
          <input
            id="sid-add-loc-qty"
            v-model.number="form.available"
            type="number"
            min="1"
            class="form-control"
            :disabled="busy || submitBusy"
          />
        </div>
        <footer class="crm-vx-modal__footer justify-content-end">
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
            :disabled="busy || submitBusy"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="busy || submitBusy"
            @click="submit"
          >
            {{ busy || submitBusy ? "Please Wait…" : "Add Item" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
