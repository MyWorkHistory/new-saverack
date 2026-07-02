<script setup>
import { computed, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  portalMode: { type: Boolean, default: false },
  portalAccountId: { type: Number, default: 0 },
  accounts: { type: Array, default: () => [] },
  accountsLoading: { type: Boolean, default: false },
  initialAccountId: { type: String, default: "" },
});

const emit = defineEmits(["close", "created", "update:open"]);

const toast = useToast();
const saving = ref(false);
const selectedAccountId = ref("");

const form = reactive({
  order_number: "",
  shipping_address: {
    first_name: "",
    last_name: "",
    company: "",
    address1: "",
    address2: "",
    city: "",
    state: "",
    zip: "",
    country: "US",
    email: "",
    phone: "",
  },
});

const accountOptions = computed(() =>
  props.accounts
    .filter((a) => a.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

const showAccountPicker = computed(() => !props.portalMode);

function resetForm() {
  form.order_number = "";
  Object.assign(form.shipping_address, {
    first_name: "",
    last_name: "",
    company: "",
    address1: "",
    address2: "",
    city: "",
    state: "",
    zip: "",
    country: "US",
    email: "",
    phone: "",
  });
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    if (props.portalMode && props.portalAccountId > 0) {
      selectedAccountId.value = String(props.portalAccountId);
    } else {
      selectedAccountId.value = props.initialAccountId || "";
      if (!selectedAccountId.value && accountOptions.value.length === 1) {
        selectedAccountId.value = String(accountOptions.value[0].id);
      }
    }
  },
);

function close() {
  if (saving.value) return;
  emit("update:open", false);
  emit("close");
}

async function submit() {
  const accountId = props.portalMode
    ? props.portalAccountId
    : Number(selectedAccountId.value || 0);
  if (!accountId) {
    toast.error("Select a client account.");
    return;
  }
  saving.value = true;
  try {
    const { data } = await api.post("/order-drafts", {
      client_account_id: accountId,
      order_number: form.order_number.trim(),
      shipping_address: { ...form.shipping_address },
    });
    resetForm();
    emit("created", data);
  } catch (e) {
    toast.errorFrom(e, "Could not create order draft.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Create Order"
    subtitle="Enter the order number and shipping address. You can add line items on the next screen."
    :busy="saving"
    form-id="order-create-form"
    @update:open="(v) => { emit('update:open', v); if (!v) emit('close'); }"
    @submit="submit"
  >
    <div class="row g-3">
      <div v-if="showAccountPicker" class="col-12">
        <label class="form-label small text-secondary" for="draft-order-account">Account</label>
        <CrmSearchableSelect
          v-model="selectedAccountId"
          appearance="staff"
          teleport-panel
          aria-label="Client account"
          :options="accountOptions"
          :disabled="accountsLoading || saving"
          placeholder="Select account"
          search-placeholder="Search accounts…"
          :allow-empty="false"
          button-id="draft-order-account-trigger"
        />
      </div>
      <div class="col-12" :class="{ 'col-md-6': showAccountPicker }">
        <label class="form-label small text-secondary" for="draft-order-number">Order #</label>
        <input
          id="draft-order-number"
          v-model.trim="form.order_number"
          type="text"
          class="form-control"
          required
          :disabled="saving"
        />
      </div>
      <div class="col-12">
        <h3 class="h6 fw-semibold text-body mb-2">Shipping Address</h3>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label small" for="draft-ship-first">First Name</label>
        <input
          id="draft-ship-first"
          v-model.trim="form.shipping_address.first_name"
          type="text"
          class="form-control form-control-sm"
          required
          :disabled="saving"
        />
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label small" for="draft-ship-last">Last Name</label>
        <input
          id="draft-ship-last"
          v-model.trim="form.shipping_address.last_name"
          type="text"
          class="form-control form-control-sm"
          required
          :disabled="saving"
        />
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small" for="draft-ship-company">Company</label>
        <input
          id="draft-ship-company"
          v-model.trim="form.shipping_address.company"
          type="text"
          class="form-control form-control-sm"
          :disabled="saving"
        />
      </div>
      <div class="col-12 col-md-8">
        <label class="form-label small" for="draft-ship-address1">Address</label>
        <input
          id="draft-ship-address1"
          v-model.trim="form.shipping_address.address1"
          type="text"
          class="form-control form-control-sm"
          required
          :disabled="saving"
        />
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small" for="draft-ship-address2">Address 2</label>
        <input
          id="draft-ship-address2"
          v-model.trim="form.shipping_address.address2"
          type="text"
          class="form-control form-control-sm"
          :disabled="saving"
        />
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small" for="draft-ship-city">City</label>
        <input
          id="draft-ship-city"
          v-model.trim="form.shipping_address.city"
          type="text"
          class="form-control form-control-sm"
          required
          :disabled="saving"
        />
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small" for="draft-ship-state">State</label>
        <input
          id="draft-ship-state"
          v-model.trim="form.shipping_address.state"
          type="text"
          class="form-control form-control-sm"
          required
          :disabled="saving"
        />
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small" for="draft-ship-zip">ZIP</label>
        <input
          id="draft-ship-zip"
          v-model.trim="form.shipping_address.zip"
          type="text"
          class="form-control form-control-sm"
          required
          :disabled="saving"
        />
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small" for="draft-ship-country">Country</label>
        <input
          id="draft-ship-country"
          v-model.trim="form.shipping_address.country"
          type="text"
          class="form-control form-control-sm"
          required
          maxlength="8"
          :disabled="saving"
        />
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label small" for="draft-ship-email">Email</label>
        <input
          id="draft-ship-email"
          v-model.trim="form.shipping_address.email"
          type="email"
          class="form-control form-control-sm"
          :disabled="saving"
        />
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label small" for="draft-ship-phone">Phone</label>
        <input
          id="draft-ship-phone"
          v-model.trim="form.shipping_address.phone"
          type="text"
          class="form-control form-control-sm"
          :disabled="saving"
        />
      </div>
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button
          type="button"
          :class="CRM_BTN_SECONDARY"
          :disabled="saving"
          @click="close"
        >
          Cancel
        </button>
        <button
          type="submit"
          form="order-create-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="saving"
        >
          {{ saving ? "Creating…" : "Create Order" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
