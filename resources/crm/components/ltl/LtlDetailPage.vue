<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import {
  formatLtlMoney,
  ltlStatusBadgeClass,
  LTL_DIRECTIONS,
  LTL_LOAD_OPTIONS,
  LTL_PICKUP_TYPES,
  LTL_SERVICES,
  LTL_STATUSES,
  LTL_TIME_MODES,
} from "../../constants/ltlSections.js";

const props = defineProps({
  mode: { type: String, default: "admin" },
});

const toast = useToast();
const route = useRoute();
const router = useRouter();
const isPortal = computed(() => props.mode === "portal");
const apiBase = computed(() => (isPortal.value ? "/ltl-shipments" : "/admin/ltl-shipments"));
const listRoute = computed(() => (isPortal.value ? "user-ltl-list" : "admin-ltl-list"));

const loading = ref(true);
const saving = ref(false);
const shipment = ref(null);

const addressOpen = ref(false);
const quoteOpen = ref(false);
const palletOpen = ref(false);
const editingPallet = ref(null);

const addressForm = ref({});
const quoteForm = ref({});
const palletForm = ref({});
const reqForm = ref({ load_requirement: "", pickup_type: "" });

const canEdit = computed(() => {
  if (!shipment.value) return false;
  if (!isPortal.value) return true;
  return ["draft", "pending"].includes(shipment.value.status);
});

const canGetQuote = computed(() => shipment.value?.status === "draft" && canEdit.value);
const canChangeStatus = computed(() => !isPortal.value);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`${apiBase.value}/${route.params.id}`);
    shipment.value = data?.shipment || null;
    if (shipment.value) {
      reqForm.value = {
        load_requirement: shipment.value.load_requirement || "",
        pickup_type: shipment.value.pickup_type || "",
      };
      setCrmPageMeta({
        title: `Save Rack | ${shipment.value.number}`,
        description: "LTL shipment detail.",
      });
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load LTL.");
    shipment.value = null;
  } finally {
    loading.value = false;
  }
}

function openAddressEdit() {
  const s = shipment.value;
  addressForm.value = {
    direction: s.direction,
    company_name: s.company_name || "",
    address_line1: s.address_line1 || "",
    address_line2: s.address_line2 || "",
    city: s.city || "",
    state: s.state || "",
    zip: s.zip || "",
    contact_name: s.contact_name || "",
    contact_email: s.contact_email || "",
    contact_phone: s.contact_phone || "",
    time_mode: s.time_mode || "asap",
    time_from: s.time_from ? s.time_from.slice(0, 16) : "",
    time_to: s.time_to ? s.time_to.slice(0, 16) : "",
  };
  addressOpen.value = true;
}

function openQuoteEdit() {
  const s = shipment.value;
  quoteForm.value = {
    quote_amount: s.quote_amount_cents != null ? (Number(s.quote_amount_cents) / 100).toFixed(2) : "",
    quote_carrier: s.quote_carrier || "",
    quote_transit_time: s.quote_transit_time || "",
    quote_service: s.quote_service || "standard_ltl",
    tracking_number: s.tracking_number || "",
  };
  quoteOpen.value = true;
}

function openAddPallet() {
  editingPallet.value = null;
  palletForm.value = { commodity: "", length_in: 48, width_in: 40, height_in: 60, weight_lbs: "" };
  palletOpen.value = true;
}

function openEditPallet(p) {
  editingPallet.value = p;
  palletForm.value = {
    commodity: p.commodity || "",
    length_in: p.length_in,
    width_in: p.width_in,
    height_in: p.height_in,
    weight_lbs: p.weight_lbs,
  };
  palletOpen.value = true;
}

async function patch(body) {
  saving.value = true;
  try {
    const { data } = await api.patch(`${apiBase.value}/${shipment.value.id}`, body);
    shipment.value = data.shipment;
    toast.success("Saved.");
    return true;
  } catch (e) {
    toast.errorFrom(e, "Could not save.");
    return false;
  } finally {
    saving.value = false;
  }
}

async function saveAddress() {
  const f = addressForm.value;
  const body = {
    direction: f.direction,
    company_name: f.company_name,
    address_line1: f.address_line1,
    address_line2: f.address_line2 || null,
    city: f.city,
    state: f.state,
    zip: f.zip,
    contact_name: f.contact_name,
    contact_email: f.contact_email || null,
    contact_phone: f.contact_phone,
    time_mode: f.time_mode,
    time_from: f.time_mode === "specific" && f.time_from ? f.time_from : null,
    time_to: f.time_mode === "specific" && f.time_to ? f.time_to : null,
  };
  if (await patch(body)) addressOpen.value = false;
}

async function saveRequirements() {
  await patch({
    load_requirement: reqForm.value.load_requirement || null,
    pickup_type: reqForm.value.pickup_type || null,
  });
}

