<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  accountId: { type: String, default: "" },
  accountManagers: { type: Array, default: () => [] },
  /** "", "left" (sidebar contact & channels), "account", "address" */
  section: { type: String, default: "" },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const loading = ref(false);
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  company_name: "",
  brand_name: "",
  website: "",
  contact_first_name: "",
  contact_last_name: "",
  email: "",
  phone: "",
  notify_email: true,
  telegram_handle: "",
  whatsapp_e164: "",
  slack_channel: "",
  in_house_slack: "",
  street: "",
  city: "",
  state: "",
  zip: "",
  country: "",
  account_manager_id: "",
});

const showAll = computed(() => !props.section);
const showLeft = computed(() => showAll.value || props.section === "left");
const showAccount = computed(() => showAll.value || props.section === "account");
const showAddress = computed(() => showAll.value || props.section === "address");
const isAccountSectionOnly = computed(() => props.section === "account");

const contactFullName = computed({
  get() {
    const a = String(form.contact_first_name || "").trim();
    const b = String(form.contact_last_name || "").trim();
    return [a, b].filter(Boolean).join(" ");
  },
  set(v) {
    const parts = String(v || "")
      .trim()
      .split(/\s+/)
      .filter(Boolean);
    form.contact_first_name = parts[0] || "";
    form.contact_last_name = parts.length > 1 ? parts.slice(1).join(" ") : "";
  },
});

const modalTitle = computed(() => {
  switch (props.section) {
    case "left":
      return "Contact & channels";
    case "account":
      return "Personal information";
    case "address":
      return "Address";
    default:
      return "Edit account";
  }
});

const modalSubtitle = computed(() => {
  switch (props.section) {
    case "left":
      return "Email, phone, account manager, and notification channels (including Slack).";
    case "account":
      return "Company, email, contact name, and phone.";
    case "address":
      return "Street, city, and country.";
    default:
      return "Update company profile, contacts, and notification channels.";
  }
});

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

onUnmounted(() => {
  document.removeEventListener("keydown", onEsc);
});

