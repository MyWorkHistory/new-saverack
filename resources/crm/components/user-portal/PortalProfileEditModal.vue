<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  /** "account" | "address" */
  section: { type: String, default: "account" },
  profile: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  name: "",
  email: "",
  company_name: "",
  phone: "",
  street: "",
  city: "",
  state: "",
  zip: "",
  country: "",
});

const isAccount = computed(() => props.section === "account");
const isAddress = computed(() => props.section === "address");

const modalTitle = computed(() =>
  isAddress.value ? "Edit Address" : "Edit Personal Information",
);

function close() {
  emit("update:open", false);
}

function onEsc(e) {
  if (e.key === "Escape") close();
}

function fillFromProfile(data) {
  if (!data || typeof data !== "object") return;
  form.name = data.name || data.contact_full_name || "";
  form.email = data.email || "";
  form.company_name = data.company_name || "";
  form.phone = data.phone || "";
  form.street = data.street || "";
  form.city = data.city || "";
  form.state = data.state || "";
  form.zip = data.zip || "";
  form.country = data.country || "";
}

watch(
  () => [props.open, props.profile],
  ([open]) => {
    if (open) {
      document.addEventListener("keydown", onEsc);
      fillFromProfile(props.profile);
      errorMsg.value = "";
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => document.removeEventListener("keydown", onEsc));

async function save() {
  if (saving.value) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    const payload = {
      name: form.name.trim(),
      email: form.email.trim(),
      company_name: form.company_name.trim(),
      phone: form.phone,
      street: form.street,
      city: form.city,
      state: form.state,
      zip: form.zip,
      country: form.country,
    };
    const { data } = await api.patch("/portal/profile", payload);
    toast.success("Saved.");
    emit("saved", data);
    close();
  } catch (e) {
    errorMsg.value = "Could not save.";
    toast.errorFrom(e, "Could not save.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="modal fade show d-block"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      @click.self="close"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
          <div class="modal-header border-bottom">
            <h2 class="modal-title h5 mb-0">{{ modalTitle }}</h2>
            <button
              type="button"
              class="btn-close"
              aria-label="Close"
              @click="close"
            />
          </div>
          <form class="modal-body" @submit.prevent="save">
            <p v-if="errorMsg" class="text-danger small">{{ errorMsg }}</p>

            <template v-if="isAccount">
              <div class="mb-3">
                <label class="form-label" for="portal-edit-company">Company</label>
                <input
                  id="portal-edit-company"
                  v-model="form.company_name"
                  type="text"
                  class="form-control"
                  required
                  autocomplete="organization"
                />
              </div>
              <div class="mb-3">
                <label class="form-label" for="portal-edit-email">Email</label>
                <input
                  id="portal-edit-email"
                  v-model="form.email"
                  type="email"
                  class="form-control"
                  required
                  autocomplete="email"
                />
              </div>
              <div class="mb-3">
                <label class="form-label" for="portal-edit-name">Name</label>
                <input
                  id="portal-edit-name"
                  v-model="form.name"
                  type="text"
                  class="form-control"
                  required
                  autocomplete="name"
                />
              </div>
              <div class="mb-3">
                <label class="form-label" for="portal-edit-phone">Phone Number</label>
                <input
                  id="portal-edit-phone"
                  v-model="form.phone"
                  type="tel"
                  class="form-control"
                  autocomplete="tel"
                />
              </div>
            </template>

            <template v-if="isAddress">
              <div class="mb-3">
                <label class="form-label" for="portal-edit-street">Street</label>
                <input
                  id="portal-edit-street"
                  v-model="form.street"
                  type="text"
                  class="form-control"
                  autocomplete="street-address"
                />
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="portal-edit-city">City</label>
                  <input
                    id="portal-edit-city"
                    v-model="form.city"
                    type="text"
                    class="form-control"
                    autocomplete="address-level2"
                  />
                </div>
                <div class="col-md-3">
                  <label class="form-label" for="portal-edit-state">State</label>
                  <input
                    id="portal-edit-state"
                    v-model="form.state"
                    type="text"
                    class="form-control"
                    autocomplete="address-level1"
                  />
                </div>
                <div class="col-md-3">
                  <label class="form-label" for="portal-edit-zip">ZIP</label>
                  <input
                    id="portal-edit-zip"
                    v-model="form.zip"
                    type="text"
                    class="form-control"
                    autocomplete="postal-code"
                  />
                </div>
                <div class="col-12">
                  <label class="form-label" for="portal-edit-country">Country</label>
                  <input
                    id="portal-edit-country"
                    v-model="form.country"
                    type="text"
                    class="form-control"
                    autocomplete="country-name"
                  />
                </div>
              </div>
            </template>

            <div class="d-flex justify-content-end gap-2 pt-3">
              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="submit"
                class="btn btn-primary staff-page-primary"
                :disabled="saving"
              >
                <CrmLoadingSpinner v-if="saving" small class="me-1" />
                Save
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div v-if="open" class="modal-backdrop fade show" />
  </Teleport>
</template>