async function saveNotes() {
  await patch({ notes: shipment.value.notes || null });
}

async function saveQuote() {
  const amount = quoteForm.value.quote_amount;
  const cents =
    amount === "" || amount == null ? null : Math.round(Number(amount) * 100);
  const body = {
    quote_amount_cents: Number.isFinite(cents) ? cents : null,
    quote_carrier: quoteForm.value.quote_carrier || null,
    quote_transit_time: quoteForm.value.quote_transit_time || null,
    quote_service: quoteForm.value.quote_service || "standard_ltl",
    tracking_number: quoteForm.value.tracking_number || null,
  };
  if (await patch(body)) quoteOpen.value = false;
}

async function savePallet() {
  saving.value = true;
  try {
    const body = { ...palletForm.value };
    if (editingPallet.value) {
      const { data } = await api.patch(
        `${apiBase.value}/${shipment.value.id}/pallets/${editingPallet.value.id}`,
        body,
      );
      shipment.value = data.shipment;
    } else {
      const { data } = await api.post(`${apiBase.value}/${shipment.value.id}/pallets`, body);
      shipment.value = data.shipment;
    }
    palletOpen.value = false;
    toast.success("Pallet saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save pallet.");
  } finally {
    saving.value = false;
  }
}

async function removePallet(p) {
  if (!confirm("Remove this pallet?")) return;
  try {
    const { data } = await api.delete(`${apiBase.value}/${shipment.value.id}/pallets/${p.id}`);
    shipment.value = data.shipment;
    toast.success("Pallet removed.");
  } catch (e) {
    toast.errorFrom(e, "Could not remove pallet.");
  }
}

async function onGetQuote() {
  saving.value = true;
  try {
    const { data } = await api.post(`${apiBase.value}/${shipment.value.id}/request-quote`);
    shipment.value = data.shipment;
    toast.success("Quote requested — status is Pending.");
  } catch (e) {
    const msgs = e.response?.data?.errors?.quote;
    if (Array.isArray(msgs) && msgs.length) {
      toast.error(msgs.join(" "));
    } else {
      toast.errorFrom(e, "Could not request quote.");
    }
  } finally {
    saving.value = false;
  }
}