async function load() {
  if (!props.accountId) return;
  loading.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.get(`/client-accounts/${props.accountId}`);
    form.company_name = data.company_name || "";
    form.brand_name = data.brand_name || "";
    form.website = data.website || "";
    form.contact_first_name = data.contact_first_name || "";
    form.contact_last_name = data.contact_last_name || "";
    form.email = data.email || "";
    form.phone = data.phone || "";
    form.notify_email = !!data.notify_email;
    form.telegram_handle = data.telegram_handle || "";
    form.whatsapp_e164 = data.whatsapp_e164 || "";
    form.slack_channel = data.slack_channel || "";
    form.in_house_slack = data.in_house_slack || "";
    form.street = data.street || "";
    form.city = data.city || "";
    form.state = data.state || "";
    form.zip = data.zip || "";
    form.country = data.country || "";
    form.account_manager_id = data.account_manager_id
      ? String(data.account_manager_id)
      : "";
  } catch (e) {
    errorMsg.value = "Could not load account.";
    toast.errorFrom(e, "Could not load account.");
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.open,
  (o) => {
    if (o) {
      document.addEventListener("keydown", onEsc);
      if (props.accountId) load();
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

function normalizeWhatsappE164(v) {
  const t = String(v || "").trim();
  if (t === "") return null;
  let digits = t.replace(/\D/g, "");
  digits = digits.replace(/^0+/, "");
  if (!digits) return null;
  if (digits.length > 15) {
    digits = digits.slice(0, 15);
  }
  return `+${digits}`;
}

function buildPatch() {
  const trimOrNull = (v) => {
    const t = String(v || "").trim();

    return t === "" ? null : t;
  };
  if (!props.section) {
    return {
      company_name: form.company_name.trim(),
      brand_name: trimOrNull(form.brand_name),
      website: trimOrNull(form.website),
      contact_first_name: trimOrNull(form.contact_first_name),
      contact_last_name: trimOrNull(form.contact_last_name),
      email: form.email.trim(),
      phone: trimOrNull(form.phone),
      notify_email: !!form.notify_email,
      telegram_handle: trimOrNull(form.telegram_handle),
      whatsapp_e164: normalizeWhatsappE164(form.whatsapp_e164),
      slack_channel: trimOrNull(form.slack_channel),
      in_house_slack: trimOrNull(form.in_house_slack),
      street: trimOrNull(form.street),
      city: trimOrNull(form.city),
      state: trimOrNull(form.state),
      zip: trimOrNull(form.zip),
      country: trimOrNull(form.country),
      account_manager_id: form.account_manager_id
        ? Number(form.account_manager_id)
        : null,
    };
  }
  if (props.section === "left") {
    return {
      email: form.email.trim(),
      phone: trimOrNull(form.phone),
      notify_email: !!form.notify_email,
      telegram_handle: trimOrNull(form.telegram_handle),
      whatsapp_e164: normalizeWhatsappE164(form.whatsapp_e164),
      slack_channel: trimOrNull(form.slack_channel),
      in_house_slack: trimOrNull(form.in_house_slack),
      account_manager_id: form.account_manager_id
        ? Number(form.account_manager_id)
        : null,
    };
  }
  if (props.section === "account") {
    return {
      company_name: form.company_name.trim(),
      brand_name: trimOrNull(form.brand_name),
      website: trimOrNull(form.website),
      contact_first_name: trimOrNull(form.contact_first_name),
      contact_last_name: trimOrNull(form.contact_last_name),
      email: form.email.trim(),
      phone: trimOrNull(form.phone),
    };
  }
  if (props.section === "address") {
    return {
      street: trimOrNull(form.street),
      city: trimOrNull(form.city),
      state: trimOrNull(form.state),
      zip: trimOrNull(form.zip),
      country: trimOrNull(form.country),
    };
  }

  return {};
}

async function onSubmit() {
  if (!props.accountId) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    await api.patch(`/client-accounts/${props.accountId}`, buildPatch());
    toast.success("Account updated.");
    emit("saved");
    close();
  } catch (e) {
    errorMsg.value = "Could not save.";
    toast.errorFrom(e, "Could not save account.");
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
        aria-labelledby="client-account-edit-modal-title"
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
              <h2 id="client-account-edit-modal-title" class="crm-vx-modal__title">
                {{ modalTitle }}
              </h2>
              <p class="crm-vx-modal__subtitle">
                {{ modalSubtitle }}
              </p>
            </header>

            <div class="crm-vx-modal__body">
              <div v-if="loading" class="d-flex justify-content-center py-5">
                <CrmLoadingSpinner message="Loading…" />
              </div>
              <template v-else>
                <p
                  v-if="errorMsg"
                  class="small text-danger mb-3 text-center"
                >
                  {{ errorMsg }}
                </p>
                <form
                  id="client-account-edit-modal-form"
                  class="d-flex flex-column gap-3"
                  @submit.prevent="onSubmit"
                >
                  <template v-if="showAccount && isAccountSectionOnly">
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-company"
                          >Company</label
                        >
                        <input
                          id="cae-company"
                          v-model="form.company_name"
                          type="text"
                          class="form-control"
                          required
                        />
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-email-personal"
                          >Email</label
                        >
                        <input
                          id="cae-email-personal"
                          v-model="form.email"
                          type="email"
                          class="form-control"
                          required
                        />
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-contact-name"
                          >Name</label
                        >
                        <input
                          id="cae-contact-name"
                          v-model="contactFullName"
                          type="text"
                          class="form-control"
                          autocomplete="name"
                        />
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-phone-personal"
                          >Phone number</label
                        >
                        <input
                          id="cae-phone-personal"
                          v-model="form.phone"
                          type="text"
                          class="form-control"
                          autocomplete="tel"
                        />
                      </div>
                    </div>
                  </template>
                  <template v-else-if="showAccount && showAll">
                    <div>
                      <label class="form-label small mb-1 text-secondary" for="cae-company-full"
                        >Company name</label
                      >
                      <input
                        id="cae-company-full"
                        v-model="form.company_name"
                        type="text"
                        class="form-control"
                        :required="showAccount"
                      />
                    </div>
                    <div class="row g-3">
                      <div class="col-sm-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-fn"
                          >Contact first name</label
                        >
                        <input
                          id="cae-fn"
                          v-model="form.contact_first_name"
                          type="text"
                          class="form-control"
                        />
                      </div>
                      <div class="col-sm-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-ln"
                          >Contact last name</label
                        >
                        <input
                          id="cae-ln"
                          v-model="form.contact_last_name"
                          type="text"
                          class="form-control"
                        />
                      </div>
                    </div>
                    <div class="row g-3">
                      <div class="col-sm-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-brand"
                          >Brand name</label
                        >
                        <input
                          id="cae-brand"
                          v-model="form.brand_name"
                          type="text"
                          class="form-control"
                        />
                      </div>
                      <div class="col-sm-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-web"
                          >Website</label
                        >
                        <input
                          id="cae-web"
                          v-model="form.website"
                          type="url"
                          class="form-control"
                        />
                      </div>
                    </div>
                  </template>
                  <template v-if="(showAccount && showAll) || showLeft">
                    <div>
                      <label class="form-label small mb-1 text-secondary" for="cae-email"
                        >Email</label
                      >
                      <input
                        id="cae-email"
                        v-model="form.email"
                        type="email"
                        class="form-control"
                        required
                      />
                    </div>
                    <div>
                      <label class="form-label small mb-1 text-secondary" for="cae-phone"
                        >Phone</label
                      >
                      <input
                        id="cae-phone"
                        v-model="form.phone"
                        type="text"
                        class="form-control"
                      />
                    </div>
                  </template>
                  <template v-if="showAddress">
                    <p class="small fw-semibold text-secondary mb-0">Address</p>
                    <div>
                      <label class="form-label small mb-1 text-secondary" for="cae-street"
                        >Street</label
                      >
                      <input
                        id="cae-street"
                        v-model="form.street"
                        type="text"
                        class="form-control"
                      />
                    </div>
                    <div class="row g-3">
                      <div class="col-sm-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-city"
                          >City</label
                        >
                        <input
                          id="cae-city"
                          v-model="form.city"
                          type="text"
                          class="form-control"
                        />
                      </div>
                      <div class="col-sm-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-state"
                          >State</label
                        >
                        <input
                          id="cae-state"
                          v-model="form.state"
                          type="text"
                          class="form-control"
                        />
                      </div>
                    </div>
                    <div class="row g-3">
                      <div class="col-sm-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-zip"
                          >ZIP</label
                        >
                        <input
                          id="cae-zip"
                          v-model="form.zip"
                          type="text"
                          class="form-control"
                        />
                      </div>
                      <div class="col-sm-6">
                        <label class="form-label small mb-1 text-secondary" for="cae-country"
                          >Country</label
                        >
                        <input
                          id="cae-country"
                          v-model="form.country"
                          type="text"
                          class="form-control"
                        />
                      </div>
                    </div>
                  </template>
                  <template v-if="showLeft">
                    <CrmSearchableSelect
                      v-model="form.account_manager_id"
                      label="Account manager"
                      :options="accountManagers"
                      placeholder="Choose account manager"
                      search-placeholder="Search staff…"
                      empty-label="— None —"
                    />
                    <div class="mt-3">
                      <label
                        class="form-label small mb-1 text-secondary"
                        for="cae-in-house-slack"
                        >In-House Slack</label
                      >
                      <input
                        id="cae-in-house-slack"
                        v-model="form.in_house_slack"
                        type="text"
                        class="form-control form-control-sm"
                        placeholder="https://your-workspace.slack.com/archives/…"
                        autocomplete="off"
                      />
                    </div>
                    <div class="border rounded p-3 mt-3">
                      <p class="small fw-semibold text-secondary mb-2">Channels</p>
                      <div class="form-check">
                        <input
                          id="cae-notify-email"
                          v-model="form.notify_email"
                          type="checkbox"
                          class="form-check-input"
                        />
                        <label
                          class="form-check-label"
                          for="cae-notify-email"
                          >Email</label
                        >
                      </div>
                      <div class="mt-2">
                        <label class="form-label small mb-1 text-secondary" for="cae-tg"
                          >Telegram</label
                        >
                        <input
                          id="cae-tg"
                          v-model="form.telegram_handle"
                          type="text"
                          class="form-control form-control-sm"
                        />
                      </div>
                      <div class="mt-2">
                        <label class="form-label small mb-1 text-secondary" for="cae-wa"
                          >WhatsApp</label
                        >
                        <input
                          id="cae-wa"
                          v-model="form.whatsapp_e164"
                          type="text"
                          class="form-control form-control-sm"
                        />
                      </div>
                      <div class="mt-2">
                        <label class="form-label small mb-1 text-secondary" for="cae-slack"
                          >Slack</label
                        >
                        <input
                          id="cae-slack"
                          v-model="form.slack_channel"
                          type="text"
                          class="form-control form-control-sm"
                          placeholder="https://… (full Slack URL)"
                          autocomplete="off"
                        />
                      </div>
                    </div>
                  </template>
                </form>
              </template>
            </div>

            <footer
              v-if="!loading && accountId"
              class="crm-vx-modal__footer"
            >
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
                form="client-account-edit-modal-form"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="saving"
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
