<script setup>
import { inject, onMounted, ref } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PortalProfileEditModal from "../../components/user-portal/PortalProfileEditModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import api from "../../services/api";

const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const profile = ref(null);
const editOpen = ref(false);
const editSection = ref("account");

setCrmPageMeta({
  title: "Save Rack | Account Settings",
  description: "Manage your account information.",
});

function display(v) {
  const s = v == null ? "" : String(v).trim();
  return s === "" ? "—" : s;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/portal/profile");
    profile.value = data;
  } catch (e) {
    toast.errorFrom(e, "Could not load account settings.");
  } finally {
    loading.value = false;
  }
}

function openEdit(section) {
  editSection.value = section;
  editOpen.value = true;
}

function onSaved(data) {
  profile.value = data;
  if (crmUser.value && data) {
    crmUser.value = {
      ...crmUser.value,
      name: data.name,
      email: data.email,
      client_account_company_name: data.company_name,
    };
  }
}

onMounted(() => load());
</script>

<template>
  <div class="staff-page staff-page--wide">
    <header class="mb-4">
      <h1 class="h4 fw-semibold text-body mb-1">Account Settings</h1>
      <p class="text-secondary small mb-0">
        Update your contact details and address.
      </p>
    </header>

    <div v-if="loading" class="py-5 text-center">
      <CrmLoadingSpinner />
    </div>

    <template v-else-if="profile">
      <div class="staff-surface p-3 p-md-4 mb-4">
        <div
          class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
        >
          <h2 class="staff-user-section-title mb-0">Personal Information</h2>
          <button
            type="button"
            class="btn btn-sm btn-primary staff-page-primary"
            @click="openEdit('account')"
          >
            Edit
          </button>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <dl class="mb-0 small">
              <dt
                class="text-secondary text-uppercase fw-semibold mb-1"
                style="font-size: 0.65rem"
              >
                Company
              </dt>
              <dd class="mb-0 fw-semibold text-body">
                {{ display(profile.company_name) }}
              </dd>
            </dl>
          </div>
          <div class="col-md-6">
            <dl class="mb-0 small">
              <dt
                class="text-secondary text-uppercase fw-semibold mb-1"
                style="font-size: 0.65rem"
              >
                Email
              </dt>
              <dd class="mb-0 fw-semibold text-body text-break">
                {{ display(profile.email) }}
              </dd>
            </dl>
          </div>
          <div class="col-md-6">
            <dl class="mb-0 small">
              <dt
                class="text-secondary text-uppercase fw-semibold mb-1"
                style="font-size: 0.65rem"
              >
                Name
              </dt>
              <dd class="mb-0 fw-semibold text-body">
                {{ display(profile.name) }}
              </dd>
            </dl>
          </div>
          <div class="col-md-6">
            <dl class="mb-0 small">
              <dt
                class="text-secondary text-uppercase fw-semibold mb-1"
                style="font-size: 0.65rem"
              >
                Phone Number
              </dt>
              <dd class="mb-0 fw-semibold text-body">
                {{ display(profile.phone) }}
              </dd>
            </dl>
          </div>
        </div>
      </div>

      <div class="staff-surface p-3 p-md-4 mb-4">
        <div
          class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
        >
          <h2 class="staff-user-section-title mb-0">Address</h2>
          <button
            type="button"
            class="btn btn-sm btn-primary staff-page-primary"
            @click="openEdit('address')"
          >
            Edit
          </button>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <dl class="mb-0 small">
              <dt
                class="text-secondary text-uppercase fw-semibold mb-1"
                style="font-size: 0.65rem"
              >
                Street
              </dt>
              <dd class="mb-0 fw-semibold text-body">
                {{ display(profile.street) }}
              </dd>
            </dl>
          </div>
          <div class="col-md-6">
            <dl class="mb-0 small">
              <dt
                class="text-secondary text-uppercase fw-semibold mb-1"
                style="font-size: 0.65rem"
              >
                City
              </dt>
              <dd class="mb-0 fw-semibold text-body">
                {{ display(profile.city) }}
              </dd>
            </dl>
          </div>
          <div class="col-md-6">
            <dl class="mb-0 small">
              <dt
                class="text-secondary text-uppercase fw-semibold mb-1"
                style="font-size: 0.65rem"
              >
                State / ZIP
              </dt>
              <dd class="mb-0 fw-semibold text-body">
                {{ display(profile.state) }}
                <template v-if="profile.zip">
                  <span v-if="profile.state"> </span>{{ display(profile.zip) }}
                </template>
              </dd>
            </dl>
          </div>
          <div class="col-md-6">
            <dl class="mb-0 small">
              <dt
                class="text-secondary text-uppercase fw-semibold mb-1"
                style="font-size: 0.65rem"
              >
                Country
              </dt>
              <dd class="mb-0 fw-semibold text-body">
                {{ display(profile.country) }}
              </dd>
            </dl>
          </div>
        </div>
      </div>

      <div class="staff-surface p-3 p-md-4 mb-4">
        <div
          class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
        >
          <h2 class="staff-user-section-title mb-0">Packaging</h2>
          <button
            type="button"
            class="btn btn-sm btn-primary staff-page-primary"
            @click="openEdit('packaging')"
          >
            Edit
          </button>
        </div>
        <dl class="mb-0 small">
          <dt
            class="text-secondary text-uppercase fw-semibold mb-1"
            style="font-size: 0.65rem"
          >
            Packaging materials
          </dt>
          <dd class="mb-0 fw-semibold text-body">
            {{ display(profile.packaging_option_label) }}
          </dd>
        </dl>
      </div>
    </template>

    <PortalProfileEditModal
      v-model:open="editOpen"
      :section="editSection"
      :profile="profile"
      @saved="onSaved"
    />
  </div>
</template>
