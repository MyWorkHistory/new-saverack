<script setup>
import { computed, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import { useToast } from "../../composables/useToast.js";

const US_STATES = [
  "AL", "AK", "AZ", "AR", "CA", "CO", "CT", "DE", "FL", "GA",
  "HI", "ID", "IL", "IN", "IA", "KS", "KY", "LA", "ME", "MD",
  "MA", "MI", "MN", "MS", "MO", "MT", "NE", "NV", "NH", "NJ",
  "NM", "NY", "NC", "ND", "OH", "OK", "OR", "PA", "RI", "SC",
  "SD", "TN", "TX", "UT", "VT", "VA", "WA", "WV", "WI", "WY", "DC",
];

const props = defineProps({
  open: { type: Boolean, default: false },
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
    country: "United States",
    first_name: "",
    last_name: "",
    company: "",
    address1: "",
    address2: "",
    city: "",
    state: "",
    zip: "",
    email: "",
    phone: "",
  },
});

const accountOptions = computed(() =>
  (props.accounts || []).map((a) => ({
    id: a.id,
    name: a.name || a.company_name || `Account #${a.id}`,
    email: a.email ? String(a.email) : "",
  })),
);

function resetForm() {
  form.order_number = "";
  Object.assign(form.shipping_address, {
    country: "United States",
    first_name: "",
    last_name: "",
    company: "",
    address1: "",
    address2: "",
    city: "",
    state: "",
    zip: "",
    email: "",
    phone: "",
  });
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    selectedAccountId.value = props.initialAccountId || "";
    if (!selectedAccountId.value && accountOptions.value.length === 1) {
      selectedAccountId.value = String(accountOptions.value[0].id);
    }
  },
);

function close() {
  if (saving.value) return;
  emit("update:open", false);
  emit("close");
}

async function submit() {
  const accountId = Number(selectedAccountId.value || 0);
  if (!accountId) {
    toast.error("Select an account.");
    return;
  }
  saving.value = true;
  try {
    const ship = { ...form.shipping_address };
    ship.province = ship.state;
    const { data } = await api.post("/shopify/orders", {
      client_account_id: accountId,
      order_number: String(form.order_number || "").trim() || undefined,
      email: String(ship.email || "").trim() || undefined,
      shipping_address: ship,
    });
    resetForm();
    emit("created", data?.order || data);
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not create order.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Create Order"
    subtitle="CRM-only draft. Nothing is sent to Shopify."
    :busy="saving"
    form-id="shopify-order-create-form"
    @update:open="(v) => { emit('update:open', v); if (!v) emit('close'); }"
    @submit="submit"
  >
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label small text-secondary" for="so-create-account">Account</label>
        <CrmSearchableSelect
          v-model="selectedAccountId"
          appearance="staff"
          teleport-panel
          aria-label="Account"
          :options="accountOptions"
          :disabled="accountsLoading || saving"
          placeholder="Select Account"
          search-placeholder="Search Accounts…"
          :allow-empty="false"
          button-id="so-create-account-trigger"
        />
      </div>
      <div class="col-12">
        <label class="form-label small text-secondary" for="so-create-order-number">
          Order #
          <span class="text-secondary fw-normal">(Optional — blank creates MO-1, MO-2…)</span>
        </label>
        <input
          id="so-create-order-number"
          v-model.trim="form.order_number"
          type="text"
          class="form-control"
          placeholder="Leave blank for MO-#"
          :disabled="saving"
        />
      </div>

      <div class="col-12 pt-2">
        <h3 class="h6 fw-semibold text-body mb-0">Recipient Info</h3>
        <p class="small text-secondary mb-0">Optional — you can create the order without a recipient.</p>
      </div>

      <div class="col-12">
        <label class="form-label small text-secondary" for="so-create-country">Country</label>
        <select id="so-create-country" v-model="form.shipping_address.country" class="form-select" :disabled="saving">
          <option value="United States">United States</option>
          <option value="Canada">Canada</option>
          <option value="Mexico">Mexico</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label small text-secondary" for="so-create-first">First Name</label>
        <input
          id="so-create-first"
          v-model.trim="form.shipping_address.first_name"
          type="text"
          class="form-control"
          :disabled="saving"
        />
      </div>
      <div class="col-6">
        <label class="form-label small text-secondary" for="so-create-last">Last Name</label>
        <input
          id="so-create-last"
          v-model.trim="form.shipping_address.last_name"
          type="text"
          class="form-control"
          :disabled="saving"
        />
      </div>
      <div class="col-12">
        <label class="form-label small text-secondary" for="so-create-company">
          Company <span class="text-secondary fw-normal">(Optional)</span>
        </label>
        <input
          id="so-create-company"
          v-model.trim="form.shipping_address.company"
          type="text"
          class="form-control"
          :disabled="saving"
        />
      </div>
      <div class="col-12">
        <label class="form-label small text-secondary" for="so-create-address1">Address 1</label>
        <input
          id="so-create-address1"
          v-model.trim="form.shipping_address.address1"
          type="text"
          class="form-control"
          placeholder="Street address"
          :disabled="saving"
        />
      </div>
      <div class="col-12">
        <label class="form-label small text-secondary" for="so-create-address2">
          Address 2 <span class="text-secondary fw-normal">(Optional)</span>
        </label>
        <input
          id="so-create-address2"
          v-model.trim="form.shipping_address.address2"
          type="text"
          class="form-control"
          placeholder="Apartment, suite, unit, etc."
          :disabled="saving"
        />
      </div>
      <div class="col-12">
        <label class="form-label small text-secondary" for="so-create-city">City</label>
        <input
          id="so-create-city"
          v-model.trim="form.shipping_address.city"
          type="text"
          class="form-control"
          :disabled="saving"
        />
      </div>
      <div class="col-6">
        <label class="form-label small text-secondary" for="so-create-state">State</label>
        <select id="so-create-state" v-model="form.shipping_address.state" class="form-select" :disabled="saving">
          <option value="">Select State</option>
          <option v-for="st in US_STATES" :key="st" :value="st">{{ st }}</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label small text-secondary" for="so-create-zip">Zip Code</label>
        <input
          id="so-create-zip"
          v-model.trim="form.shipping_address.zip"
          type="text"
          class="form-control"
          :disabled="saving"
        />
      </div>
    </div>

    <template #footer>
      <div class="d-flex justify-content-end gap-2 border-top px-4 py-3">
        <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="close">Cancel</button>
        <button type="submit" form="shopify-order-create-form" class="btn btn-primary staff-page-primary" :disabled="saving">
          {{ saving ? "Creating…" : "Create Order" }}
        </button>
      </div>
    </template>
  </CrmRightDrawer>
</template>