async function setStatus(status) {
  if (!canChangeStatus.value || !status || status === shipment.value?.status) return;
  saving.value = true;
  try {
    const { data } = await api.patch(`${apiBase.value}/${shipment.value.id}/status`, { status });
    shipment.value = data.shipment;
    toast.success("Status updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>
    <template v-else-if="shipment">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <button type="button" class="btn btn-link btn-sm px-0 mb-1" @click="router.push({ name: listRoute })">
            ← Back to LTL
          </button>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <h1 class="h4 mb-0 fw-semibold">{{ shipment.number }}</h1>
            <span :class="ltlStatusBadgeClass(shipment.status)">{{ shipment.status_label }}</span>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button
            v-if="canGetQuote"
            type="button"
            class="btn btn-primary btn-sm"
            :disabled="saving"
            @click="onGetQuote"
          >
            Get Quote
          </button>
          <select
            v-if="canChangeStatus"
            class="form-select form-select-sm"
            style="max-width: 180px"
            :disabled="saving"
            :value="shipment.status"
            @change="setStatus($event.target.value)"
          >
            <option v-for="(meta, key) in LTL_STATUSES" :key="key" :value="key">
              {{ meta.label }}
            </option>
          </select>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <section class="staff-datatable-card staff-datatable-card--white h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="h6 mb-0">Pallets in Shipment</h2>
              <button
                v-if="canEdit"
                type="button"
                class="btn btn-sm btn-outline-primary"
                @click="openAddPallet"
              >
                Add Pallet
              </button>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Commodity</th>
                    <th>Length (in)</th>
                    <th>Width (in)</th>
                    <th>Height (in)</th>
                    <th>Weight (lbs)</th>
                    <th v-if="canEdit"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!(shipment.pallets || []).length">
                    <td :colspan="canEdit ? 7 : 6" class="text-secondary text-center py-3">No pallets yet.</td>
                  </tr>
                  <tr v-for="(p, idx) in shipment.pallets" :key="p.id">
                    <td>{{ idx + 1 }}</td>
                    <td>{{ p.commodity || "—" }}</td>
                    <td>{{ p.length_in ?? "—" }}</td>
                    <td>{{ p.width_in ?? "—" }}</td>
                    <td>{{ p.height_in ?? "—" }}</td>
                    <td>{{ p.weight_lbs ?? "—" }}</td>
                    <td v-if="canEdit" class="text-end text-nowrap">
                      <button type="button" class="btn btn-link btn-sm" @click="openEditPallet(p)">Edit</button>
                      <button type="button" class="btn btn-link btn-sm text-danger" @click="removePallet(p)">
                        Remove
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="mt-3">
              <label class="form-label">Notes</label>
              <textarea
                v-model="shipment.notes"
                class="form-control"
                rows="3"
                :disabled="!canEdit || saving"
              />
              <button
                v-if="canEdit"
                type="button"
                class="btn btn-sm btn-outline-secondary mt-2"
                :disabled="saving"
                @click="saveNotes"
              >
                Save Notes
              </button>
            </div>
          </section>
        </div>

        <div class="col-12 col-lg-5 d-flex flex-column gap-3">
          <section class="staff-datatable-card staff-datatable-card--white">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h2 class="h6 mb-0">{{ shipment.address_card_title }}</h2>
              <button
                v-if="canEdit"
                type="button"
                class="btn btn-sm btn-outline-primary"
                @click="openAddressEdit"
              >
                Edit
              </button>
            </div>
            <p class="small text-secondary mb-1">{{ shipment.direction_label }}</p>
            <p class="mb-1 fw-semibold">{{ shipment.company_name || "—" }}</p>
            <p class="mb-1 small">
              {{ shipment.address_line1 || "—" }}<br />
              <template v-if="shipment.address_line2">{{ shipment.address_line2 }}<br /></template>
              {{ [shipment.city, shipment.state, shipment.zip].filter(Boolean).join(", ") || "—" }}
            </p>
            <hr class="my-2" />
            <p class="small mb-1"><strong>Contact Name:</strong> {{ shipment.contact_name || "—" }}</p>
            <p class="small mb-1"><strong>Email:</strong> {{ shipment.contact_email || "—" }}</p>
            <p class="small mb-1"><strong>Phone:</strong> {{ shipment.contact_phone || "—" }}</p>
            <p class="small mb-0">
              <strong>{{ shipment.direction === "ship_from_save_rack" ? "Delivery Time" : "Pick Up Time" }}:</strong>
              <template v-if="shipment.time_mode === 'specific'">
                {{ shipment.time_from || "—" }} → {{ shipment.time_to || "—" }}
              </template>
              <template v-else>As Soon As Possible</template>
            </p>
          </section>

          <section class="staff-datatable-card staff-datatable-card--white">
            <h2 class="h6 mb-3">Requirements</h2>
            <label class="form-label">Load</label>
            <select
              v-model="reqForm.load_requirement"
              class="form-select form-select-sm mb-2"
              :disabled="!canEdit || saving"
            >
              <option value="">Select…</option>
              <option v-for="o in LTL_LOAD_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <label class="form-label">Pickup Type</label>
            <select
              v-model="reqForm.pickup_type"
              class="form-select form-select-sm mb-2"
              :disabled="!canEdit || saving"
            >
              <option value="">Select…</option>
              <option v-for="o in LTL_PICKUP_TYPES" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <button
              v-if="canEdit"
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :disabled="saving"
              @click="saveRequirements"
            >
              Save Requirements
            </button>
          </section>

          <section class="staff-datatable-card staff-datatable-card--white">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h2 class="h6 mb-0">Quote</h2>
              <button
                v-if="!isPortal"
                type="button"
                class="btn btn-sm btn-outline-primary"
                @click="openQuoteEdit"
              >
                Edit
              </button>
            </div>
            <p class="display-6 text-success fw-bold mb-2" style="font-size: 1.75rem">
              {{ formatLtlMoney(shipment.quote_amount_cents) }}
            </p>
            <div class="row small g-2">
              <div class="col-4">
                <div class="text-secondary">Transit Time</div>
                <div>{{ shipment.quote_transit_time || "—" }}</div>
              </div>
              <div class="col-4">
                <div class="text-secondary">Carrier</div>
                <div>{{ shipment.quote_carrier || "—" }}</div>
              </div>
              <div class="col-4">
                <div class="text-secondary">Service</div>
                <div>
                  {{
                    LTL_SERVICES.find((s) => s.value === shipment.quote_service)?.label ||
                    shipment.quote_service ||
                    "—"
                  }}
                </div>
              </div>
            </div>
            <p v-if="shipment.tracking_number" class="small mt-2 mb-0">
              <strong>Tracking:</strong> {{ shipment.tracking_number }}
            </p>
          </section>
        </div>
      </div>
    </template>
    <p v-else class="text-secondary">LTL not found.</p>

    <CrmRightDrawer
      :open="addressOpen"
      title="Edit Address"
      :busy="saving"
      form-id="ltl-address-form"
      @update:open="addressOpen = $event"
      @submit="saveAddress"
    >
      <div class="d-flex flex-column gap-2">
        <label class="form-label mb-0">Location</label>
        <select v-model="addressForm.direction" class="form-select">
          <option v-for="d in LTL_DIRECTIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
        </select>
        <label class="form-label mb-0">Company Name</label>
        <input v-model="addressForm.company_name" class="form-control" />
        <label class="form-label mb-0">Address</label>
        <input v-model="addressForm.address_line1" class="form-control" />
        <div class="row g-2">
          <div class="col-5"><input v-model="addressForm.city" class="form-control" placeholder="City" /></div>
          <div class="col-3"><input v-model="addressForm.state" class="form-control" placeholder="State" /></div>
          <div class="col-4"><input v-model="addressForm.zip" class="form-control" placeholder="Zip" /></div>
        </div>
        <label class="form-label mb-0">Contact Name</label>
        <input v-model="addressForm.contact_name" class="form-control" />
        <label class="form-label mb-0">Email</label>
        <input v-model="addressForm.contact_email" type="email" class="form-control" />
        <label class="form-label mb-0">Phone</label>
        <input v-model="addressForm.contact_phone" class="form-control" />
        <label class="form-label mb-0">{{ addressForm.direction === "ship_from_save_rack" ? "Delivery Time" : "Pick Up Time" }}</label>
        <select v-model="addressForm.time_mode" class="form-select">
          <option v-for="t in LTL_TIME_MODES" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
        <template v-if="addressForm.time_mode === 'specific'">
          <label class="form-label mb-0">From</label>
          <input v-model="addressForm.time_from" type="datetime-local" class="form-control" />
          <label class="form-label mb-0">To</label>
          <input v-model="addressForm.time_to" type="datetime-local" class="form-control" />
        </template>
      </div>
      <template #footer>
        <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
          <button type="button" :class="CRM_BTN_SECONDARY" @click="addressOpen = false">Cancel</button>
          <button type="submit" form="ltl-address-form" :class="CRM_BTN_PRIMARY" :disabled="saving">
            Save
          </button>
        </footer>
      </template>
    </CrmRightDrawer>

    <CrmRightDrawer
      :open="quoteOpen"
      title="Edit Quote"
      :busy="saving"
      form-id="ltl-quote-form"
      @update:open="quoteOpen = $event"
      @submit="saveQuote"
    >
      <div class="d-flex flex-column gap-2">
        <label class="form-label mb-0">Amount</label>
        <input v-model="quoteForm.quote_amount" type="number" step="0.01" min="0" class="form-control" />
        <label class="form-label mb-0">Carrier Name</label>
        <input v-model="quoteForm.quote_carrier" class="form-control" />
        <label class="form-label mb-0">Transit Time</label>
        <input v-model="quoteForm.quote_transit_time" class="form-control" placeholder="e.g. 3 – 5 Days" />
        <label class="form-label mb-0">Service</label>
        <select v-model="quoteForm.quote_service" class="form-select">
          <option v-for="s in LTL_SERVICES" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <label class="form-label mb-0">Tracking Number</label>
        <input v-model="quoteForm.tracking_number" class="form-control" />
      </div>
      <template #footer>
        <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
          <button type="button" :class="CRM_BTN_SECONDARY" @click="quoteOpen = false">Cancel</button>
          <button type="submit" form="ltl-quote-form" :class="CRM_BTN_PRIMARY" :disabled="saving">
            Save
          </button>
        </footer>
      </template>
    </CrmRightDrawer>

    <CrmRightDrawer
      :open="palletOpen"
      :title="editingPallet ? 'Edit Pallet' : 'Add Pallet'"
      :busy="saving"
      form-id="ltl-pallet-form"
      @update:open="palletOpen = $event"
      @submit="savePallet"
    >
      <div class="d-flex flex-column gap-2">
        <label class="form-label mb-0">Commodity</label>
        <input v-model="palletForm.commodity" class="form-control" />
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label mb-0">Length</label>
            <input v-model="palletForm.length_in" type="number" class="form-control" />
          </div>
          <div class="col-4">
            <label class="form-label mb-0">Width</label>
            <input v-model="palletForm.width_in" type="number" class="form-control" />
          </div>
          <div class="col-4">
            <label class="form-label mb-0">Height</label>
            <input v-model="palletForm.height_in" type="number" class="form-control" />
          </div>
        </div>
        <label class="form-label mb-0">Weight (lbs)</label>
        <input v-model="palletForm.weight_lbs" type="number" class="form-control" />
      </div>
      <template #footer>
        <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
          <button type="button" :class="CRM_BTN_SECONDARY" @click="palletOpen = false">Cancel</button>
          <button type="submit" form="ltl-pallet-form" :class="CRM_BTN_PRIMARY" :disabled="saving">
            Save
          </button>
        </footer>
      </template>
    </CrmRightDrawer>
  </div>
</template>
